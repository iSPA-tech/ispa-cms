<?php

namespace Cms\Core\Services\NewsCompliance;

use Illuminate\Support\Str;

final class NewsSourceDocument
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $publisher = '',
        public readonly string $author = '',
        public readonly ?string $publishedAt = null,
        public readonly string $content = '',
        public readonly string $summary = '',
        public readonly string $sourceType = 'news',
        public readonly bool $seed = false,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload, bool $seed = false): self
    {
        return new self(
            url: trim((string) ($payload['url'] ?? $payload['source_url'] ?? '')),
            title: trim((string) ($payload['title'] ?? $payload['headline'] ?? '')),
            publisher: trim((string) ($payload['publisher'] ?? $payload['source_name'] ?? '')),
            author: trim((string) ($payload['author'] ?? '')),
            publishedAt: ($payload['published_at'] ?? null) !== null ? (string) $payload['published_at'] : null,
            content: (string) ($payload['content'] ?? $payload['body'] ?? $payload['text'] ?? ''),
            summary: trim((string) ($payload['summary'] ?? $payload['description'] ?? '')),
            sourceType: trim((string) ($payload['source_type'] ?? $payload['content_type'] ?? 'news')) ?: 'news',
            seed: (bool) ($payload['seed'] ?? $seed),
            metadata: (array) ($payload['metadata'] ?? []),
        );
    }

    public function host(): string
    {
        return Str::lower((string) parse_url($this->url, PHP_URL_HOST));
    }

    public function hasText(): bool
    {
        return trim($this->content . $this->summary . $this->title) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toReference(string $usedFor = 'fact'): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'publisher' => $this->publisher,
            'published_at' => $this->publishedAt,
            'used_for' => $usedFor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'publisher' => $this->publisher,
            'author' => $this->author,
            'published_at' => $this->publishedAt,
            'summary' => $this->summary,
            'source_type' => $this->sourceType,
            'seed' => $this->seed,
            'metadata' => $this->metadata,
        ];
    }
}
