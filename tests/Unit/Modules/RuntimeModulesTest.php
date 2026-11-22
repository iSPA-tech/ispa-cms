<?php

declare(strict_types=1);

uses(\Tests\TestCase::class);

use App\Support\Modules\RuntimeModuleAutoloader;
use App\Support\Modules\RuntimeModules;
use Illuminate\Filesystem\Filesystem;

/**
 * Runtime modules folder: name filter, manifest validation and the
 * namespace-restricted autoloader. Every test builds its own fake base path
 * (`<tmp>/runtime-modules`, `<tmp>/cms`) and removes it afterwards; the real
 * base_path('runtime-modules'), cms/ and modules_statuses.json are untouched.
 */
beforeEach(function (): void {
    RuntimeModuleAutoloader::reset();
    RuntimeModules::resetPath();

    $this->base = sys_get_temp_dir() . '/rtm-' . bin2hex(random_bytes(6));
    $this->runtime = $this->base . '/runtime-modules';
    mkdir($this->runtime, 0777, true);
    mkdir($this->base . '/cms/Core', 0777, true);

    // A unique suffix keeps class names distinct across tests in one process.
    $this->suffix = 'X' . bin2hex(random_bytes(4));

    /**
     * Writes a fixture module. $opts: module (array merged into module.json),
     * composer (array replacing composer.json), noComposer (bool), classes
     * (relative path => PHP source), files (relative path => contents).
     */
    $this->makeModule = function (string $name, array $opts = []): string {
        $dir = $this->runtime . '/' . $name;
        mkdir($dir . '/app', 0777, true);

        $module = array_merge([
            'name' => $name,
            'providers' => [],
            'files' => [],
        ], $opts['module'] ?? []);
        file_put_contents($dir . '/module.json', json_encode($module));

        if (! ($opts['noComposer'] ?? false)) {
            $composer = $opts['composer'] ?? ['autoload' => ['psr-4' => ["Cms\\{$name}\\" => 'app/']]];
            file_put_contents($dir . '/composer.json', json_encode($composer));
        }

        foreach ($opts['classes'] ?? [] as $relative => $source) {
            @mkdir(dirname($dir . '/' . $relative), 0777, true);
            file_put_contents($dir . '/' . $relative, $source);
        }
        foreach ($opts['files'] ?? [] as $relative => $contents) {
            @mkdir(dirname($dir . '/' . $relative), 0777, true);
            file_put_contents($dir . '/' . $relative, $contents);
        }

        return $dir;
    };

    $this->classSource = fn (string $namespace, string $class): string => "<?php\nnamespace {$namespace};\nfinal class {$class} {}\n";
});

afterEach(function (): void {
    RuntimeModuleAutoloader::reset();
    RuntimeModules::resetPath();
    (new Filesystem())->deleteDirectory($this->base);
});

test('path is null without a runtime folder and honours the test override', function (): void {
    expect(RuntimeModules::path($this->base . '/missing'))->toBeNull()
        ->and(RuntimeModules::path($this->base))->toBe(realpath($this->runtime));

    RuntimeModules::setPath($this->runtime);
    expect(RuntimeModules::path())->toBe(realpath($this->runtime));
});

test('path resolves a deploy symlink to the real runtime folder', function (): void {
    // Deploys link <release>/runtime-modules to the shared runtime/modules folder; placement refuses a linked root.
    $shared = $this->base . '/shared-runtime';
    mkdir($shared, 0777, true);
    $release = $this->base . '/release';
    mkdir($release, 0777, true);
    symlink($shared, $release . '/runtime-modules');

    expect(RuntimeModules::path($release))->toBe(realpath($shared))
        ->and(is_link((string) RuntimeModules::path($release)))->toBeFalse();
});

