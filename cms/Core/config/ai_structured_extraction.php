<?php

return [
    'enabled' => (bool) env('AI_STRUCTURED_EXTRACTION_ENABLED', true),
    'provider' => env('AI_STRUCTURED_EXTRACTION_PROVIDER', 'agentic_ai'),
    'model' => env('AI_STRUCTURED_EXTRACTION_MODEL', 'gpt-5.6-luna'),
    'temperature' => env('AI_STRUCTURED_EXTRACTION_TEMPERATURE') !== null
        ? (float) env('AI_STRUCTURED_EXTRACTION_TEMPERATURE')
        : 0.0,
    'strict' => (bool) env('AI_STRUCTURED_EXTRACTION_STRICT', true),
    'max_input_chars' => (int) env('AI_STRUCTURED_EXTRACTION_MAX_INPUT_CHARS', 60000),
    'cache' => [
        'enabled' => (bool) env('AI_STRUCTURED_EXTRACTION_CACHE_ENABLED', true),
    ],
    'request' => [
        'timeout_seconds' => (float) env('AI_STRUCTURED_EXTRACTION_TIMEOUT_SECONDS', 60),
        'connect_timeout_seconds' => (float) env('AI_STRUCTURED_EXTRACTION_CONNECT_TIMEOUT_SECONDS', 10),
        'max_attempts' => (int) env('AI_STRUCTURED_EXTRACTION_MAX_ATTEMPTS', 2),
    ],
    'system_prompt' => implode("\n", [
        'Extract structured data from the provided source text.',
        'Return only facts that are explicitly supported by the source.',
        'If a field is missing, use the null or empty value allowed by the schema.',
        'Evidence fields must quote or closely copy the relevant source text.',
        'Do not infer, invent, translate, or normalize beyond the requested schema.',
    ]),
    'schemas' => [
        // Modules may register reusable schemas here, but callers can also pass
        // a JSON Schema directly to AiStructuredExtractionService::extract().
    ],
    'providers' => [
        'openai' => [
            'base_uri' => env('OPENAI_API_BASE_URI', 'https://api.openai.com/v1/'),
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_AI_MODEL', env('AI_STRUCTURED_EXTRACTION_MODEL', 'gpt-5.6-luna')),
            // Default `reasoning_effort` for GPT-5.x (none|minimal|low|medium|high|xhigh); empty sends none.
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', ''),
        ],
        'gemini' => [
            'base_uri' => env('GEMINI_API_BASE_URI', 'https://generativelanguage.googleapis.com/v1beta/openai/'),
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_AI_MODEL', 'gemini-3.5-flash'),
        ],
        'deepseek' => [
            'base_uri' => env('DEEPSEEK_API_BASE_URI', 'https://api.deepseek.com/'),
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model' => env('DEEPSEEK_AI_MODEL', 'deepseek-v4-pro'),
            'max_tokens' => (int) env('DEEPSEEK_MAX_TOKENS', 8192),
            'thinking_enabled' => filter_var(env('DEEPSEEK_THINKING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        ],
        'grok' => [
            'base_uri' => env('XAI_API_BASE_URI', 'https://api.x.ai/v1/'),
            'api_key' => env('XAI_API_KEY'),
            'model' => env('XAI_AI_MODEL', 'grok-4.3'),
        ],
        'anthropic' => [
            'base_uri' => env('ANTHROPIC_API_BASE_URI', 'https://api.anthropic.com/'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
            // Provider default model: used when a caller sends no model. Callers that hardcode a non-Claude
            // model are translated through `model_map` below.
            'model' => env('ANTHROPIC_AI_MODEL', 'claude-sonnet-5-5'),
            // Thinking is always on for current models and counts toward max_tokens, so leave room for it.
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),
            // Optional floor for a caller's own max_tokens/max_completion_tokens (0 = never raise it). Callers tuned
            // for other vendors sometimes cap at 100-500 tokens, which thinking alone can exhaust.
            'min_max_tokens' => (int) env('ANTHROPIC_MIN_MAX_TOKENS', 0),
            // Optional `output_config.effort` (low|medium|high|xhigh|max); empty sends none (model default).
            'effort' => env('ANTHROPIC_EFFORT', ''),
            // Transport: retries on connect errors, 429, 5xx and 529 with exponential backoff (honours `retry-after`).
            'max_attempts' => (int) env('ANTHROPIC_MAX_ATTEMPTS', 3),
            'retry_base_delay_ms' => (int) env('ANTHROPIC_RETRY_BASE_DELAY_MS', 1000),
            'retry_max_wait_seconds' => (int) env('ANTHROPIC_RETRY_MAX_WAIT_SECONDS', 30),
            'connect_timeout_seconds' => (float) env('ANTHROPIC_CONNECT_TIMEOUT_SECONDS', 10),
            // Read timeout; acts as a floor over a caller's own `request_timeout_seconds` (Claude answers slower).
            'timeout_seconds' => (float) env('ANTHROPIC_TIMEOUT_SECONDS', 180),
            // Ordered map applied to requested models that do not start with `claude-`: first match wins.
            // A key starting with `/` is a regex, any other key is a case-insensitive prefix.
            // The three slots can be repointed per project with ANTHROPIC_MODEL_FAST / _STANDARD / _PREMIUM.
            // The size words are matched as whole words so that e.g. `gemini` (contains "mini") is not a small model.
            'model_map' => [
                '/(?<![a-z])(mini|nano|luna|lite|flash)(?![a-z])|gpt-3\.5/i' => env('ANTHROPIC_MODEL_FAST') ?: 'claude-haiku-5-5',
                '/(?<![a-z])(sol|opus)(?![a-z])/i' => env('ANTHROPIC_MODEL_PREMIUM') ?: 'claude-opus-5-5',
                '/./' => env('ANTHROPIC_MODEL_STANDARD') ?: 'claude-sonnet-5-5',
            ],
        ],
    ],
];
