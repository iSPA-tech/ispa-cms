<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models\Agents;

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant as Tables;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Services\DataScope\ScopeSummary;
use Cms\Core\Services\DataScope\ScopedResourceRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Execution record for a single agent run within the agentic runtime.
 *
 * Captures the full lifecycle of an agent execution: from trigger through
 * reasoning steps, tool calls, artifact production, and terminal outcome.
 * Supports hierarchical runs (child runs), workflow integration, operator
 * stop requests, and budget tracking.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property int         $agent_profile_id          Agent profile used for this run
 * @property int|null    $workflow_instance_id      Optional workflow instance context
 * @property int|null    $parent_workflow_step_id   Workflow step that spawned this run
 * @property int|null    $source_run_id             Parent run for hierarchical execution
 * @property string      $trigger_mode              How the run was triggered (manual, schedule, workflow, api)
 * @property string|null $trigger_reference_type    Polymorphic trigger source type
 * @property int|null    $trigger_reference_id      Polymorphic trigger source ID
 * @property string|null $environment               Execution environment (production, staging, dev)
 * @property int|null    $tenant_id                 Multi-tenant scope identifier
 * @property string|null $module_scope              CMS module scope for the run
 * @property string|null $target_type               Polymorphic target model type
 * @property int|null    $target_id                 Polymorphic target model ID
 * @property string      $status                    Run status (pending, running, completed, failed, cancelled)
 * @property string|null $input_goal                Natural language goal for the agent
 * @property array|null  $working_memory            In-flight working memory state
 * @property array|null  $context_snapshot           Context data at run start
 * @property string|null $reasoning_summary         Final reasoning summary
 * @property array|null  $budget_config             Budget limits for this run
 * @property array|null  $budget_usage              Actual budget consumption
 * @property string|null $terminal_reason           Reason for run termination
 * @property string|null $post_approval_outcome     Outcome after human approval
 * @property string|null $operator_request_state    Current operator intervention state
 * @property int|null    $stop_requested_by_user_id User who requested a stop
 * @property \Carbon\Carbon|null $stop_requested_at When the stop was requested
 * @property \Carbon\Carbon|null $stop_acknowledged_at When the agent acknowledged the stop
 * @property int|null    $stopped_by_user_id        User who forced the stop
 * @property \Carbon\Carbon|null $stopped_at        When the run was actually stopped
 * @property \Carbon\Carbon|null $started_at        When execution began
 * @property \Carbon\Carbon|null $finished_at       When execution ended
 * @property \Carbon\Carbon|null $last_checkpoint_at Last checkpoint timestamp
 * @property int|null    $delegated_by_user_id      User the run acts for (copied from the workflow instance)
 * @property array|null  $scope_snapshot            Data scope summary of the delegating user at run start
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read AgentProfile    $profile            The agent profile configuration
 * @property-read \Cms\Core\Models\Workflows\WorkflowInstance|null     $workflowInstance    Workflow context
 * @property-read \Cms\Core\Models\Workflows\WorkflowInstanceStep|null $parentWorkflowStep Spawning workflow step
 * @property-read AgentRun|null   $sourceRun          Parent run in a hierarchy
 * @property-read \App\Models\User|null $stoppedBy    User who forced the stop
 * @property-read \App\Models\User|null $stopRequestedBy User who requested the stop
 * @property-read \App\Models\User|null $delegatedBy User the run acts for
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentRunStep[]  $steps      Reasoning/action steps
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentToolCall[] $toolCalls  Tool invocations
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentArtifact[] $artifacts  Produced artifacts
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentApproval[] $approvals  Human review records
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentRun[]      $childRuns  Subordinate child runs
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Agents
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentRun extends Model
{
    use HasFactory;

    protected $table = Tables::AGENT_RUN;

    protected $fillable = [
        'agent_profile_id',
        'workflow_instance_id',
        'parent_workflow_step_id',
        'source_run_id',
        'trigger_mode',
        'trigger_reference_type',
        'trigger_reference_id',
        'environment',
        'tenant_id',
        'module_scope',
        'target_type',
        'target_id',
        'status',
        'input_goal',
        'working_memory',
        'context_snapshot',
        'reasoning_summary',
        'budget_config',
        'budget_usage',
        'terminal_reason',
        'post_approval_outcome',
        'operator_request_state',
        'stop_requested_by_user_id',
        'stop_requested_at',
        'stop_acknowledged_at',
        'stopped_by_user_id',
        'stopped_at',
        'started_at',
        'finished_at',
        'last_checkpoint_at',
        'delegated_by_user_id',
        'scope_snapshot',
    ];

    protected $casts = [
        'working_memory' => 'array',
        'context_snapshot' => 'array',
        'budget_config' => 'array',
        'budget_usage' => 'array',
        'stop_requested_by_user_id' => 'integer',
        'stop_requested_at' => 'datetime',
        'stop_acknowledged_at' => 'datetime',
        'stopped_by_user_id' => 'integer',
        'stopped_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_checkpoint_at' => 'datetime',
        'delegated_by_user_id' => 'integer',
        'scope_snapshot' => 'array',
    ];

    /**
     * Get the agent profile configuration used for this run.
     * @return BelongsTo
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    /**
     * Get the workflow instance this run is executing within.
     * @return BelongsTo
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    /**
     * Get the workflow instance step that spawned this agent run.
     * @return BelongsTo
     */
    public function parentWorkflowStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstanceStep::class, 'parent_workflow_step_id');
    }

    /**
     * Get the parent run in a hierarchical execution chain.
     * @return BelongsTo
     */
    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_run_id');
    }

    /**
     * Get the user who forced the run to stop.
     * @return BelongsTo
     */
    public function stoppedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stopped_by_user_id');
    }

    /**
     * Get the user who requested the run be stopped.
     * @return BelongsTo
     */
    public function stopRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stop_requested_by_user_id');
    }

    /**
     * Get the user this run acts for.
     * @return BelongsTo
     */
    public function delegatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_by_user_id');
    }

    /**
     * Load the delegating user fresh from the database, so a deleted user yields null.
     *
     * Tools receive this value as their `actor` argument.
     *
     * @return User|null
     */
    public function currentDelegate(): ?User
    {
        return $this->delegated_by_user_id === null ? null : $this->delegatedBy()->first();
    }

    /**
     * Delegation attributes for a new run inside a workflow instance.
     *
     * The user is read from the workflow instance row, never from a workflow
     * context variable. The scope snapshot summarises the user's data scope
     * for the scoped resource registered for the target model, or is null when
     * there is no user, no target type, or no registered resource.
     *
     * @param  mixed       $workflowInstanceId Workflow instance id the run belongs to.
     * @param  string|null $targetType         Target model class of the run.
     * @return array{delegated_by_user_id: int|null, scope_snapshot: array|null}
     */
    public static function delegationFor(mixed $workflowInstanceId, ?string $targetType): array
    {
        $userId = is_numeric($workflowInstanceId)
            ? WorkflowInstance::query()->withTrashed()->whereKey((int) $workflowInstanceId)->value('delegated_by_user_id')
            : null;
        $userId = $userId === null ? null : (int) $userId;

        return [
            'delegated_by_user_id' => $userId,
            'scope_snapshot' => self::scopeSnapshotFor($userId, $targetType),
        ];
    }

    /**
     * Summarise the user's data scope for the scoped resource of the target model.
     *
     * @param  int|null    $userId     Delegating user id.
     * @param  string|null $targetType Target model class.
     * @return array|null Scope summary, or null when it does not apply.
     */
    protected static function scopeSnapshotFor(?int $userId, ?string $targetType): ?array
    {
        if ($userId === null || !is_string($targetType) || $targetType === '' || !class_exists($targetType)) {
            return null;
        }

        $resource = app(ScopedResourceRegistry::class)->forModel($targetType);
        $user = $resource ? User::query()->find($userId) : null;

        if (!$resource || !$user) {
            return null;
        }

        return app(ScopeSummary::class)->for($user, $resource->permission());
    }

    /**
     * Get all reasoning/action steps executed during this run.
     * @return HasMany
     */
    public function steps(): HasMany
    {
        return $this->hasMany(AgentRunStep::class, 'run_id');
    }

    /**
     * Get all tool invocations made during this run.
     * @return HasMany
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class, 'run_id');
    }

    /**
     * Get all artifacts produced by this run.
     * @return HasMany
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(AgentArtifact::class, 'run_id');
    }

    /**
     * Get all human review approval records for this run.
     * @return HasMany
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(AgentApproval::class, 'run_id');
    }

    /**
     * Get subordinate child runs spawned from this run.
     * @return HasMany
     */
    public function childRuns(): HasMany
    {
        return $this->hasMany(self::class, 'source_run_id');
    }
}
