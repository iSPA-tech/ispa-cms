<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Support\DiagnosticBundleBuilder;
use Cms\Core\Services\Modules\Support\ModuleSupportClient;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Security\LogRedactor;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

/*
 * the site lists its marketplace support tickets, reads their public thread and replies, all through the hub
 * (`GET|POST /api/v2/public/platform/support...`, signed by PlatformHubClient). Hub answers are reduced to known fields;
 * replies are masked on the site (LogRedactor rules plus known `.env` values) before they are sent. The hub scopes
 * every ticket to the calling domain (PlatformSupportThreadTest); here a hub 404 maps to one fixed code.
 */

const MST_BASE = '/api/v2/admin/utilities/modules/support';
const MST_UUID = '0193a2b4-5c6d-7e8f-9a0b-1c2d3e4f5a6b';

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    URL::forceScheme('https');

    $this->tmp = sys_get_temp_dir() . '/mst-' . bin2hex(random_bytes(6));
    mkdir($this->tmp . '/logs', 0755, true);
    $this->appKey = 'base64:' . base64_encode('k' . bin2hex(random_bytes(12)));
    $this->dbPassword = 'Db-Pw-' . bin2hex(random_bytes(5));
    $this->secret = 'test-secret-' . bin2hex(random_bytes(8));
    file_put_contents($this->tmp . '/.env', "APP_KEY={$this->appKey}\nDB_PASSWORD=\"{$this->dbPassword}\"\n");

    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => 'https://hub.test',
    ]);

    $this->hub = ['status' => 200, 'data' => [], 'requests' => []];
    $GLOBALS['mst_hub'] = &$this->hub;
    $GLOBALS['mst_secret'] = $this->secret;
    Http::fake(function (ClientRequest $sent) {
        $hub = &$GLOBALS['mst_hub'];
        $hub['requests'][] = ['method' => $sent->method(), 'path' => (string) parse_url($sent->url(), PHP_URL_PATH), 'body' => $sent->body()];
        $body = json_encode($hub['data']);
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

        return Http::response($body, $hub['status'], [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($GLOBALS['mst_secret'], $hub['status'], $nonce, $body)]);
    });

    $this->app->instance(ModuleCatalog::class, new ModuleCatalog(null, $this->tmp . '/release'));
    $this->app->bind(DiagnosticBundleBuilder::class, fn ($app) => new DiagnosticBundleBuilder(
        $app->make(LogRedactor::class),
        $app->make(ModuleCatalog::class),
        logDirectory: $this->tmp . '/logs',
        envFile: $this->tmp . '/.env',
    ));
});

afterEach(function () {
    @unlink($this->tmp . '/.env');
    @rmdir($this->tmp . '/logs');
    @rmdir($this->tmp);
});

/**
 * Sign in as a Webmaster (level-0 role) or a plain admin.
 */
