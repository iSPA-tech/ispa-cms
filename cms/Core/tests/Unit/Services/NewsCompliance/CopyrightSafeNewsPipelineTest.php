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

uses(\Cms\Core\Tests\TestCase::class);

afterEach(function (): void {
    m::close();
});

beforeEach(function (): void {
    config()->set('core.news_compliance.mechanical_review.rewrite_on_independence_failure', false);
});

require_once __DIR__ . '/TestSupport/CopyrightSafeNewsPipelineTestSupport.php';

require_once __DIR__ . '/CopyrightSafeNewsPipelineTestPart01.php';
require_once __DIR__ . '/CopyrightSafeNewsPipelineTestPart02.php';
require_once __DIR__ . '/CopyrightSafeNewsPipelineTestPart03.php';
require_once __DIR__ . '/CopyrightSafeNewsPipelineTestPart04.php';
