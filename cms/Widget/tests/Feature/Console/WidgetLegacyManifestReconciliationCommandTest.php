<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Console;

use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Widget\Console\Commands\WidgetLegacyManifestReconciliationCommand;
use Cms\Widget\Console\Commands\WidgetRegistryPreflightCommand;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Chunk 2 Task 15a: {@see WidgetLegacyManifestReconciliationCommand}
 * (`cms:widget-manifests-reconcile`), plus D-047 cumulative
 * `WidgetServiceProvider::registerCommands()` discoverability (checklist
 * item 5: "Its test first asserts discovery through Artisan::all()/artisan
 * list --raw" -- the same precedent WidgetRegistryPreflightCommandTest
 * established for Task 15, mirrored here for both commands together).
 *
 * Boots the real WidgetServiceProvider (so command discovery/registration
 * is exercised faithfully) but rebinds FilesystemWidgetArtifactBlobStore to
 * an isolated temporary root AFTER boot -- the production binding points at
 * a shared `storage_path('app/widget_artifacts')`, and this command
 * genuinely writes Blob bytes (unlike WidgetRegistryPreflightCommand, which
 * never touches artifact storage), so behavioral tests must not pollute a
 * real shared directory. The rebind lands before anything in this module
 * ever resolves the singleton, so it takes effect for every command run
 * below.
 */