function mstAdmin(bool $webmaster = true): User
{
    $user = User::query()->create(['name' => 'Webmaster', 'email' => uniqid('mst') . '@private.test', 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    if ($webmaster) {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster ' . uniqid(), 'custom' => json_encode(['level' => 0])]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }
    Sanctum::actingAs($user->fresh());

    return $user->fresh();
}

/**
 * A ticket summary as the hub sends it.
 *
 * @return array<string, mixed>
 */
function mstSummary(string $uuid = MST_UUID, string $status = 'pending_customer'): array
{
    return [
        'uuid' => $uuid, 'number' => 'EA-000012', 'module' => 'Probe', 'subject' => 'Import fails', 'status' => $status,
        'created_at' => '2026-11-15T10:00:00+07:00', 'last_activity_at' => '2026-11-15T11:00:00+07:00', 'last_reply_at' => '2026-11-15T11:00:00+07:00',
    ];
}

test('the list comes from the hub with known fields only', function () {
    mstAdmin();
    $this->hub['data'] = ['tickets' => [
        mstSummary() + ['requester_email' => 'site-1@sites.platform.invalid', 'id' => 7],
        mstSummary('not-a-uuid'),
        mstSummary('0193a2b4-5c6d-7e8f-9a0b-1c2d3e4f5a6c', 'weird_status'),
        'garbage',
    ]];

    $response = $this->getJson(MST_BASE)->assertOk();

    expect($this->hub['requests'])->toHaveCount(1)
        ->and($this->hub['requests'][0]['method'])->toBe('GET')
        ->and($this->hub['requests'][0]['path'])->toBe('/api/v2/public/platform/support');

    $items = $response->json('data.items');
    expect($items)->toHaveCount(2)
        ->and($items[0])->toBe(mstSummary())
        ->and($items[1]['uuid'])->toBe('0193a2b4-5c6d-7e8f-9a0b-1c2d3e4f5a6c')
        ->and($items[1]['status'])->toBeNull()
        ->and($response->getContent())->not->toContain('sites.platform.invalid');
});

test('a thread shows the public messages the hub returns, with known fields only', function () {
    mstAdmin();
    $this->hub['data'] = [
        'ticket' => mstSummary() + ['organization_id' => 3],
        'messages' => [
            ['uuid' => '0193a2b4-0000-7e8f-9a0b-1c2d3e4f5a6b', 'author' => 'site', 'body' => 'It fails', 'created_at' => '2026-11-15T10:00:00+07:00'],
            ['uuid' => '0193a2b4-0001-7e8f-9a0b-1c2d3e4f5a6b', 'author' => 'support', 'body' => 'Update please', 'created_at' => '2026-11-15T11:00:00+07:00', 'author_id' => 9],
            ['uuid' => '0193a2b4-0002-7e8f-9a0b-1c2d3e4f5a6b', 'author' => 'internal', 'body' => 'INTERNAL-NOTE', 'created_at' => null],
        ],
    ];

    $response = $this->getJson(MST_BASE . '/' . MST_UUID)->assertOk();

    expect($this->hub['requests'][0]['path'])->toBe('/api/v2/public/platform/support/' . MST_UUID)
        ->and($response->json('data.ticket'))->toBe(mstSummary())
        ->and($response->json('data.messages'))->toBe([
            ['uuid' => '0193a2b4-0000-7e8f-9a0b-1c2d3e4f5a6b', 'author' => 'site', 'body' => 'It fails', 'created_at' => '2026-11-15T10:00:00+07:00'],
            ['uuid' => '0193a2b4-0001-7e8f-9a0b-1c2d3e4f5a6b', 'author' => 'support', 'body' => 'Update please', 'created_at' => '2026-11-15T11:00:00+07:00'],
        ])
        ->and($response->getContent())->not->toContain('INTERNAL-NOTE');
});

test('a ticket the hub does not show to this site answers one fixed 404 and passes no hub text on', function () {
    mstAdmin();
    $this->hub['status'] = 404;
    $this->hub['data'] = ['message' => 'hub-internal-detail', 'error_code' => 'SERVICE_NOT_FOUND'];

    $show = $this->getJson(MST_BASE . '/' . MST_UUID)->assertNotFound()->assertJsonPath('error_code', 'MODULE_SUPPORT_TICKET_NOT_FOUND');
    $reply = $this->postJson(MST_BASE . '/' . MST_UUID . '/reply', ['body' => 'Hello'])->assertNotFound()->assertJsonPath('error_code', 'MODULE_SUPPORT_TICKET_NOT_FOUND');
    expect($show->getContent())->not->toContain('hub-internal-detail')
        ->and($reply->getContent())->not->toContain('hub-internal-detail');

    // A malformed uuid never reaches the hub.
    $before = count($this->hub['requests']);
    $this->getJson(MST_BASE . '/not-a-uuid')->assertNotFound();
    $this->postJson(MST_BASE . '/not-a-uuid/reply', ['body' => 'Hello'])->assertNotFound();
    expect($this->hub['requests'])->toHaveCount($before);
});

test('a reply is masked on the site before it is sent', function () {
    mstAdmin();
    $this->hub['status'] = 201;
    $this->hub['data'] = ['message' => '0193a2b4-0003-7e8f-9a0b-1c2d3e4f5a6b', 'status' => 'open', 'extra' => 'x'];

    $body = "Still broken.\nKey {$this->appKey} and DB {$this->dbPassword}\nMail jane.doe@example.com from 203.0.113.7\nAuthorization: Bearer 12|abcdef0123456789abcdef";
    $this->postJson(MST_BASE . '/' . MST_UUID . '/reply', ['body' => $body, 'extra' => 'INJECTED'])
        ->assertCreated()
        ->assertJsonPath('data.message', '0193a2b4-0003-7e8f-9a0b-1c2d3e4f5a6b')
        ->assertJsonPath('data.status', 'open');

    expect($this->hub['requests'])->toHaveCount(1);
    $sent = $this->hub['requests'][0];
    expect($sent['method'])->toBe('POST')
        ->and($sent['path'])->toBe('/api/v2/public/platform/support/' . MST_UUID . '/reply');
    $payload = json_decode($sent['body'], true);
    expect(array_keys($payload))->toBe(['body'])
        ->and($payload['body'])->toContain('Still broken.');
    foreach ([$this->appKey, $this->dbPassword, 'jane.doe@example.com', '203.0.113.7', 'abcdef0123456789abcdef', 'INJECTED', $this->secret] as $absent) {
        expect(str_contains($sent['body'], $absent))->toBeFalse();
    }
});

test('hub refusals of a reply map to fixed codes', function () {
    mstAdmin();

    $this->hub['status'] = 409;
    $this->hub['data'] = ['message' => 'hub-internal-detail', 'error_code' => 'SUPPORT_CLOSED'];
    $this->postJson(MST_BASE . '/' . MST_UUID . '/reply', ['body' => 'Hello'])->assertStatus(409)->assertJsonPath('error_code', 'MODULE_SUPPORT_CLOSED');

    $this->hub['status'] = 403;
    $this->hub['data'] = ['error_code' => 'MODULE_RENEWAL_REQUIRED'];
    $this->postJson(MST_BASE . '/' . MST_UUID . '/reply', ['body' => 'Hello'])->assertForbidden()->assertJsonPath('error_code', 'MODULE_SUPPORT_RENEWAL_REQUIRED');

    $this->hub['status'] = 429;
    $this->postJson(MST_BASE . '/' . MST_UUID . '/reply', ['body' => 'Hello'])->assertStatus(429)->assertJsonPath('error_code', 'MODULE_SUPPORT_RATE_LIMITED');

    $this->hub['status'] = 500;
    $this->getJson(MST_BASE)->assertStatus(502)->assertJsonPath('error_code', 'MODULE_SUPPORT_UNAVAILABLE');

    // The hub does not recognise this site's connection: a plain code of its own, not "check the connection".
    $this->hub['status'] = 401;
    $this->hub['data'] = ['message' => 'Platform request is not authorized.', 'error_code' => 'PLATFORM_UNAUTHORIZED'];
    $this->getJson(MST_BASE)->assertStatus(502)->assertJsonPath('error_code', 'MODULE_HUB_NOT_RECOGNISED');
    $this->getJson(MST_BASE . '/' . MST_UUID)->assertStatus(502)->assertJsonPath('error_code', 'MODULE_HUB_NOT_RECOGNISED');

    // A hub answer of the wrong shape is a hub failure, not an empty list.
    $this->hub['status'] = 200;
    $this->hub['data'] = ['tickets' => 'nope'];
    $this->getJson(MST_BASE)->assertStatus(502)->assertJsonPath('error_code', 'MODULE_SUPPORT_UNAVAILABLE');
});

test('only a Webmaster may list, read or reply, and an invalid reply never reaches the hub', function () {
    $this->getJson(MST_BASE)->assertUnauthorized();

    mstAdmin(false); // user id 1 is always a Webmaster
    mstAdmin(false);
    $this->getJson(MST_BASE)->assertForbidden();
    $this->getJson(MST_BASE . '/' . MST_UUID)->assertForbidden();
    $this->postJson(MST_BASE . '/' . MST_UUID . '/reply', ['body' => 'Hello'])->assertForbidden();

    mstAdmin();
    $this->postJson(MST_BASE . '/' . MST_UUID . '/reply', ['body' => ''])->assertUnprocessable();
    $this->postJson(MST_BASE . '/' . MST_UUID . '/reply', ['body' => str_repeat('x', 5001)])->assertUnprocessable();
    expect($this->hub['requests'])->toBe([]);
});

test('without hub credentials the list answers 502 and nothing is sent', function () {
    mstAdmin();
    $this->app->instance(ModuleSupportClient::class, new class extends ModuleSupportClient {
        protected function hub(): PlatformHubClient
        {
            throw new PlatformHubException(PlatformHubException::NOT_CONFIGURED, 'Platform hub domain or secret is not configured.');
        }
    });

    $this->getJson(MST_BASE)->assertStatus(502)->assertJsonPath('error_code', 'MODULE_SUPPORT_UNAVAILABLE');
    // Only requests to the hub count (a 5xx answer may also raise the site's own error alert).
    expect(array_filter($this->hub['requests'], fn (array $r): bool => str_starts_with($r['path'], '/api/v2/public/platform/')))->toBe([]);
});
