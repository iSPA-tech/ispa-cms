<?php

declare(strict_types=1);

namespace Cms\Core\Listeners;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Guard\WorkflowChangeAlerter;
use Throwable;

/**
 * Sends the `integrity_violated` alert when a published workflow version fails its hash or seal check (roadmap B2/B5).
 * Listens for `WorkflowIntegrityViolated`, dispatched by the executor and `cms:workflow-integrity-check` (lane 1),
 * which already paused the project. Reads the project from the event's `project` (model) or `projectId`/`project_id`,
 * and the run from `instance` or `instanceId`, whichever the event carries.
 *
 * @package Cms\Core\Listeners
 * @since   2026-11-24
 */
class AlertWorkflowIntegrityViolation
{
    public function __construct(private readonly WorkflowChangeAlerter $alerter)
    {
    }

    public function handle(object $event): void
    {
        try {
            $project = $this->project($event);
            $instance = $event->instance ?? null;
            $instanceId = $instance instanceof WorkflowInstance ? $instance->getKey() : ($event->instanceId ?? $event->instance_id ?? null);

            $this->alerter->notify('integrity_violated', $project, [
                'reason' => is_string($event->reason ?? null) ? $event->reason : 'integrity',
                'instance_id' => is_numeric($instanceId) ? (int) $instanceId : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function project(object $event): ?WorkflowProject
    {
        $project = $event->project ?? null;
        if ($project instanceof WorkflowProject) {
            return $project;
        }

        $id = $event->projectId ?? $event->project_id ?? null;
        if (!is_numeric($id) && ($event->instance ?? null) instanceof WorkflowInstance) {
            $id = $event->instance->project_id;
        }

        return is_numeric($id) ? WorkflowProject::query()->find((int) $id) : null;
    }
}