final class WidgetLegacyManifestReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    /**
     * See WidgetPermissionSeederTest::beforeRefreshingDatabase() (Task 14):
     * this test also needs the root/Core widgets/widget_themes tables, so
     * it deliberately does NOT override migrateFreshUsing() with an
     * explicit `--path` (which would replace, not add to, the default
     * migration path set).
     */
    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = storage_path('framework/testing/widget-manifest-reconcile-' . bin2hex(random_bytes(6)));

        $this->bootWidgetServiceProvider();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );
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

    private function bootWidgetServiceProvider(): void
    {
        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }

    private function createWidget(string $js = ''): Widget
    {
        return Widget::query()->create(['name' => 'Test Widget', 'description' => '', 'js' => $js]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createTheme(Widget $widget, array $overrides = []): WidgetTheme
    {
        return WidgetTheme::query()->create(array_merge([
            'widget_id' => $widget->id,
            'name' => 'Test Theme',
            'content' => '<div class="card">{title}</div>',
            'css' => '.card { color: red; }',
            'project_name' => ['ExampleProject'],
        ], $overrides));
    }

    // ===================================================================
    // D-047: cumulative registerCommands() discovery, asserted BEFORE
    // behavior (mirrors WidgetRegistryPreflightCommandTest's own ordering).
    // ===================================================================

    public function test_both_widget_commands_are_discoverable_through_artisan_all(): void
    {
        $registered = Artisan::all();

        $this->assertArrayHasKey(
            'cms:widget-registry-preflight',
            $registered,
            'Appending the reconciliation command must never replace the existing registry preflight entry.'
        );
        $this->assertArrayHasKey('cms:widget-manifests-reconcile', $registered);
    }

    public function test_the_registry_preflight_command_still_resolves_correctly_after_the_append(): void
    {
        $this->assertInstanceOf(WidgetRegistryPreflightCommand::class, app(WidgetRegistryPreflightCommand::class));
    }

    // ===================================================================
    // Behavior: created / reused / skipped tallying, full scan
    // ===================================================================

    public function test_reconcile_creates_a_pending_manifest_for_every_currently_known_pair_across_projects(): void
    {
        $widgetA = $this->createWidget();
        $this->createTheme($widgetA, ['project_name' => ['ExampleProject']]);
        $widgetB = $this->createWidget();
        $this->createTheme($widgetB, ['project_name' => ['ProjectB'], 'content' => '<div>distinct B</div>']);

        $exitCode = Artisan::call('cms:widget-manifests-reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('2 created', $output);
        $this->assertSame(1, WidgetArtifactManifestModel::query()->where('project_name', 'ExampleProject')->count());
        $this->assertSame(1, WidgetArtifactManifestModel::query()->where('project_name', 'ProjectB')->count());
    }

    public function test_reconcile_reuses_existing_manifests_on_a_second_run_without_creating_duplicates(): void
    {
        $widget = $this->createWidget();
        $this->createTheme($widget, ['project_name' => ['ExampleProject']]);

        Artisan::call('cms:widget-manifests-reconcile');
        $exitCode = Artisan::call('cms:widget-manifests-reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 reused', $output);
        $this->assertStringContainsString('0 created', $output);
        $this->assertSame(1, WidgetArtifactManifestModel::query()->count(), 'A repeated run must never create a duplicate row.');
    }

    public function test_reconcile_is_repeatable_across_three_consecutive_runs(): void
    {
        $widget = $this->createWidget();
        $this->createTheme($widget, ['project_name' => ['ExampleProject']]);

        Artisan::call('cms:widget-manifests-reconcile');
        Artisan::call('cms:widget-manifests-reconcile');
        $thirdExitCode = Artisan::call('cms:widget-manifests-reconcile');

        $this->assertSame(0, $thirdExitCode);
        $this->assertSame(1, WidgetArtifactManifestModel::query()->count());
    }

    public function test_reconcile_never_approves_a_manifest(): void
    {
        $widget = $this->createWidget();
        $this->createTheme($widget, ['project_name' => ['ExampleProject']]);

        Artisan::call('cms:widget-manifests-reconcile');

        $row = WidgetArtifactManifestModel::query()->where('project_name', 'ExampleProject')->firstOrFail();
        $this->assertSame('pending', $row->eligibility_state);
        $this->assertNull($row->approved_by);
        $this->assertNull($row->approved_at);
    }

    // ===================================================================
    // Missed-event reconciliation: this command discovers and materializes
    // pairs regardless of whether LegacyWidgetDefinitionChanged ever fired
    // for them (e.g. a row created directly, bypassing the event entirely).
    // ===================================================================

    public function test_reconcile_materializes_a_pair_the_listener_never_touched(): void
    {
        // Created directly via Eloquent -- no WidgetDefinitionSyncService
        // call, no event dispatch, no listener invocation of any kind.
        $widget = $this->createWidget();
        $this->createTheme($widget, ['project_name' => ['ExampleProject']]);

        $this->assertSame(0, WidgetArtifactManifestModel::query()->count(), 'Precondition: nothing materialized this pair yet.');

        Artisan::call('cms:widget-manifests-reconcile');

        $this->assertSame(
            1,
            WidgetArtifactManifestModel::query()->where('project_name', 'ExampleProject')->count(),
            'The reconciliation command must discover and materialize a pair regardless of event history.'
        );
    }

    public function test_reconcile_reports_zero_activity_when_nothing_is_known(): void
    {
        $exitCode = Artisan::call('cms:widget-manifests-reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('0 created, 0 reused, 0 skipped, 0 incident(s)', $output);
    }

    // ===================================================================
    // Incident path: always SUCCESS with a counted, class-name-only
    // incident line -- an operator retry/health tool never fails the
    // command on a per-pair error, and never leaks source in the line.
    // ===================================================================

    public function test_reconcile_reports_incidents_but_still_exits_success_and_never_leaks_source(): void
    {
        $widget = $this->createWidget();
        $this->createTheme($widget, ['project_name' => ['ExampleProject'], 'content' => 'SECRET_CONTENT_MARKER_XYZ']);

        // Rebind the Blob store to a plain FILE root so every pair's write()
        // throws a genuine RuntimeException. Nothing has resolved the
        // repository yet in this test, so the rebind takes effect for the
        // command's own container-resolved materializer.
        $brokenRoot = $this->storageRoot . '-broken-file';
        file_put_contents($brokenRoot, 'not a directory');
        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            static fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($brokenRoot)
        );

        try {
            $exitCode = Artisan::call('cms:widget-manifests-reconcile');
            $output = Artisan::output();
        } finally {
            @unlink($brokenRoot);
        }

        // Always SUCCESS: a reconciliation/health tool records incidents,
        // it does not fail the command (contrast cms:widget-registry-preflight,
        // a deploy GATE that does exit non-zero).
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 incident(s)', $output);
        // The incident line is `widget=.. theme=.. project=..: <ClassName>`
        // -- the exception CLASS name only. Pin BOTH the safe format prefix
        // and, crucially, that neither the exception MESSAGE nor any theme
        // source ever appears (a real filesystem exception message would
        // contain "mkdir"/the broken path; a leak would contain the marker).
        $this->assertStringContainsString('project=ExampleProject:', $output);
        $this->assertMatchesRegularExpression('/project=ExampleProject:\s+\S*Exception/', $output, 'The incident line must end with an exception class name.');
        $this->assertStringNotContainsString('SECRET_CONTENT_MARKER_XYZ', $output, 'Theme source must never appear in incident output.');
        $this->assertStringNotContainsString('mkdir', $output, 'The exception MESSAGE (not just the class) must never be emitted.');
        $this->assertStringNotContainsString($brokenRoot, $output, 'No storage path may leak into incident output.');
        $this->assertSame(0, WidgetArtifactManifestModel::query()->count(), 'Every pair failed to persist; no partial rows.');
    }
}
