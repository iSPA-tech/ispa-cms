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
 * Agent profile defining an AI agent's identity, capabilities, and configuration.
 *
 * Represents a reusable agent configuration that specifies the system prompt,
 * model settings, budget limits, and which tools the agent is allowed to use.
 * Profiles are referenced by agent runs and schedules.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property string      $name                   Human-readable agent name
 * @property string      $slug                   URL-friendly unique identifier
 * @property string|null $description             Agent purpose description
 * @property string|null $capability_class        Capability classification for discovery
 * @property string      $status                  Profile status (active, disabled, archived)
 * @property string|null $system_prompt_template  System prompt template with placeholders
 * @property array|null  $model_config            LLM model configuration (provider, model, params)
 * @property array|null  $budget_config           Execution budget limits (tokens, steps, time)
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentRun[]            $runs             Execution runs using this profile
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentSchedule[]       $schedules        Scheduled executions for this profile
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentToolDefinition[] $toolDefinitions  Tools available to this profile (pivot)
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Agents
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentProfile extends Model
{
    use HasFactory;

    protected $table = Tables::AGENT_PROFILE;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'capability_class',
        'status',
        'system_prompt_template',
        'model_config',
        'budget_config',
    ];

    protected $casts = [
        'model_config' => 'array',
        'budget_config' => 'array',
    ];

    /**
     * Get all execution runs that used this profile.
     * @return HasMany
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'agent_profile_id');
    }

    /**
     * Get all scheduled executions configured for this profile.
     * @return HasMany
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(AgentSchedule::class, 'agent_profile_id');
    }

    /**
     * Get the tool definitions available to this profile via pivot table.
     * @return BelongsToMany
     */
    public function toolDefinitions(): BelongsToMany
    {
        return $this->belongsToMany(
            AgentToolDefinition::class,
            Tables::AGENT_PROFILE_TOOL,
            'agent_profile_id',
            'tool_definition_id'
        )->withPivot(['enabled', 'safety_override'])->withTimestamps();
    }
}
