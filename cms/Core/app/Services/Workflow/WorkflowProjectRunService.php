<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Workflow;

use App\Constants\UserType;
use App\Models\User;
use App\Support\TokenGate;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Services\Agentic\AgentScheduleRuntimeSyncService;
use Cms\Core\Services\Workflow\Exceptions\WorkflowAlreadyRunning;
use Cms\Core\Services\Workflow\Exceptions\WorkflowBreakerTripped;
use Cms\Core\Services\Workflow\Exceptions\WorkflowGloballyPaused;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectInactive;
use Cms\Core\Services\Workflow\Guard\WorkflowCircuitBreaker;
use Cms\Core\Services\Workflow\Guard\WorkflowGlobalPause;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectNotRunnable;
use Cms\Core\Services\Workflow\Exceptions\WorkflowIntegrityException;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectNotPublished;
use Cms\Core\Services\Workflow\Versioning\RunGraph;
use Cms\Core\Services\Workflow\Versioning\WorkflowIntegrity;
use Cms\Core\Services\Workflow\Triggers\ErrorWorkflowLauncher;
use Cms\Core\Services\Workflow\Triggers\SubWorkflowService;
use Cms\Core\Services\Workflow\Triggers\TriggerChain;
use Cms\Core\Services\Workflow\Triggers\WorkflowTriggerRefused;
use Cms\Core\Services\Workflow\Triggers\WorkflowTriggers;
use Illuminate\Support\Facades\Gate;

/**
 * Starts runs (instances) of authored workflow projects: by hand from the
 * admin, from artisan, or from the project's cron schedule.
 *
 * A project runs only while it is active, never for a system-managed agent
 * schedule wrapper, and never while another run of it is still in progress
 * (see IN_PROGRESS_STATUSES). A run paused for approval does not block the next
 * one. The check and the insert happen while the project row is
 * locked, so two starts at the same moment create one instance.
 *
 * @see \Cms\Core\Jobs\RunWorkflowInstanceJob
 * @package Cms\Core\Services\Workflow
 * @author  iSPA Team
 * @since   2026.11 (2026-11-24)
 */
class WorkflowProjectRunService
{
    /**
     * Instance statuses that mean a run is still in progress: started, delayed or retrying (`waiting`), queued,
     * resuming after an applied approval, waiting for a sub-workflow, or stopping (review finding 18).
     * `awaiting_approval` is left out on purpose: a run waiting for a person does not block the next one.
     */
    public const IN_PROGRESS_STATUSES = ['pending', 'queued', 'running', 'waiting', 'applied', 'awaiting_child', 'cancelling'];

    /**
     * Start a run of the project acting for $actor and queue it.
     *
     * @param WorkflowProject       $project The project to run.
     * @param User                  $actor   User the run acts for (delegated_by_user_id).
     * @param array<string, mixed>  $trigger Extra trigger metadata stored under context_snapshot.trigger.meta.
     * @param string                $source  manual | cli | schedule | model_event | event | webhook | sub_workflow | error.
     * @param CarbonInterface|null  $scheduledAt Scheduled minute this run claims (schedule source only): checked and
     *                                           written in last_scheduled_run_at under the project lock, so two
     *                                           scheduler processes start one run per minute.
     * @return WorkflowInstance The new pending instance.
     *
     * @throws WorkflowProjectInactive    When the project is turned off.
     * @throws WorkflowGloballyPaused     While all workflows are paused (WorkflowGlobalPause).
     * @throws WorkflowBreakerTripped     When a safety limit of the project is reached (the breaker pauses it).
     * @throws WorkflowProjectNotPublished When the project has no published version (fail closed).
     * @throws WorkflowIntegrityException When the published version fails its integrity check (the project is paused).
     * @throws WorkflowProjectNotRunnable When the project is a system-managed wrapper.
     * @throws WorkflowAlreadyRunning     When a run of the project is still in progress, or this scheduled minute
     *                                    was already started.
     */
    public function start(WorkflowProject $project, User $actor, array $trigger = [], string $source = 'manual', ?CarbonInterface $scheduledAt = null): WorkflowInstance
    {
        if ($this->isSystemManaged($project)) {
            throw new WorkflowProjectNotRunnable();
        }

        // The source also goes into meta: the executor rewrites the trigger snapshot as {event, at, meta}.
        $trigger['source'] = $source;
        // Loop/depth guard data: the projects whose runs led to this one, this project last.
        $trigger['chain'] = isset($trigger['chain']) && is_array($trigger['chain'])
            ? array_values(array_map('intval', $trigger['chain']))
            : [(int) $project->getKey()];

        // Guardrails B7. The breaker limits are evaluated under the project lock (createInstance) and the pause is
        // written after the rollback, so it is not undone with the refused start.
        if (app(WorkflowGlobalPause::class)->active()) {
            throw new WorkflowGloballyPaused();
        }

        $breakerHit = null;
        try {
            $instance = DB::transaction(function () use ($project, $actor, $trigger, $source, $scheduledAt, &$breakerHit) {
                return $this->createInstance($project, $actor, $trigger, $source, $scheduledAt, $breakerHit);
            });
        } catch (WorkflowBreakerTripped $e) {
            if ($breakerHit !== null) {
                app(WorkflowCircuitBreaker::class)->trip($project, $breakerHit);
            }
            throw $e;
        } catch (WorkflowIntegrityException $e) {
            // Handled after the rollback so the pause is not undone with the refused start.
            WorkflowIntegrity::violated($e, null, 'start');
            throw $e;
        }

        RunWorkflowInstanceJob::dispatch($instance->id);

        return $instance;
    }

