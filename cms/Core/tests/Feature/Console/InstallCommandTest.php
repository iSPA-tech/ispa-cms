<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Database\Seeders\UsersTableSeeder;
use Cms\Core\Services\Platform\HubCredentials;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * `cms:install`: the first-run command of a self-installed site. The heavy steps (module seeders, 12k
 * translation rows) are covered by the sandbox install from the export; these tests use `--skip-seed` and check the
 * preflight, the app key, the admin account seed hook and the one-time setup code.
 */
beforeEach(function () {
    config(['ispa-cms.cms_domain_secret' => '', 'ispa-cms.folder_name' => 'Default']);
    $this->envDir = sys_get_temp_dir().'/cms-install-'.Str::random(8);
    File::ensureDirectoryExists($this->envDir);
    File::put($this->envDir.'/.env', "APP_NAME=Test\nAPP_KEY=\n");
    app()->useEnvironmentPath($this->envDir);
    // cms:install writes public/iPanel/config.js; keep it out of the real public folder.
    $this->originalPublic = public_path();
    app()->usePublicPath($this->envDir.'/public');
});

afterEach(function () {
    app()->usePublicPath($this->originalPublic);
    File::deleteDirectory($this->envDir);
});

it('stops when the project folder of VITE_TEMPLATE_FOLDER does not exist', function () {
    config(['ispa-cms.folder_name' => 'NoSuchProject']);

    $this->artisan('cms:install', ['--skip-seed' => true])
        ->expectsOutputToContain('projects/NoSuchProject')
        ->assertFailed();

    expect(DB::table(HubCredentials::TABLE)->where('name', 'setup_code')->exists())->toBeFalse();
});

it('stops with a clear message when there is no .env file', function () {
    File::delete($this->envDir.'/.env');

    $this->artisan('cms:install', ['--skip-seed' => true])
        ->expectsOutputToContain('.env.example')
        ->assertFailed();
});

it('generates an app key into .env when it is empty', function () {
    config(['app.key' => '']);

    $this->artisan('cms:install', ['--skip-seed' => true])->assertSuccessful();

    expect(File::get($this->envDir.'/.env'))->toMatch('/^APP_KEY=base64:.{40,}$/m');
});

it('keeps an existing app key', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

    $this->artisan('cms:install', ['--skip-seed' => true])->assertSuccessful();

    expect(File::get($this->envDir.'/.env'))->toContain("APP_KEY=\n");
});

it('generates an admin key into .env and config.js when it is empty', function () {
    config(['ispa-cms.admin_auth_key' => '']);
    File::put($this->envDir.'/.env', "APP_NAME=Test\nAPP_KEY=\nISPA_ADMIN_AUTH_KEY=\n");

    $this->artisan('cms:install', ['--skip-seed' => true])
        ->expectsOutputToContain('admin key')
        ->assertSuccessful();

    preg_match('/^ISPA_ADMIN_AUTH_KEY=([0-9a-f]{40})$/m', File::get($this->envDir.'/.env'), $match);
    expect($match)->toHaveCount(2)
        ->and(substr_count(File::get($this->envDir.'/.env'), 'ISPA_ADMIN_AUTH_KEY='))->toBe(1)
        ->and(File::get($this->envDir.'/public/iPanel/config.js'))->toContain($match[1]);
});

it('appends the admin key when the .env has no such line', function () {
    config(['ispa-cms.admin_auth_key' => '']);

    $this->artisan('cms:install', ['--skip-seed' => true])->assertSuccessful();

    expect(File::get($this->envDir.'/.env'))->toMatch('/^ISPA_ADMIN_AUTH_KEY=[0-9a-f]{40}$/m');
});

it('leaves a set admin key untouched', function () {
    config(['ispa-cms.admin_auth_key' => '1234567890']);
    File::put($this->envDir.'/.env', "APP_KEY=\nISPA_ADMIN_AUTH_KEY=1234567890\n");

    $this->artisan('cms:install', ['--skip-seed' => true])
        ->doesntExpectOutputToContain('Generated an admin key')
        ->assertSuccessful();

    expect(File::get($this->envDir.'/.env'))->toContain("ISPA_ADMIN_AUTH_KEY=1234567890\n")
        ->and(File::get($this->envDir.'/public/iPanel/config.js'))->toContain('1234567890');
});

