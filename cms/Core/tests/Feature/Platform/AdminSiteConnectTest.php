<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Services\Platform\HubCredentials;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Platform\SiteConnectService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * Hub connect from the admin (spec 2026-11-22-hub-connect-from-admin-design): `POST /api/v2/admin/site-connect`.
 *
 * A signed-in user with `hub.connect` connects with the site id and secret only, no setup code. Stored credentials are
 * kept unless `replace` is true; `.env` credentials keep winning and the answer says so. Every connect is audited with
 * the acting user id. The secret and the hub address never reach a response, a log line or the audit row.
 */
const ASCT_HUB = 'https://hub.example.test';
const ASCT_CONNECT_URL = ASCT_HUB . '/api/platform/v1/site/connect';
const ASCT_ROUTE = '/api/v2/admin/site-connect';

beforeEach(function () {
    $this->withoutMiddleware([ApiLocalization::class, HttpsProtocol::class, Localization::class]);
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-22 10:00:00');

    $this->domain = 'owner.example.org';
    $this->secret = 'ispa_site_' . bin2hex(random_bytes(16));
    $this->hubRoutes = [];
    Http::fake(function (ClientRequest $request) {
        $answer = $this->hubRoutes[$request->url()] ?? null;

        return $answer instanceof Closure ? $answer($request) : $answer;
    });
    config([
        'ispa-cms.cms_domain' => 'cms.ispa.io',
        'ispa-cms.cms_domain_secret' => '',
        'ispa-cms.platform_hub_url' => ASCT_HUB,
        'app.url' => 'https://' . $this->domain,
    ]);

    $this->code = app(HubCredentials::class)->issueSetupCode();

    $makeUser = function (string $email, array $permissions): User {
        $ids = array_map(
            static fn (array $p): int => Permission::query()->firstOrCreate(['group' => $p[0], 'name' => $p[1]], ['title' => implode('.', $p)])->id,
            $permissions,
        );
        $user = User::query()->create(['name' => $email, 'email' => $email, 'password' => Hash::make('password')]);
        $user->forceFill(['type' => UserType::ADMIN])->save();
        $role = Role::query()->create(['name' => $email, 'user_id' => $user->id]);
        $role->permissions()->attach($ids);
        $user->roles()->attach($role->id);

        return $user;
    };
    $this->webmaster = $makeUser('hub-connect@test.local', [['hub', 'connect']]);
    $this->editor = $makeUser('hub-editor@test.local', [['widget', 'viewAny']]);

    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) {
        $this->logged[] = $event->message . ' ' . json_encode($event->context);
    });
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Hub answer for the connect call, signed with the secret the site sent.
 */
function asctHubAnswers(object $test, array $payload, int $status = 200): void
{
    $test->hubRoutes[ASCT_CONNECT_URL] = function (ClientRequest $request) use ($test, $payload, $status) {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $nonce = $request->header(PlatformSignature::HEADER_NONCE)[0] ?? '';

        return Http::response($body, $status, [
            PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($test->secret, $status, $nonce, $body),
        ]);
    };
}

function asctPost(object $test, array $override = []): \Illuminate\Testing\TestResponse
{
    return $test->postJson(ASCT_ROUTE, $override + ['site_id' => $test->domain, 'secret' => $test->secret]);
}

/**
 * Neither the response nor any log line holds the secret or the hub address.
 */
function asctNothingLeaked(object $test, \Illuminate\Testing\TestResponse $response): void
{
    $raw = (string) $response->getContent();
    expect($raw)->not->toContain($test->secret)->and($raw)->not->toContain('hub.example.test');
    foreach ($test->logged as $line) {
        expect($line)->not->toContain($test->secret)->and($line)->not->toContain('hub.example.test');
    }
}

it('connects without a setup code for a signed-in user with hub.connect, ends the setup code and audits it', function () {
    asctHubAnswers($this, ['status' => 'connected', 'reason' => null]);
    Sanctum::actingAs($this->webmaster);

    $response = asctPost($this)
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.source', 'stored')
        ->assertJsonPath('data.warning_key', null);
    asctNothingLeaked($this, $response);

    expect(app(HubCredentials::class)->credentials())
        ->toMatchArray(['domain' => $this->domain, 'secret' => $this->secret, 'source' => HubCredentials::SOURCE_DATABASE]);
    expect(app(HubCredentials::class)->checkSetupCode($this->code))->toBeFalse();

    $audit = AuditLog::query()->where('action', SiteConnectService::AUDIT_CONNECTED)->sole();
    expect($audit->user_id)->toBe($this->webmaster->id)
        ->and(json_encode($audit->options))->not->toContain($this->secret)
        ->and(json_encode($audit->getAttributes()))->not->toContain($this->secret);
});

