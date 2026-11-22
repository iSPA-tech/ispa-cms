<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models\Agents;

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Individual reasoning or action step within an agent run.
 *
 * Represents a single iteration of the agent's think-act-observe loop,
 * capturing the reasoning, chosen action, observation result, and any
 * associated tool calls or state snapshots.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property int         $run_id                   Foreign key to the owning agent run
 * @property int         $step_index               Zero-based sequential step number
 * @property string      $state                    Step lifecycle state
 * @property string|null $action_type              Type of action taken (tool_call, reasoning, observation)
 * @property string|null $action_name              Name of the specific action performed
 * @property string|null $reasoning_summary        Agent's reasoning for this step
 * @property string|null $observation_summary      Summary of the observation result
 * @property int|null    $observation_artifact_id   Artifact containing detailed observation
 * @property array|null  $snapshot_state            Agent state snapshot at this step
 * @property string|null $status                   Step execution status
 * @property string|null $terminal_reason          Reason if this step caused run termination
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read AgentRun           $run                 The owning agent run
 * @property-read AgentArtifact|null $observationArtifact Artifact with detailed observation data
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentToolCall[] $toolCalls Tool calls made in this step
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Agents
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentRunStep extends Model
{
    use HasFactory;

    protected $table = Tables::AGENT_RUN_STEP;

    protected $fillable = [
        'run_id',
        'step_index',
        'state',
        'action_type',
        'action_name',
        'reasoning_summary',
        'observation_summary',
        'observation_artifact_id',
        'snapshot_state',
        'status',
        'terminal_reason',
    ];

    protected $casts = [
        'step_index' => 'integer',
        'observation_artifact_id' => 'integer',
        'snapshot_state' => 'array',
    ];

    /**
     * Get the agent run this step belongs to.
     * @return BelongsTo
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }

    /**
     * Get the artifact containing detailed observation data for this step.
     * @return BelongsTo
     */
    public function observationArtifact(): BelongsTo
    {
        return $this->belongsTo(AgentArtifact::class, 'observation_artifact_id');
    }

    /**
     * Get all tool calls executed during this step.
     * @return HasMany
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class, 'run_step_id');
    }
}
