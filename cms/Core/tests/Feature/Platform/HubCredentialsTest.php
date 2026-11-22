<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use Cms\Core\Services\Platform\HubCredentials;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'ispa-cms.cms_domain' => 'cms.ispa.io',
        'ispa-cms.cms_domain_secret' => '',
        'ispa-cms.platform_hub_url' => 'https://hub.example.test',
    ]);

    $this->dbDomain = 'fresh-site.example.org';
    $this->dbSecret = 'ispa_site_'.Str::random(40);
});

/**
 * Answer every hub call with a body signed by the given secret, so the client accepts it.
 */
function hubCredentialsFakeHub(string $secret): void
{
    Http::fake(function (ClientRequest $request) use ($secret) {
        $body = '{"ok":true}';
        $nonce = $request->header(PlatformSignature::HEADER_NONCE)[0] ?? '';

        return Http::response($body, 200, [
            PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($secret, 200, $nonce, $body),
        ]);
    });
}

it('lets the .env secret win over the database row', function () {
    config(['ispa-cms.cms_domain' => 'env-site.example.com', 'ispa-cms.cms_domain_secret' => 'env-secret-value']);
    $credentials = app(HubCredentials::class);
    $credentials->store($this->dbDomain, $this->dbSecret);

    expect($credentials->source())->toBe(HubCredentials::SOURCE_ENV)
        ->and($credentials->domain())->toBe('env-site.example.com')
        ->and($credentials->secret())->toBe('env-secret-value')
        ->and($credentials->isConfigured())->toBeTrue();
});

it('keeps existing .env sites signing exactly as before', function () {
    config(['ispa-cms.cms_domain' => 'env-site.example.com', 'ispa-cms.cms_domain_secret' => 'env-secret-value']);
    hubCredentialsFakeHub('env-secret-value');

    expect((new PlatformHubClient())->get('/api/ping'))->toBe(['ok' => true]);

    Http::assertSent(function (ClientRequest $request) {
        return $request->header(PlatformSignature::HEADER_DOMAIN)[0] === 'env-site.example.com';
    });
});

it('reads the database credentials when .env has no secret', function () {
    $credentials = app(HubCredentials::class);
    expect($credentials->isConfigured())->toBeFalse()
        ->and($credentials->source())->toBeNull();

    $credentials->store($this->dbDomain, $this->dbSecret);

    expect($credentials->source())->toBe(HubCredentials::SOURCE_DATABASE)
        ->and($credentials->domain())->toBe($this->dbDomain)
        ->and($credentials->secret())->toBe($this->dbSecret);
});

it('signs hub requests with the database credentials on a site without a secret in .env', function () {
    app(HubCredentials::class)->store($this->dbDomain, $this->dbSecret);
    hubCredentialsFakeHub($this->dbSecret);

    expect((new PlatformHubClient())->post('/api/ping', ['a' => 1]))->toBe(['ok' => true]);

    Http::assertSent(function (ClientRequest $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $expected = PlatformSignature::request(
            $this->dbSecret,
            'POST',
            $path,
            '',
            (int) $request->header(PlatformSignature::HEADER_TIMESTAMP)[0],
            $request->header(PlatformSignature::HEADER_NONCE)[0],
            $request->body(),
        );

        return $request->header(PlatformSignature::HEADER_DOMAIN)[0] === $this->dbDomain
            && $request->header(PlatformSignature::HEADER_SIGNATURE)[0] === $expected
            && ! str_contains($request->body(), $this->dbSecret);
    });
});

it('still refuses to build a hub client when no credentials exist anywhere', function () {
    expect(fn () => new PlatformHubClient())->toThrow(PlatformHubException::class);
});

it('stores the database credentials encrypted at rest', function () {
    app(HubCredentials::class)->store($this->dbDomain, $this->dbSecret);

    $raw = (string) DB::table(HubCredentials::TABLE)->where('name', 'site')->value('value');

    expect($raw)->not->toBe('')
        ->and($raw)->not->toContain($this->dbSecret)
        ->and($raw)->not->toContain($this->dbDomain)
        ->and(json_decode(Crypt::decryptString($raw), true))->toBe(['domain' => $this->dbDomain, 'secret' => $this->dbSecret]);
});

it('replaces the database credentials on a second store and never writes .env', function () {
    $dir = sys_get_temp_dir().'/hub-credentials-'.Str::random(8);
    mkdir($dir);
    file_put_contents($dir.'/.env', "APP_NAME=probe\n");
    $before = hash_file('sha256', $dir.'/.env');
    $this->app->useEnvironmentPath($dir);

    try {
        $credentials = app(HubCredentials::class);
        $credentials->store('first.example.org', 'first-secret');
        $credentials->store($this->dbDomain, $this->dbSecret);

        expect(DB::table(HubCredentials::TABLE)->where('name', 'site')->count())->toBe(1)
            ->and($credentials->secret())->toBe($this->dbSecret)
            ->and(hash_file('sha256', $dir.'/.env'))->toBe($before)
            ->and(array_values(array_diff(scandir($dir), ['.', '..'])))->toBe(['.env']);
    } finally {
        @unlink($dir.'/.env');
        @rmdir($dir);
    }
});

