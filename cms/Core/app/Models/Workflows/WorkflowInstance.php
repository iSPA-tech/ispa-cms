<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models\Workflows;

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Running instance of a workflow project execution.
 *
 * Represents a single execution of a workflow project against a specific
 * target entity. Tracks overall execution status, context snapshot, timing,
 * and the resume point for paused workflows. Contains the collection of
 * individual step executions.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property int         $project_id         Foreign key to the workflow project definition
 * @property string|null $target_type        Polymorphic target model type
 * @property int|null    $target_id          Polymorphic target model ID
 * @property string      $status             Execution status (pending, running, paused, completed, failed)
 * @property array|null  $context_snapshot   Contextual data snapshot at execution start
 * @property int|null    $resume_step_id     Step to resume from when unpausing
 * @property \Carbon\Carbon|null $started_at  When execution began
 * @property \Carbon\Carbon|null $finished_at When execution ended
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property int|null    $delegated_by_user_id User the instance acts for (null when nobody delegated it)
 * @property int|null    $error_workflow_project_id Project's error workflow, recorded when this run failed
 * @property int         $usage_tokens_in     Tokens sent, summed from steps' meta.usage (executor-written)
 * @property int         $usage_tokens_out    Tokens received, summed from steps' meta.usage (executor-written)
 * @property float       $usage_cost_usd      USD spent, summed from steps' meta.usage (executor-written)
 * @property int         $usage_unknown_calls ai_spend steps that reported no cost
 * @property int|null    $version_id          Workflow version this run executes (set when it is created; null = refused)
 *
 * ==== Relationships
 * @property-read \Cms\Core\Models\Workflows\WorkflowProject $project The workflow project definition
 * @property-read \Illuminate\Database\Eloquent\Collection|WorkflowInstanceStep[] $steps Individual step executions
 * @property-read \App\Models\User|null $delegatedBy User the instance acts for
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Workflows
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowInstance extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = Tables::WORKFLOW_INSTANCE;

    protected $fillable = [
        'project_id',
        'target_type',
        'target_id',
        'status',
        'context_snapshot',
        'resume_step_id',
        'started_at',
        'finished_at',
        'delegated_by_user_id',
        'error_workflow_project_id',
        'version_id',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'target_id' => 'integer',
        'context_snapshot' => 'array',
        'resume_step_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'delegated_by_user_id' => 'integer',
        'error_workflow_project_id' => 'integer',
        'usage_tokens_in' => 'integer',
        'usage_tokens_out' => 'integer',
        'usage_cost_usd' => 'float',
        'usage_unknown_calls' => 'integer',
        'version_id' => 'integer',
    ];

    /**
     * When a run fails, start its project's error workflow; when a sub-workflow run in `wait` mode ends, continue
     * its parent (lane N3). Both are queued jobs, so the status change commits first.
     */
    protected static function booted(): void
    {
        static::updated(static function (self $instance): void {
            if (! $instance->wasChanged('status')) {
                return;
            }

            if ($instance->status === 'failed' && ($instance->error_workflow_project_id
                || WorkflowProject::query()->whereKey($instance->project_id)->value('error_workflow_project_id'))) {
                \Cms\Core\Services\Workflow\Triggers\StartErrorWorkflowJob::dispatch($instance->id);
            }

            if (data_get($instance->context_snapshot, 'trigger.meta.mode') === 'wait'
                && data_get($instance->context_snapshot, 'trigger.meta.parent_instance_id')
                && \Cms\Core\Services\Workflow\Triggers\SubWorkflowService::isFinished($instance)) {
                \Cms\Core\Services\Workflow\Triggers\ResumeParentWorkflowJob::dispatch($instance->id);
            }
        });
    }

    /**
     * Get the workflow project definition this instance is based on.
     * @return BelongsTo
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(WorkflowProject::class, 'project_id');
    }

    /**
     * The workflow version this run executes.
     * @return BelongsTo
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'version_id');
    }

    /**
     * Get all individual step executions within this instance.
     * @return HasMany
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowInstanceStep::class, 'instance_id');
    }

    /**
     * Get the user this instance acts for (set when the instance is created, never from workflow context).
     * @return BelongsTo
     */
    public function delegatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_by_user_id');
    }
}
