<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

/**
 * the Content public API.
 *
 * Every class, interface or enum under `cms/Content/app` whose class docblock carries an `@api` tag is public API.
 * Its shape (parent, interfaces, constants, and the public methods and properties the class itself declares) is stored
 * in `cms/Content/tests/Contract/content-api.json`.
 *
 * - The first test regenerates the snapshot and fails with the difference when the stored file is out of date.
 *   Regenerate with `CONTENT_API_WRITE=1 ./vendor/bin/pest cms/Content/tests/Unit/ContentApiSnapshotTest.php`.
 * - The second test enforces the module notes, same shape as the Core rule: against
 *   `git show HEAD:` while the change is not committed, and HEAD against `HEAD~1` once it is.
 *   Added members need a higher Content version; a removed or changed member needs a higher major.
 */
const CONTENT_API_FILE = 'cms/Content/tests/Contract/content-api.json';

/**
 * Fully qualified names of every `@api` symbol under cms/Content/app, sorted.
 *
 * @return list<string>
 */
function contentApiSymbols(): array
{
    $root = base_path('cms/Content/app');
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (!str_contains($source, '@api')) {
            continue;
        }
        if (!preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)) {
            continue;
        }
        if (preg_match('/\/\*\*(?:(?!\*\/).)*?@api\b(?:(?!\*\/).)*?\*\/\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|enum|trait)\s+(\w+)/s', $source, $match)) {
            $found[] = trim($namespace[1]) . '\\' . $match[1];
        }
    }
    sort($found);

    return $found;
}

/**
 * One method as a signature line: modifiers, name, parameters and return type.
 */
function contentApiSignature(ReflectionMethod $method): string
{
    $params = [];
    foreach ($method->getParameters() as $param) {
        $text = ($param->hasType() ? (string) $param->getType() . ' ' : '')
            . ($param->isPassedByReference() ? '&' : '')
            . ($param->isVariadic() ? '...' : '')
            . '$' . $param->getName();
        if ($param->isDefaultValueAvailable()) {
            if ($param->isDefaultValueConstant()) {
                $text .= ' = ' . $param->getDefaultValueConstantName();
            } else {
                $default = $param->getDefaultValue();
                $text .= ' = ' . (is_object($default) ? 'new ' . $default::class : str_replace(["\n", '  '], ['', ' '], var_export($default, true)));
            }
        }
        $params[] = $text;
    }
    $modifiers = implode(' ', Reflection::getModifierNames($method->getModifiers()));
    $return = $method->hasReturnType() ? ': ' . (string) $method->getReturnType() : '';

    return trim($modifiers . ' function ' . $method->getName() . '(' . implode(', ', $params) . ')' . $return);
}

/**
 * The snapshot of the current `@api` surface.
 *
 * @return array<string, array<string, mixed>>
 */
function contentApiBuild(): array
{
    $snapshot = [];
    foreach (contentApiSymbols() as $name) {
        $reflection = new ReflectionClass($name);
        $entry = ['kind' => $reflection->isEnum() ? 'enum' : ($reflection->isInterface() ? 'interface' : ($reflection->isTrait() ? 'trait' : 'class'))];
        if ($reflection->getParentClass() !== false) {
            $entry['extends'] = $reflection->getParentClass()->getName();
        }
        $interfaces = $reflection->getInterfaceNames();
        sort($interfaces);
        if ($interfaces !== []) {
            $entry['implements'] = $interfaces;
        }
        $constants = [];
        foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->getDeclaringClass()->getName() === $name) {
                $constants[$constant->getName()] = json_encode($constant->getValue(), JSON_UNESCAPED_SLASHES);
            }
        }
        ksort($constants);
        if ($constants !== []) {
            $entry['constants'] = $constants;
        }
        $properties = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() === $name) {
                $properties[$property->getName()] = trim(($property->isStatic() ? 'static ' : '') . ($property->hasType() ? (string) $property->getType() : 'mixed'));
            }
        }
        ksort($properties);
        if ($properties !== []) {
            $entry['properties'] = $properties;
        }
        $methods = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $name) {
                $methods[$method->getName()] = contentApiSignature($method);
            }
        }
        ksort($methods);
        if ($methods !== []) {
            $entry['methods'] = $methods;
        }
        $snapshot[$name] = $entry;
    }

    return $snapshot;
}

