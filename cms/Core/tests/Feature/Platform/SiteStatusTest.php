<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Clients\CmsClient;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use Cms\Core\Services\Platform\HubCredentials;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Platform\SiteStatusService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * the admin asks its own backend (`GET /api/v2/public/site-status`); only the backend talks to the hub.
 *
 * The backend asks the signed hub status endpoint, caches the answer for 15 minutes, keeps the last good answer for
 * 7 days while the hub cannot be reached, falls back to the old verify endpoint on a hub without the status endpoint,
 * and holds the domain token server-side for translate, gptTranslate and textToSpeech.
 */
const SST_HUB = 'https://hub.example.test';
const SST_STATUS_URL = SST_HUB . '/api/platform/v1/site/status';
const SST_VERIFY_URL = SST_HUB . '/api/v2/public/verify';

beforeEach(function () {
    $this->withoutMiddleware([ApiLocalization::class, HttpsProtocol::class, Localization::class]);
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-15 10:00:00');
    // One fake for the whole test; sstHub() swaps the answers (Http::fake stubs stack and the first match wins).
    $this->hubRoutes = [];
    Http::fake(function (ClientRequest $request) {
        $answer = $this->hubRoutes[$request->url()] ?? null;

        return $answer instanceof Closure ? $answer($request) : $answer;
    });

    $this->domain = 'site.example.org';
    $this->secret = 'site-secret-' . bin2hex(random_bytes(8));
    $this->serverToken = 'server-held-token-' . bin2hex(random_bytes(8));
    config([
        'ispa-cms.cms_domain' => $this->domain,
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => SST_HUB,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A hub response signed with the site secret for the nonce of the request it answers.
 */
function sstSigned(ClientRequest $request, string $secret, array $payload, int $status = 200): \GuzzleHttp\Promise\PromiseInterface
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $nonce = $request->header(PlatformSignature::HEADER_NONCE)[0] ?? '';

    return Http::response($body, $status, [
        PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($secret, $status, $nonce, $body),
    ]);
}

/**
 * Replace the hub answers: URL => response or closure.
 */
function sstHub(object $test, array $routes): void
{
    $test->hubRoutes = $routes;
}

/**
 * Answer the hub status endpoint with a fixed signed payload (plus any other routes).
 */
function sstFakeStatus(object $test, array $payload, array $extra = []): void
{
    sstHub($test, $extra + [
        SST_STATUS_URL => fn (ClientRequest $request) => sstSigned($request, $test->secret, $payload),
    ]);
}

/**
 * Number of requests sent to one URL.
 */
function sstSentTo(string $url): int
{
    return Http::recorded(fn (ClientRequest $request) => $request->url() === $url)->count();
}

it('asks the old verify endpoint for a site without credentials and never calls the signed endpoint', function () {
    config(['ispa-cms.cms_domain_secret' => '']);
    sstHub($this, [
        SST_VERIFY_URL => Http::response([
            'status' => 'success',
            'data' => ['token' => $this->serverToken, 'expired_at' => '2027-01-31 00:00:00'],
            'message' => 'Domain is verified.',
        ]),
    ]);

    $response = $this->getJson('/api/v2/public/site-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_CONNECTED);

    expect(array_keys($response->json('data')))->toBe(['status', 'message_key', 'expires_at'])
        ->and($response->getContent())->not->toContain($this->serverToken);
    $service = app(SiteStatusService::class);
    expect($service->domainToken())->toBe($this->serverToken)
        ->and($service->isLegacy())->toBeTrue();
    expect(sstSentTo(SST_STATUS_URL))->toBe(0);
    Http::assertSent(fn (ClientRequest $request) => $request->url() === SST_VERIFY_URL
        && $request->method() === 'GET'
        && $request->header('x-domain')[0] === $this->domain);
});

it('caches the legacy answer of a site without credentials like any other answer', function () {
    config(['ispa-cms.cms_domain_secret' => '']);
    sstHub($this, [SST_VERIFY_URL => Http::response(['status' => 'success', 'data' => ['token' => $this->serverToken, 'expired_at' => null]])]);

    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.status', 'connected');
    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.status', 'connected');
    expect(sstSentTo(SST_VERIFY_URL))->toBe(1);

    Carbon::setTestNow('2026-11-15 10:16:00');
    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.status', 'connected');
    expect(sstSentTo(SST_VERIFY_URL))->toBe(2);
});

it('does not flag an answer from the signed endpoint as legacy', function () {
    sstFakeStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => $this->serverToken]);

    expect(app(SiteStatusService::class)->isLegacy())->toBeFalse();
});

it('shows the connect screen state only for a site the hub does not know at all', function () {
    config(['ispa-cms.cms_domain_secret' => '']);
    sstHub($this, [SST_VERIFY_URL => Http::response(['status' => 'error', 'message' => 'Domain is not exist.'], 400)]);

    $this->getJson('/api/v2/public/site-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'not_connected')
        ->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_NOT_CONFIGURED)
        ->assertJsonPath('data.expires_at', null);
    expect(app(SiteStatusService::class)->domainToken())->toBeNull();
});

it('keeps a legacy site working through a hub outage within the grace and stops after it', function () {
    config(['ispa-cms.cms_domain_secret' => '']);
    sstHub($this, [SST_VERIFY_URL => Http::response(['status' => 'success', 'data' => ['token' => $this->serverToken, 'expired_at' => null]])]);
    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.status', 'connected');

    Cache::flush();
    sstHub($this, [SST_VERIFY_URL => Http::response('down', 503)]);
    Carbon::setTestNow('2026-11-18 10:00:00');
    $this->getJson('/api/v2/public/site-status')
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_HUB_UNREACHABLE_GRACE);
    expect(app(SiteStatusService::class)->domainToken())->toBe($this->serverToken);

    Cache::flush();
    Carbon::setTestNow('2026-11-23 10:00:00');
    $this->getJson('/api/v2/public/site-status')
        ->assertJsonPath('data.status', 'not_connected')
        ->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_HUB_UNREACHABLE);
});

