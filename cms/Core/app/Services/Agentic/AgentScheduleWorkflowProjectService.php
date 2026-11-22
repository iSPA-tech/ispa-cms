<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Cms\Core\Models\Agents\AgentSchedule;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowConnection;

/**
 * Manages system-generated workflow project wrappers for agent schedules.
 *
 * Each agent schedule requires a single-step WorkflowProject to execute
 * via the workflow graph executor. This service creates, restores, or
 * updates the wrapper project and its ai_action step whenever a schedule
 * is created or modified. Resolves tool bindings and normalizes step
 * configuration for the runtime.
 *
 * @see AgentScheduleService
 * @see WorkflowProject
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentScheduleWorkflowProjectService
{
    public const WRAPPER_STEP_CODE = 'scheduled_agent_action';

    /**
     * Initialize the service with the application configuration repository.
     *
     * @param ConfigRepository $config Application configuration for resolving default environment settings.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(protected ConfigRepository $config)
    {
    }

    /**
     * Ensure a workflow project wrapper exists for the given schedule.
     *
     * Creates or updates the wrapper project, its single ai_action step,
     * and links it to the schedule. Removes any stale steps or connections.
     *
     * @param AgentSchedule $schedule The schedule to wrap.
     * @return WorkflowProject The ensured workflow project with steps and connections.
     *
     * @throws \InvalidArgumentException When the schedule is missing runtime references.
     */
    public function ensureWrapper(AgentSchedule $schedule): WorkflowProject
    {
        $schedule->loadMissing(['profile', 'toolDefinition']);

        if (! $schedule->profile || ! $schedule->toolDefinition) {
            throw new \InvalidArgumentException("Agent schedule [{$schedule->id}] is missing runtime references.");
        }

        return DB::transaction(function () use ($schedule) {
            $project = $this->resolveProject($schedule);

            $project->fill([
                'name' => $this->buildProjectName($schedule),
                'slug' => $this->buildProjectSlug($schedule),
                'trigger_event' => 'agentic.schedule.execute',
                'target_entity_type' => AgentSchedule::class,
                'is_active' => true,
                'tags' => [
                    'agentic',
                    'schedule-wrapper',
                    'system-managed',
                ],
                'description' => $this->buildProjectDescription($schedule),
            ])->save();

            $step = WorkflowStep::withTrashed()->firstOrNew([
                'project_id' => $project->id,
                'step_code' => self::WRAPPER_STEP_CODE,
            ]);

            if (method_exists($step, 'trashed') && $step->trashed()) {
                $step->restore();
            }

            $step->fill([
                'name' => $schedule->name ?: 'Scheduled Agent Action',
                'step_type' => 'ai_action',
                'step_config' => $this->buildStepConfig($schedule),
                'input_schema' => null,
                'output_schema' => null,
                'position_x' => 0,
                'position_y' => 0,
                'is_start' => true,
            ])->save();

            WorkflowConnection::query()
                ->where('project_id', $project->id)
                ->delete();

            WorkflowStep::query()
                ->where('project_id', $project->id)
                ->where('id', '!=', $step->id)
                ->delete();

            if ((int) $schedule->workflow_project_id !== (int) $project->id) {
                $schedule->forceFill([
                    'workflow_project_id' => $project->id,
                ])->save();
            }

            return $project->fresh(['steps', 'connections']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildStepConfig(AgentSchedule $schedule): array
    {
        return [
            'agentProfileSlug' => $schedule->profile->slug,
            'toolClass' => $this->resolveToolClass($schedule),
            'toolName' => $schedule->toolDefinition->tool_name,
            'moduleScope' => $this->resolveEffectiveModuleScope($schedule),
            'environment' => $this->resolveEffectiveEnvironment($schedule),
            'tenantId' => $schedule->tenant_id,
            'goal' => $this->normalizeGoalForStepConfig($schedule->goal),
            'toolInput' => $this->normalizeToolInputForStepConfig(
                is_array($schedule->tool_input) ? $schedule->tool_input : []
            ),
            'triggerMode' => 'schedule',
            'triggerReferenceType' => 'agent_schedule',
            'triggerReferenceId' => (string) $schedule->id,
        ];
    }

    protected function resolveToolClass(AgentSchedule $schedule): ?string
    {
        $bindings = $schedule->toolDefinition
            ?->bindings()
            ->where('enabled', true)
            ->get();

        if (! $bindings || $bindings->isEmpty()) {
            return null;
        }

        $scored = $bindings
            ->map(function ($binding) use ($schedule) {
                $score = 0;

                foreach ([
                    [$binding->environment, $this->resolveEffectiveEnvironment($schedule), 16],
                    [$binding->module_scope, $this->resolveEffectiveModuleScope($schedule), 8],
                    [$binding->tenant_id, $schedule->tenant_id, 4],
                ] as [$bindingValue, $requestedValue, $weight]) {
                    if ($bindingValue === null || $bindingValue === '') {
                        continue;
                    }

                    if ($requestedValue === null || $bindingValue !== $requestedValue) {
                        return null;
                    }

                    $score += $weight;
                }

                return [
                    'binding' => $binding,
                    'score' => $score,
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                return [$right['score'], $right['binding']->priority]
                    <=> [$left['score'], $left['binding']->priority];
            })
            ->values();

        $binding = $scored->first()['binding'] ?? null;

        if (! $binding || $binding->binding_type !== 'service_method' || ! str_contains((string) $binding->binding_target, '@')) {
            return null;
        }

        [$className] = explode('@', (string) $binding->binding_target, 2);

        return $className !== '' ? $className : null;
    }

    protected function resolveEffectiveEnvironment(AgentSchedule $schedule): string
    {
        $environment = is_string($schedule->environment) ? trim($schedule->environment) : '';

        if ($environment !== '') {
            return $environment;
        }

        return (string) $this->config->get('core.agentic.discovery.default_environment');
    }

    protected function resolveEffectiveModuleScope(AgentSchedule $schedule): ?string
    {
        $moduleScope = is_string($schedule->module_scope) ? trim($schedule->module_scope) : '';

        if ($moduleScope !== '') {
            return $moduleScope;
        }

        foreach ([
            $schedule->toolDefinition?->tool_family,
            $schedule->toolDefinition?->contract_steward,
        ] as $fallback) {
            if (is_string($fallback) && trim($fallback) !== '') {
                return trim($fallback);
            }
        }

        return null;
    }

    protected function resolveProject(AgentSchedule $schedule): WorkflowProject
    {
        $project = null;

        if ($schedule->workflow_project_id) {
            $project = WorkflowProject::withTrashed()->find($schedule->workflow_project_id);
        }

        if (! $project) {
            $project = WorkflowProject::withTrashed()
                ->where('slug', $this->buildProjectSlug($schedule))
                ->first();
        }

        if (! $project) {
            $project = new WorkflowProject();
        }

        if (method_exists($project, 'trashed') && $project->trashed()) {
            $project->restore();
        }

        return $project;
    }

    protected function buildProjectSlug(AgentSchedule $schedule): string
    {
        return 'agentic-schedule-' . $schedule->id;
    }

    protected function buildProjectName(AgentSchedule $schedule): string
    {
        $label = trim((string) $schedule->name);
        if ($label === '') {
            $label = $schedule->toolDefinition?->tool_name ?: 'Scheduled Agent Action';
        }

        return Str::limit("Agentic Schedule {$schedule->id}: {$label}", 191, '');
    }

    protected function buildProjectDescription(AgentSchedule $schedule): string
    {
        $tool = $schedule->toolDefinition?->tool_name ?: 'unknown';

        return "System-managed workflow wrapper for agent schedule {$schedule->id} ({$tool}).";
    }

    /**
     * @param array<string, mixed> $toolInput
     * @return array<string, mixed>
     */
    protected function normalizeToolInputForStepConfig(array $toolInput): array
    {
        $normalized = [];

        foreach ($toolInput as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $normalized[$key] = $this->normalizeInputValue($value);
        }

        return $normalized;
    }

    protected function normalizeInputValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalizeInputValue($item), $value);
        }

        return $value;
    }

    protected function normalizeGoalForStepConfig(?string $goal): ?string
    {
        if (! is_string($goal) || trim($goal) === '') {
            return null;
        }

        return json_encode($goal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
};
