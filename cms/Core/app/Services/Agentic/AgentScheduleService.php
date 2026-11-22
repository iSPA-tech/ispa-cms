<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentSchedule;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Manages CRUD operations and execution of agent schedules.
 *
 * Creates and updates AgentSchedule records with validation of profiles,
 * tool definitions, and capability class constraints. Executes schedules
 * by creating workflow instances and dispatching them through the graph
 * executor. Provides queue-due scanning for cron-driven dispatch.
 *
 * @see AgentScheduleWorkflowProjectService
 * @see AgentScheduleRuntimeSyncService
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentScheduleService
{
    /**
     * Initialize the schedule service with workflow executor and supporting services.
     *
     * @param ConfigRepository                    $config                 Application configuration repository.
     * @param WorkflowGraphExecutor               $workflowGraphExecutor  Executes workflow instances for schedules.
     * @param AgentScheduleWorkflowProjectService $workflowProjectService Manages wrapper workflow projects for schedules.
     * @param AgentScheduleRuntimeSyncService     $runtimeSyncService     Synchronizes schedule runtime state after execution.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected ConfigRepository $config,
        protected WorkflowGraphExecutor $workflowGraphExecutor,
        protected AgentScheduleWorkflowProjectService $workflowProjectService,
        protected AgentScheduleRuntimeSyncService $runtimeSyncService,
    ) {
    }

    /**
     * Create a new agent schedule with validated profile and tool definition.
     *
     * @param array<string, mixed> $attributes Schedule attributes.
     * @param User|null            $creator    The user creating the schedule.
     * @return AgentSchedule The created schedule with loaded relations.
     *
     * @throws \InvalidArgumentException When the profile, tool definition, or grant is not found, or schedule is not allowed.
     */
    public function create(array $attributes, ?User $creator = null): AgentSchedule
    {
        $profile = $this->resolveProfile($attributes['agent_profile_id'] ?? null);
        $definition = $this->resolveDefinition($attributes['tool_definition_id'] ?? null);
        $resolvedEnvironment = $this->resolveEnvironment($attributes['environment'] ?? null);
        $resolvedModuleScope = $this->resolveModuleScope($attributes['module_scope'] ?? null, $definition);

        $this->ensureScheduleAllowed($profile, $definition);
        $this->ensureProfileHasGrantedTool($profile, $definition);

        return DB::transaction(function () use ($profile, $definition, $attributes, $creator, $resolvedEnvironment, $resolvedModuleScope) {
            $schedule = AgentSchedule::query()->create([
                'agent_profile_id' => $profile->id,
                'tool_definition_id' => $definition->id,
                'created_by_user_id' => $creator?->getKey(),
                'name' => (string) ($attributes['name'] ?? $definition->tool_name),
                'description' => $attributes['description'] ?? null,
                'status' => $attributes['status'] ?? 'active',
                'environment' => $resolvedEnvironment,
                'tenant_id' => $attributes['tenant_id'] ?? null,
                'module_scope' => $resolvedModuleScope,
                'target_type' => $attributes['target_type'] ?? null,
                'target_id' => isset($attributes['target_id']) ? (string) $attributes['target_id'] : null,
                'goal' => $attributes['goal'] ?? null,
                'tool_input' => is_array($attributes['tool_input'] ?? null) ? $attributes['tool_input'] : [],
                'cadence_minutes' => max(1, (int) ($attributes['cadence_minutes'] ?? 60)),
                'next_run_at' => $attributes['next_run_at'] ?? now()->addMinutes(max(1, (int) ($attributes['cadence_minutes'] ?? 60))),
            ]);

            $this->workflowProjectService->ensureWrapper($schedule);

            return $schedule->fresh(['profile', 'toolDefinition', 'lastRun', 'creator', 'workflowProject']);
        });
    }

    /**
     * Update an existing agent schedule with validated attributes.
     *
     * @param AgentSchedule        $schedule   The schedule to update.
     * @param array<string, mixed> $attributes The attributes to update.
     * @return AgentSchedule The updated schedule with loaded relations.
     *
     * @throws \InvalidArgumentException When the schedule is missing runtime references, or the profile/tool is invalid.
     */
    public function update(AgentSchedule $schedule, array $attributes): AgentSchedule
    {
        $schedule->loadMissing(['profile', 'toolDefinition']);

        $profile = $schedule->profile;
        $definition = $schedule->toolDefinition;

        if (! $profile || ! $definition) {
            throw new \InvalidArgumentException("Agent schedule [{$schedule->id}] is missing runtime references.");
        }

        if (array_key_exists('agent_profile_id', $attributes)) {
            $profile = $this->resolveProfile($attributes['agent_profile_id']);
        }

        if (array_key_exists('tool_definition_id', $attributes)) {
            $definition = $this->resolveDefinition($attributes['tool_definition_id']);
        }

        $resolvedEnvironment = Arr::exists($attributes, 'environment')
            ? $this->resolveEnvironment($attributes['environment'])
            : $this->resolveEnvironment($schedule->environment);
        $resolvedModuleScope = Arr::exists($attributes, 'module_scope')
            ? $this->resolveModuleScope($attributes['module_scope'], $definition)
            : $this->resolveModuleScope($schedule->module_scope, $definition);

        $this->ensureScheduleAllowed($profile, $definition);
        $this->ensureProfileHasGrantedTool($profile, $definition);

        return DB::transaction(function () use ($schedule, $profile, $definition, $attributes, $resolvedEnvironment, $resolvedModuleScope) {
            $schedule->fill([
                'agent_profile_id' => $profile->id,
                'tool_definition_id' => $definition->id,
                'name' => Arr::exists($attributes, 'name') ? (string) $attributes['name'] : $schedule->name,
                'description' => Arr::exists($attributes, 'description') ? $attributes['description'] : $schedule->description,
                'status' => Arr::exists($attributes, 'status') ? (string) $attributes['status'] : $schedule->status,
                'environment' => $resolvedEnvironment,
                'tenant_id' => Arr::exists($attributes, 'tenant_id') ? $attributes['tenant_id'] : $schedule->tenant_id,
                'module_scope' => $resolvedModuleScope,
                'target_type' => Arr::exists($attributes, 'target_type') ? $attributes['target_type'] : $schedule->target_type,
                'target_id' => Arr::exists($attributes, 'target_id') ? (string) $attributes['target_id'] : $schedule->target_id,
                'goal' => Arr::exists($attributes, 'goal') ? $attributes['goal'] : $schedule->goal,
                'tool_input' => Arr::exists($attributes, 'tool_input') && is_array($attributes['tool_input']) ? $attributes['tool_input'] : $schedule->tool_input,
                'cadence_minutes' => Arr::exists($attributes, 'cadence_minutes') ? max(1, (int) $attributes['cadence_minutes']) : $schedule->cadence_minutes,
                'next_run_at' => Arr::exists($attributes, 'next_run_at') ? $attributes['next_run_at'] : $schedule->next_run_at,
            ])->save();

            $this->workflowProjectService->ensureWrapper($schedule);

            return $schedule->fresh(['profile', 'toolDefinition', 'lastRun', 'creator', 'workflowProject']);
        });
    }

    /**
     * Mark a schedule as running/queued for execution.
     *
     * @param AgentSchedule      $schedule        The schedule to mark.
     * @param array<int, string> $allowedStatuses Statuses from which queuing is allowed.
     * @return AgentSchedule The updated schedule.
     *
     * @throws \InvalidArgumentException When the schedule cannot be dispatched from its current status.
     */
    public function markQueued(AgentSchedule $schedule, array $allowedStatuses = ['active']): AgentSchedule
    {
        $updated = AgentSchedule::query()
            ->whereKey($schedule->getKey())
            ->whereIn('status', $allowedStatuses)
            ->update([
                'status' => 'running',
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            $currentStatus = AgentSchedule::query()->whereKey($schedule->getKey())->value('status') ?? 'unknown';
            throw new \InvalidArgumentException("Agent schedule [{$schedule->id}] can not be dispatched from status [{$currentStatus}].");
        }

        return AgentSchedule::query()->findOrFail($schedule->getKey());
    }

    /**
     * Execute a queued agent schedule by creating and running a workflow instance.
     *
     * @param AgentSchedule $schedule                    The schedule to execute.
     * @param string        $steadyStateAfterTerminal    Desired schedule status after terminal.
     * @return array{schedule: AgentSchedule, run: AgentRun|null}
     *
     * @throws \InvalidArgumentException When the schedule is not queued for execution.
     */
    public function execute(AgentSchedule $schedule, string $steadyStateAfterTerminal = 'active'): array
    {
        $schedule->refresh()->loadMissing(['profile', 'toolDefinition', 'workflowProject']);

        if ($schedule->status !== 'running') {
            throw new \InvalidArgumentException("Agent schedule [{$schedule->id}] is not queued for execution.");
        }

        $project = $this->workflowProjectService->ensureWrapper($schedule);

        $instance = WorkflowInstance::query()->create([
            'project_id' => $project->id,
            'target_type' => $this->resolveWorkflowTargetType($schedule),
            'target_id' => $this->resolveWorkflowTargetId($schedule),
            'status' => 'pending',
            'context_snapshot' => [
                'trigger' => [
                    'event' => 'agentic.schedule.execute',
                    'at' => now()->toISOString(),
                    'meta' => [
                        'schedule_id' => $schedule->id,
                        'tool_name' => $schedule->toolDefinition?->tool_name,
                    ],
                ],
                'vars' => [
                    '__runtime' => [
                        'agent_schedule_id' => $schedule->id,
                        'agent_schedule_steady_state_after_terminal' => $steadyStateAfterTerminal,
                    ],
                ],
                'steps' => [],
            ],
            'resume_step_id' => null,
            'started_at' => null,
            'finished_at' => null,
            'delegated_by_user_id' => $schedule->created_by_user_id,
        ]);

        $this->workflowGraphExecutor->execute($instance->id, true);
        $instance = $instance->fresh();

        $run = AgentRun::query()
            ->with(['profile', 'workflowInstance', 'parentWorkflowStep', 'sourceRun', 'stoppedBy', 'steps', 'approvals', 'artifacts', 'toolCalls'])
            ->where('workflow_instance_id', $instance->id)
            ->orderByDesc('id')
            ->first();

        $syncedSchedule = $this->runtimeSyncService->syncFromWorkflowInstance($instance, $run);

        return [
            'schedule' => $syncedSchedule ?? $schedule->fresh(['profile', 'toolDefinition', 'lastRun', 'creator', 'workflowProject']),
            'run' => $run?->fresh(['profile', 'workflowInstance', 'parentWorkflowStep', 'sourceRun', 'stoppedBy', 'steps', 'approvals', 'artifacts', 'toolCalls']),
        ];
    }

    /**
     * Scan for due active schedules and dispatch them for execution.
     *
     * @param int $limit Maximum number of schedules to queue.
     * @return array{queued: int, schedule_ids: array<int, int>}
     */
    public function queueDue(int $limit = 20): array
    {
        $queued = [];

        AgentSchedule::query()
            ->where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (AgentSchedule $schedule) use (&$queued) {
                try {
                    $this->markQueued($schedule, ['active']);
                    \Cms\Core\Jobs\RunAgentScheduleJob::dispatch($schedule->id, 'active');
                    $queued[] = $schedule->id;
                } catch (\InvalidArgumentException) {
                }
            });

        return [
            'queued' => count($queued),
            'schedule_ids' => $queued,
        ];
    }

    protected function resolveProfile(mixed $id): AgentProfile
    {
        $profile = AgentProfile::query()->find($id);

        if (! $profile) {
            throw new \InvalidArgumentException('Agent profile not found.');
        }

        return $profile;
    }

    protected function resolveDefinition(mixed $id): AgentToolDefinition
    {
        $definition = AgentToolDefinition::query()->find($id);

        if (! $definition) {
            throw new \InvalidArgumentException('Agent tool definition not found.');
        }

        return $definition;
    }

    protected function resolveEnvironment(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return (string) $this->config->get('core.agentic.discovery.default_environment');
    }

    protected function resolveModuleScope(mixed $value, AgentToolDefinition $definition): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        foreach ([
            $definition->tool_family,
            $definition->contract_steward,
        ] as $fallback) {
            if (is_string($fallback) && trim($fallback) !== '') {
                return trim($fallback);
            }
        }

        return null;
    }

    protected function ensureScheduleAllowed(AgentProfile $profile, AgentToolDefinition $definition): void
    {
        $capabilities = (array) $this->config->get('core.agentic.capability_classes', []);
        $classConfig = $capabilities[$profile->capability_class] ?? null;

        if (! is_array($classConfig) || ! (bool) ($classConfig['schedule_allowed'] ?? false)) {
            throw new \InvalidArgumentException("Agent profile [{$profile->slug}] does not allow scheduled execution.");
        }

        if ($definition->status !== 'active') {
            throw new \InvalidArgumentException("Agent tool definition [{$definition->tool_name}] is not active.");
        }
    }

    protected function ensureProfileHasGrantedTool(AgentProfile $profile, AgentToolDefinition $definition): void
    {
        $granted = $profile->toolDefinitions()
            ->where($definition->getTable() . '.id', $definition->id)
            ->wherePivot('enabled', true)
            ->exists();

        if (! $granted) {
            throw new \InvalidArgumentException("Agent profile [{$profile->slug}] does not have tool [{$definition->tool_name}] granted.");
        }
    }

    protected function resolveWorkflowTargetType(AgentSchedule $schedule): string
    {
        if (is_string($schedule->target_type) && trim($schedule->target_type) !== '' && $schedule->target_id !== null && $schedule->target_id !== '') {
            return $schedule->target_type;
        }

        return AgentSchedule::class;
    }

    protected function resolveWorkflowTargetId(AgentSchedule $schedule): string
    {
        if (is_string($schedule->target_type) && trim($schedule->target_type) !== '' && $schedule->target_id !== null && $schedule->target_id !== '') {
            return (string) $schedule->target_id;
        }

        return (string) $schedule->getKey();
    }
}
