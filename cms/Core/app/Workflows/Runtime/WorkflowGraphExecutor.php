<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime;

use Cms\Core\Services\Agentic\AgentRunControlService;
use Cms\Core\Services\Agentic\AgentScheduleRuntimeSyncService;
use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Workflows\Runtime\Contracts\IdempotentStepRunner;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\RootModelLoader;
use Cms\Core\Workflows\Upgrade\StepConfigUpgrader;
use Cms\Core\Workflows\Logging\StepLogFormatter;
use Cms\Core\Services\Workflow\Exceptions\WorkflowIntegrityException;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectNotPublished;
use Cms\Core\Services\Workflow\Guard\WorkflowCircuitBreaker;
use Cms\Core\Services\Workflow\Guard\WorkflowGlobalPause;
use Cms\Core\Services\Workflow\Triggers\TriggerChain;
use Cms\Core\Services\Workflow\Versioning\RunGraph;
use Cms\Core\Services\Workflow\Versioning\WorkflowIntegrity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Executes a workflow instance by traversing the step graph from start to completion.
 *
 * Manages the full lifecycle: loading the root model, restoring context, running steps
 * through the StepRunnerRegistry, handling retries, timeouts, delays, pausing for
 * approvals, and persisting execution state at each step.
 *
 * @see StepRunnerRegistry
 * @see WorkflowContext
 * @package Cms\Core\Workflows\Runtime
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowGraphExecutor
{
    /**
     * Statuses from which an execution may claim (start or resume) an instance.
     * `awaiting_approval` / `awaiting_apply` resume only after apply sets `applied`.
     */
    public const CLAIMABLE_STATUSES = ['pending', 'queued', 'waiting', 'applied'];

    /** Output handle a failed step leaves from when it has an edge on it. */
    public const ERROR_OUTPUT = 'error';

    /**
     * Named outputs that are specific branches. A lone edge on one of them is not a catch-all.
     * `case:<key>` handles count too.
     */
    public const BRANCH_OUTPUTS = [
        'true', 'false', 'yes', 'no', 'default', 'each', 'loop', 'done',
        'approved', 'rejected', 'expired', 'changes_requested', 'no_proposal', self::ERROR_OUTPUT,
        // Flow and utility nodes (lock, rate_limit, json_transform, dedupe, split_in_batches).
        'acquired', 'busy', 'ok', 'limited', 'valid', 'invalid', 'new', 'seen', 'batch',
    ];

    /**
     * Outputs that follow only their own edge or the `default` edge, never a legacy fallback edge:
     * "nothing to do" or "not approved" must not run the steps meant for the normal path.
     */
    public const STRICT_OUTPUTS = ['no_proposal', 'rejected', 'expired', 'changes_requested', self::ERROR_OUTPUT, 'busy', 'limited', 'invalid', 'seen'];

    /** Legacy runner outputs and the catalog handle names they are equal to. */
    /** Edge handles that mean the step's plain output (no named branch). */
    public const PLAIN_OUTPUT_HANDLES = ['output', 'main'];

    public const OUTPUT_ALIASES = [
        'yes' => ['true'],
        'no' => ['false'],
        'true' => ['yes'],
        'false' => ['no'],
        'loop' => ['each'],
        'each' => ['loop'],
    ];

    protected int $maxSteps;
    protected int $maxDurationSeconds;
    protected int $maxDurationContinuations;
    protected int $maxRetryAttempts;
    protected int $pausedRecheckSeconds;

    /**
     * Initialize the executor with its runtime dependencies.
     *
     * @param StepRunnerRegistry              $registry            Registry mapping step types to runners.
     * @param RootModelLoader                 $rootLoader          Loader for the workflow's root Eloquent model.
     * @param StepConfigUpgrader              $upgrader            Upgrades legacy step configurations.
     * @param AgentRunControlService          $runControlService   Handles operator stop/cancel requests.
     * @param AgentScheduleRuntimeSyncService $scheduleRuntimeSync Synchronizes schedule state after execution.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected StepRunnerRegistry $registry,
        protected RootModelLoader $rootLoader,
        protected StepConfigUpgrader $upgrader,
        protected AgentRunControlService $runControlService,
        protected AgentScheduleRuntimeSyncService $scheduleRuntimeSync,
    ) {
        // Run-wide: counted in `__runtime.steps` across continuations, delays and retries (review finding 20).
        $this->maxSteps = (int) config('core.workflow.max_steps', 2000);
        $this->maxRetryAttempts = max(1, (int) config('core.workflow.max_retry_attempts', 10));
        $this->pausedRecheckSeconds = max(1, (int) config('core.workflow_guard.paused_recheck_seconds', 60));
        $this->maxDurationSeconds = (int) config('core.workflow.max_duration_seconds', 60);
        $this->maxDurationContinuations = (int) config('core.workflow.max_duration_continuations', 20);
    }

    /**
     * Execute a workflow instance end-to-end.
     *
     * @param  int  $workflowInstanceId The ID of the workflow instance to execute.
     * @param  bool $queued             Whether this execution was dispatched via queue.
     * @return void
     */
    public function execute(int $workflowInstanceId, bool $queued = false): void
    {
        // The graph comes from the run's version (RunGraph), never from the project's draft rows.
        $instance = WorkflowInstance::query()
            ->with(['project'])
            ->find($workflowInstanceId);

        if (!$instance) {
            Log::warning('Workflow instance not found', ['id' => $workflowInstanceId]);
            return;
        }

        if (in_array($instance->status, ['completed', 'cancelled', 'failed'], true)) {
            return;
        }

        $project = $instance->project;
        if (!$project) {
            $this->markInstanceFailed($instance, 'Workflow project not found');
            return;
        }

        if ($this->runControlService->shouldStopWorkflowInstance($instance)) {
            $this->runControlService->acknowledgeRequestedStopForInstance(
                $instance,
                'Stop acknowledged before workflow execution started.'
            );
            return;
        }

        if (!$this->claimInstance($instance)) {
            Log::info('Workflow instance not claimable; another execution owns it or it is not resumable', [
                'id' => $instance->id,
                'status' => $instance->status,
            ]);
            return;
        }

        // The instance was read before the claim; another execution may have advanced the cursor
        // and context since (e.g. it rescheduled the run to `waiting`). Continue from what is stored now.
        $instance->refresh();
        $project = $instance->project ?? $project;

        $rootModel = $this->loadRoot($instance);
        $context = $this->restoreContext($instance, $rootModel);
        $context->setRuntime('instance_id', $instance->id);
        $context->setRuntime('queued', $queued);

        if ($project->is_active === false) {
            $this->markInstanceTerminated($instance, 'cancelled', 'project_inactive');
            return;
        }

        // Every execution (start and every resume) verifies the version it runs: hash, seal, owner (B2).
        try {
            $graph = RunGraph::forInstance($instance);
        } catch (WorkflowIntegrityException $e) {
            $this->markInstanceFailed($instance, WorkflowIntegrityException::CODE);
            WorkflowIntegrity::violated($e, (int) $instance->id, 'executor');
            return;
        } catch (WorkflowProjectNotPublished $e) {
            $this->markInstanceFailed($instance, 'version_missing');
            return;
        }

        // Budget caps are run settings of the version, not of the editable project row.
        $project = clone $project;
        $project->budget_max_cost_per_run = $graph['settings']['budget_max_cost_per_run'] ?? null;
        $project->budget_max_runs_per_day = $graph['settings']['budget_max_runs_per_day'] ?? null;

        $instance->started_at = $instance->started_at ?: now();
        $instance->save();

        RunGraph::enter($graph);
        // Writes of this run see its trigger chain even outside its queued job (inline runs, finding 29).
        TriggerChain::enter((int) $instance->id);
        try {
            $steps = $graph['steps'];
            $connections = $graph['connections'];

            $stepMap = $steps->keyBy('id');

            $current = $this->pickExecutionStartStep($instance, $steps, $stepMap);
            if (!$current) {
                throw new \RuntimeException('No start step found');
            }

            $executionStartedAt = CarbonImmutable::now();
            $visited = 0;
            while ($current) {
                if ($this->runControlService->shouldStopWorkflowInstance($instance)) {
                    $this->runControlService->acknowledgeRequestedStopForInstance(
                        $instance,
                        'Stop acknowledged before next workflow step.'
                    );
                    return;
                }

                // Pause-all also holds runs already in flight (delays, retries, continuations, approval resumes): the
                // run goes back to `waiting` on this step and checks again later (review finding 11).
                if (app(WorkflowGlobalPause::class)->active()) {
                    $this->persistInstanceResumeState($instance, $context, $current->id);
                    $this->rescheduleInstance($instance, $this->pausedRecheckSeconds);
                    return;
                }

                if ($visited > 0 && $executionStartedAt->diffInSeconds(CarbonImmutable::now()) > $this->maxDurationSeconds) {
                    // Out of time for this execution: continue in a new queued job instead of failing,
                    // up to a bounded number of continuations per instance.
                    $continuations = (int) $context->getVarPath('__runtime.duration_continuations', 0);
                    if (!$queued || $continuations >= $this->maxDurationContinuations) {
                        throw new \RuntimeException('Workflow execution timeout');
                    }

                    $context->setRuntime('duration_continuations', $continuations + 1);
                    $this->persistInstanceResumeState($instance, $context, $current->id);
                    $this->rescheduleInstance($instance, 0);
                    return;
                }

                // A step resumed after a person's decision (awaiting_approval) does not call AI again. An ai_action is
                // pointed back at itself only for a requested rewrite, which calls its tool again: the budget gate applies.
                $resumingDecision = $context->getStep($this->resolveStepCode($current))?->getStatus() === 'awaiting_approval'
                    && $current->step_type !== 'ai_action';
                $spendsAi = !$resumingDecision
                    && $this->isAiSpendStep((string) $current->step_type, is_array($current->step_config) ? $current->step_config : []);
                if ($spendsAi) {
                    $budgetStop = $this->budgetStopReason($instance, $project, $current);
                    if ($budgetStop !== null) {
                        $this->persistInstanceResumeState($instance, $context, $current->id);
                        $this->markInstanceTerminated($instance, 'budget_exceeded', $budgetStop);
                        return;
                    }
                }

                // One step budget for the whole run, so a cycle through a delay node or a long loop ends (finding 20).
                $runSteps = (int) $context->getVarPath('__runtime.steps', 0);
                if ($runSteps >= $this->maxSteps) {
                    $this->markInstanceFailed($instance, 'Max step count exceeded');
                    return;
                }
                $context->setRuntime('steps', $runSteps + 1);

                // Outside calls and publishes are capped per run as well as per hour (review finding 26).
                if (WorkflowCircuitBreaker::countsAsAction((string) $current->step_type, is_array($current->step_config) ? $current->step_config : [])) {
                    $runActions = (int) $context->getVarPath('__runtime.actions', 0);
                    $actionsCap = (int) config('core.workflow_guard.breaker.actions_per_run', 30);
                    if ($actionsCap > 0 && $runActions >= $actionsCap) {
                        $this->markInstanceFailed($instance, "breaker: actions_per_run ({$runActions} of {$actionsCap})");
                        return;
                    }
                    $context->setRuntime('actions', $runActions + 1);
                }

                $visited++;
                $instanceStep = $this->beginStepExecution($instance, $current);
                $context->setRuntime('current_instance_step_id', $instanceStep->id);

                if ($this->runControlService->shouldStopWorkflowInstance($instance)) {
                    $cancelledResult = StepRunResult::cancelled(
                        'Operator stop acknowledged before step execution started.',
                        [
                            'operator_request_state' => 'stop_requested',
                        ],
                        [
                            ['level' => 'warning', 'message' => 'Operator stop acknowledged before step execution started.'],
                        ]
                    );
                    $this->persistStepExecution($instanceStep, $current, $cancelledResult);
                    $this->runControlService->acknowledgeRequestedStopForInstance(
                        $instance,
                        'Operator stop acknowledged before step execution started.'
                    );

                    return;
                }

                $definition = StepDefinition::fromModel($current);
                $upgradeResult = $this->upgrader->upgrade($definition->stepConfig, $definition->stepType);
                $upgradeLog = $upgradeResult['_upgrade_log'] ?? [];
                $definition = new StepDefinition(
                    $definition->id,
                    $definition->projectId,
                    $definition->stepType,
                    $definition->stepCode,
                    $definition->stepKey,
                    $definition->stepName,
                    $upgradeResult['config'] ?? $definition->stepConfig,
                    $definition->order,
                );

                $runner = $this->registry->get($definition->stepType);

                // Attempts made by earlier executions of this step (a backoff reschedules the run): without them every
                // resumed job started again at attempt 1 and a failing step retried forever (review finding 8).
                $retryCode = $this->resolveStepCode($current);
                $retryState = (array) $context->getVarPath('__runtime.retry', []);
                $attempt = max(0, (int) ($retryState[$retryCode] ?? 0));
                $maxRetry = min((int) ($definition->stepConfig['retry']['max'] ?? 0), $this->maxRetryAttempts);
                $backoffs = $definition->stepConfig['retry']['backoffSeconds'] ?? [];
                $eligible = !in_array($definition->stepType, ['decision', 'condition', 'set_variable'], true);
                $result = null;
                $stepTimeout = isset($definition->stepConfig['timeoutSeconds']) ? (int) $definition->stepConfig['timeoutSeconds'] : null;

                do {
                    $attempt++;
                    $result = $this->runStep($runner, $definition, $context, $attempt, $maxRetry);
                    $timedOut = false;

                    if ($stepTimeout && $result->startedAt && $result->finishedAt) {
                        $elapsed = $result->finishedAt->diffInSeconds($result->startedAt, true);
                        if ($elapsed > $stepTimeout) {
                            $timedOut = true;
                            $result->status = 'failed';
                            $result->error = ['message' => 'Step timeout exceeded'];
                            $result->logs[] = ['level' => 'error', 'message' => "Step timeout {$elapsed}s > {$stepTimeout}s"];
                        }
                    }

                    // A step that finished late may have taken effect (a slow POST or email): run it again only when its
                    // runner says running twice is harmless (review finding 19).
                    if ($timedOut && !$runner instanceof IdempotentStepRunner) {
                        $result->logs[] = ['level' => 'warning', 'message' => 'Not retried: a step that ran past its timeout may already have taken effect.'];
                        break;
                    }

                    if ($result->status !== 'failed' || !$eligible || $attempt >= $maxRetry) {
                        break;
                    }

                    $delay = $backoffs[$attempt - 1] ?? 0;
                    $result->logs[] = [
                        'level' => 'warning',
                        'message' => "Retrying step (attempt {$attempt} of {$maxRetry}) after {$delay}s",
                    ];

                    if ($queued && $delay > 0) {
                        $retryState[$retryCode] = $attempt;
                        $context->setRuntime('retry', $retryState);
                        $this->persistStepExecution($instanceStep, $current, $result, $attempt);
                        $this->persistInstanceResumeState($instance, $context, $current->id);
                        $this->rescheduleInstance($instance, (int) $delay);
                        return;
                    }

                    if ($delay > 1 && !$queued) {
                        $result->logs[] = [
                            'level' => 'error',
                            'message' => 'Retry requires async execution; aborting.',
                        ];
                        break;
                    }

                    if ($delay > 0) {
                        sleep($delay);
                    }
                } while ($result->status === 'failed' && $attempt < $maxRetry);

                if (array_key_exists($retryCode, $retryState)) {
                    // The step is done retrying; a later visit (e.g. the next loop item) starts with a fresh count.
                    unset($retryState[$retryCode]);
                    $context->setRuntime('retry', $retryState);
                }
                if (!empty($upgradeLog)) {
                    $result->logs = array_merge(
                        array_map(fn($l) => array_merge(['level' => 'warning'], $l), $upgradeLog),
                        $result->logs
                    );
                }

                // Alias warning
                if (!$this->registry->has($definition->stepType) && config('core.workflow_step_aliases.' . $definition->stepType)) {
                    $alias = config('core.workflow_step_aliases.' . $definition->stepType);
                    $result->logs[] = [
                        'level' => 'warning',
                        'message' => "Legacy step_type '{$definition->stepType}' mapped to '{$alias}'",
                    ];
                }
                $this->recordUsage($instance, $context, (string) $current->step_type, $result, $spendsAi);
                $this->persistStepExecution($instanceStep, $current, $result, $attempt);

                // Merge with the meta a runner already stored (e.g. loop progress) instead of replacing it.
                $stepCode = $this->resolveStepCode($current);
                $context->setStepResult(
                    $stepCode,
                    $result->resultData,
                    $result->status,
                    array_merge($context->getStep($stepCode)?->getMeta() ?? [], [
                        'logs' => $result->logs,
                        'error' => $result->error,
                        'next_edge_hint' => $result->nextEdgeHint,
                        'usage' => $result->usage(),
                    ])
                );

                if ($result->status === 'cancelled') {
                    $this->runControlService->acknowledgeRequestedStopForInstance(
                        $instance,
                        $result->error['message'] ?? 'Operator stop acknowledged during step execution.'
                    );

                    return;
                }

                $nextId = null;
                $errorStepId = $result->status === 'failed' ? $this->errorStepId($current->id, $connections) : null;
                if ($errorStepId !== null) {
                    // on_error = route: the failure is handled by the `error` branch, the run goes on.
                    $context->setVarPath('__error', [
                        'step_code' => $stepCode,
                        'message' => (string) ($result->error['message'] ?? 'Step failed'),
                    ]);
                    $nextId = $errorStepId;
                    $this->persistInstanceResumeState($instance, $context, $nextId);
                    $current = $stepMap->get($nextId);
                    continue;
                }

                if (!$this->isTermination($definition, $result) && $result->status !== 'failed') {
                    $nextId = $this->nextStepId($current->id, $connections, $result->nextEdgeHint);
                    $this->persistInstanceResumeState($instance, $context, $nextId);
                }

                if ($this->isTermination($definition, $result)) {
                    $status = $result->resultData['status'] ?? 'success';
                    $message = $result->resultData['message'] ?? null;
                    $this->markInstanceTerminated($instance, $status, $message);
                    return;
                }

                if ($result->paused) {
                    $this->markInstancePaused($instance, $result);
                    return;
                }

                if ($result->status === 'failed') {
                    $this->markInstanceFailed($instance, $result->error['message'] ?? 'Step failed');
                    return;
                }

                if ($result->status === 'rescheduled' && $nextId) {
                    $this->rescheduleInstance($instance, (int) ($result->delaySeconds ?? 0));
                    return;
                }

                $current = $nextId && $stepMap->has($nextId) ? $stepMap->get($nextId) : null;
            }

            $instance->status = 'completed';
            $instance->resume_step_id = null;
            $instance->finished_at = now();
            $instance->save();
            $this->scheduleRuntimeSync->syncFromWorkflowInstance($instance->fresh());
            $this->afterTerminal($instance);
        } catch (\Throwable $e) {
            $this->markInstanceFailed($instance, $e->getMessage());
            Log::error('Workflow execution failed', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            TriggerChain::leave((int) $instance->id);
            RunGraph::leave();
        }
    }

    protected function loadRoot(WorkflowInstance $instance)
    {
        try {
            if ($instance->target_type && $instance->target_id) {
                return $this->rootLoader->load($instance->target_type, $instance->target_id, true);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to load workflow root model', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
        }

        return new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'workflow_dummy_root';
            protected $guarded = [];
        };
    }

    protected function pickStartStep(EloquentCollection $steps): ?WorkflowStep
    {
        if ($steps->isEmpty()) {
            return null;
        }

        $byType = $steps->first(fn($s) => ($s->step_type ?? null) === 'input');
        if ($byType) {
            return $byType;
        }

        $byKey = $steps->first(fn($s) => ($s->step_code ?? null) === 'input');
        if ($byKey) {
            return $byKey;
        }

        return $steps->sortBy('position_x')->first();
    }

    protected function pickExecutionStartStep(
        WorkflowInstance $instance,
        EloquentCollection $steps,
        EloquentCollection $stepMap,
    ): ?WorkflowStep {
        $resumeStepId = $instance->resume_step_id;

        if ($resumeStepId && $stepMap->has($resumeStepId)) {
            return $stepMap->get($resumeStepId);
        }

        return $this->pickStartStep($steps);
    }

    protected function runStep(StepRunner $runner, StepDefinition $definition, WorkflowContext $context, int $attempt = 1, int $maxRetry = 0): StepRunResult
    {
        $started = CarbonImmutable::now();

        try {
            $result = $runner->run($definition, $context);
        } catch (\Throwable $e) {
            return StepRunResult::failed($e->getMessage(), [
                ['level' => 'error', 'message' => $e->getMessage()],
            ], ['exception' => get_class($e)]);
        }

        // Ensure timestamps
        $result->startedAt = $started;
        $result->finishedAt = CarbonImmutable::now();

        $result->logs[] = [
            'level' => 'info',
            'message' => 'Step attempt',
            'context' => ['attempt' => $attempt, 'max' => $maxRetry],
        ];
        $result->resultData = [
            'payload' => $result->resultData,
            'retry' => ['attempt' => $attempt, 'max' => $maxRetry],
        ];

        return $result;
    }

    protected function beginStepExecution(WorkflowInstance $instance, WorkflowStep $step): WorkflowInstanceStep
    {
        return WorkflowInstanceStep::create([
            'instance_id' => $instance->id,
            'step_id' => $step->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    protected function persistStepExecution(
        WorkflowInstanceStep $instanceStep,
        WorkflowStep $step,
        StepRunResult $result,
        ?int $attempt = null,
    ): void
    {
        $context = [
            'step_type' => $step->step_type,
            'step_code' => $this->resolveStepCode($step),
        ];

        $logs = collect($result->logs)->map(fn($log) => StepLogFormatter::format($log, $context))->all();

        $payload = [
            'status' => $result->status,
            'started_at' => $result->startedAt,
            'finished_at' => $result->finishedAt,
            'result_data' => [
                'result' => $result->resultData,
                'logs' => $logs,
                'next_edge_hint' => $result->nextEdgeHint,
                'duration_ms' => $this->calculateDurationMs($result->startedAt, $result->finishedAt),
                'usage' => $result->usage(),
            ],
            'error_message' => $result->error['message'] ?? null,
        ];

        if (Schema::hasColumn($instanceStep->getTable(), 'retry_count')) {
            $payload['retry_count'] = $attempt ? max(0, $attempt - 1) : $instanceStep->retry_count;
        }

        $instanceStep->fill($payload);
        $instanceStep->save();
    }

    protected function persistInstanceResumeState(
        WorkflowInstance $instance,
        WorkflowContext $context,
        ?int $resumeStepId,
    ): void {
        $instance->context_snapshot = $context->toArray();
        $instance->resume_step_id = $resumeStepId;
        $instance->save();
    }

    protected function resolveStepCode(WorkflowStep $step): string
    {
        return $step->step_code ?: ('step_' . $step->id);
    }

    protected function calculateDurationMs(?CarbonImmutable $start, ?CarbonImmutable $end): ?int
    {
        if (!$start || !$end) {
            return null;
        }
        return $end->diffInMilliseconds($start, true);
    }

    /**
     * Pick the next step from the current step's output handle.
     *
     * Edge convention: a connection's `source_handle` (stored with the derived
     * `condition_type=equals`, `condition_value=<handle>`) names the output it leaves from.
     * Order: exact handle (or its legacy alias, e.g. `yes` = `true`) → the `default` edge →
     * (outputs other than {@see STRICT_OUTPUTS}) the legacy fallbacks: a single edge that is not
     * a different named branch, then an edge without a condition. Edges on the `error` handle
     * are taken only by {@see errorStepId()}. A named output with no matching edge ends the branch.
     */
    protected function nextStepId(int $currentId, EloquentCollection $connections, ?string $hint): ?int
    {
        $outgoing = $connections
            ->filter(fn($c) => (int) $c->from_step_id === $currentId)
            ->reject(fn($c) => $this->connectionHandle($c) === self::ERROR_OUTPUT)
            ->values();

        if ($outgoing->isEmpty()) {
            return null;
        }

        if ($hint) {
            $accepted = $this->handleAliases($hint);
            $matched = $outgoing->first(fn($conn) => in_array($this->connectionHandle($conn), $accepted, true));
            if ($matched) {
                return $matched->to_step_id;
            }

            $default = $outgoing->first(fn($conn) => $this->connectionHandle($conn) === 'default');
            if ($default) {
                return $default->to_step_id;
            }

            if (in_array($hint, self::STRICT_OUTPUTS, true)) {
                return null;
            }
        }

        if ($outgoing->count() === 1) {
            $only = $outgoing->first();
            $handle = $this->connectionHandle($only);
            if ($hint && $handle !== null && $this->isBranchHandle($handle)) {
                // The only edge belongs to another named output (e.g. a `true` edge after `false`).
                return null;
            }

            return $only->to_step_id;
        }

        $unconditioned = $outgoing->first(fn($conn) => $this->connectionHandle($conn) === null);
        if ($unconditioned) {
            return $unconditioned->to_step_id;
        }

        $default = $outgoing->first(fn($conn) => $this->connectionHandle($conn) === 'default');
        if ($default) {
            return $default->to_step_id;
        }

        if ($hint && $outgoing->every(fn($conn) => $this->isBranchHandle((string) $this->connectionHandle($conn)))) {
            return null;
        }

        throw new \RuntimeException('Ambiguous next step: multiple outgoing edges without hint or default edge');
    }

    /**
     * Whether a step type spends AI budget (safety class `ai_spend`).
     *
     * Asks the node registry (lane N0a) when it exists and knows the type; otherwise uses the
     * temporary `core.workflow_budget.ai_spend_step_types` list. A definition's `safety_when` makes it depend on the
     * step config (e.g. `approval` in `mode: jev` calls the judge, so it spends).
     *
     * @param array<string, mixed> $config
     */
    protected function isAiSpendStep(string $stepType, array $config = []): bool
    {
        $stepType = trim($stepType);
        if ($stepType === '') {
            return false;
        }

        $alias = config('core.workflow_step_aliases.' . $stepType);
        $types = array_values(array_unique(array_filter([$stepType, is_string($alias) ? $alias : null])));

        $registryClass = 'Cms\\Core\\Workflows\\Nodes\\NodeRegistry';
        if (class_exists($registryClass)) {
            try {
                $registry = app($registryClass);
                foreach (['definition', 'get', 'find'] as $method) {
                    if (!method_exists($registry, $method)) {
                        continue;
                    }
                    foreach ($types as $type) {
                        $definition = $registry->{$method}($type);
                        if (is_object($definition) && isset($definition->safety)) {
                            $safety = method_exists($definition, 'safetyFor') ? $definition->safetyFor($config) : (array) $definition->safety;

                            return in_array('ai_spend', $safety, true);
                        }
                    }
                    break;
                }
            } catch (\Throwable) {
                // Registry not usable for this type: fall back to the config list.
            }
        }

        $spendTypes = (array) config('core.workflow_budget.ai_spend_step_types', []);

        return array_intersect($types, $spendTypes) !== [];
    }

    /**
     * Why the run must stop before this ai_spend step, or null when it may run.
     *
     * Caps: the project's `budget_max_cost_per_run` / `budget_max_runs_per_day`, else the config
     * defaults (null = no cap). Totals are read fresh from the database inside the claimed run.
     * The cost cap stops the run when spent >= cap, when spent + the step's declared
     * `limits.usd_per_call` > cap, or when an earlier ai_spend step reported no cost (unknown spend
     * is never treated as free).
     */
    protected function budgetStopReason(WorkflowInstance $instance, WorkflowProject $project, WorkflowStep $step): ?string
    {
        $maxCost = $project->budget_max_cost_per_run ?? config('core.workflow_budget.default_max_cost_per_run');
        $maxRuns = $project->budget_max_runs_per_day ?? config('core.workflow_budget.default_max_runs_per_day');

        if ($maxRuns !== null && $maxRuns !== '') {
            $maxRuns = (int) $maxRuns;
            $runsToday = WorkflowInstance::query()
                ->where('project_id', $project->id)
                ->where(fn($q) => $q->where('started_at', '>=', now()->startOfDay())->orWhereKey($instance->id))
                ->count();

            if ($runsToday > $maxRuns) {
                return "budget_exceeded: {$runsToday} runs today, daily cap is {$maxRuns}";
            }
        }

        if ($maxCost === null || $maxCost === '') {
            return null;
        }

        $maxCost = (float) $maxCost;
        $totals = $this->usageTotals($instance);

        if ($totals['unknown_calls'] > 0) {
            return 'budget_exceeded: an earlier AI step reported no cost, so spend against the cap '
                . "of {$maxCost} USD is unknown";
        }

        $config = is_array($step->step_config) ? $step->step_config : [];
        // A step that declares no per-call price is not assumed free against a cap (review finding 26).
        $estimate = data_get($config, 'limits.usd_per_call');
        $estimate = is_numeric($estimate)
            ? max(0.0, (float) $estimate)
            : max(0.0, (float) config('core.workflow_budget.default_usd_per_call', 0.01));

        if ($totals['cost_usd'] >= $maxCost || $totals['cost_usd'] + $estimate > $maxCost) {
            return sprintf(
                'budget_exceeded: run spent %.6f USD, next AI step may add %.6f, cap is %.6f',
                $totals['cost_usd'],
                $estimate,
                $maxCost
            );
        }

        return null;
    }

    /**
     * Sum a step's reported usage onto the run (database columns and `vars.__runtime.usage`).
     *
     * An ai_spend step that ran without reporting `cost_usd` counts as an unknown call.
     */
    protected function recordUsage(WorkflowInstance $instance, WorkflowContext $context, string $stepType, StepRunResult $result, ?bool $isSpend = null): void
    {
        if (in_array($result->status, ['skipped', 'cancelled'], true)) {
            return;
        }

        $usage = $result->usage();
        $isSpend ??= $this->isAiSpendStep($stepType);
        $tokensIn = max(0, (int) ($usage['tokens_in'] ?? 0));
        $tokensOut = max(0, (int) ($usage['tokens_out'] ?? 0));
        $cost = isset($usage['cost_usd']) && is_numeric($usage['cost_usd']) ? max(0.0, (float) $usage['cost_usd']) : null;
        $unknown = $isSpend && $cost === null ? 1 : 0;

        if ($usage === null && $unknown === 0) {
            return;
        }

        $runtime = (array) $context->getVarPath('__runtime.usage', []);
        $context->setRuntime('usage', [
            'tokens_in' => (int) ($runtime['tokens_in'] ?? 0) + $tokensIn,
            'tokens_out' => (int) ($runtime['tokens_out'] ?? 0) + $tokensOut,
            'cost_usd' => round((float) ($runtime['cost_usd'] ?? 0) + (float) $cost, 6),
            'unknown_calls' => (int) ($runtime['unknown_calls'] ?? 0) + $unknown,
        ]);

        if (!Schema::hasColumn($instance->getTable(), 'usage_cost_usd')) {
            return;
        }

        // Atomic increments: totals stay right even if the instance model in memory is stale.
        WorkflowInstance::query()->whereKey($instance->getKey())->update([
            'usage_tokens_in' => DB::raw('usage_tokens_in + ' . $tokensIn),
            'usage_tokens_out' => DB::raw('usage_tokens_out + ' . $tokensOut),
            'usage_cost_usd' => DB::raw('usage_cost_usd + ' . sprintf('%.6F', (float) $cost)),
            'usage_unknown_calls' => DB::raw('usage_unknown_calls + ' . $unknown),
        ]);
    }

    /**
     * Fresh usage totals of the run: database columns when present, else the context runtime vars.
     *
     * @return array{cost_usd: float, unknown_calls: int}
     */
    protected function usageTotals(WorkflowInstance $instance): array
    {
        if (Schema::hasColumn($instance->getTable(), 'usage_cost_usd')) {
            $row = WorkflowInstance::query()
                ->whereKey($instance->getKey())
                ->first(['usage_cost_usd', 'usage_unknown_calls']);

            return [
                'cost_usd' => (float) ($row?->usage_cost_usd ?? 0),
                'unknown_calls' => (int) ($row?->usage_unknown_calls ?? 0),
            ];
        }

        $snapshot = WorkflowInstance::query()->whereKey($instance->getKey())->value('context_snapshot');
        $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;
        $usage = (array) data_get($snapshot, 'vars.__runtime.usage', []);

        return [
            'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
            'unknown_calls' => (int) ($usage['unknown_calls'] ?? 0),
        ];
    }

    /**
     * Target of the current step's `error` edge, or null when the step has none (the run then fails).
     */
    protected function errorStepId(int $currentId, EloquentCollection $connections): ?int
    {
        $edge = $connections->first(fn($c) => (int) $c->from_step_id === $currentId
            && $this->connectionHandle($c) === self::ERROR_OUTPUT);

        return $edge && $edge->to_step_id ? (int) $edge->to_step_id : null;
    }

    /**
     * Output handle a connection leaves from: the equals value of its normalized condition, or null.
     */
    protected function connectionHandle(mixed $connection): ?string
    {
        $condition = $this->normalizeConnectionCondition($connection->condition ?? null);
        $handle = ($condition['condition_type'] ?? null) === 'equals' ? ($condition['condition_value'] ?? null) : null;

        // The plain output: older designer saves stored it as `output` (or `main`); a step with a second output edge
        // then had no unconditioned edge and failed with "Ambiguous next step".
        return in_array($handle, self::PLAIN_OUTPUT_HANDLES, true) ? null : $handle;
    }

    /**
     * Handle names that satisfy an output, including legacy runner outputs (`yes`/`no`, `loop`).
     *
     * @return array<int, string>
     */
    protected function handleAliases(string $output): array
    {
        return array_values(array_unique(array_merge([$output], self::OUTPUT_ALIASES[$output] ?? [])));
    }

    /**
     * Whether a handle names a specific branch (so a lone edge on it must not catch other outputs).
     */
    protected function isBranchHandle(string $handle): bool
    {
        return in_array($handle, self::BRANCH_OUTPUTS, true) || str_starts_with($handle, 'case:');
    }

    /**
     * Atomically move the instance to `running`. Only one execution can win: the UPDATE
     * matches the row only while its status is claimable, and the affected-row count decides.
     */
    protected function claimInstance(WorkflowInstance $instance): bool
    {
        $claimed = WorkflowInstance::query()
            ->whereKey($instance->getKey())
            ->whereIn('status', self::CLAIMABLE_STATUSES)
            ->update(['status' => 'running']);

        if ($claimed !== 1) {
            return false;
        }

        $instance->status = 'running';
        $instance->syncOriginalAttribute('status');

        return true;
    }

    /**
     * Stop this execution and continue the run from its saved cursor in a new queued job.
     *
     * The resume cursor and context must already be saved. The instance is left `waiting`
     * so the next job can claim it.
     */
    protected function rescheduleInstance(WorkflowInstance $instance, int $delaySeconds): void
    {
        $instance->status = 'waiting';
        $instance->finished_at = null;
        $instance->save();
        $this->scheduleRuntimeSync->syncFromWorkflowInstance($instance->fresh());

        $pending = RunWorkflowInstanceJob::dispatch($instance->id);
        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
    }

    protected function markInstanceFailed(WorkflowInstance $instance, string $message): void
    {
        // Record the error workflow of the run's version on the failed run; starting it belongs to lane N3.
        $errorWorkflowId = $instance->version_id
            ? (RunGraph::settingsOf((int) $instance->version_id)['error_workflow_project_id'] ?? null)
            : null;
        if ($errorWorkflowId && Schema::hasColumn($instance->getTable(), 'error_workflow_project_id')) {
            $instance->error_workflow_project_id = (int) $errorWorkflowId;
        }

        $instance->status = 'failed';
        $instance->resume_step_id = null;
        $instance->finished_at = now();
        $instance->save();
        $this->scheduleRuntimeSync->syncFromWorkflowInstance($instance->fresh());

        WorkflowInstanceStep::create([
            'instance_id' => $instance->id,
            'step_id' => null,
            'status' => 'failed',
            'started_at' => now(),
            'finished_at' => now(),
            'error_message' => $message,
        ]);
        $this->afterTerminal($instance);
    }

    /**
     * A run reached a terminal status: the error-rate breaker looks at the project's last runs (guardrails B7). A
     * breaker failure never changes the run's outcome. System-managed agent schedule wrappers are left out.
     */
    protected function afterTerminal(WorkflowInstance $instance): void
    {
        if (!$instance->project_id) {
            return;
        }

        try {
            // Agent schedule wrappers are generated by code and start through their schedule, not WorkflowProjectRunService.
            $project = $instance->relationLoaded('project') ? $instance->project : $instance->project()->first();
            if ($project && app(\Cms\Core\Services\Workflow\WorkflowProjectRunService::class)->isSystemManaged($project)) {
                return;
            }
            app(WorkflowCircuitBreaker::class)->afterRun($instance);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function markInstancePaused(WorkflowInstance $instance, StepRunResult $result): void
    {
        $instance->status = $result->status;
        $instance->finished_at = null;
        $instance->save();
        $this->scheduleRuntimeSync->syncFromWorkflowInstance($instance->fresh());
    }

    protected function markInstanceTerminated(WorkflowInstance $instance, string $status, ?string $message = null): void
    {
        $instance->status = $status;
        $instance->resume_step_id = null;
        $instance->finished_at = now();
        $instance->save();
        $this->scheduleRuntimeSync->syncFromWorkflowInstance($instance->fresh());

        if ($message) {
            WorkflowInstanceStep::create([
                'instance_id' => $instance->id,
                'step_id' => null,
                'status' => $status,
                'started_at' => now(),
                'finished_at' => now(),
                'error_message' => $message,
            ]);
        }
        $this->afterTerminal($instance);
    }

    protected function isTermination(StepDefinition $definition, StepRunResult $result): bool
    {
        if ($definition->stepType === 'terminate_workflow') {
            return true;
        }

        return (bool) ($result->resultData['terminated'] ?? false);
    }

    protected function restoreContext(WorkflowInstance $instance, $rootModel): WorkflowContext
    {
        $snapshot = $instance->context_snapshot;

        if (is_array($snapshot) && !empty($snapshot)) {
            return WorkflowContext::fromArray($snapshot, $rootModel);
        }

        return new WorkflowContext($rootModel, trigger: [], vars: [], steps: []);
    }

    /**
     * @param array<string, mixed>|mixed $condition
     * @return array<string, mixed>
     */
    protected function normalizeConnectionCondition(mixed $condition): array
    {
        $condition = is_array($condition) ? $condition : [];

        $type = $condition['condition_type'] ?? null;
        $value = $condition['condition_value'] ?? null;
        $sourceHandle = $condition['source_handle'] ?? null;
        $label = $condition['condition'] ?? null;

        if ((! is_string($type) || trim($type) === '') && is_string($sourceHandle) && trim($sourceHandle) !== '') {
            $type = 'equals';
            $value = is_string($value) && trim($value) !== '' ? trim($value) : trim($sourceHandle);
        }

        if ((! is_string($type) || trim($type) === '') && is_string($label) && trim($label) !== '') {
            $type = 'equals';
            $value = trim($label);
        }

        return [
            'condition_type' => is_string($type) && trim($type) !== '' ? trim($type) : null,
            'condition_value' => is_string($value) && trim($value) !== '' ? trim($value) : null,
        ];
    }
}
