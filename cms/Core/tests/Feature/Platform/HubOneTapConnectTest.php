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
use Cms\Core\Services\Platform\HubOneTapConnect;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Platform\SiteConnectService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * One-tap hub connect (plan 2026-11-22-hub-one-tap-connect, Task 2): `POST /api/v2/admin/hub-connect/start` and
 * `POST /api/v2/admin/hub-connect/finish`.
 *
 * The state is checked (known, unexpired, unused, same user) before the hub is called; the code is exchanged with the
 * PKCE verifier for the site id and secret, which then run the same signed connect pipeline as the manual form. The
 * hub is faked; nothing here writes a tracked file.
 */
const HOTC_HUB = 'https://hub.example.test';
const HOTC_TOKEN_URL = HOTC_HUB . '/api/v2/public/platform/site-connect/token';
const HOTC_CONNECT_URL = HOTC_HUB . '/api/platform/v1/site/connect';

beforeEach(function () {
    $this->withoutMiddleware([ApiLocalization::class, HttpsProtocol::class, Localization::class]);
    config(['cache.default' => 'array']);
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-22 10:00:00');

    $this->domain = 'owner.example.org';
    $this->secret = 'ispa_site_' . bin2hex(random_bytes(16));
    $this->code = 'code_' . bin2hex(random_bytes(16));
    $this->hubRoutes = [];
    Http::fake(function (ClientRequest $request) {
        $answer = $this->hubRoutes[$request->url()] ?? null;

        return $answer instanceof Closure ? $answer($request) : $answer;
    });
    config([
        'ispa-cms.cms_domain' => 'cms.ispa.io',
        'ispa-cms.cms_domain_secret' => '',
        'ispa-cms.platform_hub_url' => HOTC_HUB,
        'core.hub.portal_url' => null,
        'app.url' => 'https://' . $this->domain,
    ]);

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
    $this->webmaster = $makeUser('one-tap@test.local', [['hub', 'connect']]);
    $this->otherWebmaster = $makeUser('one-tap-other@test.local', [['hub', 'connect']]);
    $this->editor = $makeUser('one-tap-editor@test.local', [['widget', 'viewAny']]);

    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) {
        $this->logged[] = $event->message . ' ' . json_encode($event->context);
    });
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Start an attempt as the given user and return the redirect query parameters.
 *
 * @return array<string, string>
 */
