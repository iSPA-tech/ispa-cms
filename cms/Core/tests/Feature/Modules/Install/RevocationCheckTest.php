<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\RevocationAttentionProvider;
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
    $this->coreVersion = (new ModuleCatalog())->installedState('Core')['installed_version'];
});

/**
 * Fake the hub revocations endpoint with a correctly signed answer.
 */
function revocationFakeHub(string $secret, array $payload): void
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
function revocationAdmin(string $email, bool $webmaster): User
{
    $user = User::query()->create(['name' => 'Revocation Admin', 'email' => $email, 'password' => Hash::make('password')]);
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

test('a revoked enabled module is stored, logged, flagged in the list and counted for Webmasters only', function () {
    revocationFakeHub($this->secret, ['revoked' => [
        ['name' => 'Core', 'version' => $this->coreVersion, 'reason' => 'Security hole', 'revoked_at' => '2026-11-01T00:00:00+00:00'],
    ]]);
    Log::spy();

    expect(Artisan::call('cms:module-revocation-check'))->toBe(0);

    expect(RevocationAttentionProvider::revoked())->toHaveKey('Core')
        ->and(RevocationAttentionProvider::revoked()['Core']['reason'])->toBe('Security hole');
    Log::shouldHaveReceived('warning')->once();
    Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/api/v2/public/platform/revocations')
        && in_array('Core', array_column($r['installed'], 'name'), true));

    $webmaster = revocationAdmin('rev-webmaster@test.local', true);
    Sanctum::actingAs($webmaster);
    $core = collect($this->getJson('/api/v2/admin/utilities/modules')->assertOk()->json('data'))->firstWhere('name', 'Core');
    expect($core['revoked'])->toBeTrue()->and($core['revoked_reason'])->toBe('Security hole');

    expect(RevocationAttentionProvider::count())->toBe(1);
    $entries = app(\App\Services\Dashboard\AttentionRegistry::class)->forUser($webmaster);
    expect(array_column($entries, 'key'))->toContain(RevocationAttentionProvider::KEY);
    $other = revocationAdmin('rev-other@test.local', false);
    expect(array_column(app(\App\Services\Dashboard\AttentionRegistry::class)->forUser($other), 'key'))
        ->not->toContain(RevocationAttentionProvider::KEY);
});

test('a revoked entry for a different version does not flag the module', function () {
    revocationFakeHub($this->secret, ['revoked' => [
        ['name' => 'Core', 'version' => '0.0.1', 'reason' => 'old', 'revoked_at' => null],
    ]]);

    Artisan::call('cms:module-revocation-check');

    expect(RevocationAttentionProvider::count())->toBe(0);
});

test('a hub failure keeps the previous result, logs one warning and fails', function (string $mode) {
    RevocationAttentionProvider::store(['Core' => ['version' => $this->coreVersion, 'reason' => 'kept', 'revoked_at' => null]]);
    Http::fake(fn () => $mode === 'down' ? throw new ConnectionException('down') : Http::response('nope', 500));
    Log::spy();

    expect(Artisan::call('cms:module-revocation-check'))->not->toBe(0);

    expect(RevocationAttentionProvider::revoked()['Core']['reason'])->toBe('kept');
    Log::shouldHaveReceived('warning')->once();
})->with([
    'unreachable' => ['down'],
    'server error' => ['error'],
]);

test('nothing is disabled when a module is revoked', function () {
    revocationFakeHub($this->secret, ['revoked' => [
        ['name' => 'Core', 'version' => $this->coreVersion, 'reason' => 'x', 'revoked_at' => null],
    ]]);
    $before = collect(Module::allEnabled())->keys()->all();

    Artisan::call('cms:module-revocation-check');

    expect(collect(Module::allEnabled())->keys()->all())->toBe($before)
        ->and(Module::find('Core')->isEnabled())->toBeTrue();
});

test('an unconfigured hub is a quiet success and stores nothing', function () {
    config(['ispa-cms.cms_domain' => '', 'ispa-cms.cms_domain_secret' => '']);
    Log::spy();

    expect(Artisan::call('cms:module-revocation-check'))->toBe(0);

    expect(Cache::has(RevocationAttentionProvider::cacheKey()))->toBeFalse();
    Log::shouldNotHaveReceived('warning');
});
