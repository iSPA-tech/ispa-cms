<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models\Workflows;

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class WorkflowProject
 * -- About the WorkflowProject Entity
 *
 * @package Cms\Core\Models\Workflows
 * @author malayvuong
 * @since 1.0.0
 *
 * @property integer id
 * @property string name
 * @property string slug
 * @property string trigger_event
 * @property string target_entity_type
 * @property boolean is_active
 * @property array tags
 * @property string description
 * @property string|null schedule_cron         Cron expression for cms:workflow-run-scheduled (null = no schedule)
 * @property string|null schedule_timezone     Timezone the cron is read in (null = app timezone)
 * @property int|null    scheduled_by_user_id  User scheduled runs act for
 * @property \Carbon\Carbon|null last_scheduled_run_at  Last minute a scheduled run was started
 * @property int|null    error_workflow_project_id  Project to start when a run fails (recorded on the instance)
 * @property string|null webhook_token        Path token of the project's inbound webhook URL (null = no webhook)
 * @property string|null webhook_secret       Webhook secret (encrypted at rest, hidden from serialization)
 * @property float|null  budget_max_cost_per_run  USD cap per run before ai_spend steps (null = config default)
 * @property int|null    budget_max_runs_per_day  Runs per day cap before ai_spend steps (null = config default)
 * @property int|null    published_version_id  Version runs start on (null = never published; the project can not run)
 * @property string|null published_version_seal HMAC of "<id>|<published_version_id>" (WorkflowVersionSeal::signPointer; hidden)
 * @property array|null  draft_editor_ids     Users who changed the draft since it last matched the live version (WorkflowAuthors; hidden)
 * @property string|null paused_reason        Why the system turned the project off (`integrity`, breaker reasons)
 * @property \Carbon\Carbon|null paused_at    When the system turned it off
 * @property array|null  policy_exemptions    Publish steps exempt from the approval rule: [{step_code, by, at, reason}]
 * @property array|null  source               Hub library provenance {kind: hub, key, version, checksum, installed_hash, installed_hashes?} (null = made here)
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\Workflows\WorkflowStep[] $steps  Workflow steps in this project
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\Workflows\WorkflowConnection[] $connections  Workflow connections in this project
 */
class WorkflowProject extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = Tables::WORKFLOW_PROJECT;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'slug',
        'trigger_event',
        'target_entity_type',
        'is_active',
        'tags',
        'description',
        'schedule_cron',
        'schedule_timezone',
        'scheduled_by_user_id',
        'last_scheduled_run_at',
        'error_workflow_project_id',
        'budget_max_cost_per_run',
        'budget_max_runs_per_day',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'is_active' => 'boolean',
        'tags' => 'array',
        'scheduled_by_user_id' => 'integer',
        'last_scheduled_run_at' => 'datetime',
        'error_workflow_project_id' => 'integer',
        'budget_max_cost_per_run' => 'float',
        'budget_max_runs_per_day' => 'integer',
        'webhook_secret' => 'encrypted',
        'published_version_id' => 'integer',
        'paused_at' => 'datetime',
        'policy_exemptions' => 'array',
        'source' => 'array',
        'draft_editor_ids' => 'array',
    ];

    /**
     * Never serialized: the webhook URL token and its secret.
     *
     * @var array<int, string>
     */
    protected $hidden = ['webhook_token', 'webhook_secret', 'published_version_seal', 'draft_editor_ids'];

    /**
     * Project saves clear the trigger index (the trigger listeners are wired in CoreServiceProvider::boot()).
     */
    protected static function booted(): void
    {
        static::saved(static fn () => \Cms\Core\Services\Workflow\Triggers\TriggerIndex::forget());
        static::deleted(static fn () => \Cms\Core\Services\Workflow\Triggers\TriggerIndex::forget());
    }

    /**
     * Get the workflow steps for this project.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class, 'project_id');
    }

    /**
     * Get the workflow connections for this project.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function connections(): HasMany
    {
        return $this->hasMany(WorkflowConnection::class, 'project_id');
    }

    /**
     * Get the workflow runs (instances) of this project.
     *
     * @return HasMany
     */
    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'project_id');
    }

    /**
     * Versions of this project (B1), newest first.
     *
     * @return HasMany
     */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class, 'project_id')->orderByDesc('number');
    }

    /**
     * The version new runs start on.
     *
     * @return BelongsTo
     */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'published_version_id');
    }

    /**
     * Get the user scheduled runs act for.
     *
     * @return BelongsTo
     */
    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by_user_id');
    }
}
