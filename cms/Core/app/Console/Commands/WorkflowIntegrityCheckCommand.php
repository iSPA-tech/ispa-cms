<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Services\Workflow\Exceptions\WorkflowIntegrityException;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectNotPublished;
use Cms\Core\Services\Workflow\Versioning\RunGraph;
use Cms\Core\Services\Workflow\Versioning\WorkflowIntegrity;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Illuminate\Console\Command;

/**
 * Re-verify every workflow version that can still run (guardrails B2): the published version of every active project
 * and the version of every run that has not finished.
 *
 * On a mismatch it does what the executor does: the project is turned off (`paused_reason = integrity`),
 * WorkflowIntegrityViolated is dispatched, and the unfinished runs on that version that are not executing right now
 * fail with `integrity_mismatch` (a run executing now is refused by the executor at its next step batch).
 *
 * It also lists the module workflow nodes the node registry dropped (bad name, invalid definition, duplicate type;
 * NodeRegistry::rejected()): a workflow that uses one fails as an unknown step type, so a dropped node is a failure too.
 * Exit 1 when anything failed.
 *
 * Usage: php artisan cms:workflow-integrity-check   (scheduled daily 02:15 by app/Console/Kernel.php)
 *
 * @package Cms\Core\Console\Commands
 * @since   2026-11-24
 */
class WorkflowIntegrityCheckCommand extends Command
{
    /** Instance statuses after which a run never executes another step. */
    public const TERMINAL = ['completed', 'failed', 'cancelled', 'rejected', 'approval_rejected', 'budget_exceeded', 'success'];

    protected $signature = 'cms:workflow-integrity-check';

    protected $description = 'Verify the content hash and seal of every workflow version that can still run.';

    public function handle(): int
    {
        $failed = 0;
        $checked = 0;
        $seen = [];

        $projects = WorkflowProject::query()->where('is_active', true)->orderBy('id')->get();
        foreach ($projects as $project) {
            try {
                $version = RunGraph::published($project);
                $seen[(int) $version->id] = true;
                $checked++;
            } catch (WorkflowIntegrityException $e) {
                $failed++;
                $this->handleMismatch($e);
            } catch (WorkflowProjectNotPublished) {
                // Active without a published version: it can not start a run (fail closed); worth a look.
                $this->warn("Project #{$project->id} is active but has no published version.");
            }
        }

        $versionIds = WorkflowInstance::query()
            ->whereNotIn('status', self::TERMINAL)
            ->whereNotNull('version_id')
            ->distinct()
            ->pluck('version_id');

        foreach ($versionIds as $versionId) {
            $versionId = (int) $versionId;
            if (isset($seen[$versionId])) {
                continue;
            }
            $seen[$versionId] = true;
            $checked++;

            $version = WorkflowVersion::query()->find($versionId);
            $projectId = (int) WorkflowInstance::query()->where('version_id', $versionId)->value('project_id');
            try {
                if (!$version) {
                    throw new WorkflowIntegrityException($projectId, $versionId, 'version_missing');
                }
                RunGraph::verify($version);
            } catch (WorkflowIntegrityException $e) {
                $failed++;
                $this->handleMismatch($e);
            }
        }

        $unversioned = WorkflowInstance::query()->whereNotIn('status', self::TERMINAL)->whereNull('version_id')
            ->whereNotNull('project_id')->count();
        if ($unversioned > 0) {
            $this->warn("{$unversioned} unfinished run(s) have no version; they are refused when they resume.");
        }

        $this->line("Checked {$checked} workflow version(s); {$failed} failed.");

        $dropped = NodeRegistry::current()->rejected();
        foreach ($dropped as $item) {
            $where = $item['class'] . ($item['method'] !== null ? '::' . $item['method'] : '');
            $this->error('Workflow node ' . ($item['type'] ?? '(whole service)') . " not registered ({$where}): {$item['reason']}");
        }
        if ($dropped !== []) {
            $this->line(count($dropped) . ' workflow node(s) could not be registered; workflows that use them fail.');
        }

        return $failed > 0 || $dropped !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Pause the project, fail the waiting runs on that version, dispatch the event.
     */
    protected function handleMismatch(WorkflowIntegrityException $e): void
    {
        $this->error("Project #{$e->projectId}, version #{$e->versionId}: {$e->reason}. The project was turned off.");

        if ($e->versionId !== null) {
            $instances = WorkflowInstance::query()
                ->where('version_id', $e->versionId)
                ->whereNotIn('status', array_merge(self::TERMINAL, ['running']))
                ->get();
            foreach ($instances as $instance) {
                $instance->forceFill(['status' => 'failed', 'resume_step_id' => null, 'finished_at' => now()])->save();
                WorkflowInstanceStep::query()->create([
                    'instance_id' => $instance->id,
                    'step_id' => null,
                    'status' => 'failed',
                    'started_at' => now(),
                    'finished_at' => now(),
                    'error_message' => WorkflowIntegrityException::CODE,
                ]);
            }
        }

        WorkflowIntegrity::violated($e, null, 'integrity_check');
    }
}
