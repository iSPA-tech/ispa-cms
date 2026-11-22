<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * site report levels off, basic and full. Every level sends exactly its fields, off sends nothing, sites with
 * the `.env` secret (iSPA-run fleet) stay on full, sites connected from the connect screen default to basic, the `.env`
 * level wins over the stored setting, and the Webmaster-only API stores (audited) and previews the exact report.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Role;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Platform\HubCredentials;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Platform\SiteStatusReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const SRL_BASE = 'https://localhost/api/v2/admin/utilities/modules/report-level';

const SRL_FULL_KEYS = [
    'schema', 'domain', 'app_version', 'release', 'revision', 'deployed_at', 'php_version', 'laravel_version',
    'activator', 'modules', 'pending_migrations', 'health', 'backups', 'runtime_collisions',
];

const SRL_BASIC_KEYS = ['schema', 'domain', 'app_version', 'release', 'modules'];

/** An admin-type account with a role of the given level (0 = Webmaster). */
function srlUser(string $name, ?int $roleLevel, int $type = UserType::ADMIN): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => $type,
        'level' => 0,
        'status' => null,
    ]);
    if ($roleLevel !== null) {
        $user->roles()->attach(Role::query()->create(['name' => $name . ' ' . Str::random(4), 'custom' => ['level' => $roleLevel]]));
    }

    return $user;
}

/** A fleet site: domain and secret in `.env` (config). */
function srlEnvSite(string $secret = 'env-secret-value-1234567890'): void
{
    config(['ispa-cms.cms_domain' => 'fleet.example.test', 'ispa-cms.cms_domain_secret' => $secret]);
}

/** A self-installed site connected from the connect screen: the pair is stored encrypted in the database. */
function srlConnectedSite(string $secret = 'db-secret-value-1234567890'): void
{
    config(['ispa-cms.cms_domain' => '', 'ispa-cms.cms_domain_secret' => '']);
    app(HubCredentials::class)->store('self.example.test', $secret);
}

/** Fake hub that answers every request with a signed response of the given status. */
function srlFakeHub(string $secret, int $status = 201, string $body = '{"status":"ok"}'): void
{
    Http::fake(function (ClientRequest $sent) use ($secret, $status, $body) {
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0] ?? '';

        return Http::response($body, $status, [
            'Content-Type' => 'application/json',
            PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($secret, $status, $nonce, $body),
        ]);
    });
}

beforeEach(function (): void {
    config(['cache.default' => 'array', 'ispa-cms.platform_hub_url' => 'https://hub.test', 'ispa-cms.platform_report_level' => '']);
    Cache::flush();
    Http::preventStrayRequests();
    srlUser('filler', null); // user id 1 is always a Webmaster
    $this->webmaster = srlUser('webmaster', 0);
    $this->manager = srlUser('manager', 50);
});

test('full sends exactly today\'s report fields, module rows with the revoked flag', function (): void {
    srlEnvSite();
    $report = app(SiteStatusReport::class)->payload(SiteStatusReport::LEVEL_FULL);

    expect(array_keys($report))->toBe(SRL_FULL_KEYS);
    foreach ($report['modules'] as $row) {
        expect(array_keys($row))->toBe(['name', 'enabled', 'version', 'source', 'revoked']);
    }
});

test('basic sends only schema, domain, app version, release and modules (name, enabled, version, source)', function (): void {
    srlConnectedSite();
    $report = app(SiteStatusReport::class)->payload(SiteStatusReport::LEVEL_BASIC);

    expect(array_keys($report))->toBe(SRL_BASIC_KEYS)
        ->and($report['domain'])->toBe('self.example.test')
        ->and($report['modules'])->not->toBeEmpty();
    foreach ($report['modules'] as $row) {
        expect(array_keys($row))->toBe(['name', 'enabled', 'version', 'source']);
    }
    $json = (string) json_encode($report);
    foreach (['health', 'backups', 'pending_migrations', 'php_version', 'laravel_version', 'runtime_collisions', 'module_integrity', 'revoked'] as $key) {
        expect($json)->not->toContain('"' . $key . '"');
    }
});

