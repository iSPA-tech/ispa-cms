<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Services\Agentic;

use App\Models\Seo;
use InvalidArgumentException;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Content\Models\Post;
use Cms\Core\Agents\Contracts\ArtifactApplierInterface;
use Cms\Core\Services\Agentic\Exceptions\ApprovalStaleException;

/**
 * Applies an approved SEO-refresh artifact to a Content post.
 *
 * Performs staleness checks (updated_at and per-field SEO snapshot) before
 * delegating the actual SEO update to {@see PostSeoMutationService}.
 *
 * @see AgentArtifact
 * @see PostSeoMutationService
 * @package Cms\Content\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PostSeoArtifactApplier implements ArtifactApplierInterface
{
    /**
     * Inject the SEO mutation service for applying approved post SEO changes.
     *
     * @param  PostSeoMutationService  $mutations  Service handling post SEO record updates.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function __construct(protected PostSeoMutationService $mutations)
    {
    }

    /**
     * Apply the approved SEO artifact to the target post.
     *
     * @param  AgentArtifact  $artifact  The artifact containing the proposed SEO changes.
     * @param  AgentApproval  $approval  The approval record authorizing the change.
     *
     * @return array<string, mixed> Post ID, language ID, updated SEO fields, and approval ID.
     *
     * @throws \InvalidArgumentException   If subject_id or language_id is invalid, or post not found.
     * @throws ApprovalStaleException      If the post or its SEO record changed after proposal.
     */
    public function apply(AgentArtifact $artifact, AgentApproval $approval): array
    {
        $postId = (int) ($artifact->subject_id ?? 0);
        if ($postId <= 0) {
            throw new InvalidArgumentException('Artifact subject_id must be a valid post id.');
        }

        $languageId = (int) data_get($artifact->payload, 'proposed_change.language_id', data_get($artifact->original_data_snapshot, 'seo.language_id', 0));
        if ($languageId <= 0) {
            throw new InvalidArgumentException('Artifact proposed_change.language_id is required.');
        }

        $post = Post::query()->with('seos')->find($postId);
        if (! $post) {
            throw new InvalidArgumentException("Post [{$postId}] not found.");
        }

        $baselineUpdatedAt = data_get($artifact->original_data_snapshot, 'updated_at');
        $currentUpdatedAt = optional($post->updated_at)->toJSON();
        if ($baselineUpdatedAt !== null && (string) $baselineUpdatedAt !== (string) $currentUpdatedAt) {
            throw new ApprovalStaleException("Post [{$postId}] changed after the SEO proposal was generated.");
        }

        /** @var Seo|null $currentSeo */
        $currentSeo = $post->seos->firstWhere('language_id', $languageId);
        $baselineSeo = (array) data_get($artifact->original_data_snapshot, 'seo', []);

        foreach (['title', 'description', 'hashtag'] as $field) {
            $baselineValue = array_key_exists($field, $baselineSeo) ? (string) ($baselineSeo[$field] ?? '') : '';
            $currentValue = (string) ($currentSeo?->{$field} ?? '');

            if ($baselineValue !== $currentValue) {
                throw new ApprovalStaleException("Post [{$postId}] SEO {$field} changed after the proposal was generated.");
            }
        }

        return $this->mutations->updateSeo($postId, [
            'language_id' => $languageId,
            'title' => data_get($artifact->payload, 'proposed_change.title'),
            'description' => data_get($artifact->payload, 'proposed_change.description'),
            'hashtag' => data_get($artifact->payload, 'proposed_change.hashtag'),
            'approval_id' => $approval->id,
        ]) + [
            'approval_id' => $approval->id,
        ];
    }
}
