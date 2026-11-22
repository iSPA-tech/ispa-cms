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
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\RootModelLoader;
use Cms\Core\Workflows\Upgrade\StepConfigUpgrader;
use Cms\Core\Workflows\Logging\StepLogFormatter;
use Carbon\CarbonImmutable;
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
    protected int $maxSteps;
    protected int $maxDurationSeconds;

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
        $this->maxSteps = (int) config('core.workflow.max_steps', 200);
        $this->maxDurationSeconds = (int) config('core.workflow.max_duration_seconds', 60);
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
        $instance = WorkflowInstance::query()
            ->with(['project.steps', 'project.connections'])
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

        $rootModel = $this->loadRoot($instance);
        $context = $this->restoreContext($instance, $rootModel);
        $context->setRuntime('instance_id', $instance->id);
        $context->setRuntime('queued', $queued);

        if ($this->runControlService->shouldStopWorkflowInstance($instance)) {
            $this->runControlService->acknowledgeRequestedStopForInstance(
                $instance,
                'Stop acknowledged before workflow execution started.'
            );
            return;
        }

        $instance->status = 'running';
        $instance->started_at = $instance->started_at ?: now();
        $instance->save();

        try {
            $steps = $project->steps ?? new EloquentCollection();
            $connections = $project->connections ?? new EloquentCollection();

            $stepMap = $steps->keyBy('id');

            $current = $this->pickExecutionStartStep($instance, $steps, $stepMap);
            if (!$current) {
                throw new \RuntimeException('No start step found');
            }

            $executionStartedAt = CarbonImmutable::now();
            $visited = 0;
            while ($current && $visited < $this->maxSteps) {
                if ($this->runControlService->shouldStopWorkflowInstance($instance)) {
                    $this->runControlService->acknowledgeRequestedStopForInstance(
                        $instance,
                        'Stop acknowledged before next workflow step.'
                    );
                    return;
                }

                if ($executionStartedAt->diffInSeconds(CarbonImmutable::now()) > $this->maxDurationSeconds) {
                    throw new \RuntimeException('Workflow execution timeout');
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

                $attempt = 0;
                $maxRetry = (int) ($definition->stepConfig['retry']['max'] ?? 0);
                $backoffs = $definition->stepConfig['retry']['backoffSeconds'] ?? [];
                $eligible = !in_array($definition->stepType, ['decision', 'condition', 'set_variable'], true);
                $result = null;
                $stepTimeout = isset($definition->stepConfig['timeoutSeconds']) ? (int) $definition->stepConfig['timeoutSeconds'] : null;

                do {
                    $attempt++;
                    $result = $this->runStep($runner, $definition, $context, $attempt, $maxRetry);

                    if ($stepTimeout && $result->startedAt && $result->finishedAt) {
                        $elapsed = $result->finishedAt->diffInSeconds($result->startedAt, true);
                        if ($elapsed > $stepTimeout) {
                            $result->status = 'failed';
                            $result->error = ['message' => 'Step timeout exceeded'];
                            $result->logs[] = ['level' => 'error', 'message' => "Step timeout {$elapsed}s > {$stepTimeout}s"];
                        }
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
                        $this->persistStepExecution($instanceStep, $current, $result, $attempt);
                        $this->persistInstanceResumeState($instance, $context, $current->id);
                        RunWorkflowInstanceJob::dispatch($instance->id)->delay(now()->addSeconds($delay));
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
                $this->persistStepExecution($instanceStep, $current, $result, $attempt);

                $context->setStepResult(
                    $this->resolveStepCode($current),
                    $result->resultData,
                    $result->status,
                    [
                        'logs' => $result->logs,
                        'error' => $result->error,
                        'next_edge_hint' => $result->nextEdgeHint,
                    ]
                );

                if ($result->status === 'cancelled') {
                    $this->runControlService->acknowledgeRequestedStopForInstance(
                        $instance,
                        $result->error['message'] ?? 'Operator stop acknowledged during step execution.'
                    );

                    return;
                }

                $nextId = null;
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

                $current = $nextId && $stepMap->has($nextId) ? $stepMap->get($nextId) : null;
            }

            if ($visited >= $this->maxSteps) {
                $this->markInstanceFailed($instance, 'Max step count exceeded');
                return;
            }

            $instance->status = 'completed';
            $instance->resume_step_id = null;
            $instance->finished_at = now();
            $instance->save();
            $this->scheduleRuntimeSync->syncFromWorkflowInstance($instance->fresh());
        } catch (\Throwable $e) {
            $this->markInstanceFailed($instance, $e->getMessage());
            Log::error('Workflow execution failed', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
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

    protected function nextStepId(int $currentId, EloquentCollection $connections, ?string $hint): ?int
    {
        $outgoing = $connections->filter(fn($c) => (int) $c->from_step_id === $currentId);

        if ($outgoing->isEmpty()) {
            return null;
        }

        if ($hint) {
            $matched = $outgoing->first(function ($conn) use ($hint) {
                $condition = $this->normalizeConnectionCondition($conn->condition);
                $type = $condition['condition_type'] ?? null;
                $value = $condition['condition_value'] ?? null;

                return $type === 'equals' && $value === $hint;
            });

            if ($matched) {
                return $matched->to_step_id;
            }
        }

        if ($outgoing->count() === 1) {
            return $outgoing->first()->to_step_id;
        }

        $defaultEdge = $outgoing->first(function ($conn) {
            $condition = $this->normalizeConnectionCondition($conn->condition);
            return empty($condition['condition_type'] ?? null);
        });

        if ($defaultEdge) {
            return $defaultEdge->to_step_id;
        }

        throw new \RuntimeException('Ambiguous next step: multiple outgoing edges without hint or default edge');
    }

    protected function markInstanceFailed(WorkflowInstance $instance, string $message): void
    {
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
