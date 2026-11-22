<?php

declare(strict_types=1);

uses(\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Jobs\Modules\RefreshRuntimeCachesJob;
use App\Providers\AppServiceProvider;
use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\QuarantinedModuleProvider;
use App\Support\Modules\ReleaseFirstModuleManifest;
use App\Support\Modules\ReleaseFirstModuleRepository;
use Illuminate\Support\Facades\Cache;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use App\Support\Modules\RuntimeModuleAutoloader;
use Cms\Core\Console\Commands\ModuleStatusCommand;
use Cms\Core\Models\AuditLog;
use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Nwidart\Modules\Activators\FileActivator;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Laravel\LaravelFileRepository;
use Nwidart\Modules\ModuleManifest;

/**
 * A runtime module whose provider class cannot be loaded must not stop the
 * site from booting. Fixtures live in a temp base path with a temp statuses
 * file; the real runtime-modules folder and modules_statuses.json are never touched.
 */
beforeEach(function (): void {
    RuntimeModuleAutoloader::reset();
    Queue::fake();

    $this->base = sys_get_temp_dir() . '/rtq-' . bin2hex(random_bytes(6));
    $this->runtime = $this->base . '/runtime-modules';
    $this->statusesFile = tempnam(sys_get_temp_dir(), 'rtq-json-');

    /** Create a fixture module; $withRoot false leaves out the psr-4 root folder, $withFile the provider file. */
    $this->makeModule = function (string $suffix, bool $withRoot, bool $withFile): array {
        $name = 'Quar' . $suffix . bin2hex(random_bytes(3));
        $provider = "Cms\\{$name}\\Providers\\BrokenServiceProvider";
        $dir = $this->runtime . '/' . $name;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/module.json', json_encode(['name' => $name, 'priority' => 0, 'providers' => [$provider], 'files' => []]));
        file_put_contents($dir . '/composer.json', json_encode(['autoload' => ['psr-4' => ["Cms\\{$name}\\" => 'app/']]]));
        if ($withRoot) {
            mkdir($dir . '/app/Providers', 0777, true);
        }
        if ($withRoot && $withFile) {
            file_put_contents($dir . '/app/Providers/BrokenServiceProvider.php', "<?php\nnamespace Cms\\{$name}\\Providers;\n"
                . "final class BrokenServiceProvider extends \\Illuminate\\Support\\ServiceProvider {}\n");
        }

        return [$name, $provider];
    };

    /** Rebuild repository + activator over the fixtures, register the autoloader and the enabled providers. */
    $this->boot = function (string $name, bool $enabled, string $kind = 'file', ?string $releasePath = null): array {
        file_put_contents($this->statusesFile, json_encode([$name => $enabled]));
        config([
            'modules.scan.enabled' => true,
            'modules.scan.paths' => [$this->runtime],
            'modules.activator' => $kind,
            'modules.activators.file.statuses-file' => $this->statusesFile,
            'modules.activators.database.statuses-file' => $this->statusesFile,
            'modules.activators.database.cache_store' => 'array',
            'modules.activators.database.connection' => null,
        ]);
        if ($kind === 'database') {
            DatabaseActivator::flushProcessState();
            Cache::store('array')->flush();
            DB::table(DatabaseActivator::TABLE)->where('name', $name)->delete();
            DB::table(DatabaseActivator::TABLE)->insert(['name' => $name, 'enabled' => $enabled]);
        }
        RuntimeModuleAutoloader::register($this->base);
        $activator = $kind === 'database' ? new DatabaseActivator($this->app) : new FileActivator($this->app);
        $this->app->instance(ActivatorInterface::class, $activator);
        $repository = new ReleaseFirstModuleRepository($this->app, $releasePath ?? config('modules.paths.modules'));
        $this->app->instance('modules', $repository);
        \Nwidart\Modules\Facades\Module::clearResolvedInstance('modules');
        $manifest = new ReleaseFirstModuleManifest(new Filesystem(), $repository->getScanPaths(), $this->statusesFile . '.manifest', $activator, $repository->getPath());
        foreach ($manifest->getProviders() as $provider) {
            $this->app->register($provider);
        }

        return [$repository, $activator];
    };
});

/** Create the release copy cms/<Name> with its own module.json and a shadow-only provider in the runtime copy. */
beforeEach(function (): void {
    $this->makeRelease = function (string $name): string {
        mkdir($this->base . '/cms/' . $name, 0777, true);
        file_put_contents($this->base . '/cms/' . $name . '/module.json', json_encode(['name' => $name, 'priority' => 0, 'providers' => [], 'files' => []]));
        file_put_contents($this->runtime . '/' . $name . '/module.json', json_encode([
            'name' => $name, 'priority' => 0, 'providers' => ["Cms\\{$name}\\Providers\\OnlyShadowProvider"], 'files' => [],
        ]));

        return "Cms\\{$name}\\Providers\\OnlyShadowProvider";
    };
});

afterEach(function (): void {
    RuntimeModuleAutoloader::reset();
    \Nwidart\Modules\Facades\Module::clearResolvedInstance('modules');
    (new Filesystem())->deleteDirectory($this->base);
    @unlink($this->statusesFile);
    @unlink($this->statusesFile . '.manifest');
});

test('a missing provider file is quarantined and enabled registration does not throw', function (): void {
    [$name, $provider] = ($this->makeModule)('File', true, false);

    ($this->boot)($name, true);

    $info = RuntimeModuleAutoloader::quarantined()[$name] ?? null;
    expect($info)->not->toBeNull()
        ->and($info['code'])->toBe('PROVIDER_CLASS_MISSING')
        ->and($info['reason'])->toContain($provider)
        ->and($info['path'])->toBe(realpath($this->runtime . '/' . $name))
        ->and($this->app->getProviders(QuarantinedModuleProvider::class))->not->toBeEmpty()
        ->and(class_exists($provider, false))->toBeTrue();
});

test('a missing psr-4 root folder is quarantined with its own code', function (): void {
    [$name] = ($this->makeModule)('Root', false, false);

    ($this->boot)($name, true);

    expect(RuntimeModuleAutoloader::quarantined()[$name]['code'])->toBe('AUTOLOAD_ROOT_MISSING');
});

test('a healthy module is not quarantined', function (): void {
    [$name, $provider] = ($this->makeModule)('Ok', true, true);

    ($this->boot)($name, true);

    expect(RuntimeModuleAutoloader::quarantined())->toBe([])
        ->and(class_exists($provider, false))->toBeTrue()
        ->and($this->app->getProviders(QuarantinedModuleProvider::class))->toBeEmpty();
});

test('boot disables an enabled broken module once and writes one audit row', function (): void {
    [$name] = ($this->makeModule)('Audit', true, false);
    [$repository] = ($this->boot)($name, true);
    expect($repository->find($name)->isEnabled())->toBeTrue();

    $provider = new AppServiceProvider($this->app);
    $provider->quarantineRuntimeModules();
    $provider->quarantineRuntimeModules();

    $rows = AuditLog::query()->where('action', 'action.module.quarantined')->get();
    expect($repository->find($name)->isEnabled())->toBeFalse()
        ->and(json_decode((string) file_get_contents($this->statusesFile), true)[$name])->toBeFalse()
        ->and($rows)->toHaveCount(1);
    Queue::assertPushed(RefreshRuntimeCachesJob::class, 1);
});

test('an already disabled broken module writes nothing', function (): void {
    [$name] = ($this->makeModule)('Off', true, false);
    ($this->boot)($name, false);

    (new AppServiceProvider($this->app))->quarantineRuntimeModules();

    expect(AuditLog::query()->where('action', 'action.module.quarantined')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('module:disable and cms:module-status set off succeed for a broken module', function (): void {
    $this->app->make(Kernel::class)->registerCommand($this->app->make(ModuleStatusCommand::class));
    [$name] = ($this->makeModule)('Cli', true, false);
    ($this->boot)($name, true);

    $code = Artisan::call('module:disable', ['module' => [$name]]);
    expect($code)->toBe(0);

    ($this->boot)($name, true);
    $code = Artisan::call('cms:module-status', ['action' => 'set', 'name' => $name, 'state' => 'off']);

    expect($code)->toBe(0)
        ->and(json_decode((string) file_get_contents($this->statusesFile), true)[$name])->toBeFalse();
});

test('a module that fails validation is quarantined as MODULE_INVALID, aliased and disabled at boot', function (): void {
    [$name, $provider] = ($this->makeModule)('Inv', true, false);
    // A forbidden autoload section makes validation fail.
    file_put_contents($this->runtime . '/' . $name . '/composer.json', json_encode([
        'autoload' => ['psr-4' => ["Cms\\{$name}\\" => 'app/'], 'files' => ['x.php']],
    ]));

    [$repository] = ($this->boot)($name, true);

    $info = RuntimeModuleAutoloader::quarantined()[$name] ?? null;
    expect($info['code'])->toBe('MODULE_INVALID')
        ->and($info['reason'])->toContain("autoload section 'files'")
        ->and($info['providers'])->toBe([$provider])
        ->and(RuntimeModuleAutoloader::skipped())->toHaveKey($name)
        ->and($this->app->getProviders(QuarantinedModuleProvider::class))->not->toBeEmpty();

    (new AppServiceProvider($this->app))->quarantineRuntimeModules();

    expect($repository->find($name)->isEnabled())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'action.module.quarantined')->count())->toBe(1);
});

test('the module list shows the quarantine fields for a broken module and false for a healthy one', function (): void {
    URL::forceScheme('https');
    [$broken] = ($this->makeModule)('Api', true, false);
    $healthy = 'Core';
    ($this->boot)($broken, true);

    $admin = User::query()->create(['name' => 'Admin', 'email' => 'qa-webmaster@test.local', 'password' => Hash::make('password')]);
    $admin->forceFill(['type' => UserType::ADMIN])->save();
    $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster ' . uniqid(), 'custom' => json_encode(['level' => 0])]);
    DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $admin->id]);
    Sanctum::actingAs($admin->fresh());

    $rows = collect($this->getJson('/api/v2/admin/utilities/modules')->assertOk()->json('data'))->keyBy('name');

    expect($rows[$broken]['quarantined'])->toBeTrue()
        ->and($rows[$broken]['quarantineCode'])->toBe('PROVIDER_CLASS_MISSING')
        ->and($rows[$broken]['quarantineReason'])->toContain('BrokenServiceProvider')
        ->and($rows[$healthy]['quarantined'])->toBeFalse()
        ->and($rows[$healthy]['quarantineCode'])->toBeNull();
});

