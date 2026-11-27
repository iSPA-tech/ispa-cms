<?php

declare(strict_types=1);

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Environment\EnvironmentSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * environment settings service (allowed rows only, secrets write-only, audit without values).
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Secret planted in the database; it must never be returned, logged or audited. */
const ESS_SECRET = 'PLANTED-ESS-SECRET-4b1e77';

/**
 * Records every CMSLogger call as JSON so tests can search it for values.
 */
final class EssLoggerRecorder
{
    /** @var list<string> */
    public array $lines = [];

    /**
     * Record any logger method call.
     *
     * @param string $method Logger method.
     * @param array<int, mixed> $arguments Arguments.
     */
    public function __call(string $method, array $arguments): mixed
    {
        $this->lines[] = $method . ' ' . json_encode($arguments, JSON_PARTIAL_OUTPUT_ON_ERROR) . ' ' . print_r($arguments, true);

        return null;
    }
}

/**
 * Create a setting row through Eloquent (so the encryption cast runs).
 *
 * @param array<string, mixed> $input
 */
function essSetting(string $key, string $type, ?string $value, array $input): Setting
{
    return Setting::create([
        'setting_key' => $key,
        'type' => $type,
        'group_name' => explode('.', $key)[0],
        'name' => explode('.', $key)[1],
        'value' => $value,
        'input_setting' => $input,
    ]);
}

/**
 * Seed the env-bound fixture rows and a temporary environment file.
 */
function essSeed(object $test): void
{
    essSetting('email.MAIL_PASSWORD', 'password', ESS_SECRET, ['isEnv' => true, 'isPublic' => true]);
    essSetting('email.MAIL_HOST', 'text', 'smtp.example.com', ['isPublic' => true, 'title' => 'text.from.host']);
    essSetting('api.GOOGLE_API', 'text', null, ['isEnv' => true]);
    essSetting('notify.TELEGRAM_CHAT_ID', 'text', '12345', ['isEnv' => true]);
    essSetting('basic.site_name', 'text', 'Site', ['isEnv' => false, 'isPublic' => true]);

    $test->envDir = sys_get_temp_dir() . '/ess-env-' . bin2hex(random_bytes(6));
    mkdir($test->envDir, 0700, true);
    file_put_contents($test->envDir . '/.env', "APP_NAME=Test\nMAIL_HOST=old.example.com\nMAIL_PASSWORD=old\n");
    app()->useEnvironmentPath($test->envDir);
    expect(app()->environmentFilePath())->toBe($test->envDir . '/.env');
}

/**
 * Lines of the temporary environment file.
 *
 * @return list<string>
 */
function essEnvLines(object $test): array
{
    return array_values(array_filter(explode("\n", (string) file_get_contents($test->envDir . '/.env')), static fn (string $l): bool => $l !== ''));
}

beforeEach(function (): void {
    $this->realEnvHash = is_file(base_path('.env')) ? hash_file('sha256', base_path('.env')) : null;
    // These cases cover a legacy site (settings runtime off), where a change is still written to `.env`. With the
    // runtime on the row is the only source: cms/Core/tests/Feature/Settings/EnvBoundSettingsTest.php.
    config(['ispa-cms.setting_runtime_enabled' => false]);
    Artisan::spy();
    essSeed($this);
    $this->service = app(EnvironmentSettingService::class);
});

afterEach(function (): void {
    if (isset($this->envDir) && is_dir($this->envDir)) {
        @unlink($this->envDir . '/.env');
        @rmdir($this->envDir);
    }
    expect(is_file(base_path('.env')) ? hash_file('sha256', base_path('.env')) : null)->toBe($this->realEnvHash);
});

