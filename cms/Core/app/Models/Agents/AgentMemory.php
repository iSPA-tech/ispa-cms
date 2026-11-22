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

/**
 * Persistent memory entry for agent knowledge retention and recall.
 *
 * Stores learned facts, observations, and contextual knowledge that agents
 * accumulate over time. Supports decay scoring, confidence tracking, usage
 * counting, and optional promotion to formal policies.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property string      $entity_type         Polymorphic entity type (agent profile, user, etc.)
 * @property int|string  $entity_id           Polymorphic entity identifier
 * @property string      $memory_class        Classification of the memory (short_term, long_term, episodic)
 * @property string      $memory_type         Specific type within the class (fact, observation, rule)
 * @property string|null $context_key         Contextual namespace key for retrieval
 * @property string      $content             The memory content text
 * @property string|null $embedding_ref       Reference to the vector embedding for semantic search
 * @property array|null  $source_citation     Citation metadata for the memory source
 * @property string|null $confidence_score    Confidence level of the memory (0.0000 - 1.0000)
 * @property int         $usage_count         Number of times this memory has been recalled
 * @property string|null $decay_score         Decay factor for memory relevance over time (0.0000 - 1.0000)
 * @property string|null $quality_state       Quality classification (verified, unverified, deprecated)
 * @property \Carbon\Carbon|null $promoted_to_policy_at  When the memory was promoted to a policy
 * @property \Carbon\Carbon|null $expires_at             Expiration timestamp
 * @property \Carbon\Carbon|null $last_used_at           Last recall timestamp
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentPolicy[] $policies Policies derived from this memory
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Agents
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentMemory extends Model
{
    use HasFactory;

    protected $table = Tables::AGENT_MEMORY;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'memory_class',
        'memory_type',
        'context_key',
        'content',
        'embedding_ref',
        'source_citation',
        'confidence_score',
        'usage_count',
        'decay_score',
        'quality_state',
        'promoted_to_policy_at',
        'expires_at',
        'last_used_at',
    ];

    protected $casts = [
        'source_citation' => 'array',
        'confidence_score' => 'decimal:4',
        'usage_count' => 'integer',
        'decay_score' => 'decimal:4',
        'promoted_to_policy_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    /**
     * Get the policies that were derived from this memory entry.
     * @return HasMany
     */
    public function policies(): HasMany
    {
        return $this->hasMany(AgentPolicy::class, 'source_memory_id');
    }
}
