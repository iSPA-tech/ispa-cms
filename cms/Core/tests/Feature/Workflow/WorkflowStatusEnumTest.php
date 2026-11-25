<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Services\Workflow\Triggers\SubWorkflowService;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Cms\Core\Tests\TestCase;
use Cms\Core\Workflows\Runtime\Runners\Human\WorkflowApprovalGate;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `status` columns of workflow_instances / workflow_instance_steps are an ENUM on MySQL/MariaDB and a CHECK
 * constraint on PostgreSQL; SQLite does not enforce either. Every status the code writes must be in the list of the
 * latest migration that sets those lists (review finding 9: `cancelling` and `awaiting_child` were missing).
 */
class WorkflowStatusEnumTest extends TestCase
{
    /** Instance statuses written by literal (executor, run control, sub-workflows, stale sweep, budget, terminate). */
    private const INSTANCE_LITERALS = [
        'pending', 'running', 'waiting', 'completed', 'failed', 'cancelled', 'cancelling',
        'budget_exceeded', 'success', 'awaiting_approval', 'awaiting_apply', 'applied',
    ];

    /** Step statuses: StepRunResult kinds, paused statuses, run control, terminal rows written by the executor. */
    private const STEP_LITERALS = [
        'pending', 'running', 'success', 'failed', 'skipped', 'cancelled', 'cancelling', 'rescheduled',
        'budget_exceeded', 'awaiting_approval', 'awaiting_child',
    ];

    public function test_latest_status_migration_lists_every_status_the_code_writes(): void
    {
        [$instanceStatuses, $stepStatuses] = $this->latestMigrationLists();

        $instanceWritten = array_unique(array_merge(
            self::INSTANCE_LITERALS,
            WorkflowGraphExecutor::CLAIMABLE_STATUSES,
            WorkflowProjectRunService::IN_PROGRESS_STATUSES,
            [SubWorkflowService::AWAITING_STATUS, WorkflowApprovalGate::PAUSED_STATUS],
        ));
        $stepWritten = array_unique(array_merge(
            self::STEP_LITERALS,
            [SubWorkflowService::AWAITING_STATUS, WorkflowApprovalGate::PAUSED_STATUS],
        ));

        $this->assertSame([], array_values(array_diff($instanceWritten, $instanceStatuses)), 'workflow_instances.status misses');
        $this->assertSame([], array_values(array_diff($stepWritten, $stepStatuses)), 'workflow_instance_steps.status misses');
    }

    public function test_widening_migration_runs_up_and_down_on_sqlite_without_rebuilding_the_tables(): void
    {
        $path = $this->migrationPath('2026_11_25_000100_add_cancelling_and_awaiting_child_workflow_statuses.php');
        $this->assertFileExists($path);
        $this->assertSame('sqlite', DB::getDriverName());

        Schema::dropIfExists('workflow_instances');
        Schema::create('workflow_instances', fn ($t) => [$t->id(), $t->string('status')->default('pending')]);
        $before = DB::table('sqlite_master')->where('name', 'workflow_instances')->value('sql');

        $migration = require $path;
        $migration->up();
        $migration->down();
        $migration->up();

        // A rebuild would re-create workflow_instance_steps with its stale step_id foreign key (DemoWorkflowSampleTest).
        $this->assertSame($before, DB::table('sqlite_master')->where('name', 'workflow_instances')->value('sql'));
        DB::table('workflow_instances')->insert(['status' => 'awaiting_child']);
        $this->assertSame('awaiting_child', DB::table('workflow_instances')->value('status'));
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function latestMigrationLists(): array
    {
        $files = glob($this->migrationPath('*.php')) ?: [];
        sort($files);
        $lists = null;
        foreach ($files as $file) {
            if (! str_contains((string) file_get_contents($file), 'INSTANCE_STATUSES')) {
                continue;
            }
            $reflection = new \ReflectionClass(require $file);
            $lists = [
                (array) $reflection->getConstant('INSTANCE_STATUSES'),
                (array) $reflection->getConstant('STEP_STATUSES'),
            ];
        }
        $this->assertNotNull($lists, 'no migration sets the workflow status lists');

        return $lists;
    }

    private function migrationPath(string $file): string
    {
        return dirname(__DIR__, 3) . '/database/migrations/' . $file;
    }
}
