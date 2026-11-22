<?php

namespace Cms\Core\Services\NewsCompliance;

final class NewsSearchResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $title,
        public readonly string $url,
        public readonly string $snippet = '',
        public readonly int $rank = 0,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'title' => $this->title,
            'url' => $this->url,
            'snippet' => $this->snippet,
            'rank' => $this->rank,
            'metadata' => $this->metadata,
        ];
    }
}
