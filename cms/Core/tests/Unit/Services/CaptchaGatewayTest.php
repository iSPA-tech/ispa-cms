<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Support\Facades\Http;
use Cms\Core\Services\CMSSettingRuntime;
use Cms\Core\Services\Captcha\CaptchaGateway;
use Cms\Core\Services\Captcha\CaptchaVerifyResult;

function makeCaptchaGateway(array $settings = []): CaptchaGateway
{
    $runtime = Mockery::mock(CMSSettingRuntime::class);

    $runtime->allows('get')->andReturnUsing(function (string $key, mixed $default = null) use ($settings) {
        return $settings[$key] ?? $default;
    });

    return new CaptchaGateway($runtime);
}

test('provider=off returns ok without HTTP call', function () {
    $gw = makeCaptchaGateway(['advanced.recaptcha_provider' => 'off']);

    $result = $gw->verify(null);

    expect($result)->toBeInstanceOf(CaptchaVerifyResult::class)
        ->and($result->ok)->toBeTrue()
        ->and($result->provider)->toBe('off')
        ->and($result->error)->toBeNull();
});

test('cloudflare with missing secret fails closed', function () {
    $gw = makeCaptchaGateway([
        'advanced.recaptcha_provider' => 'cloudflare',
        'api.CLOUDFLARE_TURNSTILE_SECRET_KEY' => '',
    ]);

    $result = $gw->verify('some-token');

    expect($result->ok)->toBeFalse()
        ->and($result->provider)->toBe('cloudflare')
        ->and($result->error)->toBe('secret_missing');
});

test('cloudflare with missing token returns error', function () {
    $gw = makeCaptchaGateway([
        'advanced.recaptcha_provider' => 'cloudflare',
        'api.CLOUDFLARE_TURNSTILE_SECRET_KEY' => 'secret-cf-123',
    ]);

    $result = $gw->verify(null);

    expect($result->ok)->toBeFalse()
        ->and($result->provider)->toBe('cloudflare')
        ->and($result->error)->toBe('missing_token');
});

test('cloudflare verify success returns ok', function () {
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true, 'challenge_ts' => '2026-01-01T00:00:00Z']),
    ]);

    $gw = makeCaptchaGateway([
        'advanced.recaptcha_provider' => 'cloudflare',
        'api.CLOUDFLARE_TURNSTILE_SECRET_KEY' => 'secret-cf-123',
    ]);

    $result = $gw->verify('valid-token', '127.0.0.1');

    expect($result->ok)->toBeTrue()
        ->and($result->provider)->toBe('cloudflare')
        ->and($result->error)->toBeNull()
        ->and($result->raw)->toHaveKey('success', true);
});

test('google verify success returns ok', function () {
    Http::fake([
        'www.google.com/*' => Http::response(['success' => true, 'score' => 0.9, 'action' => 'login']),
    ]);

    $gw = makeCaptchaGateway([
        'advanced.recaptcha_provider' => 'google',
        'api.GOOGLE_RECAPTCHA_BE_KEY' => 'secret-goog-456',
    ]);

    $result = $gw->verify('valid-token');

    expect($result->ok)->toBeTrue()
        ->and($result->provider)->toBe('google')
        ->and($result->raw)->toHaveKey('score', 0.9);
});

test('hcaptcha with missing site key fails closed', function () {
    Http::fake();

    $gw = makeCaptchaGateway([
        'advanced.recaptcha_provider' => 'hcaptcha',
        'api.HCAPTCHA_SITE_KEY' => '',
        'api.HCAPTCHA_SECRET_KEY' => 'secret-hcaptcha-123',
    ]);

    $result = $gw->verify('some-token');

    expect($result->ok)->toBeFalse()
        ->and($result->provider)->toBe('hcaptcha')
        ->and($result->error)->toBe('site_key_missing');

    Http::assertNothingSent();
});

