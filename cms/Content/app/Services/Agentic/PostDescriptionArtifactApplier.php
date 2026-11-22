<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Services\Agentic;

use InvalidArgumentException;
use Cms\Content\Models\Post;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;
use Cms\Core\Agents\Contracts\ArtifactApplierInterface;
use Cms\Core\Services\Agentic\Exceptions\ApprovalStaleException;

/**
 * Applies an approved description-refresh artifact to a Content post.
 *
 * Performs staleness checks (updated_at, description snapshot) before writing
 * the proposed description. Invalidates post cache on success.
 *
 * @see AgentArtifact
 * @see PostInterface
 * @package Cms\Content\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PostDescriptionArtifactApplier implements ArtifactApplierInterface
{
    /**
     * Inject the post repository for reading and updating post descriptions.
     *
     * @param  PostInterface  $posts  Post repository providing find and update operations.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function __construct(protected PostInterface $posts)
    {
    }

    /**
     * Apply the approved description artifact to the target post.
     *
     * @param  AgentArtifact  $artifact  The artifact containing the proposed description change.
     * @param  AgentApproval  $approval  The approval record authorizing the change.
     *
     * @return array<string, mixed> Post ID, updated description, and approval ID.
     *
     * @throws \InvalidArgumentException   If subject_id is invalid or post not found.
     * @throws ApprovalStaleException      If the post changed after proposal generation.
     */
    public function apply(AgentArtifact $artifact, AgentApproval $approval): array
    {
        $postId = (int) ($artifact->subject_id ?? 0);
        if ($postId <= 0) {
            throw new InvalidArgumentException('Artifact subject_id must be a valid post id.');
        }

        $post = $this->posts->find($postId, ['id', 'name', 'description', 'updated_at']);
        if (!$post) {
            throw new InvalidArgumentException("Post [{$postId}] not found.");
        }

        $baselineUpdatedAt = data_get($artifact->original_data_snapshot, 'updated_at');
        $currentUpdatedAt = optional($post->updated_at)->toJSON();
        if ($baselineUpdatedAt !== null && (string) $baselineUpdatedAt !== (string) $currentUpdatedAt) {
            throw new ApprovalStaleException("Post [{$postId}] changed after the proposal was generated.");
        }

        $baselineDescription = data_get($artifact->original_data_snapshot, 'description');
        $currentDescription = (string) ($post->description ?? '');
        if ($baselineDescription !== null && (string) $baselineDescription !== $currentDescription) {
            throw new ApprovalStaleException("Post [{$postId}] changed after the proposal was generated.");
        }

        $proposedDescription = data_get($artifact->payload, 'proposed_change.description');
        if (!is_string($proposedDescription) || trim($proposedDescription) === '') {
            throw new InvalidArgumentException('Artifact proposed_change.description is required.');
        }

        $invalidator = app(ContentPostCacheInvalidationService::class);
        $cacheBefore = $invalidator->snapshot(Post::query()->find($postId));

        $this->posts->update(['id' => $postId], ['description' => trim($proposedDescription)]);
        $invalidator->invalidateSnapshots($cacheBefore, $invalidator->snapshot(Post::query()->find($postId)));

        $fresh = $this->posts->find($postId, ['id', 'name', 'description', 'updated_at']);

        return [
            'post_id' => $postId,
            'description' => $fresh?->description,
            'approval_id' => $approval->id,
        ];
    }
}
