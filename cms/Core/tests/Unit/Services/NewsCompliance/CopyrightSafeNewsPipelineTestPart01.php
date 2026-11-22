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

test('copyright safe pipeline keeps raw source text out of drafting step', function (): void {
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.news_compliance.ai.cache_enabled', false);
    config()->set('core.news_compliance.discovery.enabled', false);
    config()->set('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1);

    $rawSeedText = 'RAW SOURCE SHOULD NOT REACH DRAFTING: tuyến đường bị ngập theo nguyên văn bài nguồn.';
    $rawRelatedText = 'RELATED RAW SOURCE SHOULD NOT REACH DRAFTING: lực lượng chức năng phân luồng.';

    $responses = [
        newsComplianceAiResponse([
            'topic' => 'Cảnh báo giao thông sau mưa lớn',
            'sources' => [
                [
                    'url' => 'https://seed.example.test/post.html',
                    'title' => 'Tin nguồn',
                    'publisher' => 'Seed Publisher',
                    'published_at' => '2026-06-25T08:00:00+07:00',
                    'source_type' => 'straight_news',
                    'independence_score' => 1.0,
                ],
                [
                    'url' => 'https://related.example.test/post.html',
                    'title' => 'Tin liên quan',
                    'publisher' => 'Related Publisher',
                    'published_at' => '2026-06-25T08:10:00+07:00',
                    'source_type' => 'straight_news',
                    'independence_score' => 0.9,
                ],
            ],
            'entities' => [],
            'facts' => [[
                'id' => 'f1',
                'text' => 'Một tuyến đường tại TP.HCM bị ngập sau mưa lớn và có phân luồng giao thông.',
                'fact_type' => 'event',
                'source_url' => 'https://seed.example.test/post.html',
                'source_title' => 'Tin nguồn',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'quote_span' => '',
                'confidence' => 0.91,
                'is_opinion' => false,
                'needs_manual_review' => false,
            ]],
            'normalized_facts' => [[
                'canonical_fact' => 'Một tuyến đường tại TP.HCM bị ngập sau mưa lớn và có phân luồng giao thông.',
                'supporting_sources' => ['https://seed.example.test/post.html', 'https://related.example.test/post.html'],
                'contradicting_sources' => [],
                'confidence' => 0.91,
                'manual_review' => false,
            ]],
            'facts_high' => ['Một tuyến đường tại TP.HCM bị ngập sau mưa lớn và có phân luồng giao thông.'],
            'facts_medium' => [],
            'facts_low' => [],
            'manual_review_items' => [],
            'confidence' => 0.91,
        ]),
        newsComplianceAiResponse([
            'headline' => 'Ngập sau mưa lớn: người dân cần kiểm tra lộ trình trước khi di chuyển',
            'intro' => 'Mưa lớn khiến việc đi lại tại một khu vực ở TP.HCM phải điều chỉnh, nên người dân cần kiểm tra lộ trình trước khi di chuyển.',
            'sections' => [[
                'heading' => 'Điểm cần chú ý',
                'body' => 'Người dân nên kiểm tra lộ trình và theo dõi thông báo chính thức.',
                'facts_used' => ['f1'],
            ]],
            'source_mentions' => ['Thông tin được đối chiếu từ các nguồn liên quan.'],
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
            'url' => 'https://seed.example.test/post.html',
            'title' => 'Tin nguồn',
            'publisher' => 'Seed Publisher',
            'published_at' => '2026-06-25T08:00:00+07:00',
            'content' => $rawSeedText,
        ],
        'related_sources' => [[
            'url' => 'https://related.example.test/post.html',
            'title' => 'Tin liên quan',
            'publisher' => 'Related Publisher',
            'published_at' => '2026-06-25T08:10:00+07:00',
            'content' => $rawRelatedText,
        ]],
    ]);

    expect($result['status'])->toBe('approved')
        ->and($result['claim_status'])->toBe('approved_from_verified_fact_packet')
        ->and($result['compliance_log']['fact_only_drafting'])->toBeTrue()
        ->and($result['compliance_log']['drafting_raw_source_access'])->toBeFalse();

    $factExtractionPrompt = json_encode($payloads[0]['messages'], JSON_UNESCAPED_UNICODE);
    $draftPrompt = json_encode($payloads[1]['messages'], JSON_UNESCAPED_UNICODE);

    expect($factExtractionPrompt)->toContain($rawSeedText)
        ->and($factExtractionPrompt)->toContain($rawRelatedText)
        ->and($draftPrompt)->not->toContain($rawSeedText)
        ->and($draftPrompt)->not->toContain($rawRelatedText)
        ->and($draftPrompt)->toContain('verified_fact_sheet')
        ->and($draftPrompt)->toContain('drafting_packet')
        ->and($draftPrompt)->toContain('independent_article')
        ->and($draftPrompt)->toContain('blocked_source_phrases');
});

test('news compliance pipeline can use separate ai providers and models per stage', function (): void {
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.news_compliance.ai.cache_enabled', false);
    config()->set('core.news_compliance.discovery.enabled', false);
    config()->set('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1);
    config()->set('core.news_compliance.ai.provider', 'agentic_ai');
    config()->set('core.news_compliance.ai.model', 'fallback-model');
    config()->set('core.news_compliance.ai.stages.fact_sheet.provider', 'openai');
    config()->set('core.news_compliance.ai.stages.fact_sheet.model', 'fact-model');
    config()->set('core.news_compliance.ai.stages.draft.provider', 'deepseek');
    config()->set('core.news_compliance.ai.stages.draft.model', 'deepseek-draft-model');
    config()->set('core.news_compliance.ai.stages.review.provider', 'anthropic');
    config()->set('core.news_compliance.ai.stages.review.model', 'review-model');

    $fact = 'Cơ quan chức năng thông báo điều tiết giao thông tại một tuyến đường ven sông.';
    $responses = [
        newsComplianceAiResponse([
            'topic' => 'Điều tiết giao thông',
            'sources' => [
                [
                    'url' => 'https://seed.example.test/traffic.html',
                    'title' => 'Tin nguồn',
                    'publisher' => 'Seed Publisher',
                    'published_at' => '2026-06-25T08:00:00+07:00',
                    'source_type' => 'straight_news',
                    'independence_score' => 1.0,
                ],
                [
                    'url' => 'https://related.example.test/traffic.html',
                    'title' => 'Tin liên quan',
                    'publisher' => 'Related Publisher',
                    'published_at' => '2026-06-25T08:10:00+07:00',
                    'source_type' => 'straight_news',
                    'independence_score' => 0.9,
                ],
            ],
            'entities' => [],
            'facts' => [[
                'id' => 'f1',
                'text' => $fact,
                'fact_type' => 'event',
                'source_url' => 'https://seed.example.test/traffic.html',
                'source_title' => 'Tin nguồn',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'quote_span' => '',
                'confidence' => 0.91,
                'is_opinion' => false,
                'needs_manual_review' => false,
            ]],
            'normalized_facts' => [[
                'canonical_fact' => $fact,
                'supporting_sources' => ['https://seed.example.test/traffic.html', 'https://related.example.test/traffic.html'],
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
            'headline' => 'Cập nhật hướng dẫn đi lại mới',
            'intro' => 'Người dân cần theo dõi thông báo chính thức trước khi di chuyển.',
            'sections' => [[
                'heading' => 'Thông tin chính',
                'body' => 'Người dân nên kiểm tra kênh hướng dẫn chính thức và chủ động chọn lộ trình phù hợp trước khi xuất phát.',
                'facts_used' => ['f1'],
            ]],
            'source_mentions' => ['Thông tin được đối chiếu từ nguồn gốc và nguồn liên quan.'],
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
            'url' => 'https://seed.example.test/traffic.html',
            'title' => 'Tin nguồn',
            'publisher' => 'Seed Publisher',
            'content' => 'Nguồn thô ghi nhận một thông báo điều tiết giao thông tại khu vực ven sông.',
        ],
        'related_sources' => [[
            'url' => 'https://related.example.test/traffic.html',
            'title' => 'Tin liên quan',
            'publisher' => 'Related Publisher',
            'content' => 'Nguồn liên quan ghi nhận khuyến nghị kiểm tra lộ trình trước khi di chuyển.',
        ]],
    ]);

    expect($result['status'])->toBe('approved')
        ->and($payloads[0]['model'])->toBe('fact-model')
        ->and($payloads[1]['model'])->toBe('deepseek-draft-model')
        ->and($payloads[2]['model'])->toBe('review-model')
        ->and(data_get($result, 'ai.fact_extraction.stage'))->toBe('fact_sheet')
        ->and(data_get($result, 'ai.fact_extraction.provider'))->toBe('openai')
        ->and(data_get($result, 'ai.fact_extraction.model'))->toBe('fact-model')
        ->and(data_get($result, 'ai.draft.stage'))->toBe('draft')
        ->and(data_get($result, 'ai.draft.provider'))->toBe('deepseek')
        ->and(data_get($result, 'ai.draft.model'))->toBe('deepseek-draft-model')
        ->and(data_get($result, 'ai.review.stage'))->toBe('review')
        ->and(data_get($result, 'ai.review.provider'))->toBe('anthropic')
        ->and(data_get($result, 'ai.review.model'))->toBe('review-model');
});

test('pipeline per run minimum related sources can block auto approval', function (): void {
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.news_compliance.ai.cache_enabled', false);
    config()->set('core.news_compliance.discovery.enabled', false);
    config()->set('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1);

    $responses = [
        newsComplianceAiResponse([
            'topic' => 'Cảnh báo giao thông',
            'sources' => [
                [
                    'url' => 'https://seed.example.test/post.html',
                    'title' => 'Tin nguồn',
                    'publisher' => 'Seed Publisher',
                    'published_at' => '2026-06-25T08:00:00+07:00',
                    'source_type' => 'straight_news',
                    'independence_score' => 1.0,
                ],
                [
                    'url' => 'https://related.example.test/post.html',
                    'title' => 'Tin liên quan',
                    'publisher' => 'Related Publisher',
                    'published_at' => '2026-06-25T08:10:00+07:00',
                    'source_type' => 'straight_news',
                    'independence_score' => 0.9,
                ],
            ],
            'entities' => [],
            'facts' => [[
                'id' => 'f1',
                'text' => 'Một tuyến đường bị ngập sau mưa lớn.',
                'fact_type' => 'event',
                'source_url' => 'https://seed.example.test/post.html',
                'source_title' => 'Tin nguồn',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'quote_span' => '',
                'confidence' => 0.91,
                'is_opinion' => false,
                'needs_manual_review' => false,
            ]],
            'normalized_facts' => [[
                'canonical_fact' => 'Một tuyến đường bị ngập sau mưa lớn.',
                'supporting_sources' => ['https://seed.example.test/post.html', 'https://related.example.test/post.html'],
                'contradicting_sources' => [],
                'confidence' => 0.91,
                'manual_review' => false,
            ]],
            'facts_high' => ['Một tuyến đường bị ngập sau mưa lớn.'],
            'facts_medium' => [],
            'facts_low' => [],
            'manual_review_items' => [],
            'confidence' => 0.91,
        ]),
        newsComplianceAiResponse([
            'headline' => 'Người dân cần kiểm tra lộ trình sau mưa lớn',
            'intro' => 'Một tuyến đường bị ngập sau mưa lớn.',
            'sections' => [],
            'source_mentions' => ['Thông tin được đối chiếu từ các nguồn liên quan.'],
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

    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->times(3)
        ->andReturnUsing(static function () use (&$responses): array {
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
        'min_related_sources_for_auto_pass' => 3,
        'seed' => [
            'url' => 'https://seed.example.test/post.html',
            'title' => 'Tin nguồn',
            'publisher' => 'Seed Publisher',
            'content' => 'Một tuyến đường bị ngập sau mưa lớn.',
        ],
        'related_sources' => [[
            'url' => 'https://related.example.test/post.html',
            'title' => 'Tin liên quan',
            'publisher' => 'Related Publisher',
            'content' => 'Lực lượng chức năng phân luồng sau mưa lớn.',
        ]],
    ]);

    expect($result['status'])->toBe('needs_manual_review')
        ->and($result['published_article'])->toBeNull()
        ->and($result['compliance_log']['related_source_count'])->toBe(1)
        ->and(collect($result['review']['issues'])->pluck('detail')->implode(' '))->toContain('requires at least 3');
});

test('mechanical review blocks source wording when ai review passes', function (): void {
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.news_compliance.ai.cache_enabled', false);
    config()->set('core.news_compliance.discovery.enabled', false);
    config()->set('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1);

    $sourceSentence = 'Tuyến đường ven sông tạm dừng lưu thông trong hai giờ để xử lý điểm ngập sâu.';
    $approvedFact = 'Cơ quan chức năng thông báo tạm dừng lưu thông tại một tuyến đường ven sông để xử lý ngập.';

    $responses = [
        newsComplianceAiResponse([
            'topic' => 'Cảnh báo giao thông',
            'sources' => [
                [
                    'url' => 'https://seed.example.test/traffic.html',
                    'title' => 'Tin nguồn',
                    'publisher' => 'Seed Publisher',
                    'published_at' => '2026-06-25T08:00:00+07:00',
                    'source_type' => 'straight_news',
                    'independence_score' => 1.0,
                ],
                [
                    'url' => 'https://related.example.test/traffic.html',
                    'title' => 'Tin liên quan',
                    'publisher' => 'Related Publisher',
                    'published_at' => '2026-06-25T08:10:00+07:00',
                    'source_type' => 'straight_news',
                    'independence_score' => 0.9,
                ],
            ],
            'entities' => [],
            'facts' => [[
                'id' => 'f1',
                'text' => $approvedFact,
                'fact_type' => 'event',
                'source_url' => 'https://seed.example.test/traffic.html',
                'source_title' => 'Tin nguồn',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'quote_span' => '',
                'confidence' => 0.91,
                'is_opinion' => false,
                'needs_manual_review' => false,
            ]],
            'normalized_facts' => [[
                'canonical_fact' => $approvedFact,
                'supporting_sources' => ['https://seed.example.test/traffic.html', 'https://related.example.test/traffic.html'],
                'contradicting_sources' => [],
                'confidence' => 0.91,
                'manual_review' => false,
            ]],
            'facts_high' => [$approvedFact],
            'facts_medium' => [],
            'facts_low' => [],
            'manual_review_items' => [],
            'confidence' => 0.91,
        ]),
        newsComplianceAiResponse([
            'headline' => 'Cập nhật điều tiết giao thông tại khu vực ven sông',
            'intro' => 'Người dân cần theo dõi thông báo điều tiết giao thông mới.',
            'sections' => [[
                'heading' => 'Thông tin chính',
                'body' => $sourceSentence,
                'facts_used' => ['f1'],
            ]],
            'source_mentions' => ['Thông tin được đối chiếu từ các nguồn liên quan.'],
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

    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->times(3)
        ->andReturnUsing(static function () use (&$responses): array {
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
            'url' => 'https://seed.example.test/traffic.html',
            'title' => 'Tin nguồn',
            'publisher' => 'Seed Publisher',
            'content' => $sourceSentence,
        ],
        'related_sources' => [[
            'url' => 'https://related.example.test/traffic.html',
            'title' => 'Tin liên quan',
            'publisher' => 'Related Publisher',
            'content' => 'Cơ quan chức năng sẽ cập nhật thông tin phân luồng trên cổng thông tin chính thức.',
        ]],
    ]);

    expect($result['status'])->toBe('needs_manual_review')
        ->and($result['mechanical_review']['passed'])->toBeFalse()
        ->and($result['mechanical_review']['risk_level'])->toBe('high')
        ->and($result['mechanical_review']['metrics']['max_sentence_similarity'])->toBeGreaterThanOrEqual(0.86)
        ->and(collect($result['review']['issues'])->pluck('type'))->toContain('similarity');
});
