<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\ArtisanSubprocess;
use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\ModuleStatusService;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Jobs\Modules\InstallModuleJob;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Modules\Packages\RuntimeAssets;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

/**
 * uninstall removes every published front-end asset version of the module, after its code, and nothing of
 * any other module. Everything runs in a temp folder; status and artisan collaborators are fakes.
 */
const UNA_NAME = 'ProbeAssetsGone';

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Queue::fake();
    URL::forceScheme('https');
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response('', 500));

    $base = sys_get_temp_dir().'/uninstall-assets-'.bin2hex(random_bytes(6));
    mkdir($base, 0755, true);
    $this->tmp = realpath($base);
    $this->runtimeDir = $this->tmp.'/runtime';
    $this->assetsDir = $this->tmp.'/public-assets';
    $this->releaseDir = $this->tmp.'/release';
    $this->cacheDir = $this->tmp.'/cache';
    foreach ([$this->runtimeDir, $this->assetsDir, $this->releaseDir, $this->cacheDir] as $dir) {
        mkdir($dir, 0755);
    }
    RuntimeModules::setPath($this->runtimeDir);
    RuntimeAssets::setPath($this->assetsDir);

    foreach (['APP_CONFIG_CACHE' => 'config.php', 'APP_ROUTES_CACHE' => 'routes-v7.php', 'APP_EVENTS_CACHE' => 'events.php'] as $var => $file) {
        putenv($var.'='.$this->cacheDir.'/'.$file);
        $_SERVER[$var] = $this->cacheDir.'/'.$file;
    }
    File::put($this->cacheDir.'/routes-v7.php', '<?php');

    $this->statusesFile = $this->tmp.'/statuses.json';
    file_put_contents($this->statusesFile, json_encode(['Core' => true]));
    config([
        'modules.paths.modules' => $this->releaseDir,
        'modules.activators.database.statuses-file' => $this->statusesFile,
        'modules.activators.database.cache_store' => 'array',
        'modules.activators.database.connection' => null,
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => 'test-secret',
        'ispa-cms.platform_hub_url' => 'https://hub.test',
        'core.module_packages.install_enabled' => true,
        'core.module_packages.job_timeout' => 2700,
        'modules.activator' => 'database',
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 3000,
    ]);
    DatabaseActivator::flushProcessState();

    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->andReturn((new Backup())->forceFill(['uuid' => (string) \Illuminate\Support\Str::uuid()]));
    $this->app->instance(BackupGate::class, $gate);
    $this->app->instance(ModuleStatusService::class, new class extends ModuleStatusService {
        public function change(string $name, bool $enabled, ?User $by, string $reason, bool $refreshCaches = true): void
        {
            (new DatabaseActivator(app()))->setActiveByName($name, $enabled);
        }
    });
    $this->app->instance(ArtisanSubprocess::class, new class extends ArtisanSubprocess {
        public function __construct()
        {
        }

        public function run(array $args, int $timeout): ProcessResult
        {
            return new FakeProcessResult(command: implode(' ', $args), exitCode: 0);
        }
    });
});

afterEach(function () {
    foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $var) {
        putenv($var);
        unset($_SERVER[$var]);
    }
    RuntimeModules::resetPath();
    RuntimeAssets::resetPath();
    DatabaseActivator::flushProcessState();
    unaRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function unaRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            unaRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Install $name at $version (folder, link, status row) and publish the given asset versions for it.
 *
 * @param list<string> $assetVersions
 */
function unaInstall(object $t, string $name, string $version, array $assetVersions): void
{
    $dir = $t->runtimeDir.'/.versions/'.$name.'-'.$version;
    $files = [
        'module.json' => json_encode(['name' => $name, 'version' => $version, 'requires' => [], 'providers' => ['Cms\\'.$name.'\\Providers\\'.$name.'ServiceProvider']]),
        'composer.json' => json_encode(['name' => 'ispa/probe', 'autoload' => ['psr-4' => ['Cms\\'.$name.'\\' => 'app/']]]),
        'app/Providers/'.$name.'ServiceProvider.php' => "<?php\n\nnamespace Cms\\".$name."\\Providers;\n",
    ];
    foreach ($files as $path => $content) {
        @mkdir(dirname($dir.'/'.$path), 0755, true);
        file_put_contents($dir.'/'.$path, $content);
    }
    symlink('.versions/'.$name.'-'.$version, $t->runtimeDir.'/'.$name);

    foreach ($assetVersions as $assetVersion) {
        $source = $t->tmp.'/build-'.$name.'-'.$assetVersion;
        @mkdir($source.'/assets', 0755, true);
        file_put_contents($source.'/manifest.json', '{}');
        file_put_contents($source.'/assets/app.js', 'console.log(1);');
        RuntimeAssets::publish($name, $assetVersion, $source);
    }

    $activator = new DatabaseActivator(app());
    $activator->setVersion($name, $version);
    $activator->setActiveByName($name, true);
}

/**
 * A Webmaster admin.
 */
function unaWebmaster(): User
{
    $user = User::query()->create(['name' => 'Uninstall Admin', 'email' => 'una@test.local', 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster '.uniqid(), 'custom' => json_encode(['level' => 0])]);
    DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);

    return $user->fresh();
}

/**
 * Entries (dot entries included) of a folder; empty when it does not exist.
 *
 * @return list<string>
 */
function unaEntries(string $dir): array
{
    return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
}

test('uninstall removes every asset version of the module and nothing else', function () {
    unaInstall($this, UNA_NAME, '1.1.0', ['1.0.0', '1.1.0']);
    unaInstall($this, 'Keeper', '2.0.0', ['2.0.0']);
    file_put_contents($this->assetsDir.'/README.txt', 'not a module');
    Sanctum::actingAs(unaWebmaster());

    $response = $this->deleteJson('/api/v2/admin/utilities/modules/runtime/'.UNA_NAME, ['confirm_name' => UNA_NAME]);
    $response->assertStatus(202);
    app()->call([new InstallModuleJob((string) $response->json('data.uuid')), 'handle']);
    $install = ModuleInstall::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(@lstat($this->runtimeDir.'/'.UNA_NAME))->toBeFalse()
        ->and(unaEntries($this->assetsDir))->toBe(['Keeper', 'README.txt'])
        ->and(unaEntries($this->assetsDir.'/Keeper'))->toBe(['2.0.0'])
        ->and(is_file($this->assetsDir.'/Keeper/2.0.0/assets/app.js'))->toBeTrue()
        ->and(is_link($this->runtimeDir.'/Keeper'))->toBeTrue();
});

test('uninstall of a module without published assets works as before', function () {
    unaInstall($this, UNA_NAME, '1.0.0', []);
    unaInstall($this, 'Keeper', '2.0.0', ['2.0.0']);
    Sanctum::actingAs(unaWebmaster());

    $response = $this->deleteJson('/api/v2/admin/utilities/modules/runtime/'.UNA_NAME, ['confirm_name' => UNA_NAME]);
    $response->assertStatus(202);
    app()->call([new InstallModuleJob((string) $response->json('data.uuid')), 'handle']);

    expect(ModuleInstall::query()->where('uuid', $response->json('data.uuid'))->value('status'))->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(unaEntries($this->assetsDir))->toBe(['Keeper']);
});
