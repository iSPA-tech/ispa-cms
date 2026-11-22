<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleListingClient;
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
    URL::forceScheme('https');

    $this->tmp = sys_get_temp_dir() . '/listing-proxy-' . bin2hex(random_bytes(6));
    $this->releaseDir = $this->tmp . '/release';
    $this->runtimeDir = $this->tmp . '/runtime';
    $this->mediaDir = $this->tmp . '/media';
    mkdir($this->releaseDir, 0755, true);
    mkdir($this->runtimeDir, 0755, true);
    RuntimeModules::setPath($this->runtimeDir);

    $this->secret = 'test-secret-' . bin2hex(random_bytes(8));
    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => 'https://hub.test',
    ]);

    $this->token = 'Probe.' . str_repeat('ab', 24);
    $this->png = "\x89PNG\r\n\x1a\n" . str_repeat('x', 40);
    $this->hub = [
        'catalog' => ['modules' => [['name' => 'Probe', 'latest_version' => '2026.3.0', 'versions' => [['version' => '2026.3.0']]]]],
        'listing' => lpListingPayload($this->token),
        'media' => [200, $this->png],
        'listing_status' => 200,
        'requests' => [],
    ];
    $GLOBALS['lp_hub'] = &$this->hub;
    Http::fake(function (ClientRequest $sent) {
        $hub = &$GLOBALS['lp_hub'];
        $path = (string) parse_url($sent->url(), PHP_URL_PATH);
        $hub['requests'][] = $path;
        if (str_contains($path, '/listing-media/')) {
            [$status, $body] = $hub['media'];
        } elseif (str_contains($path, '/listings/')) {
            $status = $hub['listing_status'];
            $body = json_encode($hub['listing']);
        } else {
            $status = 200;
            $body = json_encode($hub['catalog']);
        }
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

        return Http::response($body, $status, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($GLOBALS['lp_secret'], $status, $nonce, $body)]);
    });
    $GLOBALS['lp_secret'] = $this->secret;

    $this->app->instance(ModuleCatalog::class, new ModuleCatalog(null, $this->releaseDir));
    $this->app->instance(ModuleListingClient::class, new ModuleListingClient(null, $this->mediaDir));
});

afterEach(function () {
    RuntimeModules::resetPath();
    lpRemoveTree($this->tmp);
});

/**
 * Remove a temp tree.
 */
function lpRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            lpRemoveTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

/**
 * A hub listing answer for module Probe with one screenshot token.
 *
 * @return array<string, mixed>
 */
function lpListingPayload(string $token): array
{
    $content = fn (string $name) => ['display_name' => $name, 'summary' => 'Short', 'price_note' => 'Free', 'description' => 'Long', 'features' => ['One', 'Two'], 'faq' => [['q' => 'Why?', 'a' => 'Because.']]];

    return [
        'name' => 'Probe',
        'listing' => [
            'category' => 'content',
            'publisher' => ['key' => 'ispa', 'type' => 'ispa', 'name' => 'iSPA'],
            'icon_token' => $token,
            'content' => ['vi' => $content('Thăm dò'), 'en' => $content('Probe')],
            'revision' => 3,
            'published_at' => '2026-11-10T00:00:00+00:00',
            'screenshots' => [['token' => $token, 'kind' => 'screenshot', 'caption_vi' => 'Ảnh', 'caption_en' => 'Shot']],
            'video_url' => 'javascript:alert(1)',
            'demo_url' => 'https://demo.example.test/probe',
            'support_contact' => 'help@example.test',
        ],
        'installs' => 12,
        'last_updated_at' => '2026-11-10T00:00:00+00:00',
        'versions' => [
            ['version' => '2026.3.0', 'revoked' => false, 'published_at' => '2026-11-10T00:00:00+00:00', 'sheet' => null, 'release_notes' => 'Three'],
            ['version' => '2026.2.0', 'revoked' => false, 'published_at' => '2026-10-10T00:00:00+00:00', 'sheet' => null, 'release_notes' => 'Two'],
            ['version' => '2026.1.0', 'revoked' => false, 'published_at' => '2026-09-10T00:00:00+00:00', 'sheet' => null, 'release_notes' => 'One'],
        ],
    ];
}

/**
 * Sign in as a Webmaster (level-0 role) or a plain admin.
 */
