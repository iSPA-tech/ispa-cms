<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Services\Dashboard\AttentionRegistry;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Modules\Install\EntitlementAttentionProvider;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Nwidart\Modules\Facades\Module;

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    URL::forceScheme('https');
    $this->secret = 'test-secret-'.bin2hex(random_bytes(8));
    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => 'https://hub.test',
    ]);
    $this->tmp = sys_get_temp_dir().'/entitlement-'.bin2hex(random_bytes(6));
    mkdir($this->tmp.'/runtime', 0755, true);
    mkdir($this->tmp.'/release', 0755, true);
    RuntimeModules::setPath($this->tmp.'/runtime');
});

afterEach(function () {
    RuntimeModules::resetPath();
    foreach (glob($this->tmp.'/runtime/*') ?: [] as $dir) {
        @unlink($dir.'/composer.json');
        @rmdir($dir);
    }
    @rmdir($this->tmp.'/runtime');
    @rmdir($this->tmp.'/release');
    @rmdir($this->tmp);
});

/**
 * Fake the hub with a correctly signed answer; the same stub serves the revocation and the catalog call.
 */
function entitlementFakeHub(string $secret, array $payload): void
{
    Http::fake(function (ClientRequest $sent) use ($secret, $payload) {
        $body = json_encode($payload);
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

        return Http::response($body, 200, [
            PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($secret, 200, $nonce, $body),
        ]);
    });
}

/**
 * Create an admin user; Webmaster when a level-0 role is attached.
 */
