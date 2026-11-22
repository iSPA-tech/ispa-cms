<?php

declare(strict_types=1);

uses(\Tests\TestCase::class);

use App\Support\Modules\ReleaseFirstModuleManifest;
use App\Support\Modules\RuntimeModuleAutoloader;
use App\Support\Modules\RuntimeModules;
use App\Support\Modules\RuntimePrecedence;
use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;
use Illuminate\Filesystem\Filesystem;
use Nwidart\Modules\Contracts\ActivatorInterface;

/**
 * version-aware precedence between the release copy and the runtime copy of a module listed in
 * runtime_modules.json. Every test builds a fake base path (`<tmp>/cms`, `<tmp>/runtime-modules`,
 * `<tmp>/runtime_modules.json`); the real checkout is never touched.
 */
beforeEach(function (): void {
    RuntimeModuleAutoloader::reset();
    RuntimeModules::resetPath();

    $base = sys_get_temp_dir() . '/rtp-' . bin2hex(random_bytes(6));
    mkdir($base . '/cms/Core', 0777, true);
    mkdir($base . '/runtime-modules/.versions', 0777, true);
    $this->base = realpath($base);
    file_put_contents($this->base . '/cms/Core/module.json', json_encode(['name' => 'Core', 'version' => '2026.11.0']));

    // Unique module name per test: the autoloader tests load real classes into this process.
    $this->name = 'Prec' . bin2hex(random_bytes(4));

    /** Release copy cms/<Name> at $version. */
    $this->release = function (string $version, ?string $name = null): void {
        $name ??= $this->name;
        $dir = $this->base . '/cms/' . $name;
        @mkdir($dir . '/app/Providers', 0777, true);
        file_put_contents($dir . '/module.json', json_encode(['name' => $name, 'version' => $version, 'providers' => ["Cms\\{$name}\\Providers\\{$name}ServiceProvider"]]));
        file_put_contents($dir . '/composer.json', json_encode(['autoload' => ['psr-4' => ["Cms\\{$name}\\" => 'app/']]]));
        file_put_contents($dir . "/app/Providers/{$name}ServiceProvider.php", "<?php\nnamespace Cms\\{$name}\\Providers;\nclass {$name}ServiceProvider extends \\Illuminate\\Support\\ServiceProvider { public const COPY = 'release'; }\n");
    };

    /**
     * Runtime copy placed as the installer does (`.versions/<Name>-<version>` + link). $opts: requires (module.json
     * requires), plain (a folder instead of the link), module (raw module.json text), noProvider (bool),
     * linkVersion (version in the link name).
     */
    $this->runtime = function (string $version, array $opts = []): string {
        $name = $this->name;
        $folder = $name . '-' . ($opts['linkVersion'] ?? $version);
        $dir = ($opts['plain'] ?? false) ? $this->base . '/runtime-modules/' . $name : $this->base . '/runtime-modules/.versions/' . $folder;
        @mkdir($dir . '/app/Providers', 0777, true);
        $module = $opts['module'] ?? json_encode([
            'name' => $name,
            'version' => $version,
            'providers' => ["Cms\\{$name}\\Providers\\{$name}ServiceProvider"],
            'files' => [],
            'requires' => $opts['requires'] ?? ['core' => '>=2026.11.0,<2027.0.0'],
        ]);
        file_put_contents($dir . '/module.json', $module);
        file_put_contents($dir . '/composer.json', json_encode(['autoload' => ['psr-4' => ["Cms\\{$name}\\" => 'app/']]]));
        if (! ($opts['noProvider'] ?? false)) {
            file_put_contents($dir . "/app/Providers/{$name}ServiceProvider.php", "<?php\nnamespace Cms\\{$name}\\Providers;\nclass {$name}ServiceProvider extends \\Illuminate\\Support\\ServiceProvider { public const COPY = 'runtime'; }\n");
        }
        if (! ($opts['plain'] ?? false)) {
            symlink('.versions/' . $folder, $this->base . '/runtime-modules/' . $name);
        }

        return $dir;
    };

    /** Write runtime_modules.json (raw string, or a value encoded as JSON). */
    $this->list = function (mixed $payload): void {
        file_put_contents($this->base . '/runtime_modules.json', is_string($payload) ? $payload : json_encode($payload));
    };

    $this->decide = fn (): array => RuntimePrecedence::decide($this->base, $this->name);
});