test('list returns only env-bound rows, hides secret values and reports has_value', function (): void {
    $rows = collect($this->service->list())->keyBy('key');

    expect($rows->keys()->sort()->values()->all())->toBe(['api.GOOGLE_API', 'email.MAIL_HOST', 'email.MAIL_PASSWORD', 'notify.TELEGRAM_CHAT_ID'])
        ->and($rows['email.MAIL_PASSWORD'])->toMatchArray(['env_name' => 'MAIL_PASSWORD', 'group' => 'email', 'secret' => true, 'has_value' => true, 'value' => null])
        ->and($rows['email.MAIL_HOST'])->toMatchArray(['env_name' => 'MAIL_HOST', 'secret' => false, 'has_value' => true, 'value' => 'smtp.example.com', 'in_env_file' => true, 'title' => 'text.from.host'])
        ->and($rows['api.GOOGLE_API']['title'])->toBeNull()
        ->and($rows['api.GOOGLE_API'])->toMatchArray(['env_name' => 'GOOGLE_API', 'secret' => true, 'has_value' => false, 'value' => null])
        ->and($rows['notify.TELEGRAM_CHAT_ID'])->toMatchArray(['secret' => false, 'value' => '12345', 'in_env_file' => false])
        ->and(json_encode($rows->all()))->not->toContain(ESS_SECRET);
});

test('the Environment page uses the settings-list policy: MAIL_USERNAME is readable, MAIL_PASSWORD is write-only', function (): void {
    essSetting('email.MAIL_USERNAME', 'text', 'mailer-user', ['isEnv' => true, 'isPublic' => true]);
    $rows = collect($this->service->list())->keyBy('key');
    $guard = app(\Cms\Core\Services\ConfigPackage\SecretGuard::class);

    expect($rows['email.MAIL_USERNAME'])->toMatchArray(['secret' => false, 'value' => 'mailer-user'])
        ->and($rows['email.MAIL_PASSWORD'])->toMatchArray(['secret' => true, 'value' => null])
        // Same answer as the settings list classifier; the export path keeps the broader one.
        ->and($guard->isMaskedSetting(['type' => 'text', 'setting_key' => 'email.MAIL_USERNAME', 'input_setting' => ['isEnv' => true]]))->toBeFalse()
        ->and($guard->isSecretSetting(['type' => 'text', 'input_setting' => ['isEnv' => true]]))->toBeTrue();
});

test('a non-secret name that looks secret is still treated as secret', function (): void {
    essSetting('email.MAIL_API_KEY_HINT', 'text', 'visible?', ['isPublic' => true]);
    // Not env-bound (no isEnv, not a MAIL_* env key): not listed at all.
    expect(collect($this->service->list())->pluck('key'))->not->toContain('email.MAIL_API_KEY_HINT');

    essSetting('email.MAIL_TOKEN_NOTE', 'text', 'tok-value', ['isEnv' => true, 'isPublic' => true]);
    $row = collect($this->service->list())->firstWhere('key', 'email.MAIL_TOKEN_NOTE');
    expect($row['secret'])->toBeTrue()->and($row['value'])->toBeNull();
});

test('set stores the value encrypted and writes the temporary env line', function (): void {
    Event::fake([AuditLogEvent::class]);
    $this->service->set('email.MAIL_PASSWORD', 'n3w-Secret', 7);

    $raw = DB::table('settings')->where('setting_key', 'email.MAIL_PASSWORD')->value('value');
    expect($raw)->not->toBe('n3w-Secret')
        ->and(Setting::query()->where('setting_key', 'email.MAIL_PASSWORD')->first()->value)->toBe('n3w-Secret')
        ->and(essEnvLines($this))->toContain('MAIL_PASSWORD=n3w-Secret')
        ->and(essEnvLines($this))->not->toContain('MAIL_PASSWORD=old');

    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'config.env.update'
        && $e->changed === ['key' => 'email.MAIL_PASSWORD', 'secret' => true]
        && $e->user_id === 7);
    Artisan::shouldHaveReceived('call')->with('queue:restart');
});