test('hcaptcha verify success binds token to configured site key', function () {
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(['success' => true, 'hostname' => 'example.test']),
    ]);

    $gw = makeCaptchaGateway([
        'advanced.recaptcha_provider' => 'hcaptcha',
        'api.HCAPTCHA_SITE_KEY' => 'site-hcaptcha-123',
        'api.HCAPTCHA_SECRET_KEY' => 'secret-hcaptcha-123',
    ]);

    $result = $gw->verify('valid-token', '127.0.0.1');

    expect($result->ok)->toBeTrue()
        ->and($result->provider)->toBe('hcaptcha')
        ->and($result->raw)->toHaveKey('hostname', 'example.test');

    Http::assertSent(fn ($request): bool =>
        $request->url() === 'https://api.hcaptcha.com/siteverify'
        && $request['response'] === 'valid-token'
        && $request['remoteip'] === '127.0.0.1'
        && $request['sitekey'] === 'site-hcaptcha-123'
    );
});

test('typo "cloudlfare" normalizes to cloudflare', function () {
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);

    $gw = makeCaptchaGateway([
        'advanced.recaptcha_provider' => 'cloudlfare',
        'api.CLOUDFLARE_TURNSTILE_SECRET_KEY' => 'secret-cf-123',
    ]);

    expect($gw->provider())->toBe('cloudflare');

    $result = $gw->verify('token-123');

    expect($result->ok)->toBeTrue()
        ->and($result->provider)->toBe('cloudflare');
});

/**
 * A Cloudflare gateway with a configured secret; extra settings can override or extend the defaults.
 */
function makeCloudflareGateway(array $settings = []): CaptchaGateway
{
    return makeCaptchaGateway($settings + [
        'advanced.recaptcha_provider' => 'cloudflare',
        'api.CLOUDFLARE_TURNSTILE_SECRET_KEY' => 'secret-cf-123',
    ]);
}

test('cloudflare rejects a token over 2048 characters without calling siteverify', function () {
    Http::fake();

    $result = makeCloudflareGateway()->verify(str_repeat('a', 2049), '127.0.0.1');

    expect($result->ok)->toBeFalse()
        ->and($result->provider)->toBe('cloudflare')
        ->and($result->error)->toBe('invalid_token');

    Http::assertNothingSent();
});

test('cloudflare sends a token of exactly 2048 characters', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $result = makeCloudflareGateway()->verify(str_repeat('a', 2048));

    expect($result->ok)->toBeTrue();

    Http::assertSentCount(1);
});

test('the token length cap is cloudflare only, other providers keep their own token sizes', function () {
    Http::fake(['www.google.com/*' => Http::response(['success' => true, 'score' => 0.9])]);

    $gw = makeCaptchaGateway([
        'advanced.recaptcha_provider' => 'google',
        'api.GOOGLE_RECAPTCHA_BE_KEY' => 'secret-goog-456',
    ]);

    expect($gw->verify(str_repeat('a', 3000))->ok)->toBeTrue();
});

test('a non-2xx siteverify answer is an http_error even when the body says success', function (int $status) {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true], $status)]);

    $result = makeCloudflareGateway()->verify('valid-token');

    expect($result->ok)->toBeFalse()
        ->and($result->provider)->toBe('cloudflare')
        ->and($result->error)->toBe('http_error');
})->with([400, 500, 503]);

test('a non-2xx answer is an http_error for the other providers too', function () {
    Http::fake(['www.google.com/*' => Http::response('', 502)]);

    $gw = makeCaptchaGateway([
        'advanced.recaptcha_provider' => 'google',
        'api.GOOGLE_RECAPTCHA_BE_KEY' => 'secret-goog-456',
    ]);

    $result = $gw->verify('valid-token');

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe('http_error');
});

test('a transport failure is an http_error', function () {
    Http::fake(['challenges.cloudflare.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

    $result = makeCloudflareGateway()->verify('valid-token');

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe('http_error');
});

test('cloudflare rejects a token issued for another action', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'action' => 'helpdesk_chat'])]);

    $result = makeCloudflareGateway()->verify('valid-token', null, ['action' => 'login']);

    expect($result->ok)->toBeFalse()
        ->and($result->provider)->toBe('cloudflare')
        ->and($result->error)->toBe('action_mismatch')
        ->and($result->raw)->toHaveKey('action', 'helpdesk_chat');
});

test('cloudflare rejects an answer without an action when one is expected', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'action' => ''])]);

    $result = makeCloudflareGateway()->verify('valid-token', null, ['action' => 'login']);

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe('action_mismatch');
});

test('cloudflare accepts a token issued for the expected action', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'action' => 'login'])]);

    $result = makeCloudflareGateway()->verify('valid-token', null, ['action' => 'login']);

    expect($result->ok)->toBeTrue()
        ->and($result->error)->toBeNull();
});