it('treats an unreadable database row as not configured', function () {
    DB::table(HubCredentials::TABLE)->insert([
        'name' => 'site',
        'value' => 'not-a-crypt-payload',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(HubCredentials::class)->isConfigured())->toBeFalse();
});

it('warns about an unreadable database row once a day, not on every read', function () {
    // The dashboard reads the connection on every request; a changed APP_KEY must not flood the log.
    DB::table(HubCredentials::TABLE)->insert([
        'name' => 'site',
        'value' => 'not-a-crypt-payload',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $warnings = 0;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings) {
        if ($event->level === 'warning' && str_contains($event->message, 'cannot be decrypted')) {
            $warnings++;
        }
    });

    $credentials = app(HubCredentials::class);
    $credentials->isConfigured();
    $credentials->isConfigured();
    app(HubCredentials::class)->isConfigured();

    expect($warnings)->toBe(1);
});

it('never writes the secret or the setup code to the logs', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $credentials = app(HubCredentials::class);
    $code = $credentials->issueSetupCode();
    $credentials->checkSetupCode($code);
    $credentials->checkSetupCode('WRONG-CODE');
    $credentials->store($this->dbDomain, $this->dbSecret);
    $credentials->secret();

    // A hub answer signed with another key makes the client fail after signing with the secret.
    hubCredentialsFakeHub('another-secret');
    try {
        (new PlatformHubClient())->get('/api/ping');
    } catch (PlatformHubException $e) {
        Log::warning('hub call failed', ['error' => $e->getMessage(), 'reason' => $e->reason()]);
        expect($e->getMessage())->not->toContain($this->dbSecret);
    }

    // A row that cannot be decrypted (APP_KEY changed) is reported without its content.
    DB::table(HubCredentials::TABLE)->where('name', 'site')->update(['value' => 'broken']);
    $credentials->isConfigured();

    $this->artisan('cms:hub-setup-code')->assertSuccessful();

    expect($logged)->not->toBeEmpty();
    foreach ($logged as $line) {
        expect($line)->not->toContain($this->dbSecret)
            ->and($line)->not->toContain($code)
            ->and($line)->not->toContain(str_replace('-', '', $code));
    }
});

it('issues a setup code of 8 groups of 4 base32 characters and stores only its hash', function () {
    $code = app(HubCredentials::class)->issueSetupCode();

    expect($code)->toMatch('/^[A-Z2-7]{4}(-[A-Z2-7]{4}){7}$/');

    $row = (array) DB::table(HubCredentials::TABLE)->where('name', 'setup_code')->first();
    $dump = json_encode($row);
    expect($dump)->not->toContain($code)
        ->and($dump)->not->toContain(str_replace('-', '', $code))
        ->and($row['value'])->toBe(hash('sha256', str_replace('-', '', $code)));
});

it('accepts the setup code with any spacing or case and refuses a wrong one', function () {
    $credentials = app(HubCredentials::class);
    $code = $credentials->issueSetupCode();

    expect($credentials->checkSetupCode($code))->toBeTrue()
        ->and($credentials->checkSetupCode(strtolower(str_replace('-', ' ', $code))))->toBeTrue()
        ->and($credentials->checkSetupCode(substr($code, 0, -1).($code[-1] === 'A' ? 'B' : 'A')))->toBeFalse()
        ->and($credentials->checkSetupCode(''))->toBeFalse();
});

it('makes the setup code single-use after a successful connect', function () {
    $credentials = app(HubCredentials::class);
    $code = $credentials->issueSetupCode();

    $credentials->invalidateSetupCode();

    expect($credentials->checkSetupCode($code))->toBeFalse();
});

it('expires the setup code after 7 days', function () {
    $credentials = app(HubCredentials::class);
    $code = $credentials->issueSetupCode();

    $this->travel(7)->days();
    $this->travel(-1)->minutes();
    expect($credentials->checkSetupCode($code))->toBeTrue();

    $this->travel(2)->minutes();
    expect($credentials->checkSetupCode($code))->toBeFalse();
});

it('invalidates the previous setup code when a new one is issued', function () {
    $credentials = app(HubCredentials::class);
    $first = $credentials->issueSetupCode();
    $second = $credentials->issueSetupCode();

    expect($credentials->checkSetupCode($first))->toBeFalse()
        ->and($credentials->checkSetupCode($second))->toBeTrue();
});

it('prints a new setup code from cms:hub-setup-code', function () {
    $this->artisan('cms:hub-setup-code')
        ->expectsOutputToContain('-')
        ->assertSuccessful();

    expect(DB::table(HubCredentials::TABLE)->where('name', 'setup_code')->whereNotNull('expires_at')->count())->toBe(1);
});

it('announces a setup code once on first boot only when the site has no credentials', function () {
    $credentials = app(HubCredentials::class);

    $first = $credentials->announceSetupCodeOnce();
    expect($first)->toBeString()
        ->and($credentials->checkSetupCode((string) $first))->toBeTrue()
        ->and($credentials->announceSetupCodeOnce())->toBeNull();
});

it('does not announce a setup code when .env already holds credentials', function () {
    config(['ispa-cms.cms_domain' => 'env-site.example.com', 'ispa-cms.cms_domain_secret' => 'env-secret-value']);

    expect(app(HubCredentials::class)->announceSetupCodeOnce())->toBeNull()
        ->and(DB::table(HubCredentials::TABLE)->where('name', 'setup_code')->count())->toBe(0);
});

it('does not announce a setup code when the database already holds credentials', function () {
    $credentials = app(HubCredentials::class);
    $credentials->store($this->dbDomain, $this->dbSecret);

    expect($credentials->announceSetupCodeOnce())->toBeNull();
});