afterEach(function (): void {
    RuntimeModuleAutoloader::reset();
    (new Filesystem())->deleteDirectory($this->base);
});

test('the runtime copy wins only when listed, installer-placed, newer and accepting the release Core', function () {
    ($this->release)('2026.11.0');
    ($this->runtime)('2026.11.1');
    ($this->list)([$this->name]);

    $decision = ($this->decide)();

    expect($decision['runtime'])->toBeTrue()
        ->and($decision['release_version'])->toBe('2026.11.0')
        ->and($decision['runtime_version'])->toBe('2026.11.1')
        ->and(RuntimePrecedence::cliLine($this->base, $this->name))->toStartWith('runtime ');
});

test('the object form of runtime_modules.json lists a module too', function () {
    ($this->release)('2026.11.0');
    ($this->runtime)('2026.11.1');
    ($this->list)([$this->name => true]);

    expect(($this->decide)()['runtime'])->toBeTrue();
});

/**
 * Matrix: version order × Core accepted/refused × listed/unlisted. Only newer + accepted + listed gives runtime.
 */
dataset('matrix', function () {
    $rows = [];
    foreach (['newer' => '2026.11.1', 'equal' => '2026.11.0', 'older' => '2026.10.9'] as $order => $runtime) {
        foreach (['accepted' => '>=2026.11.0,<2027.0.0', 'refused' => '>=2026.12.0,<2027.0.0'] as $core => $range) {
            foreach (['listed' => true, 'unlisted' => false] as $listed => $isListed) {
                $rows["{$order} {$core} {$listed}"] = [$runtime, $range, $isListed, $order === 'newer' && $core === 'accepted' && $isListed];
            }
        }
    }

    return $rows;
});

test('precedence matrix', function (string $runtimeVersion, string $coreRange, bool $listed, bool $expected) {
    ($this->release)('2026.11.0');
    ($this->runtime)($runtimeVersion, ['requires' => ['core' => $coreRange]]);
    ($this->list)($listed ? [$this->name] : ['SomethingElse']);

    expect(($this->decide)()['runtime'])->toBe($expected);
})->with('matrix');

dataset('broken', [
    'runtime module.json missing' => [['module' => null]],
    'runtime module.json bad JSON' => [['module' => '{"name": ']],
    'runtime module.json a list' => [['module' => '[1,2]']],
    'runtime version not numeric' => [['module' => 'VERSION_TEXT']],
    'no Core range (list form)' => [['requires' => ['core']]],
    'Core range is any' => [['requires' => ['core' => '*']]],
    'no requires at all' => [['requires' => []]],
    'unparsable Core range' => [['requires' => ['core' => '~2026']]],
    'plain folder, not placed by the installer' => [['plain' => true]],
    'link name and version disagree' => [['linkVersion' => '2026.11.2']],
    'provider file missing' => [['noProvider' => true]],
]);

test('a broken or foreign runtime copy falls back to the release copy', function (array $opts) {
    ($this->release)('2026.11.0');
    if (array_key_exists('module', $opts) && $opts['module'] === 'VERSION_TEXT') {
        $opts['module'] = json_encode(['name' => $this->name, 'version' => 'dev-main', 'requires' => ['core' => '>=2026.11.0']]);
    }
    $dir = ($this->runtime)('2026.11.1', array_filter($opts, fn ($v) => $v !== null) + []);
    if (array_key_exists('module', $opts) && $opts['module'] === null) {
        unlink($dir . '/module.json');
    }
    ($this->list)([$this->name]);

    expect(($this->decide)()['runtime'])->toBeFalse();
})->with('broken');