it('uses the app host for a fresh install that left CMS_DOMAIN at the hub default', function () {
    config([
        'ispa-cms.cms_domain_secret' => '',
        'ispa-cms.cms_domain' => 'cms.ispa.io',
        'app.url' => 'https://fresh.example.org',
    ]);
    sstHub($this, [SST_VERIFY_URL => Http::response(['status' => 'error', 'message' => 'Domain is not exist.'], 400)]);

    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.status', 'not_connected');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === SST_VERIFY_URL
        && $request->header('x-domain')[0] === 'fresh.example.org');
});

it('keeps cms.ispa.io itself connected through the old verify endpoint while it has no secret', function () {
    config(['ispa-cms.cms_domain_secret' => '', 'ispa-cms.cms_domain' => 'cms.ispa.io', 'app.url' => 'https://cms.ispa.io']);
    sstHub($this, [SST_VERIFY_URL => Http::response(['status' => 'success', 'data' => ['token' => $this->serverToken, 'expired_at' => null]])]);

    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.status', 'connected');

    expect(app(SiteStatusService::class)->domainToken())->toBe($this->serverToken);
    Http::assertSent(fn (ClientRequest $request) => $request->header('x-domain')[0] === 'cms.ispa.io');
});

it('answers connected with the expiry date and never returns the token, the secret or the hub address', function () {
    sstFakeStatus($this, ['status' => 'connected', 'expires_at' => '2027-01-31T00:00:00+07:00', 'token' => $this->serverToken]);

    $response = $this->getJson('/api/v2/public/site-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_CONNECTED)
        ->assertJsonPath('data.expires_at', '2027-01-31T00:00:00+07:00');

    expect(array_keys($response->json('data')))->toBe(['status', 'message_key', 'expires_at']);
    $raw = $response->getContent();
    expect($raw)->not->toContain($this->serverToken)
        ->and($raw)->not->toContain($this->secret)
        ->and($raw)->not->toContain('hub.example.test');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === SST_STATUS_URL
        && $request->method() === 'POST'
        && $request->header(PlatformSignature::HEADER_DOMAIN)[0] === $this->domain);
});

it('answers pending for a site the hub has not verified yet and holds no token', function () {
    sstFakeStatus($this, ['status' => 'pending', 'expires_at' => null, 'token' => null]);

    $this->getJson('/api/v2/public/site-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_PENDING);

    expect(app(SiteStatusService::class)->domainToken())->toBeNull();
});

it('answers not_connected when the hub refuses the site and forgets the last good answer', function () {
    sstFakeStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => $this->serverToken]);
    app(SiteStatusService::class)->current();
    expect(app(SiteStatusService::class)->domainToken())->toBe($this->serverToken);

    Carbon::setTestNow(now()->addMinutes(16));
    sstHub($this, [SST_STATUS_URL => Http::response('{"message":"Unauthorized"}', 401)]);

    $this->getJson('/api/v2/public/site-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'not_connected')
        ->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_NOT_CONNECTED);

    expect(app(SiteStatusService::class)->domainToken())->toBeNull()
        ->and(app(HubCredentials::class)->lastStatus())->toBeNull();
});

