<?php

namespace Cms\Core\Services\StarterPacks;

/**
 * Reads and validates starter pack manifests. It never reads outside the pack folder.
 */
class StarterPackLoader
{
    private const FIELDS = [
        'pack_format', 'key', 'version', 'name', 'description', 'modules',
        'config', 'roles', 'workflows', 'content', 'post_apply',
    ];

    private const POST_APPLY = ['translation-sync', 'cache-clear'];

    private const SEMVER = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    /**
     * @param string|null $root Folder holding one sub-folder per pack; defaults to Core's starter-packs resources.
     */
    public function __construct(private readonly ?string $root = null)
    {
    }

    /**
     * Load every pack under the root, keyed and sorted by pack key.
     *
     * @return array<string, StarterPackManifest>
     */
    public function all(): array
    {
        $root = $this->root ?? base_path('cms/Core/resources/starter-packs');
        if (!is_dir($root)) {
            return [];
        }

        $packs = [];
        foreach (glob(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'pack.json') ?: [] as $file) {
            $manifest = $this->load(dirname($file));
            $packs[$manifest->key] = $manifest;
        }
        ksort($packs);

        return $packs;
    }

    /**
     * Load and validate the pack stored in a folder.
     *
     * @param string $dir Pack folder containing pack.json.
     *
     * @throws StarterPackException When the pack is missing or invalid.
     */
    public function load(string $dir): StarterPackManifest
    {
        $folder = basename(rtrim($dir, '/\\'));
        $packDir = realpath($dir);
        if ($packDir === false || !is_dir($packDir)) {
            throw StarterPackException::invalidPack($folder, 'the pack folder does not exist.');
        }

        $file = $packDir . DIRECTORY_SEPARATOR . 'pack.json';
        $real = realpath($file);
        if ($real === false || !is_file($real)) {
            throw StarterPackException::invalidPack($folder, 'pack.json is missing.');
        }
        if (!str_starts_with($real, $packDir . DIRECTORY_SEPARATOR)) {
            throw StarterPackException::invalidPack($folder, 'pack.json must be inside the pack folder.');
        }

        $data = json_decode((string) file_get_contents($real), true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw StarterPackException::invalidPack($folder, 'pack.json must contain a valid JSON object.');
        }

        foreach (array_keys($data) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                throw StarterPackException::invalidField($folder, (string) $field, 'is not a known field.');
            }
        }
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                throw StarterPackException::invalidField($folder, $field, 'is required.');
            }
        }

        if (($data['pack_format'] ?? null) !== 1) {
            throw StarterPackException::invalidField($folder, 'pack_format', 'must be the integer 1.');
        }

        $key = $data['key'];
        if (!is_string($key) || !preg_match('/^[a-z0-9-]+$/', $key)) {
            throw StarterPackException::invalidField($folder, 'key', 'must use only lowercase letters, digits and hyphens.');
        }
        if ($key !== $folder) {
            throw StarterPackException::invalidField($folder, 'key', 'must equal the folder name.');
        }

        if (!is_string($data['version']) || !preg_match(self::SEMVER, $data['version'])) {
            throw StarterPackException::invalidField($key, 'version', 'must be a semantic version like 1.0.0.');
        }

        $name = $this->localized($key, 'name', $data['name']);
        $description = $this->localized($key, 'description', $data['description']);
        $modules = $this->modules($key, $data['modules']);

        $resolved = [];
        $paths = [];
        foreach (['config', 'roles', 'workflows'] as $field) {
            $value = $data[$field];
            if ($value === null) {
                $paths[$field] = null;
                $resolved[$field] = null;
                continue;
            }
            $resolved[$field] = $this->resolve($packDir, $key, $field, $value);
            $paths[$field] = $value;
        }

        if (!is_array($data['content']) || !array_is_list($data['content'])) {
            throw StarterPackException::invalidField($key, 'content', 'must be a list.');
        }
        $content = [];
        foreach ($data['content'] as $i => $entry) {
            $field = "content[$i]";
            if (!is_array($entry) || array_is_list($entry) || array_keys($entry) === [] || array_diff(array_keys($entry), ['module', 'importer', 'file']) !== [] || count($entry) !== 3) {
                throw StarterPackException::invalidField($key, $field, 'must be an object with exactly module, importer and file.');
            }
            if (!is_string($entry['module']) || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $entry['module'])) {
                throw StarterPackException::invalidField($key, $field . '.module', 'must be a StudlyCase module name.');
            }
            // Importer ids are `<module>.<name>` in practice (`helpdesk.kb`, see StarterContentImporter::id()): words of lowercase
            // letters, digits and hyphens, joined by single dots.
            if (!is_string($entry['importer']) || !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $entry['importer'])) {
                throw StarterPackException::invalidField($key, $field . '.importer', 'must use only lowercase letters, digits, hyphens and single dots between words.');
            }
            $path = $this->resolve($packDir, $key, $field . '.file', $entry['file']);
            $content[] = ['module' => $entry['module'], 'importer' => $entry['importer'], 'file' => $entry['file'], 'path' => $path];
        }

        $post = $data['post_apply'];
        if (!is_array($post) || !array_is_list($post)) {
            throw StarterPackException::invalidField($key, 'post_apply', 'must be a list.');
        }
        foreach ($post as $step) {
            if (!is_string($step) || !in_array($step, self::POST_APPLY, true)) {
                throw StarterPackException::invalidField($key, 'post_apply', 'may only contain: ' . implode(', ', self::POST_APPLY) . '.');
            }
        }
        if (count(array_unique($post)) !== count($post)) {
            throw StarterPackException::invalidField($key, 'post_apply', 'must not contain duplicates.');
        }

        return new StarterPackManifest(
            $packDir, $key, $data['version'], $name, $description, $modules,
            $paths['config'], $paths['roles'], $paths['workflows'], $content, $post, $resolved, 1,
        );
    }

    /**
     * Validate a {"vi": ..., "en": ...} object.
     *
     * @return array{vi: string, en: string}
     */
    private function localized(string $pack, string $field, mixed $value): array
    {
        if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value), ['vi', 'en']) !== []) {
            throw StarterPackException::invalidField($pack, $field, 'must be an object with only "vi" and "en" texts.');
        }
        foreach (['vi', 'en'] as $lang) {
            if (!isset($value[$lang]) || !is_string($value[$lang]) || trim($value[$lang]) === '') {
                throw StarterPackException::invalidField($pack, $field, "needs a non-empty \"$lang\" text.");
            }
        }

        return ['vi' => $value['vi'], 'en' => $value['en']];
    }

    /**
     * Validate the modules requirement map.
     *
     * @return array<string, string>
     */
    private function modules(string $pack, mixed $value): array
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw StarterPackException::invalidField($pack, 'modules', 'must be a non-empty object of module names and version constraints.');
        }
        foreach ($value as $module => $constraint) {
            if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', (string) $module)) {
                throw StarterPackException::invalidField($pack, 'modules', "has an invalid module name \"$module\".");
            }
            if (!is_string($constraint) || trim($constraint) === '' || !preg_match('/^[\^~<>=*0-9A-Za-z.\-+|, ]+$/', $constraint)) {
                throw StarterPackException::invalidField($pack, 'modules', "has an invalid version constraint for \"$module\".");
            }
        }
        if (!array_key_exists('Core', $value)) {
            throw StarterPackException::invalidField($pack, 'modules', 'must include the "Core" module.');
        }

        return $value;
    }

    /**
     * Resolve a relative path inside the pack folder; the file must exist.
     *
     * @return string Resolved absolute path.
     */
    private function resolve(string $packDir, string $pack, string $field, mixed $value): string
    {
        $rule = 'must be a relative path to an existing file inside the pack folder.';
        if (!is_string($value) || $value === '' || str_contains($value, "\0") || str_contains($value, '\\')
            || str_starts_with($value, '/') || preg_match('/^[A-Za-z]:/', $value)
            || in_array('..', explode('/', $value), true)) {
            throw StarterPackException::invalidField($pack, $field, $rule);
        }
        $real = realpath($packDir . DIRECTORY_SEPARATOR . $value);
        if ($real === false || !is_file($real) || !str_starts_with($real, $packDir . DIRECTORY_SEPARATOR)) {
            throw StarterPackException::invalidField($pack, $field, $rule);
        }

        return $real;
    }
}
