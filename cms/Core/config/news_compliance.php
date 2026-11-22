<?php

return [
    'enabled' => (bool) env('CORE_NEWS_COMPLIANCE_ENABLED', true),
    'user_agent' => env('CORE_NEWS_COMPLIANCE_USER_AGENT', 'iSPANewsComplianceBot/1.0 (+https://ispa.io)'),
    'discovery' => [
        'enabled' => filter_var(env('CORE_NEWS_COMPLIANCE_DISCOVERY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'max_related_sources' => (int) env('CORE_NEWS_COMPLIANCE_MAX_RELATED_SOURCES', 5),
        'min_related_sources_for_auto_pass' => (int) env('CORE_NEWS_COMPLIANCE_MIN_RELATED_SOURCES_FOR_AUTO_PASS', 1),
        'request_delay_milliseconds' => (int) env('CORE_NEWS_COMPLIANCE_SEARCH_DELAY_MS', 900),
        'excluded_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => trim($host),
            explode(',', (string) env('CORE_NEWS_COMPLIANCE_EXCLUDED_HOSTS', '')),
        ))),
    ],
    'search' => [
        'provider' => env('CORE_NEWS_COMPLIANCE_SEARCH_PROVIDER', 'brave'),
        'timeout_seconds' => (int) env('CORE_NEWS_COMPLIANCE_SEARCH_TIMEOUT_SECONDS', 10),
        'country' => env('CORE_NEWS_COMPLIANCE_SEARCH_COUNTRY', 'VN'),
        'language' => env('CORE_NEWS_COMPLIANCE_SEARCH_LANGUAGE', 'vi'),
        'brave' => [
            'base_url' => env('BRAVE_SEARCH_BASE_URL', 'https://api.search.brave.com/res/v1/web/search'),
            'api_key' => env('BRAVE_SEARCH_API_KEY'),
        ],
    ],
    'evidence_crawl' => [
        'timeout_seconds' => (int) env('CORE_NEWS_COMPLIANCE_EVIDENCE_TIMEOUT_SECONDS', 12),
        'max_text_chars' => (int) env('CORE_NEWS_COMPLIANCE_EVIDENCE_MAX_TEXT_CHARS', 9000),
        'max_links' => (int) env('CORE_NEWS_COMPLIANCE_EVIDENCE_MAX_LINKS', 30),
    ],
    'mechanical_review' => [
        'rewrite_on_independence_failure' => filter_var(env('CORE_NEWS_COMPLIANCE_REWRITE_ON_INDEPENDENCE_FAILURE', true), FILTER_VALIDATE_BOOLEAN),
        'headline_medium_similarity' => (float) env('CORE_NEWS_COMPLIANCE_HEADLINE_MEDIUM_SIMILARITY', 0.45),
        'headline_high_similarity' => (float) env('CORE_NEWS_COMPLIANCE_HEADLINE_HIGH_SIMILARITY', 0.65),
        'sentence_medium_similarity' => (float) env('CORE_NEWS_COMPLIANCE_SENTENCE_MEDIUM_SIMILARITY', 0.75),
        'sentence_high_similarity' => (float) env('CORE_NEWS_COMPLIANCE_SENTENCE_HIGH_SIMILARITY', 0.86),
        'fact_sheet_sentence_medium_similarity' => (float) env('CORE_NEWS_COMPLIANCE_FACT_SHEET_SENTENCE_MEDIUM_SIMILARITY', 0.90),
        'fact_sheet_sentence_high_similarity' => (float) env('CORE_NEWS_COMPLIANCE_FACT_SHEET_SENTENCE_HIGH_SIMILARITY', 0.93),
    ],
    'ai' => [
        'provider' => env('CORE_NEWS_COMPLIANCE_AI_PROVIDER', env('AI_STRUCTURED_EXTRACTION_PROVIDER', 'agentic_ai')),
        'model' => env('CORE_NEWS_COMPLIANCE_AI_MODEL', env('AI_STRUCTURED_EXTRACTION_MODEL', 'gpt-5.6-luna')),
        'stages' => [
            'fact_sheet' => [
                'provider' => env('CORE_NEWS_COMPLIANCE_FACT_AI_PROVIDER', env('CORE_NEWS_COMPLIANCE_AI_PROVIDER', env('AI_STRUCTURED_EXTRACTION_PROVIDER', 'agentic_ai'))),
                'model' => env('CORE_NEWS_COMPLIANCE_FACT_AI_MODEL', env('CORE_NEWS_COMPLIANCE_AI_MODEL', env('AI_STRUCTURED_EXTRACTION_MODEL', 'gpt-5.6-luna'))),
            ],
            'draft' => [
                'provider' => env('CORE_NEWS_COMPLIANCE_DRAFT_AI_PROVIDER', env('CORE_NEWS_COMPLIANCE_AI_PROVIDER', env('AI_STRUCTURED_EXTRACTION_PROVIDER', 'agentic_ai'))),
                'model' => env('CORE_NEWS_COMPLIANCE_DRAFT_AI_MODEL', env('CORE_NEWS_COMPLIANCE_AI_MODEL', env('AI_STRUCTURED_EXTRACTION_MODEL', 'gpt-5.6-luna'))),
            ],
            'review' => [
                'provider' => env('CORE_NEWS_COMPLIANCE_REVIEW_AI_PROVIDER', env('CORE_NEWS_COMPLIANCE_AI_PROVIDER', env('AI_STRUCTURED_EXTRACTION_PROVIDER', 'agentic_ai'))),
                'model' => env('CORE_NEWS_COMPLIANCE_REVIEW_AI_MODEL', env('CORE_NEWS_COMPLIANCE_AI_MODEL', env('AI_STRUCTURED_EXTRACTION_MODEL', 'gpt-5.6-luna'))),
            ],
        ],
        'cache_enabled' => filter_var(env('CORE_NEWS_COMPLIANCE_AI_CACHE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'max_fact_input_chars' => (int) env('CORE_NEWS_COMPLIANCE_FACT_INPUT_MAX_CHARS', 40000),
    ],
];
