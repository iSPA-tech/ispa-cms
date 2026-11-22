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
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Install\ModuleUninstaller;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

/**
 * uninstall of a runtime module. Everything runs in a temp folder; status and artisan collaborators are
 * fakes; no request leaves the process.
 */
const UN_NAME = 'ProbeUninstall';
const UN_TABLE = 'probe_uninstall_items';

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Queue::fake();
    URL::forceScheme('https');
    Http::preventStrayRequests();

    $base = sys_get_temp_dir().'/install-uninstall-'.bin2hex(random_bytes(6));
    mkdir($base, 0755, true);
    $this->tmp = realpath($base);
    $this->runtimeDir = $this->tmp.'/runtime';
    $this->releaseDir = $this->tmp.'/release';
    $this->cacheDir = $this->tmp.'/cache';
    mkdir($this->runtimeDir, 0755);
    mkdir($this->releaseDir, 0755);
    mkdir($this->cacheDir, 0755);
    RuntimeModules::setPath($this->runtimeDir);

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

    $this->backupUuid = (string) \Illuminate\Support\Str::uuid();
    $this->backupCalls = 0;
    $t = $this;
    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->andReturnUsing(function () use ($t) {
        $t->backupCalls++;

        return (new Backup())->forceFill(['uuid' => $t->backupUuid]);
    });
    $this->app->instance(BackupGate::class, $gate);
    $this->statuses = unStatuses();
    $this->app->instance(ModuleStatusService::class, $this->statuses);
    $this->artisan = unArtisan();
    $this->app->instance(ArtisanSubprocess::class, $this->artisan);

    $this->hubSent = [];
    $this->hub = null;
    Http::fake(function (ClientRequest $sent) use ($t) {
        $t->hubSent[] = $sent->method().' '.$sent->url();
        $handler = $t->hub ?? null;

        return $handler !== null ? $handler($sent) : Http::response('', 500);
    });
});

