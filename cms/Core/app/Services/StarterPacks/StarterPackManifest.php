<?php

namespace Cms\Core\Services\StarterPacks;

/**
 * Immutable, validated representation of a starter pack's pack.json.
 */
final readonly class StarterPackManifest
{
    /**
     * @param string                              $packDir   Resolved absolute pack folder.
     * @param string                              $key       Pack key (equals the folder name).
     * @param string                              $version   Semantic version.
     * @param array{vi: string, en: string}       $name      Localized name.
     * @param array{vi: string, en: string}       $description Localized description.
     * @param array<string, string>               $modules   Module name => version constraint.
     * @param string|null                         $config    Raw relative config path.
     * @param string|null                         $roles     Raw relative roles path.
     * @param string|null                         $workflows Raw relative workflows path.
     * @param list<array{module: string, importer: string, file: string, path: string}> $content Content entries with resolved "path".
     * @param list<string>                        $postApply Post-apply steps.
     * @param array<string, string|null>          $resolved  Resolved absolute paths for config/roles/workflows.
     * @param int                                 $packFormat Manifest format version.
     */
    public function __construct(
        public string $packDir,
        public string $key,
        public string $version,
        public array $name,
        public array $description,
        public array $modules,
        public ?string $config,
        public ?string $roles,
        public ?string $workflows,
        public array $content,
        public array $postApply,
        public array $resolved = [],
        public int $packFormat = 1,
    ) {
    }

    /**
     * Resolved absolute path of the config file, or null.
     */
    public function configPath(): ?string
    {
        return $this->resolved['config'] ?? null;
    }

    /**
     * Resolved absolute path of the roles file, or null.
     */
    public function rolesPath(): ?string
    {
        return $this->resolved['roles'] ?? null;
    }

    /**
     * Resolved absolute path of the workflows file, or null.
     */
    public function workflowsPath(): ?string
    {
        return $this->resolved['workflows'] ?? null;
    }

    /**
     * Export the manifest as a plain array (raw relative values plus resolved paths).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pack_format' => $this->packFormat,
            'key' => $this->key,
            'version' => $this->version,
            'name' => $this->name,
            'description' => $this->description,
            'modules' => $this->modules,
            'config' => $this->config,
            'roles' => $this->roles,
            'workflows' => $this->workflows,
            'content' => $this->content,
            'post_apply' => $this->postApply,
            'pack_dir' => $this->packDir,
            'resolved' => $this->resolved,
        ];
    }
}