    /**
     * Create the pending instance under the project lock, on the project's published version (verified).
     *
     * @param array<string, mixed> $trigger
     * @param array<string, mixed>|null $breakerHit Set to the breaker limit that refused the start.
     */
    protected function createInstance(WorkflowProject $project, User $actor, array $trigger, string $source, ?CarbonInterface $scheduledAt, ?array &$breakerHit = null): WorkflowInstance
    {
        $locked = WorkflowProject::query()->lockForUpdate()->findOrFail($project->getKey());

        if (! $locked->is_active) {
            throw new WorkflowProjectInactive();
        }

        $breakerHit = app(WorkflowCircuitBreaker::class)->evaluate($locked);
        if ($breakerHit !== null) {
            throw new WorkflowBreakerTripped($breakerHit['reason']);
        }

        // Runs execute the published version only, verified before the run exists (fail closed).
        $version = RunGraph::published($locked);
        // A graph with a trigger node starts there; a legacy graph keeps the executor's own start pick.
        $startStepId = RunGraph::triggerStart(RunGraph::hydrate($version->graphData(), (int) $locked->getKey())['steps'])?->getKey();

        if ($this->hasRunInProgress($locked)) {
            throw new WorkflowAlreadyRunning();
        }

        if ($scheduledAt !== null) {
            // Compare the stored wall-clock minute, which is how the timestamp is written.
            $lastRun = (string) $locked->getRawOriginal('last_scheduled_run_at');
            if ($lastRun !== '' && substr($lastRun, 0, 16) === $scheduledAt->format('Y-m-d H:i')) {
                throw new WorkflowAlreadyRunning();
            }

            $locked->forceFill(['last_scheduled_run_at' => $scheduledAt->format('Y-m-d H:i:s')])->save();
        }

        return WorkflowInstance::query()->create([
            'project_id' => $locked->id,
            // No root record: authored project runs start from their trigger, not from a target row.
            'target_type' => WorkflowProject::class,
            'target_id' => null,
            'status' => 'pending',
            'resume_step_id' => $startStepId,
            'version_id' => $version->getKey(),
            'delegated_by_user_id' => $actor->getKey(),
            'context_snapshot' => [
                'trigger' => [
                    'event' => 'workflow.project.run',
                    'source' => $source,
                    'at' => now()->toISOString(),
                    'meta' => $trigger,
                ],
                'vars' => [],
            ],
        ]);
    }