test('a missing, malformed or wrongly shaped runtime_modules.json lists nothing', function (?string $raw) {
    ($this->release)('2026.11.0');
    ($this->runtime)('2026.11.1');
    if ($raw !== null) {
        ($this->list)($raw);
    }

    expect(($this->decide)()['runtime'])->toBeFalse();
})->with([
    'absent' => [null],
    'bad JSON' => ['{"x": '],
    'string' => ['"Prec"'],
    'object with false' => ['{"PLACEHOLDER": false}'],
    'list with a number' => ['[1]'],
]);

test('release copy unreadable, Core unreadable or no runtime copy means release', function () {
    ($this->list)([$this->name]);
    // No release copy, no runtime copy.
    expect(($this->decide)()['runtime'])->toBeFalse();

    RuntimePrecedence::forget();
    ($this->release)('2026.11.0');
    expect(($this->decide)()['runtime'])->toBeFalse();

    RuntimePrecedence::forget();
    ($this->runtime)('2026.11.1');
    file_put_contents($this->base . '/cms/Core/module.json', '{');
    expect(($this->decide)()['runtime'])->toBeFalse();

    RuntimePrecedence::forget();
    file_put_contents($this->base . '/cms/Core/module.json', json_encode(['name' => 'Core', 'version' => '2026.11.0']));
    file_put_contents($this->base . '/cms/' . $this->name . '/module.json', '{');
    expect(($this->decide)()['runtime'])->toBeFalse();
});

test('a runtime link that points outside the versions folder is refused', function () {
    ($this->release)('2026.11.0');
    $dir = ($this->runtime)('2026.11.1');
    unlink($this->base . '/runtime-modules/' . $this->name);
    rename($dir, $this->base . '/elsewhere');
    symlink($this->base . '/elsewhere', $dir);
    symlink('.versions/' . $this->name . '-2026.11.1', $this->base . '/runtime-modules/' . $this->name);
    ($this->list)([$this->name]);

    expect(($this->decide)()['runtime'])->toBeFalse();
});

test('Core and Game names never prefer a runtime copy', function () {
    ($this->list)(['Core', 'GameShop']);

    expect(RuntimePrecedence::decide($this->base, 'Core')['runtime'])->toBeFalse()
        ->and(RuntimePrecedence::decide($this->base, 'GameShop')['runtime'])->toBeFalse();
});

test('satisfies() agrees with ModuleVersionConstraint', function () {
    $cases = [
        ['2026.11.0', '>=2026.11.0,<2027.0.0'], ['2027.0.0', '>=2026.11.0,<2027.0.0'], ['2026.10.9', '>=2026.11.0'],
        ['1.0.0', '=1.0.0'], ['1.0.0', '1.0.0'], ['1.0.1', '<=1.0.0'], ['1.0.0', '*'], ['1.0.0', ''], ['1.0.0', '~1.0'],
        ['1.0.0', '>1.0.0'], ['1.0.0', '>=1.0.0, *'],
    ];
    foreach ($cases as [$version, $constraint]) {
        expect(RuntimePrecedence::satisfies($version, $constraint))->toBe(ModuleVersionConstraint::satisfies($version, $constraint), "{$version} {$constraint}");
    }
});

test('the autoloader prepends a preferred runtime copy and keeps a refused one out', function () {
    ($this->release)('2026.11.0');
    ($this->runtime)('2026.11.1');
    ($this->list)([$this->name]);
    $class = "Cms\\{$this->name}\\Providers\\{$this->name}ServiceProvider";
    // Stand-in for Composer's release mapping, registered first like vendor/autoload.php.
    $release = function (string $c) use ($class): void {
        if ($c === $class) {
            require $this->base . "/cms/{$this->name}/app/Providers/{$this->name}ServiceProvider.php";
        }
    };
    spl_autoload_register($release);

    try {
        RuntimeModuleAutoloader::register($this->base);

        expect(RuntimeModuleAutoloader::preferred())->toBe([$this->name])
            ->and(RuntimeModuleAutoloader::collisions())->toBe([])
            ->and(RuntimeModuleAutoloader::quarantined())->toBe([])
            ->and($class::COPY)->toBe('runtime');
    } finally {
        spl_autoload_unregister($release);
    }
});