afterEach(function () {
    foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $var) {
        putenv($var);
        unset($_SERVER[$var]);
    }
    RuntimeModules::resetPath();
    DatabaseActivator::flushProcessState();
    Schema::dropIfExists(UN_TABLE);
    unRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function unRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            unRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Install $name at $version the way leaves it: `.versions/<Name>-<ver>` plus the link, an upgrade's
 * `.previous/<Name>-<old>` folder (with the `.versions` stub link), and the status row.
 *
 * @param list<string>|null $tables `uninstall.tables` of module.json (null: not declared)
 * @param array<array-key, string> $requires
 */
function unInstall(object $t, string $name, string $version = '1.0.0', bool $enabled = true, ?array $tables = null, array $requires = [], bool $withPrevious = true): void
{
    $json = ['name' => $name, 'version' => $version, 'requires' => $requires, 'providers' => ['Cms\\'.$name.'\\Providers\\'.$name.'ServiceProvider']];
    if ($tables !== null) {
        $json['uninstall'] = ['tables' => $tables];
    }
    $files = [
        'module.json' => json_encode($json),
        'composer.json' => json_encode(['name' => 'ispa/probe', 'autoload' => ['psr-4' => ['Cms\\'.$name.'\\' => 'app/']]]),
        'app/Providers/'.$name.'ServiceProvider.php' => "<?php\n\nnamespace Cms\\".$name."\\Providers;\n",
    ];
    $dir = $t->runtimeDir.'/.versions/'.$name.'-'.$version;
    foreach ($files as $path => $content) {
        @mkdir(dirname($dir.'/'.$path), 0755, true);
        file_put_contents($dir.'/'.$path, $content);
    }
    symlink('.versions/'.$name.'-'.$version, $t->runtimeDir.'/'.$name);

    if ($withPrevious) {
        @mkdir($t->runtimeDir.'/.previous/'.$name.'-0.9.0', 0755, true);
        file_put_contents($t->runtimeDir.'/.previous/'.$name.'-0.9.0/module.json', json_encode(['name' => $name, 'version' => '0.9.0']));
        symlink('../.previous/'.$name.'-0.9.0', $t->runtimeDir.'/.versions/'.$name.'-0.9.0');
    }

    $activator = new DatabaseActivator(app());
    $activator->setVersion($name, $version);
    $activator->setActiveByName($name, $enabled);
}

/**
 * Give an installed module a migration that creates $table (the way a real module owns its tables).
 */
function unMigration(object $t, string $name, string $table, string $version = '1.0.0'): void
{
    $dir = $t->runtimeDir.'/.versions/'.$name.'-'.$version.'/database/migrations';
    @mkdir($dir, 0755, true);
    file_put_contents($dir.'/2026_01_01_000000_create_'.$table.'_table.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class {\n    public function up(): void\n    {\n        Schema::create('".$table."', function (\$t) {\n            \$t->id();\n        });\n    }\n};\n");
}

/**
 * Fake ArtisanSubprocess: records argv, runs nothing; commands in $failing exit 1.
 *
 * @param list<string> $failing
 */
function unArtisan(array $failing = []): ArtisanSubprocess
{
    return new class($failing) extends ArtisanSubprocess {
        /** @var list<array<int, string>> */
        public array $calls = [];

        public function __construct(private readonly array $failing)
        {
        }

        public function run(array $args, int $timeout): ProcessResult
        {
            $this->calls[] = $args;
            $failed = in_array($args[0], $this->failing, true);

            return new FakeProcessResult(command: implode(' ', $args), exitCode: $failed ? 1 : 0, errorOutput: $failed ? 'boom' : '');
        }
    };
}

/**
 * Fake ModuleStatusService: records calls and writes only the test-database status row.
 */
function unStatuses(): ModuleStatusService
{
    return new class extends ModuleStatusService {
        /** @var list<array{0: string, 1: bool, 2: int|null, 3: string}> */
        public array $calls = [];

        public function change(string $name, bool $enabled, ?User $by, string $reason, bool $refreshCaches = true): void
        {
            $this->calls[] = [$name, $enabled, $by?->id, $reason];
            (new DatabaseActivator(app()))->setActiveByName($name, $enabled);
        }
    };
}

/**
 * Create an admin user; Webmaster when a level-0 role is attached.
 */
function unAdmin(string $email, bool $webmaster): User
{
    $user = User::query()->create(['name' => 'Uninstall Admin', 'email' => $email, 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    if ($webmaster) {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster '.uniqid(), 'custom' => json_encode(['level' => 0])]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }

    return $user->fresh();
}

/**
 * Call the uninstall endpoint and, when it answered 202, run the queued job.
 *
 * @return array{0: \Illuminate\Testing\TestResponse, 1: ModuleInstall|null}
 */
function unUninstall(object $t, string $name, array $body): array
{
    $response = $t->deleteJson('/api/v2/admin/utilities/modules/runtime/'.$name, $body);
    if ($response->status() !== 202) {
        return [$response, null];
    }
    app()->call([new InstallModuleJob((string) $response->json('data.uuid')), 'handle']);

    return [$response, ModuleInstall::query()->where('uuid', $response->json('data.uuid'))->firstOrFail()];
}

/**
 * Names (not paths) inside the runtime folder, dot folders included.
 *
 * @return list<string>
 */
function unEntries(string $dir): array
{
    return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
}

test('keep data: files, versions and previous folders go, other modules stay, tables are untouched, audited', function () {
    Schema::create(UN_TABLE, function ($table) {
        $table->id();
    });
    DB::table(UN_TABLE)->insert(['id' => 1]);
    unInstall($this, UN_NAME, '1.0.0', true, [UN_TABLE]);
    unInstall($this, 'Keeper', '2.0.0');
    Sanctum::actingAs(unAdmin('un-wm1@test.local', true));

    [$response, $install] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME]);

    $response->assertStatus(202)->assertJsonPath('data.kind', 'uninstall');
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($install->kind)->toBe('uninstall')
        ->and($install->backup_uuid)->toBe($this->backupUuid)
        ->and($this->backupCalls)->toBe(1)
        ->and(@lstat($this->runtimeDir.'/'.UN_NAME))->toBeFalse()
        ->and(array_values(array_filter(unEntries($this->runtimeDir.'/.versions'), fn ($e) => str_starts_with($e, 'Keeper-'))))->toBe(['Keeper-0.9.0', 'Keeper-2.0.0'])
        ->and(array_filter(unEntries($this->runtimeDir.'/.versions'), fn ($e) => str_starts_with($e, UN_NAME.'-')))->toBe([])
        ->and(array_filter(unEntries($this->runtimeDir.'/.previous'), fn ($e) => str_starts_with($e, UN_NAME.'-')))->toBe([])
        ->and(is_dir($this->runtimeDir.'/.previous/Keeper-0.9.0'))->toBeTrue()
        ->and(is_link($this->runtimeDir.'/Keeper'))->toBeTrue()
        ->and(Schema::hasTable(UN_TABLE))->toBeTrue()
        ->and(DB::table(UN_TABLE)->count())->toBe(1)
        ->and($this->statuses->calls)->toBe([[UN_NAME, false, $install->requested_by, 'uninstall']])
        ->and(DB::table('module_statuses')->where('name', UN_NAME)->exists())->toBeFalse()
        ->and(DB::table('module_statuses')->where('name', 'Keeper')->exists())->toBeTrue();

    $commands = array_map(fn (array $a): string => implode(' ', $a), $this->artisan->calls);
    expect(array_filter($commands, fn (string $c) => str_starts_with($c, 'module:migrate')))->toBe([]);

    $audit = AuditLog::query()->where('action', 'action.module.uninstalled')->sole();
    expect($audit->changed)->toMatchArray(['name' => UN_NAME, 'version' => '1.0.0', 'drop_data' => false, 'backup_uuid' => $this->backupUuid]);

    $list = $this->getJson('/api/v2/admin/utilities/modules/installs')->assertOk()->json('data');
    expect($list[0])->toMatchArray(['kind' => 'uninstall', 'name' => UN_NAME, 'status' => 'succeeded']);
});

test('the signed integrity record of the module goes with it, another module keeps its own', function () {
    unInstall($this, UN_NAME, '1.0.0');
    unInstall($this, 'Keeper', '2.0.0');
    @mkdir($this->runtimeDir.'/.integrity', 0755, true);
    file_put_contents($this->runtimeDir.'/.integrity/'.UN_NAME.'.json', '{"record":1}');
    file_put_contents($this->runtimeDir.'/.integrity/Keeper.json', '{"record":2}');
    Sanctum::actingAs(unAdmin('un-wm-int@test.local', true));

    [, $install] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME]);

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(file_exists($this->runtimeDir.'/.integrity/'.UN_NAME.'.json'))->toBeFalse()
        ->and(is_file($this->runtimeDir.'/.integrity/Keeper.json'))->toBeTrue();
});

test('drop data: the down migrations run after the module is disabled, only for a module that declares tables', function () {
    unInstall($this, UN_NAME, '1.0.0', true, [UN_TABLE]);
    unMigration($this, UN_NAME, UN_TABLE);
    Sanctum::actingAs(unAdmin('un-wm2@test.local', true));

    [$response, $install] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME, 'drop_data' => true]);

    $response->assertStatus(202);
    $commands = array_map(fn (array $a): string => implode(' ', $a), $this->artisan->calls);
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($install->confirmed_irreversible)->toBeTrue()
        ->and($commands[0])->toBe('module:migrate-reset '.UN_NAME.' --force')
        ->and($this->statuses->calls[0][1])->toBeFalse()
        ->and(@lstat($this->runtimeDir.'/'.UN_NAME))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'action.module.uninstalled')->sole()->changed)->toMatchArray(['drop_data' => true, 'tables' => [UN_TABLE]]);
});

