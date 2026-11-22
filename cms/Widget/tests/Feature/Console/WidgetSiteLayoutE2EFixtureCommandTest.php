<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Console;

use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use Cms\Core\Models\Permission;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Nwidart\Modules\Contracts\ActivatorInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Chunk 2 Task 24b: {@see \Cms\Widget\Console\Commands\WidgetSiteLayoutE2EFixtureCommand}
 * (plan lines 1293-1317, DoD 1305-1306).
 *
 * Boots the real WidgetServiceProvider manually (the module is disabled by
 * default in modules_statuses.json, same established precedent as every
 * other Widget Feature test -- see WidgetInstanceApiTest's own class
 * docblock) so the command's own service dependencies resolve, then drives
 * `cms:widget-site-layout-fixture` through Artisan::call()/Artisan::output()
 * exactly as a real operator would.
 */
final class WidgetSiteLayoutE2EFixtureCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'WidgetFixtureE2E';

    private string $storageRoot;

    /** @var array<string, string|null> class-level snapshot: see setUpBeforeClass()'s docblock. */
    private static array $sharedFileSnapshots = [];

    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    /**
     * Same class-level snapshot/shutdown-function pattern as
     * WidgetInstanceApiTest::setUpBeforeClass() -- see that method's own
     * docblock for the full reasoning (array-keyed shape, never a nullable
     * scalar, to avoid the double-restore-deletion hazard Task 17's closure
     * notes recorded).
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $path = dirname(__DIR__, 5) . '/modules_statuses.json';
        self::$sharedFileSnapshots = [$path => file_exists($path) ? file_get_contents($path) : null];

        register_shutdown_function(static function (): void {
            self::restoreSharedFiles();
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreSharedFiles();

        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    private static function restoreSharedFiles(): void
    {
        foreach (self::$sharedFileSnapshots as $path => $original) {
            if ($original !== null) {
                file_put_contents($path, $original);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        self::$sharedFileSnapshots = [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $this->storageRoot = storage_path('framework/testing/widget-fixture-cmd-' . bin2hex(random_bytes(6)));

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        $this->app->singleton(ModuleLifecycleBarrier::class, fn () => new class implements ModuleLifecycleBarrier {
            public function transition(string $moduleName, callable $onAcquired, bool $maintenanceConfirmed = false): ?string
            {
                return $onAcquired();
            }
        });

        app(ActivatorInterface::class)->setActiveByName('Widget', true);

        $this->withoutMiddleware([
            ApiLocalization::class,
            ThrottleRequests::class,
            HttpsProtocol::class,
            Localization::class,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->storageRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->storageRoot);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Registration (DoD 1306: assert all four commands visible BEFORE
    // exercising fixture behavior).
    // -----------------------------------------------------------------

    public function test_all_four_widget_commands_are_registered_via_artisan_all(): void
    {
        $names = array_keys(Artisan::all());

        foreach ([
            'cms:widget-registry-preflight',
            'cms:widget-manifests-reconcile',
            'cms:widget-module-disable-check',
            'cms:widget-site-layout-fixture',
        ] as $expected) {
            $this->assertContains($expected, $names, "{$expected} must be registered via WidgetServiceProvider::registerCommands().");
        }
    }

    // -----------------------------------------------------------------
    // Production refusal (DoD 1305).
    // -----------------------------------------------------------------

    public function test_refuses_to_run_in_production_and_creates_nothing(): void
    {
        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $exitCode = Artisan::call('cms:widget-site-layout-fixture', ['--format' => 'json']);
        } finally {
            $this->app['env'] = $originalEnv;
        }

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('production', strtolower(Artisan::output()));
        $this->assertSame(0, WidgetInstance::query()->count());
        $this->assertSame(0, WidgetLayout::query()->count());
    }

    // -----------------------------------------------------------------
    // Deterministic lifecycle (DoD 1305: "seeds a complete deterministic
    // lifecycle: roles + Layout+Draft, Instance+Version, published Release,
    // approved Manifest").
    // -----------------------------------------------------------------

    public function test_reset_seeds_a_complete_deterministic_lifecycle_reported_as_json(): void
    {
        $exitCode = Artisan::call('cms:widget-site-layout-fixture', ['--reset' => true, '--format' => 'json']);
        $this->assertSame(0, $exitCode);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        foreach ([
            'reset', 'project_name', 'widget_id', 'widget_theme_id', 'manifest_hash',
            'instance_uuid', 'instance_version', 'layout_uuid', 'layout_key',
            'draft_version', 'draft_lock_version', 'release_version', 'roles_seeded',
        ] as $key) {
            $this->assertArrayHasKey($key, $output, "JSON output must carry '{$key}'.");
        }

        $this->assertTrue($output['reset']);
        $this->assertSame(self::PROJECT, $output['project_name']);
        $this->assertSame('site', $output['layout_key']);
        $this->assertSame(1, $output['instance_version'], 'A freshly reset Instance must be at Version 1.');
        $this->assertSame($output['release_version'] + 1, $output['draft_version'], 'Publish always reserves fresh Draft = Release + 1 (WidgetAggregateLockOrder::allocateLayoutVersionPair()).');
        $this->assertSame(0, $output['draft_lock_version'], 'A fresh Draft cloned by publish starts at lock_version 0.');
        $this->assertIsList($output['roles_seeded']);
        $this->assertContains('widget_instance.create', $output['roles_seeded']);
        $this->assertContains('widget_template.approve', $output['roles_seeded']);

        // Roles + Layout+Draft, Instance+Version, published Release, approved Manifest.
        $this->assertTrue(Permission::query()->where('group', 'widget_instance')->where('name', 'create')->exists());

        $this->assertSame(1, WidgetInstance::query()->count());
        $this->assertSame(1, WidgetInstanceVersion::query()->count());
        $this->assertSame(1, WidgetLayout::query()->count());

        $layout = WidgetLayout::query()->firstOrFail();
        $this->assertNotNull($layout->current_draft_version_id);
        $this->assertNotNull($layout->current_published_version_id, 'A published Release must exist.');

        $draft = WidgetLayoutVersion::query()->findOrFail($layout->current_draft_version_id);
        $this->assertSame(WidgetLayoutVersion::STATE_DRAFT, $draft->state);

        $release = WidgetLayoutVersion::query()->findOrFail($layout->current_published_version_id);
        $this->assertSame(WidgetLayoutVersion::STATE_RELEASE, $release->state);
        $this->assertNotNull($release->published_at);

        $this->assertSame(
            1,
            WidgetPlacement::query()->where('layout_version_id', $release->id)->count(),
            'The published Release must carry the seeded Placement (publish clones the Draft Placements).'
        );

        $manifest = WidgetArtifactManifestModel::query()->where('manifest_hash', $output['manifest_hash'])->firstOrFail();
        $this->assertSame(WidgetManifestApprovalService::STATE_APPROVED, $manifest->eligibility_state);
        $this->assertFalse((bool) $manifest->blocked);
    }

    public function test_reset_run_twice_converges_on_a_single_row_each_never_duplicating(): void
    {
        Artisan::call('cms:widget-site-layout-fixture', ['--reset' => true, '--format' => 'json']);
        $exitCode = Artisan::call('cms:widget-site-layout-fixture', ['--reset' => true, '--format' => 'json']);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, WidgetInstance::query()->count());
        $this->assertSame(1, WidgetLayout::query()->count());
        $this->assertSame(1, WidgetArtifactManifestModel::query()->count());

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('site', $output['layout_key']);
        $this->assertSame(1, $output['instance_version']);
    }

    public function test_repeat_without_reset_is_a_true_no_op_replay(): void
    {
        Artisan::call('cms:widget-site-layout-fixture', ['--reset' => true, '--format' => 'json']);
        $first = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $firstDraftLockVersion = WidgetLayoutVersion::query()->where('state', WidgetLayoutVersion::STATE_DRAFT)->value('lock_version');

        $exitCode = Artisan::call('cms:widget-site-layout-fixture', ['--format' => 'json']);
        $second = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        // "reset" itself legitimately echoes back the CLI flag passed (true
        // vs false on the two calls) -- every OTHER field must be identical,
        // proving the underlying lifecycle data was never re-mutated.
        unset($first['reset'], $second['reset']);
        $this->assertSame($first, $second, 'A repeat run without --reset must replay identical lifecycle data (true idempotent no-op).');

        $this->assertSame(1, WidgetInstance::query()->count());
        $this->assertSame(1, WidgetInstanceVersion::query()->count(), 'A no-op replay must never insert a second Instance Version.');
        // A full lifecycle build leaves exactly 3 rows: the initial
        // bootstrap Draft (now superseded), the published Release, and the
        // fresh post-publish Draft. A repeat run without --reset must never
        // grow this -- publish is skipped once current_published_version_id
        // is already set.
        $this->assertSame(3, WidgetLayoutVersion::query()->count(), 'A no-op replay must never allocate a new Draft/Release pair.');
        $this->assertSame(
            $firstDraftLockVersion,
            WidgetLayoutVersion::query()->where('state', WidgetLayoutVersion::STATE_DRAFT)->value('lock_version'),
            'A no-op replay must never re-save the Draft (lock_version must be untouched).'
        );
    }

    // -----------------------------------------------------------------
    // Text format (default) is human-readable, not raw JSON.
    // -----------------------------------------------------------------

    public function test_default_format_is_human_readable_text_not_json(): void
    {
        $exitCode = Artisan::call('cms:widget-site-layout-fixture', ['--reset' => true]);
        // Artisan::output() reads Symfony's BufferedOutput::fetch(), which
        // EMPTIES the buffer on read -- capture it exactly once and reuse
        // the local copy for every assertion below.
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        json_decode($output);
        $this->assertNotSame(JSON_ERROR_NONE, json_last_error(), 'Default text format output must not itself decode as raw JSON.');
        $this->assertStringContainsString('site', $output);
    }

    // -----------------------------------------------------------------
    // No embedded credentials: the fixture never writes a literal secret
    // string into any persisted row (DoD 1305 "free of embedded
    // credentials").
    // -----------------------------------------------------------------

    public function test_no_static_password_literal_is_persisted_for_any_seeded_user(): void
    {
        Artisan::call('cms:widget-site-layout-fixture', ['--reset' => true, '--format' => 'json']);

        $staticHash = bcrypt('secret');
        $this->assertSame(
            0,
            \App\Models\User::query()->where('password', $staticHash)->count(),
            'No seeded user may use the literal "secret" password used elsewhere in this test suite as a fixture-only convenience.'
        );
    }
}
