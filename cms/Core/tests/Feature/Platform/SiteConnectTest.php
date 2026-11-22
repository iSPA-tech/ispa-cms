<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use Cms\Core\Services\Platform\HubCredentials;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Platform\SiteConnectService;
use Cms\Core\Services\Platform\SiteStatusService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * `POST /api/v2/public/site-connect` (the pre-login connect screen) and the `.well-known/ispa-hub/{nonce}`
 * answer route.
 *
 * The setup code is checked first and a wrong one never stores anything and never reaches the hub. A signed hub answer
 * (connected or pending) stores the credentials; a refusal, silence or an unsigned answer stores nothing. The
 * `.well-known` route answers only while a connect call is outstanding.
 */
const SCT_HUB = 'https://hub.example.test';
const SCT_CONNECT_URL = SCT_HUB . '/api/platform/v1/site/connect';
const SCT_STATUS_URL = SCT_HUB . '/api/platform/v1/site/status';
const SCT_NONCE = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

beforeEach(function () {
    $this->withoutMiddleware([ApiLocalization::class, HttpsProtocol::class, Localization::class]);
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-15 10:00:00');

    $this->domain = 'fresh.example.org';
    $this->secret = 'ispa_site_' . bin2hex(random_bytes(16));
    $this->callbackProofs = [];
    $this->hubRoutes = [];
    Http::fake(function (ClientRequest $request) {
        $answer = $this->hubRoutes[$request->url()] ?? null;

        return $answer instanceof Closure ? $answer($request) : $answer;
    });
    config([
        'ispa-cms.cms_domain' => 'cms.ispa.io',
        'ispa-cms.cms_domain_secret' => '',
        'ispa-cms.platform_hub_url' => SCT_HUB,
        'app.url' => 'https://' . $this->domain,
    ]);

    $this->code = app(HubCredentials::class)->issueSetupCode();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Hub answer for the connect call, signed with the secret the site sent; the closure also asks the site's own
 * callback logic for the proof the way the hub's domain check would while the call is outstanding.
 */
function sctHubConnect(object $test, array $payload, int $status = 200, ?string $signWith = null): void
{
    $test->hubRoutes[SCT_CONNECT_URL] = function (ClientRequest $request) use ($test, $payload, $status, $signWith) {
        $test->callbackProofs[] = app(SiteConnectService::class)->callbackProof(SCT_NONCE);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $nonce = $request->header(PlatformSignature::HEADER_NONCE)[0] ?? '';

        return Http::response($body, $status, [
            PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($signWith ?? $test->secret, $status, $nonce, $body),
        ]);
    };
}

/**
 * Post the connect form.
 */
function sctPost(object $test, array $override = []): \Illuminate\Testing\TestResponse
{
    return $test->postJson('/api/v2/public/site-connect', $override + [
        'site_id' => $test->domain,
        'site_secret' => $test->secret,
        'setup_code' => $test->code,
    ]);
}

/**
 * Number of requests sent to one URL.
 */
function sctSentTo(string $url): int
{
    return Http::recorded(fn (ClientRequest $request) => $request->url() === $url)->count();
}

/**
 * Nothing about the site secret is stored.
 */
function sctNothingStored(): void
{
    expect(DB::table(HubCredentials::TABLE)->where('name', 'site')->exists())->toBeFalse()
        ->and(app(HubCredentials::class)->isConfigured())->toBeFalse();
}

it('refuses a wrong setup code, stores nothing and never reaches the hub', function () {
    sctPost($this, ['setup_code' => 'AAAA-BBBB-CCCC-DDDD-EEEE-FFFF-GGGG-HHHH'])
        ->assertStatus(422)
        ->assertJsonPath('data.status', 'not_connected')
        ->assertJsonPath('data.message_key', 'text.site_connect.setup_code_invalid');

    Http::assertNothingSent();
    sctNothingStored();
    expect(app(HubCredentials::class)->checkSetupCode($this->code))->toBeTrue();
});

it('refuses every code when no setup code was ever issued', function () {
    DB::table(HubCredentials::TABLE)->where('name', 'setup_code')->delete();

    sctPost($this)->assertStatus(422)->assertJsonPath('data.message_key', 'text.site_connect.setup_code_invalid');

    Http::assertNothingSent();
    sctNothingStored();
});

it('refuses an expired setup code', function () {
    Carbon::setTestNow('2026-11-23 10:00:00');

    sctPost($this)->assertStatus(422)->assertJsonPath('data.message_key', 'text.site_connect.setup_code_invalid');

    Http::assertNothingSent();
    sctNothingStored();
});

it('refuses missing or malformed fields before anything else', function (array $override) {
    sctPost($this, $override)->assertStatus(422)->assertJsonPath('data.message_key', 'text.site_connect.invalid_input');

    Http::assertNothingSent();
    sctNothingStored();
})->with([
    'no site id' => [['site_id' => '']],
    'site id with a path' => [['site_id' => 'example.org/admin']],
    'site id with spaces' => [['site_id' => 'bad host.org']],
    'no secret' => [['site_secret' => '']],
    'no setup code' => [['setup_code' => '']],
]);

it('connects a site: stores the credentials encrypted, ends the setup code and answers connected', function () {
    sctHubConnect($this, ['status' => 'connected', 'reason' => null]);
    $this->hubRoutes[SCT_STATUS_URL] = function (ClientRequest $request) {
        $body = json_encode(['status' => 'connected', 'expires_at' => null, 'token' => 'server-held-token']);
        $nonce = $request->header(PlatformSignature::HEADER_NONCE)[0] ?? '';

        return Http::response($body, 200, [
            PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($this->secret, 200, $nonce, $body),
        ]);
    };

    $response = sctPost($this)
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.message_key', 'text.site_connect.connected');

    $raw = $response->getContent();
    expect($raw)->not->toContain($this->secret)->and($raw)->not->toContain($this->code)->and($raw)->not->toContain('hub.example.test');

    $credentials = app(HubCredentials::class)->credentials();
    expect($credentials)->toMatchArray(['domain' => $this->domain, 'secret' => $this->secret, 'source' => HubCredentials::SOURCE_DATABASE]);
    expect((string) DB::table(HubCredentials::TABLE)->where('name', 'site')->value('value'))->not->toContain($this->secret);
    expect(app(HubCredentials::class)->checkSetupCode($this->code))->toBeFalse();

    Http::assertSent(fn (ClientRequest $request) => $request->url() === SCT_CONNECT_URL
        && $request->method() === 'POST'
        && $request->header(PlatformSignature::HEADER_DOMAIN)[0] === $this->domain);
    // While the call was outstanding the site answered the domain check with the HMAC of the nonce.
    expect($this->callbackProofs)->toBe([PlatformSignature::callback($this->secret, SCT_NONCE)]);
    // The answer window is closed again.
    expect(app(SiteConnectService::class)->callbackProof(SCT_NONCE))->toBeNull();

    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.status', 'connected');
});

it('normalizes a pasted address to a bare host', function () {
    sctHubConnect($this, ['status' => 'connected', 'reason' => null]);

    sctPost($this, ['site_id' => '  HTTPS://Fresh.Example.org/ '])->assertOk()->assertJsonPath('data.status', 'connected');

    expect(app(HubCredentials::class)->domain())->toBe($this->domain);
});

it('keeps the credentials and the setup code when the hub answers pending, and says why in plain words', function () {
    sctHubConnect($this, ['status' => 'pending', 'reason' => 'wrong_answer']);

    sctPost($this)
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.message_key', 'text.site_connect.reason.wrong_answer');

    expect(app(HubCredentials::class)->credentials()['domain'])->toBe($this->domain);
    expect(app(HubCredentials::class)->checkSetupCode($this->code))->toBeTrue();
});

it('shows a generic pending sentence for a reason it does not know', function () {
    sctHubConnect($this, ['status' => 'pending', 'reason' => 'something_new']);

    sctPost($this)->assertOk()->assertJsonPath('data.message_key', 'text.site_connect.pending');
});

it('stores nothing when the hub refuses the site id or secret', function () {
    $this->hubRoutes[SCT_CONNECT_URL] = Http::response('{"message":"Unauthorized"}', 401);

    sctPost($this)
        ->assertStatus(422)
        ->assertJsonPath('data.status', 'not_connected')
        ->assertJsonPath('data.message_key', 'text.site_connect.credentials_refused');

    sctNothingStored();
    expect(app(HubCredentials::class)->checkSetupCode($this->code))->toBeTrue();
    expect(app(SiteConnectService::class)->callbackProof(SCT_NONCE))->toBeNull();
});

it('stores nothing when the hub cannot be reached', function () {
    $this->hubRoutes[SCT_CONNECT_URL] = fn () => throw new ConnectionException('refused');

    sctPost($this)->assertStatus(503)->assertJsonPath('data.message_key', 'text.site_connect.hub_unreachable');

    sctNothingStored();
    expect(app(HubCredentials::class)->checkSetupCode($this->code))->toBeTrue();
});

it('stores nothing when the hub answer is not signed with the submitted secret', function () {
    sctHubConnect($this, ['status' => 'connected', 'reason' => null], 200, 'some-other-secret');

    sctPost($this)->assertStatus(503)->assertJsonPath('data.message_key', 'text.site_connect.hub_unreachable');

    sctNothingStored();
    expect(app(HubCredentials::class)->checkSetupCode($this->code))->toBeTrue();
});

it('does not replace credentials an operator set in .env', function () {
    config(['ispa-cms.cms_domain' => 'site.example.org', 'ispa-cms.cms_domain_secret' => 'env-secret']);

    sctPost($this)->assertStatus(409)->assertJsonPath('data.message_key', 'text.site_connect.managed_by_env');

    Http::assertNothingSent();
    expect(DB::table(HubCredentials::TABLE)->where('name', 'site')->exists())->toBeFalse();
});

it('is throttled to 5 attempts a minute', function () {
    for ($i = 0; $i < 5; $i++) {
        sctPost($this, ['setup_code' => 'WRONG-CODE'])->assertStatus(422);
    }

    sctPost($this)->assertStatus(429);
    Http::assertNothingSent();
    sctNothingStored();
});

it('answers the domain check only while a connect call is outstanding', function () {
    $proof = PlatformSignature::callback($this->secret, SCT_NONCE);

    $this->get('/.well-known/ispa-hub/' . SCT_NONCE)->assertNotFound();

    app(SiteConnectService::class)->openCallbackWindow($this->secret);
    $this->get('/.well-known/ispa-hub/' . SCT_NONCE)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee($proof, false);
    $this->get('/.well-known/ispa-hub/not-a-nonce')->assertNotFound();
    $this->get('/.well-known/ispa-hub/' . strtoupper(SCT_NONCE))->assertNotFound();

    app(SiteConnectService::class)->closeCallbackWindow();
    $this->get('/.well-known/ispa-hub/' . SCT_NONCE)->assertNotFound();
});

it('stops answering the domain check once the window time is over', function () {
    app(SiteConnectService::class)->openCallbackWindow($this->secret);
    $this->get('/.well-known/ispa-hub/' . SCT_NONCE)->assertOk();

    Carbon::setTestNow('2026-11-15 10:01:00');
    $this->get('/.well-known/ispa-hub/' . SCT_NONCE)->assertNotFound();
});

it('keeps an existing site on the old verify endpoint until it connects (status of a fresh site)', function () {
    $this->hubRoutes['https://hub.example.test/api/v2/public/verify'] = Http::response(['status' => 'error', 'message' => 'Domain is not exist.'], 400);

    $this->getJson('/api/v2/public/site-status')
        ->assertJsonPath('data.status', 'not_connected')
        ->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_NOT_CONFIGURED);
    expect(sctSentTo(SCT_CONNECT_URL))->toBe(0);
});
