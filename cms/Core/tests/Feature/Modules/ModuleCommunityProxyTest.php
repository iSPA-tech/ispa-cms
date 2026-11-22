<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleCommunityClient;
use Cms\Core\Services\Modules\Install\ModuleListingClient;
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

/*
 * the site proxies ratings, questions and private feedback to the hub. The Webmaster is asserted to the hub
 * only by a pseudonymous reference (HMAC of the user uuid keyed by the domain secret) and a display name; the e-mail,
 * the user id and the browser IP never leave the site.
 */

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    URL::forceScheme('https');

    $this->tmp = sys_get_temp_dir() . '/community-proxy-' . bin2hex(random_bytes(6));
    $this->releaseDir = $this->tmp . '/release';
    $this->runtimeDir = $this->tmp . '/runtime';
    mkdir($this->releaseDir, 0755, true);
    mkdir($this->runtimeDir, 0755, true);
    RuntimeModules::setPath($this->runtimeDir);

    $this->secret = 'test-secret-' . bin2hex(random_bytes(8));
    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => 'https://hub.test',
    ]);

    $this->hub = [
        'mine' => [200, ['rating' => null, 'can_rate' => true, 'reason' => null, 'installed_version' => '2026.3.0']],
        'ratings' => [200, ['average' => 4.5, 'count' => 2, 'items' => [
            ['stars' => 5, 'body' => '<b>Great</b>', 'version' => '2026.3.0', 'author_name' => 'An', 'updated_at' => '2026-11-14T00:00:00+00:00', 'author_ref' => str_repeat('f', 64)],
        ], 'page' => 1, 'per_page' => 10, 'total' => 1]],
        'questions' => [200, ['items' => [
            ['body' => 'Does it work offline?', 'author_kind' => 'site', 'author_name' => 'Binh', 'created_at' => '2026-11-14T00:00:00+00:00', 'answers' => [
                ['body' => 'Yes.', 'author_kind' => 'ispa', 'author_name' => 'iSPA', 'created_at' => '2026-11-14T01:00:00+00:00'],
            ]],
        ], 'page' => 1, 'per_page' => 10, 'total' => 1]],
        'write' => [200, ['rating' => ['stars' => 4, 'body' => 'Good', 'version' => '2026.3.0', 'author_name' => 'Mai', 'updated_at' => '2026-11-15T00:00:00+00:00', 'hidden' => false]]],
        'requests' => [],
    ];
    $GLOBALS['cp_hub'] = &$this->hub;
    $GLOBALS['cp_secret'] = $this->secret;
    Http::fake(function (ClientRequest $sent) {
        $hub = &$GLOBALS['cp_hub'];
        $path = (string) parse_url($sent->url(), PHP_URL_PATH);
        $hub['requests'][] = ['method' => $sent->method(), 'path' => $path, 'query' => (string) parse_url($sent->url(), PHP_URL_QUERY), 'body' => $sent->body(), 'headers' => $sent->headers()];
        if ($sent->method() !== 'GET') {
            [$status, $data] = $hub['write'];
        } elseif (str_ends_with($path, '/mine')) {
            [$status, $data] = $hub['mine'];
        } elseif (str_contains($path, '/ratings/')) {
            [$status, $data] = $hub['ratings'];
        } else {
            [$status, $data] = $hub['questions'];
        }
        $body = json_encode($data);
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

        return Http::response($body, $status, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($GLOBALS['cp_secret'], $status, $nonce, $body)]);
    });

    $this->app->instance(ModuleCatalog::class, new ModuleCatalog(null, $this->releaseDir));
});

afterEach(function () {
    RuntimeModules::resetPath();
    cpRemoveTree($this->tmp);
});

/**
 * Remove a temp tree.
 */
function cpRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            cpRemoveTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

/**
 * Sign in as a Webmaster (level-0 role) or a plain admin.
 */