test('drop data refuses a declared table the core owns and names it; nothing is queued', function () {
    unInstall($this, UN_NAME, '1.0.0', true, [UN_TABLE, 'users']);
    unMigration($this, UN_NAME, UN_TABLE);
    Sanctum::actingAs(unAdmin('un-wm2a@test.local', true));

    [$response] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME, 'drop_data' => true]);

    $response->assertStatus(409)->assertJsonPath('error_code', 'MODULE_TABLE_NOT_OWNED');
    expect($response->json('data.tables'))->toBe(['users'])
        ->and($response->json('message'))->toContain('users')->not->toContain(UN_TABLE)
        ->and(is_link($this->runtimeDir.'/'.UN_NAME))->toBeTrue()
        ->and(ModuleInstall::query()->count())->toBe(0)
        ->and($this->artisan->calls)->toBe([]);
});

test('drop data refuses a table another release module creates, even when the module lists it in its own migration', function () {
    mkdir($this->releaseDir.'/Shipped/database/migrations', 0755, true);
    file_put_contents($this->releaseDir.'/Shipped/module.json', json_encode(['name' => 'Shipped', 'version' => '1.0.0']));
    file_put_contents($this->releaseDir.'/Shipped/database/migrations/2026_01_01_000000_create_shipped_items_table.php', "<?php\nSchema::create(\"shipped_items\", function (\$t) {});\n");
    unInstall($this, UN_NAME, '1.0.0', true, ['shipped_items']);
    unMigration($this, UN_NAME, 'shipped_items');
    Sanctum::actingAs(unAdmin('un-wm2b@test.local', true));

    [$response] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME, 'drop_data' => true]);

    $response->assertStatus(409)->assertJsonPath('error_code', 'MODULE_TABLE_NOT_OWNED');
    expect($response->json('data.tables'))->toBe(['shipped_items']);
});