it('serves the cached answer for 15 minutes and asks the hub again after that', function () {
    sstFakeStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => $this->serverToken]);

    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.status', 'connected');
    Carbon::setTestNow(now()->addMinutes(14));
    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.status', 'connected');
    app(SiteStatusService::class)->domainToken();
    expect(sstSentTo(SST_STATUS_URL))->toBe(1);

    Carbon::setTestNow(now()->addMinutes(2));
    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.status', 'connected');
    expect(sstSentTo(SST_STATUS_URL))->toBe(2);
});

it('keeps the last good answer while the hub is unreachable within the 7-day grace', function () {
    sstFakeStatus($this, ['status' => 'connected', 'expires_at' => '2027-01-31T00:00:00+07:00', 'token' => $this->serverToken]);
    app(SiteStatusService::class)->current();

    // A deploy clears the cache; the last good answer lives encrypted in the database.
    Cache::flush();
    Carbon::setTestNow(now()->addDays(6));
    sstHub($this, [SST_STATUS_URL => Http::failedConnection()]);

    $this->getJson('/api/v2/public/site-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_HUB_UNREACHABLE_GRACE)
        ->assertJsonPath('data.expires_at', '2027-01-31T00:00:00+07:00');

    expect(app(SiteStatusService::class)->domainToken())->toBe($this->serverToken);

    $stored = DB::table(HubCredentials::TABLE)->where('name', 'site_status')->value('value');
    expect($stored)->toBeString()->not->toContain($this->serverToken);
});

it('treats a hub server error or an unsigned answer as unreachable, not as a refusal', function () {
    sstFakeStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => $this->serverToken]);
    app(SiteStatusService::class)->current();
    Cache::flush();

    sstHub($this, [SST_STATUS_URL => Http::response('{"status":"connected","token":"forged"}', 200)]);
    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_HUB_UNREACHABLE_GRACE);
    expect(app(SiteStatusService::class)->domainToken())->toBe($this->serverToken);

    Cache::flush();
    sstHub($this, [SST_STATUS_URL => Http::response('oops', 503)]);
    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.status', 'connected');
});

it('waits a minute before asking an unreachable hub again', function () {
    sstHub($this, [SST_STATUS_URL => Http::failedConnection()]);

    app(SiteStatusService::class)->current();
    app(SiteStatusService::class)->current();
    expect(sstSentTo(SST_STATUS_URL))->toBe(1);

    Carbon::setTestNow(now()->addSeconds(61));
    app(SiteStatusService::class)->current();
    expect(sstSentTo(SST_STATUS_URL))->toBe(2);
});

it('answers not_connected once the hub has been unreachable for more than 7 days', function () {
    sstFakeStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => $this->serverToken]);
    app(SiteStatusService::class)->current();

    Cache::flush();
    Carbon::setTestNow(now()->addDays(7)->addMinute());
    sstHub($this, [SST_STATUS_URL => Http::failedConnection()]);

    $this->getJson('/api/v2/public/site-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'not_connected')
        ->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_HUB_UNREACHABLE)
        ->assertJsonPath('data.expires_at', null);

    expect(app(SiteStatusService::class)->domainToken())->toBeNull();
});

it('falls back to the old verify endpoint on a hub without the status endpoint', function (int $missing) {
    sstHub($this, [
        SST_STATUS_URL => Http::response('Not Found', $missing),
        SST_VERIFY_URL => Http::response([
            'status' => 'success',
            'data' => ['token' => $this->serverToken, 'expired_at' => '2027-01-31 00:00:00'],
            'message' => 'Domain is verified.',
        ]),
    ]);

    $raw = $this->getJson('/api/v2/public/site-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_CONNECTED)
        ->getContent();

    expect($raw)->not->toContain($this->serverToken)
        ->and(app(SiteStatusService::class)->domainToken())->toBe($this->serverToken);
    Http::assertSent(fn (ClientRequest $request) => $request->url() === SST_VERIFY_URL
        && $request->method() === 'GET'
        && $request->header('x-domain')[0] === $this->domain);
})->with([404, 405]);

it('answers not_connected when the old verify endpoint does not know the domain', function () {
    sstHub($this, [
        SST_STATUS_URL => Http::response('Not Found', 404),
        SST_VERIFY_URL => Http::response(['status' => 'error', 'message' => 'Domain is not exist.'], 400),
    ]);

    $this->getJson('/api/v2/public/site-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'not_connected')
        ->assertJsonPath('data.message_key', SiteStatusService::MESSAGE_NOT_CONNECTED);
});

it('works for a site whose credentials live only in the database', function () {
    config(['ispa-cms.cms_domain' => 'cms.ispa.io', 'ispa-cms.cms_domain_secret' => '']);
    app(HubCredentials::class)->store($this->domain, $this->secret);
    sstFakeStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => $this->serverToken]);

    $this->getJson('/api/v2/public/site-status')->assertJsonPath('data.status', 'connected');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === SST_STATUS_URL
        && $request->header(PlatformSignature::HEADER_DOMAIN)[0] === $this->domain);
});