function hotcStart(object $test, ?User $user = null): array
{
    Sanctum::actingAs($user ?? $test->webmaster);
    $url = $test->postJson('/api/v2/admin/hub-connect/start')->assertOk()->json('data.redirect_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

function hotcFinish(object $test, array $body): \Illuminate\Testing\TestResponse
{
    return $test->postJson('/api/v2/admin/hub-connect/finish', $body);
}

/**
 * The hub token endpoint answers the site id and secret (in the `data` envelope unless `$wrapped` is false) and
 * remembers the exchange body for assertions.
 */
function hotcTokenAnswers(object $test, bool $wrapped = true, ?string $siteId = null): void
{
    $test->hubRoutes[HOTC_TOKEN_URL] = function (ClientRequest $request) use ($test, $wrapped, $siteId) {
        $test->exchanged[] = $request->data();
        $pair = ['site_id' => $siteId ?? $test->domain, 'secret' => $test->secret];

        return Http::response($wrapped ? ['data' => $pair] : $pair, 200);
    };
}

/**
 * The signed hub connect answer, signed with the secret the exchange handed out.
 */
function hotcConnectAnswers(object $test, array $payload, int $status = 200): void
{
    $test->hubRoutes[HOTC_CONNECT_URL] = function (ClientRequest $request) use ($test, $payload, $status) {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $nonce = $request->header(PlatformSignature::HEADER_NONCE)[0] ?? '';

        return Http::response($body, $status, [
            PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($test->secret, $status, $nonce, $body),
        ]);
    };
}

function hotcStoredNothing(): void
{
    expect(DB::table(HubCredentials::TABLE)->where('name', 'site')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'like', 'hub.%')->count())->toBe(0);
}

/**
 * Neither a response nor a log line carries the secret, the code, a verifier or a state.
 */
function hotcNothingLeaked(object $test, array $responses, array $extra = []): void
{
    $verifiers = DB::table(HubOneTapConnect::TABLE)->pluck('verifier')->all();
    $haystacks = array_merge(array_map(static fn ($r) => (string) $r->getContent(), $responses), $test->logged);
    foreach ($haystacks as $text) {
        expect($text)->not->toContain($test->secret)->and($text)->not->toContain($test->code);
        foreach ($extra as $needle) {
            expect($text)->not->toContain($needle);
        }
    }
    expect($verifiers)->each->not->toContain($test->secret);
}

it('starts with a portal URL carrying the domain, an S256 challenge and a return URL on the own host', function () {
    $query = hotcStart($this);

    expect($query['domain'])->toBe($this->domain)
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['state'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['return_url'])->toBe('https://' . $this->domain . '/iPanel/hub-connect/callback');

    $row = DB::table(HubOneTapConnect::TABLE)->sole();
    expect($row->state_hash)->toBe(hash('sha256', $query['state']))
        ->and((int) $row->user_id)->toBe($this->webmaster->id)
        ->and($row->used_at)->toBeNull()
        ->and(Carbon::parse($row->expires_at)->equalTo(Carbon::now()->addMinutes(10)))->toBeTrue()
        ->and(json_encode($row))->not->toContain($query['state']);
    $verifier = \Illuminate\Support\Facades\Crypt::decryptString($row->verifier);
    expect(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))->toBe($query['code_challenge']);
    Http::assertNothingSent();
});

it('sends the browser to the portal host derived from the hub address, or the configured portal URL', function () {
    Sanctum::actingAs($this->webmaster);
    $url = $this->postJson('/api/v2/admin/hub-connect/start')->json('data.redirect_url');
    expect($url)->toStartWith(HOTC_HUB . '/portal/connect-site?');

    config(['core.hub.portal_url' => 'https://portal.example.test/']);
    $url = $this->postJson('/api/v2/admin/hub-connect/start')->json('data.redirect_url');
    expect($url)->toStartWith('https://portal.example.test/portal/connect-site?');
});

it('refuses to start for a user without hub.connect or signed out', function () {
    $this->postJson('/api/v2/admin/hub-connect/start')->assertStatus(401);

    Sanctum::actingAs($this->editor);
    $this->postJson('/api/v2/admin/hub-connect/start')->assertStatus(403)->assertJsonPath('error_code', 'HUB_CONNECT_FORBIDDEN');
    hotcFinish($this, ['code' => $this->code, 'state' => str_repeat('a', 43)])
        ->assertStatus(403)->assertJsonPath('error_code', 'HUB_CONNECT_FORBIDDEN');

    expect(DB::table(HubOneTapConnect::TABLE)->count())->toBe(0);
    Http::assertNothingSent();
});

it('connects: exchanges the code with the verifier, runs the signed connect, stores and audits', function () {
    $this->exchanged = [];
    hotcTokenAnswers($this);
    hotcConnectAnswers($this, ['status' => 'connected', 'reason' => null]);
    $query = hotcStart($this);

    $response = hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.source', 'stored')
        ->assertJsonPath('data.message_key', 'text.site_connect.connected');

    expect($this->exchanged)->toHaveCount(1);
    $sent = $this->exchanged[0];
    expect($sent['code'])->toBe($this->code)
        ->and($sent['domain'])->toBe($this->domain)
        ->and(rtrim(strtr(base64_encode(hash('sha256', $sent['code_verifier'], true)), '+/', '-_'), '='))->toBe($query['code_challenge']);
    expect(app(HubCredentials::class)->credentials())
        ->toMatchArray(['domain' => $this->domain, 'secret' => $this->secret, 'source' => HubCredentials::SOURCE_DATABASE]);
    $audit = AuditLog::query()->where('action', SiteConnectService::AUDIT_CONNECTED)->sole();
    expect($audit->user_id)->toBe($this->webmaster->id)->and(json_encode($audit->getAttributes()))->not->toContain($this->secret);
    expect(DB::table(HubOneTapConnect::TABLE)->sole()->used_at)->not->toBeNull();
    hotcNothingLeaked($this, [$response], [$sent['code_verifier']]);
});