test('drop data refuses a declared table the module never created', function () {
    unInstall($this, UN_NAME, '1.0.0', true, [UN_TABLE, 'probe_uninstall_other']);
    unMigration($this, UN_NAME, UN_TABLE);
    Sanctum::actingAs(unAdmin('un-wm2c@test.local', true));

    [$response] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME, 'drop_data' => true]);

    $response->assertStatus(409)->assertJsonPath('error_code', 'MODULE_TABLE_NOT_OWNED');
    expect($response->json('data.tables'))->toBe(['probe_uninstall_other']);
});

test('keep data is untouched by a declared shared table', function () {
    unInstall($this, UN_NAME, '1.0.0', true, ['users']);
    Sanctum::actingAs(unAdmin('un-wm2d@test.local', true));

    [, $install] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME]);

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(array_filter(array_map(fn (array $a): string => implode(' ', $a), $this->artisan->calls), fn (string $c) => str_starts_with($c, 'module:migrate')))->toBe([]);
});

test('drop data is refused when the module declares no tables', function () {
    unInstall($this, UN_NAME, '1.0.0', true, null);
    Sanctum::actingAs(unAdmin('un-wm3@test.local', true));

    [$response] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME, 'drop_data' => true]);

    $response->assertStatus(422)->assertJsonPath('error_code', 'MODULE_UNINSTALL_NO_TABLES');
    expect(is_link($this->runtimeDir.'/'.UN_NAME))->toBeTrue()
        ->and(ModuleInstall::query()->count())->toBe(0)
        ->and($this->artisan->calls)->toBe([]);
});

test('a failing down migration keeps the files, leaves the module disabled and audits the failure', function () {
    unInstall($this, UN_NAME, '1.0.0', true, [UN_TABLE]);
    unMigration($this, UN_NAME, UN_TABLE);
    $this->artisan = unArtisan(['module:migrate-reset']);
    $this->app->instance(ArtisanSubprocess::class, $this->artisan);
    Sanctum::actingAs(unAdmin('un-wm4@test.local', true));

    [, $install] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME, 'drop_data' => true]);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleUninstaller::ERROR_MIGRATE_DOWN)
        ->and(is_link($this->runtimeDir.'/'.UN_NAME))->toBeTrue()
        ->and((bool) DB::table('module_statuses')->where('name', UN_NAME)->value('enabled'))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'action.module.uninstall_failed')->sole()->changed)->toMatchArray(['error_code' => ModuleUninstaller::ERROR_MIGRATE_DOWN, 'backup_uuid' => $this->backupUuid])
        ->and(AuditLog::query()->where('action', 'action.module.uninstalled')->exists())->toBeFalse();
});

test('an enabled module that requires it blocks the uninstall and the names come back', function () {
    unInstall($this, UN_NAME, '1.0.0');
    unInstall($this, 'Beta', '1.0.0', true, null, [UN_NAME => '>=1.0.0']);
    unInstall($this, 'Alpha', '1.0.0', true, null, [UN_NAME]);
    unInstall($this, 'Sleeper', '1.0.0', false, null, [UN_NAME]);
    Sanctum::actingAs(unAdmin('un-wm5@test.local', true));

    [$response] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME]);

    $response->assertStatus(409)->assertJsonPath('error_code', 'MODULE_HAS_DEPENDENTS');
    expect($response->json('data.dependents'))->toBe(['Alpha', 'Beta'])
        ->and($response->json('message'))->toContain('Alpha')->toContain('Beta')->not->toContain('Sleeper')
        ->and(is_link($this->runtimeDir.'/'.UN_NAME))->toBeTrue()
        ->and(ModuleInstall::query()->count())->toBe(0);

    $info = $this->getJson('/api/v2/admin/utilities/modules/runtime/'.UN_NAME.'/uninstall')->assertOk()->json('data');
    expect($info['dependents'])->toBe(['Alpha', 'Beta']);
});

test('a dependent that names the module in lower case still blocks the uninstall', function () {
    unInstall($this, UN_NAME, '1.0.0');
    unInstall($this, 'Lister', '1.0.0', true, null, [strtolower(UN_NAME)]);
    unInstall($this, 'Pinner', '1.0.0', true, null, [strtolower(UN_NAME) => '>=1.0.0']);
    Sanctum::actingAs(unAdmin('un-wm-case@test.local', true));

    [$response] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME]);

    $response->assertStatus(409)->assertJsonPath('error_code', 'MODULE_HAS_DEPENDENTS');
    expect($response->json('data.dependents'))->toBe(['Lister', 'Pinner'])
        ->and(is_link($this->runtimeDir.'/'.UN_NAME))->toBeTrue();
});

