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

test('pipeline retries drafting from sanitized packet when independence review fails', function (): void {
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.news_compliance.ai.cache_enabled', false);
    config()->set('core.news_compliance.discovery.enabled', false);
    config()->set('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1);
    config()->set('core.news_compliance.mechanical_review.rewrite_on_independence_failure', true);

    $sourceSentence = 'Tuyến đường ven sông tạm dừng lưu thông trong hai giờ để xử lý điểm ngập sâu.';
    $approvedFact = 'Cơ quan chức năng thông báo tạm dừng lưu thông tại một tuyến đường ven sông để xử lý ngập.';

    $responses = [
        newsComplianceAiResponse([
            'topic' => 'Cảnh báo giao thông',
            'sources' => [[
                'url' => 'https://seed.example.test/traffic.html',
                'title' => 'Tin nguồn',
                'publisher' => 'Seed Publisher',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 1.0,
            ], [
                'url' => 'https://related.example.test/traffic.html',
                'title' => 'Tin liên quan',
                'publisher' => 'Related Publisher',
                'published_at' => '2026-06-25T08:10:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 0.9,
            ]],
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
            'source_mentions' => ['Tin nguồn'],
            'editor_commentary_blocks' => [],
            'image_policy' => [
                'allowed_images' => [],
                'rejected_images' => ['Không dùng ảnh nguồn báo khi chưa có quyền.'],
                'notes' => 'No source images used.',
            ],
            'confidence' => 0.9,
        ]),
        newsComplianceAiResponse([
            'headline' => 'Khu ven sông điều chỉnh giao thông sau mưa lớn',
            'intro' => 'Người đi qua khu ven sông cần kiểm tra hướng dẫn mới trước khi xuất phát.',
            'sections' => [[
                'heading' => 'Điểm cần theo dõi',
                'body' => 'Việc di chuyển trong khu vực đang được điều chỉnh để phục vụ xử lý ngập, nên người dân cần cập nhật lộ trình qua kênh chính thức.',
                'facts_used' => ['f1'],
            ]],
            'source_mentions' => ['Tin nguồn'],
            'editor_commentary_blocks' => ['Angle: public movement guidance rather than source recap.'],
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
        ->times(4)
        ->andReturnUsing(function (array $payload) use (&$payloads, &$responses): array {
            $payloads[] = $payload;

            return array_shift($responses);
        });
    $provider->shouldReceive('getContent')
        ->times(4)
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

    $revisionPrompt = json_encode($payloads[2]['messages'], JSON_UNESCAPED_UNICODE);

    expect($result['status'])->toBe('approved')
        ->and(data_get($result, 'ai.draft_initial.ok'))->toBeTrue()
        ->and(data_get($result, 'ai.draft_independence_revision.ok'))->toBeTrue()
        ->and($result['draft']['headline'])->toBe('Khu ven sông điều chỉnh giao thông sau mưa lớn')
        ->and($revisionPrompt)->toContain('Previous draft failed independence review')
        ->and($revisionPrompt)->toContain('sanitized drafting_packet only');
});

test('drafting packet sanitizes source-like claim wording before drafting', function (): void {
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.news_compliance.ai.cache_enabled', false);
    config()->set('core.news_compliance.discovery.enabled', false);
    config()->set('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1);

    $sourceLikeClaim = 'CCTV cáo buộc lực lượng Nhật Bản theo dõi, quấy rối và khiêu khích ở cự ly gần nhóm tàu Liêu Ninh.';
    $responses = [
        newsComplianceAiResponse([
            'topic' => 'Tranh chấp giám sát quân sự trên biển',
            'sources' => [[
                'url' => 'https://seed.example.test/naval.html',
                'title' => 'Tin nguồn',
                'publisher' => 'Seed Publisher',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 1.0,
            ], [
                'url' => 'https://related.example.test/naval.html',
                'title' => 'Tin liên quan',
                'publisher' => 'Related Publisher',
                'published_at' => '2026-06-25T08:10:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 0.9,
            ]],
            'entities' => [],
            'facts' => [[
                'id' => 'f1',
                'text' => $sourceLikeClaim,
                'fact_type' => 'claim',
                'source_url' => 'https://seed.example.test/naval.html',
                'source_title' => 'Tin nguồn',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'quote_span' => '',
                'confidence' => 0.9,
                'is_opinion' => false,
                'needs_manual_review' => false,
            ]],
            'normalized_facts' => [[
                'canonical_fact' => $sourceLikeClaim,
                'supporting_sources' => ['https://seed.example.test/naval.html', 'https://related.example.test/naval.html'],
                'contradicting_sources' => [],
                'confidence' => 0.9,
                'manual_review' => false,
            ]],
            'facts_high' => [$sourceLikeClaim],
            'facts_medium' => [],
            'facts_low' => [],
            'manual_review_items' => [],
            'confidence' => 0.9,
        ]),
        newsComplianceAiResponse([
            'headline' => 'Trung - Nhật bất đồng về cách giám sát hoạt động quân sự trên biển',
            'intro' => 'Tranh cãi mới tập trung vào cách hai bên mô tả hoạt động theo dõi quanh nhóm tàu Liêu Ninh.',
            'sections' => [[
                'heading' => 'Điểm đã rõ',
                'body' => 'Phía Trung Quốc nêu cáo buộc về hoạt động áp sát nhóm tàu, còn phần đúng sai của cáo buộc vẫn cần thêm kiểm chứng độc lập.',
                'facts_used' => ['f1'],
            ]],
            'source_mentions' => ['Tin nguồn'],
            'editor_commentary_blocks' => ['Angle: focus on disputed monitoring boundaries rather than accusation-denial recap.'],
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
            'url' => 'https://seed.example.test/naval.html',
            'title' => 'Trung Quốc cáo buộc Nhật Bản quấy rối tàu sân bay',
            'publisher' => 'Seed Publisher',
            'content' => 'Bài viết nhắc pháp luật quốc tế như bối cảnh phụ. ' . $sourceLikeClaim,
        ],
        'related_sources' => [[
            'url' => 'https://related.example.test/naval.html',
            'title' => 'Nguồn liên quan',
            'publisher' => 'Related Publisher',
            'content' => 'Nguồn liên quan ghi nhận tranh cãi về hoạt động giám sát quân sự trên biển.',
        ]],
    ]);

    $draftPrompt = json_encode($payloads[1]['messages'], JSON_UNESCAPED_UNICODE);

    expect(data_get($result, 'verified_fact_sheet.source_wording_scrubbed'))->toBeTrue()
        ->and(data_get($result, 'article_profile.selected_profile'))->toBe('dispute_news')
        ->and(data_get($result, 'verified_fact_sheet.drafting_packet.approved_facts.0.claim_status'))->toBe('official_claim')
        ->and($draftPrompt)->toContain('structured_fact_records')
        ->and($draftPrompt)->toContain('source_like_phrase_hash:')
        ->and($draftPrompt)->not->toContain($sourceLikeClaim)
        ->and($draftPrompt)->not->toContain('theo dõi, quấy rối và khiêu khích ở cự ly gần')
        ->and($draftPrompt)->not->toContain('áp sát hoặc theo dõi nhóm lực lượng theo cáo buộc của một bên');
});

test('mechanical review fails unsupported draft fact markers as high risk', function (): void {
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.news_compliance.ai.cache_enabled', false);
    config()->set('core.news_compliance.discovery.enabled', false);
    config()->set('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1);

    $approvedFact = 'Cơ quan chức năng thông báo điều tiết giao thông tại một tuyến đường ven sông.';
    $responses = [
        newsComplianceAiResponse([
            'topic' => 'Điều tiết giao thông',
            'sources' => [[
                'url' => 'https://seed.example.test/traffic.html',
                'title' => 'Tin nguồn',
                'publisher' => 'Seed Publisher',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 1.0,
            ], [
                'url' => 'https://related.example.test/traffic.html',
                'title' => 'Tin liên quan',
                'publisher' => 'Related Publisher',
                'published_at' => '2026-06-25T08:10:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 0.9,
            ]],
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
            'headline' => 'Cập nhật giao thông ven sông',
            'intro' => 'Người dân cần theo dõi hướng dẫn mới trước khi đi qua khu ven sông.',
            'sections' => [[
                'heading' => 'Thông tin chính',
                'body' => 'Việc đi lại tại khu vực ven sông đang được điều chỉnh theo thông báo mới.',
                'facts_used' => ['f1', 'f99'],
            ]],
            'source_mentions' => ['Tin nguồn'],
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
            'content' => 'Nguồn thô ghi nhận điều tiết giao thông tại khu ven sông.',
        ],
        'related_sources' => [[
            'url' => 'https://related.example.test/traffic.html',
            'title' => 'Tin liên quan',
            'publisher' => 'Related Publisher',
            'content' => 'Nguồn liên quan ghi nhận người dân cần theo dõi hướng dẫn mới.',
        ]],
    ]);

    expect($result['status'])->toBe('needs_manual_review')
        ->and($result['claim_status'])->toBe('claims_require_editorial_or_legal_review')
        ->and($result['mechanical_review']['passed'])->toBeFalse()
        ->and($result['mechanical_review']['risk_level'])->toBe('high')
        ->and($result['mechanical_review']['metrics']['unsupported_draft_fact_count'])->toBe(1)
        ->and(collect($result['mechanical_review']['issues'])->firstWhere('type', 'fact')['severity'])->toBe('high');
});

test('verified fact promotion lets approved atomic facts pass mechanical support checks', function (): void {
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.news_compliance.ai.cache_enabled', false);
    config()->set('core.news_compliance.discovery.enabled', false);
    config()->set('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1);

    $atomicFact = 'Cơ quan chức năng thông báo tạm dừng lưu thông tại một tuyến đường ven sông để xử lý ngập.';

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
                'text' => $atomicFact,
                'fact_type' => 'event',
                'source_url' => 'https://seed.example.test/traffic.html',
                'source_title' => 'Tin nguồn',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'quote_span' => '',
                'confidence' => 0.88,
                'is_opinion' => false,
                'needs_manual_review' => false,
            ]],
            'normalized_facts' => [[
                'canonical_fact' => $atomicFact,
                'supporting_sources' => ['https://seed.example.test/traffic.html', 'https://related.example.test/traffic.html'],
                'contradicting_sources' => [],
                'confidence' => 0.88,
                'manual_review' => false,
            ]],
            'facts_high' => [],
            'facts_medium' => [],
            'facts_low' => [],
            'manual_review_items' => [],
            'confidence' => 0.88,
        ]),
        newsComplianceAiResponse([
            'headline' => 'Cập nhật hướng dẫn đi lại sau mưa',
            'intro' => 'Người dân cần kiểm tra kênh thông báo chính thức trước khi di chuyển.',
            'sections' => [[
                'heading' => 'Thông tin chính',
                'body' => 'Trong thời gian xử lý sự cố, người dân nên chủ động chọn lộ trình phù hợp và theo dõi hướng dẫn mới.',
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
            'content' => $atomicFact,
        ],
        'related_sources' => [[
            'url' => 'https://related.example.test/traffic.html',
            'title' => 'Tin liên quan',
            'publisher' => 'Related Publisher',
            'content' => 'Nguồn liên quan xác nhận việc điều tiết giao thông tại khu vực ven sông.',
        ]],
    ]);

    expect($result['status'])->toBe('approved')
        ->and($result['verified_fact_sheet']['facts_high'])->toContain($atomicFact)
        ->and($result['mechanical_review']['passed'])->toBeTrue()
        ->and($result['mechanical_review']['metrics']['unsupported_draft_fact_count'])->toBe(0)
        ->and($result['mechanical_review']['metrics']['max_sentence_approved_fact_similarity'])->toBeLessThan(0.9);
});

test('mechanical review blocks public sentences without sentence level fact map', function (): void {
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.news_compliance.ai.cache_enabled', false);
    config()->set('core.news_compliance.discovery.enabled', false);
    config()->set('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1);

    $approvedFact = 'Cơ quan chức năng thông báo điều tiết giao thông tại một tuyến đường ven sông.';
    $responses = [
        newsComplianceAiResponse([
            'topic' => 'Điều tiết giao thông',
            'sources' => [[
                'url' => 'https://seed.example.test/traffic.html',
                'title' => 'Tin nguồn',
                'publisher' => 'Seed Publisher',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 1.0,
            ], [
                'url' => 'https://related.example.test/traffic.html',
                'title' => 'Tin liên quan',
                'publisher' => 'Related Publisher',
                'published_at' => '2026-06-25T08:10:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 0.9,
            ]],
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
            'headline' => 'Khu ven sông điều chỉnh luồng đi lại',
            'intro' => 'Người dân đi qua khu vực ven sông cần kiểm tra hướng dẫn mới trước khi xuất phát.',
            'sections' => [[
                'heading' => 'Thông tin chính',
                'body' => 'Việc đi lại tại khu vực đang được điều chỉnh, nên người dân cần chủ động chọn lộ trình phù hợp.',
                'facts_used' => ['f1'],
            ]],
            'sentence_fact_map' => [],
            'source_mentions' => ['Tin nguồn'],
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
            'content' => $approvedFact,
        ],
        'related_sources' => [[
            'url' => 'https://related.example.test/traffic.html',
            'title' => 'Tin liên quan',
            'publisher' => 'Related Publisher',
            'content' => 'Nguồn liên quan xác nhận khu vực ven sông có điều chỉnh đi lại.',
        ]],
    ]);

    expect($result['status'])->toBe('needs_manual_review')
        ->and($result['mechanical_review']['metrics']['unmapped_public_sentence_count'])->toBeGreaterThan(0)
        ->and($result['review']['final_gate']['model_review_passed'])->toBeTrue()
        ->and($result['review']['final_gate']['mechanical_review_passed'])->toBeFalse()
        ->and($result['review']['final_gate']['passed'])->toBeFalse();
});

test('mechanical review blocks blocked facts in public article body', function (): void {
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.news_compliance.ai.cache_enabled', false);
    config()->set('core.news_compliance.discovery.enabled', false);
    config()->set('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1);

    $approvedFact = 'Một bên nêu cáo buộc về hoạt động theo dõi quanh nhóm tàu Liêu Ninh.';
    $blockedFact = 'Tàu Akizuki cắt trước mũi nhóm tàu Liêu Ninh ở eo biển Miyako.';
    $responses = [
        newsComplianceAiResponse([
            'topic' => 'Tranh cãi giám sát quân sự trên biển',
            'sources' => [[
                'url' => 'https://seed.example.test/naval.html',
                'title' => 'Tin nguồn',
                'publisher' => 'Seed Publisher',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 1.0,
            ], [
                'url' => 'https://related.example.test/naval.html',
                'title' => 'Tin liên quan',
                'publisher' => 'Related Publisher',
                'published_at' => '2026-06-25T08:10:00+07:00',
                'source_type' => 'straight_news',
                'independence_score' => 0.9,
            ]],
            'entities' => [],
            'facts' => [[
                'id' => 'f1',
                'text' => $approvedFact,
                'fact_type' => 'claim',
                'source_url' => 'https://seed.example.test/naval.html',
                'source_title' => 'Tin nguồn',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'quote_span' => '',
                'confidence' => 0.91,
                'is_opinion' => false,
                'needs_manual_review' => false,
            ], [
                'id' => 'f2',
                'text' => $blockedFact,
                'fact_type' => 'claim',
                'source_url' => 'https://seed.example.test/naval.html',
                'source_title' => 'Tin nguồn',
                'published_at' => '2026-06-25T08:00:00+07:00',
                'quote_span' => '',
                'confidence' => 0.5,
                'is_opinion' => false,
                'needs_manual_review' => true,
            ]],
            'normalized_facts' => [[
                'canonical_fact' => $approvedFact,
                'supporting_sources' => ['https://seed.example.test/naval.html', 'https://related.example.test/naval.html'],
                'contradicting_sources' => [],
                'confidence' => 0.91,
                'manual_review' => false,
            ]],
            'facts_high' => [$approvedFact],
            'facts_medium' => [],
            'facts_low' => [$blockedFact],
            'manual_review_items' => [$blockedFact],
            'confidence' => 0.91,
        ]),
        newsComplianceAiResponse([
            'headline' => 'Tranh cãi quanh việc theo dõi nhóm tàu Liêu Ninh',
            'intro' => 'Một bên nêu cáo buộc về hoạt động theo dõi quanh nhóm tàu Liêu Ninh.',
            'sections' => [[
                'heading' => 'Chi tiết chưa thể dùng',
                'body' => $blockedFact,
                'facts_used' => ['f1'],
            ]],
            'sentence_fact_map' => [[
                'sentence_text' => 'Một bên nêu cáo buộc về hoạt động theo dõi quanh nhóm tàu Liêu Ninh.',
                'sentence_type' => 'attributed_claim',
                'approved_fact_ids' => ['f1'],
                'approved_inference_id' => '',
                'claim_status' => 'official_claim',
                'attribution_in_sentence' => true,
                'blocked_fact_hit' => false,
                'source_similarity_score' => 0.0,
                'fact_sheet_similarity_score' => 0.0,
            ], [
                'sentence_text' => $blockedFact,
                'sentence_type' => 'fact',
                'approved_fact_ids' => ['f1'],
                'approved_inference_id' => '',
                'claim_status' => 'verified_fact',
                'attribution_in_sentence' => true,
                'blocked_fact_hit' => false,
                'source_similarity_score' => 0.0,
                'fact_sheet_similarity_score' => 0.0,
            ]],
            'source_mentions' => ['Tin nguồn'],
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
            'url' => 'https://seed.example.test/naval.html',
            'title' => 'Tin nguồn',
            'publisher' => 'Seed Publisher',
            'content' => $approvedFact . "\n" . $blockedFact,
        ],
        'related_sources' => [[
            'url' => 'https://related.example.test/naval.html',
            'title' => 'Tin liên quan',
            'publisher' => 'Related Publisher',
            'content' => 'Nguồn liên quan xác nhận tranh cãi về hoạt động theo dõi trên biển.',
        ]],
    ]);

    expect($result['status'])->toBe('needs_manual_review')
        ->and($result['mechanical_review']['metrics']['blocked_fact_in_public_body_count'])->toBe(1)
        ->and(collect($result['mechanical_review']['issues'])->pluck('detail')->implode(' '))->toContain('blocked/excluded fact');
});
