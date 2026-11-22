<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Platform\PlatformHubException;
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    $GLOBALS['catalog_faked'] = false;
    URL::forceScheme('https');

    $this->tmp = sys_get_temp_dir().'/catalog-'.bin2hex(random_bytes(6));
    $this->releaseDir = $this->tmp.'/release';
    $this->runtimeDir = $this->tmp.'/runtime';
    mkdir($this->releaseDir, 0755, true);
    mkdir($this->runtimeDir, 0755, true);
    RuntimeModules::setPath($this->runtimeDir);

    $this->secret = 'test-secret-'.bin2hex(random_bytes(8));
    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => 'https://hub.test',
    ]);

    $this->payload = ['modules' => [
        ['name' => 'Alpha', 'latest_version' => '2026.2.0', 'versions' => [
            ['version' => '2026.2.0', 'revoked' => false, 'requires' => ['Core' => '>=2026.03.0'], 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.1.0'],
            ['version' => '2026.1.0', 'revoked' => true, 'revoked_reason' => 'bad', 'requires' => [], 'irreversible' => true, 'custom_code' => true, 'php_min' => '8.1.0'],
        ]],
        ['name' => 'Core', 'latest_version' => '2026.3.0', 'versions' => [['version' => '2026.3.0']]],
    ]];
});

