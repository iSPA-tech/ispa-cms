<?php

declare(strict_types=1);

use Cms\Core\Services\ConfigPackage\SecretGuard;
use Illuminate\Support\Facades\Crypt;

uses(Cms\Core\Tests\TestCase::class);

it('flags password settings case-insensitively', function (): void {
    $guard = new SecretGuard();

    expect($guard->isSecretSetting(['key' => 'smtp.pass', 'type' => 'password']))->toBeTrue()
        ->and($guard->isSecretSetting(['key' => 'smtp.pass', 'type' => 'PassWord']))->toBeTrue();
});

it('flags env settings from array, JSON string and snake case input_setting', function (): void {
    $guard = new SecretGuard();

    expect($guard->isSecretSetting(['key' => 'a', 'type' => 'text', 'input_setting' => ['isEnv' => true]]))->toBeTrue()
        ->and($guard->isSecretSetting(['key' => 'a', 'type' => 'text', 'input_setting' => '{"isEnv":true}']))->toBeTrue()
        ->and($guard->isSecretSetting(['key' => 'a', 'type' => 'text', 'input_setting' => ['is_env' => true]]))->toBeTrue()
        ->and($guard->isSecretSetting(['key' => 'a', 'type' => 'text', 'isEnv' => true]))->toBeTrue();
});

it('flags non-public settings', function (): void {
    $guard = new SecretGuard();

    expect($guard->isSecretSetting(['key' => 'a', 'type' => 'text', 'input_setting' => ['isPublic' => false]]))->toBeTrue()
        ->and($guard->isSecretSetting(['key' => 'a', 'type' => 'text', 'input_setting' => '{"is_public":false}']))->toBeTrue()
        ->and($guard->isSecretSetting(['key' => 'a', 'type' => 'text', 'is_public' => false]))->toBeTrue();
});

it('passes an ordinary public setting', function (): void {
    $guard = new SecretGuard();

    expect($guard->isSecretSetting([
        'key' => 'site.title',
        'type' => 'text',
        'input_setting' => ['isPublic' => true, 'isEnv' => false],
        'value' => 'My site',
    ]))->toBeFalse()
        ->and($guard->isSecretSetting(['key' => 'site.title', 'type' => 'text']))->toBeFalse();
});

it('refuses a secret key nested deep and names the path without the value', function (): void {
    $sections = ['settings' => [['key' => 'mail', 'value' => ['smtp' => ['smtp_password' => 'hunter2-plain']]]]];

    try {
        (new SecretGuard())->assertNoSecrets($sections);
        $this->fail('Expected an exception.');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toContain('sections.settings.0.value.smtp.smtp_password')
            ->and($exception->getMessage())->not->toContain('hunter2-plain');
    }
});

it('refuses Laravel Crypt ciphertext values', function (): void {
    $cipher = Crypt::encryptString('x');
    $guard = new SecretGuard();

    expect(fn () => $guard->assertNoSecrets(['settings' => [['key' => 'a', 'value' => $cipher]]]))
        ->toThrow(InvalidArgumentException::class)
        ->and($guard->findSecrets(['settings' => [['key' => 'a', 'value' => $cipher]]]))
        ->toBe(['sections.settings.0.value']);
});

it('detects base64 ciphertext even without the usual prefix', function (): void {
    $payload = base64_encode(json_encode(['value' => 'abc', 'mac' => 'def', 'iv' => 'ghi']));

    expect((new SecretGuard())->findSecrets(['tables' => ['x' => [['note' => $payload]]]]))
        ->toBe(['sections.tables.x.0.note']);
});

it('passes ordinary text including the word token in values', function (): void {
    $sections = [
        'translations' => [['name' => 'text.password.label', 'text' => 'Enter your token here']],
        'settings' => [['key' => 'site.title', 'value' => 'Token shop secret sale']],
    ];

    (new SecretGuard())->assertNoSecrets($sections);

    expect((new SecretGuard())->findSecrets($sections))->toBe([]);
});

it('matches secret key names case-insensitively', function (): void {
    $guard = new SecretGuard();

    expect($guard->findSecrets(['tables' => ['t' => [['API_KEY' => 'v', 'Authorization' => 'v', 'clientSecret' => 'v', 'title' => 'v']]]]))
        ->toBe(['sections.tables.t.0.API_KEY', 'sections.tables.t.0.Authorization', 'sections.tables.t.0.clientSecret']);
});

it('lets counting and limit names that contain the word token pass', function (string $key): void {
    expect((new SecretGuard())->isSecretKey($key))->toBeFalse();
})->with(['max_tokens', 'token_limit', 'tokens_used', 'max_completion_tokens', 'token_length']);

