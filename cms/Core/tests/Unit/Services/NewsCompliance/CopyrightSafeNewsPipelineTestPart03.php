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

test('article profile selector applies product policy without dispute-only thresholds', function (): void {
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.news_compliance.ai.cache_enabled', false);
    config()->set('core.news_compliance.discovery.enabled', false);
    config()->set('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1);

    $fact = 'Apple công bố iPhone mới với chip A19 và giá khởi điểm 999 USD tại Mỹ.';
    $responses = [
        newsComplianceAiResponse([
            'topic' => 'iPhone mới',
            'sources' => [[
                'url' => 'https://seed.example.test/iphone.html',
                'title' => 'Apple ra mắt iPhone mới',
                'publisher' => 'Seed Publisher',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 1.0,
            ], [
                'url' => 'https://related.example.test/iphone.html',
                'title' => 'Thông số iPhone mới',
                'publisher' => 'Related Publisher',
                'published_at' => '2026-06-25T08:10:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 0.9,
            ]],
            'entities' => [],
            'facts' => [[
                'id' => 'f1',
                'text' => $fact,
                'fact_type' => 'number',
                'source_url' => 'https://seed.example.test/iphone.html',
                'source_title' => 'Apple ra mắt iPhone mới',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'quote_span' => '',
                'confidence' => 0.91,
                'is_opinion' => false,
                'needs_manual_review' => false,
            ]],
            'normalized_facts' => [[
                'canonical_fact' => $fact,
                'supporting_sources' => ['https://seed.example.test/iphone.html', 'https://related.example.test/iphone.html'],
                'contradicting_sources' => [],
                'confidence' => 0.91,
                'manual_review' => false,
            ]],
            'facts_high' => [$fact],
            'facts_medium' => [],
            'facts_low' => [],
            'manual_review_items' => [],
            'confidence' => 0.91,
        ]),
        newsComplianceAiResponse([
            'headline' => 'iPhone mới có chip A19 và giá từ 999 USD',
            'intro' => 'Mẫu iPhone mới được Apple công bố với chip A19 và giá khởi điểm 999 USD tại thị trường Mỹ.',
            'sections' => [[
                'heading' => 'Thông số và giá',
                'body' => 'Điểm chính của sản phẩm là chip A19, trong khi mức giá khởi điểm tại Mỹ được ghi nhận ở 999 USD.',
                'facts_used' => ['f1'],
            ]],
            'source_mentions' => ['Seed Publisher'],
            'editor_commentary_blocks' => [],
            'image_policy' => [
                'allowed_images' => [],
                'rejected_images' => ['Không dùng ảnh nguồn báo khi chưa có quyền.'],
                'notes' => 'No source images used.',
            ],
            'confidence' => 0.9,
        ]),
        newsComplianceAiResponse([
            'passed' => true,
            'risk_level' => 'low',
            'issues' => [],
            'required_actions' => [],
            'image_rights_passed' => true,
            'confidence' => 0.92,
        ]),
    ];
    $payloads = [];

    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->times(3)
        ->andReturnUsing(function (array $payload) use (&$payloads, &$responses): array {
            $payloads[] = $payload;

            return array_shift($responses);
        });
    $provider->shouldReceive('getContent')
        ->times(3)
        ->andReturnUsing(static fn (array $response): string => (string) data_get($response, 'choices.0.message.content'));

    $pipeline = new CopyrightSafeNewsPipeline(
        new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator()),
        new AiHtmlPromptSanitizer(),
        new BraveNewsSearchProvider(),
        new NewsEvidenceCrawler(new AiHtmlPromptSanitizer()),
    );

    $result = $pipeline->run([
        'module' => 'SampleDirectory',
        'seed' => [
            'url' => 'https://seed.example.test/iphone.html',
            'title' => 'Apple ra mắt iPhone mới với chip A19',
            'publisher' => 'Seed Publisher',
            'content' => $fact,
        ],
        'related_sources' => [[
            'url' => 'https://related.example.test/iphone.html',
            'title' => 'Thông số iPhone mới',
            'publisher' => 'Related Publisher',
            'content' => 'Nguồn liên quan ghi nhận chip A19 và giá khởi điểm 999 USD.',
        ]],
    ]);

    $draftPrompt = json_encode($payloads[1]['messages'], JSON_UNESCAPED_UNICODE);

    expect(data_get($result, 'article_profile.selected_profile'))->toBe('tech_product')
        ->and(data_get($result, 'article_profile.similarity_threshold_level'))->toBe('medium')
        ->and(data_get($result, 'mechanical_review.metrics.similarity_threshold_level'))->toBe('medium')
        ->and($draftPrompt)->toContain('official_specs')
        ->and($draftPrompt)->toContain('current_price')
        ->and($draftPrompt)->toContain('Standard product section structure is allowed');
});

