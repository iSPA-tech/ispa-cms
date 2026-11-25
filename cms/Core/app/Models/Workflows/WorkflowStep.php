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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class WorkflowStep
 * -- About the WorkflowStep Entity
 *
 * @package Cms\Core\Models\Workflows
 * @author malayvuong
 * @since 1.0.0
 *
 * @property integer id
 * @property integer project_id
 * @property string step_code
 * @property string name
 * @property string step_type
 * @property string step_config
 * @property string input_schema
 * @property string output_schema
 * @property integer position_x
 * @property integer position_y
 * @property boolean is_start
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * @property-read \Cms\Core\Models\Workflows\WorkflowProject $project  Workflow project this step belongs to
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\Workflows\WorkflowConnection[] $outgoingConnections  Outgoing connections from this step
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\Workflows\WorkflowConnection[] $incomingConnections  Incoming connections to this step
 */
class WorkflowStep extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = Tables::WORKFLOW_STEP;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'project_id', 'step_code', 'name', 'step_type',
        'step_config', 'input_schema', 'output_schema',
        'position_x', 'position_y', 'is_start',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'project_id' => 'integer',
        'step_config' => 'json',
        'input_schema' => 'json',
        'output_schema' => 'json',
        'position_x' => 'integer',
        'position_y' => 'integer',
        'is_start' => 'boolean',
    ];

    /**
     * Trigger start nodes keep the project's trigger columns in step (schedule, scheduler) and the trigger index fresh.
     */
    protected static function booted(): void
    {
        static::saved(static fn (self $step) => \Cms\Core\Services\Workflow\Triggers\TriggerConfigSync::stepSaved($step));
        static::deleted(static fn (self $step) => \Cms\Core\Services\Workflow\Triggers\TriggerConfigSync::stepDeleted($step));
    }

    /**
     * Get the workflow project that this step belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(WorkflowProject::class, 'project_id');
    }

    /**
     * Get the outgoing connections from this step.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany|\Cms\Core\Models\Workflows\WorkflowStep
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function outgoingConnections(): HasMany|WorkflowStep
    {
        return $this->hasMany(WorkflowConnection::class, 'from_step_id');
    }

    /**
     * Get the incoming connections to this step.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany|\Cms\Core\Models\Workflows\WorkflowStep
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function incomingConnections(): HasMany|WorkflowStep
    {
        return $this->hasMany(WorkflowConnection::class, 'to_step_id');
    }
}
