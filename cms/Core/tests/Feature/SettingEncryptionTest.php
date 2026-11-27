<?php

use Cms\Core\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function () {
    if (!Schema::hasTable('settings')) {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key')->unique();
            $table->string('type', 20)->default('text');
            $table->string('group_name', 50)->nullable();
            $table->string('name', 50)->nullable();
            $table->longText('value')->nullable();
            $table->unsignedBigInteger('enumerate_id')->nullable();
            $table->unsignedBigInteger('language_id')->nullable();
            $table->text('input_setting')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    } else {
        DB::table('settings')->truncate();
    }

    if (!Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    if (!Schema::hasTable('user_settings')) {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('setting_group')->nullable();
            $table->string('setting_key')->nullable();
            $table->text('setting_value')->nullable();
            $table->timestamps();
        });
    }
});

function createEncSetting(array $overrides = []): Setting
{
    return Setting::create(array_merge([
        'setting_key' => 'test.setting_' . random_int(10000, 99999),
        'type' => 'text',
        'group_name' => 'test',
        'name' => 'TEST_SETTING',
        'value' => null,
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ], $overrides));
}

function getRawSettingValue(int $id): ?string
{
    return DB::table('settings')->where('id', $id)->value('value');
}

/**
 * Authenticate as an admin user with all permissions granted.
 */
function actAsAdmin(\Illuminate\Foundation\Testing\TestCase $test): void
{
    $user = \App\Models\User::forceCreate([
        'name' => 'Admin',
        'email' => 'admin_' . random_int(1000, 9999) . '@test.local',
        'password' => bcrypt('password'),
    ]);

    $test->actingAs($user);
    Gate::before(fn () => true);
}

// ── Conditional encryption ────────────────────────────────────────────

