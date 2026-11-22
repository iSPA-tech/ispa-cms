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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Abstract definition of a tool that agents can invoke.
 *
 * Describes a tool's contract including its input/output schemas, safety
 * classification, retry and idempotency policies, locking behavior, and
 * whether human approval or citation is required before execution.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property string      $tool_name              Unique tool identifier
 * @property string|null $tool_family            Tool family grouping (e.g. cms, database, external)
 * @property string|null $contract_steward       Team/person responsible for the tool contract
 * @property string|null $description            Human-readable tool description
 * @property array|null  $input_schema           JSON Schema for tool input validation
 * @property array|null  $output_schema          JSON Schema for tool output validation
 * @property string|null $safety_level           Safety classification (safe, cautious, dangerous)
 * @property string|null $side_effect_class      Side effect type (none, read_only, write, destructive)
 * @property int|null    $timeout_seconds        Maximum execution time in seconds
 * @property array|null  $retry_policy           Retry configuration (max_attempts, backoff, etc.)
 * @property array|null  $idempotency_strategy   Strategy for ensuring idempotent execution
 * @property array|null  $lock_scope             Resource locking configuration
 * @property array|null  $compensation_policy    Rollback/compensation strategy on failure
 * @property bool        $citation_required      Whether output requires source citations
 * @property bool        $approval_required      Whether execution requires human approval
 * @property string|null $capability_class       Capability classification for discovery
 * @property string      $status                 Definition status (active, deprecated, disabled)
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentToolBinding[] $bindings  Runtime implementation bindings
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentSchedule[]    $schedules Schedules using this tool
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentProfile[]     $profiles  Profiles with access to this tool (pivot)
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Agents
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentToolDefinition extends Model
{
    use HasFactory;

    protected $table = Tables::AGENT_TOOL_DEFINITION;

    protected $fillable = [
        'tool_name',
        'tool_family',
        'contract_steward',
        'description',
        'input_schema',
        'output_schema',
        'safety_level',
        'side_effect_class',
        'timeout_seconds',
        'retry_policy',
        'idempotency_strategy',
        'lock_scope',
        'compensation_policy',
        'citation_required',
        'approval_required',
        'capability_class',
        'status',
    ];

    protected $casts = [
        'input_schema' => 'array',
        'output_schema' => 'array',
        'timeout_seconds' => 'integer',
        'retry_policy' => 'array',
        'idempotency_strategy' => 'array',
        'lock_scope' => 'array',
        'compensation_policy' => 'array',
        'citation_required' => 'boolean',
        'approval_required' => 'boolean',
    ];

    /**
     * Get all runtime implementation bindings for this tool.
     * @return HasMany
     */
    public function bindings(): HasMany
    {
        return $this->hasMany(AgentToolBinding::class, 'tool_definition_id');
    }

    /**
     * Get all schedules that use this tool definition.
     * @return HasMany
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(AgentSchedule::class, 'tool_definition_id');
    }

    /**
     * Get the agent profiles that have access to this tool via pivot table.
     * @return BelongsToMany
     */
    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(
            AgentProfile::class,
            Tables::AGENT_PROFILE_TOOL,
            'tool_definition_id',
            'agent_profile_id'
        )->withPivot(['enabled', 'safety_override'])->withTimestamps();
    }
}
