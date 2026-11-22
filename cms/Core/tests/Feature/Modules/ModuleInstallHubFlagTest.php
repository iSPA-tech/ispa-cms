<?php

declare(strict_types=1);

/**
 * Hub forbid flag for module install (spec 2026-11-22, plan Task 2): the site reads `module_install` from its backend
 * hub verification (SiteStatusService), keeps the last known value with the 15 minute cache and the 7 day grace, and
 * ModuleInstallPolicy turns `forbidden` into MODULE_INSTALL_FORBIDDEN_BY_HUB. No known value lets the admin switch
 * decide.
 */

use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleInstallPolicy;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Platform\SiteStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const MHF_HUB = 'https://hub.example.test';
const MHF_STATUS_URL = MHF_HUB . '/api/platform/v1/site/status';
const MHF_VERIFY_URL = MHF_HUB . '/api/v2/public/verify';

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-22 10:00:00');
    $this->hubRoutes = [];
    Http::fake(function (ClientRequest $request) {
        $answer = $this->hubRoutes[$request->url()] ?? null;

        return $answer instanceof Closure ? $answer($request) : $answer;
    });

    $this->secret = 'site-secret-' . bin2hex(random_bytes(8));
    config([
        'ispa-cms.cms_domain' => 'site.example.org',
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => MHF_HUB,
        'core.module_packages.install_enabled' => null,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Answer the signed status endpoint with a payload signed by the site secret. */
function mhfStatus(object $test, array $payload): void
{
    $test->hubRoutes = [
        MHF_STATUS_URL => function (ClientRequest $request) use ($test, $payload) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $nonce = $request->header(PlatformSignature::HEADER_NONCE)[0] ?? '';

            return Http::response($body, 200, [
                PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($test->secret, 200, $nonce, $body),
            ]);
        },
    ];
}

/** Store the Webmaster's switch without preparing the runtime folder (only the setting matters here). */
function mhfSwitch(bool $on): void
{
    $policy = app(ModuleInstallPolicy::class);
    (fn () => $this->store($on))->call($policy);
}

/** Site-level blocking codes from the preflight. */
function mhfSiteCodes(): array
{
    $preflight = new InstallPreflight(new ModuleCatalog(null, sys_get_temp_dir()), '8.3.0', fn (): bool => true, fn (): array => [], fn (): array => [], fn (): array => []);
    $blocking = [];
    $add = static function (array &$list, string $code, string $message, array $details = []): void {
        $list[] = ['code' => $code, 'message' => $message, 'details' => $details];
    };
    (function () use ($add, &$blocking): void {
        $this->checkSite($add, $blocking);
    })->call($preflight);

    return array_column($blocking, 'code');
}

test('the hub forbids: blocked with MODULE_INSTALL_FORBIDDEN_BY_HUB even with the switch on, and the switch is locked', function (): void {
    mhfSwitch(true);
    mhfStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => 'tok', 'module_install' => 'forbidden']);

    $policy = app(ModuleInstallPolicy::class);
    expect(app(SiteStatusService::class)->moduleInstall())->toBe('forbidden')
        ->and($policy->state())->toBe(ModuleInstallPolicy::STATE_FORBIDDEN_BY_HUB)
        ->and($policy->resolution()['locked'])->toBeTrue()
        ->and(mhfSiteCodes())->toContain(InstallPreflight::FORBIDDEN_BY_HUB);

    // The hub flag also beats a server `true`; only a server `false` speaks first.
    config(['core.module_packages.install_enabled' => true]);
    expect($policy->state())->toBe(ModuleInstallPolicy::STATE_FORBIDDEN_BY_HUB);
    config(['core.module_packages.install_enabled' => false]);
    expect($policy->state())->toBe(ModuleInstallPolicy::STATE_FORBIDDEN_BY_SERVER);
});

