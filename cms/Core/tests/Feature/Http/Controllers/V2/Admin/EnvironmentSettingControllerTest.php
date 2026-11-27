<?php

declare(strict_types=1);

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Role;
use Cms\Core\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * environment settings admin API (Webmaster only, secrets write-only, values never answered).
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const ESC_BASE = 'https://localhost/api/v2/admin/environment-settings';

/** Secret planted in the database; it must never appear in any response. */
const ESC_SECRET = 'PLANTED-ESC-SECRET-91c0d2';

/**
 * Create an admin user (type ADMIN) with a role of the given level (0 = Webmaster).
 */
function escUser(string $name, ?int $roleLevel): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => UserType::ADMIN,
        'level' => 0,
        'status' => null,
    ]);
    if ($roleLevel !== null) {
        $user->roles()->attach(Role::query()->create(['name' => $name . ' ' . Str::random(4), 'custom' => ['level' => $roleLevel]]));
    }

    return $user;
}

/**
 * Lines of the temporary environment file.
 *
 * @return list<string>
 */
function escEnvLines(object $test): array
{
    return array_values(array_filter(explode("\n", (string) file_get_contents($test->envDir . '/.env')), static fn (string $l): bool => $l !== ''));
}

beforeEach(function (): void {
    $this->realEnvHash = is_file(base_path('.env')) ? hash_file('sha256', base_path('.env')) : null;
    // These cases cover a legacy site (settings runtime off), where a change is also written to `.env`; the mode is
    // set here so the result never depends on the site's own .env. The runtime-on case is the last test below.
    config(['ispa-cms.setting_runtime_enabled' => false]);
    Artisan::spy();

    // User id 1 is always a Webmaster (User::isWebmaster), so take it with a filler account first.
    escUser('filler', null);
    $this->webmaster = escUser('webmaster', 0);
    $this->manager = escUser('manager', 50);

    foreach ([
        ['email.MAIL_PASSWORD', 'password', ESC_SECRET, ['isEnv' => true]],
        ['email.MAIL_HOST', 'text', 'smtp.example.com', ['isPublic' => true]],
        ['api.GOOGLE_API', 'text', null, ['isEnv' => true]],
        ['basic.site_name', 'text', 'Site', ['isEnv' => false, 'isPublic' => true]],
    ] as [$key, $type, $value, $input]) {
        Setting::create([
            'setting_key' => $key, 'type' => $type, 'group_name' => explode('.', $key)[0],
            'name' => explode('.', $key)[1], 'value' => $value, 'input_setting' => $input,
        ]);
    }

    $this->envDir = sys_get_temp_dir() . '/esc-env-' . bin2hex(random_bytes(6));
    mkdir($this->envDir, 0700, true);
    file_put_contents($this->envDir . '/.env', "APP_NAME=Test\nMAIL_HOST=old.example.com\nMAIL_PASSWORD=old\n");
    $this->app->useEnvironmentPath($this->envDir);

    $this->asWebmaster = fn () => Sanctum::actingAs($this->webmaster->fresh(), ['*']);
});

afterEach(function (): void {
    @unlink($this->envDir . '/.env');
    @rmdir($this->envDir);
    expect(is_file(base_path('.env')) ? hash_file('sha256', base_path('.env')) : null)->toBe($this->realEnvHash);
});

/**
 * Every environment route with a sample path.
 *
 * @return list<array{0: string, 1: string}>
 */
function escRoutes(): array
{
    return [
        ['GET', ESC_BASE],
        ['PUT', ESC_BASE . '/email.MAIL_HOST'],
        ['DELETE', ESC_BASE . '/email.MAIL_HOST'],
    ];
}

test('routes use the Webmaster guard and their own named throttle', function (): void {
    $route = Route::getRoutes()->match(Illuminate\Http\Request::create(ESC_BASE . '/email.MAIL_HOST', 'PUT'));
    expect($route->gatherMiddleware())->toContain('admin.webmaster', 'throttle.route:20,1,environment-settings:');
});

test('every route requires authentication', function (): void {
    foreach (escRoutes() as [$method, $url]) {
        $this->json($method, $url, ['value' => 'x'])->assertStatus(401);
    }
});

test('a non-Webmaster admin gets 403 on every route, also in the local environment', function (): void {
    foreach (['testing', 'local'] as $environment) {
        $this->app['env'] = $environment;
        Sanctum::actingAs($this->manager->fresh(), ['*']);

        foreach (escRoutes() as [$method, $url]) {
            $this->json($method, $url, ['value' => 'forged'])
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'WEBMASTER_ONLY');
        }
    }

    expect(Setting::query()->where('setting_key', 'email.MAIL_HOST')->first()->value)->toBe('smtp.example.com')
        ->and(escEnvLines($this))->toContain('MAIL_HOST=old.example.com');
});

test('index lists env rows, shows non-secret values and never a secret value', function (): void {
    ($this->asWebmaster)();

    $response = $this->getJson(ESC_BASE)->assertOk();
    $items = collect($response->json('data.items'))->keyBy('key');

    expect($items->keys()->sort()->values()->all())->toBe(['api.GOOGLE_API', 'email.MAIL_HOST', 'email.MAIL_PASSWORD'])
        ->and($items['email.MAIL_HOST']['value'])->toBe('smtp.example.com')
        ->and($items['email.MAIL_PASSWORD'])->toMatchArray(['secret' => true, 'has_value' => true, 'value' => null])
        ->and($items['api.GOOGLE_API']['has_value'])->toBeFalse()
        ->and($response->getContent())->not->toContain(ESC_SECRET);
});

