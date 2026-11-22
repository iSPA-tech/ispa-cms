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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Audit record of a single tool invocation during an agent run step.
 *
 * Captures full details of each tool call: which tool was invoked, what input
 * was provided, what output was returned, execution timing, retry behavior,
 * and idempotency tracking for reliable tool execution.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property int         $run_id               Foreign key to the agent run
 * @property int|null    $run_step_id           Foreign key to the run step
 * @property int|null    $tool_definition_id    Foreign key to the tool definition
 * @property int|null    $tool_binding_id       Foreign key to the resolved tool binding
 * @property int|null    $input_artifact_id     Artifact containing the input data
 * @property int|null    $output_artifact_id    Artifact containing the output data
 * @property array|null  $input_payload         Tool invocation input payload
 * @property array|null  $output_payload        Tool execution output payload
 * @property string|null $retry_scope           Retry scope identifier
 * @property string      $status                Call status (pending, running, succeeded, failed)
 * @property int|null    $duration_ms           Execution duration in milliseconds
 * @property int|null    $retry_count           Number of retry attempts
 * @property string|null $idempotency_key       Idempotency key for safe retries
 * @property string|null $error_message         Error message if the call failed
 * @property \Carbon\Carbon|null $started_at    When the tool call began
 * @property \Carbon\Carbon|null $finished_at   When the tool call completed
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read AgentRun                $run             The owning agent run
 * @property-read AgentRunStep|null       $step            The run step this call belongs to
 * @property-read AgentToolDefinition|null $definition     The tool definition invoked
 * @property-read AgentToolBinding|null   $binding         The resolved runtime binding
 * @property-read AgentArtifact|null      $inputArtifact   Artifact with input data
 * @property-read AgentArtifact|null      $outputArtifact  Artifact with output data
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Agents
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentToolCall extends Model
{
    use HasFactory;

    protected $table = Tables::AGENT_TOOL_CALL;

    protected $fillable = [
        'run_id',
        'run_step_id',
        'tool_definition_id',
        'tool_binding_id',
        'input_artifact_id',
        'output_artifact_id',
        'input_payload',
        'output_payload',
        'retry_scope',
        'status',
        'duration_ms',
        'retry_count',
        'idempotency_key',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'run_step_id' => 'integer',
        'tool_definition_id' => 'integer',
        'tool_binding_id' => 'integer',
        'input_artifact_id' => 'integer',
        'output_artifact_id' => 'integer',
        'input_payload' => 'array',
        'output_payload' => 'array',
        'duration_ms' => 'integer',
        'retry_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Get the agent run this tool call belongs to.
     * @return BelongsTo
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }

    /**
     * Get the run step this tool call was executed within.
     * @return BelongsTo
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(AgentRunStep::class, 'run_step_id');
    }

    /**
     * Get the tool definition that was invoked.
     * @return BelongsTo
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(AgentToolDefinition::class, 'tool_definition_id');
    }

    /**
     * Get the resolved runtime binding used for this call.
     * @return BelongsTo
     */
    public function binding(): BelongsTo
    {
        return $this->belongsTo(AgentToolBinding::class, 'tool_binding_id');
    }

    /**
     * Get the artifact containing the input data for this call.
     * @return BelongsTo
     */
    public function inputArtifact(): BelongsTo
    {
        return $this->belongsTo(AgentArtifact::class, 'input_artifact_id');
    }

    /**
     * Get the artifact containing the output data from this call.
     * @return BelongsTo
     */
    public function outputArtifact(): BelongsTo
    {
        return $this->belongsTo(AgentArtifact::class, 'output_artifact_id');
    }
}
