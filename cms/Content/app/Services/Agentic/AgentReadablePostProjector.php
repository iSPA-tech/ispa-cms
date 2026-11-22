<?php

declare(strict_types=1);

namespace Cms\Content\Services\Agentic;

use Carbon\CarbonImmutable;
use Cms\Content\Models\Post;
use DateTimeInterface;
use Traversable;

final class AgentReadablePostProjector
{
    private const TEXT_BLOCKS = ['text', 'paragraph', 'heading', 'quote', 'glossary'];
    private const TEXT_KEYS = ['value', 'term', 'definition'];

    /**
     * @return array<string, mixed>
     */
    public function project(Post $post): array
    {
        $sections = $this->sections((array) $post->content);
        $semantic = [
            'schema_version' => 'ispa.agent-content.v1',
            'id' => 'urn:ispa:content:' . $post->uuid,
            'type' => in_array($post->type, ['post', 'article'], true) ? 'NewsArticle' : 'Article',
            'title' => $this->plainText((string) $post->name),
            'summary' => $this->plainText((string) $post->description),
            'sections' => $sections,
            'categories' => $this->categories($post),
            'language' => $this->language($post),
        ];

        return $semantic + [
            'content_digest' => hash('sha256', $this->canonicalJson($semantic)),
            'published_at' => $this->utcTimestamp($post->published_at),
            'modified_at' => $this->utcTimestamp($post->getAttribute('modified_at') ?? $post->updated_at),
            'canonical_url' => null,
            'provenance' => [
                'source_url' => $this->publicHttpUrl(data_get($post->config, 'crawler_from')),
            ],
        ];
    }

    /**
     * @param array<int, mixed> $blocks
     * @return array<int, array{id: string, text: string}>
     */
    private function sections(array $blocks): array
    {
        $sectionLimit = max(0, (int) config('content.agent_answers.max_section_chars', 8000));
        $documentLimit = max(0, (int) config('content.agent_answers.max_document_chars', 30000));
        $sections = [];
        $remaining = $documentLimit;

        foreach ($blocks as $block) {
            if ($remaining === 0 || !is_array($block) || !in_array($block['type'] ?? null, self::TEXT_BLOCKS, true)) {
                continue;
            }

            $pieces = [];
            $this->collectTextLeaves($block, $pieces);
            $text = $this->plainText(implode(' ', $pieces));
            if ($text === '') {
                continue;
            }

            $text = mb_substr($text, 0, min($sectionLimit, $remaining), 'UTF-8');
            if ($text === '') {
                continue;
            }

            $sections[] = [
                'id' => 'body-' . (count($sections) + 1),
                'text' => $text,
            ];
            $remaining -= mb_strlen($text, 'UTF-8');
        }

        return $sections;
    }

    /**
     * @param array<int, string> $pieces
     */
    private function collectTextLeaves(mixed $value, array &$pieces, ?string $key = null): void
    {
        if (is_array($value) || $value instanceof Traversable) {
            foreach ($value as $rawChildKey => $child) {
                $childKey = is_string($rawChildKey) ? $rawChildKey : null;
                if ($childKey !== null && in_array($childKey, self::TEXT_KEYS, true)) {
                    $this->collectTextLeaves($child, $pieces, $childKey);
                    continue;
                }

                if (is_array($child) || $child instanceof Traversable) {
                    $this->collectTextLeaves($child, $pieces, $key);
                } elseif ($key === 'value' && is_int($rawChildKey) && is_scalar($child)) {
                    $text = $this->plainText((string) $child);
                    if ($text !== '') {
                        $pieces[] = $text;
                    }
                }
            }

            return;
        }

        if ($key !== null && in_array($key, self::TEXT_KEYS, true) && is_scalar($value)) {
            $text = $this->plainText((string) $value);
            if ($text !== '') {
                $pieces[] = $text;
            }
        }
    }

    /**
     * @return array<int, array{id: int|null, name: string, slug: string}>
     */
    private function categories(Post $post): array
    {
        if (!$post->relationLoaded('categories')) {
            return [];
        }

        $categories = [];
        foreach ($post->getRelation('categories') ?: [] as $category) {
            $id = is_array($category) ? ($category['id'] ?? null) : $category->getAttribute('id');
            $name = is_array($category) ? ($category['name'] ?? '') : $category->getAttribute('name');
            $categories[] = [
                'id' => is_numeric($id) ? (int) $id : null,
                'name' => $this->plainText((string) $name),
                'slug' => $this->categorySlug($category),
            ];
        }

        usort($categories, static function (array $left, array $right): int {
            $leftId = $left['id'] ?? PHP_INT_MAX;
            $rightId = $right['id'] ?? PHP_INT_MAX;

            return [$leftId, $left['slug'], $left['name']] <=> [$rightId, $right['slug'], $right['name']];
        });

        return $categories;
    }

    private function categorySlug(mixed $category): string
    {
        if (is_array($category)) {
            $slugs = $category['slugs'] ?? [];
        } elseif ($category->relationLoaded('slugs')) {
            $slugs = $category->getRelation('slugs') ?: [];
        } else {
            return '';
        }

        $candidates = [];
        foreach ($slugs as $slug) {
            $id = is_array($slug) ? ($slug['id'] ?? null) : $slug->getAttribute('id');
            $value = is_array($slug) ? ($slug['slug'] ?? '') : $slug->getAttribute('slug');
            if (!is_scalar($value) || (string) $value === '') {
                continue;
            }

            $candidates[] = [
                'id' => is_numeric($id) ? (int) $id : null,
                'slug' => (string) $value,
            ];
        }

        if ($candidates === []) {
            return '';
        }

        usort($candidates, static fn(array $left, array $right): int => [
            $left['id'] ?? -1,
            $left['slug'],
        ] <=> [
            $right['id'] ?? -1,
            $right['slug'],
        ]);

        return $this->plainText($candidates[array_key_last($candidates)]['slug']);
    }

    /** @return array{code: string, name: string}|null */
    private function language(Post $post): ?array
    {
        if (!$post->relationLoaded('language') || !$post->getRelation('language')) {
            return null;
        }

        $language = $post->getRelation('language');

        return [
            'code' => $this->plainText((string) $language->getAttribute('code')),
            'name' => $this->plainText((string) $language->getAttribute('name')),
        ];
    }

    private function plainText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace(
            '~<\s*(script|style|noscript|template)\b[^>]*>.*?<\s*/\s*\1\s*>~is',
            ' ',
            $value
        ) ?? $value;
        $value = preg_replace('/<[^>]+>/u', ' ', $value) ?? $value;
        $value = strip_tags($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function utcTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);

        return $date->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function publicHttpUrl(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('~^https?://~i', trim($value))) {
            return null;
        }

        $url = trim($value);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->normalizeKeys($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private function normalizeKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $child) {
            $normalized[$key] = $this->normalizeKeys($child);
        }

        if (!array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }
}