test('off builds nothing and the command sends nothing', function (): void {
    srlEnvSite();
    config(['ispa-cms.platform_report_level' => 'off']);

    expect(app(SiteStatusReport::class)->payload())->toBeNull()
        ->and(Artisan::call('cms:platform-report'))->toBe(0)
        ->and(Artisan::output())->toContain('off');
    Http::assertNothingSent();

    expect(Artisan::call('cms:platform-report', ['--dry-run' => true]))->toBe(0);
    Http::assertNothingSent();
});

test('defaults: the .env secret keeps full, a connect-screen site gets basic, an unconnected site sends nothing', function (): void {
    srlEnvSite();
    expect(app(SiteStatusReport::class)->resolution())->toMatchArray(['level' => 'full', 'source' => 'default', 'default' => 'full', 'connected' => true]);

    srlConnectedSite();
    expect(app(SiteStatusReport::class)->resolution())->toMatchArray(['level' => 'basic', 'source' => 'default', 'default' => 'basic', 'connected' => true]);

    Illuminate\Support\Facades\DB::table(HubCredentials::TABLE)->delete();
    expect(app(SiteStatusReport::class)->resolution())->toMatchArray(['level' => 'off', 'source' => 'unconnected', 'connected' => false])
        ->and(app(SiteStatusReport::class)->payload())->toBeNull();
});

test('the stored setting beats the default and the .env level beats the stored setting; bad values are ignored', function (): void {
    srlEnvSite();
    $report = app(SiteStatusReport::class);

    $report->storeLevel('basic');
    expect($report->resolution())->toMatchArray(['level' => 'basic', 'source' => 'setting', 'stored' => 'basic', 'env_locked' => false]);

    config(['ispa-cms.platform_report_level' => 'off']);
    expect($report->resolution())->toMatchArray(['level' => 'off', 'source' => 'env', 'env_locked' => true]);

    config(['ispa-cms.platform_report_level' => 'everything']);
    expect($report->resolution())->toMatchArray(['level' => 'basic', 'source' => 'setting', 'env_locked' => false]);

    Setting::query()->where('setting_key', SiteStatusReport::SETTING_KEY)->update(['value' => 'loud']);
    expect($report->resolution())->toMatchArray(['level' => 'full', 'source' => 'default']);
});

test('a connect-screen site posts exactly the basic report, signed', function (): void {
    $secret = 'db-secret-value-1234567890';
    srlConnectedSite($secret);
    srlFakeHub($secret);

    expect(Artisan::call('cms:platform-report'))->toBe(0);

    Http::assertSentCount(1);
    Http::assertSent(function (ClientRequest $r) use ($secret) {
        $data = json_decode($r->body(), true);

        return $r->url() === 'https://hub.test/api/v2/public/platform/reports'
            && $r->hasHeader(PlatformSignature::HEADER_DOMAIN, 'self.example.test')
            && $r->hasHeader(PlatformSignature::HEADER_SIGNATURE)
            && array_keys($data) === SRL_BASIC_KEYS
            && ! str_contains($r->body(), $secret);
    });
});

test('old site x new hub: a fleet site without any new setting still sends the full report', function (): void {
    $secret = 'env-secret-value-1234567890';
    srlEnvSite($secret);
    srlFakeHub($secret);

    expect(Artisan::call('cms:platform-report'))->toBe(0);
    Http::assertSent(fn (ClientRequest $r) => array_keys(json_decode($r->body(), true)) === SRL_FULL_KEYS);
});