it('still flags credential-like key names by whole segment', function (string $key): void {
    expect((new SecretGuard())->isSecretKey($key))->toBeTrue();
})->with(['access_token', 'refresh_token', 'apiKey', 'x-api-key', 'API_KEY', 'api.key', 'private_key', 'clientSecret', 'Authorization', 'smtp_password', 'bearerToken', 'apikey']);

it('does not flag ordinary key names', function (string $key): void {
    expect((new SecretGuard())->isSecretKey($key))->toBeFalse();
})->with(['title', 'key', 'api', 'monkey', 'name']);

// ── isMaskedSetting (admin API masking policy) ─────────────────────────

it('does not mask env-managed or non-public settings that are not secrets', function (string $key, array|string $input): void {
    $guard = new SecretGuard();
    $row = ['type' => 'text', 'setting_key' => $key, 'input_setting' => $input];

    expect($guard->isMaskedSetting($row))->toBeFalse()
        ->and($guard->isSecretSetting($row))->toBeTrue();
})->with([
    'app version' => ['advanced.APP_VERSION', ['isEnv' => true, 'isPublic' => false]],
    'maintenance switch' => ['advanced.APP_MAINTENANCE', ['isEnv' => true]],
    'theme folder' => ['advanced.THEME_FOLDER', ['isEnv' => true, 'isPublic' => false]],
    'logger channel' => ['advanced.LOG_CHANNEL', ['isEnv' => true]],
    'recaptcha site key' => ['recaptcha.site_key', ['isEnv' => true, 'isPublic' => false]],
    'captcha site key json string' => ['captcha.site_key', '{"isPublic":false}'],
]);

it('masks real secrets', function (array $row): void {
    expect((new SecretGuard())->isMaskedSetting($row))->toBeTrue();
})->with([
    'password type' => [['type' => 'password', 'setting_key' => 'x.y', 'input_setting' => []]],
    'mail password key' => [['type' => 'text', 'setting_key' => 'email.MAIL_PASSWORD', 'input_setting' => ['isEnv' => true]]],
    'sentry dsn' => [['type' => 'text', 'setting_key' => 'advanced.SENTRY_DSN', 'input_setting' => ['isEnv' => true]]],
    'api key' => [['type' => 'text', 'setting_key' => 'advanced.X_API_KEY', 'input_setting' => ['isEnv' => true]]],
    'client secret' => [['type' => 'text', 'setting_key' => 'api.CLIENT_SECRET', 'input_setting' => ['isEnv' => true]]],
    'private key' => [['type' => 'text', 'setting_key' => 'app.private_value', 'input_setting' => []]],
    'explicit secret flag' => [['type' => 'text', 'setting_key' => 'a.b', 'input_setting' => ['secret' => true]]],
    'explicit secret flag json' => [['type' => 'text', 'setting_key' => 'a.b', 'input_setting' => '{"secret":true}']],
    'env name field' => [['type' => 'text', 'setting_key' => 'a.b', 'input_setting' => ['isEnv' => true, 'envKey' => 'MAIL_PASSWORD']]],
    'env row name' => [['type' => 'text', 'setting_key' => 'a.b', 'name' => 'SENTRY_DSN', 'input_setting' => ['isEnv' => true]]],
]);

it('does not change findSecrets key vocabulary for dsn and private segments', function (): void {
    $guard = new SecretGuard();

    expect($guard->isSecretKey('sentry_dsn'))->toBeFalse()
        ->and($guard->isSecretKey('private'))->toBeFalse();
});

it('masks env settings whose name ends with api or key even without a secret word', function (string $key): void {
    $guard = new SecretGuard();

    expect($guard->isMaskedSetting(['setting_key' => $key, 'type' => 'text', 'input_setting' => ['isEnv' => true]]))->toBeTrue();
})->with(['api.GOOGLE_API', 'api.OPEN_AI_API', 'maps.GOOGLE_MAPS_KEY']);

it('keeps public site keys and plain env settings readable', function (string $key): void {
    $guard = new SecretGuard();

    expect($guard->isMaskedSetting(['setting_key' => $key, 'type' => 'text', 'input_setting' => ['isEnv' => true]]))->toBeFalse();
})->with(['turnstile.TURNSTILE_SITE_KEY', 'advanced.VITE_TEMPLATE_FOLDER', 'notify.LOGGER_PROVIDER', 'notify.TELEGRAM_CHAT_ID', 'api.API_VERSION']);

