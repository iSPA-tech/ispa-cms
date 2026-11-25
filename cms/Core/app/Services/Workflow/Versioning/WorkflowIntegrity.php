<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Versioning;

use Cms\Core\Events\WorkflowIntegrityViolated;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Exceptions\WorkflowIntegrityException;
use Illuminate\Support\Facades\Log;

/**
 * What happens when a workflow version fails its integrity check (B2), wherever it is detected (run start, executor,
 * nightly cms:workflow-integrity-check): the project is turned off (`is_active = false`, `paused_reason = integrity`,
 * `paused_at`), an error is logged and WorkflowIntegrityViolated is dispatched (lane 2 alerts people from it).
 * Failing the run itself is the caller's part.
 *
 * @package Cms\Core\Services\Workflow\Versioning
 * @since   2026-11-24
 */
final class WorkflowIntegrity
{
    public const PAUSED_REASON = 'integrity';

    public static function violated(WorkflowIntegrityException $e, ?int $instanceId, string $detected): void
    {
        $project = WorkflowProject::query()->find($e->projectId);
        if ($project) {
            // A model save, so the trigger index is cleared and the project observer audits the change.
            $project->forceFill([
                'is_active' => false,
                'paused_reason' => self::PAUSED_REASON,
                'paused_at' => now(),
            ])->save();
        }

        Log::error('Workflow version failed its integrity check; the project was turned off.', [
            'project_id' => $e->projectId,
            'version_id' => $e->versionId,
            'instance_id' => $instanceId,
            'reason' => $e->reason,
            'detected' => $detected,
        ]);

        event(new WorkflowIntegrityViolated($e->projectId, $e->versionId, $instanceId, $e->reason, $detected));
    }
}