    /**
     * Start a run from a trigger nobody clicked (model event, app event, webhook, sub-workflow, error workflow).
     *
     * The run acts for $actor when given (sub-workflows act for the parent run's user, who must be allowed to update
     * the child project), else for the project's scheduler, who must pass schedulerMayRun() like a scheduled run.
     * The loop/depth guard (TriggerChain) is checked against $parentChain, by default the chain of the run executing
     * in this process.
     *
     * @param WorkflowProject           $project     Project to run.
     * @param string                    $source      model_event | event | webhook | sub_workflow | error.
     * @param array<string, mixed>      $meta        Trigger payload stored under context_snapshot.trigger.meta.
     * @param list<int>|null            $parentChain Chain of the run that caused this one (null = current run).
     * @param User|null                 $actor       User the run acts for (null = the project's scheduler).
     * @return WorkflowInstance
     *
     * @throws WorkflowTriggerRefused     Loop, depth, or nobody allowed to act.
     * @throws WorkflowAlreadyRunning|WorkflowProjectInactive|WorkflowProjectNotRunnable As start().
     */
    public function startTriggered(WorkflowProject $project, string $source, array $meta = [], ?array $parentChain = null, ?User $actor = null): WorkflowInstance
    {
        $parentChain ??= TriggerChain::current();
        $refusal = TriggerChain::refusal((int) $project->getKey(), $parentChain);
        if ($refusal !== null) {
            throw new WorkflowTriggerRefused($refusal);
        }

        if ($actor !== null) {
            if (! $this->schedulerMayRun($actor) && ! Gate::forUser($actor)->allows('update', $project)) {
                throw new WorkflowTriggerRefused('actor_not_allowed');
            }
        } else {
            $actor = $project->scheduledBy()->first();
            if (! $actor || ! $this->schedulerMayRun($actor)) {
                throw new WorkflowTriggerRefused('scheduler_not_allowed');
            }
        }

        $meta['chain'] = TriggerChain::extend($parentChain, (int) $project->getKey());

        return $this->start($project, $actor, WorkflowTriggers::capPayload($meta) + ['chain' => $meta['chain']], $source);
    }

    /**
     * Start every scheduled project whose cron is due in the current minute, or whose last due minute a late pass
     * missed (bounded catch-up, see dueAt()).
     *
     * A project is started at most once per minute (last_scheduled_run_at,
     * claimed under the project lock), only for a scheduler who may still run
     * it (see schedulerMayRun()), and is skipped (not doubled) while a previous
     * run is in progress.
     *
     * @param CarbonInterface|null $now Current time (defaults to now()).
     * @return array{started: array<int, int>, skipped: array<int, string>} Project ids started, and skip reasons by project id.
     */
    public function runDue(?CarbonInterface $now = null): array
    {
        // Stored timestamps are written without a zone and read back in PHP's default zone.
        $now = ($now ? $now->copy() : now())->setTimezone(date_default_timezone_get());
        $started = [];
        $skipped = [];

        // The schedule and the start node come from the published version (a draft edit never changes them).
        $projects = WorkflowProject::query()
            ->whereNotNull('published_version_id')
            ->where('is_active', true)
            ->with(['scheduledBy', 'publishedVersion'])
            ->orderBy('id')
            ->get();

        foreach ($projects as $project) {
            $graph = $project->publishedVersion?->graphData() ?? [];
            $cron = (string) ($graph['settings']['schedule_cron'] ?? '');
            // Compare the stored wall-clock minute, which is how the timestamp was written.
            $lastRun = (string) $project->getRawOriginal('last_scheduled_run_at');
            $dueAt = $cron === '' ? null : $this->dueAt($cron, $graph['settings']['schedule_timezone'] ?? null, $now, $lastRun);
            if ($dueAt === null) {
                continue;
            }

            if ($lastRun !== '' && substr($lastRun, 0, 16) === $dueAt->format('Y-m-d H:i')) {
                continue;
            }

            // A graph whose start node is another trigger (webhook, model event, ...) never runs on the cron.
            $startType = RunGraph::triggerStart(RunGraph::hydrate($graph, (int) $project->id)['steps'])?->step_type;
            if ($startType !== null && $startType !== WorkflowTriggers::SCHEDULE) {
                $skipped[$project->id] = 'not_schedule_trigger';
                continue;
            }

            $actor = $project->scheduledBy;
            if (! $actor || ! $this->schedulerMayRun($actor)) {
                $skipped[$project->id] = 'scheduler_not_allowed';
                continue;
            }

            try {
                $this->start($project, $actor, ['cron' => $cron], 'schedule', $dueAt);
            } catch (WorkflowAlreadyRunning|WorkflowProjectInactive|WorkflowProjectNotRunnable $e) {
                $skipped[$project->id] = $e::CODE;
                continue;
            } catch (\Throwable $e) {
                Log::error('Scheduled workflow run failed to start', [
                    'project_id' => $project->id,
                    'error' => $e->getMessage(),
                ]);
                $skipped[$project->id] = 'start_failed';
                continue;
            }

            $started[] = $project->id;
        }

        return ['started' => $started, 'skipped' => $skipped];
    }