test('the install path never asks the hub itself: it only reads what the verification already knows', function (): void {
    mhfSwitch(true);
    mhfStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => 'tok', 'module_install' => 'forbidden']);

    expect(app(ModuleInstallPolicy::class)->state())->toBe(ModuleInstallPolicy::STATE_ALLOWED)
        ->and(mhfSiteCodes())->not->toContain(InstallPreflight::FORBIDDEN_BY_HUB);
    Http::assertNothingSent();

    // Once the verification has asked (admin site status), the known value applies.
    app(SiteStatusService::class)->current();
    expect(app(ModuleInstallPolicy::class)->state())->toBe(ModuleInstallPolicy::STATE_FORBIDDEN_BY_HUB);
    Http::assertSentCount(1);
});

test('the hub allows: the switch decides', function (): void {
    mhfStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => 'tok', 'module_install' => 'allowed']);
    app(SiteStatusService::class)->current();
    $policy = app(ModuleInstallPolicy::class);

    expect($policy->state())->toBe(ModuleInstallPolicy::STATE_DISABLED);
    mhfSwitch(true);
    expect($policy->state())->toBe(ModuleInstallPolicy::STATE_ALLOWED)
        ->and(mhfSiteCodes())->not->toContain(InstallPreflight::FORBIDDEN_BY_HUB);
});

test('the hub cannot be reached: the last known value holds within the grace, then the switch decides', function (): void {
    mhfSwitch(true);
    mhfStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => 'tok', 'module_install' => 'forbidden']);
    app(SiteStatusService::class)->current();
    expect(app(ModuleInstallPolicy::class)->state())->toBe(ModuleInstallPolicy::STATE_FORBIDDEN_BY_HUB);

    // A deploy clears the cache and the hub goes away: the stored last answer still forbids for 7 days.
    Cache::flush();
    $this->hubRoutes = [MHF_STATUS_URL => Http::failedConnection()];
    Carbon::setTestNow(now()->addDays(6));
    expect(app(SiteStatusService::class)->moduleInstall())->toBe('forbidden')
        ->and(app(ModuleInstallPolicy::class)->state())->toBe(ModuleInstallPolicy::STATE_FORBIDDEN_BY_HUB);

    // Past the grace nothing is known: the hub is not a dependency, the switch decides.
    Cache::flush();
    Carbon::setTestNow(now()->addDays(2));
    expect(app(SiteStatusService::class)->moduleInstall())->toBeNull()
        ->and(app(ModuleInstallPolicy::class)->state())->toBe(ModuleInstallPolicy::STATE_ALLOWED);
});

test('no known value: an unreachable hub with no past answer or an older hub without the field lets the switch decide', function (): void {
    $this->hubRoutes = [MHF_STATUS_URL => Http::failedConnection()];
    expect(app(SiteStatusService::class)->moduleInstall())->toBeNull()
        ->and(app(ModuleInstallPolicy::class)->state())->toBe(ModuleInstallPolicy::STATE_DISABLED);

    Cache::flush();
    mhfStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => 'tok']);
    mhfSwitch(true);
    expect(app(SiteStatusService::class)->moduleInstall())->toBeNull()
        ->and(app(ModuleInstallPolicy::class)->state())->toBe(ModuleInstallPolicy::STATE_ALLOWED);
});

test('the value is cached with the verification for 15 minutes', function (): void {
    mhfStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => 'tok', 'module_install' => 'allowed']);
    expect(app(SiteStatusService::class)->moduleInstall())->toBe('allowed');

    mhfStatus($this, ['status' => 'connected', 'expires_at' => null, 'token' => 'tok', 'module_install' => 'forbidden']);
    Carbon::setTestNow(now()->addMinutes(14));
    expect(app(SiteStatusService::class)->moduleInstall())->toBe('allowed');
    Carbon::setTestNow(now()->addMinutes(2));
    expect(app(SiteStatusService::class)->moduleInstall())->toBe('forbidden');
});

test('a site without credentials reads the flag from the old verify answer', function (): void {
    config(['ispa-cms.cms_domain_secret' => '']);
    $this->hubRoutes = [MHF_VERIFY_URL => Http::response([
        'status' => 'success',
        'data' => ['token' => 'tok', 'expired_at' => null, 'module_install' => 'forbidden'],
        'message' => 'ok',
    ])];

    expect(app(SiteStatusService::class)->moduleInstall())->toBe('forbidden')
        ->and(app(SiteStatusService::class)->current())->not->toHaveKey('module_install');
});
