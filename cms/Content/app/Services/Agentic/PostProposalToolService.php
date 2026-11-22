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
use Cms\Core\Agents\Attributes\AgentTool;
use Cms\Content\Interfaces\PostInterface;

/**
 * Agentic tool service for creating staged post mutation proposals.
 *
 * Exposes agent tools that build structured artifact payloads for
 * post description refresh and SEO metadata refresh, following the
 * Class 2 staged-mutation contract (approval required before apply).
 *
 * @see PostInterface
 * @see PostDescriptionArtifactApplier
 * @see PostSeoArtifactApplier
 * @package Cms\Content\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PostProposalToolService
{
    /**
     * Inject the post repository for reading post data during proposal generation.
     *
     * @param  PostInterface  $posts  Post repository providing post queries.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function __construct(protected PostInterface $posts)
    {
    }

    /**
     * Create a staged proposal to refresh a post description.
     *
     * @param  int          $post_id              The target post ID.
     * @param  string|null  $proposed_description  Optional explicit replacement description.
     * @param  string|null  $instruction           Optional instruction to guide generation.
     *
     * @return array<string, mixed> Structured artifact payload for the approval pipeline.
     *
     * @throws \InvalidArgumentException If the post is not found.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    #[AgentTool(
        name: 'content.propose_post_description_refresh',
        family: 'content',
        moduleScope: 'content',
        contractSteward: 'content',
        sideEffectClass: 'staged_write',
        approvalRequired: true,
        capabilityClass: 'class_2_staged_mutation',
        description: 'Create a staged proposal to refresh a post description in the Content module.'
    )]
    public function propose_post_description_refresh_tool(
        int $post_id,
        ?string $proposed_description = null,
        ?string $instruction = null,
    ): array {
        $post = $this->posts->find($post_id, ['id', 'name', 'description', 'type', 'status', 'updated_at']);
        if (!$post) {
            throw new InvalidArgumentException("Post [{$post_id}] not found.");
        }

        $currentDescription = trim((string) ($post->description ?? ''));
        $nextDescription = $this->buildDescription(
            (string) ($post->name ?? 'Untitled post'),
            $currentDescription,
            $proposed_description,
            $instruction
        );

        return [
            'artifact_type' => 'content_post_description_patch',
            'subject_type' => Post::class,
            'subject_id' => (string) $post->getKey(),
            'source_snapshot_ref' => 'content.post:' . $post->getKey(),
            'original_data_snapshot' => [
                'id' => $post->getKey(),
                'name' => $post->name,
                'description' => $post->description,
                'updated_at' => optional($post->updated_at)->toJSON(),
            ],
            'payload' => [
                'summary' => sprintf('Refresh description for post [%s].', $post->name ?? $post->getKey()),
                'source_snapshot' => [
                    'name' => $post->name,
                    'description' => $post->description,
                    'type' => $post->type,
                    'status' => $post->status,
                ],
                'proposed_change' => [
                    'description' => $nextDescription,
                ],
                'evidence' => [
                    [
                        'type' => 'post_snapshot',
                        'note' => 'Generated from the current post snapshot and optional operator instruction.',
                    ],
                ],
                'citations' => [],
            ],
            'render_hint' => 'text_diff',
            'apply_contract_version' => 'v1',
        ];
    }

    /**
     * Create a staged proposal to refresh SEO title, description, and hashtags for a post.
     *
     * @param  int          $post_id               The target post ID.
     * @param  int|null     $language_id            Language for the SEO record (falls back to post language).
     * @param  string|null  $proposed_title         Optional explicit SEO title.
     * @param  string|null  $proposed_description   Optional explicit SEO description.
     * @param  string|null  $proposed_hashtag       Optional explicit SEO hashtags.
     * @param  string|null  $instruction            Optional instruction to guide generation.
     *
     * @return array<string, mixed> Structured artifact payload for the approval pipeline.
     *
     * @throws \InvalidArgumentException If the post is not found or language_id is invalid.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    #[AgentTool(
        name: 'content.propose_post_seo_refresh',
        family: 'content',
        moduleScope: 'content',
        contractSteward: 'content',
        sideEffectClass: 'staged_write',
        approvalRequired: true,
        capabilityClass: 'class_2_staged_mutation',
        description: 'Create a staged proposal to refresh SEO title, description, and hashtags for a content post.'
    )]
    public function propose_post_seo_refresh_tool(
        int $post_id,
        ?int $language_id = null,
        ?string $proposed_title = null,
        ?string $proposed_description = null,
        ?string $proposed_hashtag = null,
        ?string $instruction = null,
    ): array {
        $post = $this->posts->find($post_id, ['id', 'name', 'description', 'type', 'status', 'updated_at', 'language_id'], ['seos']);
        if (! $post) {
            throw new InvalidArgumentException("Post [{$post_id}] not found.");
        }

        $targetLanguageId = (int) ($language_id ?? $post->language_id ?? 0);
        if ($targetLanguageId <= 0) {
            throw new InvalidArgumentException('Post SEO proposal requires a valid language_id.');
        }

        $currentSeo = $post->seos?->firstWhere('language_id', $targetLanguageId);
        $currentTitle = trim((string) ($currentSeo?->title ?? ''));
        $currentSeoDescription = trim((string) ($currentSeo?->description ?? ''));
        $currentHashtag = trim((string) ($currentSeo?->hashtag ?? ''));

        $nextTitle = $this->buildSeoField(
            $currentTitle,
            $proposed_title,
            (string) ($post->name ?? 'Untitled post'),
            $instruction,
            'SEO title'
        );
        $nextDescription = $this->buildSeoField(
            $currentSeoDescription,
            $proposed_description,
            trim((string) ($post->description ?? '')),
            $instruction,
            'SEO description'
        );
        $nextHashtag = $this->buildSeoHashtag(
            $currentHashtag,
            $proposed_hashtag,
            (string) ($post->name ?? 'post')
        );

        return [
            'artifact_type' => 'content_post_seo_patch',
            'subject_type' => Post::class,
            'subject_id' => (string) $post->getKey(),
            'source_snapshot_ref' => 'content.post:' . $post->getKey(),
            'original_data_snapshot' => [
                'id' => $post->getKey(),
                'name' => $post->name,
                'updated_at' => optional($post->updated_at)->toJSON(),
                'seo' => [
                    'language_id' => $targetLanguageId,
                    'title' => $currentSeo?->title,
                    'description' => $currentSeo?->description,
                    'hashtag' => $currentSeo?->hashtag,
                ],
            ],
            'payload' => [
                'summary' => sprintf('Refresh SEO metadata for post [%s].', $post->name ?? $post->getKey()),
                'source_snapshot' => [
                    'name' => $post->name,
                    'description' => $post->description,
                    'type' => $post->type,
                    'status' => $post->status,
                    'seo' => [
                        'language_id' => $targetLanguageId,
                        'title' => $currentSeo?->title,
                        'description' => $currentSeo?->description,
                        'hashtag' => $currentSeo?->hashtag,
                    ],
                ],
                'proposed_change' => [
                    'language_id' => $targetLanguageId,
                    'title' => $nextTitle,
                    'description' => $nextDescription,
                    'hashtag' => $nextHashtag,
                ],
                'evidence' => [
                    [
                        'type' => 'post_snapshot',
                        'note' => 'Generated from the current post snapshot and optional operator instruction.',
                    ],
                ],
                'citations' => [],
            ],
            'render_hint' => 'json_patch',
            'apply_contract_version' => 'v1',
        ];
    }

    protected function buildDescription(
        string $title,
        string $currentDescription,
        ?string $proposedDescription,
        ?string $instruction,
    ): string {
        if (is_string($proposedDescription) && trim($proposedDescription) !== '') {
            return trim($proposedDescription);
        }

        $segments = array_values(array_filter([
            trim($currentDescription) !== '' ? trim($currentDescription) : null,
            "Updated summary for {$title}.",
            is_string($instruction) && trim($instruction) !== '' ? trim($instruction) : null,
        ]));

        return implode(' ', $segments);
    }

    protected function buildSeoField(
        string $currentValue,
        ?string $proposedValue,
        string $fallbackSeed,
        ?string $instruction,
        string $label,
    ): string {
        if (is_string($proposedValue) && trim($proposedValue) !== '') {
            return trim($proposedValue);
        }

        $segments = array_values(array_filter([
            trim($currentValue) !== '' ? trim($currentValue) : null,
            trim($fallbackSeed) !== '' ? trim($fallbackSeed) : null,
            is_string($instruction) && trim($instruction) !== '' ? "{$label}: " . trim($instruction) : null,
        ]));

        return implode(' ', $segments);
    }

    protected function buildSeoHashtag(
        string $currentValue,
        ?string $proposedHashtag,
        string $title,
    ): string {
        if (is_string($proposedHashtag) && trim($proposedHashtag) !== '') {
            return trim($proposedHashtag);
        }

        if ($currentValue !== '') {
            return $currentValue;
        }

        return collect(preg_split('/\s+/', trim($title)) ?: [])
            ->filter(fn ($segment) => $segment !== '')
            ->map(fn ($segment) => ltrim(trim($segment), '#'))
            ->take(4)
            ->implode(',');
    }
}
