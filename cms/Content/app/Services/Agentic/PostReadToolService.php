<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Services\Agentic;

use InvalidArgumentException;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Cms\Content\Interfaces\PostInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Cms\Core\Agents\Attributes\AgentTool;

/**
 * Agentic read-only tool service for Content posts.
 *
 * Exposes Class 0 (read-only) agent tools for retrieving latest posts,
 * searching posts, summarizing individual posts, and suggesting internal
 * links based on category relationships.
 *
 * @see PostInterface
 * @package Cms\Content\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PostReadToolService
{
    /**
     * Inject the post repository for read-only post queries.
     *
     * @param  PostInterface  $posts  Post repository providing listing, search, and related post queries.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function __construct(protected PostInterface $posts)
    {
    }

    /**
     * Retrieve the latest published posts.
     *
     * @param  int  $limit  Maximum number of posts to return.
     *
     * @return array<string, mixed> Contains limit and normalized item list.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    #[AgentTool(
        name: 'content.latest_posts',
        family: 'content',
        moduleScope: 'content',
        contractSteward: 'content',
        capabilityClass: 'class_0_read_only',
        description: 'Read the latest published posts from the Content module.'
    )]
    public function latest_posts_tool(int $limit = 5): array
    {
        $posts = $this->posts->getLatestPosts($limit);

        return [
            'limit' => $limit,
            'items' => $this->normalizeResult($posts),
        ];
    }

    /**
     * Search published posts by keywords and article type.
     *
     * @param  string|null  $keywords      Search keywords (empty returns latest).
     * @param  string       $article_type  Article type filter (default: 'post').
     * @param  int          $limit         Maximum results (clamped 1-20).
     *
     * @return array<string, mixed> Contains keywords, article_type, limit, and normalized items.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    #[AgentTool(
        name: 'content.search_posts',
        family: 'content',
        moduleScope: 'content',
        contractSteward: 'content',
        capabilityClass: 'class_0_read_only',
        description: 'Search published Content posts for research and drafting support.'
    )]
    public function search_posts_tool(?string $keywords = null, string $article_type = 'post', int $limit = 5): array
    {
        $limit = max(1, min($limit, 20));
        $articleType = trim($article_type) !== '' ? trim($article_type) : 'post';
        $keywords = trim((string) $keywords);

        $posts = $this->posts->searchByType(
            $keywords !== '' ? $keywords : null,
            $limit,
            1,
            $articleType
        );

        return [
            'keywords' => $keywords,
            'article_type' => $articleType,
            'limit' => $limit,
            'items' => $this->normalizeResult($posts),
        ];
    }

    /**
     * Summarize a single post for operator review or drafting context.
     *
     * @param  int  $post_id  The ID of the post to summarize.
     *
     * @return array<string, mixed> Contains post metadata and a human-readable summary.
     *
     * @throws \InvalidArgumentException If the post is not found.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    #[AgentTool(
        name: 'content.summarize_post',
        family: 'content',
        moduleScope: 'content',
        contractSteward: 'content',
        capabilityClass: 'class_0_read_only',
        description: 'Summarize a Content post for operator review or drafting context.'
    )]
    public function summarize_post_tool(int $post_id): array
    {
        $post = $this->posts->find(
            $post_id,
            ['id', 'uuid', 'name', 'description', 'type', 'status', 'published_at', 'updated_at'],
            ['category:id,name', 'slugs:target_id,slug']
        );

        if (! $post) {
            throw new InvalidArgumentException("Post [{$post_id}] not found.");
        }

        $summary = implode(' ', array_values(array_filter([
            sprintf('Post [%s] is a %s item currently marked as %s.', $post->name ?? $post->getKey(), $post->type ?? 'post', $post->status ?? 'unknown'),
            trim((string) ($post->description ?? '')) !== '' ? trim((string) $post->description) : 'No description has been written yet.',
            $post->category?->name ? sprintf('Primary category: %s.', $post->category->name) : null,
        ])));

        return [
            'post' => [
                'id' => $post->getKey(),
                'uuid' => $post->uuid ?? null,
                'name' => $post->name,
                'slug' => $post->slugs?->first()?->slug ?? null,
                'type' => $post->type,
                'status' => $post->status,
                'published_at' => optional($post->published_at)->toDateTimeString(),
                'updated_at' => optional($post->updated_at)->toDateTimeString(),
            ],
            'summary' => $summary,
        ];
    }

    /**
     * Suggest related posts as internal link candidates for a given post.
     *
     * @param  int          $post_id       The source post ID.
     * @param  int|null     $category_id   Optional category override (falls back to post category).
     * @param  string|null  $article_type  Optional article type override (falls back to post type).
     * @param  int          $limit         Maximum suggestions (clamped 1-20).
     *
     * @return array<string, mixed> Contains post context, suggestion count, and suggestion list.
     *
     * @throws \InvalidArgumentException If the post is not found or category cannot be resolved.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    #[AgentTool(
        name: 'content.suggest_internal_links',
        family: 'content',
        moduleScope: 'content',
        contractSteward: 'content',
        capabilityClass: 'class_0_read_only',
        description: 'Suggest related Content posts that can be used as internal links for a given post.'
    )]
    public function suggest_internal_links_tool(
        int $post_id,
        ?int $category_id = null,
        ?string $article_type = null,
        int $limit = 5,
    ): array {
        $limit = max(1, min($limit, 20));

        $post = $this->posts->find(
            $post_id,
            ['id', 'uuid', 'name', 'description', 'type', 'category_id', 'status', 'updated_at'],
            ['categories:id,name', 'slugs:target_id,slug']
        );

        if (! $post) {
            throw new InvalidArgumentException("Post [{$post_id}] not found.");
        }

        $resolvedCategoryId = (int) (
            $category_id
            ?? $post->category_id
            ?? $post->categories?->first()?->id
            ?? 0
        );

        if ($resolvedCategoryId <= 0) {
            throw new InvalidArgumentException('Internal link suggestions require a valid category_id.');
        }

        $resolvedType = trim((string) ($article_type ?? $post->type ?? 'post'));
        $resolvedType = $resolvedType !== '' ? $resolvedType : 'post';

        $relatedPosts = $this->posts->getRelatedPosts(
            $resolvedCategoryId,
            (int) $post->getKey(),
            $limit,
            $resolvedType
        );

        $suggestions = collect($this->extractItems($relatedPosts))
            ->map(fn (mixed $item) => $this->normalizeInternalLinkSuggestion($item))
            ->filter()
            ->values()
            ->all();

        return [
            'post' => [
                'id' => $post->getKey(),
                'uuid' => $post->uuid ?? null,
                'name' => $post->name,
                'slug' => $post->slugs?->first()?->slug ?? null,
                'type' => $resolvedType,
                'category_id' => $resolvedCategoryId,
                'status' => $post->status,
            ],
            'limit' => $limit,
            'suggestion_count' => count($suggestions),
            'suggestions' => $suggestions,
        ];
    }

    protected function normalizeResult(mixed $result): array
    {
        if ($result instanceof LengthAwarePaginator) {
            return [
                'data' => $this->normalizeItems($result->items()),
                'total' => $result->total(),
                'per_page' => $result->perPage(),
                'current_page' => $result->currentPage(),
            ];
        }

        if ($result instanceof Collection || $result instanceof EloquentCollection) {
            return $this->normalizeItems($result->all());
        }

        if (is_array($result)) {
            return $this->normalizeItems($result);
        }

        return ['value' => $this->normalizeItem($result)];
    }

    protected function normalizeItems(array $items): array
    {
        return array_map(fn ($item) => $this->normalizeItem($item), $items);
    }

    protected function extractItems(mixed $result): array
    {
        if ($result instanceof LengthAwarePaginator) {
            return $result->items();
        }

        if ($result instanceof Collection || $result instanceof EloquentCollection) {
            return $result->all();
        }

        if (is_array($result)) {
            return $result;
        }

        return [$result];
    }

    protected function normalizeInternalLinkSuggestion(mixed $item): ?array
    {
        if ($item instanceof Model) {
            return [
                'id' => $item->getKey(),
                'name' => $item->name ?? null,
                'slug' => $item->slugs?->first()?->slug ?? null,
                'type' => $item->type ?? null,
                'status' => $item->status ?? null,
                'published_at' => optional($item->published_at)->toDateTimeString(),
                'category' => $item->category ? [
                    'id' => $item->category->id,
                    'name' => $item->category->name,
                ] : null,
                'anchor_text' => trim((string) ($item->name ?? '')) ?: null,
                'reason' => 'Related post in the same category and article type.',
            ];
        }

        if (is_array($item)) {
            return [
                'id' => $item['id'] ?? null,
                'name' => $item['name'] ?? null,
                'slug' => $item['slug'] ?? null,
                'type' => $item['type'] ?? null,
                'status' => $item['status'] ?? null,
                'published_at' => $item['published_at'] ?? null,
                'category' => $item['category'] ?? null,
                'anchor_text' => $item['anchor_text'] ?? $item['name'] ?? null,
                'reason' => $item['reason'] ?? 'Related post in the same category and article type.',
            ];
        }

        if (is_object($item) && method_exists($item, 'toArray')) {
            return $this->normalizeInternalLinkSuggestion($item->toArray());
        }

        return null;
    }

    protected function normalizeItem(mixed $item): mixed
    {
        if ($item instanceof Model) {
            return [
                'id' => $item->getKey(),
                'class' => $item::class,
                'attributes' => $item->getAttributes(),
            ];
        }

        if (is_array($item)) {
            return $item;
        }

        if (is_object($item) && method_exists($item, 'toArray')) {
            return $item->toArray();
        }

        return $item;
    }
}
