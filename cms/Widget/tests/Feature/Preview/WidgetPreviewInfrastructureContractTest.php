<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Preview;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Http\Middleware\PrepareAnonymousWidgetPreviewRequest;
use App\Models\User;
use App\Support\Widget\CanonicalWidgetSchema;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Preview\RedisAtomicWidgetPreviewStore;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Cms\Widget\Tests\Fakes\FakeWidgetTargetContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Predis\Client as PredisClient;
use Tests\TestCase;

/**
 * Chunk 2 Task 19: infrastructure-contract proofs for the signed preview
 * session's atomic store, encryption at rest, response security headers,
 * app-emitted cache-bypass marker, and token redaction (plan lines
 * 1082-1124 DoD 1103, 1110, 1111, 1113; docs/architecture/adr-widget-
 * preview-session.md "Measurable acceptance evidence").
 *
 * Runs against the coordinator-provided ephemeral Redis at 127.0.0.1:6399
 * (throwaway, no password, no persistence) -- NOT the application's
 * `.env` production Redis (REDIS_ENABLE=false, password-protected). Every
 * test in this class constructs its OWN `Predis\Client` directly against
 * that ephemeral instance so no test depends on Laravel's `database.redis.*`
 * config being pre-wired for production.
 *
 * WHAT THIS FILE DOES NOT PROVE (explicit honesty boundary, not silently
 * skipped): real Cloudflare zone cache/page-rule export showing the edge
 * honors the app-emitted bypass marker. That is a deferred
 * `[OPS-RESIDUAL]` -- see the Task 19 change-log entry. This file proves
 * only the APPLICATION side: the response carries the correct signals
 * (`Cache-Control: no-store, private`) for any edge/reverse-proxy that
 * honors them.
 */
final class WidgetPreviewInfrastructureContractTest extends TestCase
{
    use RefreshDatabase;

    private const REDIS_HOST = '127.0.0.1';

    private const REDIS_PORT = 6399;

    private const REDIS_DB = 15;

    private const PROJECT = 'ExampleProject';

    private const SLOT = 'test.infra.slot';

    private const TEMPLATE_CONTEXT = 'test.infra.context';

    private const TARGET_TYPE = 'test.infra.target';

    private const ROUTE_NAME = 'test.widget.preview.infra-canonical';

    private string $storageRoot;

    /** @var array<string, string|null> */
    private static array $modulesStatusesSnapshot = [];

    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $path = dirname(__DIR__, 5) . '/modules_statuses.json';
        self::$modulesStatusesSnapshot = [$path => file_exists($path) ? file_get_contents($path) : null];

