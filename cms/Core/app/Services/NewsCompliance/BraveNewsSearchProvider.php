<?php

namespace Cms\Core\Services\NewsCompliance;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class BraveNewsSearchProvider
{
    private const SUPPORTED_COUNTRIES = [
        'AR', 'AU', 'AT', 'BE', 'BR', 'CA', 'CL', 'DK', 'FI', 'FR', 'DE', 'GR',
        'HK', 'IN', 'ID', 'IT', 'JP', 'KR', 'MY', 'MX', 'NL', 'NZ', 'NO', 'CN',
        'PL', 'PT', 'PH', 'RU', 'SA', 'ZA', 'ES', 'SE', 'CH', 'TW', 'TR', 'GB',
        'US',
    ];

    /**
     * @return list<NewsSearchResult>
     */
    public function search(string $query, int $limit = 5): array
    {
        $apiKey = trim((string) config('core.news_compliance.search.brave.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('Brave Search API key is not configured.');
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
                'Cache-Control' => 'no-cache',
                'X-Subscription-Token' => $apiKey,
            ])
                ->timeout((int) config('core.news_compliance.search.timeout_seconds', 10))
                ->retry(2, 500)
                ->get($this->baseUrl(), $this->queryParams($query, $limit));
        } catch (RequestException $exception) {
            throw new RuntimeException($this->failureMessage($exception->response, 'Brave Search request'), 0, $exception);
        }

        $this->throwIfFailed($response, 'Brave Search request');

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Brave Search response was not valid JSON.');
        }

        return $this->results((array) data_get($payload, 'web.results', []));
    }

    private function baseUrl(): string
    {
        return (string) config('core.news_compliance.search.brave.base_url', 'https://api.search.brave.com/res/v1/web/search');
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParams(string $query, int $limit): array
    {
        $country = strtoupper(trim((string) config('core.news_compliance.search.country', 'VN')));

        return array_filter([
            'q' => $query,
            'count' => min(20, max(1, $limit)),
            'country' => in_array($country, self::SUPPORTED_COUNTRIES, true) ? $country : null,
            'search_lang' => trim((string) config('core.news_compliance.search.language', 'vi')) ?: null,
            'result_filter' => 'web',
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<int, mixed> $items
     * @return list<NewsSearchResult>
     */
    private function results(array $items): array
    {
        $results = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $results[] = new NewsSearchResult(
                provider: 'brave',
                title: trim((string) ($item['title'] ?? '')),
                url: $url,
                snippet: trim((string) ($item['description'] ?? $item['snippet'] ?? '')),
                rank: $index + 1,
                metadata: array_filter([
                    'profile' => $item['profile'] ?? null,
                    'age' => $item['age'] ?? null,
                    'language' => $item['language'] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            );
        }

        return $results;
    }

    private function throwIfFailed(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException($this->failureMessage($response, $operation));
    }

    private function failureMessage(Response $response, string $operation): string
    {
        $detail = data_get($response->json() ?: [], 'error.detail');
        $errors = collect((array) data_get($response->json() ?: [], 'error.meta.errors', []))
            ->map(function (mixed $error): ?string {
                if (! is_array($error)) {
                    return null;
                }

                $location = implode('.', array_filter((array) ($error['loc'] ?? []), 'is_scalar'));
                $message = trim((string) ($error['msg'] ?? ''));

                return trim($location . ($message !== '' ? ': ' . $message : ''));
            })
            ->filter()
            ->take(3)
            ->implode('; ');

        $suffix = trim(implode(' ', array_filter([
            is_scalar($detail) ? (string) $detail : null,
            $errors !== '' ? '(' . $errors . ')' : null,
        ])));

        return sprintf('%s failed with HTTP %d%s.', $operation, $response->status(), $suffix !== '' ? ': ' . $suffix : '');
    }
}