test('a runtime folder named like a release module is quarantined as MODULE_SHADOWS_RELEASE and never disables it', function (string $kind): void {
    [$name] = ($this->makeModule)('Shadow', true, true);
    ($this->makeRelease)($name);

    [$repository, $activator] = ($this->boot)($name, true, $kind, $this->base . '/cms');
    $provider = new AppServiceProvider($this->app);
    $provider->quarantineRuntimeModules();
    $provider->quarantineRuntimeModules();

    $info = RuntimeModuleAutoloader::quarantined()[$name] ?? null;
    expect($info['code'])->toBe('MODULE_SHADOWS_RELEASE')
        ->and($info['reason'])->toContain($name)
        ->and($info['providers'])->toBe([])
        ->and(RuntimeModuleAutoloader::skipped())->toHaveKey($name)
        ->and($activator->hasStatus($name, true))->toBeTrue()
        ->and($repository->find($name)->isEnabled())->toBeTrue()
        ->and(json_decode((string) file_get_contents($this->statusesFile), true)[$name])->toBeTrue()
        ->and(AuditLog::query()->where('action', 'action.module.quarantined')->count())->toBe(0);
    if ($kind === 'database') {
        expect((bool) DB::table(DatabaseActivator::TABLE)->where('name', $name)->value('enabled'))->toBeTrue();
    }
    Queue::assertNothingPushed();
})->with(['file', 'database']);