it('keeps the generated admin key on a second run', function () {
    config(['ispa-cms.admin_auth_key' => '']);

    $this->artisan('cms:install', ['--skip-seed' => true])->assertSuccessful();
    preg_match('/^ISPA_ADMIN_AUTH_KEY=(.+)$/m', File::get($this->envDir.'/.env'), $first);

    // A real second run reloads .env, so the config carries the written key.
    config(['ispa-cms.admin_auth_key' => $first[1]]);
    $this->artisan('cms:install', ['--skip-seed' => true])->assertSuccessful();
    preg_match('/^ISPA_ADMIN_AUTH_KEY=(.+)$/m', File::get($this->envDir.'/.env'), $second);

    expect($second[1])->toBe($first[1]);
});

it('generates an API key into .env when it is empty', function () {
    config(['ispa-cms.api_auth_key' => '']);
    File::put($this->envDir.'/.env', "APP_NAME=Test\nAPP_KEY=\nISPA_API_AUTH_KEY=\"\"\n");

    $this->artisan('cms:install', ['--skip-seed' => true])
        ->expectsOutputToContain('API key')
        ->assertSuccessful();

    $env = File::get($this->envDir.'/.env');
    expect($env)->toMatch('/^ISPA_API_AUTH_KEY=[0-9a-f]{40}$/m')
        ->and(substr_count($env, 'ISPA_API_AUTH_KEY='))->toBe(1)
        ->and(config('ispa-cms.api_auth_key'))->toMatch('/^[0-9a-f]{40}$/');
});

it('appends the API key when the .env has no such line', function () {
    config(['ispa-cms.api_auth_key' => '']);

    $this->artisan('cms:install', ['--skip-seed' => true])->assertSuccessful();

    expect(File::get($this->envDir.'/.env'))->toMatch('/^ISPA_API_AUTH_KEY=[0-9a-f]{40}$/m');
});

it('leaves a set API key untouched', function () {
    config(['ispa-cms.api_auth_key' => 'my-api-key']);
    File::put($this->envDir.'/.env', "APP_KEY=\nISPA_API_AUTH_KEY=my-api-key\n");

    $this->artisan('cms:install', ['--skip-seed' => true])
        ->doesntExpectOutputToContain('Generated an API key')
        ->assertSuccessful();

    expect(File::get($this->envDir.'/.env'))->toContain("ISPA_API_AUTH_KEY=my-api-key\n");
});

it('keeps the generated API key on a second run', function () {
    config(['ispa-cms.api_auth_key' => '']);

    $this->artisan('cms:install', ['--skip-seed' => true])->assertSuccessful();
    preg_match('/^ISPA_API_AUTH_KEY=(.+)$/m', File::get($this->envDir.'/.env'), $first);

    config(['ispa-cms.api_auth_key' => $first[1]]);
    $this->artisan('cms:install', ['--skip-seed' => true])->assertSuccessful();
    preg_match('/^ISPA_API_AUTH_KEY=(.+)$/m', File::get($this->envDir.'/.env'), $second);

    expect($second[1])->toBe($first[1]);
});

it('prints the hub setup code once and tells how to get a new one on a second run', function () {
    $this->artisan('cms:install', ['--skip-seed' => true])
        ->expectsOutputToContain('Hub setup code')
        ->assertSuccessful();

    $row = DB::table(HubCredentials::TABLE)->where('name', 'setup_code')->first();
    expect($row)->not->toBeNull()
        ->and((string) $row->value)->not->toMatch('/^([A-Z2-7]{4}-){7}[A-Z2-7]{4}$/');

    $this->artisan('cms:install', ['--skip-seed' => true])
        ->expectsOutputToContain('cms:hub-setup-code')
        ->doesntExpectOutputToContain('Hub setup code:')
        ->assertSuccessful();

    expect(DB::table(HubCredentials::TABLE)->where('name', 'setup_code')->count())->toBe(1);
});