test('a disabled dependent does not block', function () {
    unInstall($this, UN_NAME, '1.0.0');
    unInstall($this, 'Sleeper', '1.0.0', false, null, [UN_NAME]);
    Sanctum::actingAs(unAdmin('un-wm6@test.local', true));

    [, $install] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME]);

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED);
});

test('release modules and Core are refused, unknown modules are not found', function () {
    mkdir($this->releaseDir.'/Shipped');
    file_put_contents($this->releaseDir.'/Shipped/module.json', json_encode(['name' => 'Shipped', 'version' => '1.0.0']));
    Sanctum::actingAs(unAdmin('un-wm7@test.local', true));

    $this->deleteJson('/api/v2/admin/utilities/modules/runtime/Shipped', ['confirm_name' => 'Shipped'])
        ->assertStatus(422)->assertJsonPath('error_code', 'MODULE_NOT_RUNTIME');
    $this->deleteJson('/api/v2/admin/utilities/modules/runtime/Core', ['confirm_name' => 'Core'])
        ->assertStatus(422)->assertJsonPath('error_code', 'MODULE_CORE_PROTECTED');
    $this->deleteJson('/api/v2/admin/utilities/modules/runtime/Nowhere', ['confirm_name' => 'Nowhere'])
        ->assertStatus(404)->assertJsonPath('error_code', 'MODULE_NOT_INSTALLED');
    expect(ModuleInstall::query()->count())->toBe(0);
});

test('a link that points outside the runtime folder is refused and nothing is removed', function () {
    $outside = $this->tmp.'/outside';
    mkdir($outside);
    file_put_contents($outside.'/module.json', json_encode(['name' => 'Escaper', 'version' => '1.0.0']));
    symlink($outside, $this->runtimeDir.'/Escaper');
    Sanctum::actingAs(unAdmin('un-wm8@test.local', true));

    $this->deleteJson('/api/v2/admin/utilities/modules/runtime/Escaper', ['confirm_name' => 'Escaper'])
        ->assertStatus(422)->assertJsonPath('error_code', 'MODULE_NOT_RUNTIME');
    expect(is_file($outside.'/module.json'))->toBeTrue();
});

test('a plain folder (not a link) is removed too', function () {
    mkdir($this->runtimeDir.'/PlainMod/app', 0755, true);
    file_put_contents($this->runtimeDir.'/PlainMod/module.json', json_encode(['name' => 'PlainMod', 'version' => '1.0.0']));
    (new DatabaseActivator(app()))->setVersion('PlainMod', '1.0.0');
    Sanctum::actingAs(unAdmin('un-wm9@test.local', true));

    [, $install] = unUninstall($this, 'PlainMod', ['confirm_name' => 'PlainMod']);

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(@lstat($this->runtimeDir.'/PlainMod'))->toBeFalse();
});

test('a wrong typed confirmation is refused with 422 and nothing is queued', function () {
    unInstall($this, UN_NAME, '1.0.0');
    Sanctum::actingAs(unAdmin('un-wm10@test.local', true));

    $this->deleteJson('/api/v2/admin/utilities/modules/runtime/'.UN_NAME, ['confirm_name' => 'probeuninstall'])
        ->assertStatus(422)->assertJsonPath('error_code', 'MODULE_CONFIRMATION_REQUIRED');
    $this->deleteJson('/api/v2/admin/utilities/modules/runtime/'.UN_NAME, [])->assertStatus(422);

    expect(ModuleInstall::query()->count())->toBe(0)->and(is_link($this->runtimeDir.'/'.UN_NAME))->toBeTrue();
    Queue::assertNothingPushed();
});

test('an admin who is not a Webmaster gets 403 on both endpoints', function () {
    unInstall($this, UN_NAME, '1.0.0');
    unAdmin('un-filler@test.local', false); // user id 1 is always a Webmaster
    Sanctum::actingAs(unAdmin('un-editor@test.local', false));

    $this->deleteJson('/api/v2/admin/utilities/modules/runtime/'.UN_NAME, ['confirm_name' => UN_NAME])->assertForbidden();
    $this->getJson('/api/v2/admin/utilities/modules/runtime/'.UN_NAME.'/uninstall')->assertForbidden();

    expect(ModuleInstall::query()->count())->toBe(0)->and(is_link($this->runtimeDir.'/'.UN_NAME))->toBeTrue();
});