test('the install preflight refuses a package whose name exists under cms with MODULE_SHADOWS_RELEASE', function (): void {
    mkdir($this->base . '/cms/Content', 0777, true);
    $catalog = new \Cms\Core\Services\Modules\Install\ModuleCatalog(null, $this->base . '/cms');

    $ref = new ReflectionClass(\Cms\Core\Services\Modules\Packages\ReleaseManifest::class);
    $manifest = $ref->newInstanceWithoutConstructor();
    // The reflection-built manifest must initialise every typed property the preflight reads (`irreversibleMigrations`
    // `keyId` since the publisher badge,).
    foreach (['name' => 'Content', 'version' => '2026.1.0', 'requires' => [], 'irreversible' => false, 'irreversibleMigrations' => [], 'customCode' => false, 'phpMin' => '', 'keyId' => '0000000000000000'] as $prop => $value) {
        $ref->getProperty($prop)->setValue($manifest, $value);
    }

    $codes = (new InstallPreflight($catalog, null, null, fn (): array => [], fn (): array => [], fn (): array => []))
        ->checkUpload($manifest)->blockingCodes();

    expect($codes)->toContain(InstallPreflight::SHADOWS_RELEASE)
        ->and(InstallPreflight::SHADOWS_RELEASE)->toBe('MODULE_SHADOWS_RELEASE');
});

