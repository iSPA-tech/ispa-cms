<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use Illuminate\Support\Str;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentMemory;
use Cms\Core\Models\Agents\AgentApproval;

/**
 * Normalizes reviewer rejection feedback into classified agent memory entries.
 *
 * When an approval is rejected with a reviewer note, this service classifies
 * the note (forbidden source, reviewer preference, field correction, or general
 * rejection) and persists it as an AgentMemory record with an expiration TTL.
 * Confidence scores are assigned based on keyword-prefix heuristics.
 *
 * @see AgentApprovalService
 * @see AgentMemory
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentFeedbackNormalizer
{
    /**
     * Capture and persist rejection feedback as a classified agent memory entry.
     *
     * @param AgentApproval $approval     The rejected approval.
     * @param string|null   $reviewerNote The reviewer's rejection note.
     * @param string|null   $reason       The declared reject reason key the reviewer picked, if any.
     * @return AgentMemory|null The created memory entry, or null if there is neither note nor reason.
     */
    public function captureRejectionFeedback(AgentApproval $approval, ?string $reviewerNote, ?string $reason = null): ?AgentMemory
    {
        $note = trim((string) $reviewerNote);
        $reason = trim((string) $reason);
        if ($reason !== '') {
            // Appended so a prefixed note (`source:`, `style:`, `field:`) keeps its classification.
            $note = trim($note . ' (reject reason: ' . $reason . ')');
        }
        if ($note === '') {
            return null;
        }

        $approval->loadMissing(['artifact', 'run.profile']);
        $run = $approval->run;

        $classification = $this->classify($note, $approval);

        return AgentMemory::query()->create([
            'entity_type' => $run?->target_type ?: $approval->artifact?->subject_type,
            'entity_id' => $run?->target_id ?: $approval->artifact?->subject_id,
            'memory_class' => 'feedback_overlay',
            'memory_type' => $classification['memory_type'],
            'context_key' => $this->buildContextKey($approval, $classification['memory_type']),
            'content' => $note,
            'source_citation' => [
                'approval_id' => $approval->getKey(),
                'run_id' => $run?->getKey(),
                'artifact_id' => $approval->artifact?->getKey(),
                'artifact_type' => $approval->artifact?->artifact_type,
                'profile_id' => $run?->agent_profile_id,
                'reviewed_by_user_id' => $approval->reviewed_by_user_id,
            ],
            'confidence_score' => $classification['confidence_score'],
            'usage_count' => 0,
            'decay_score' => 0,
            'quality_state' => 'active',
            'expires_at' => $this->expiresAt($classification['memory_type']),
            'last_used_at' => now(),
        ]);
    }

    /**
     * @return array{memory_type: string, confidence_score: float}
     */
    protected function classify(string $note, AgentApproval $approval): array
    {
        $normalized = Str::of($note)->trim()->lower()->value();

        if (Str::startsWith($normalized, ['source:', 'forbidden source:', 'citation:'])) {
            return ['memory_type' => 'forbidden_source', 'confidence_score' => 0.92];
        }

        if (Str::startsWith($normalized, ['style:', 'tone:', 'preference:'])) {
            return ['memory_type' => 'reviewer_preference', 'confidence_score' => 0.9];
        }

        if (Str::startsWith($normalized, ['field:', 'correction:', 'fix:'])) {
            return ['memory_type' => 'field_correction', 'confidence_score' => 0.88];
        }

        if (Str::contains($normalized, ['source', 'citation', 'hallucinated'])) {
            return ['memory_type' => 'forbidden_source', 'confidence_score' => 0.74];
        }

        if (Str::contains($normalized, ['tone', 'style', 'voice', 'too promotional'])) {
            return ['memory_type' => 'reviewer_preference', 'confidence_score' => 0.7];
        }

        if (
            Str::contains($normalized, ['field', 'incorrect', 'wrong', 'mismatch'])
            || Str::contains((string) $approval->artifact?->artifact_type, ['score', 'status', 'field'])
        ) {
            return ['memory_type' => 'field_correction', 'confidence_score' => 0.68];
        }

        return ['memory_type' => 'general_rejection', 'confidence_score' => 0.55];
    }

    protected function buildContextKey(AgentApproval $approval, string $memoryType): string
    {
        $artifactType = $approval->artifact?->artifact_type ?: 'generic_artifact';
        $moduleScope = $approval->run?->module_scope ?: 'global';

        return sprintf('approval_feedback.%s.%s.%s', $moduleScope, $artifactType, $memoryType);
    }

    protected function expiresAt(string $memoryType): \Illuminate\Support\Carbon
    {
        return match ($memoryType) {
            'field_correction' => now()->addDays(90),
            'forbidden_source' => now()->addDays(60),
            'reviewer_preference' => now()->addDays(45),
            default => now()->addDays(30),
        };
    }
}