test('cloudflare skips the action check when the context has no action', function (array $context) {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'action' => 'anything'])]);

    $result = makeCloudflareGateway()->verify('valid-token', null, $context);

    expect($result->ok)->toBeTrue();
})->with([
    'no context' => [[]],
    'empty action' => [['action' => '']],
    'null action' => [['action' => null]],
]);

test('a failed cloudflare answer stays a plain failure and is not reported as a mismatch', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    $result = makeCloudflareGateway()->verify('bad-token', null, ['action' => 'login']);

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBeNull()
        ->and($result->raw)->toHaveKey('error-codes');
});

test('the action check does not apply to google', function () {
    Http::fake([
        'www.google.com/*' => Http::response(['success' => true, 'score' => 0.9, 'action' => 'other']),
    ]);

    $gw = makeCaptchaGateway([
        'advanced.recaptcha_provider' => 'google',
        'api.GOOGLE_RECAPTCHA_BE_KEY' => 'secret-goog-456',
    ]);

    expect($gw->verify('valid-token', null, ['action' => 'login', 'minScore' => 0.5])->ok)->toBeTrue();
});

test('cloudflare rejects a hostname that is not in the allowlist', function () {
    config(['services.turnstile.hostnames' => ['help.example.test']]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'evil.example.test'])]);

    $result = makeCloudflareGateway()->verify('valid-token');

    expect($result->ok)->toBeFalse()
        ->and($result->provider)->toBe('cloudflare')
        ->and($result->error)->toBe('hostname_mismatch')
        ->and($result->raw)->toHaveKey('hostname', 'evil.example.test');
});

test('cloudflare accepts a hostname in the allowlist, ignoring case', function () {
    config(['services.turnstile.hostnames' => ['other.example.test', 'Help.Example.Test']]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'HELP.example.test'])]);

    expect(makeCloudflareGateway()->verify('valid-token')->ok)->toBeTrue();
});

test('cloudflare rejects an answer without a hostname when an allowlist is set', function () {
    config(['services.turnstile.hostnames' => ['help.example.test']]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $result = makeCloudflareGateway()->verify('valid-token');

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe('hostname_mismatch');
});

test('an empty hostname allowlist skips the hostname check', function (array $hostnames) {
    config(['services.turnstile.hostnames' => $hostnames]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'anything.example.test'])]);

    expect(makeCloudflareGateway()->verify('valid-token')->ok)->toBeTrue();
})->with([
    'empty list' => [[]],
    'blank entries only' => [['', '  ']],
]);

test('the hostname allowlist does not apply to google or hcaptcha', function () {
    config(['services.turnstile.hostnames' => ['help.example.test']]);
    Http::fake(['api.hcaptcha.com/*' => Http::response(['success' => true, 'hostname' => 'elsewhere.example.test'])]);

    $gw = makeCaptchaGateway([
        'advanced.recaptcha_provider' => 'hcaptcha',
        'api.HCAPTCHA_SITE_KEY' => 'site-hcaptcha-123',
        'api.HCAPTCHA_SECRET_KEY' => 'secret-hcaptcha-123',
    ]);

    expect($gw->verify('valid-token')->ok)->toBeTrue();
});

test('the turnstile hostnames config parses a comma separated TURNSTILE_HOSTNAMES value', function () {
    $services = (function (string $value): array {
        // env() reads $_SERVER first, where a TURNSTILE_HOSTNAMES line in .env (even an empty one) already sits.
        $server = $_SERVER['TURNSTILE_HOSTNAMES'] ?? null;
        putenv('TURNSTILE_HOSTNAMES=' . $value);
        $_ENV['TURNSTILE_HOSTNAMES'] = $value;
        $_SERVER['TURNSTILE_HOSTNAMES'] = $value;

        try {
            return require base_path('config/services.php');
        } finally {
            putenv('TURNSTILE_HOSTNAMES');
            unset($_ENV['TURNSTILE_HOSTNAMES']);
            if ($server === null) {
                unset($_SERVER['TURNSTILE_HOSTNAMES']);
            } else {
                $_SERVER['TURNSTILE_HOSTNAMES'] = $server;
            }
        }
    })(' Help.Acme.Test , ,acme.test');

    expect($services['turnstile']['hostnames'])->toBe(['help.acme.test', 'acme.test']);
});
