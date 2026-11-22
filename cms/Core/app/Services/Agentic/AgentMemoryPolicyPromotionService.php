<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use Illuminate\Support\Facades\DB;
use Cms\Core\Models\Agents\AgentMemory;
use Cms\Core\Models\Agents\AgentPolicy;

/**
 * Promotes high-confidence agent memories to active policies.
 *
 * Scans for memories that meet promotion criteria (high confidence,
 * sufficient usage count, active quality state, not already promoted)
 * and creates corresponding agent_policies entries. This closes the
 * feedback automation loop: repeated reviewer rejections → memory →
 * policy → automatic tool blocking.
 *
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0
 */
class AgentMemoryPolicyPromotionService
{
    protected const DEFAULT_MIN_CONFIDENCE = 0.85;
    protected const DEFAULT_MIN_USAGE_COUNT = 3;

    /**
     * Promote eligible memories to policies.
     *
     * @param  float $minConfidence  Minimum confidence score for promotion.
     * @param  int   $minUsageCount  Minimum usage count for promotion.
     * @return array{promoted: int, skipped: int, memories: array<int, int>}
     */
    public function promote(
        float $minConfidence = self::DEFAULT_MIN_CONFIDENCE,
        int $minUsageCount = self::DEFAULT_MIN_USAGE_COUNT,
    ): array {
        $candidates = AgentMemory::query()
            ->where('quality_state', 'active')
            ->whereNull('promoted_to_policy_at')
            ->where('confidence_score', '>=', $minConfidence)
            ->where('usage_count', '>=', $minUsageCount)
            ->whereIn('memory_type', ['forbidden_source', 'reviewer_preference', 'field_correction'])
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('confidence_score')
            ->limit(50)
            ->get();

        $promoted = 0;
        $skipped = 0;
        $promotedIds = [];

        foreach ($candidates as $memory) {
            $policyData = $this->buildPolicyFromMemory($memory);

            if ($policyData === null) {
                $skipped++;
                continue;
            }

            // Check if a similar policy already exists
            $exists = AgentPolicy::query()
                ->where('scope_type', $policyData['scope_type'])
                ->where('policy_type', $policyData['policy_type'])
                ->where('policy_key', $policyData['policy_key'])
                ->where('enabled', true)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            DB::transaction(function () use ($memory, $policyData) {
                AgentPolicy::create($policyData + [
                    'source_memory_id' => $memory->id,
                    'enabled' => true,
                ]);

                $memory->update([
                    'promoted_to_policy_at' => now(),
                    'quality_state' => 'verified',
                ]);
            });

            $promoted++;
            $promotedIds[] = $memory->id;
        }

        return [
            'promoted' => $promoted,
            'skipped' => $skipped,
            'memories' => $promotedIds,
        ];
    }

    /**
     * Build policy attributes from a memory entry.
     *
     * @param  AgentMemory $memory The memory to promote.
     * @return array<string, mixed>|null Policy attributes, or null if not promotable.
     */
    protected function buildPolicyFromMemory(AgentMemory $memory): ?array
    {
        $contextParts = explode('.', $memory->context_key ?? '');
        $moduleScope = $contextParts[1] ?? 'global';
        $artifactType = $contextParts[2] ?? null;

        return match ($memory->memory_type) {
            'forbidden_source' => [
                'scope_type' => 'module',
                'scope_id' => $moduleScope,
                'policy_type' => 'constraint',
                'policy_key' => 'forbidden_source',
                'policy_value' => [
                    'content' => $memory->content,
                    'artifact_type' => $artifactType,
                    'source' => 'memory_promotion',
                ],
            ],
            'reviewer_preference' => [
                'scope_type' => 'module',
                'scope_id' => $moduleScope,
                'policy_type' => 'preference',
                'policy_key' => 'reviewer_preference',
                'policy_value' => [
                    'content' => $memory->content,
                    'artifact_type' => $artifactType,
                    'source' => 'memory_promotion',
                ],
            ],
            'field_correction' => [
                'scope_type' => 'module',
                'scope_id' => $moduleScope,
                'policy_type' => 'constraint',
                'policy_key' => 'field_correction',
                'policy_value' => [
                    'content' => $memory->content,
                    'artifact_type' => $artifactType,
                    'source' => 'memory_promotion',
                ],
            ],
            default => null,
        };
    }
}