afterEach(function () {
    RuntimeModules::resetPath();
    catalogRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function catalogRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            catalogRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Fake the hub: answers the payload signed with $signWith (null = no signature header at all).
 */
function catalogFakeHub(array $payload, ?string $signWith, int $status = 200): void
{
    // Http::fake() stubs accumulate and the first match wins, so register once and swap the behaviour.
    $GLOBALS['catalog_hub'] = [$payload, $signWith, $status];
    if (empty($GLOBALS['catalog_faked'])) {
        $GLOBALS['catalog_faked'] = true;
        Http::fake(function (ClientRequest $sent) {
            [$payload, $signWith, $status] = $GLOBALS['catalog_hub'];
            $body = json_encode($payload);
            $headers = [];
            if ($signWith !== null) {
                $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];
                $headers[PlatformSignature::HEADER_RESPONSE_SIGNATURE] = PlatformSignature::response($signWith, $status, $nonce, $body);
            }

            return Http::response($body, $status, $headers);
        });
    }
}

test('entries carry installed state: release, runtime and none', function () {
    mkdir($this->releaseDir.'/Core');
    file_put_contents($this->releaseDir.'/Core/module.json', json_encode(['name' => 'Core', 'version' => '2026.3.0']));
    file_put_contents($this->releaseDir.'/Core/composer.json', json_encode(['version' => '1.0.0']));
    mkdir($this->runtimeDir.'/Alpha');
    file_put_contents($this->runtimeDir.'/Alpha/composer.json', json_encode(['version' => '2026.1.0']));
    catalogFakeHub($this->payload, $this->secret);

    $entries = collect((new ModuleCatalog(null, $this->releaseDir))->entries())->keyBy('name');

    // module.json wins over composer.json; a runtime module falls back to composer.json.
    expect($entries['Core'])->toMatchArray(['installed' => 'release', 'installed_version' => '2026.3.0'])
        ->and($entries['Alpha'])->toMatchArray(['installed' => 'runtime', 'installed_version' => '2026.1.0', 'enabled' => false])
        ->and($entries['Alpha']['versions'])->toHaveCount(2)
        ->and($entries['Alpha']['versions'][1])->toMatchArray(['revoked' => true, 'revoked_reason' => 'bad']);

    $none = collect((new ModuleCatalog(null, $this->tmp.'/nothing'))->entries())->firstWhere('name', 'Core');
    expect($none)->toMatchArray(['installed' => 'none', 'enabled' => false, 'installed_version' => null]);
});

test('the real Core module reads as enabled and versioned from module.json', function () {
    catalogFakeHub($this->payload, $this->secret);

    $core = collect((new ModuleCatalog())->entries())->firstWhere('name', 'Core');

    expect($core['installed'])->toBe('release')->and($core['enabled'])->toBeTrue()->and($core['installed_version'])->toMatch('/^\d+\.\d+\.\d+$/');
});

test('the hub answer is cached for ten minutes', function () {
    catalogFakeHub($this->payload, $this->secret);
    $catalog = new ModuleCatalog(null, $this->releaseDir);

    $catalog->entries();
    $catalog->entries();
    (new ModuleCatalog(null, $this->releaseDir))->entries();

    Http::assertSentCount(1);

    $catalog->forget();
    $catalog->entries();
    Http::assertSentCount(2);
});

test('an unsigned or wrongly signed hub answer is an error and is not cached', function (?string $signWith, string $reason) {
    catalogFakeHub($this->payload, $signWith === 'wrong' ? 'another-secret-entirely' : null);
    $catalog = new ModuleCatalog(null, $this->releaseDir);

    try {
        $catalog->entries();
        $this->fail('Expected PlatformHubException.');
    } catch (PlatformHubException $e) {
        expect($e->reason())->toBe($reason);
    }

    // Nothing cached: the next call asks the hub again and a good answer now works.
    catalogFakeHub($this->payload, $this->secret);
    expect($catalog->entries())->toHaveCount(2);
})->with([
    'unsigned' => [null, PlatformHubException::BAD_SIGNATURE],
    'wrong secret' => ['wrong', PlatformHubException::BAD_SIGNATURE],
]);

test('a hub failure or an odd payload is an error, never an empty catalog', function () {
    catalogFakeHub([], $this->secret, 500);
    expect(fn () => (new ModuleCatalog(null, $this->releaseDir))->entries())->toThrow(PlatformHubException::class);

    catalogFakeHub(['unexpected' => true], $this->secret);
    expect(fn () => (new ModuleCatalog(null, $this->releaseDir))->entries())->toThrow(PlatformHubException::class);
});

/**
 * Create an admin user; Webmaster when a level-0 role is attached.
 */
function catalogAdmin(string $email, bool $webmaster): User
{
    $user = User::query()->create(['name' => 'Catalog Admin', 'email' => $email, 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    if ($webmaster) {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId([
            'name' => 'Webmaster '.uniqid(),
            'custom' => json_encode(['level' => 0]),
        ]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }

    return $user->fresh();
}

test('catalog and preflight endpoints answer a Webmaster', function () {
    catalogFakeHub($this->payload, $this->secret);
    Sanctum::actingAs(catalogAdmin('catalog-webmaster@test.local', true));

    $this->getJson('/api/v2/admin/utilities/modules/catalog')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Alpha')
        ->assertJsonPath('data.0.installed', 'none');

    $response = $this->getJson('/api/v2/admin/utilities/modules/catalog/Alpha/2026.1.0/preflight')->assertOk();
    $codes = array_column($response->json('data.blocking'), 'code');
    expect($codes)->toContain('MODULE_REVOKED')
        ->and($response->json('data.can_install'))->toBeFalse()
        ->and(array_column($response->json('data.warnings'), 'code'))->toContain('MODULE_WARN_IRREVERSIBLE_MIGRATIONS', 'MODULE_WARN_CUSTOM_CODE');
});

test('an admin who is not a Webmaster gets 403 on both endpoints', function () {
    catalogFakeHub($this->payload, $this->secret);
    catalogAdmin('catalog-filler@test.local', false); // user id 1 is always a Webmaster
    Sanctum::actingAs(catalogAdmin('catalog-editor@test.local', false));

    $this->getJson('/api/v2/admin/utilities/modules/catalog')->assertForbidden();
    $this->getJson('/api/v2/admin/utilities/modules/catalog/Alpha/2026.1.0/preflight')->assertForbidden();
    Http::assertNothingSent();
});

test('an untrusted hub answer returns 502 with an error code, not an empty list', function () {
    catalogFakeHub($this->payload, null);
    Sanctum::actingAs(catalogAdmin('catalog-webmaster2@test.local', true));

    $this->getJson('/api/v2/admin/utilities/modules/catalog')
        ->assertStatus(502)
        ->assertJsonPath('error_code', 'MODULE_CATALOG_UNAVAILABLE')
        ->assertJsonMissingPath('data');
    $this->getJson('/api/v2/admin/utilities/modules/catalog/Alpha/2026.1.0/preflight')
        ->assertStatus(502);
});

test('a hub that refuses this site with 401 answers MODULE_HUB_NOT_RECOGNISED, an outage keeps MODULE_CATALOG_UNAVAILABLE', function () {
    Sanctum::actingAs(catalogAdmin('catalog-webmaster3@test.local', true));

    catalogFakeHub(['message' => 'Platform request is not authorized.', 'error_code' => 'PLATFORM_UNAUTHORIZED', 'status' => 'error'], null, 401);
    $this->getJson('/api/v2/admin/utilities/modules/catalog')
        ->assertStatus(502)
        ->assertJsonPath('error_code', 'MODULE_HUB_NOT_RECOGNISED')
        ->assertJsonMissingPath('data');

    catalogFakeHub(['message' => 'down'], null, 503);
    $this->getJson('/api/v2/admin/utilities/modules/catalog')
        ->assertStatus(502)
        ->assertJsonPath('error_code', 'MODULE_CATALOG_UNAVAILABLE');
});

test('name and version segments are constrained', function () {
    catalogFakeHub($this->payload, $this->secret);
    Sanctum::actingAs(catalogAdmin('catalog-webmaster3@test.local', true));

    $this->getJson('/api/v2/admin/utilities/modules/catalog/alpha/2026.1.0/preflight')->assertNotFound();
    $this->getJson('/api/v2/admin/utilities/modules/catalog/Alpha/latest/preflight')->assertNotFound();
});

test('the renewal note of the hub is read, odd notes are dropped, and an older hub gives none', function () {
    $payload = $this->payload;
    $payload['modules'][0]['renewal'] = ['reason' => 'updates_ended', 'date' => '2027-01-31T16:59:59+00:00', 'version' => '2027.1.0'];
    $payload['modules'][1]['renewal'] = ['reason' => 'kill-switch', 'date' => '2027-01-31T16:59:59+00:00'];
    $payload['modules'][] = ['name' => 'Beta', 'latest_version' => null, 'versions' => [], 'renewal' => ['reason' => 'expired', 'date' => 'not a date']];
    $payload['modules'][] = ['name' => 'Gamma', 'latest_version' => null, 'versions' => [], 'renewal' => ['reason' => 'grace', 'date' => '2026-12-01T00:00:00+00:00', 'version' => '../x']];
    catalogFakeHub($payload, $this->secret);

    $entries = collect((new ModuleCatalog(null, $this->releaseDir))->entries())->keyBy('name');

    expect($entries['Alpha']['renewal'])->toBe(['reason' => 'updates_ended', 'date' => '2027-01-31T16:59:59+00:00', 'version' => '2027.1.0'])
        ->and($entries['Core']['renewal'])->toBeNull()
        ->and($entries['Beta']['renewal'])->toBeNull()
        ->and($entries['Gamma']['renewal'])->toBe(['reason' => 'grace', 'date' => '2026-12-01T00:00:00+00:00', 'version' => null]);

    (new ModuleCatalog(null, $this->releaseDir))->forget();
    catalogFakeHub($this->payload, $this->secret);
    expect(collect((new ModuleCatalog(null, $this->releaseDir))->entries())->firstWhere('name', 'Alpha')['renewal'])->toBeNull();
});

test('an expired module keeps its installed state and gets no update offer; the site never disables it', function () {
    mkdir($this->runtimeDir.'/Alpha');
    file_put_contents($this->runtimeDir.'/Alpha/composer.json', json_encode(['version' => '2026.1.0']));
    $payload = ['modules' => [
        ['name' => 'Alpha', 'latest_version' => null, 'versions' => [], 'renewal' => ['reason' => 'expired', 'date' => '2026-11-27T03:00:00+00:00', 'version' => null]],
    ]];
    catalogFakeHub($payload, $this->secret);

    $alpha = collect((new ModuleCatalog(null, $this->releaseDir))->entries())->firstWhere('name', 'Alpha');

    expect($alpha)->toMatchArray([
        'installed' => 'runtime',
        'installed_version' => '2026.1.0',
        'upgrade_available' => false,
        'upgrade_version' => null,
        'updates_unavailable' => true,
    ])->and($alpha['renewal']['reason'])->toBe('expired')
        ->and(is_dir($this->runtimeDir.'/Alpha'))->toBeTrue();
});