test('password setting value is encrypted in database', function () {
    $setting = createEncSetting([
        'type' => 'password',
        'value' => 'my-secret-password',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $raw = getRawSettingValue($setting->id);

    expect($raw)->not->toBe('my-secret-password');
    expect(Crypt::decryptString($raw))->toBe('my-secret-password');
});

test('setting with isEnv=true and isPublic=false is encrypted', function () {
    $setting = createEncSetting([
        'type' => 'text',
        'value' => 'secret-api-key',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $raw = getRawSettingValue($setting->id);

    expect($raw)->not->toBe('secret-api-key');
    expect(Crypt::decryptString($raw))->toBe('secret-api-key');
});

test('setting with isPublic=false is encrypted', function () {
    $setting = createEncSetting([
        'type' => 'text',
        'value' => 'private-value',
        'input_setting' => ['isEnv' => false, 'isPublic' => false],
    ]);

    $raw = getRawSettingValue($setting->id);

    expect($raw)->not->toBe('private-value');
    expect(Crypt::decryptString($raw))->toBe('private-value');
});

test('public non-password setting is stored as plaintext', function () {
    $setting = createEncSetting([
        'type' => 'text',
        'value' => 'hello world',
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ]);

    $raw = getRawSettingValue($setting->id);

    expect($raw)->toBe('hello world');
});

// ── Eloquent update path ──────────────────────────────────────────────

test('updating a secret setting via Eloquent model encrypts the value', function () {
    $setting = createEncSetting([
        'type' => 'password',
        'value' => 'old-password',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $setting->value = 'new-password';
    $setting->save();

    $raw = getRawSettingValue($setting->id);

    expect($raw)->not->toBe('new-password');
    expect(Crypt::decryptString($raw))->toBe('new-password');
});

test('repository update encrypts secret setting value', function () {
    $setting = createEncSetting([
        'setting_key' => 'email.MAIL_PASSWORD',
        'type' => 'password',
        'value' => 'old-pw',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $repo = app(\Cms\Core\Interfaces\SettingInterface::class);
    $repo->update(['id' => $setting->id], ['value' => 'new-pw']);

    $raw = getRawSettingValue($setting->id);

    expect($raw)->not->toBe('new-pw');
    expect(Crypt::decryptString($raw))->toBe('new-pw');
});

// ── get() tolerant decryption ─────────────────────────────────────────

test('encrypted value is decrypted transparently on model access', function () {
    $setting = createEncSetting([
        'type' => 'password',
        'value' => 'transparent-secret',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $fresh = Setting::find($setting->id);

    expect($fresh->value)->toBe('transparent-secret');
});

test('plaintext value is returned as-is on model access', function () {
    $setting = createEncSetting([
        'type' => 'text',
        'value' => 'plain-text-value',
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ]);

    $fresh = Setting::find($setting->id);

    expect($fresh->value)->toBe('plain-text-value');
});

// ── Index masking ─────────────────────────────────────────────────────

test('index API masks secret setting values and reports has_value', function () {
    $this->withoutMiddleware();
    actAsAdmin($this);

    createEncSetting([
        'setting_key' => 'email.MAIL_PASSWORD',
        'type' => 'password',
        'group_name' => 'email',
        'name' => 'MAIL_PASSWORD',
        'value' => 'super-secret',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);
    createEncSetting([
        'setting_key' => 'email.MAIL_API_KEY',
        'type' => 'text',
        'group_name' => 'email',
        'name' => 'MAIL_API_KEY',
        'value' => null,
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $response = $this->getJson('/api/v2/admin/setting?type=email')
        ->assertOk();

    expect($response->getContent())->not->toContain('super-secret');

    $items = collect($response->json('data.data'));
    $mailPw = $items->firstWhere('setting_key', 'email.MAIL_PASSWORD');
    $apiKey = $items->firstWhere('setting_key', 'email.MAIL_API_KEY');

    expect($mailPw)->not->toBeNull()
        ->and($mailPw['value'])->toBe('********')
        ->and($mailPw['has_value'])->toBeTrue()
        ->and($apiKey)->not->toBeNull()
        ->and($apiKey['value'])->toBe('')
        ->and($apiKey['has_value'])->toBeFalse();
});

test('index API returns plaintext for public settings', function () {
    $this->withoutMiddleware();
    actAsAdmin($this);

    createEncSetting([
        'setting_key' => 'test.public_setting',
        'type' => 'text',
        'group_name' => 'test',
        'name' => 'public_setting',
        'value' => 'visible-value',
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ]);

    $response = $this->getJson('/api/v2/admin/setting?type=test')
        ->assertOk();

    $items = collect($response->json('data.data'));
    $publicSetting = $items->firstWhere('setting_key', 'test.public_setting');

    expect($publicSetting)->not->toBeNull()
        ->and($publicSetting['value'])->toBe('visible-value');
});

test('index API returns readable values for env-managed settings that are not secrets', function () {
    $this->withoutMiddleware();
    actAsAdmin($this);

    createEncSetting([
        'setting_key' => 'advanced.THEME_FOLDER',
        'group_name' => 'advanced',
        'name' => 'THEME_FOLDER',
        'value' => 'my-theme',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);
    createEncSetting([
        'setting_key' => 'advanced.MAIL_PASSWORD',
        'type' => 'password',
        'group_name' => 'advanced',
        'name' => 'MAIL_PASSWORD',
        'value' => 'hidden-pw',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $response = $this->getJson('/api/v2/admin/setting?type=advanced')->assertOk();
    $items = collect($response->json('data.data'));

    expect($items->firstWhere('setting_key', 'advanced.THEME_FOLDER')['value'])->toBe('my-theme')
        ->and($items->firstWhere('setting_key', 'advanced.MAIL_PASSWORD')['value'])->toBe('********')
        ->and($response->getContent())->not->toContain('hidden-pw');
});

test('quickUpdate and save store the sent value for env-managed non-secret settings', function () {
    $this->withoutMiddleware();
    actAsAdmin($this);

    $setting = createEncSetting([
        'setting_key' => 'advanced.THEME_FOLDER',
        'group_name' => 'advanced',
        'name' => 'THEME_FOLDER',
        'value' => 'old',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $this->putJson("/api/v2/admin/setting/{$setting->id}/quick", ['value' => 'new', 'type' => 'text'])->assertOk();
    expect(Setting::find($setting->id)->value)->toBe('new');

    $this->postJson('/api/v2/admin/setting/save', ['advanced' => ['advanced.THEME_FOLDER' => '']])->assertOk();
    expect((string) Setting::find($setting->id)->value)->toBe('');
});

// ── Guard masked value in updates ─────────────────────────────────────

test('quickUpdate skips overwrite when masked value is sent for secret setting', function () {
    $this->withoutMiddleware();
    actAsAdmin($this);

    $setting = createEncSetting([
        'type' => 'password',
        'group_name' => 'email',
        'name' => 'MAIL_PASSWORD',
        'value' => 'real-password',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $originalRaw = getRawSettingValue($setting->id);

    $this->putJson("/api/v2/admin/setting/{$setting->id}/quick", [
        'value' => '********',
        'type' => 'password',
    ])->assertOk();

    expect(getRawSettingValue($setting->id))->toBe($originalRaw);
});

test('save endpoint skips overwrite when masked value is sent for secret setting', function () {
    $this->withoutMiddleware();
    actAsAdmin($this);

    $setting = createEncSetting([
        'setting_key' => 'email.MAIL_PASSWORD',
        'type' => 'password',
        'group_name' => 'email',
        'name' => 'MAIL_PASSWORD',
        'value' => 'actual-password',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $originalRaw = getRawSettingValue($setting->id);

    $this->postJson('/api/v2/admin/setting/save', [
        'email' => [
            'email.MAIL_PASSWORD' => '********',
        ],
    ])->assertOk();

    expect(getRawSettingValue($setting->id))->toBe($originalRaw);
});

test('save endpoint keeps a secret when the masked field comes back empty or masked', function () {
    $this->withoutMiddleware();
    actAsAdmin($this);

    $password = createEncSetting([
        'setting_key' => 'email.MAIL_PASSWORD',
        'type' => 'password',
        'group_name' => 'email',
        'name' => 'MAIL_PASSWORD',
        'value' => 'kept-password',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);
    $apiKey = createEncSetting([
        'setting_key' => 'email.MAIL_API_KEY',
        'type' => 'text',
        'group_name' => 'email',
        'name' => 'MAIL_API_KEY',
        'value' => 'kept-api-key',
        'input_setting' => ['isEnv' => false, 'isPublic' => false],
    ]);

    $this->postJson('/api/v2/admin/setting/save', [
        'email' => [
            'email.MAIL_PASSWORD' => '',
            'email.MAIL_API_KEY' => '********',
        ],
    ])->assertOk();

    expect(Setting::find($password->id)->value)->toBe('kept-password')
        ->and(Setting::find($apiKey->id)->value)->toBe('kept-api-key');

    $this->postJson('/api/v2/admin/setting/save', [
        'email' => [
            'email.MAIL_PASSWORD' => 'replaced-password',
        ],
    ])->assertOk();

    expect(Setting::find($password->id)->value)->toBe('replaced-password');
});

test('saving email settings signals queue workers to restart', function () {
    $this->withoutMiddleware();
    actAsAdmin($this);

    $setting = createEncSetting([
        'setting_key' => 'email.MAIL_FROM_ADDRESS',
        'group_name' => 'email',
        'name' => 'MAIL_FROM_ADDRESS',
        'value' => 'old@example.test',
        'input_setting' => ['isEnv' => false, 'isPublic' => false],
    ]);

    Cache::forget('illuminate:queue:restart');

    $this->postJson('/api/v2/admin/setting/save', [
        'email' => [
            $setting->setting_key => 'new@example.test',
        ],
    ])->assertOk();

    expect(Cache::get('illuminate:queue:restart'))->not->toBeNull();
});

// ── Clear a stored secret (quickUpdate with clear: true) ──────────────

/**
 * Point the application at a temporary environment file so a clear never touches a real one.
 */
function useTempEnvFile(object $test, string $content): void
{
    $test->clearEnvDir = sys_get_temp_dir() . '/setting-clear-env-' . bin2hex(random_bytes(6));
    mkdir($test->clearEnvDir, 0700, true);
    file_put_contents($test->clearEnvDir . '/.env', $content);
    app()->useEnvironmentPath($test->clearEnvDir);
}

afterEach(function () {
    if (isset($this->clearEnvDir) && is_dir($this->clearEnvDir)) {
        @unlink($this->clearEnvDir . '/.env');
        @rmdir($this->clearEnvDir);
    }
});

test('quickUpdate with clear empties an env-bound secret, its env line, and audits the key only', function () {
    // A legacy site (settings runtime off) still writes `.env`; set the mode so the site's own .env never decides it.
    config(['ispa-cms.setting_runtime_enabled' => false]);
    $this->withoutMiddleware();
    actAsAdmin($this);
    \Illuminate\Support\Facades\Artisan::spy();
    \Illuminate\Support\Facades\Event::fake([\Cms\Core\Events\AuditLogEvent::class]);
    useTempEnvFile($this, "APP_NAME=Test\nMAIL_PASSWORD=CLEAR-ME-ENV-SECRET\n");

    $setting = createEncSetting([
        'setting_key' => 'email.MAIL_PASSWORD',
        'type' => 'password',
        'group_name' => 'email',
        'name' => 'MAIL_PASSWORD',
        'value' => 'CLEAR-ME-ENV-SECRET',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $response = $this->putJson("/api/v2/admin/setting/{$setting->id}/quick", ['clear' => true])->assertOk();

    expect(getRawSettingValue($setting->id))->toBeIn([null, ''])
        ->and($response->getContent())->not->toContain('CLEAR-ME-ENV-SECRET')
        ->and($response->json('data.has_value'))->toBeFalse()
        ->and($response->json('data'))->not->toHaveKey('value')
        ->and((string) file_get_contents($this->clearEnvDir . '/.env'))->toContain('MAIL_PASSWORD=""')
        ->not->toContain('CLEAR-ME-ENV-SECRET');

    \Illuminate\Support\Facades\Event::assertDispatched(
        \Cms\Core\Events\AuditLogEvent::class,
        fn (\Cms\Core\Events\AuditLogEvent $e): bool => $e->action === 'config.env.clear'
            && $e->changed === ['key' => 'email.MAIL_PASSWORD', 'secret' => true]
    );
});

test('quickUpdate with clear and the settings runtime on empties the env-bound row and leaves the env file alone', function () {
    config(['ispa-cms.setting_runtime_enabled' => true]);
    $this->withoutMiddleware();
    actAsAdmin($this);
    \Illuminate\Support\Facades\Artisan::spy();
    \Illuminate\Support\Facades\Event::fake([\Cms\Core\Events\AuditLogEvent::class]);
    $envContent = "APP_NAME=Test\nMAIL_PASSWORD=KEEP-ENV-LINE-SECRET\n";
    useTempEnvFile($this, $envContent);

    $setting = createEncSetting([
        'setting_key' => 'email.MAIL_PASSWORD',
        'type' => 'password',
        'group_name' => 'email',
        'name' => 'MAIL_PASSWORD',
        'value' => 'CLEAR-ME-ROW-SECRET',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $response = $this->putJson("/api/v2/admin/setting/{$setting->id}/quick", ['clear' => true])->assertOk();

    expect(getRawSettingValue($setting->id))->toBeIn([null, ''])
        ->and($response->getContent())->not->toContain('CLEAR-ME-ROW-SECRET')
        ->and($response->json('data.has_value'))->toBeFalse()
        ->and((string) file_get_contents($this->clearEnvDir . '/.env'))->toBe($envContent);

    \Illuminate\Support\Facades\Event::assertDispatched(
        \Cms\Core\Events\AuditLogEvent::class,
        fn (\Cms\Core\Events\AuditLogEvent $e): bool => $e->action === 'config.env.clear'
            && $e->changed === ['key' => 'email.MAIL_PASSWORD', 'secret' => true]
    );
});

test('quickUpdate with clear empties a secret that is not env-bound and audits the key only', function () {
    $this->withoutMiddleware();
    actAsAdmin($this);
    \Illuminate\Support\Facades\Artisan::spy();
    \Illuminate\Support\Facades\Event::fake([\Cms\Core\Events\AuditLogEvent::class]);
    useTempEnvFile($this, "APP_NAME=Test\n");

    $setting = createEncSetting([
        'setting_key' => 'payment.GATEWAY_SECRET',
        'type' => 'password',
        'group_name' => 'payment',
        'name' => 'GATEWAY_SECRET',
        'value' => 'CLEAR-ME-PLAIN-SECRET',
        'input_setting' => ['isEnv' => false, 'isPublic' => false],
    ]);

    $response = $this->putJson("/api/v2/admin/setting/{$setting->id}/quick", ['clear' => true])->assertOk();

    expect(getRawSettingValue($setting->id))->toBeIn([null, ''])
        ->and($response->getContent())->not->toContain('CLEAR-ME-PLAIN-SECRET')
        ->and($response->json('data.has_value'))->toBeFalse()
        ->and((string) file_get_contents($this->clearEnvDir . '/.env'))->toBe("APP_NAME=Test\n");

    \Illuminate\Support\Facades\Event::assertDispatched(
        \Cms\Core\Events\AuditLogEvent::class,
        fn (\Cms\Core\Events\AuditLogEvent $e): bool => $e->action === 'setting.secret.clear'
            && $e->changed === ['key' => 'payment.GATEWAY_SECRET', 'secret' => true]
    );
});

test('quickUpdate with clear is refused with 422 for a setting that is not a secret', function () {
    $this->withoutMiddleware();
    actAsAdmin($this);
    \Illuminate\Support\Facades\Artisan::spy();
    \Illuminate\Support\Facades\Event::fake([\Cms\Core\Events\AuditLogEvent::class]);

    $setting = createEncSetting([
        'setting_key' => 'advanced.THEME_FOLDER',
        'group_name' => 'advanced',
        'name' => 'THEME_FOLDER',
        'value' => 'my-theme',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    $this->putJson("/api/v2/admin/setting/{$setting->id}/quick", ['clear' => true])->assertStatus(422);

    expect(Setting::find($setting->id)->value)->toBe('my-theme');
    \Illuminate\Support\Facades\Event::assertNotDispatched(\Cms\Core\Events\AuditLogEvent::class);
});

test('quickUpdate with an empty value and no clear flag still keeps the stored secret', function () {
    $this->withoutMiddleware();
    actAsAdmin($this);

    $setting = createEncSetting([
        'setting_key' => 'email.MAIL_PASSWORD',
        'type' => 'password',
        'group_name' => 'email',
        'name' => 'MAIL_PASSWORD',
        'value' => 'keep-me',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);
    $originalRaw = getRawSettingValue($setting->id);

    $this->putJson("/api/v2/admin/setting/{$setting->id}/quick", ['value' => '', 'type' => 'password'])->assertOk();
    $this->putJson("/api/v2/admin/setting/{$setting->id}/quick", ['value' => '', 'type' => 'password', 'clear' => false])->assertOk();

    expect(getRawSettingValue($setting->id))->toBe($originalRaw);
});