function entitlementAdmin(string $email, bool $webmaster): User
{
    $user = User::query()->create(['name' => 'Entitlement Admin', 'email' => $email, 'password' => Hash::make('password')]);
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

/**
 * One entitlement row as the hub sends it.
 */
function entitlementRow(string $name, string $state, ?string $expires = null, ?string $grace = null): array
{
    return ['name' => $name, 'state' => $state, 'expires_at' => $expires, 'grace_until' => $grace];
}

test('the daily check stores the hub entitlements beside the revocations', function () {
    entitlementFakeHub($this->secret, ['revoked' => [], 'entitlements' => [
        entitlementRow('Core', 'grace', '2026-11-01T00:00:00+00:00', '2026-11-15T00:00:00+00:00'),
    ]]);

    expect(Artisan::call('cms:module-revocation-check'))->toBe(0);

    expect(EntitlementAttentionProvider::states())->toBe([
        'Core' => ['state' => 'grace', 'expires_at' => '2026-11-01T00:00:00+00:00', 'grace_until' => '2026-11-15T00:00:00+00:00'],
    ]);
});

test('a stored state survives a hub outage', function (string $mode) {
    EntitlementAttentionProvider::store(['Core' => ['state' => 'expired', 'expires_at' => null, 'grace_until' => null]]);
    Http::fake(fn () => $mode === 'down' ? throw new ConnectionException('down') : Http::response('nope', 500));

    expect(Artisan::call('cms:module-revocation-check'))->not->toBe(0);

    expect(EntitlementAttentionProvider::states())->toHaveKey('Core')
        ->and(EntitlementAttentionProvider::states()['Core']['state'])->toBe('expired');
})->with(['unreachable' => ['down'], 'server error' => ['error']]);

test('an older hub answer without the key leaves the stored state and the page unchanged', function () {
    EntitlementAttentionProvider::store(['Core' => ['state' => 'revoked', 'expires_at' => null, 'grace_until' => null]]);
    entitlementFakeHub($this->secret, ['revoked' => []]);

    Artisan::call('cms:module-revocation-check');

    expect(EntitlementAttentionProvider::states()['Core']['state'])->toBe('revoked');

    Cache::flush();
    entitlementFakeHub($this->secret, ['revoked' => []]);
    Artisan::call('cms:module-revocation-check');
    Sanctum::actingAs(entitlementAdmin('ent-older@test.local', true));
    $core = collect($this->getJson('/api/v2/admin/utilities/modules')->assertOk()->json('data'))->firstWhere('name', 'Core');
    expect($core['entitlement'])->toBeNull()->and($core['updates_unavailable'])->toBeFalse();
});

test('an explicit empty list clears the stored states', function () {
    EntitlementAttentionProvider::store(['Core' => ['state' => 'grace', 'expires_at' => null, 'grace_until' => null]]);
    entitlementFakeHub($this->secret, ['revoked' => [], 'entitlements' => []]);

    Artisan::call('cms:module-revocation-check');

    expect(EntitlementAttentionProvider::states())->toBe([]);
});

test('a malformed row is skipped and logged while good rows are kept', function () {
    entitlementFakeHub($this->secret, ['revoked' => [], 'entitlements' => [
        'not-an-array',
        ['state' => 'grace'],
        entitlementRow('lowercase', 'grace'),
        entitlementRow('Core', 'bogus-state'),
        entitlementRow('Core', 'grace', 'yesterday-ish'),
        ['name' => 'Core', 'state' => 'grace', 'expires_at' => 5, 'grace_until' => null],
        entitlementRow('Core', 'expired', '2026-11-01T00:00:00+00:00', '2026-11-15T00:00:00+00:00'),
    ]]);
    Log::spy();

    expect(Artisan::call('cms:module-revocation-check'))->toBe(0);

    expect(array_keys(EntitlementAttentionProvider::states()))->toBe(['Core'])
        ->and(EntitlementAttentionProvider::states()['Core']['state'])->toBe('expired');
    Log::shouldHaveReceived('warning')->times(6);
});

test('a non-list entitlements value keeps the previous result and is logged', function () {
    EntitlementAttentionProvider::store(['Core' => ['state' => 'grace', 'expires_at' => null, 'grace_until' => null]]);
    entitlementFakeHub($this->secret, ['revoked' => [], 'entitlements' => 'oops']);
    Log::spy();

    expect(Artisan::call('cms:module-revocation-check'))->toBe(0);

    expect(EntitlementAttentionProvider::states()['Core']['state'])->toBe('grace');
    Log::shouldHaveReceived('warning')->once();
});

test('an expired module stays enabled, keeps serving its routes and is listed with its state', function () {
    entitlementFakeHub($this->secret, ['revoked' => [], 'entitlements' => [
        entitlementRow('Core', 'expired', '2026-10-01T00:00:00+00:00', '2026-10-15T00:00:00+00:00'),
    ]]);
    $before = collect(Module::allEnabled())->keys()->all();

    Artisan::call('cms:module-revocation-check');

    expect(collect(Module::allEnabled())->keys()->all())->toBe($before)
        ->and(Module::find('Core')->isEnabled())->toBeTrue();
    Sanctum::actingAs(entitlementAdmin('ent-list@test.local', true));
    $core = collect($this->getJson('/api/v2/admin/utilities/modules')->assertOk()->json('data'))->firstWhere('name', 'Core');
    expect($core['enabled'])->toBeTrue()
        ->and($core['entitlement'])->toBe(['state' => 'expired', 'expires_at' => '2026-10-01T00:00:00+00:00', 'grace_until' => '2026-10-15T00:00:00+00:00'])
        ->and($core['updates_unavailable'])->toBeTrue();
});

test('active and grace modules keep their update offer, expired and revoked ones get none', function (string $state, bool $offered) {
    mkdir($this->tmp.'/runtime/Alpha');
    file_put_contents($this->tmp.'/runtime/Alpha/composer.json', json_encode(['version' => '2026.1.0']));
    EntitlementAttentionProvider::store(['Alpha' => ['state' => $state, 'expires_at' => null, 'grace_until' => null]]);
    entitlementFakeHub($this->secret, ['modules' => [
        ['name' => 'Alpha', 'latest_version' => '2026.2.0', 'versions' => [
            ['version' => '2026.2.0', 'revoked' => false, 'requires' => [], 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.1.0'],
        ]],
    ]]);

    $alpha = collect((new ModuleCatalog(null, $this->tmp.'/release'))->entries())->firstWhere('name', 'Alpha');

    expect($alpha['upgrade_available'])->toBe($offered)
        ->and($alpha['upgrade_version'])->toBe($offered ? '2026.2.0' : null)
        ->and($alpha['updates_unavailable'])->toBe(! $offered);
})->with([
    'entitled' => ['entitled', true],
    'grace' => ['grace', true],
    'expired' => ['expired', false],
    'revoked' => ['revoked', false],
]);

test('an installed runtime module missing from the hub catalog stays listed as updates unavailable', function () {
    mkdir($this->tmp.'/runtime/Ghost');
    file_put_contents($this->tmp.'/runtime/Ghost/composer.json', json_encode(['version' => '2026.1.0']));
    entitlementFakeHub($this->secret, ['modules' => []]);

    $ghost = collect((new ModuleCatalog(null, $this->tmp.'/release'))->entries())->firstWhere('name', 'Ghost');

    expect($ghost)->not->toBeNull()
        ->and($ghost['installed'])->toBe('runtime')
        ->and($ghost['installed_version'])->toBe('2026.1.0')
        ->and($ghost['updates_unavailable'])->toBeTrue()
        ->and($ghost['upgrade_available'])->toBeFalse()
        ->and($ghost['versions'])->toBe([]);
});

test('the attention item counts grace, expired and revoked only, for Webmasters only', function () {
    $webmaster = entitlementAdmin('ent-webmaster@test.local', true);
    $other = entitlementAdmin('ent-other@test.local', false);
    $keys = fn ($user) => array_column(app(AttentionRegistry::class)->forUser($user), 'key');

    EntitlementAttentionProvider::store(['Core' => ['state' => 'entitled', 'expires_at' => null, 'grace_until' => null]]);
    expect(EntitlementAttentionProvider::count())->toBe(0)
        ->and($keys($webmaster))->not->toContain(EntitlementAttentionProvider::KEY);

    foreach (['grace', 'expired', 'revoked'] as $state) {
        EntitlementAttentionProvider::store(['Core' => ['state' => $state, 'expires_at' => null, 'grace_until' => null]]);
        expect(EntitlementAttentionProvider::count())->toBe(1)
            ->and($keys($webmaster))->toContain(EntitlementAttentionProvider::KEY)
            ->and($keys($other))->not->toContain(EntitlementAttentionProvider::KEY);
    }

    // A stored name that is not installed on this site is not counted.
    EntitlementAttentionProvider::store(['NotInstalledHere' => ['state' => 'expired', 'expires_at' => null, 'grace_until' => null]]);
    expect(EntitlementAttentionProvider::count())->toBe(0);
});

test('with no stored result there is no attention item and no entitlement on the rows', function () {
    expect(EntitlementAttentionProvider::states())->toBe([])
        ->and(EntitlementAttentionProvider::count())->toBe(0);
});