    /**
     * Fail runs stuck in `running` with no activity (instance or step row update) for
     * `core.workflow_schedule.stale_running_minutes`, as `stale_running`.
     *
     * A worker that dies mid-run leaves the instance `running`, which blocks every new run of the project. The status
     * change is conditional (still `running`, still idle), so a run that wakes up in between is left alone.
     *
     * @param CarbonInterface|null $now Current time (defaults to now()).
     * @return array<int, int> Ids of the instances failed.
     */
    public function failStaleRunning(?CarbonInterface $now = null): array
    {
        $now = ($now ? $now->copy() : now())->setTimezone(date_default_timezone_get());
        $cutoff = $now->copy()->subMinutes(max(1, (int) config('core.workflow_schedule.stale_running_minutes', 30)));
        $stepTable = (new WorkflowInstanceStep())->getTable();
        $instanceTable = (new WorkflowInstance())->getTable();

        $ids = WorkflowInstance::query()
            ->where('status', 'running')
            ->where('updated_at', '<', $cutoff)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from($stepTable)
                ->whereColumn($stepTable . '.instance_id', $instanceTable . '.id')
                ->where($stepTable . '.updated_at', '>=', $cutoff))
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->all();

        $failed = [];
        foreach ($ids as $id) {
            $claimed = WorkflowInstance::query()
                ->whereKey($id)
                ->where('status', 'running')
                ->where('updated_at', '<', $cutoff)
                ->update(['status' => 'failed', 'resume_step_id' => null, 'finished_at' => $now, 'updated_at' => $now]);

            if ($claimed !== 1) {
                continue;
            }

            WorkflowInstanceStep::query()->create([
                'instance_id' => $id,
                'step_id' => null,
                'status' => 'failed',
                'started_at' => $now,
                'finished_at' => $now,
                'error_message' => 'stale_running',
            ]);

            try {
                app(AgentScheduleRuntimeSyncService::class)->syncFromWorkflowInstance(WorkflowInstance::query()->find($id));
            } catch (\Throwable $e) {
                Log::warning('Stale workflow run failed but its agent schedule state was not synced', [
                    'instance_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }

            // A conditional update fires no model events: start the error workflow here.
            app(ErrorWorkflowLauncher::class)->instanceFailed((int) $id);

            $failed[] = (int) $id;
        }

        $this->settleOrphanedParents($cutoff);

        return $failed;
    }

    /**
     * Parents left in `awaiting_child` with no activity since $cutoff whose child already ended (ResumeParentWorkflowJob
     * gave up, e.g. the parent was still running all through its retries): hand them the child's result now, as the
     * job would have. A parent whose child row is gone fails as `sub_workflow_missing`. Parents of a child still
     * running are left alone, however long it takes (review finding 36).
     */
    protected function settleOrphanedParents(CarbonInterface $cutoff): void
    {
        $parents = WorkflowInstance::query()
            ->where('status', SubWorkflowService::AWAITING_STATUS)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'context_snapshot']);

        foreach ($parents as $parent) {
            $childId = (int) data_get($parent->context_snapshot, 'vars.' . SubWorkflowService::AWAITING_VAR . '.instance_id');
            $child = $childId > 0 ? WorkflowInstance::query()->find($childId) : null;

            try {
                if ($child === null) {
                    $gone = WorkflowInstance::query()->whereKey($parent->id)->where('status', SubWorkflowService::AWAITING_STATUS)
                        ->update(['status' => 'failed', 'resume_step_id' => null, 'finished_at' => now(), 'updated_at' => now()]);
                    if ($gone === 1) {
                        WorkflowInstanceStep::query()->create([
                            'instance_id' => $parent->id, 'step_id' => null, 'status' => 'failed',
                            'started_at' => now(), 'finished_at' => now(), 'error_message' => 'sub_workflow_missing',
                        ]);
                        app(ErrorWorkflowLauncher::class)->instanceFailed((int) $parent->id);
                    }
                } elseif (SubWorkflowService::isFinished($child)) {
                    app(SubWorkflowService::class)->resumeParent($childId);
                }
            } catch (\Throwable $e) {
                Log::warning('Waiting parent workflow run not settled', ['instance_id' => $parent->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Whether a scheduled run may still act for this user.
     *
     * The user must be active and not deleted, admin-eligible (webmaster or admin type, the rule of the `api.admin`
     * gate) and hold the `workflow_project.update` permission (or be the webmaster). The policy's owner shortcut does
     * not count: owning a project is not a right to keep running it unattended.
     *
     * @param User $user The scheduler.
     * @return bool
     */
    public function schedulerMayRun(User $user): bool
    {
        if (method_exists($user, 'trashed') && $user->trashed()) {
            return false;
        }

        if (! in_array((int) ($user->type ?? -1), [UserType::WEBMASTER, UserType::ADMIN], true)) {
            return false;
        }

        if (! TokenGate::ownerIsActive($user)) {
            return false;
        }

        return $user->isWebmaster() || $user->hasRole('workflow_project.update');
    }

    /**
     * Whether a run of the project is still in progress.
     */
    public function hasRunInProgress(WorkflowProject $project): bool
    {
        return WorkflowInstance::query()
            ->where('project_id', $project->getKey())
            ->whereIn('status', self::IN_PROGRESS_STATUSES)
            ->exists();
    }

    /**
     * Whether the project is a system-managed agent schedule wrapper.
     */
    public function isSystemManaged(WorkflowProject $project): bool
    {
        $tags = $project->tags;

        return is_array($tags)
            && (in_array('system-managed', $tags, true) || in_array('schedule-wrapper', $tags, true));
    }

    /**
     * The due minute a pass should start: $now when the cron is due now, else the cron's previous due minute when a
     * late or overlapped pass missed it (after `last_scheduled_run_at`, within `catch_up_minutes`), else null.
     * Catch-up needs a recorded last run, so turning a schedule on never starts a run for a minute before it.
     */
    protected function dueAt(string $cron, ?string $timezone, CarbonInterface $now, string $lastRun): ?CarbonInterface
    {
        if ($this->isDue($cron, $timezone, $now)) {
            return $now;
        }

        $window = (int) config('core.workflow_schedule.catch_up_minutes', 10);
        if ($window <= 0 || $lastRun === '' || ! CronExpression::isValidExpression($cron)) {
            return null;
        }

        $timezone = $timezone ?: (string) config('app.timezone', 'UTC');
        try {
            $previous = (new CronExpression($cron))
                ->getPreviousRunDate($now->copy()->setTimezone($timezone)->toDateTimeImmutable(), 0, false, $timezone);
        } catch (\Throwable) {
            return null;
        }

        $previous = \Carbon\Carbon::instance($previous)->setTimezone($now->getTimezone());
        if ($previous->lt($now->copy()->subMinutes($window)) || substr($lastRun, 0, 16) >= $previous->format('Y-m-d H:i')) {
            return null;
        }

        return $previous;
    }

    /**
     * Whether a cron is due at $now, read in $timezone (null = the app timezone).
     */
    protected function isDue(string $cron, ?string $timezone, CarbonInterface $now): bool
    {
        if (! CronExpression::isValidExpression($cron)) {
            return false;
        }

        $timezone = $timezone ?: (string) config('app.timezone', 'UTC');

        try {
            return (new CronExpression($cron))->isDue($now->copy()->setTimezone($timezone)->toDateTimeImmutable(), $timezone);
        } catch (\Throwable) {
            return false;
        }
    }
}
