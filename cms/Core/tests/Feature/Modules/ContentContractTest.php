<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use App\Support\Modules\RuntimeModules;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Core's version is the version of the platform contract Content is built against.
 *
 * The contract is every `App\` and `Cms\Core\` class, interface, trait or enum that non-test code in `cms/Content`
 * imports (a `use` statement, a grouped `use`, or an inline fully qualified name). For each one it records the parent,
 * the interfaces, enum cases, public constants, and the public methods and properties the platform itself declares
 * (members from vendor code are left out). Traits, abstract classes and classes Content extends also record their
 * protected methods and properties, because Content code reaches those through `$this`. It is stored in
 * `cms/Core/tests/Contract/content-contract.json`.
 *
 * - The first test regenerates the contract and fails with the difference when the stored file is out of date.
 *   Regenerate with `CONTENT_CONTRACT_WRITE=1 ./vendor/bin/pest cms/Core/tests/Feature/Modules/ContentContractTest.php`.
 * - The second test enforces the module notes: against `git show HEAD:` while the change is not
 *   committed, and HEAD against `HEAD~1` once it is, so a clean checkout still checks the latest commit.
 * - The last tests prove the installer refuses a Content package whose `requires.core` excludes the installed Core.
 */
const CONTENT_CONTRACT_FILE = 'cms/Core/tests/Contract/content-contract.json';

/**
 * Import map of one PHP source: short alias => fully qualified name, for every `use` of a class (single or grouped).
 *
 * @return array<string, string>
 */
function contentContractImports(string $source): array
{
    $map = [];
    // Only top-level imports (column 0); trait `use` lines inside a class body are indented.
    preg_match_all('/^use\s+(?!function\b|const\b)([A-Za-z0-9_\\\\]+)\s*(?:\\\\\{([^}]*)\}|\s+as\s+(\w+))?\s*;/m', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $prefix = rtrim($m[1], '\\');
        if (($m[2] ?? '') !== '') {
            foreach (explode(',', $m[2]) as $part) {
                if (preg_match('/^\s*([A-Za-z0-9_\\\\]+)(?:\s+as\s+(\w+))?\s*$/', $part, $p) !== 1) {
                    continue;
                }
                $name = $prefix . '\\' . $p[1];
                $map[$p[2] ?? '' ?: substr(strrchr('\\' . $name, '\\'), 1)] = $name;
            }

            continue;
        }
        $map[($m[3] ?? '') !== '' ? $m[3] : substr(strrchr('\\' . $prefix, '\\'), 1)] = $prefix;
    }

    return $map;
}

/**
 * True when a fully qualified name is platform code (`App\` or `Cms\Core\`).
 */
function contentContractIsPlatformName(string $name): bool
{
    return str_starts_with($name, 'App\\') || str_starts_with($name, 'Cms\\Core\\');
}

/**
 * Platform names non-test cms/Content code uses, and the subset it extends (needs the protected surface), sorted.
 *
 * @return array{symbols: list<string>, extended: list<string>}
 */
function contentContractSymbols(): array
{
    $root = base_path('cms/Content');
    $testsDir = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR;
    $names = [];
    $extended = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php') || str_starts_with($path, $testsDir)) {
            continue;
        }
        $source = (string) file_get_contents($path);
        $imports = contentContractImports($source);
        foreach ($imports as $name) {
            if (contentContractIsPlatformName($name)) {
                $names[$name] = true;
            }
        }
        preg_match_all('/(?<![\w\\\\])\\\\((?:App|Cms\\\\Core)\\\\[A-Z][A-Za-z0-9_\\\\]*[A-Za-z0-9_])/', $source, $inline);
        foreach ($inline[1] as $name) {
            $names[$name] = true;
        }
        preg_match_all('/\bextends\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/', $source, $parents);
        foreach ($parents[1] as $parent) {
            $resolved = str_starts_with($parent, '\\') ? ltrim($parent, '\\') : ($imports[explode('\\', $parent)[0]] ?? null);
            if ($resolved !== null && !str_starts_with($parent, '\\') && str_contains($parent, '\\')) {
                $resolved .= substr($parent, strpos($parent, '\\'));
            }
            if ($resolved !== null && contentContractIsPlatformName($resolved)) {
                $extended[$resolved] = true;
            }
        }
    }
    $names = array_keys($names);
    sort($names);
    $extended = array_keys($extended);
    sort($extended);

    return ['symbols' => $names, 'extended' => $extended];
}

/**
 * Text form of a default parameter value.
 */
function contentContractDefault(ReflectionParameter $param): string
{
    if ($param->isDefaultValueConstant()) {
        return (string) $param->getDefaultValueConstantName();
    }
    $value = $param->getDefaultValue();
    if (is_object($value)) {
        return 'new ' . $value::class;
    }

    return str_replace(["\n", '  '], ['', ' '], var_export($value, true));
}

/**
 * One method as a signature line: modifiers, name, parameters and return type.
 */
function contentContractSignature(ReflectionMethod $method): string
{
    $params = [];
    foreach ($method->getParameters() as $param) {
        $text = ($param->hasType() ? (string) $param->getType() . ' ' : '')
            . ($param->isPassedByReference() ? '&' : '')
            . ($param->isVariadic() ? '...' : '')
            . '$' . $param->getName();
        if ($param->isDefaultValueAvailable()) {
            $text .= ' = ' . contentContractDefault($param);
        }
        $params[] = $text;
    }
    $modifiers = implode(' ', Reflection::getModifierNames($method->getModifiers()));
    $return = $method->hasReturnType() ? ': ' . (string) $method->getReturnType() : '';

    return trim($modifiers . ' function ' . $method->getName() . '(' . implode(', ', $params) . ')' . $return);
}

/**
 * True when $file is platform code (app/ or cms/Core), not vendor or another module. Both sides are resolved with
 * realpath() so a symlinked release or checkout does not filter everything out.
 */
function contentContractPlatformFile(string|false $file): bool
{
    if ($file === false) {
        return false;
    }
    static $roots = null;
    $roots ??= array_values(array_filter(array_map(
        fn (string $dir): string|false => realpath(base_path($dir)),
        ['app', 'cms/Core']
    )));
    $real = realpath($file) ?: $file;
    foreach ($roots as $root) {
        if (str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return true;
        }
    }

    return false;
}

/**
 * Names of the members (properties and constants) that $class takes from vendor traits, at any depth.
 *
 * Reflection reports the using class as the declaring class of a trait property or constant, so the file filter alone
 * would let vendor trait members through.
 *
 * @return array{properties: array<string, true>, constants: array<string, true>}
 */
function contentContractVendorTraitMembers(ReflectionClass $class): array
{
    $out = ['properties' => [], 'constants' => []];
    $pending = [];
    for ($c = $class; $c !== false; $c = $c->getParentClass()) {
        $pending = [...$pending, ...array_values($c->getTraits())];
    }
    $seen = [];
    while ($pending !== []) {
        $trait = array_pop($pending);
        if (isset($seen[$trait->getName()])) {
            continue;
        }
        $seen[$trait->getName()] = true;
        $pending = [...$pending, ...array_values($trait->getTraits())];
        if (contentContractPlatformFile($trait->getFileName())) {
            continue;
        }
        foreach ($trait->getProperties() as $property) {
            $out['properties'][$property->getName()] = true;
        }
        foreach ($trait->getReflectionConstants() as $constant) {
            $out['constants'][$constant->getName()] = true;
        }
    }

    return $out;
}

/**
 * One property as a declaration line (no default value).
 */
function contentContractProperty(ReflectionProperty $property): string
{
    $modifiers = implode(' ', Reflection::getModifierNames($property->getModifiers()));
    $type = $property->hasType() ? (string) $property->getType() . ' ' : '';

    return trim($modifiers . ' ' . $type . '$' . $property->getName());
}

/**
 * Build the contract from the current code.
 *
 * @return array<string, array<string, mixed>>
 */
function contentContractBuild(): array
{
    ['symbols' => $symbols, 'extended' => $extended] = contentContractSymbols();
    $contract = [];
    foreach ($symbols as $name) {
        if (!class_exists($name) && !interface_exists($name) && !trait_exists($name) && !enum_exists($name)) {
            // A dangling import (never autoloaded at runtime) is recorded so that creating the symbol is a change.
            $contract[$name] = ['kind' => 'missing'];

            continue;
        }
        $class = new ReflectionClass($name);
        $kind = match (true) {
            $class->isEnum() => 'enum',
            $class->isInterface() => 'interface',
            $class->isTrait() => 'trait',
            $class->isAbstract() => 'abstract class',
            $class->isFinal() => 'final class',
            default => 'class',
        };
        $entry = ['kind' => $kind];

        $parent = $class->getParentClass();
        if ($parent !== false) {
            $entry['extends'] = $parent->getName();
        }
        $interfaces = $class->getInterfaceNames();
        sort($interfaces);
        if ($interfaces !== []) {
            $entry['implements'] = $interfaces;
        }

        if ($class->isEnum()) {
            $cases = [];
            foreach ((new ReflectionEnum($name))->getCases() as $case) {
                $cases[$case->getName()] = $case instanceof ReflectionEnumBackedCase ? $case->getBackingValue() : null;
            }
            $entry['cases'] = $cases;
        }

        $vendorTrait = contentContractVendorTraitMembers($class);
        $withProtected = $class->isTrait() || $class->isAbstract() || in_array($name, $extended, true);
        $visibility = ReflectionMethod::IS_PUBLIC | ($withProtected ? ReflectionMethod::IS_PROTECTED : 0);

        $constants = [];
        foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->isEnumCase()
                || isset($vendorTrait['constants'][$constant->getName()])
                || !contentContractPlatformFile($constant->getDeclaringClass()->getFileName())) {
                continue;
            }
            $value = $constant->getValue();
            $constants[$constant->getName()] = match (true) {
                $value instanceof UnitEnum => $value::class . '::' . $value->name,
                is_scalar($value) || $value === null || is_array($value) => $value,
                default => get_debug_type($value),
            };
        }
        ksort($constants);
        if ($constants !== []) {
            $entry['constants'] = $constants;
        }

        $properties = [];
        foreach ($class->getProperties($visibility) as $property) {
            // Enum `name` and `value` are language properties, not platform API.
            if ($class->isEnum()
                || isset($vendorTrait['properties'][$property->getName()])
                || !contentContractPlatformFile($property->getDeclaringClass()->getFileName())) {
                continue;
            }
            $properties[$property->getName()] = contentContractProperty($property);
        }
        ksort($properties);
        if ($properties !== []) {
            $entry['properties'] = $properties;
        }

        $methods = [];
        foreach ($class->getMethods($visibility) as $method) {
            // A trait method reports the trait's file, so vendor trait methods are dropped here.
            if (!contentContractPlatformFile($method->getFileName())) {
                continue;
            }
            $methods[$method->getName()] = contentContractSignature($method);
        }
        ksort($methods);
        if ($methods !== []) {
            $entry['methods'] = $methods;
        }

        $contract[$name] = $entry;
    }

    return $contract;
}

/**
 * Flatten a contract into "symbol", "symbol::member" => text pairs for a readable difference.
 *
 * @param array<string, array<string, mixed>> $contract
 *
 * @return array<string, string>
 */
function contentContractFlatten(array $contract): array
{
    $flat = [];
    foreach ($contract as $name => $entry) {
        $flat[$name] = $entry['kind'] . (isset($entry['extends']) ? ' extends ' . $entry['extends'] : '');
        foreach ((array) ($entry['implements'] ?? []) as $interface) {
            $flat[$name . '::implements ' . $interface] = $interface;
        }
        foreach (['cases' => 'case', 'constants' => 'const', 'properties' => 'property', 'methods' => 'method'] as $group => $label) {
            foreach ((array) ($entry[$group] ?? []) as $member => $value) {
                $flat[$name . '::' . $label . ' ' . $member] = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
    }

    return $flat;
}

/**
 * Added, removed, breaking and changed keys between two contracts.
 *
 * `removed` lists every key that is gone. `breaking` is the subset caused by Core: a member gone from a symbol Content
 * still uses, or a symbol that no longer exists. A whole symbol leaving because Content stopped importing it is not
 * breaking.
 *
 * @param array<string, array<string, mixed>> $old
 * @param array<string, array<string, mixed>> $new
 *
 * @return array{added: list<string>, removed: list<string>, breaking: list<string>, changed: list<string>}
 */
function contentContractDiff(array $old, array $new): array
{
    $before = contentContractFlatten($old);
    $after = contentContractFlatten($new);
    $changed = [];
    $breaking = [];
    foreach (array_intersect_key($before, $after) as $key => $value) {
        if ($after[$key] !== $value) {
            $changed[] = $key . "\n      was: " . $value . "\n      now: " . $after[$key];
            if (!str_contains($key, '::') && ($new[$key]['kind'] ?? null) === 'missing') {
                $breaking[] = $key;
            }
        }
    }
    $removed = array_keys(array_diff_key($before, $after));
    foreach ($removed as $key) {
        $symbol = explode('::', $key, 2)[0];
        $symbolStillUsed = isset($new[$symbol]) && ($new[$symbol]['kind'] ?? null) !== 'missing';
        $symbolExists = class_exists($symbol) || interface_exists($symbol) || trait_exists($symbol) || enum_exists($symbol);
        if ($symbolStillUsed ? str_contains($key, '::') : !$symbolExists) {
            $breaking[] = $key;
        }
    }

    return [
        'added' => array_keys(array_diff_key($after, $before)),
        'removed' => $removed,
        'breaking' => array_values(array_unique($breaking)),
        'changed' => $changed,
    ];
}

/**
 * True when a difference is empty.
 *
 * @param array<string, list<string>> $diff
 */
function contentContractSame(array $diff): bool
{
    return $diff['added'] === [] && $diff['removed'] === [] && $diff['changed'] === [];
}

/**
 * Human-readable text of a difference.
 *
 * @param array<string, list<string>> $diff
 */
function contentContractDiffText(array $diff): string
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
 * Encode a contract the way it is stored.
 *
 * @param array<string, array<string, mixed>> $contract
 */
function contentContractEncode(array $contract): string
{
    return json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

/**
 * Output of `git show <rev>:<path>` in the ispa-cms repository, or null when git, the revision or the file is missing.
 */
function contentContractGitShow(string $rev, string $path): ?string
{
    $command = 'git -C ' . escapeshellarg(base_path()) . ' show ' . escapeshellarg($rev . ':' . $path) . ' 2>/dev/null';
    $output = [];
    $code = 1;
    @exec($command, $output, $code);

    return $code === 0 ? implode("\n", $output) . "\n" : null;
}

/**
 * The `version` of a module.json text.
 */
function contentContractVersion(string $moduleJson): string
{
    return (string) (json_decode($moduleJson, true)['version'] ?? '');
}

/**
 * Failure messages of the bump rule for a contract difference and a Core version change (empty when it holds).
 *
 * @param array{added: list<string>, removed: list<string>, breaking: list<string>, changed: list<string>} $diff
 *
 * @return list<string>
 */
function contentContractBumpViolations(array $diff, string $old, string $new): array
{
    if (contentContractSame($diff)) {
        return [];
    }
    $text = contentContractDiffText($diff);
    $oldMajor = (int) explode('.', $old)[0];
    $newMajor = (int) explode('.', $new)[0];
    if ($diff['breaking'] !== []) {
        return $newMajor > $oldMajor ? [] : ["The Core contract lost symbols Content uses; that needs a major Core bump ({$old} -> {$new}) and ships only by deploy:\n{$text}"];
    }
    if (!version_compare($new, $old, '>')) {
        return ["The Core contract changed but cms/Core/module.json version did not go up ({$old} -> {$new}):\n{$text}"];
    }

    return [];
}

test('the stored Content contract matches the code Content uses', function () {
    $current = contentContractBuild();
    $path = base_path(CONTENT_CONTRACT_FILE);

    if (getenv('CONTENT_CONTRACT_WRITE') === '1') {
        file_put_contents($path, contentContractEncode($current));
    }

    expect(is_file($path))->toBeTrue('Missing ' . CONTENT_CONTRACT_FILE . '; regenerate it with CONTENT_CONTRACT_WRITE=1.');
    $stored = json_decode((string) file_get_contents($path), true);
    $diff = contentContractDiff((array) $stored, $current);

    expect(contentContractSame($diff))->toBeTrue("The Core contract Content depends on changed:\n"
        . contentContractDiffText($diff)
        . "\nRegenerate " . CONTENT_CONTRACT_FILE . ' with CONTENT_CONTRACT_WRITE=1 and bump cms/Core/module.json version.');
});

test('a contract change comes with a Core version bump', function () {
    $headContract = contentContractGitShow('HEAD', CONTENT_CONTRACT_FILE);
    $headCore = contentContractGitShow('HEAD', 'cms/Core/module.json');
    if ($headContract === null || $headCore === null) {
        $this->markTestSkipped('No git checkout or no committed contract yet.');
    }

    $working = (array) json_decode((string) file_get_contents(base_path(CONTENT_CONTRACT_FILE)), true);
    $workingCore = (string) file_get_contents(base_path('cms/Core/module.json'));
    $diff = contentContractDiff((array) json_decode($headContract, true), $working);
    $old = contentContractVersion($headCore);
    $new = contentContractVersion($workingCore);

    if (contentContractSame($diff)) {
        // Nothing uncommitted: check the latest commit instead (HEAD against its parent).
        $parentContract = contentContractGitShow('HEAD~1', CONTENT_CONTRACT_FILE);
        $parentCore = contentContractGitShow('HEAD~1', 'cms/Core/module.json');
        if ($parentContract === null || $parentCore === null) {
            expect(true)->toBeTrue();

            return;
        }
        $diff = contentContractDiff((array) json_decode($parentContract, true), (array) json_decode($headContract, true));
        $old = contentContractVersion($parentCore);
        $new = contentContractVersion($headCore);
    }

    expect(contentContractBumpViolations($diff, $old, $new))->toBe([]);
});

test('the bump rule: additions need a higher version, Core removals a higher major, dropped imports no major', function () {
    $old = [
        'App\\A' => ['kind' => 'class', 'methods' => ['run' => 'public function run(): void']],
        'App\\Gone' => ['kind' => 'class'],
        'App\\Constants\\Constant' => ['kind' => 'class'],
    ];
    $added = $old;
    $added['App\\A']['methods']['stop'] = 'public function stop(): void';
    $memberRemoved = $old;
    unset($memberRemoved['App\\A']['methods']);
    $symbolGone = $old;
    $symbolGone['App\\Gone'] = ['kind' => 'missing'];
    $importDropped = $old;
    unset($importDropped['App\\Constants\\Constant']);

    expect(contentContractBumpViolations(contentContractDiff($old, $old), '2026.11.0', '2026.11.0'))->toBe([])
        ->and(contentContractBumpViolations(contentContractDiff($old, $added), '2026.11.0', '2026.11.0'))->toHaveCount(1)
        ->and(contentContractBumpViolations(contentContractDiff($old, $added), '2026.11.0', '2026.12.0'))->toBe([])
        ->and(contentContractBumpViolations(contentContractDiff($old, $memberRemoved), '2026.11.0', '2026.12.0'))->toHaveCount(1)
        ->and(contentContractBumpViolations(contentContractDiff($old, $memberRemoved), '2026.11.0', '2027.0.0'))->toBe([])
        ->and(contentContractBumpViolations(contentContractDiff($old, $symbolGone), '2026.11.0', '2026.12.0'))->toHaveCount(1)
        ->and(contentContractBumpViolations(contentContractDiff($old, $importDropped), '2026.11.0', '2026.12.0'))->toBe([])
        ->and(contentContractBumpViolations(contentContractDiff($old, $importDropped), '2026.11.0', '2026.11.0'))->toHaveCount(1);
});

test('grouped and aliased imports are read', function () {
    $map = contentContractImports("<?php\nuse App\\Models\\{User, Seo as S};\nuse Cms\\Core\\Models\\Tag as T;\nuse App\\Enums\\StatusEnum;\nuse function App\\helper;\n");

    expect($map)->toBe([
        'User' => 'App\\Models\\User',
        'S' => 'App\\Models\\Seo',
        'T' => 'Cms\\Core\\Models\\Tag',
        'StatusEnum' => 'App\\Enums\\StatusEnum',
    ]);
});

test('Core carries a real version that Content declares as its Core range', function () {
    $core = json_decode((string) file_get_contents(base_path('cms/Core/module.json')), true);
    $content = json_decode((string) file_get_contents(base_path('cms/Content/module.json')), true);
    $requires = ModuleVersionConstraint::parseRequires((array) ($content['requires'] ?? []));

    expect($core['version'])->toMatch('/^\d{4}\.\d+\.\d+$/')
        ->and($core['version'])->not->toBe('2026.03.0')
        ->and($requires)->toHaveKey('Core')
        ->and($requires['Core'])->not->toBe('*')
        ->and(ModuleVersionConstraint::satisfies($core['version'], $requires['Core']))->toBeTrue(
            "Content requires core {$requires['Core']} but Core is {$core['version']}."
        );
});

/**
 * A preflight over a temp release folder that holds a copy of the real Core manifest.
 */
function contentContractPreflight(string $releaseDir): InstallPreflight
{
    return new InstallPreflight(
        new ModuleCatalog(null, $releaseDir),
        '8.3.0',
        fn (string $e): bool => true,
        fn (): array => [],
        fn (): array => [],
        fn (): array => [],
        fn (string $k): string => '0',
    );
}

/**
 * A verified-shape Content package manifest with the given module.json requires, normalised to the folder-name object
 * form the package builder writes (`{"Core": ">=..."}`).
 *
 * @param array<array-key, mixed> $requires
 */
function contentContractManifest(array $requires): ReleaseManifest
{
    return ReleaseManifest::fromVerifiedBytes(json_encode([
        'format' => 1, 'name' => 'Content', 'version' => '2099.1.0',
        'zip_sha256' => str_repeat('a', 64), 'zip_size' => 10, 'unpacked_size' => 10,
        'files' => ['Content/module.json' => str_repeat('b', 64)],
        'requires' => ModuleVersionConstraint::parseRequires($requires), 'irreversible' => false, 'custom_code' => true, 'php_min' => '8.1',
        'built_at' => '2026-11-13T10:00:00Z', 'key_id' => '0123456789abcdef',
    ], JSON_UNESCAPED_SLASHES));
}

describe('installer and the Core range', function () {
    beforeEach(function () {
        config(['cache.default' => 'array']);
        Cache::flush();
        Http::preventStrayRequests();

        $base = sys_get_temp_dir() . '/content-contract-' . bin2hex(random_bytes(6));
        mkdir($base . '/release/Core', 0755, true);
        mkdir($base . '/runtime', 0755, true);
        $this->tmp = realpath($base);
        copy(base_path('cms/Core/module.json'), $this->tmp . '/release/Core/module.json');
        RuntimeModules::setPath($this->tmp . '/runtime');
        (new ModuleCatalog(null, $this->tmp . '/release'))->forget();

        config([
            'core.module_packages.install_enabled' => true,
            'core.module_packages.trusted_keys' => ['0123456789abcdef' => base64_encode(str_repeat('k', 32))],
            'core.module_packages.revoked_key_ids' => [],
            'core.module_packages.job_timeout' => 2700,
            'core.module_packages.max_zip_bytes' => 1024,
            'modules.activator' => 'database',
            'queue.default' => 'redis',
            'queue.connections.redis.retry_after' => 3000,
        ]);
        $this->coreVersion = contentContractVersion((string) file_get_contents(base_path('cms/Core/module.json')));
    });

    afterEach(function () {
        RuntimeModules::resetPath();
        exec('rm -rf ' . escapeshellarg($this->tmp));
    });

    test('a Content package whose Core range excludes the installed Core is refused', function () {
        $major = (int) explode('.', $this->coreVersion)[0];
        $result = contentContractPreflight($this->tmp . '/release')
            ->checkUpload(contentContractManifest(['core' => '>=' . ($major + 1) . '.1.0,<' . ($major + 2) . '.0.0']));
        $block = collect($result->blocking)->firstWhere('code', InstallPreflight::REQUIRES_UNMET);

        expect($result->canInstall())->toBeFalse()
            ->and($block)->not->toBeNull()
            ->and($block['details']['unmet'][0])->toMatchArray([
                'module' => 'Core',
                'reason' => 'version',
                'installed_version' => $this->coreVersion,
            ]);
    });

    test('the range Content declares today is accepted by the installed Core', function () {
        $content = json_decode((string) file_get_contents(base_path('cms/Content/module.json')), true);
        $result = contentContractPreflight($this->tmp . '/release')->checkUpload(contentContractManifest((array) $content['requires']));

        expect($result->blockingCodes())->not->toContain(InstallPreflight::REQUIRES_UNMET);
    });
});

test('the object form of Content requires still makes Content a dependent of Core', function () {
    $core = \Nwidart\Modules\Facades\Module::find('Core');
    $content = \Nwidart\Modules\Facades\Module::find('Content');
    if ($content === null || !$content->isEnabled()) {
        $this->markTestSkipped('Content is not enabled in this environment.');
    }

    $controller = app(\Cms\Core\Http\Controllers\V2\Admin\ModuleController::class);
    $call = fn (string $method, $module) => (new ReflectionMethod($controller, $method))->invoke($controller, $module);

    expect(app(\App\Support\Modules\ManagedModuleDisabler::class)->activeDependentsFor('Core'))->toContain('Content')
        ->and($call('activeDependentsFor', $core)->all())->toContain('Content')
        ->and($call('missingDependenciesFor', $content)->all())->toBe([]);
});