it('throttles the public site-status endpoint', function () {
    sstFakeStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => $this->serverToken]);

    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v2/public/site-status')->assertOk();
    }
    $this->getJson('/api/v2/public/site-status')->assertStatus(429);
});

it('translates with the server-held token when the request carries none, and ignores a token an old admin sends', function () {
    $translateUrl = SST_HUB . '/api/v2/public/content/translate/en';
    sstFakeStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => $this->serverToken], [
        $translateUrl => Http::response(['status' => 'success', 'data' => ['content' => ['Hello']]]),
    ]);

    $client = app(CmsClient::class);
    expect($client->translate('en', 'Xin chào'))->toBe('Hello')
        ->and($client->translate('en', 'Xin chào'))->toBe('Hello');

    $sent = Http::recorded(fn (ClientRequest $request) => $request->url() === $translateUrl);
    expect($sent)->toHaveCount(2);
    foreach ($sent as [$request]) {
        expect($request->header('domain-token')[0])->toBe($this->serverToken)
            ->and($request->header('domain-name')[0])->toBe($this->domain)
            ->and($request->header('domain-secret')[0])->toBe($this->secret)
            ->and($request['content'])->toBe('Xin chào');
    }
});

it('runs gptTranslate and textToSpeech with the server-held token', function () {
    Storage::fake('public');
    config(['filesystems.default' => 'public']);
    $gptUrl = SST_HUB . '/api/v2/public/content/gpt-translate';
    $ttsUrl = SST_HUB . '/api/v2/public/content/text-to-speech/vi';
    sstFakeStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => $this->serverToken], [
        $gptUrl => Http::response(['status' => 'success', 'data' => ['choices' => [['message' => ['content' => 'Done']]]]]),
        $ttsUrl => Http::response('ID3-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
    ]);

    $client = app(CmsClient::class);
    expect($client->gptTranslate(['messages' => []]))->toBe('Done')
        ->and($client->textToSpeech('vi', 'Xin chào', 'audio/test.mp3'))->toBe(['path' => 'audio/test.mp3', 'name' => 'test.mp3']);

    Storage::disk('public')->assertExists('audio/test.mp3');
    Http::assertSent(fn (ClientRequest $request) => in_array($request->url(), [$gptUrl, $ttsUrl], true)
        && $request->header('domain-token')[0] === $this->serverToken);
});

it('leaves the process time limit alone in textToSpeech (it runs inside long-lived queue workers)', function () {
    // A set_time_limit(60) here gave the whole worker process a 60 s budget from that call on; the HTTP client has
    // its own timeout.
    Storage::fake('public');
    config(['filesystems.default' => 'public']);
    $ttsUrl = SST_HUB . '/api/v2/public/content/text-to-speech/vi';
    sstFakeStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => $this->serverToken], [
        $ttsUrl => Http::response('ID3-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
    ]);
    set_time_limit(0);

    app(CmsClient::class)->textToSpeech('vi', 'Xin chào', 'audio/limit.mp3');

    expect(ini_get('max_execution_time'))->toBe('0');
});

it('skips the hub-run services without a request when the site has no token', function () {
    config(['ispa-cms.cms_domain_secret' => '']);
    sstHub($this, [SST_VERIFY_URL => Http::response(['status' => 'error', 'message' => 'Domain is not exist.'], 400)]);

    $client = app(CmsClient::class);
    expect($client->translate('en', 'Xin chào'))->toBeNull()
        ->and($client->gptTranslate(['messages' => []]))->toBeNull()
        ->and($client->textToSpeech('vi', 'Xin chào', 'audio/x.mp3'))->toBe([]);

    expect(Http::recorded(fn (ClientRequest $request) => $request->url() !== SST_VERIFY_URL)->count())->toBe(0);
});