test('release always wins: the module path and manifest providers come from cms, not the shadow folder', function (string $kind): void {
    [$name] = ($this->makeModule)('Win', true, true);
    $shadowProvider = ($this->makeRelease)($name);

    [$repository] = ($this->boot)($name, true, $kind, $this->base . '/cms');
    $manifest = new ReleaseFirstModuleManifest(new Filesystem(), $repository->getScanPaths(), $this->statusesFile . '.manifest2', $this->app->make(ActivatorInterface::class), $repository->getPath());

    expect($repository->find($name)->getPath())->toBe($this->base . '/cms/' . $name)
        ->and($manifest->getProviders())->not->toContain($shadowProvider)
        ->and($manifest->getModulesData()->pluck('module_directory')->all())->toBe([$this->base . '/cms/' . $name]);
    expect(fn () => $this->app->register($shadowProvider))->toThrow(Error::class);
    @unlink($this->statusesFile . '.manifest2');
})->with(['file', 'database']);

test('the container resolves the release-first repository and manifest', function (): void {
    expect($this->app->make(\Nwidart\Modules\Contracts\RepositoryInterface::class))->toBeInstanceOf(ReleaseFirstModuleRepository::class)
        ->and($this->app->make(\Nwidart\Modules\ModuleManifest::class))->toBeInstanceOf(ReleaseFirstModuleManifest::class);
});

test('resetModules clears the static scan cache so a module placed after the first scan is found', function (): void {
    [$first] = ($this->makeModule)('ResetA', true, true);
    [$repository] = ($this->boot)($first, true);
    $cache = new ReflectionProperty(ReleaseFirstModuleRepository::class, 'resolved');

    expect(array_keys($repository->scan()))->toContain(strtolower($first))
        ->and($cache->getValue())->not->toBe([]);

    // A module folder arrives after the first scan (the queue worker case).
    [$second] = ($this->makeModule)('ResetB', true, true);
    $repository->resetModules();

    expect($cache->getValue())->toBe([])
        ->and(array_keys($repository->scan()))->toContain(strtolower($second));
});