test('clear empties the row and the env line and audits without a value', function (): void {
    Event::fake([AuditLogEvent::class]);
    $this->service->clear('email.MAIL_HOST', 3);

    expect(DB::table('settings')->where('setting_key', 'email.MAIL_HOST')->value('value'))->toBeIn([null, ''])
        ->and(essEnvLines($this))->toContain('MAIL_HOST=""')
        ->and(collect($this->service->list())->firstWhere('key', 'email.MAIL_HOST')['has_value'])->toBeFalse();

    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'config.env.clear'
        && $e->changed === ['key' => 'email.MAIL_HOST', 'secret' => false]);
});

test('values with spaces, quotes, dollar, hash and newline are escaped on one line', function (): void {
    $value = "a b\"c'd\$e#f\nAPP_KEY=pwned";
    $this->service->set('email.MAIL_HOST', $value, 1);

    $lines = essEnvLines($this);
    expect($lines)->toHaveCount(3)
        ->and($lines)->toContain('MAIL_HOST="a b\\"c\'d\\$e#f\\nAPP_KEY=pwned"')
        ->and(collect($lines)->filter(fn (string $l): bool => str_starts_with($l, 'APP_KEY='))->all())->toBe([])
        ->and(Setting::query()->where('setting_key', 'email.MAIL_HOST')->first()->value)->toBe($value);

    // The written file still parses to the original value.
    $parsed = Dotenv\Dotenv::parse((string) file_get_contents($this->envDir . '/.env'));
    expect($parsed['MAIL_HOST'])->toBe($value)->and($parsed)->not->toHaveKey('APP_KEY');
});

test('control characters other than newline and tab are rejected', function (): void {
    foreach (["a\rb", "a\0b", "a\x1bb"] as $value) {
        expect(fn () => $this->service->set('email.MAIL_HOST', $value, 1))->toThrow(InvalidArgumentException::class);
    }
    expect(essEnvLines($this))->toContain('MAIL_HOST=old.example.com');
});

test('unlisted keys and an empty value are refused and nothing is written', function (): void {
    Event::fake([AuditLogEvent::class]);
    $before = file_get_contents($this->envDir . '/.env');

    foreach (['basic.site_name', 'email.NOT_A_ROW', 'APP_KEY'] as $key) {
        expect(fn () => $this->service->set($key, 'x-value', 1))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->service->clear($key, 1))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $this->service->set('email.MAIL_HOST', '', 1))->toThrow(InvalidArgumentException::class);

    expect(file_get_contents($this->envDir . '/.env'))->toBe($before)
        ->and(Setting::query()->where('setting_key', 'basic.site_name')->first()->value)->toBe('Site')
        ->and(Setting::query()->where('setting_key', 'email.NOT_A_ROW')->exists())->toBeFalse();
    Event::assertNotDispatched(AuditLogEvent::class);
});

test('logger, log channel and exception texts never contain the value', function (): void {
    $recorder = new EssLoggerRecorder();
    CMSLogger::swap($recorder);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged): void {
        $logged[] = $m->message . ' ' . print_r($m->context, true);
    });

    $value = 'LEAK-CHECK-' . bin2hex(random_bytes(4));
    $this->service->set('email.MAIL_PASSWORD', $value, 1);
    $this->service->clear('email.MAIL_PASSWORD', 1);

    // A failing save whose inner exception carries the value must surface a sanitized exception.
    Setting::saving(function (Setting $s): void {
        throw new RuntimeException('insert failed with ' . $s->value);
    });
    $message = null;
    try {
        $this->service->set('email.MAIL_PASSWORD', $value, 1);
    } catch (Throwable $e) {
        $message = $e->getMessage() . ' ' . ($e->getPrevious()?->getMessage() ?? '');
    }
    try {
        $this->service->set('basic.site_name', $value, 1);
    } catch (Throwable $e) {
        $message .= ' ' . $e->getMessage();
    }

    expect($message)->not->toBeNull()->not->toContain($value)
        ->and(implode("\n", $recorder->lines))->not->toContain($value)
        ->and(implode("\n", $logged))->not->toContain($value);
});