test('an unlisted runtime copy of a release module stays a collision as today', function () {
    ($this->release)('2026.11.0');
    ($this->runtime)('2026.11.1');

    RuntimeModuleAutoloader::register($this->base);

    expect(RuntimeModuleAutoloader::preferred())->toBe([])
        ->and(RuntimeModuleAutoloader::collisions())->toBe([$this->name])
        ->and(RuntimeModuleAutoloader::quarantined()[$this->name]['code'] ?? null)->toBe(RuntimeModuleAutoloader::SHADOWS_RELEASE);
});

test('the module manifest keeps the runtime copy when preferred and the release copy otherwise', function (bool $listed, string $expected) {
    ($this->release)('2026.11.0');
    ($this->runtime)('2026.11.1');
    ($this->list)($listed ? [$this->name] : []);
    $activator = Mockery::mock(ActivatorInterface::class);
    $activator->shouldReceive('hasStatus')->andReturn(true);
    $manifest = new ReleaseFirstModuleManifest(new Filesystem(), [$this->base . '/cms/*', $this->base . '/runtime-modules/*'], $this->base . '/modules-cache.php', $activator, $this->base . '/cms', $this->base);

    $dirs = $manifest->getModulesData()->where('name', $this->name)->pluck('module_directory')->all();

    expect($dirs)->toBe([$this->base . '/' . $expected . '/' . $this->name]);
})->with([
    'listed' => [true, 'runtime-modules'],
    'unlisted' => [false, 'cms'],
]);

test('the module manifest keeps the release copy when no base path is given', function () {
    ($this->release)('2026.11.0');
    ($this->runtime)('2026.11.1');
    ($this->list)([$this->name]);
    $activator = Mockery::mock(ActivatorInterface::class);
    $activator->shouldReceive('hasStatus')->andReturn(true);
    $manifest = new ReleaseFirstModuleManifest(new Filesystem(), [$this->base . '/cms/*', $this->base . '/runtime-modules/*'], $this->base . '/modules-cache.php', $activator, $this->base . '/cms');

    expect($manifest->getModulesData()->where('name', $this->name)->pluck('module_directory')->all())->toBe([$this->base . '/cms/' . $this->name]);
});

test('a runtime module.json that is not JSON leaves the release manifest instead of stopping the boot', function () {
    ($this->release)('2026.11.0');
    $dir = ($this->runtime)('2026.11.1');
    file_put_contents($dir . '/module.json', '{');
    ($this->list)([$this->name]);
    $activator = Mockery::mock(ActivatorInterface::class);
    $activator->shouldReceive('hasStatus')->andReturn(true);
    $manifest = new ReleaseFirstModuleManifest(new Filesystem(), [$this->base . '/cms/*', $this->base . '/runtime-modules/*'], $this->base . '/modules-cache.php', $activator, $this->base . '/cms', $this->base);

    expect($manifest->getModulesData()->where('name', $this->name)->pluck('module_directory')->all())->toBe([$this->base . '/cms/' . $this->name]);
});

test('a versions folder that is itself a link is refused', function () {
    ($this->release)('2026.11.0');
    rename($this->base . '/runtime-modules/.versions', $this->base . '/outside-versions');
    symlink($this->base . '/outside-versions', $this->base . '/runtime-modules/.versions');
    ($this->runtime)('2026.11.1');
    ($this->list)([$this->name]);

    expect(($this->decide)())->toMatchArray(['runtime' => false, 'reason' => 'versions folder is a link']);
});
