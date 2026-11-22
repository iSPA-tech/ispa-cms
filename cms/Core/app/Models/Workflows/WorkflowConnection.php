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
 * Directed edge connecting two workflow steps within a workflow project.
 *
 * Defines the transition path between workflow steps, including optional
 * conditional logic that determines when the connection is traversable.
 * Supports soft deletes for version-safe workflow editing.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property int         $project_id     Foreign key to the workflow project
 * @property int         $from_step_id   Source step (outgoing edge)
 * @property int         $to_step_id     Target step (incoming edge)
 * @property array|null  $condition      JSON condition expression for conditional transitions
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * ==== Relationships
 * @property-read \Cms\Core\Models\Workflows\WorkflowProject $project  The owning workflow project
 * @property-read \Cms\Core\Models\Workflows\WorkflowStep    $fromStep The source step
 * @property-read \Cms\Core\Models\Workflows\WorkflowStep    $toStep   The target step
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Workflows
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowConnection extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = Tables::WORKFLOW_CONNECTION;

    protected $fillable = [
        'project_id',
        'from_step_id',
        'to_step_id',
        'condition',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'from_step_id' => 'integer',
        'to_step_id' => 'integer',
        'condition' => 'json',
    ];

    /**
     * Get the workflow project this connection belongs to.
     * @return BelongsTo
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(WorkflowProject::class, 'project_id');
    }

    /**
     * Get the source step of this connection (outgoing edge).
     * @return BelongsTo
     */
    public function fromStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'from_step_id');
    }

    /**
     * Get the target step of this connection (incoming edge).
     * @return BelongsTo
     */
    public function toStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'to_step_id');
    }
}