test('new site x old hub: a hub that refuses the basic report fails the command quietly and changes nothing', function (): void {
    $secret = 'db-secret-value-1234567890';
    srlConnectedSite($secret);
    srlFakeHub($secret, 422, '{"error_code":"PLATFORM_INVALID_REQUEST","message":"The report is invalid."}');

    expect(Artisan::call('cms:platform-report'))->toBe(1)
        ->and(Artisan::output())->not->toContain($secret)
        ->and(app(SiteStatusReport::class)->level())->toBe('basic');
});

test('every report-level route needs a signed-in Webmaster', function (): void {
    $routes = [['GET', SRL_BASE], ['PUT', SRL_BASE], ['GET', SRL_BASE . '/preview']];
    foreach ($routes as [$method, $url]) {
        $this->json($method, $url, ['level' => 'off'])->assertStatus(401);
    }
    Sanctum::actingAs($this->manager->fresh(), ['*']);
    foreach ($routes as [$method, $url]) {
        $this->json($method, $url, ['level' => 'off'])->assertStatus(403);
    }
    expect(Setting::query()->where('setting_key', SiteStatusReport::SETTING_KEY)->exists())->toBeFalse();
});

test('show returns the level, where it comes from and the default, never a secret', function (): void {
    srlConnectedSite('db-secret-value-1234567890');
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);

    $response = $this->getJson(SRL_BASE)->assertOk();
    expect($response->json('data'))->toMatchArray([
        'level' => 'basic', 'source' => 'default', 'default' => 'basic', 'stored' => null, 'env_locked' => false, 'connected' => true,
        'levels' => ['off', 'basic', 'full'],
    ])->and($response->getContent())->not->toContain('db-secret-value');
});

test('update stores the level, audits only the change and refuses bad values', function (): void {
    srlConnectedSite();
    Event::fake([AuditLogEvent::class]);
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);

    $this->putJson(SRL_BASE, ['level' => 'loud'])->assertStatus(422);
    Event::assertNotDispatched(AuditLogEvent::class);

    $this->putJson(SRL_BASE, ['level' => 'full'])->assertOk()->assertJsonPath('data.level', 'full')->assertJsonPath('data.source', 'setting');
    expect(app(SiteStatusReport::class)->level())->toBe('full');
    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e) => $e->action === 'platform.report_level.updated'
        && $e->changed === ['from' => 'basic', 'to' => 'full'] && $e->type === Setting::class);
});

test('update is refused while the .env level decides, and nothing is stored', function (): void {
    srlEnvSite();
    config(['ispa-cms.platform_report_level' => 'full']);
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);

    $this->putJson(SRL_BASE, ['level' => 'off'])->assertStatus(409)->assertJsonPath('error_code', 'REPORT_LEVEL_MANAGED_BY_ENV');
    expect(Setting::query()->where('setting_key', SiteStatusReport::SETTING_KEY)->exists())->toBeFalse();
});

test('preview returns the exact JSON the next report would send, for the current or a chosen level', function (): void {
    srlConnectedSite();
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);

    $current = $this->getJson(SRL_BASE . '/preview')->assertOk();
    expect($current->json('data.level'))->toBe('basic')
        ->and(array_keys(json_decode($current->json('data.json'), true)))->toBe(SRL_BASIC_KEYS)
        ->and($current->json('data.json'))->toContain("\n    \"schema\": 1");

    // The same text the command prints with --dry-run.
    Artisan::call('cms:platform-report', ['--dry-run' => true]);
    expect(trim(Artisan::output()))->toBe($current->json('data.json'));

    $full = $this->getJson(SRL_BASE . '/preview?level=full')->assertOk();
    expect(array_keys(json_decode($full->json('data.json'), true)))->toBe(SRL_FULL_KEYS);

    $off = $this->getJson(SRL_BASE . '/preview?level=off')->assertOk();
    expect($off->json('data.level'))->toBe('off')->and($off->json('data.json'))->toBeNull();

    $this->getJson(SRL_BASE . '/preview?level=loud')->assertStatus(422);
    expect(Setting::query()->where('setting_key', SiteStatusReport::SETTING_KEY)->exists())->toBeFalse();
});