function lpAdmin(bool $webmaster = true): User
{
    $user = User::query()->create(['name' => 'Listing Admin', 'email' => uniqid('lp') . '@test.local', 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    if ($webmaster) {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster ' . uniqid(), 'custom' => json_encode(['level' => 0])]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }
    Sanctum::actingAs($user->fresh());

    return $user;
}

const LP_BASE = '/api/v2/admin/utilities/modules/catalog';

test('the listing is validated: capped text, https links only, tokens replaced by local hashes', function () {
    lpAdmin();
    $hash = hash('sha256', $this->token);
    $this->hub['listing']['listing']['content']['en']['display_name'] = str_repeat('x', 500);

    $response = $this->getJson(LP_BASE . '/Probe/listing')->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.listing.publisher.type', 'ispa')
        ->assertJsonPath('data.listing.content.vi.display_name', 'Thăm dò')
        ->assertJsonPath('data.listing.content.en.display_name', '')
        ->assertJsonPath('data.listing.video_url', null)
        ->assertJsonPath('data.listing.demo_url', 'https://demo.example.test/probe')
        ->assertJsonPath('data.listing.icon_hash', $hash)
        ->assertJsonPath('data.listing.screenshots.0.hash', $hash)
        ->assertJsonPath('data.installs', 12);

    expect($response->getContent())->not->toContain($this->token);
});

test('an unknown publisher type drops the publisher', function () {
    lpAdmin();
    $this->hub['listing']['listing']['publisher']['type'] = 'evil';

    $this->getJson(LP_BASE . '/Probe/listing')->assertOk()->assertJsonPath('data.listing.publisher', null);
});

test('release notes list only versions above the installed one', function () {
    lpAdmin();
    mkdir($this->releaseDir . '/Probe');
    file_put_contents($this->releaseDir . '/Probe/module.json', json_encode(['name' => 'Probe', 'version' => '2026.1.0']));

    $response = $this->getJson(LP_BASE . '/Probe/listing')->assertOk()->assertJsonPath('data.installed_version', '2026.1.0');

    expect(array_column($response->json('data.release_notes'), 'version'))->toBe(['2026.3.0', '2026.2.0']);

    // A module that is not installed gets the newest version only.
    lpRemoveTree($this->releaseDir . '/Probe');
    $none = $this->getJson(LP_BASE . '/Probe/listing')->assertOk();
    expect(array_column($none->json('data.release_notes'), 'version'))->toBe(['2026.3.0']);
});

test('a revoked version carries no release notes offer', function () {
    lpAdmin();
    $this->hub['listing']['versions'][0]['revoked'] = true;

    $response = $this->getJson(LP_BASE . '/Probe/listing')->assertOk();

    expect(array_column($response->json('data.release_notes'), 'version'))->toBe(['2026.2.0']);
});

test('a hub error is an error answer, never a made-up listing', function () {
    lpAdmin();
    $this->hub['listing_status'] = 500;

    $this->getJson(LP_BASE . '/Probe/listing')->assertStatus(502)->assertJsonPath('error_code', 'MODULE_CATALOG_UNAVAILABLE');

    // The failure is not cached: the next call asks the hub again and recovers.
    $this->hub['listing_status'] = 200;
    $this->getJson(LP_BASE . '/Probe/listing')->assertOk()->assertJsonPath('data.available', true);
});

test('a hub that predates listings still gives a working store: no listing key, listing endpoint 404', function () {
    lpAdmin();
    $this->hub['listing_status'] = 404;

    $this->getJson(LP_BASE . '/Probe/listing')->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.listing', null)
        ->assertJsonPath('data.versions', [])
        ->assertJsonPath('data.release_notes', []);

    // The catalog without a `listing` key still validates and gives listing = null.
    $this->getJson(LP_BASE)->assertOk()
        ->assertJsonPath('data.0.name', 'Probe')
        ->assertJsonPath('data.0.listing', null);
});

test('the catalog carries the validated listing summary with a local icon hash', function () {
    lpAdmin();
    $this->hub['catalog']['modules'][0]['listing'] = [
        'category' => 'content',
        'publisher' => ['key' => 'ispa', 'type' => 'ispa', 'name' => 'iSPA'],
        'icon_token' => $this->token,
        'content' => ['vi' => ['display_name' => 'Thăm dò', 'summary' => 'Ngắn'], 'en' => ['display_name' => 'Probe', 'summary' => 'Short']],
        'secret_extra' => 'ignored',
    ];

    $response = $this->getJson(LP_BASE)->assertOk()
        ->assertJsonPath('data.0.listing.publisher.name', 'iSPA')
        ->assertJsonPath('data.0.listing.icon_hash', hash('sha256', $this->token))
        ->assertJsonPath('data.0.listing.content.en.summary', 'Short')
        ->assertJsonMissingPath('data.0.listing.secret_extra')
        // A summary from a hub that predates has no installs and no price note.
        ->assertJsonPath('data.0.listing.installs', null)
        ->assertJsonPath('data.0.listing.content.vi.price_note', '')
        ->assertJsonPath('data.0.listing.content.en.price_note', '');
    expect($response->getContent())->not->toContain($this->token);
});

test('the catalog summary keeps the install count and the price note for the cards', function () {
    lpAdmin();
    $this->hub['catalog']['modules'][0]['listing'] = [
        'category' => 'content',
        'publisher' => ['key' => 'ispa', 'type' => 'ispa', 'name' => 'iSPA'],
        'content' => ['vi' => ['display_name' => 'Thăm dò', 'summary' => 'Ngắn', 'price_note' => 'Miễn phí'], 'en' => ['display_name' => 'Probe', 'summary' => 'Short', 'price_note' => 'Free']],
        'installs' => 7,
    ];
    $this->getJson(LP_BASE)->assertOk()
        ->assertJsonPath('data.0.listing.installs', 7)
        ->assertJsonPath('data.0.listing.content.vi.price_note', 'Miễn phí')
        ->assertJsonPath('data.0.listing.content.en.price_note', 'Free');

    // A negative or non-integer count and an over-long price note are dropped, never shown.
    Cache::flush();
    $this->hub['catalog']['modules'][0]['listing']['content']['en']['price_note'] = str_repeat('p', 201);
    $this->getJson(LP_BASE)->assertOk()->assertJsonPath('data.0.listing.content.en.price_note', '');
    Cache::flush();
    $this->hub['catalog']['modules'][0]['listing']['installs'] = -3;
    $this->getJson(LP_BASE)->assertOk()->assertJsonPath('data.0.listing.installs', null);
    Cache::flush();
    $this->hub['catalog']['modules'][0]['listing']['installs'] = '12';
    $this->getJson(LP_BASE)->assertOk()->assertJsonPath('data.0.listing.installs', null);

    // The full listing keeps its install count at the top level only.
    $this->getJson(LP_BASE . '/Probe/listing')->assertOk()
        ->assertJsonPath('data.installs', 12)
        ->assertJsonMissingPath('data.listing.installs')
        ->assertJsonPath('data.listing.content.en.price_note', 'Free')
        ->assertJsonMissingPath('data.listing.price_note');

    // A category outside the shared list is dropped (an older hub or a bad draft never reaches the cards).
    Cache::flush();
    $this->hub['catalog']['modules'][0]['listing'] = ['category' => 'catalog', 'content' => ['vi' => ['display_name' => 'x', 'summary' => 'y'], 'en' => ['display_name' => 'x', 'summary' => 'y']]];
    $this->getJson(LP_BASE)->assertOk()->assertJsonPath('data.0.listing.category', null);
});

test('media is fetched once, stored by hash and served with the type of its bytes', function () {
    lpAdmin();
    $hash = hash('sha256', $this->token);
    $this->getJson(LP_BASE . '/Probe/listing')->assertOk();

    $first = $this->get(LP_BASE . '/media/' . $hash)->assertOk();
    expect($first->headers->get('Content-Type'))->toBe('image/png')
        ->and($first->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(file_get_contents($this->mediaDir . '/' . $hash))->toBe($this->png);

    $this->get(LP_BASE . '/media/' . $hash)->assertOk();
    $mediaCalls = array_filter($this->hub['requests'], fn ($p) => str_contains($p, '/listing-media/'));
    expect($mediaCalls)->toHaveCount(1);
});

test('a media hash that no fetched listing produced is 404 and never reaches the hub', function () {
    lpAdmin();
    $this->getJson(LP_BASE . '/Probe/listing')->assertOk();
    $before = count($this->hub['requests']);

    $this->getJson(LP_BASE . '/media/' . hash('sha256', 'Other.' . str_repeat('cd', 24)))->assertStatus(404);
    expect(count($this->hub['requests']))->toBe($before);
});

test('bytes that are not an image are refused and not stored', function () {
    lpAdmin();
    $this->hub['media'] = [200, '<html>nope</html>'];
    $hash = hash('sha256', $this->token);
    $this->getJson(LP_BASE . '/Probe/listing')->assertOk();

    $this->getJson(LP_BASE . '/media/' . $hash)->assertStatus(404);
    expect(is_file($this->mediaDir . '/' . $hash))->toBeFalse();
});

test('a hub 404 for an image is a 404 for the site', function () {
    lpAdmin();
    $this->hub['media'] = [404, '{}'];
    $this->getJson(LP_BASE . '/Probe/listing')->assertOk();

    $this->getJson(LP_BASE . '/media/' . hash('sha256', $this->token))->assertStatus(404);
});

test('the listing and media routes are Webmaster only', function () {
    lpAdmin(false); // user id 1 is always a Webmaster
    lpAdmin(false);

    $this->getJson(LP_BASE . '/Probe/listing')->assertForbidden();
    $this->getJson(LP_BASE . '/media/' . str_repeat('a', 64))->assertForbidden();
});