it('does not issue a setup code when the .env already holds hub credentials', function () {
    config(['ispa-cms.cms_domain' => 'env-site.example.com', 'ispa-cms.cms_domain_secret' => 'env-secret-value']);

    $this->artisan('cms:install', ['--skip-seed' => true])
        ->expectsOutputToContain('already has hub credentials')
        ->assertSuccessful();

    expect(DB::table(HubCredentials::TABLE)->where('name', 'setup_code')->exists())->toBeFalse();
});

it('creates the first webmaster from MASTER_EMAIL, MASTER_USERNAME and MASTER_PASSWORD in the seeder', function () {
    foreach (['MASTER_EMAIL' => 'owner@install.example.test', 'MASTER_NAME' => 'Site Owner', 'MASTER_USERNAME' => 'siteowner', 'MASTER_PASSWORD' => 'Pw-'.Str::random(16)] as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    // users.status references enumerates.enum_key; the module seed creates this row in a real install.
    DB::table('enumerates')->insert(['name' => 'Active', 'enum_key' => 'user_active', 'type' => 'user_status']);

    (new UsersTableSeeder())->run();

    $user = User::query()->where('email', 'owner@install.example.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->username)->toBe('siteowner')
        ->and($user->name)->toBe('Site Owner')
        ->and((int) $user->type)->toBe(UserType::WEBMASTER)
        ->and(User::query()->where('email', 'jane@example.test')->exists())->toBeFalse();

    foreach (['MASTER_EMAIL', 'MASTER_NAME', 'MASTER_USERNAME', 'MASTER_PASSWORD'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
});

it('does not rewrite config/setting.php with --skip-seed', function () {
    $path = base_path('config/setting.php');
    $before = is_file($path) ? hash_file('sha256', $path) : null;

    $this->artisan('cms:install', ['--skip-seed' => true])
        ->doesntExpectOutputToContain('Writing the site settings')
        ->assertSuccessful();

    clearstatcache();
    expect(is_file($path) ? hash_file('sha256', $path) : null)->toBe($before);
});

/*
 * The first webmaster's username and password ("lúc cài đặt, thông tin này có để user tự nhập được
 * không?"). The module seed is replaced by the Core user seeder, the language packs and settings writers by no-ops.
 */
function installWithStandInSeed(): void
{
    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->registerCommand(new class extends \Illuminate\Console\Command {
        protected $signature = 'cms:modules-sync';

        public function handle(): int
        {
            (new UsersTableSeeder())->run();

            return self::SUCCESS;
        }
    });
    foreach (['cms:translation-generate {--public-only}', 'cms:setting-initialize {--updateEnv=}'] as $signature) {
        $kernel->registerCommand(new class($signature) extends \Illuminate\Console\Command {
            public function __construct(string $signature)
            {
                $this->signature = $signature;
                parent::__construct();
            }

            public function handle(): int
            {
                return self::SUCCESS;
            }
        });
    }
    // users.status references enumerates.enum_key; the module seed creates this row in a real install.
    DB::table('enumerates')->insert(['name' => 'Active', 'enum_key' => 'user_active', 'type' => 'user_status']);
}

function installPasswordQuestion(): string
{
    return 'Password of the first webmaster (at least '.\Cms\Core\Console\Commands\InstallCommand::MIN_PASSWORD_LENGTH.' characters; leave empty to generate one)';
}

it('asks a person for the email, the username and a password typed twice, and never prints that password', function () {
    installWithStandInSeed();

    $this->artisan('cms:install')
        ->expectsQuestion('Email of the first webmaster', 'owner@install.example.test')
        ->expectsQuestion('Username of the first webmaster', 'SiteOwner')
        ->expectsQuestion(installPasswordQuestion(), 'correct-horse-battery')
        ->expectsQuestion('Type the password again', 'correct-horse-battery')
        ->expectsOutputToContain('username: siteowner')
        ->expectsOutputToContain('the one you typed')
        ->doesntExpectOutputToContain('correct-horse-battery')
        ->assertSuccessful();

    $user = User::query()->where('email', 'owner@install.example.test')->firstOrFail();
    expect($user->username)->toBe('siteowner')
        ->and(\Illuminate\Support\Facades\Hash::check('correct-horse-battery', $user->password))->toBeTrue();
});

it('asks again when the password is too short or the two entries differ', function () {
    installWithStandInSeed();

    $this->artisan('cms:install')
        ->expectsQuestion('Email of the first webmaster', 'owner@install.example.test')
        ->expectsQuestion('Username of the first webmaster', 'webmaster')
        ->expectsQuestion(installPasswordQuestion(), 'short')
        ->expectsOutputToContain('needs '.\Cms\Core\Console\Commands\InstallCommand::MIN_PASSWORD_LENGTH.' to 255 characters')
        ->expectsQuestion(installPasswordQuestion(), 'correct-horse-battery')
        ->expectsQuestion('Type the password again', 'correct-horse-staple')
        ->expectsOutputToContain('not the same')
        ->expectsQuestion(installPasswordQuestion(), 'correct-horse-battery')
        ->expectsQuestion('Type the password again', 'correct-horse-battery')
        ->assertSuccessful();

    expect(\Illuminate\Support\Facades\Hash::check('correct-horse-battery', User::query()->where('email', 'owner@install.example.test')->value('password')))->toBeTrue();
});

it('generates the password and prints it once when the answer is empty', function () {
    installWithStandInSeed();

    $this->artisan('cms:install')
        ->expectsQuestion('Email of the first webmaster', 'owner@install.example.test')
        ->expectsQuestion('Username of the first webmaster', 'webmaster')
        ->expectsQuestion(installPasswordQuestion(), '')
        ->expectsOutputToContain('shown once')
        ->expectsOutputToContain('username: webmaster')
        ->assertSuccessful();

    expect(User::query()->where('email', 'owner@install.example.test')->value('username'))->toBe('webmaster');
});

it('asks nothing with --no-interaction: username from the option or webmaster, generated password', function () {
    installWithStandInSeed();

    $this->artisan('cms:install', ['--admin-email' => 'ops@install.example.test', '--admin-username' => 'ops', '--no-interaction' => true])
        ->expectsOutputToContain('username: ops')
        ->expectsOutputToContain('shown once')
        ->assertSuccessful();

    expect(User::query()->where('email', 'ops@install.example.test')->value('username'))->toBe('ops');
});

it('stops before any change when --admin-username is not a valid username', function () {
    $this->artisan('cms:install', ['--admin-email' => 'ops@install.example.test', '--admin-username' => 'a b', '--no-interaction' => true])
        ->expectsOutputToContain('--admin-username')
        ->assertFailed();

    expect(User::query()->where('email', 'ops@install.example.test')->exists())->toBeFalse();
});

it('cms:install prints the runner line on a core-package install', function () {
    $base = $this->envDir.'/site';
    File::ensureDirectoryExists($base);
    File::put($base.'/core-manifest.json', '{}');
    File::put($base.'/artisan', '<?php');
    $requests = new \Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests(app(\Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder::class), $base);
    app()->instance(\Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests::class, $requests);
    $setup = $requests->runnerSetup();

    $this->artisan('cms:install', ['--skip-seed' => true])
        ->expectsOutputToContain('One-click core updates')
        ->expectsOutputToContain($setup['user'])
        ->expectsOutputToContain($setup['line'])
        ->assertSuccessful();
});

it('prints nothing about the runner without core-manifest.json', function () {
    $base = $this->envDir.'/site';
    File::ensureDirectoryExists($base);
    app()->instance(\Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests::class, new \Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests(app(\Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder::class), $base));

    $this->artisan('cms:install', ['--skip-seed' => true])
        ->doesntExpectOutputToContain('One-click core updates')
        ->doesntExpectOutputToContain('--run-requested')
        ->assertSuccessful();
});
