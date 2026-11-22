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
 * Pivot model linking agent profiles to their available tool definitions.
 *
 * Controls which tools a specific agent profile can invoke, with per-profile
 * enable/disable toggles and optional safety level overrides.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property int         $agent_profile_id    Foreign key to the agent profile
 * @property int         $tool_definition_id  Foreign key to the tool definition
 * @property bool        $enabled             Whether this tool is enabled for the profile
 * @property string|null $safety_override     Optional safety level override for this profile-tool pair
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read AgentProfile        $profile    The agent profile
 * @property-read AgentToolDefinition $definition The tool definition
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Agents
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentProfileTool extends Model
{
    use HasFactory;

    protected $table = Tables::AGENT_PROFILE_TOOL;

    protected $fillable = [
        'agent_profile_id',
        'tool_definition_id',
        'enabled',
        'safety_override',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * Get the agent profile this tool assignment belongs to.
     * @return BelongsTo
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    /**
     * Get the tool definition assigned to the profile.
     * @return BelongsTo
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(AgentToolDefinition::class, 'tool_definition_id');
    }
}
