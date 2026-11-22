<?php

use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Services\Ai\AiHtmlPromptSanitizer;
use Cms\Core\Services\Ai\AiStructuredExtractionService;
use Cms\Core\Services\Ai\JsonSchemaShapeValidator;
use Cms\Core\Services\NewsCompliance\BraveNewsSearchProvider;
use Cms\Core\Services\NewsCompliance\CopyrightSafeNewsPipeline;
use Cms\Core\Services\NewsCompliance\NewsEvidenceCrawler;
use Illuminate\Support\Facades\Http;
use Mockery as m;

test('brave news provider sends bounded web search request and maps results', function (): void {
    config()->set('core.news_compliance.search.brave.api_key', 'test-brave-key');
    config()->set('core.news_compliance.search.brave.base_url', 'https://api.search.brave.com/res/v1/web/search');
    config()->set('core.news_compliance.search.country', 'VN');
    config()->set('core.news_compliance.search.language', 'vi');

    Http::fake([
        'https://api.search.brave.com/res/v1/web/search*' => Http::response([
            'web' => [
                'results' => [[
                    'title' => 'Related story',
                    'url' => 'https://news.example.test/story',
                    'description' => 'Related description',
                    'age' => '1 day ago',
                    'language' => 'vi',
                ]],
            ],
        ]),
    ]);

    $results = (new BraveNewsSearchProvider())->search('cảnh báo giao thông', 3);

    expect($results)->toHaveCount(1)
        ->and($results[0]->provider)->toBe('brave')
        ->and($results[0]->url)->toBe('https://news.example.test/story')
        ->and($results[0]->rank)->toBe(1);

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

        return $request->hasHeader('X-Subscription-Token', 'test-brave-key')
            && ($query['q'] ?? null) === 'cảnh báo giao thông'
            && (int) ($query['count'] ?? 0) === 3
            && ! array_key_exists('country', $query)
            && ($query['search_lang'] ?? null) === 'vi'
            && ($query['result_filter'] ?? null) === 'web';
    });
});

test('news evidence crawler compacts page chrome before returning source document', function (): void {
    config()->set('core.news_compliance.user_agent', 'ComplianceTestBot/1.0');

    Http::fake([
        'https://related.example.test/story' => Http::response(<<<'HTML'
<!doctype html>
<html>
<body>
<nav>Navigation should disappear</nav>
<script>alert('bad')</script>
<article>
<h1>Related story</h1>
<p>Evidence paragraph stays visible for fact extraction.</p>
</article>
</body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
    ]);

    $document = (new NewsEvidenceCrawler(new AiHtmlPromptSanitizer()))->crawl(new \Cms\Core\Services\NewsCompliance\NewsSearchResult(
        provider: 'brave',
        title: 'Related story',
        url: 'https://related.example.test/story',
        snippet: 'Snippet',
        rank: 1,
    ));

    expect($document->content)->toContain('Evidence paragraph stays visible')
        ->and($document->content)->not->toContain('Navigation should disappear')
        ->and($document->content)->not->toContain("alert('bad')")
        ->and($document->metadata['http_status'])->toBe(200);
});

test('pipeline sends the admin ai runtime task key for each stage schema', function (string $schemaName, string $stage, string $expectedTask): void {
    $pipeline = new CopyrightSafeNewsPipeline(
        new AiStructuredExtractionService(m::mock(AgenticAiProviderInterface::class), new JsonSchemaShapeValidator()),
        new AiHtmlPromptSanitizer(),
        new BraveNewsSearchProvider(),
        new NewsEvidenceCrawler(new AiHtmlPromptSanitizer()),
    );

    $options = (new ReflectionMethod($pipeline, 'aiOptions'))->invoke($pipeline, 'SampleDirectory', $schemaName, 'v1');

    expect($options['task'])->toBe($expectedTask)
        ->and($options['stage'])->toBe($stage)
        ->and($options['schema_name'])->toBe($schemaName);
})->with([
    'fact sheet' => ['core_news_compliance_fact_sheet', 'fact_sheet', 'copyright_safe_news_fact_extraction'],
    'draft' => ['core_news_compliance_draft', 'draft', 'copyright_safe_news_drafting'],
    'review' => ['core_news_compliance_review', 'review', 'copyright_safe_news_legal_editorial_review'],
    'unknown schema keeps its own name' => ['custom_schema', 'default', 'custom_schema'],
]);