it('refuses a user without hub.connect with 403 and never reaches the hub', function () {
    Sanctum::actingAs($this->editor);

    asctPost($this)->assertStatus(403)->assertJsonPath('error_code', 'HUB_CONNECT_FORBIDDEN');

    Http::assertNothingSent();
    expect(DB::table(HubCredentials::TABLE)->where('name', 'site')->exists())->toBeFalse();
});

it('refuses a signed-out request with 401', function () {
    asctPost($this)->assertStatus(401);

    Http::assertNothingSent();
});

it('refuses malformed input with HUB_CONNECT_INVALID', function (array $override) {
    Sanctum::actingAs($this->webmaster);

    asctPost($this, $override)->assertStatus(422)->assertJsonPath('error_code', 'HUB_CONNECT_INVALID');

    Http::assertNothingSent();
})->with([
    'no site id' => [['site_id' => '']],
    'site id with spaces' => [['site_id' => 'bad host.org']],
    'short secret' => [['secret' => 'short']],
    'secret not a string' => [['secret' => ['x']]],
]);

it('keeps stored credentials and answers 409 when replace is missing', function () {
    app(HubCredentials::class)->store('old.example.org', 'old-secret-value');
    Sanctum::actingAs($this->webmaster);

    asctPost($this)->assertStatus(409)->assertJsonPath('error_code', 'HUB_ALREADY_CONNECTED');

    Http::assertNothingSent();
    expect(app(HubCredentials::class)->domain())->toBe('old.example.org');
});

it('replaces stored credentials when replace is true and audits a reconnect', function () {
    app(HubCredentials::class)->store('old.example.org', 'old-secret-value');
    asctHubAnswers($this, ['status' => 'connected', 'reason' => null]);
    Sanctum::actingAs($this->webmaster);

    $response = asctPost($this, ['replace' => true])->assertOk()->assertJsonPath('data.status', 'connected');
    asctNothingLeaked($this, $response);

    expect(app(HubCredentials::class)->credentials())->toMatchArray(['domain' => $this->domain, 'secret' => $this->secret]);
    $audit = AuditLog::query()->where('action', SiteConnectService::AUDIT_RECONNECTED)->sole();
    expect($audit->user_id)->toBe($this->webmaster->id);
    expect(AuditLog::query()->where('action', SiteConnectService::AUDIT_CONNECTED)->exists())->toBeFalse();
});

it('maps a hub refusal and an unreachable hub to error codes and stores nothing', function () {
    Sanctum::actingAs($this->webmaster);

    $this->hubRoutes[ASCT_CONNECT_URL] = Http::response('{"message":"Unauthorized"}', 401);
    $refused = asctPost($this)->assertStatus(422)->assertJsonPath('error_code', 'CREDENTIALS_REFUSED');
    asctNothingLeaked($this, $refused);

    $this->hubRoutes[ASCT_CONNECT_URL] = fn () => throw new ConnectionException('refused');
    $down = asctPost($this)->assertStatus(503)->assertJsonPath('error_code', 'HUB_UNREACHABLE');
    asctNothingLeaked($this, $down);

    expect(DB::table(HubCredentials::TABLE)->where('name', 'site')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'like', 'hub.%')->count())->toBe(0)
        ->and(app(HubCredentials::class)->checkSetupCode($this->code))->toBeTrue();
});

it('stores the pair but reports that .env credentials keep winning', function () {
    config(['ispa-cms.cms_domain' => 'env.example.org', 'ispa-cms.cms_domain_secret' => 'env-secret-value']);
    asctHubAnswers($this, ['status' => 'connected', 'reason' => null]);
    Sanctum::actingAs($this->webmaster);

    asctPost($this)
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.source', 'env')
        ->assertJsonPath('data.warning_key', SiteConnectService::WARNING_ENV_WINS);

    expect(DB::table(HubCredentials::TABLE)->where('name', 'site')->exists())->toBeTrue()
        ->and(app(HubCredentials::class)->source())->toBe(HubCredentials::SOURCE_ENV);
});

it('leaves the public endpoint requiring the setup code', function () {
    asctHubAnswers($this, ['status' => 'connected', 'reason' => null]);
    Sanctum::actingAs($this->webmaster);

    $this->postJson('/api/v2/public/site-connect', ['site_id' => $this->domain, 'site_secret' => $this->secret])
        ->assertStatus(422)
        ->assertJsonPath('data.message_key', 'text.site_connect.invalid_input');

    Http::assertNothingSent();
    expect(DB::table(HubCredentials::TABLE)->where('name', 'site')->exists())->toBeFalse();
});

it('is throttled to 5 attempts a minute', function () {
    Sanctum::actingAs($this->webmaster);
    for ($i = 0; $i < 5; $i++) {
        asctPost($this, ['site_id' => ''])->assertStatus(422);
    }

    asctPost($this)->assertStatus(429);
    Http::assertNothingSent();
});
