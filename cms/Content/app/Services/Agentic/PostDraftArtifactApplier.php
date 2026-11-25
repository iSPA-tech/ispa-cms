<?php

declare(strict_types=1);

namespace Cms\Content\Services\Agentic;

use Cms\Content\Services\Workflow\PostDraftWriter;
use Cms\Core\Agents\Contracts\ArtifactApplierInterface;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Services\Agentic\Exceptions\ApprovalStaleException;
use InvalidArgumentException;

/**
 * Applies an approved draft proposal (`content_post_draft_patch`) to a Content post.
 *
 * With `payload.post_uuid` it rewrites that post's draft fields with the approved (possibly edited) values; the post
 * must still be a draft and unchanged since the proposal (`payload.draft_updated_at` and `payload.draft_hash`). Without it, it creates a new
 * draft. It never publishes and never creates tags.
 *
 * @package Cms\Content\Services\Agentic
 * @since   2026-11-23
 */
class PostDraftArtifactApplier implements ArtifactApplierInterface
{
    public const ARTIFACT_TYPE = 'content_post_draft_patch';

    public function __construct(protected PostDraftWriter $writer)
    {
    }

    public function apply(AgentArtifact $artifact, AgentApproval $approval): array
    {
        $payload = (array) $artifact->payload;
        $fields = data_get($payload, 'proposed_change');
        if (!is_array($fields) || $fields === []) {
            throw new InvalidArgumentException('Artifact proposed_change is required.');
        }
        $fields = array_intersect_key($fields, array_flip(PostDraftWriter::DRAFT_FIELDS));
        unset($fields['create_missing_tags']);

        $uuid = is_string($payload['post_uuid'] ?? null) && $payload['post_uuid'] !== '' ? $payload['post_uuid'] : null;
        if ($uuid !== null) {
            $current = $this->writer->findByUuid($uuid);
            if ($current === null) {
                throw new InvalidArgumentException("Post {$uuid} was not found.");
            }

            $expectedAt = $payload['draft_updated_at'] ?? null;
            $expectedHash = $payload['draft_hash'] ?? null;
            if (($expectedAt !== null && (string) $expectedAt !== (string) optional($current->updated_at)->toJSON())
                || (is_string($expectedHash) && !hash_equals($expectedHash, PostDraftWriter::draftHash($this->writer->draftView($current))))) {
                throw new ApprovalStaleException("Post {$uuid} changed after the draft was proposed.");
            }
        }

        $post = $this->writer->saveDraft($fields, $uuid, $approval->reviewed_by_user_id !== null ? (int) $approval->reviewed_by_user_id : null);

        return [
            'post_uuid' => $post->uuid,
            'created' => $uuid === null,
            'status' => $post->status,
            'approval_id' => $approval->id,
        ];
    }
}