it('accepts a token answer without the data envelope', function () {
    $this->exchanged = [];
    hotcTokenAnswers($this, wrapped: false);
    hotcConnectAnswers($this, ['status' => 'connected', 'reason' => null]);
    $query = hotcStart($this);

    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])->assertOk()->assertJsonPath('data.status', 'connected');
    expect(app(HubCredentials::class)->hasStoredPair())->toBeTrue();
});

it('refuses an unknown, malformed or missing state before calling the hub', function (array $body) {
    hotcStart($this);

    hotcFinish($this, $body + ['code' => $this->code])->assertStatus(400)->assertJsonPath('error_code', 'HUB_CONNECT_STATE_INVALID');

    Http::assertNothingSent();
    hotcStoredNothing();
    expect(DB::table(HubOneTapConnect::TABLE)->sole()->used_at)->toBeNull();
})->with([
    'never issued' => [['state' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')]],
    'missing' => [[]],
    'not a string' => [['state' => ['x']]],
    'too long' => [['state' => str_repeat('a', 300)]],
]);

it('refuses an expired state before calling the hub', function () {
    $query = hotcStart($this);
    Carbon::setTestNow(Carbon::now()->addMinutes(10)->addSecond());

    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertStatus(400)->assertJsonPath('error_code', 'HUB_CONNECT_STATE_INVALID');

    Http::assertNothingSent();
    hotcStoredNothing();
});

it('refuses a replayed state: the second finish never reaches the hub and stores nothing new', function () {
    $this->exchanged = [];
    hotcTokenAnswers($this);
    $this->hubRoutes[HOTC_TOKEN_URL] = Http::response(['error_code' => 'HUB_CONNECT_CODE_INVALID'], 400);
    $query = hotcStart($this);

    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])->assertStatus(502);
    hotcTokenAnswers($this);
    hotcConnectAnswers($this, ['status' => 'connected', 'reason' => null]);
    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertStatus(400)->assertJsonPath('error_code', 'HUB_CONNECT_STATE_INVALID');

    Http::assertSentCount(1);
    expect($this->exchanged)->toBe([]);
    hotcStoredNothing();
});

it('refuses a state started by another user and burns it', function () {
    $query = hotcStart($this, $this->webmaster);

    Sanctum::actingAs($this->otherWebmaster);
    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertStatus(400)->assertJsonPath('error_code', 'HUB_CONNECT_STATE_INVALID');

    Sanctum::actingAs($this->webmaster);
    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertStatus(400)->assertJsonPath('error_code', 'HUB_CONNECT_STATE_INVALID');

    Http::assertNothingSent();
    hotcStoredNothing();
});

it('answers HUB_CONNECT_DENIED when the person pressed Huỷ, and burns the state', function () {
    $query = hotcStart($this);

    hotcFinish($this, ['error' => 'access_denied', 'state' => $query['state']])
        ->assertStatus(400)->assertJsonPath('error_code', 'HUB_CONNECT_DENIED');
    expect(DB::table(HubOneTapConnect::TABLE)->sole()->used_at)->not->toBeNull();

    hotcTokenAnswers($this);
    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])->assertStatus(400)->assertJsonPath('error_code', 'HUB_CONNECT_STATE_INVALID');
    Http::assertNothingSent();
    hotcStoredNothing();
});