function cpAdmin(bool $webmaster = true, string $name = 'Trần Thị Mai'): User
{
    $user = User::query()->create(['name' => $name, 'email' => uniqid('cp') . '@private.test', 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    if ($webmaster) {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster ' . uniqid(), 'custom' => json_encode(['level' => 0])]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }
    Sanctum::actingAs($user->fresh());

    return $user->fresh();
}

/**
 * Mark module Probe as installed on the site (a release folder with its module.json).
 */
function cpInstall(string $releaseDir): void
{
    mkdir($releaseDir . '/Probe', 0755, true);
    file_put_contents($releaseDir . '/Probe/module.json', json_encode(['name' => 'Probe', 'version' => '2026.3.0']));
}

const CP_BASE = '/api/v2/admin/utilities/modules/catalog';

test('the rating sent to the hub carries only the pseudonymous reference, the name, the stars and the text', function () {
    $user = cpAdmin();
    cpInstall($this->releaseDir);

    $this->putJson(CP_BASE . '/Probe/rating', ['stars' => 4, 'body' => 'Good', 'author_name' => 'Mai'], ['REMOTE_ADDR' => '203.0.113.77'])
        ->assertOk()
        ->assertJsonPath('data.rating.stars', 4);

    $sent = collect($this->hub['requests'])->firstWhere('method', 'PUT');
    expect($sent['path'])->toBe('/api/v2/public/platform/ratings/Probe');
    $payload = json_decode($sent['body'], true);
    expect(array_keys($payload))->toEqualCanonicalizing(['author_ref', 'author_name', 'stars', 'body'])
        ->and($payload['author_ref'])->toBe(hash_hmac('sha256', $user->uuid, $this->secret))
        ->and($payload['author_ref'])->toMatch('/^[a-f0-9]{64}$/');

    // PlatformHubClient::put() signs method, path and body like every other hub call.
    $expected = PlatformSignature::request(
        $this->secret,
        'PUT',
        $sent['path'],
        '',
        (int) $sent['headers'][PlatformSignature::HEADER_TIMESTAMP][0],
        $sent['headers'][PlatformSignature::HEADER_NONCE][0],
        $sent['body'],
    );
    expect($sent['headers'][PlatformSignature::HEADER_SIGNATURE][0])->toBe($expected);

    $wire = $sent['body'] . json_encode($sent['headers']);
    expect($wire)->not->toContain($user->email)
        ->and($wire)->not->toContain('203.0.113.77')
        ->and($wire)->not->toContain($user->uuid)
        ->and($wire)->not->toContain('"user_id"')
        ->and($wire)->not->toContain($this->secret);
});

test('questions and feedback carry no e-mail, user id or IP either', function () {
    $user = cpAdmin();

    $this->postJson(CP_BASE . '/Probe/questions', ['body' => 'Does it work offline?', 'author_name' => 'Mai'], ['REMOTE_ADDR' => '203.0.113.77'])->assertCreated();
    $this->postJson(CP_BASE . '/Probe/feedback', ['kind' => 'feature', 'body' => 'Add export', 'author_name' => 'Mai'], ['REMOTE_ADDR' => '203.0.113.77'])->assertCreated();

    $writes = collect($this->hub['requests'])->where('method', 'POST')->values();
    expect($writes)->toHaveCount(2)
        ->and($writes[0]['path'])->toBe('/api/v2/public/platform/questions/Probe')
        ->and(array_keys(json_decode($writes[0]['body'], true)))->toEqualCanonicalizing(['author_ref', 'author_name', 'body'])
        ->and($writes[1]['path'])->toBe('/api/v2/public/platform/feedback/Probe')
        ->and(array_keys(json_decode($writes[1]['body'], true)))->toEqualCanonicalizing(['author_ref', 'author_name', 'kind', 'body']);
    foreach ($writes as $sent) {
        $wire = $sent['body'] . json_encode($sent['headers']);
        expect($wire)->not->toContain($user->email)
            ->and($wire)->not->toContain('203.0.113.77')
            ->and($wire)->not->toContain($user->uuid);
    }
});

test('a display name with an e-mail or phone number is refused with a plain code and nothing is sent', function () {
    cpAdmin();

    foreach (['lan.nguyen@example.com', 'Lan 0912 345 678', 'Lan +84 912-345-678', 'call (028) 3822 1234'] as $name) {
        $this->postJson(CP_BASE . '/Probe/questions', ['body' => 'Hi', 'author_name' => $name])
            ->assertStatus(422)->assertJsonPath('error_code', 'MODULE_COMMUNITY_NAME_CONTACT');
    }
    expect(collect($this->hub['requests'])->where('method', 'POST')->count())->toBe(0);
});

test('a display name that pretends to be iSPA is refused, whatever the case, accents or spacing', function () {
    cpAdmin();

    foreach (['iSPA', 'ISPA Team', 'i S P A', 'Đội ngũ iSPA', 'đội-ngũ_i.s.p.a', 'iSPA  Hỗ trợ'] as $name) {
        $this->putJson(CP_BASE . '/Probe/rating', ['stars' => 4, 'author_name' => $name])
            ->assertStatus(422)->assertJsonPath('error_code', 'MODULE_COMMUNITY_NAME_ISPA');
    }
    foreach (['Lan', 'Nguyễn Văn Spa', 'Mai 2026'] as $name) {
        $this->postJson(CP_BASE . '/Probe/questions', ['body' => 'Hi', 'author_name' => $name])->assertCreated();
    }
    expect(collect($this->hub['requests'])->where('method', 'POST')->count())->toBe(3);
});

test('the default display name is the given name, never the e-mail', function () {
    cpAdmin(true, 'Trần Thị Mai');

    $this->getJson(CP_BASE . '/Probe/ratings/mine')->assertOk()->assertJsonPath('data.default_name', 'Mai');

    expect(ModuleCommunityClient::defaultName('someone@example.test'))->toBe('Webmaster')
        ->and(ModuleCommunityClient::defaultName(''))->toBe('Webmaster')
        ->and(ModuleCommunityClient::defaultName(null))->toBe('Webmaster');
});

test('the same user gives the same reference, two sites give different ones', function () {
    $user = cpAdmin();

    $this->postJson(CP_BASE . '/Probe/questions', ['body' => 'One'])->assertCreated();
    $this->postJson(CP_BASE . '/Probe/questions', ['body' => 'Two'])->assertCreated();
    config(['ispa-cms.cms_domain' => 'other.example.test', 'ispa-cms.cms_domain_secret' => $other = 'other-secret-' . bin2hex(random_bytes(8))]);
    $GLOBALS['cp_secret'] = $other;
    $this->postJson(CP_BASE . '/Probe/questions', ['body' => 'Three'])->assertCreated();

    $refs = collect($this->hub['requests'])->where('method', 'POST')->map(fn ($r) => json_decode($r['body'], true)['author_ref'])->values();
    expect($refs[0])->toBe($refs[1])
        ->and($refs[2])->not->toBe($refs[0])
        ->and(ModuleCommunityClient::authorRef($user->uuid, 'a'))->not->toBe(ModuleCommunityClient::authorRef($user->uuid, 'b'))
        ->and(ModuleCommunityClient::authorRef($user->uuid, 'a'))->toBe(ModuleCommunityClient::authorRef($user->uuid, 'a'));
});

test('a module that is not installed here cannot be rated and the hub is not asked', function () {
    cpAdmin();

    $this->getJson(CP_BASE . '/Probe/ratings/mine')->assertOk()
        ->assertJsonPath('data.installed', false)
        ->assertJsonPath('data.can_rate', false)
        ->assertJsonPath('data.reason', 'MODULE_COMMUNITY_NOT_INSTALLED');

    $this->putJson(CP_BASE . '/Probe/rating', ['stars' => 5])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'MODULE_COMMUNITY_NOT_INSTALLED');
    expect(collect($this->hub['requests'])->where('method', 'PUT'))->toBeEmpty();
});

test('a stale fleet report: the hub refusal reason reaches the admin', function () {
    cpAdmin();
    cpInstall($this->releaseDir);
    $this->hub['mine'] = [200, ['rating' => null, 'can_rate' => false, 'reason' => 'RATING_INSTALL_REQUIRED', 'installed_version' => null]];

    $this->getJson(CP_BASE . '/Probe/ratings/mine')->assertOk()
        ->assertJsonPath('data.installed', true)
        ->assertJsonPath('data.can_rate', false)
        ->assertJsonPath('data.reason', 'MODULE_COMMUNITY_INSTALL_REQUIRED');

    $this->hub['write'] = [403, ['message' => 'Only a site ...', 'error_code' => 'RATING_INSTALL_REQUIRED', 'status' => 'error']];
    $this->putJson(CP_BASE . '/Probe/rating', ['stars' => 5])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'MODULE_COMMUNITY_INSTALL_REQUIRED');
});

test('hub refusals map to fixed codes, never the hub text', function () {
    cpAdmin();

    $this->hub['write'] = [429, ['message' => 'Too many', 'error_code' => 'PLATFORM_RATE_LIMITED']];
    $this->postJson(CP_BASE . '/Probe/questions', ['body' => 'Hi'])->assertStatus(429)->assertJsonPath('error_code', 'MODULE_COMMUNITY_RATE_LIMITED');

    $this->hub['write'] = [404, ['message' => 'Listing not found.']];
    $this->postJson(CP_BASE . '/Probe/feedback', ['kind' => 'praise', 'body' => 'Nice'])->assertStatus(404)->assertJsonPath('error_code', 'MODULE_COMMUNITY_NOT_FOUND');

    $this->hub['write'] = [500, ['message' => 'boom secret detail']];
    $response = $this->postJson(CP_BASE . '/Probe/questions', ['body' => 'Hi'])->assertStatus(502)->assertJsonPath('error_code', 'MODULE_CATALOG_UNAVAILABLE');
    expect($response->getContent())->not->toContain('boom secret detail');
});

test('ratings and questions are read through the site, validated and without author references', function () {
    cpAdmin();

    $ratings = $this->getJson(CP_BASE . '/Probe/ratings?page=1')->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.average', 4.5)
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.items.0.body', '<b>Great</b>')
        ->assertJsonPath('data.items.0.stars', 5);
    expect($ratings->getContent())->not->toContain('author_ref');

    $this->getJson(CP_BASE . '/Probe/questions?page=1')->assertOk()
        ->assertJsonPath('data.items.0.body', 'Does it work offline?')
        ->assertJsonPath('data.items.0.answers.0.author_kind', 'ispa');
});

test('a hub that predates community answers unavailable, not an error', function () {
    cpAdmin();
    $this->hub['ratings'] = [404, ['message' => 'Not found']];
    $this->hub['questions'] = [404, ['message' => 'Not found']];

    $this->getJson(CP_BASE . '/Probe/ratings')->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.items', []);
    $this->getJson(CP_BASE . '/Probe/questions')->assertOk()->assertJsonPath('data.available', false);
});

test('input is validated on the site before the hub is called', function () {
    cpAdmin();
    cpInstall($this->releaseDir);

    $this->putJson(CP_BASE . '/Probe/rating', ['stars' => 6])->assertStatus(422);
    $this->postJson(CP_BASE . '/Probe/questions', ['body' => ''])->assertStatus(422);
    $this->postJson(CP_BASE . '/Probe/feedback', ['kind' => 'bug', 'body' => 'x'])->assertStatus(422);
    $this->postJson(CP_BASE . '/Probe/questions', ['body' => str_repeat('x', 2001)])->assertStatus(422);
    expect(collect($this->hub['requests'])->where('method', '!=', 'GET'))->toBeEmpty();
});

test('a non-Webmaster gets 403 on every community route', function () {
    cpAdmin(false); // user id 1 is always a Webmaster
    cpAdmin(false);
    cpInstall($this->releaseDir);

    $this->getJson(CP_BASE . '/Probe/ratings/mine')->assertForbidden();
    $this->getJson(CP_BASE . '/Probe/ratings')->assertForbidden();
    $this->getJson(CP_BASE . '/Probe/questions')->assertForbidden();
    $this->putJson(CP_BASE . '/Probe/rating', ['stars' => 5])->assertForbidden();
    $this->postJson(CP_BASE . '/Probe/questions', ['body' => 'Hi'])->assertForbidden();
    $this->postJson(CP_BASE . '/Probe/feedback', ['kind' => 'praise', 'body' => 'Hi'])->assertForbidden();
    expect($this->hub['requests'])->toBeEmpty();
});

test('the catalog summary carries the validated rating aggregate for the cards', function () {
    expect(ModuleListingClient::summary('Probe', ['rating' => ['average' => 4.26, 'count' => 3]])['rating'])->toBe(['average' => 4.3, 'count' => 3])
        ->and(ModuleListingClient::summary('Probe', ['rating' => ['average' => 9, 'count' => 3]])['rating'])->toBe(['average' => null, 'count' => 0])
        ->and(ModuleListingClient::summary('Probe', ['rating' => 'x'])['rating'])->toBe(['average' => null, 'count' => 0])
        ->and(ModuleListingClient::summary('Probe', [])['rating'])->toBe(['average' => null, 'count' => 0]);
});
