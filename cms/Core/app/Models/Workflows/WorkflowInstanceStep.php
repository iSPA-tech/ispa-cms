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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Execution record for a single step within a workflow instance.
 *
 * Tracks the runtime state of an individual workflow step execution,
 * including input/output data, retry counts, timing, and error details.
 * Links back to both the parent instance and the step definition.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property int         $instance_id    Foreign key to the workflow instance
 * @property int         $step_id        Foreign key to the workflow step definition
 * @property string      $status         Step execution status (pending, running, completed, failed, skipped)
 * @property \Carbon\Carbon|null $started_at   When step execution began
 * @property \Carbon\Carbon|null $finished_at  When step execution ended
 * @property int         $retry_count    Number of retry attempts made
 * @property array|null  $input_data     Input data passed to the step
 * @property array|null  $result_data    Output data produced by the step
 * @property string|null $error_message  Error details if the step failed
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * ==== Relationships
 * @property-read WorkflowInstance $instance The parent workflow instance
 * @property-read \Cms\Core\Models\Workflows\WorkflowStep $step The step definition
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Workflows
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowInstanceStep extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = Tables::WORKFLOW_INSTANCE_STEP;

    protected $fillable = [
        'instance_id',
        'step_id',
        'status',
        'started_at',
        'finished_at',
        'retry_count',
        'input_data',
        'result_data',
        'error_message',
    ];

    protected $casts = [
        'instance_id' => 'integer',
        'step_id' => 'integer',
        'retry_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'input_data' => 'array',
        'result_data' => 'array',
    ];

    /**
     * Get the parent workflow instance this step execution belongs to.
     * @return BelongsTo
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    /**
     * Get the workflow step definition this execution is based on.
     * @return BelongsTo
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'step_id');
    }
}