test('names keep studly folders and ignore dot folders, files and bad names', function (): void {
    mkdir($this->runtime . '/.staging');
    mkdir($this->runtime . '/.versions');
    mkdir($this->runtime . '/lowercase');
    mkdir($this->runtime . '/A');
    mkdir($this->runtime . '/Bad-Name');
    mkdir($this->runtime . '/ProbeOne');
    file_put_contents($this->runtime . '/NotADir', 'x');

    expect(RuntimeModules::names($this->runtime))->toBe(['ProbeOne']);
});

test('a valid module validates clean and its class autoloads', function (): void {
    $name = 'Probe' . $this->suffix;
    ($this->makeModule)($name, [
        'module' => ['providers' => ["Cms\\{$name}\\Providers\\ProbeServiceProvider"], 'files' => ['helpers.php']],
        'files' => ['helpers.php' => "<?php\n"],
        'classes' => ['app/Support/Thing.php' => ($this->classSource)("Cms\\{$name}\\Support", 'Thing')],
    ]);

    expect(RuntimeModules::validate($this->runtime . '/' . $name, $name, $this->base . '/cms'))->toBe([]);

    RuntimeModuleAutoloader::register($this->base);

    expect(class_exists("Cms\\{$name}\\Support\\Thing"))->toBeTrue()
        ->and(RuntimeModuleAutoloader::skipped())->toBe([]);
});

test('psr-4 prefixes outside the module namespace skip the module', function (string $prefix): void {
    $name = 'Probe' . $this->suffix;
    ($this->makeModule)($name, [
        'composer' => ['autoload' => ['psr-4' => ["Cms\\{$name}\\" => 'app/', $prefix => 'app/']]],
        'classes' => ['app/Leak.php' => ($this->classSource)("Cms\\{$name}", 'Leak')],
    ]);

    RuntimeModuleAutoloader::register($this->base);

    expect(RuntimeModuleAutoloader::skipped())->toHaveKey($name)
        ->and(class_exists("Cms\\{$name}\\Leak"))->toBeFalse();
})->with([
    'App' => ['App\\'],
    'Cms Core' => ['Cms\\Core\\'],
    'Cms prefix lookalike' => ['Cms\\ProbeOther\\'],
]);

test('psr-4 folders climbing out of the module are invalid', function (string|array $target): void {
    $name = 'Probe' . $this->suffix;
    ($this->makeModule)($name, [
        'composer' => ['autoload' => ['psr-4' => ["Cms\\{$name}\\" => $target]]],
    ]);

    expect(RuntimeModules::validate($this->runtime . '/' . $name, $name, $this->base . '/cms'))->not->toBe([]);

    RuntimeModuleAutoloader::register($this->base);
    expect(RuntimeModuleAutoloader::skipped())->toHaveKey($name);
})->with([
    'parent' => ['../'],
    'nested parent' => ['app/../../other/'],
    'absolute' => ['/tmp/'],
    'array with parent' => [['app/', '../x/']],
]);

test('files, classmap and psr-0 autoload sections are invalid', function (array $autoload): void {
    $name = 'Probe' . $this->suffix;
    ($this->makeModule)($name, [
        'composer' => ['autoload' => ['psr-4' => ["Cms\\{$name}\\" => 'app/']] + $autoload],
    ]);

    expect(RuntimeModules::validate($this->runtime . '/' . $name, $name, $this->base . '/cms'))->not->toBe([]);
})->with([
    'files' => [['files' => ['app/helpers.php']]],
    'classmap' => [['classmap' => ['app/']]],
    'psr-0' => [['psr-0' => ['Foo' => 'app/']]],
]);

test('autoload-dev is ignored', function (): void {
    $name = 'Probe' . $this->suffix;
    ($this->makeModule)($name, [
        'composer' => [
            'autoload' => ['psr-4' => ["Cms\\{$name}\\" => 'app/']],
            'autoload-dev' => ['psr-4' => ['App\\' => '../']],
        ],
    ]);

    expect(RuntimeModules::validate($this->runtime . '/' . $name, $name, $this->base . '/cms'))->toBe([]);
});