test('update stores a secret encrypted, writes the env line, and the value is never answered', function (): void {
    Event::fake([AuditLogEvent::class]);
    ($this->asWebmaster)();
    $value = 'Brand-New-Secret-' . Str::random(8);

    $put = $this->putJson(ESC_BASE . '/api.GOOGLE_API', ['value' => $value])->assertOk();
    $index = $this->getJson(ESC_BASE)->assertOk();

    expect($put->getContent())->not->toContain($value)
        ->and($index->getContent())->not->toContain($value)
        ->and(collect($index->json('data.items'))->firstWhere('key', 'api.GOOGLE_API')['has_value'])->toBeTrue()
        ->and(DB::table('settings')->where('setting_key', 'api.GOOGLE_API')->value('value'))->not->toBe($value)
        ->and(escEnvLines($this))->toContain('GOOGLE_API=' . $value);

    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'config.env.update'
        && $e->changed === ['key' => 'api.GOOGLE_API', 'secret' => true]
        && $e->user_id === $this->webmaster->id);
});

test('an injected newline stays on one escaped line', function (): void {
    ($this->asWebmaster)();

    $this->putJson(ESC_BASE . '/email.MAIL_HOST', ['value' => "host a\nAPP_KEY=pwned"])->assertOk();

    $lines = escEnvLines($this);
    expect($lines)->toHaveCount(3)
        ->and($lines)->toContain('MAIL_HOST="host a\\nAPP_KEY=pwned"')
        ->and(collect($lines)->filter(fn (string $l): bool => str_starts_with($l, 'APP_KEY='))->all())->toBe([]);
});

test('unlisted keys and empty values are 422 and write nothing', function (): void {
    Event::fake([AuditLogEvent::class]);
    ($this->asWebmaster)();
    $before = file_get_contents($this->envDir . '/.env');

    foreach (['basic.site_name', 'email.NOT_A_ROW', 'APP_KEY'] as $key) {
        $this->putJson(ESC_BASE . '/' . $key, ['value' => 'forged-value'])->assertStatus(422);
        $this->deleteJson(ESC_BASE . '/' . $key)->assertStatus(422);
    }
    $this->putJson(ESC_BASE . '/email.MAIL_HOST', ['value' => ''])->assertStatus(422);
    $this->putJson(ESC_BASE . '/email.MAIL_HOST', [])->assertStatus(422);
    $bad = $this->putJson(ESC_BASE . '/email.MAIL_HOST', ['value' => "ctl\x1bvalue"])->assertStatus(422);

    expect(file_get_contents($this->envDir . '/.env'))->toBe($before)
        ->and($bad->getContent())->not->toContain("ctl\x1bvalue")
        ->and(Setting::query()->where('setting_key', 'basic.site_name')->first()->value)->toBe('Site')
        ->and(Setting::query()->where('setting_key', 'email.MAIL_HOST')->first()->value)->toBe('smtp.example.com');
    Event::assertNotDispatched(AuditLogEvent::class);
});

test('clear empties the row and the env line and audits only key and secret flag', function (): void {
    Event::fake([AuditLogEvent::class]);
    ($this->asWebmaster)();

    $this->deleteJson(ESC_BASE . '/email.MAIL_PASSWORD')->assertOk()
        ->assertJsonPath('data.has_value', false)
        ->assertJsonPath('data.value', null);

    expect(DB::table('settings')->where('setting_key', 'email.MAIL_PASSWORD')->value('value'))->toBeIn([null, ''])
        ->and(escEnvLines($this))->toContain('MAIL_PASSWORD=""');

    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'config.env.clear'
        && $e->changed === ['key' => 'email.MAIL_PASSWORD', 'secret' => true]);
    Artisan::shouldHaveReceived('call')->with('queue:restart');
});

test('with the settings runtime on, update and clear change only the row and never the env file', function (): void {
    config(['ispa-cms.setting_runtime_enabled' => true]);
    Event::fake([AuditLogEvent::class]);
    ($this->asWebmaster)();
    $before = file_get_contents($this->envDir . '/.env');
    $value = 'Row-Only-Secret-' . Str::random(8);

    $put = $this->putJson(ESC_BASE . '/api.GOOGLE_API', ['value' => $value])->assertOk()
        ->assertJsonPath('data.in_env_file', false);
    $this->putJson(ESC_BASE . '/email.MAIL_HOST', ['value' => 'row.example.com'])->assertOk();
    $this->deleteJson(ESC_BASE . '/email.MAIL_PASSWORD')->assertOk()->assertJsonPath('data.has_value', false);

    expect(file_get_contents($this->envDir . '/.env'))->toBe($before)
        ->and($put->getContent())->not->toContain($value)
        ->and(Setting::query()->where('setting_key', 'api.GOOGLE_API')->first()->value)->toBe($value)
        ->and(DB::table('settings')->where('setting_key', 'api.GOOGLE_API')->value('value'))->not->toBe($value)
        ->and(Setting::query()->where('setting_key', 'email.MAIL_HOST')->first()->value)->toBe('row.example.com')
        ->and(DB::table('settings')->where('setting_key', 'email.MAIL_PASSWORD')->value('value'))->toBeIn([null, '']);

    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'config.env.update'
        && $e->changed === ['key' => 'api.GOOGLE_API', 'secret' => true]);
    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'config.env.clear'
        && $e->changed === ['key' => 'email.MAIL_PASSWORD', 'secret' => true]);
});