/**
 * Flatten a snapshot to `key => text` lines so two snapshots compare member by member.
 *
 * @param array<string, array<string, mixed>> $snapshot
 *
 * @return array<string, string>
 */
function contentApiFlatten(array $snapshot): array
{
    $flat = [];
    foreach ($snapshot as $name => $entry) {
        $flat[$name] = $entry['kind'] . (isset($entry['extends']) ? ' extends ' . $entry['extends'] : '');
        foreach ((array) ($entry['implements'] ?? []) as $interface) {
            $flat[$name . '::implements ' . $interface] = $interface;
        }
        foreach (['constants' => 'const', 'properties' => 'property', 'methods' => 'method'] as $group => $label) {
            foreach ((array) ($entry[$group] ?? []) as $member => $value) {
                $flat[$name . '::' . $label . ' ' . $member] = (string) $value;
            }
        }
    }

    return $flat;
}

/**
 * Added, removed and changed keys between two snapshots.
 *
 * @param array<string, array<string, mixed>> $old
 * @param array<string, array<string, mixed>> $new
 *
 * @return array{added: list<string>, removed: list<string>, changed: list<string>}
 */
function contentApiDiff(array $old, array $new): array
{
    $before = contentApiFlatten($old);
    $after = contentApiFlatten($new);
    $changed = [];
    foreach (array_intersect_key($before, $after) as $key => $value) {
        if ($after[$key] !== $value) {
            $changed[] = $key . "\n      was: " . $value . "\n      now: " . $after[$key];
        }
    }

    return [
        'added' => array_keys(array_diff_key($after, $before)),
        'removed' => array_keys(array_diff_key($before, $after)),
        'changed' => $changed,
    ];
}

/**
 * Human-readable text of a difference.
 *
 * @param array<string, list<string>> $diff
 */
function contentApiDiffText(array $diff): string
{
    $lines = [];
    foreach ($diff as $kind => $keys) {
        foreach ($keys as $key) {
            $lines[] = '  ' . $kind . ': ' . $key;
        }
    }

    return implode("\n", $lines);
}

/**
 * Output of `git show <rev>:<path>` in the ispa-cms repository, or null when git, the revision or the file is missing.
 */
function contentApiGitShow(string $rev, string $path): ?string
{
    $output = [];
    $code = 1;
    @exec('git -C ' . escapeshellarg(base_path()) . ' show ' . escapeshellarg($rev . ':' . $path) . ' 2>/dev/null', $output, $code);

    return $code === 0 ? implode("\n", $output) . "\n" : null;
}

/**
 * The `version` of a module.json text.
 */
function contentApiVersion(string $moduleJson): string
{
    return (string) (json_decode($moduleJson, true)['version'] ?? '');
}

/**
 * Failure messages of the bump rule for an API difference and a Content version change (empty when it holds).
 *
 * @param array{added: list<string>, removed: list<string>, changed: list<string>} $diff
 *
 * @return list<string>
 */
function contentApiBumpViolations(array $diff, string $old, string $new): array
{
    if ($diff['added'] === [] && $diff['removed'] === [] && $diff['changed'] === []) {
        return [];
    }
    $text = contentApiDiffText($diff);
    if ($diff['removed'] !== [] || $diff['changed'] !== []) {
        return (int) explode('.', $new)[0] > (int) explode('.', $old)[0]
            ? []
            : ["The Content API lost or changed members; that needs a major Content bump ({$old} -> {$new}) and ships only by deploy:\n{$text}"];
    }

    return version_compare($new, $old, '>')
        ? []
        : ["The Content API grew but cms/Content/module.json version did not go up ({$old} -> {$new}):\n{$text}"];
}

