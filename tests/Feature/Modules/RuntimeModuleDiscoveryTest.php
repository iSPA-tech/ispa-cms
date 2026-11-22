<?php

declare(strict_types=1);

uses(\Tests\TestCase::class);

use App\Support\Modules\RuntimeModuleAutoloader;
use Illuminate\Filesystem\Filesystem;
use Nwidart\Modules\Activators\FileActivator;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Laravel\LaravelFileRepository;
use Nwidart\Modules\ModuleManifest;

/**
 * nwidart discovers a module in the runtime folder through `modules.scan`, and
 * the file activator decides whether its provider is registered. A fresh
 * repository, activator and manifest are built per test over a temp base path
 * and a temp statuses file; the real modules_statuses.json is never touched.
 */
beforeEach(function (): void {
    RuntimeModuleAutoloader::reset();

    $this->base = sys_get_temp_dir() . '/rtd-' . bin2hex(random_bytes(6));
    $this->runtime = $this->base . '/runtime-modules';
    $this->name = 'ProbeRuntime' . 'X' . bin2hex(random_bytes(4));
    $this->provider = "Cms\\{$this->name}\\Providers\\ProbeServiceProvider";

    $dir = $this->runtime . '/' . $this->name;
    mkdir($dir . '/app/Providers', 0777, true);
    file_put_contents($dir . '/module.json', json_encode([
        'name' => $this->name,
        'priority' => 0,
        'providers' => [$this->provider],
        'files' => [],
    ]));
    file_put_contents($dir . '/composer.json', json_encode([
        'autoload' => ['psr-4' => ["Cms\\{$this->name}\\" => 'app/']],
    ]));
    file_put_contents($dir . '/app/Providers/ProbeServiceProvider.php', "<?php\nnamespace Cms\\{$this->name}\\Providers;\n"
        . "final class ProbeServiceProvider extends \\Illuminate\\Support\\ServiceProvider {}\n");

    $this->statusesFile = tempnam(sys_get_temp_dir(), 'rtd-json-');

    /** Builds the repository + manifest for the given status and registers the enabled providers. */
    $this->discover = function (bool $enabled): array {
        file_put_contents($this->statusesFile, json_encode([$this->name => $enabled]));
        config([
            'modules.scan.enabled' => true,
            'modules.scan.paths' => [$this->runtime],
            'modules.activator' => 'file',
            'modules.activators.file.statuses-file' => $this->statusesFile,
        ]);
        $activator = new FileActivator($this->app);
        $this->app->instance(ActivatorInterface::class, $activator);

        $repository = new LaravelFileRepository($this->app, config('modules.paths.modules'));
        $manifest = new ModuleManifest(new Filesystem(), $repository->getScanPaths(), $this->statusesFile . '.manifest', $activator);
        $providers = $manifest->getProviders();
        foreach ($providers as $provider) {
            $this->app->register($provider);
        }

        return [$repository, $providers];
    };

    RuntimeModuleAutoloader::register($this->base);
});

afterEach(function (): void {
    RuntimeModuleAutoloader::reset();
    (new Filesystem())->deleteDirectory($this->base);
    @unlink($this->statusesFile);
});

test('an enabled runtime module is found and its provider registers', function (): void {
    [$repository, $providers] = ($this->discover)(true);

    $module = $repository->find($this->name);

    expect($module)->not->toBeNull()
        ->and($module->getPath())->toBe($this->runtime . '/' . $this->name)
        ->and($module->isEnabled())->toBeTrue()
        ->and($repository->find('Core'))->not->toBeNull()
        ->and($providers)->toContain($this->provider)
        ->and($this->app->getProvider($this->provider))->not->toBeNull();
});

test('a disabled runtime module is found but its provider does not register', function (): void {
    [$repository, $providers] = ($this->discover)(false);

    expect($repository->find($this->name))->not->toBeNull()
        ->and($repository->find($this->name)->isEnabled())->toBeFalse()
        ->and($providers)->not->toContain($this->provider)
        ->and($this->app->getProvider($this->provider))->toBeNull();
});

test('the configured scan path is the runtime folder and follows its presence', function (): void {
    $config = require base_path('config/modules.php');

    expect($config['scan']['paths'])->toBe([base_path('runtime-modules')])
        ->and($config['scan']['enabled'])->toBe(is_dir(base_path('runtime-modules')));
});
