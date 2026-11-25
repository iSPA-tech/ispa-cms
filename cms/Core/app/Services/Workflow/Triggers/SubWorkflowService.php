<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Calls one workflow project from another (`execute_workflow` → `trigger_sub_workflow`).
 *
 * The child runs as its own instance, acting for the parent run's user, with the parent's chain (loop and depth
 * guard, TriggerChain). In `wait` mode the parent pauses as `awaiting_child`; when the child finishes,
 * WorkflowInstance's `updated` event queues ResumeParentWorkflowJob, which writes the child's result into the parent's
 * `storeAs` var and continues the parent (or fails it when the child did not complete).
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
class SubWorkflowService
{
    public const AWAITING_STATUS = 'awaiting_child';
    public const AWAITING_VAR = '__awaiting_child';

    public function __construct(protected WorkflowProjectRunService $runService)
    {
    }

    /**
     * Resolve the child project by id or slug; it must be active and start with `trigger_sub_workflow`.
     *
     * @throws WorkflowTriggerRefused
     */
    public function resolveChild(string|int $reference): WorkflowProject
    {
        $reference = is_string($reference) ? trim($reference) : $reference;
        $child = (is_int($reference) || ctype_digit((string) $reference))
            ? WorkflowProject::query()->find((int) $reference)
            : WorkflowProject::query()->where('slug', (string) $reference)->first();

        if (! $child) {
            throw new WorkflowTriggerRefused('sub_workflow_not_found');
        }

        if (! WorkflowTriggers::publishedStartsWith($child, WorkflowTriggers::SUB_WORKFLOW)) {
            throw new WorkflowTriggerRefused('wrong_trigger');
        }

        return $child;
    }

    /**
     * Start the child run for $parent.
     *
     * @param array<string, mixed> $input
     */
    public function start(WorkflowInstance $parent, WorkflowProject $child, array $input, string $mode, string $storePath): WorkflowInstance
    {
        $actor = $parent->delegatedBy()->first();
        if (! $actor) {
            throw new WorkflowTriggerRefused('actor_not_allowed');
        }

        return $this->runService->startTriggered($child, 'sub_workflow', [
            'input' => $input,
            'parent_instance_id' => $parent->id,
            'mode' => $mode,
            'store_path' => $storePath,
        ], TriggerChain::of($parent), $actor);
    }

    /**
     * Whether a run has ended (completed, failed, cancelled, terminated): finished_at is set only then.
     */
    public static function isFinished(WorkflowInstance $instance): bool
    {
        return $instance->finished_at !== null
            && ! in_array($instance->status, ['pending', 'queued', 'running', 'waiting', 'cancelling', 'applied'], true);
    }

    /**
     * What the parent receives from a finished child: its status and its vars (internal `__*` vars left out).
     *
     * @return array<string, mixed>
     */
    public function result(WorkflowInstance $child): array
    {
        $vars = (array) data_get($child->context_snapshot, 'vars', []);
        $vars = array_filter($vars, static fn ($key) => ! str_starts_with((string) $key, '__'), ARRAY_FILTER_USE_KEY);

        return [
            'instance_id' => $child->id,
            'status' => $child->status,
            'vars' => WorkflowTriggers::capPayload($vars),
        ];
    }

    /**
     * Continue a parent waiting for $childId.
     *
     * @return string resumed | completed | failed | not_ready (parent has not paused yet) | ignored
     */
    public function resumeParent(int $childId): string
    {
        $child = WorkflowInstance::query()->find($childId);
        if (! $child || ! self::isFinished($child) || data_get($child->context_snapshot, 'trigger.meta.mode') !== 'wait') {
            return 'ignored';
        }

        $parentId = (int) data_get($child->context_snapshot, 'trigger.meta.parent_instance_id');
        if ($parentId <= 0) {
            return 'ignored';
        }

        $outcome = DB::transaction(function () use ($parentId, $child) {
            $parent = WorkflowInstance::query()->lockForUpdate()->find($parentId);
            if (! $parent) {
                return 'ignored';
            }

            // Still running the step that started the child (another worker): try again shortly.
            if (in_array($parent->status, ['pending', 'queued', 'running'], true)) {
                return 'not_ready';
            }

            $snapshot = (array) $parent->context_snapshot;
            $awaiting = data_get($snapshot, 'vars.' . self::AWAITING_VAR);
            if ($parent->status !== self::AWAITING_STATUS || (int) data_get($awaiting, 'instance_id') !== $child->id) {
                return 'ignored';
            }

            $vars = (array) ($snapshot['vars'] ?? []);
            unset($vars[self::AWAITING_VAR]);
            $storePath = (string) data_get($awaiting, 'store_path', 'child');
            Arr::set($vars, $storePath, $this->result($child));
            $snapshot['vars'] = $vars;
            $parent->context_snapshot = $snapshot;

            if ($child->status !== 'completed') {
                $parent->forceFill(['status' => 'failed', 'resume_step_id' => null, 'finished_at' => now()])->save();
                WorkflowInstanceStep::query()->create([
                    'instance_id' => $parent->id,
                    'step_id' => null,
                    'status' => 'failed',
                    'started_at' => now(),
                    'finished_at' => now(),
                    'error_message' => 'sub_workflow_failed: ' . $child->status,
                ]);

                return 'failed';
            }

            if ($parent->resume_step_id === null) {
                // The call was the last step: nothing left to run.
                $parent->forceFill(['status' => 'completed', 'finished_at' => now()])->save();

                return 'completed';
            }

            $parent->forceFill(['status' => 'waiting', 'finished_at' => null])->save();

            return 'resumed';
        });

        if ($outcome === 'resumed') {
            RunWorkflowInstanceJob::dispatch($parentId);
        }

        return $outcome;
    }
}