it('maps exchange failures to HUB_CONNECT_EXCHANGE_FAILED and stores nothing', function (Closure $answer) {
    $this->hubRoutes[HOTC_TOKEN_URL] = $answer;
    $query = hotcStart($this);

    $response = hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertStatus(502)->assertJsonPath('error_code', 'HUB_CONNECT_EXCHANGE_FAILED');

    hotcStoredNothing();
    expect(DB::table(HubOneTapConnect::TABLE)->sole()->used_at)->not->toBeNull();
    hotcNothingLeaked($this, [$response]);
})->with([
    'code refused' => [fn () => fn () => Http::response(['status' => 'error', 'error_code' => 'HUB_CONNECT_CODE_INVALID', 'message' => 'x'], 400)],
    'hub down' => [fn () => fn () => throw new ConnectionException('refused')],
    'server error' => [fn () => fn () => Http::response('oops', 500)],
    'redirect' => [fn () => fn () => Http::response('', 302, ['Location' => 'https://evil.example.test/'])],
    'no secret' => [fn () => fn () => Http::response(['data' => ['site_id' => 'owner.example.org']], 200)],
    'other site id' => [fn () => fn () => Http::response(['data' => ['site_id' => 'evil.example.org', 'secret' => 'ispa_site_0123456789']], 200)],
]);

it('answers HUB_CONNECT_DOMAIN_TAKEN when the domain belongs to another hub account', function () {
    $this->hubRoutes[HOTC_TOKEN_URL] = Http::response(['status' => 'error', 'error_code' => 'HUB_CONNECT_DOMAIN_TAKEN', 'message' => 'taken'], 409);
    $query = hotcStart($this);

    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'HUB_CONNECT_DOMAIN_TAKEN')
        ->assertJsonPath('message', 'Website này đang thuộc một tài khoản iSPA khác.');

    hotcStoredNothing();
});

it('answers HUB_CONNECT_SITE_DISABLED when the hub row was turned off in the account', function () {
    $this->hubRoutes[HOTC_TOKEN_URL] = Http::response(['status' => 'error', 'error_code' => 'HUB_CONNECT_SITE_DISABLED', 'message' => 'disabled'], 409);
    $query = hotcStart($this);

    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertStatus(409)->assertJsonPath('error_code', 'HUB_CONNECT_SITE_DISABLED');

    hotcStoredNothing();
});

