<?php

namespace Cms\Core\Services\NewsCompliance;

use Cms\Core\Services\Ai\AiHtmlPromptSanitizer;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class NewsEvidenceCrawler
{
    public function __construct(private readonly AiHtmlPromptSanitizer $htmlSanitizer)
    {
    }

    public function crawl(NewsSearchResult $result): NewsSourceDocument
    {
        $url = trim($result->url);
        if ($url === '') {
            throw new RuntimeException('News evidence crawl requires a non-empty URL.');
        }

        try {
            $response = Http::accept('text/html,application/xhtml+xml,application/json')
                ->withUserAgent((string) config('core.news_compliance.user_agent'))
                ->timeout((int) config('core.news_compliance.evidence_crawl.timeout_seconds', 12))
                ->retry(1, 500)
                ->get($url);
        } catch (RequestException $exception) {
            throw new RuntimeException(sprintf('News evidence crawl failed with HTTP %d.', $exception->response->status()), 0, $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('News evidence crawl failed with HTTP %d.', $response->status()));
        }

        $body = $response->body();
        $text = $this->htmlSanitizer->compactPrompt(
            input: $body,
            baseUrl: $url,
            maxTextChars: max(1000, (int) config('core.news_compliance.evidence_crawl.max_text_chars', 9000)),
            maxLinks: max(0, (int) config('core.news_compliance.evidence_crawl.max_links', 30)),
        );

        return new NewsSourceDocument(
            url: $url,
            title: $result->title,
            content: $text,
            summary: $result->snippet,
            metadata: [
                'search_provider' => $result->provider,
                'search_rank' => $result->rank,
                'search_metadata' => $result->metadata,
                'http_status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'content_hash' => hash('sha256', $text),
                'original_chars' => mb_strlen($body),
                'compact_chars' => mb_strlen($text),
            ],
        );
    }
}
