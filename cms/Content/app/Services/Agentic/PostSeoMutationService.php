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
use Cms\Content\Models\Post;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;

/**
 * Handles direct SEO metadata mutations for Content posts.
 *
 * Syncs SEO title, description, and hashtags via the Post model's syncSeo()
 * method, then invalidates the post cache. Used by {@see PostSeoArtifactApplier}
 * after approval validation.
 *
 * @see Post
 * @see PostSeoArtifactApplier
 * @package Cms\Content\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PostSeoMutationService
{
    /**
     * Update SEO metadata for a post in the given language.
     *
     * @param  int                    $postId      The target post ID.
     * @param  array<string, mixed>   $attributes  SEO fields: language_id, title, description, hashtag.
     *
     * @return array<string, mixed> The post ID, language ID, and refreshed SEO values.
     *
     * @throws \InvalidArgumentException If the post is not found or language_id is invalid.
     */
    public function updateSeo(int $postId, array $attributes): array
    {
        $post = Post::query()->with('seos')->find($postId);
        if (! $post) {
            throw new InvalidArgumentException("Post [{$postId}] not found.");
        }

        $languageId = (int) ($attributes['language_id'] ?? $post->language_id ?? 0);
        if ($languageId <= 0) {
            throw new InvalidArgumentException('SEO update requires a valid language_id.');
        }

        $invalidator = app(ContentPostCacheInvalidationService::class);
        $cacheBefore = $invalidator->snapshot($post);

        $seoPayload = [[
            'language_id' => $languageId,
            'title' => (string) ($attributes['title'] ?? ''),
            'description' => (string) ($attributes['description'] ?? ''),
            'hashtag' => (string) ($attributes['hashtag'] ?? ''),
        ]];

        $post->syncSeo($seoPayload);
        $invalidator->invalidateSnapshots($cacheBefore, $invalidator->snapshot($post->fresh()));

        /** @var Seo|null $seo */
        $seo = $post->fresh(['seos'])?->seos?->firstWhere('language_id', $languageId);

        return [
            'post_id' => $post->getKey(),
            'language_id' => $languageId,
            'title' => $seo?->title,
            'description' => $seo?->description,
            'hashtag' => $seo?->hashtag,
        ];
    }
}