it('asks before going to the hub when credentials are stored, and keeps the answer for the callback', function () {
    app(HubCredentials::class)->store('old.example.org', 'old-secret-value');
    $this->exchanged = [];
    hotcTokenAnswers($this);
    hotcConnectAnswers($this, ['status' => 'connected', 'reason' => null]);

    Sanctum::actingAs($this->webmaster);
    $this->postJson('/api/v2/admin/hub-connect/start')->assertStatus(409)->assertJsonPath('error_code', 'HUB_ALREADY_CONNECTED');
    expect(DB::table(HubOneTapConnect::TABLE)->count())->toBe(0);

    $url = $this->postJson('/api/v2/admin/hub-connect/start', ['replace' => true])->assertOk()->json('data.redirect_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertOk()->assertJsonPath('data.status', 'connected');
    expect(app(HubCredentials::class)->credentials())->toMatchArray(['domain' => $this->domain, 'secret' => $this->secret])
        ->and(AuditLog::query()->where('action', SiteConnectService::AUDIT_RECONNECTED)->sole()->user_id)->toBe($this->webmaster->id);
});

it('asks before replacing credentials stored after the attempt started, without exchanging the code', function () {
    $this->exchanged = [];
    hotcTokenAnswers($this);
    hotcConnectAnswers($this, ['status' => 'connected', 'reason' => null]);
    $query = hotcStart($this);
    app(HubCredentials::class)->store('old.example.org', 'old-secret-value');

    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertStatus(409)->assertJsonPath('error_code', 'HUB_ALREADY_CONNECTED');
    Http::assertNothingSent();
    expect(app(HubCredentials::class)->domain())->toBe('old.example.org');

    hotcFinish($this, ['code' => $this->code, 'state' => $query['state'], 'replace' => true])
        ->assertOk()->assertJsonPath('data.status', 'connected');
});

it('refuses one-tap with HUB_CONNECT_MANAGED_BY_ENV when the credentials come from .env', function () {
    $query = hotcStart($this);
    config(['ispa-cms.cms_domain' => $this->domain, 'ispa-cms.cms_domain_secret' => 'env-secret-value']);
    hotcTokenAnswers($this);

    $this->postJson('/api/v2/admin/hub-connect/start', ['replace' => true])
        ->assertStatus(409)->assertJsonPath('error_code', 'HUB_CONNECT_MANAGED_BY_ENV');
    expect(DB::table(HubOneTapConnect::TABLE)->count())->toBe(1);

    hotcFinish($this, ['code' => $this->code, 'state' => $query['state'], 'replace' => true])
        ->assertStatus(409)->assertJsonPath('error_code', 'HUB_CONNECT_MANAGED_BY_ENV');
    Http::assertNothingSent();
    hotcStoredNothing();
});

it('sends the bare domain for a www APP_URL, keeps the www host in the return URL and accepts the bare site id', function () {
    config(['app.url' => 'https://WWW.' . $this->domain . '.']);
    $this->exchanged = [];
    hotcTokenAnswers($this);
    hotcConnectAnswers($this, ['status' => 'connected', 'reason' => null]);
    $query = hotcStart($this);

    expect($query['domain'])->toBe($this->domain)
        ->and($query['return_url'])->toBe('https://www.' . $this->domain . '/iPanel/hub-connect/callback');

    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])->assertOk()->assertJsonPath('data.status', 'connected');
    expect($this->exchanged[0]['domain'])->toBe($this->domain)
        ->and(app(HubCredentials::class)->credentials())->toMatchArray(['domain' => $this->domain]);
});

it('prunes attempts that ended more than a day ago', function () {
    hotcStart($this);
    Carbon::setTestNow(Carbon::now()->addDay()->addMinutes(9));
    hotcStart($this);
    expect(app(HubOneTapConnect::class)->prune())->toBe(0);

    Carbon::setTestNow(Carbon::now()->addMinutes(2));
    expect(app(HubOneTapConnect::class)->prune())->toBe(1)
        ->and(DB::table(HubOneTapConnect::TABLE)->count())->toBe(1);
});

it('when the hub cannot reach the site, says so plainly and keeps nothing half-stored', function () {
    hotcTokenAnswers($this);
    $this->hubRoutes[HOTC_CONNECT_URL] = fn () => throw new ConnectionException('refused');
    $query = hotcStart($this);

    $response = hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertStatus(503)->assertJsonPath('error_code', 'HUB_UNREACHABLE');

    hotcStoredNothing();
    hotcNothingLeaked($this, [$response]);
});

it('reports a pending domain check with the reason, exactly like the manual connect', function () {
    hotcTokenAnswers($this);
    hotcConnectAnswers($this, ['status' => 'pending', 'reason' => 'unreachable']);
    $query = hotcStart($this);

    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.message_key', 'text.site_connect.reason.unreachable');
});

it('maps a hub refusal of the exchanged pair to CREDENTIALS_REFUSED and stores nothing', function () {
    hotcTokenAnswers($this);
    $this->hubRoutes[HOTC_CONNECT_URL] = Http::response('{"message":"Unauthorized"}', 401);
    $query = hotcStart($this);

    hotcFinish($this, ['code' => $this->code, 'state' => $query['state']])
        ->assertStatus(422)->assertJsonPath('error_code', 'CREDENTIALS_REFUSED');
    hotcStoredNothing();
});
