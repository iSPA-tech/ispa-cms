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
 * Policy rule governing agent behavior within a specific scope.
 *
 * Defines constraints and guardrails for agent execution, scoped to
 * specific entities (profiles, tools, modules). Policies can be manually
 * created by users or automatically promoted from agent memories.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property string      $scope_type           Polymorphic scope type (profile, tool, global)
 * @property int|string  $scope_id             Polymorphic scope identifier
 * @property string      $policy_type          Type of policy (constraint, guardrail, preference)
 * @property string      $policy_key           Unique key identifying the policy rule
 * @property array|null  $policy_value         Structured policy configuration
 * @property int|null    $source_memory_id     Memory entry this policy was promoted from
 * @property bool        $enabled              Whether the policy is currently active
 * @property int|null    $created_by_user_id   User who created this policy
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read AgentMemory|null       $sourceMemory The memory entry this policy was derived from
 * @property-read \App\Models\User|null  $createdBy    The user who created this policy
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Agents
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentPolicy extends Model
{
    use HasFactory;

    protected $table = Tables::AGENT_POLICY;

    protected $fillable = [
        'scope_type',
        'scope_id',
        'policy_type',
        'policy_key',
        'policy_value',
        'source_memory_id',
        'enabled',
        'created_by_user_id',
    ];

    protected $casts = [
        'policy_value' => 'array',
        'source_memory_id' => 'integer',
        'enabled' => 'boolean',
        'created_by_user_id' => 'integer',
    ];

    /**
     * Get the memory entry this policy was promoted from.
     * @return BelongsTo
     */
    public function sourceMemory(): BelongsTo
    {
        return $this->belongsTo(AgentMemory::class, 'source_memory_id');
    }

    /**
     * Get the user who manually created this policy.
     * @return BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