test('article profile selector prioritizes weather title over legal page chrome', function (): void {
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.news_compliance.ai.cache_enabled', false);
    config()->set('core.news_compliance.discovery.enabled', false);
    config()->set('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1);

    $fact = 'Miền Bắc ghi nhận mưa lớn gần 200 mm, Hà Nội có khả năng giông gió vào giờ cao điểm.';
    $responses = [
        newsComplianceAiResponse([
            'topic' => 'Mưa lớn ở miền Bắc',
            'sources' => [[
                'url' => 'https://seed.example.test/weather.html',
                'title' => 'Miền Bắc mưa gần 200 mm, Hà Nội có giông giờ cao điểm',
                'publisher' => 'Seed Publisher',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 1.0,
            ], [
                'url' => 'https://related.example.test/weather.html',
                'title' => 'Cập nhật mưa giông miền Bắc',
                'publisher' => 'Related Publisher',
                'published_at' => '2026-06-25T08:10:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 0.9,
            ]],
            'entities' => [],
            'facts' => [[
                'id' => 'f1',
                'text' => $fact,
                'fact_type' => 'number',
                'source_url' => 'https://seed.example.test/weather.html',
                'source_title' => 'Miền Bắc mưa gần 200 mm, Hà Nội có giông giờ cao điểm',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'quote_span' => '',
                'confidence' => 0.91,
                'is_opinion' => false,
                'needs_manual_review' => false,
            ]],
            'normalized_facts' => [[
                'canonical_fact' => $fact,
                'supporting_sources' => ['https://seed.example.test/weather.html', 'https://related.example.test/weather.html'],
                'contradicting_sources' => [],
                'confidence' => 0.91,
                'manual_review' => false,
            ]],
            'facts_high' => [$fact],
            'facts_medium' => [],
            'facts_low' => [],
            'manual_review_items' => [],
            'confidence' => 0.91,
        ]),
        newsComplianceAiResponse([
            'headline' => 'Miền Bắc tiếp tục theo dõi mưa lớn và giông gió',
            'intro' => 'Người dân miền Bắc cần theo dõi cập nhật mưa giông mới nhất trong ngày.',
            'sections' => [[
                'heading' => 'Điểm cần chú ý',
                'body' => 'Lượng mưa lớn và nguy cơ giông gió tại Hà Nội cần được theo dõi theo các bản tin khí tượng cập nhật.',
                'facts_used' => ['f1'],
            ]],
            'source_mentions' => ['Seed Publisher'],
            'editor_commentary_blocks' => [],
            'image_policy' => [
                'allowed_images' => [],
                'rejected_images' => ['Không dùng ảnh nguồn báo khi chưa có quyền.'],
                'notes' => 'No source images used.',
            ],
            'confidence' => 0.9,
        ]),
        newsComplianceAiResponse([
            'passed' => true,
            'risk_level' => 'low',
            'issues' => [],
            'required_actions' => [],
            'image_rights_passed' => true,
            'confidence' => 0.92,
        ]),
    ];
    $payloads = [];

    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->times(3)
        ->andReturnUsing(function (array $payload) use (&$payloads, &$responses): array {
            $payloads[] = $payload;

            return array_shift($responses);
        });
    $provider->shouldReceive('getContent')
        ->times(3)
        ->andReturnUsing(static fn (array $response): string => (string) data_get($response, 'choices.0.message.content'));

    $pipeline = new CopyrightSafeNewsPipeline(
        new AiStructuredExtractionService($provider, new JsonSchemaShapeValidator()),
        new AiHtmlPromptSanitizer(),
        new BraveNewsSearchProvider(),
        new NewsEvidenceCrawler(new AiHtmlPromptSanitizer()),
    );

    $result = $pipeline->run([
        'module' => 'SampleDirectory',
        'seed' => [
            'url' => 'https://seed.example.test/weather.html',
            'title' => 'Miền Bắc mưa gần 200 mm, Hà Nội có giông giờ cao điểm',
            'publisher' => 'Seed Publisher',
            'content' => 'Pháp luật và nghị định là nội dung menu trang, không phải chủ đề bài. ' . $fact,
        ],
        'related_sources' => [[
            'url' => 'https://related.example.test/weather.html',
            'title' => 'Cập nhật mưa giông miền Bắc',
            'publisher' => 'Related Publisher',
            'content' => 'Nguồn liên quan ghi nhận mưa giông tại miền Bắc trong ngày.',
        ]],
    ]);

    $draftPrompt = json_encode($payloads[1]['messages'], JSON_UNESCAPED_UNICODE);

    expect(data_get($result, 'article_profile.selected_profile'))->toBe('weather_public_safety')
        ->and(data_get($result, 'article_profile.similarity_threshold_level'))->toBe('medium')
        ->and($draftPrompt)->toContain('official_forecast_source')
        ->and($draftPrompt)->toContain('location_specific_numbers')
        ->and($draftPrompt)->toContain('source timestamp');
});