test('the stored Content API snapshot matches the @api surface', function () {
    $current = contentApiBuild();
    $path = base_path(CONTENT_API_FILE);

    if (getenv('CONTENT_API_WRITE') === '1') {
        file_put_contents($path, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    expect(is_file($path))->toBeTrue('Missing ' . CONTENT_API_FILE . '; regenerate it with CONTENT_API_WRITE=1.');
    $diff = contentApiDiff((array) json_decode((string) file_get_contents($path), true), $current);

    expect($diff['added'] === [] && $diff['removed'] === [] && $diff['changed'] === [])->toBeTrue(
        "The Content public API changed:\n" . contentApiDiffText($diff)
        . "\nRegenerate " . CONTENT_API_FILE . ' with CONTENT_API_WRITE=1 and bump cms/Content/module.json version.'
    );
});

test('the @api surface covers the classes the design lists', function () {
    $expected = [
        'Cms\\Content\\Models\\Post',
        'Cms\\Content\\Models\\Content',
        'Cms\\Content\\Models\\BookMark',
        'Cms\\Content\\Interfaces\\PostInterface',
        'Cms\\Content\\Interfaces\\ContentInterface',
        'Cms\\Content\\Interfaces\\TagInterface',
        'Cms\\Content\\Interfaces\\BookmarkInterface',
        'Cms\\Content\\Constants\\ContentTableConstant',
        'Cms\\Content\\Events\\AdminPostSaving',
        'Cms\\Content\\Services\\Cache\\ContentPostCacheInvalidationService',
        'Cms\\Content\\Services\\Cache\\ContentPublicTagCacheService',
        'Cms\\Content\\Services\\DeletedPostPurgeService',
    ];

    expect(array_values(array_diff($expected, contentApiSymbols())))->toBe([]);
});

test('an API change comes with a Content version bump', function () {
    $headApi = contentApiGitShow('HEAD', CONTENT_API_FILE);
    $headModule = contentApiGitShow('HEAD', 'cms/Content/module.json');
    if ($headApi === null || $headModule === null) {
        $this->markTestSkipped('No git checkout or no committed API snapshot yet.');
    }

    $working = (array) json_decode((string) file_get_contents(base_path(CONTENT_API_FILE)), true);
    $diff = contentApiDiff((array) json_decode($headApi, true), $working);
    $old = contentApiVersion($headModule);
    $new = contentApiVersion((string) file_get_contents(base_path('cms/Content/module.json')));

    if ($diff['added'] === [] && $diff['removed'] === [] && $diff['changed'] === []) {
        $parentApi = contentApiGitShow('HEAD~1', CONTENT_API_FILE);
        $parentModule = contentApiGitShow('HEAD~1', 'cms/Content/module.json');
        if ($parentApi === null || $parentModule === null) {
            expect(true)->toBeTrue();

            return;
        }
        $diff = contentApiDiff((array) json_decode($parentApi, true), (array) json_decode($headApi, true));
        $old = contentApiVersion($parentModule);
        $new = contentApiVersion($headModule);
    }

    expect(contentApiBumpViolations($diff, $old, $new))->toBe([]);
});

test('the bump rule: additions need a higher version, removals and changes a higher major', function () {
    $old = ['Cms\\A' => ['kind' => 'class', 'methods' => ['run' => 'public function run(): void']]];
    $added = $old;
    $added['Cms\\A']['methods']['stop'] = 'public function stop(): void';
    $removed = $old;
    unset($removed['Cms\\A']['methods']);
    $changed = $old;
    $changed['Cms\\A']['methods']['run'] = 'public function run(int $x): void';

    expect(contentApiBumpViolations(contentApiDiff($old, $old), '2026.11.0', '2026.11.0'))->toBe([])
        ->and(contentApiBumpViolations(contentApiDiff($old, $added), '2026.11.0', '2026.11.0'))->toHaveCount(1)
        ->and(contentApiBumpViolations(contentApiDiff($old, $added), '2026.11.0', '2026.12.0'))->toBe([])
        ->and(contentApiBumpViolations(contentApiDiff($old, $removed), '2026.11.0', '2026.12.0'))->toHaveCount(1)
        ->and(contentApiBumpViolations(contentApiDiff($old, $changed), '2026.11.0', '2027.0.0'))->toBe([]);
});
