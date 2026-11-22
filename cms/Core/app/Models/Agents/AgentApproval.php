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

/**
 * Human review approval record for agent-produced artifacts.
 *
 * Tracks the review lifecycle of artifacts generated during an agent run,
 * including reviewer decision, application outcome, and timing metadata.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property int         $run_id                Foreign key to the agent run
 * @property int|null    $artifact_id           Foreign key to the reviewed artifact
 * @property string      $status                Approval status (pending, approved, rejected)
 * @property array|null  $diff_data             Diff payload showing proposed changes
 * @property string|null $reviewer_note         Free-text note from the reviewer
 * @property int|null    $reviewed_by_user_id   User who performed the review
 * @property \Carbon\Carbon|null $reviewed_at         When the review was completed
 * @property string|null $apply_outcome         Outcome after applying the approved artifact
 * @property string|null $apply_error           Error message if application failed
 * @property \Carbon\Carbon|null $apply_started_at    When artifact application began
 * @property \Carbon\Carbon|null $apply_finished_at   When artifact application finished
 * @property int|null    $applied_by_user_id    User who ran the last apply (null for system applies)
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read AgentRun      $run       The agent run that produced the artifact under review
 * @property-read AgentArtifact $artifact  The artifact being reviewed
 * @property-read \App\Models\User|null $reviewer The user who reviewed the approval
 * @property-read \App\Models\User|null $applier  The user who ran the last apply
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Agents
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentApproval extends Model
{
    use HasFactory;

    protected $table = Tables::AGENT_APPROVAL;

    protected $fillable = [
        'run_id',
        'artifact_id',
        'status',
        'diff_data',
        'reviewer_note',
        'reviewed_by_user_id',
        'reviewed_at',
        'apply_outcome',
        'apply_error',
        'apply_started_at',
        'apply_finished_at',
        'applied_by_user_id',
    ];

    protected $casts = [
        'diff_data' => 'array',
        'reviewed_by_user_id' => 'integer',
        'reviewed_at' => 'datetime',
        'apply_started_at' => 'datetime',
        'apply_finished_at' => 'datetime',
        'applied_by_user_id' => 'integer',
    ];

    /**
     * Get the agent run that produced the artifact under review.
     * @return BelongsTo
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }

    /**
     * Get the artifact being reviewed.
     * @return BelongsTo
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(AgentArtifact::class, 'artifact_id');
    }

    /**
     * Get the user who reviewed this approval.
     * @return BelongsTo
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * Get the user who ran the last apply of this approval.
     * @return BelongsTo
     */
    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }
}