        register_shutdown_function(static function (): void {
            self::restoreModulesStatuses();
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreModulesStatuses();

        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    private static function restoreModulesStatuses(): void
    {
        foreach (self::$modulesStatusesSnapshot as $path => $original) {
            if ($original !== null) {
                file_put_contents($path, $original);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        self::$modulesStatusesSnapshot = [];
    }

    /**
     * Whether the ephemeral test Redis (127.0.0.1:6399) accepts TCP connections.
     * Probed once per PHP process so unreachable-Redis runs skip cheaply instead
     * of building huge Predis error traces.
     */
    private static function ephemeralRedisReachable(): bool
    {
        static $reachable = null;

        if ($reachable === null) {
            // A no-op handler keeps the expected connection warning out of the test report.
            set_error_handler(static fn (): bool => true);
            $socket = fsockopen('127.0.0.1', 6399, $errno, $errstr, 0.5);
            restore_error_handler();
            $reachable = $socket !== false;
            if ($socket !== false) {
                fclose($socket);
            }
        }

        return $reachable;
    }

    protected function setUp(): void
    {
        if (! self::ephemeralRedisReachable()) {
            $this->markTestSkipped('Redis 127.0.0.1:6399 not reachable');
        }

        parent::setUp();

        $this->redisClient()->flushdb();

        config(['database.redis.widget_preview' => [
            'host' => self::REDIS_HOST,
            'port' => self::REDIS_PORT,
            'database' => self::REDIS_DB,
            'timeout' => 2.0,
        ]]);

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $this->storageRoot = storage_path('framework/testing/widget-preview-infra-' . bin2hex(random_bytes(6)));

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        $slots = $this->app->make(WidgetSlotRegistry::class);
        $slots->register(new WidgetSlotDeclaration(
            key: self::SLOT,
            labelKey: 'test.infra.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));

        $templateContexts = $this->app->make(WidgetTemplateContextRegistry::class);
        $templateContexts->registerTemplateContext(self::TEMPLATE_CONTEXT, [self::SLOT]);

        $fakeResolver = new FakeWidgetTargetContextResolver();
        $fakeResolver->withTarget(
            targetId: 'infra-target-1',
            targetType: self::TARGET_TYPE,
            projectName: self::PROJECT,
            label: 'Infra Target One',
            routeName: self::ROUTE_NAME,
            routeParameters: ['slug' => 'infra-target-1'],
        );
        $this->app->instance(FakeWidgetTargetContextResolver::class, $fakeResolver);

        $targetContexts = $this->app->make(WidgetTargetContextRegistry::class);
        $targetContexts->registerTargetContext(new WidgetTargetContextDefinition(
            targetType: self::TARGET_TYPE,
            labelKey: 'test.infra.target.label',
            templateContextKey: self::TEMPLATE_CONTEXT,
            resolverService: FakeWidgetTargetContextResolver::class,
        ));

        if (!Route::has(self::ROUTE_NAME)) {
            Route::middleware(['web'])
                ->get('/__test/widget-preview-infra/{slug}', static function (string $slug) {
                    if (request()->boolean('__throw')) {
                        throw new \RuntimeException('simulated downstream failure for redaction proof');
                    }

                    return response()->json(['mode' => 'preview-infra', 'slug' => $slug]);
                })
                ->name(self::ROUTE_NAME);
            app('router')->getRoutes()->refreshNameLookups();
        }

        app(ActivatorInterface::class)->setActiveByName('Widget', true);

        $this->app->singleton(ModuleLifecycleBarrier::class, fn () => new class implements ModuleLifecycleBarrier {
            public function transition(string $moduleName, callable $onAcquired, bool $maintenanceConfirmed = false): ?string
            {
                return $onAcquired();
            }
        });

        $this->withoutMiddleware([
            ApiLocalization::class,
            ThrottleRequests::class,
            HttpsProtocol::class,
            Localization::class,
        ]);
    }

    protected function tearDown(): void
    {
        // storageRoot is uninitialized when setUp() skipped before creating it.
        if (isset($this->storageRoot) && is_dir($this->storageRoot)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->storageRoot);
        }

        parent::tearDown();
    }

    private function redisClient(): PredisClient
    {
        return new PredisClient([
            'scheme' => 'tcp',
            'host' => self::REDIS_HOST,
            'port' => self::REDIS_PORT,
            'database' => self::REDIS_DB,
            'timeout' => 2.0,
        ]);
    }

    // -----------------------------------------------------------------
    // HTTP fixtures (mirrors WidgetPreviewSessionTest/WidgetPreviewCanonicalRouteTest)
    // -----------------------------------------------------------------

    private function createWidget(): Widget
    {
        return Widget::query()->create(['name' => 'Test Widget', 'description' => '', 'js' => '']);
    }

    private function createTheme(Widget $widget): WidgetTheme
    {
        return WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Test Theme',
            'content' => '<div class="card">{title}</div>',
            'css' => '.card { color: red; }',
            'project_name' => [self::PROJECT],
        ]);
    }

    private function liveSourceFingerprint(Widget $widget, WidgetTheme $theme): string
    {
        $fingerprint = (new LegacyWidgetExecutableSurfaceAnalyzer())->analyze(
            widgetJs: (string) $widget->js,
            themeContent: (string) $theme->content,
            themeCss: (string) $theme->css,
            canonicalSchema: (new CanonicalWidgetSchema())->resolve($widget->id, $theme->id),
            policyVersion: ApprovedLegacyWidgetRenderer::CURRENT_POLICY_VERSION,
            trustLane: ApprovedLegacyWidgetRenderer::CURRENT_TRUST_LANE,
            applicability: is_array($theme->project_name) ? $theme->project_name : ['Default'],
            artifactBytes: (new LegacyWidgetArtifactSetPublisher())->renderThemeContent($theme),
        );

        return $fingerprint->manifestHash;
    }

    private function userOfType(int $type, array $permissionKeys = []): User
    {
        $user = User::forceCreate([
            'name' => 'Infra Tester',
            'email' => 'infra-tester-' . uniqid('', true) . '@test.local',
            'password' => bcrypt('secret'),
            'type' => $type,
        ]);

        if ($permissionKeys === []) {
            return $user;
        }

        (new WidgetPermissionTableSeeder())->run();

        $role = Role::query()->create(['name' => 'Test Role ' . uniqid('', true), 'custom' => ['level' => 50]]);
        foreach ($permissionKeys as $key) {
            [$group, $name] = explode('.', $key, 2);
            $permission = Permission::query()->where('group', $group)->where('name', $name)->firstOrFail();
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function fullAccessUser(): User
    {
        return $this->userOfType(UserType::ADMIN, [
            'widget_instance.create',
            'widget_layout.preview',
            'widget_template.approve',
        ]);
    }

    private function createReadyInstance(Widget $widget, WidgetTheme $theme, User $user): WidgetInstance
    {
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
            ]);
        $response->assertSuccessful();
        $manifestHash = (string) $response->json('data.manifest_hash');
        app(WidgetManifestApprovalService::class)->approve(self::PROJECT, $manifestHash, 1, (int) $user->id, 'test approval');

        $result = app(WidgetInstanceService::class)->create(
            self::PROJECT,
            (int) $user->id,
            'create-key-' . uniqid('', true),
            [
                'name' => 'Infra Instance',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'Original'],
            ],
        );

        return $result->instance;
    }

    private function createLayout(string $key = 'site'): WidgetLayout
    {
        return WidgetLayout::query()->create(['project_name' => self::PROJECT, 'key' => $key]);
    }

    private function mintPreviewUrl(User $user, WidgetInstance $instance, WidgetLayout $layout): string
    {
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/preview-sessions", [
                'overlay' => [[
                    'slot_key' => self::SLOT,
                    'scope_type' => 'target',
                    'target_type' => self::TARGET_TYPE,
                    'target_id' => 'infra-target-1',
                    'widget_instance_uuid' => $instance->uuid,
                    'widget_instance_version' => 1,
                    'position' => 0,
                    'enabled' => true,
                    'rules' => [],
                ]],
                'locale' => 'vi',
                'template_context' => self::TEMPLATE_CONTEXT,
                'target_type' => self::TARGET_TYPE,
                'target_id' => 'infra-target-1',
                'viewport' => 'desktop',
            ]);
        $response->assertStatus(201);

        return (string) $response->json('preview_url');
    }