test('module.json files entries must be relative, inside and present', function (array $files): void {
    $name = 'Probe' . $this->suffix;
    ($this->makeModule)($name, ['module' => ['files' => $files]]);

    expect(RuntimeModules::validate($this->runtime . '/' . $name, $name, $this->base . '/cms'))->not->toBe([]);
})->with([
    'parent' => [['../outside.php']],
    'absolute' => [['/etc/hosts']],
    'missing' => [['helpers.php']],
]);

test('a class file symlinked outside the module is not loaded', function (): void {
    $name = 'Probe' . $this->suffix;
    $dir = ($this->makeModule)($name);
    $outside = $this->base . '/Outside.php';
    file_put_contents($outside, ($this->classSource)("Cms\\{$name}", 'Outside'));
    symlink($outside, $dir . '/app/Outside.php');

    RuntimeModuleAutoloader::register($this->base);

    expect(RuntimeModuleAutoloader::skipped())->toBe([])
        ->and(class_exists("Cms\\{$name}\\Outside"))->toBeFalse();
});

test('a name that exists in cms is invalid and reported as a collision', function (): void {
    ($this->makeModule)('Core');

    expect(RuntimeModules::validate($this->runtime . '/Core', 'Core', $this->base . '/cms'))->not->toBe([]);

    RuntimeModuleAutoloader::register($this->base);
    expect(RuntimeModuleAutoloader::skipped())->toHaveKey('Core')
        ->and(RuntimeModuleAutoloader::collisions())->toBe(['Core']);
});

test('module.json name mismatch is invalid', function (): void {
    $name = 'Probe' . $this->suffix;
    ($this->makeModule)($name, ['module' => ['name' => 'Other']]);

    expect(RuntimeModules::validate($this->runtime . '/' . $name, $name, $this->base . '/cms'))->not->toBe([]);
});

test('a provider outside the module namespace is invalid', function (): void {
    $name = 'Probe' . $this->suffix;
    ($this->makeModule)($name, ['module' => ['providers' => ['App\\Providers\\EvilServiceProvider']]]);

    expect(RuntimeModules::validate($this->runtime . '/' . $name, $name, $this->base . '/cms'))->not->toBe([]);
});

test('a missing composer.json is invalid', function (): void {
    $name = 'Probe' . $this->suffix;
    ($this->makeModule)($name, ['noComposer' => true]);

    expect(RuntimeModules::validate($this->runtime . '/' . $name, $name, $this->base . '/cms'))->not->toBe([]);
});

test('register returns at once without a runtime folder', function (): void {
    $before = count(spl_autoload_functions());

    RuntimeModuleAutoloader::register($this->base . '/missing');

    expect(count(spl_autoload_functions()))->toBe($before)
        ->and(RuntimeModuleAutoloader::skipped())->toBe([]);
});

test('reset unregisters the module loaders', function (): void {
    $name = 'Probe' . $this->suffix;
    ($this->makeModule)($name);
    $before = count(spl_autoload_functions());

    RuntimeModuleAutoloader::register($this->base);
    expect(count(spl_autoload_functions()))->toBe($before + 1);

    RuntimeModuleAutoloader::reset();
    expect(count(spl_autoload_functions()))->toBe($before);
});

test('Core and Game* names are refused even when the release ships no module of that name ( F1)', function (string $name): void {
    ($this->makeModule)($name);
    mkdir($this->base . '/empty-release', 0777, true);

    $errors = RuntimeModules::validate($this->runtime . '/' . $name, $name, $this->base . '/empty-release');

    expect(RuntimeModules::isForbiddenName($name))->toBeTrue()
        ->and($errors)->toContain("module name '{$name}' is reserved: Core and Game* modules are never installed at runtime");
})->with(['Core', 'GameProbe', 'GAMEProbe', 'Gameplay']);

test('ordinary names are not forbidden', function (string $name): void {
    expect(RuntimeModules::isForbiddenName($name))->toBeFalse();
})->with(['Journal', 'Widget', 'Corelink', 'Gamma', 'Ecommerce']);
