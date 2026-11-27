<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use App\Services\Dashboard\DashboardSummaryService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contract test for GET /api/v2/admin/dashboard/summary.
 */
final class DashboardSummaryTest extends TestCase
{
    private const KEYS = ['range', 'traffic', 'content', 'comments', 'commerce', 'people', 'attention', 'notices', 'series', 'modules'];

    /**
     * Authenticate as an admin and disable transport middleware.
     */
    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(DashboardSummaryService::class, 'availabilityMemo'))->setValue(null, []);
        $this->seedMinimalSchema();
        $this->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class, ThrottleRequests::class]);
        config()->set('ispa-cms.admin_auth_key', 'admin-test-key');
        Sanctum::actingAs(new User(['id' => 91, 'type' => UserType::WEBMASTER]));
    }

    /**
     * Create the minimal tables the aggregate queries read.
     */
    private function seedMinimalSchema(): void
    {
        Schema::create('comments', static function (Blueprint $t): void {
            $t->id();
            $t->string('status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('tracking_daily', static function (Blueprint $t): void {
            $t->id();
            foreach (['views', 'visitors', 'scans', 'download', 'sessions'] as $c) {
                $t->integer($c)->default(0);
            }
            $t->date('date')->nullable();
            $t->timestamps();
        });
        Schema::create('users', static function (Blueprint $t): void {
            $t->id();
            $t->integer('type')->nullable();
            $t->string('status')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        DB::table('comments')->insert([
            ['status' => 'status_published', 'created_at' => now()],
            ['status' => 'status_pending', 'created_at' => now()],
            ['status' => null, 'created_at' => now()],
        ]);
        DB::table('tracking_daily')->insert(['views' => 5, 'date' => now()->toDateString()]);
    }

    /**
     * The endpoint returns every top-level key with sane shapes.
     */
    public function test_summary_returns_contract_shape(): void
    {
        $response = $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')
            ->getJson('/api/v2/admin/dashboard/summary');

        $response->assertOk();
        $data = $response->json('data');

        foreach (self::KEYS as $key) {
            self::assertArrayHasKey($key, $data);
        }

        foreach (['content', 'comments', 'commerce', 'traffic'] as $block) {
            foreach ((array) ($data[$block] ?? []) as $name => $value) {
                if (! in_array($name, ['previous', 'lifetime'], true)) {
                    self::assertIsNumeric($value, "$block.$name");
                }
            }
        }

        foreach ($data['attention'] as $item) {
            self::assertArrayHasKey('key', $item);
            self::assertArrayHasKey('count', $item);
            self::assertArrayHasKey('route', $item);
        }

        self::assertSame(['content', 'ecommerce', 'education', 'tracking'], array_keys($data['modules']));
        self::assertSame(3, $data['comments']['total']);
        self::assertSame(1, $data['comments']['pending']);
        self::assertSame(1, $data['comments']['confirmed']);
        // comments_pending and posts_pending come from the per-user Content attention provider now (route, query and
        // count are covered by ContentAttentionProviderTest); this user holds no post.comment permission row.
        self::assertNull(collect($data['attention'])->firstWhere('key', 'comments_pending'));
        self::assertSame(5, $data['traffic']['views']);
        self::assertCount(7, $data['series']['views']);
        self::assertCount(7, $data['series']['visitors']);
        self::assertSame(5, $data['traffic']['lifetime']['views']);
        foreach (['views', 'visitors', 'sessions', 'downloads', 'scans'] as $k) {
            self::assertArrayHasKey($k, $data['traffic']['lifetime']);
        }
        self::assertArrayHasKey('contacts_total', $data['people']);
        self::assertIsArray($data['notices']);
    }

    /**
     * Raw read mode: the migration-seeded tracking_site_totals row (0/0) only grows in aggregate mode, so the
     * all-time views must come from tracking_daily, not from that row (cms.ispa.io showed 0 on 2026-11-21).
     */
    public function test_lifetime_views_ignore_site_totals_in_raw_read_mode(): void
    {
        config()->set('tracking.read_mode', 'raw');
        $this->createSiteTotals(0, 0);

        $this->withHeaders(['ISPA-ADMIN-AUTH-KEY' => 'admin-test-key'])
            ->getJson('/api/v2/admin/dashboard/summary?reload=1')
            ->assertOk()
            ->assertJsonPath('data.traffic.lifetime.views', 5);
    }

    /**
     * Aggregate read mode: baseline + live views of tracking_site_totals are the all-time views.
     */
    public function test_lifetime_views_use_site_totals_in_aggregate_read_mode(): void
    {
        config()->set('tracking.read_mode', 'aggregate');
        $this->createSiteTotals(1000, 20);

        $this->withHeaders(['ISPA-ADMIN-AUTH-KEY' => 'admin-test-key'])
            ->getJson('/api/v2/admin/dashboard/summary?reload=1')
            ->assertOk()
            ->assertJsonPath('data.traffic.lifetime.views', 1020);
    }

    /**
     * Create the tracking_site_totals singleton row with the given baseline and live views.
     */
    private function createSiteTotals(int $baseline, int $live): void
    {
        Schema::create('tracking_site_totals', static function (Blueprint $t): void {
            $t->unsignedTinyInteger('id')->primary();
            foreach (['baseline_views', 'live_views', 'baseline_scans', 'live_scans'] as $c) {
                $t->bigInteger($c)->default(0);
            }
        });
        DB::table('tracking_site_totals')->insert(['id' => 1, 'baseline_views' => $baseline, 'live_views' => $live]);
    }

    /**
     * reload=1 and custom ranges are accepted.
     */
    public function test_reload_and_custom_range_work(): void
    {
        $headers = ['ISPA-ADMIN-AUTH-KEY' => 'admin-test-key'];

        $this->withHeaders($headers)->getJson('/api/v2/admin/dashboard/summary?reload=1')->assertOk();
        $this->withHeaders($headers)
            ->getJson('/api/v2/admin/dashboard/summary?range=custom&from=2026-10-01&to=2026-10-10&reload=1')
            ->assertOk()
            ->assertJsonPath('data.range.from', '2026-10-01')
            ->assertJsonPath('data.range.previous_to', '2026-09-30')
            ->assertJsonPath('data.range.previous_from', '2026-09-21');
    }

    /**
     * The period tiles draw small charts from daily series: sessions and scans come with views and visitors.
     */
    public function test_series_carry_daily_sessions_and_scans(): void
    {
        $today = now()->toDateString();
        DB::table('tracking_daily')->insert(['date' => $today, 'views' => 4, 'visitors' => 3, 'sessions' => 5, 'scans' => 2]);

        $series = $this->withHeaders(['ISPA-ADMIN-AUTH-KEY' => 'admin-test-key'])
            ->getJson('/api/v2/admin/dashboard/summary?reload=1')
            ->assertOk()
            ->json('data.series');

        self::assertSame([$today, 5], array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, end($series['sessions'])));
        self::assertSame([$today, 2], array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, end($series['scans'])));
        self::assertCount(count($series['views']), $series['sessions']);
        self::assertCount(count($series['views']), $series['scans']);
    }

    /**
     * The dashboard band reads whether iSPA Hub is connected, whether the domain is a development one and the portal.
     */
    public function test_hub_block_reports_connection_and_portal(): void
    {
        config(['ispa-cms.cms_domain' => '', 'ispa-cms.cms_domain_secret' => '', 'ispa-cms.platform_hub_url' => 'https://ispa.tech', 'app.url' => 'http://site.test']);
        $headers = ['ISPA-ADMIN-AUTH-KEY' => 'admin-test-key'];

        $this->withHeaders($headers)->getJson('/api/v2/admin/dashboard/summary?reload=1')
            ->assertOk()
            ->assertJsonPath('data.hub.connected', false)
            ->assertJsonPath('data.hub.development', true)
            ->assertJsonPath('data.hub.portal_url', 'https://ispa.tech/portal');

        config(['app.url' => 'https://example.com']);
        $this->withHeaders($headers)->getJson('/api/v2/admin/dashboard/summary')->assertJsonPath('data.hub.development', false);

        // No readable host (APP_URL empty or without a scheme) is not a development domain, as on the hub
        // (CmsSiteService::domainType): the band never tells a live site that only free modules install.
        foreach (['', 'example.com'] as $url) {
            config(['app.url' => $url]);
            $this->withHeaders($headers)->getJson('/api/v2/admin/dashboard/summary')->assertJsonPath('data.hub.development', false);
        }
    }

    /**
     * An admin Core upgrade on a CI-deployed site rewrites core-manifest.json but never VERSION (deploy-owned), so the
     * band shows the manifest version; without a manifest it falls back to VERSION.
     */
    public function test_hub_block_version_prefers_core_manifest_over_version_file(): void
    {
        // A temp tree root, so a real core-manifest.json (a release install has one) is never read or overwritten.
        $base = sys_get_temp_dir().'/dashboard-manifest-'.bin2hex(random_bytes(6));
        mkdir($base, 0755, true);
        $path = $base.'/core-manifest.json';
        config(['ispa-cms.core_manifest_base' => $base, 'ispa-cms.app_version' => '2026.11.05']);
        $headers = ['ISPA-ADMIN-AUTH-KEY' => 'admin-test-key'];

        $this->withHeaders($headers)->getJson('/api/v2/admin/dashboard/summary?reload=1')
            ->assertJsonPath('data.hub.version', '2026.11.05');

        file_put_contents($path, json_encode([
            'format' => 1, 'version' => '2026.11.07', 'php_min' => '8.2', 'origin' => 'deploy',
            'upgraded_from' => '2026.11.05', 'checked' => (object) [], 'replaced' => [],
        ]));
        try {
            $this->withHeaders($headers)->getJson('/api/v2/admin/dashboard/summary')
                ->assertJsonPath('data.hub.version', '2026.11.07');

            // An unreadable manifest never breaks the dashboard.
            file_put_contents($path, '{broken');
            $this->withHeaders($headers)->getJson('/api/v2/admin/dashboard/summary')
                ->assertOk()->assertJsonPath('data.hub.version', '2026.11.05');
        } finally {
            @unlink($path);
            @rmdir($base);
        }
    }

    /**
     * The hub block is read on every request, so a site connected a minute ago never shows "not connected".
     */
    public function test_hub_block_is_not_cached(): void
    {
        config(['ispa-cms.cms_domain' => '', 'ispa-cms.cms_domain_secret' => '']);
        $headers = ['ISPA-ADMIN-AUTH-KEY' => 'admin-test-key'];
        $this->withHeaders($headers)->getJson('/api/v2/admin/dashboard/summary?reload=1')->assertJsonPath('data.hub.connected', false);

        config(['ispa-cms.cms_domain' => 'site.example.com', 'ispa-cms.cms_domain_secret' => 'secret-for-test']);

        $this->withHeaders($headers)->getJson('/api/v2/admin/dashboard/summary')->assertJsonPath('data.hub.connected', true);
    }

    public function test_a_status_group_the_installed_ecommerce_lacks_matches_nothing(): void
    {
        // Asked by module name: naming an Ecommerce class here would make the open-source export leave this whole file out.
        if (! \Nwidart\Modules\Facades\Module::has('Ecommerce')) {
            $this->markTestSkipped('Needs the Ecommerce module (not part of every install).');
        }

        $service = app(\App\Services\Dashboard\DashboardSummaryService::class);
        $method = new \ReflectionMethod($service, 'anyStatusIn');

        // An older Ecommerce from the hub may not have a newer bucket such as `draft`: no error, no match.
        $this->assertSame('(1 = 0)', $method->invoke($service, 'bucket_this_version_does_not_have'));
        $this->assertStringContainsString('order_status', $method->invoke($service, 'paid'));
    }
}