    /**
     * @return array{user: User, instance: WidgetInstance, layout: WidgetLayout}
     */
    private function readyFixture(): array
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, $user);
        $layout = $this->createLayout();

        return ['user' => $user, 'instance' => $instance, 'layout' => $layout];
    }

    public function test_put_then_consume_returns_the_stored_value_exactly_once(): void
    {
        $store = new RedisAtomicWidgetPreviewStore($this->redisClient());

        $store->put('token-abc', 'ciphertext-payload', 60);

        $this->assertSame('ciphertext-payload', $store->consume('token-abc'));
        $this->assertNull($store->consume('token-abc'), 'A second consume of the same token must fail: single-use.');
    }

    public function test_consume_of_unknown_token_returns_null(): void
    {
        $store = new RedisAtomicWidgetPreviewStore($this->redisClient());

        $this->assertNull($store->consume('never-existed'));
    }

    public function test_stored_value_expires_after_its_ttl(): void
    {
        $store = new RedisAtomicWidgetPreviewStore($this->redisClient());

        $store->put('token-ttl', 'payload', 1);
        sleep(2);

        $this->assertNull($store->consume('token-ttl'), 'Expired token must not be consumable.');
    }

    public function test_cross_node_read_after_write_over_two_separate_connections(): void
    {
        // Node A and Node B are two INDEPENDENT Predis connections against
        // the SAME shared Redis logical database -- proving the
        // shared-store cross-node semantic (admin mints on one process/
        // node, a public node consumes on a different process/node).
        $nodeA = new RedisAtomicWidgetPreviewStore($this->redisClient());
        $nodeB = new RedisAtomicWidgetPreviewStore($this->redisClient());

        $nodeA->put('token-cross-node', 'cross-node-payload', 60);

        $this->assertSame('cross-node-payload', $nodeB->consume('token-cross-node'));
        $this->assertNull($nodeA->consume('token-cross-node'), 'Already consumed by node B; node A must not see it again.');
    }

    public function test_sequential_double_consume_only_one_winner_proves_the_atomic_primitive(): void
    {
        // True parallel concurrency cannot be exercised inside a single PHP
        // test process; sequential double-consume against the SAME atomic
        // GETDEL primitive is this module's own established proxy for
        // "only one concurrent winner" (Task 18 disclosed the identical
        // scope boundary for its own concurrency tests: functional
        // sequencing proof, not true concurrent contention).
        $store = new RedisAtomicWidgetPreviewStore($this->redisClient());
        $store->put('token-concurrent', 'payload', 60);

        $first = $store->consume('token-concurrent');
        $second = $store->consume('token-concurrent');

        $this->assertSame('payload', $first);
        $this->assertNull($second, 'Only one of two consumers may win the atomic consume.');
    }

    // -----------------------------------------------------------------
    // Cross-node consume via the REAL HTTP mint endpoint (complements the
    // raw-store-level cross-node test above).
    // -----------------------------------------------------------------

    public function test_a_token_minted_via_the_http_admin_endpoint_is_consumable_from_an_independent_redis_connection(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['user'], $fixture['instance'], $fixture['layout']);

        $query = [];
        parse_str((string) parse_url($previewUrl, PHP_URL_QUERY), $query);
        $token = (string) $query[PrepareAnonymousWidgetPreviewRequest::TOKEN_PARAMETER];

        // "Node B": a brand-new Predis connection, never touched by the
        // HTTP mint call above (that call used the container-bound store
        // resolved inside THIS SAME php-fpm-style process, but through its
        // own independent connection object per WidgetServiceProvider's
        // singleton factory).
        $nodeB = new RedisAtomicWidgetPreviewStore($this->redisClient());
        $raw = $nodeB->consume($token);

        $this->assertNotNull($raw, 'The token minted by the HTTP endpoint must be visible and consumable from an independent Redis connection.');
    }

    // -----------------------------------------------------------------
    // Encryption at rest
    // -----------------------------------------------------------------

    public function test_the_stored_overlay_record_is_encrypted_not_plaintext(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['user'], $fixture['instance'], $fixture['layout']);

        $query = [];
        parse_str((string) parse_url($previewUrl, PHP_URL_QUERY), $query);
        $token = (string) $query[PrepareAnonymousWidgetPreviewRequest::TOKEN_PARAMETER];

        $raw = $this->redisClient()->get('widget_preview_session:' . $token);

        $this->assertNotNull($raw, 'The raw Redis value must exist before consume.');
        $this->assertStringNotContainsString(self::SLOT, $raw, 'Slot key must not appear in plaintext.');
        $this->assertStringNotContainsString(self::PROJECT, $raw, 'Project name must not appear in plaintext.');
        $this->assertStringNotContainsString('infra-target-1', $raw, 'Target id must not appear in plaintext.');
        $this->assertStringNotContainsString($fixture['layout']->uuid, $raw, 'Layout uuid must not appear in plaintext.');
        // Laravel's encrypt payload is base64 JSON {iv, value, mac, tag} --
        // proving it decodes to that shape (rather than our own plain JSON)
        // is a stronger proof than "some substrings are absent".
        $decoded = json_decode((string) base64_decode($raw, true), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('mac', $decoded, 'Must be a real Laravel Crypt payload (HMAC-protected), not ad-hoc obfuscation.');
    }

    // -----------------------------------------------------------------
    // Response security headers (DoD 1110, 1111)
    // -----------------------------------------------------------------

    public function test_a_successful_preview_response_carries_the_frozen_security_headers(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['user'], $fixture['instance'], $fixture['layout']);

        $response = $this->get($previewUrl);

        $response->assertStatus(200);
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("script-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('X-Frame-Options'), 'A conflicting X-Frame-Options must be reconciled away, not left to block the CSP frame-ancestors intent.');
        $this->assertFalse($response->headers->has('Set-Cookie'), 'No preview session cookie may ever be set.');
    }

    /**
     * Defense in depth: a RECOGNIZED candidate that fails consume (e.g.
     * replay) must be exactly as protected as a successful one -- both are
     * "accepted candidate" responses from the root middleware's point of
     * view.
     */
    public function test_a_failed_consume_response_still_carries_the_frozen_security_headers(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['user'], $fixture['instance'], $fixture['layout']);

        $this->get($previewUrl); // burn the token
        $replay = $this->get($previewUrl); // recognized candidate, invalid session

        $replay->assertHeader('Cache-Control', 'no-store, private');
        $replay->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $replay->headers->get('Content-Security-Policy'));
    }

    // -----------------------------------------------------------------
    // Token redaction (DoD 1111: "redact token from app ... and exception
    // logs")
    // -----------------------------------------------------------------

    public function test_a_downstream_exception_during_a_preview_request_never_logs_the_raw_token(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['user'], $fixture['instance'], $fixture['layout']);

        $query = [];
        parse_str((string) parse_url($previewUrl, PHP_URL_QUERY), $query);
        $token = (string) $query[PrepareAnonymousWidgetPreviewRequest::TOKEN_PARAMETER];

        $capturedMessages = [];
        Log::listen(function ($event) use (&$capturedMessages): void {
            $capturedMessages[] = $event->message;
            $capturedMessages[] = json_encode($event->context);
        });

        // Appending a query param to the already-signed $previewUrl would
        // invalidate its Laravel signature (the signature covers the full
        // query string), so mint a purpose-built throwing variant that
        // includes the SAME token but signs `__throw=1` as part of the
        // covered parameters instead.
        $throwingUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            self::ROUTE_NAME,
            now()->addMinutes(5),
            ['slug' => 'infra-target-1', PrepareAnonymousWidgetPreviewRequest::TOKEN_PARAMETER => $token, '__throw' => '1'],
        );

        $response = $this->get($throwingUrl);

        $response->assertStatus(500);

        $joined = implode("\n", $capturedMessages);
        $this->assertStringNotContainsString($token, $joined, 'The raw preview token must never appear in application log output.');
    }
}
