<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Database;

use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactBlobRepository;
use Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactManifestRepository;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobRepository;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactManifestRepository;
use Cms\Widget\Tests\Support\WidgetArtifactRepositoryContractAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chunk 2 Task 13b: runs the EXACT SAME contract suite Task 10 proved
 * against in-memory fakes
 * ({@see \Cms\Widget\Tests\Unit\Rendering\WidgetArtifactRepositoryTest})
 * against the real {@see DatabaseWidgetArtifactBlobRepository} +
 * {@see DatabaseWidgetArtifactManifestRepository} + real
 * {@see FilesystemWidgetArtifactBlobStore}, satisfying both classes'
 * docblock obligation ("Task 13b's real database/storage adapter must
 * satisfy the same behavior contract").
 *
 * Every assertion body lives in the shared
 * {@see WidgetArtifactRepositoryContractAssertions} trait; this class
 * supplies only the four factory/row-count hooks plus the real DB/disk
 * plumbing (migrations, RefreshDatabase, a disposable temp storage root).
 */
final class WidgetArtifactBlobRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use WidgetArtifactRepositoryContractAssertions;

    private string $storageRoot;

    private FilesystemWidgetArtifactBlobStore $filesystemStore;

    /**
     * See WidgetSiteLayoutSchemaTest::beforeRefreshingDatabase() (Task 13a)
     * for the full explanation: the Widget module stays disabled by design,
     * so its migration path must be registered manually before
     * RefreshDatabase's migrate:fresh runs.
     */
    protected function beforeRefreshingDatabase(): void
    {
        // Force a real migrate:fresh for THIS class regardless of what any
        // earlier-running, non-Widget-scoped RefreshDatabase test left the
        // process-wide RefreshDatabaseState::$migrated flag set to -- Laravel
        // checks that flag AFTER calling this hook
        // (RefreshDatabase::refreshTestDatabase()), so resetting it here
        // (before the module migration path is registered) guarantees this
        // class never silently skips migrating cms/Widget's own tables
        // because some unrelated class already flipped $migrated=true via
        // the app's DEFAULT (non-Widget) migration path. Same root cause as
        // WidgetSiteLayoutSchemaTest::tearDownAfterClass()'s docblock (Task
        // 13a) describes for the REVERSE direction (this class polluting a
        // LATER one); this is the missing complementary half for the case
        // where an EARLIER class pollutes THIS one -- discovered empirically
        // when this file's Unit/Support sibling ran after Feature/Security's
        // WidgetPilotAuthorizationContractTest in a full-module run.
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    /**
     * See WidgetSiteLayoutSchemaTest::migrateFreshUsing() (Task 13a) for the
     * full explanation: scope migrate:fresh to only this module's migrations.
     */
    protected function migrateFreshUsing()
    {
        return [
            '--path' => 'cms/Widget/database/migrations',
            '--realpath' => false,
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => $this->shouldSeed(),
        ];
    }

    /**
     * See WidgetSiteLayoutSchemaTest::tearDownAfterClass() (Task 13a) for
     * the full explanation: reset the process-wide
     * RefreshDatabaseState::$migrated flag so this module-scoped
     * migrate:fresh never leaks into whichever unrelated test class runs
     * next in the same process.
     */
    public static function tearDownAfterClass(): void
    {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = storage_path('framework/testing/widget-artifacts-' . bin2hex(random_bytes(6)));
        $this->filesystemStore = new FilesystemWidgetArtifactBlobStore($this->storageRoot);

        $this->initializeArtifactRepositoryContract();
    }

    protected function tearDown(): void
    {
        $this->removeTempStorageRoot();

        parent::tearDown();
    }

    protected function createBlobRepositoryUnderTest(): WidgetArtifactBlobRepository
    {
        return new DatabaseWidgetArtifactBlobRepository($this->filesystemStore);
    }

    protected function createManifestRepositoryUnderTest(): WidgetArtifactManifestRepository
    {
        return new DatabaseWidgetArtifactManifestRepository();
    }

    protected function countBlobRowsForProject(string $projectName): int
    {
        return WidgetArtifactBlob::query()->where('project_name', $projectName)->count();
    }

    protected function countManifestRowsForProject(string $projectName): int
    {
        return WidgetArtifactManifestModel::query()->where('project_name', $projectName)->count();
    }

    private function removeTempStorageRoot(): void
    {
        if (!isset($this->storageRoot) || !is_dir($this->storageRoot)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($this->storageRoot);
    }
}