test('a second uninstall while one runs is refused', function () {
    unInstall($this, UN_NAME, '1.0.0');
    ModuleInstall::query()->create(['name' => 'Other', 'version' => '1.0.0', 'status' => ModuleInstall::STATUS_MIGRATING]);
    Sanctum::actingAs(unAdmin('un-wm11@test.local', true));

    $this->deleteJson('/api/v2/admin/utilities/modules/runtime/'.UN_NAME, ['confirm_name' => UN_NAME])
        ->assertStatus(409)->assertJsonPath('error_code', 'MODULE_INSTALL_RUNNING');
});

test('the info endpoint lists the declared tables, and an undeclared module offers none', function () {
    unInstall($this, UN_NAME, '1.0.0', true, [UN_TABLE, 'probe_uninstall_other']);
    unInstall($this, 'Plain', '1.0.0');
    unInstall($this, 'Odd', '1.0.0', true, ['bad name; drop']);
    Sanctum::actingAs(unAdmin('un-wm12@test.local', true));

    $with = $this->getJson('/api/v2/admin/utilities/modules/runtime/'.UN_NAME.'/uninstall')->assertOk()->json('data');
    $without = $this->getJson('/api/v2/admin/utilities/modules/runtime/Plain/uninstall')->assertOk()->json('data');
    $odd = $this->getJson('/api/v2/admin/utilities/modules/runtime/Odd/uninstall')->assertOk()->json('data');

    expect($with)->toMatchArray(['name' => UN_NAME, 'version' => '1.0.0', 'enabled' => true, 'uninstall_tables' => [UN_TABLE, 'probe_uninstall_other']])
        ->and($without['uninstall_tables'])->toBe([])
        ->and($odd['uninstall_tables'])->toBe([]);
});

test('after the uninstall the hub catalog offers the module as installable again', function () {
    unInstall($this, UN_NAME, '1.0.0');
    $this->hub = function (ClientRequest $sent) {
        $body = json_encode(['modules' => [['name' => UN_NAME, 'latest_version' => '1.0.0', 'versions' => [
            ['version' => '1.0.0', 'revoked' => false, 'requires' => [], 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.1.0'],
        ]]]]);
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

        return Http::response($body, 200, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response('test-secret', 200, $nonce, $body)]);
    };
    $catalog = new ModuleCatalog(null, $this->releaseDir);
    expect(collect($catalog->entries())->firstWhere('name', UN_NAME)['installed'])->toBe('runtime');
    Sanctum::actingAs(unAdmin('un-wm13@test.local', true));

    [, $install] = unUninstall($this, UN_NAME, ['confirm_name' => UN_NAME]);
    $catalog->forget();
    $entry = collect($catalog->entries())->firstWhere('name', UN_NAME);

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($entry['installed'])->toBe('none')
        ->and($entry['installed_version'])->toBeNull();
});

test('the release manifest accepts an optional uninstall.tables list and validates it', function () {
    $base = [
        'format' => 1, 'name' => 'Probe', 'version' => '1.0.0', 'zip_sha256' => str_repeat('a', 64), 'zip_size' => 10, 'unpacked_size' => 10,
        'files' => ['Probe/module.json' => str_repeat('b', 64)], 'requires' => [], 'irreversible' => false, 'custom_code' => false,
        'php_min' => '8.3', 'built_at' => '2026-11-10T10:00:00Z', 'key_id' => '0123456789abcdef',
    ];

    $plain = ReleaseManifest::fromVerifiedBytes(json_encode($base));
    $declared = ReleaseManifest::fromVerifiedBytes(json_encode($base + ['uninstall' => ['tables' => ['probe_items']]]));

    expect($plain->uninstallTables)->toBeNull()
        ->and(array_key_exists('uninstall', $plain->toArray()))->toBeFalse()
        ->and($declared->uninstallTables)->toBe(['probe_items'])
        ->and($declared->toArray()['uninstall'])->toBe(['tables' => ['probe_items']]);

    foreach ([['tables' => ['bad name']], ['tables' => ['a', 'a']], ['tables' => 'x'], ['other' => []], []] as $bad) {
        expect(fn () => ReleaseManifest::fromVerifiedBytes(json_encode($base + ['uninstall' => $bad])))->toThrow(PackageVerificationException::class);
    }
});
