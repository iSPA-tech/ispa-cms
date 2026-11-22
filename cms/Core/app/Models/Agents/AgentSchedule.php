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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Models\Workflows\WorkflowProject;

/**
 * Scheduled execution configuration for periodic agent runs.
 *
 * Defines a recurring schedule for an agent profile to execute a specific
 * tool or workflow at a configured cadence. Tracks last execution outcome,
 * next scheduled time, and supports scoping by environment and tenant.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property int         $agent_profile_id     Agent profile to execute
 * @property int|null    $tool_definition_id   Specific tool to invoke on schedule
 * @property int|null    $workflow_project_id  Workflow project to trigger on schedule
 * @property int|null    $last_run_id          Most recent agent run from this schedule
 * @property int|null    $created_by_user_id   User who created this schedule
 * @property string      $name                 Human-readable schedule name
 * @property string|null $description          Schedule purpose description
 * @property string      $status               Schedule status (active, paused, disabled)
 * @property string|null $environment          Target environment (production, staging, dev)
 * @property int|null    $tenant_id            Multi-tenant scope identifier
 * @property string|null $module_scope         CMS module scope
 * @property string|null $target_type          Polymorphic target model type
 * @property int|null    $target_id            Polymorphic target model ID
 * @property string|null $goal                 Natural language goal for the scheduled run
 * @property array|null  $tool_input           Pre-configured tool input payload
 * @property int|null    $cadence_minutes       Interval between executions in minutes
 * @property \Carbon\Carbon|null $next_run_at   Next scheduled execution time
 * @property \Carbon\Carbon|null $last_run_at   Most recent execution time
 * @property string|null $last_outcome         Outcome of the most recent execution
 * @property string|null $last_error           Error from the most recent execution (if any)
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read AgentProfile             $profile          The agent profile to execute
 * @property-read AgentToolDefinition|null $toolDefinition   The tool to invoke
 * @property-read AgentRun|null            $lastRun          Most recent run record
 * @property-read \Cms\Core\Models\Workflows\WorkflowProject|null $workflowProject Linked workflow project
 * @property-read \App\Models\User|null    $creator          User who created this schedule
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Agents
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentSchedule extends Model
{
    use HasFactory;

    protected $table = Tables::AGENT_SCHEDULE;

    protected $fillable = [
        'agent_profile_id',
        'tool_definition_id',
        'workflow_project_id',
        'last_run_id',
        'created_by_user_id',
        'name',
        'description',
        'status',
        'environment',
        'tenant_id',
        'module_scope',
        'target_type',
        'target_id',
        'goal',
        'tool_input',
        'cadence_minutes',
        'next_run_at',
        'last_run_at',
        'last_outcome',
        'last_error',
    ];

    protected $casts = [
        'tool_input' => 'array',
        'workflow_project_id' => 'integer',
        'cadence_minutes' => 'integer',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    /**
     * Get the agent profile to execute on this schedule.
     * @return BelongsTo
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    /**
     * Get the tool definition to invoke on schedule.
     * @return BelongsTo
     */
    public function toolDefinition(): BelongsTo
    {
        return $this->belongsTo(AgentToolDefinition::class, 'tool_definition_id');
    }

    /**
     * Get the most recent agent run from this schedule.
     * @return BelongsTo
     */
    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'last_run_id');
    }

    /**
     * Get the linked workflow project for workflow-triggered schedules.
     * @return BelongsTo
     */
    public function workflowProject(): BelongsTo
    {
        return $this->belongsTo(WorkflowProject::class, 'workflow_project_id');
    }

    /**
     * Get the user who created this schedule.
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
