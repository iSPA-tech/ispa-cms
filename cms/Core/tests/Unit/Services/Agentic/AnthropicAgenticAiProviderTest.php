<?php

use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Agents\Contracts\ReasoningEngineInterface;
use Cms\Core\Agents\Runtime\NullReasoningEngine;
use Cms\Core\Agents\Runtime\OpenAiReasoningEngine;
use Cms\Core\Services\Agentic\AgenticAiProviderFactory;
use Cms\Core\Services\Agentic\AnthropicAgenticAiProvider;
use Cms\Core\Services\Agentic\NullAgenticAiProvider;
use Cms\Core\Services\Agentic\OpenAiAgenticAiProvider;
use Cms\Core\Services\Ai\AiStructuredExtractionService;
use Cms\Core\Services\Ai\AiTaskRuntime;
use Cms\Core\Services\Ai\JsonSchemaShapeValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(\Tests\TestCase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('core.ai_structured_extraction.providers.anthropic.api_key', 'test-key');
    // A DB-stored base URI may carry the version segment; it must not end up as /v1/v1/messages.
    config()->set('core.ai_structured_extraction.providers.anthropic.base_uri', 'https://api.anthropic.com/v1/');
    config()->set('core.ai_structured_extraction.providers.anthropic.model', 'claude-sonnet-5-5');
    config()->set('core.ai_structured_extraction.providers.anthropic.max_tokens', 16000);
    config()->set('core.ai_structured_extraction.providers.anthropic.min_max_tokens', 0);
    config()->set('core.ai_structured_extraction.providers.anthropic.effort', '');
    config()->set('core.ai_structured_extraction.providers.anthropic.max_attempts', 3);
    config()->set('core.ai_structured_extraction.providers.anthropic.retry_base_delay_ms', 0);
    // Hermetic against ANTHROPIC_MODEL_* values of the local .env; the shipped patterns are asserted separately.
    config()->set('core.ai_structured_extraction.providers.anthropic.model_map', array_combine(
        array_keys(anthropicShippedModelMap()),
        ['claude-haiku-5-5', 'claude-opus-5-5', 'claude-sonnet-5-5'],
    ));
});

/** @return array<string, string> */
function anthropicShippedModelMap(): array
{
    return (require __DIR__ . '/../../../../config/ai_structured_extraction.php')['providers']['anthropic']['model_map'];
}

function anthropicMessage(array $overrides = []): array
{
    return [
        'id' => 'msg_test',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => '{"ok":true}']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
        ...$overrides,
    ];
}

function anthropicSentBody(int $index = 0): array
{
    return Http::recorded()[$index][0]->data();
}

function anthropicChatPayload(array $overrides = []): array
{
    return [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a classifier.'],
            ['role' => 'user', 'content' => 'Classify this.'],
        ],
        ...$overrides,
    ];
}

function anthropicSchemaFormat(): array
{
    return [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'sample',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'required' => ['ok', 'nested'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'nested' => [
                        'type' => 'object',
                        'required' => ['a'],
                        'properties' => ['a' => ['type' => 'string']],
                    ],
                ],
            ],
        ],
    ];
}

test('anthropic provider translates an OpenAI chat payload into a Messages API request', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage())]);

    $response = (new AnthropicAgenticAiProvider())->createCompletion(anthropicChatPayload([
        'response_format' => anthropicSchemaFormat(),
        'temperature' => 0.2,
        'top_p' => 0.9,
        'frequency_penalty' => 0.1,
        'presence_penalty' => 0.1,
        'seed' => 7,
        'n' => 1,
        'logit_bias' => ['1' => 1],
        'stream' => false,
        'user' => 'u-1',
        'store' => false,
        'request_timeout_seconds' => 60,
        'request_connect_timeout_seconds' => 10,
        'request_max_attempts' => 2,
    ]));

    Http::assertSentCount(1);
    $request = Http::recorded()[0][0];
    $body = anthropicSentBody();
    $schema = $body['output_config']['format']['schema'];

    // The /v1 of the configured base URI is not doubled, and the Anthropic headers are used.
    expect($request->url())->toBe('https://api.anthropic.com/v1/messages')
        ->and($request->header('x-api-key'))->toBe(['test-key'])
        ->and($request->header('anthropic-version'))->toBe(['2023-06-01'])
        // Only whitelisted keys survive: sampling, OpenAI-only and transport controls are gone.
        ->and(array_keys($body))->toEqualCanonicalizing(['model', 'max_tokens', 'system', 'messages', 'output_config'])
        ->and($body['model'])->toBe('claude-sonnet-5-5')
        ->and($body['max_tokens'])->toBe(16000)
        ->and($body['system'])->toContain('You are a classifier.')
        ->and($body['messages'])->toBe([['role' => 'user', 'content' => 'Classify this.']])
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        // Objects get closed, required lists are untouched.
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['nested']['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toBe(['ok', 'nested'])
        ->and($schema['properties']['nested']['required'])->toBe(['a'])
        ->and($response['content'][0]['text'])->toBe('{"ok":true}');
});

test('anthropic provider sends json_object requests as an instruction instead of a format', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage())]);

    (new AnthropicAgenticAiProvider())->createCompletion(anthropicChatPayload(['response_format' => ['type' => 'json_object']]));

    $body = anthropicSentBody();

    expect($body)->not->toHaveKey('output_config')
        ->and($body['system'])->toContain('You are a classifier.')
        ->and($body['system'])->toContain('Return only a valid JSON object.');
});

test('anthropic provider adds no JSON instruction to plain text requests and merges system messages', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage(['content' => [['type' => 'text', 'text' => 'Xin chào']]]))]);
    $provider = new AnthropicAgenticAiProvider();

    $response = $provider->createCompletion(anthropicChatPayload([
        'messages' => [
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'developer', 'content' => [['type' => 'text', 'text' => 'Reply in Vietnamese.']]],
            ['role' => 'user', 'content' => 'Hello'],
        ],
    ]));

    $body = anthropicSentBody();

    expect($provider->getContent($response))->toBe('Xin chào')
        ->and($body['system'])->toBe("Be brief.\n\nReply in Vietnamese.")
        ->and($body)->not->toHaveKey('output_config');
});

test('anthropic provider maps max_tokens and max_completion_tokens and can apply a floor', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage())]);
    $provider = new AnthropicAgenticAiProvider();

    $provider->createCompletion(anthropicChatPayload(['max_tokens' => 500]));
    $provider->createCompletion(anthropicChatPayload(['max_completion_tokens' => 700]));
    config()->set('core.ai_structured_extraction.providers.anthropic.min_max_tokens', 4096);
    $provider->createCompletion(anthropicChatPayload(['max_tokens' => 100]));

    expect(anthropicSentBody(0)['max_tokens'])->toBe(500)
        ->and(anthropicSentBody(1)['max_tokens'])->toBe(700)
        ->and(anthropicSentBody(2)['max_tokens'])->toBe(4096);
});

test('anthropic provider keeps temperature for legacy models only', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage())]);
    $provider = new AnthropicAgenticAiProvider();

    $provider->createCompletion(anthropicChatPayload(['model' => 'claude-sonnet-4-6', 'temperature' => 0.3]));
    $provider->createCompletion(anthropicChatPayload(['model' => 'claude-haiku-5-5', 'temperature' => 0.3]));

    expect(anthropicSentBody(0)['temperature'])->toBe(0.3)
        ->and(anthropicSentBody(1))->not->toHaveKey('temperature');
});

test('anthropic provider drops a trailing assistant prefill on current models', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage())]);

    (new AnthropicAgenticAiProvider())->createCompletion(anthropicChatPayload([
        'messages' => [
            ['role' => 'user', 'content' => 'Give JSON.'],
            ['role' => 'assistant', 'content' => '{'],
        ],
    ]));

    expect(anthropicSentBody()['messages'])->toBe([['role' => 'user', 'content' => 'Give JSON.']]);
});

test('anthropic provider maps other vendors models to Claude and leaves claude ids alone', function (string $requested, string $expected): void {
    expect(AnthropicAgenticAiProvider::resolveModel($requested))->toBe($expected);
})->with([
    'gpt-4o-mini' => ['gpt-4o-mini', 'claude-haiku-5-5'],
    'gpt-5.4-mini' => ['gpt-5.4-mini', 'claude-haiku-5-5'],
    'gpt-5.6-luna' => ['gpt-5.6-luna', 'claude-haiku-5-5'],
    'gemini flash' => ['gemini-3.5-flash', 'claude-haiku-5-5'],
    'gpt-3.5' => ['gpt-3.5-turbo', 'claude-haiku-5-5'],
    'gpt-4o' => ['gpt-4o', 'claude-sonnet-5-5'],
    'gpt-4.1' => ['gpt-4.1', 'claude-sonnet-5-5'],
    'gpt-5.6-terra' => ['gpt-5.6-terra', 'claude-sonnet-5-5'],
    'o3' => ['o3', 'claude-sonnet-5-5'],
    'deepseek' => ['deepseek-v4-pro', 'claude-sonnet-5-5'],
    'grok' => ['grok-4.3', 'claude-sonnet-5-5'],
    'gemini pro' => ['gemini-3-pro', 'claude-sonnet-5-5'],
    'gpt-5.6-sol' => ['gpt-5.6-sol', 'claude-opus-5-5'],
    'claude haiku' => ['claude-haiku-5-5', 'claude-haiku-5-5'],
    'claude fable' => ['claude-fable-5-1', 'claude-fable-5-1'],
    'claude legacy' => ['claude-sonnet-4-6', 'claude-sonnet-4-6'],
    'empty uses the provider default' => ['', 'claude-sonnet-5-5'],
]);

test('anthropic provider sends the mapped model and honours a custom map and provider default', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage())]);
    $provider = new AnthropicAgenticAiProvider();

    $provider->createCompletion(anthropicChatPayload(['model' => 'gpt-4o-mini']));
    config()->set('core.ai_structured_extraction.providers.anthropic.model_map', [
        'gpt-4o-mini' => 'claude-haiku-custom',
        '/^o\d/' => 'claude-opus-custom',
    ]);
    config()->set('core.ai_structured_extraction.providers.anthropic.model', 'claude-default-custom');
    $provider->createCompletion(anthropicChatPayload(['model' => 'GPT-4o-mini-2026']));
    $provider->createCompletion(anthropicChatPayload(['model' => 'o3']));
    $provider->createCompletion(anthropicChatPayload(['model' => 'something-else']));

    expect(anthropicSentBody(0)['model'])->toBe('claude-haiku-5-5')
        ->and(anthropicSentBody(1)['model'])->toBe('claude-haiku-custom')
        ->and(anthropicSentBody(2)['model'])->toBe('claude-opus-custom')
        ->and(anthropicSentBody(3)['model'])->toBe('claude-default-custom');
});

test('the model map slots can be repointed with ANTHROPIC_MODEL_FAST, STANDARD and PREMIUM', function (): void {
    $keys = ['ANTHROPIC_MODEL_FAST', 'ANTHROPIC_MODEL_STANDARD', 'ANTHROPIC_MODEL_PREMIUM'];
    $before = array_map(static fn (string $key): mixed => $_SERVER[$key] ?? null, array_combine($keys, $keys));
    foreach ($keys as $key) {
        $_SERVER[$key] = strtolower($key) . '-model';
    }

    try {
        $overridden = anthropicShippedModelMap();
    } finally {
        foreach ($before as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }

    expect(array_values($overridden))->toBe(['anthropic_model_fast-model', 'anthropic_model_premium-model', 'anthropic_model_standard-model'])
        ->and(array_keys($overridden))->toBe(array_keys(anthropicShippedModelMap()))
        ->and(count(anthropicShippedModelMap()))->toBe(3);
});

test('anthropic provider maps reasoning effort to output_config.effort', function (mixed $caller, string $configured, ?string $expected): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage())]);
    config()->set('core.ai_structured_extraction.providers.anthropic.effort', $configured);

    (new AnthropicAgenticAiProvider())->createCompletion(anthropicChatPayload(
        $caller === null ? [] : ['reasoning_effort' => $caller],
    ));

    expect(anthropicSentBody()['output_config']['effort'] ?? null)->toBe($expected);
})->with([
    'minimal' => ['minimal', '', 'low'],
    'low' => ['low', 'high', 'low'],
    'medium' => ['medium', '', 'medium'],
    'high' => ['HIGH', '', 'high'],
    'xhigh' => ['xhigh', '', 'xhigh'],
    'max' => ['max', '', 'max'],
    'invalid falls back to config' => ['turbo', 'high', 'high'],
    'invalid config is omitted' => ['turbo', 'turbo', null],
    'caller absent uses config' => [null, 'medium', 'medium'],
    'nothing to send' => [null, '', null],
]);

test('anthropic provider retries a 529 overload and then succeeds', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529, ['retry-after' => '0'])
        ->push(anthropicMessage())]);

    $response = (new AnthropicAgenticAiProvider())->createCompletion(anthropicChatPayload(['model' => 'claude-sonnet-5-5']));

    Http::assertSentCount(2);
    expect($response['content'][0]['text'])->toBe('{"ok":true}');
});

test('anthropic provider retries connection errors, 429 and 5xx, then gives up after max_attempts', function (): void {
    Log::spy();
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->pushFailedConnection()
        ->push([], 429, ['retry-after' => '0'])
        ->push([], 503)
        ->push(anthropicMessage())]);
    $provider = new AnthropicAgenticAiProvider();

    $result = $provider->createCompletion(anthropicChatPayload(['model' => 'claude-sonnet-5-5']));

    // Three attempts are allowed: connection error (never recorded: no response), 429, 503 -> failure.
    // The fourth, successful response in the sequence is never requested.
    Http::assertSentCount(2);
    expect($result)->toBe([]);
    Log::shouldHaveReceived('error')->once();
});

test('anthropic provider does not retry client errors', function (): void {
    Log::spy();
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'bad']], 400)
        ->push(anthropicMessage())]);

    $result = (new AnthropicAgenticAiProvider())->createCompletion(anthropicChatPayload(['model' => 'claude-sonnet-5-5']));

    Http::assertSentCount(1);
    expect($result)->toBe([]);
});

test('anthropic provider honours retry-after but caps the wait', function (): void {
    config()->set('core.ai_structured_extraction.providers.anthropic.retry_max_wait_seconds', 0);
    config()->set('core.ai_structured_extraction.providers.anthropic.max_attempts', 2);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push([], 429, ['retry-after' => '120'])
        ->push(anthropicMessage())]);

    $started = microtime(true);
    $response = (new AnthropicAgenticAiProvider())->createCompletion(anthropicChatPayload(['model' => 'claude-sonnet-5-5']));

    expect($response['stop_reason'])->toBe('end_turn')
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
});

test('anthropic provider exposes the answer text and an OpenAI compatible choices block', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage(['content' => [
        ['type' => 'thinking', 'thinking' => 'hidden', 'signature' => 'sig'],
        ['type' => 'text', 'text' => "```json\n{\"ok\":true}\n```"],
    ]]))]);
    $provider = new AnthropicAgenticAiProvider();

    $response = $provider->createCompletion(anthropicChatPayload());

    expect($provider->getContent($response))->toBe('{"ok":true}')
        ->and($response['choices'][0]['message']['content'])->toBe('{"ok":true}')
        ->and($response['choices'][0]['finish_reason'])->toBe('stop')
        ->and($response['stop_reason'])->toBe('end_turn')
        ->and($response['usage']['input_tokens'])->toBe(10)
        ->and(AnthropicAgenticAiProvider::failureReason($response))->toBeNull();
});

test('anthropic provider leaves non json code fences alone', function (): void {
    $provider = new AnthropicAgenticAiProvider();
    $text = "```php\necho 1;\n```";

    expect($provider->getContent(['content' => [['type' => 'text', 'text' => $text]]]))->toBe($text);
});

test('anthropic provider surfaces refusal and max_tokens stop reasons', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(anthropicMessage(['stop_reason' => 'refusal', 'content' => []]))
        ->push(anthropicMessage(['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"ok"']]]))
        ->push(anthropicMessage(['content' => [['type' => 'thinking', 'thinking' => 'only thoughts']]]))]);
    $provider = new AnthropicAgenticAiProvider();

    $refusal = $provider->createCompletion(anthropicChatPayload());
    $truncated = $provider->createCompletion(anthropicChatPayload());
    $empty = $provider->createCompletion(anthropicChatPayload());

    expect(AnthropicAgenticAiProvider::failureReason($refusal))->toBe('refusal')
        ->and($refusal['choices'][0]['message']['refusal'])->not->toBe('')
        ->and($refusal['choices'][0]['finish_reason'])->toBe('content_filter')
        ->and(AnthropicAgenticAiProvider::failureReason($truncated))->toBe('max_tokens')
        ->and($truncated['choices'][0]['finish_reason'])->toBe('length')
        ->and(AnthropicAgenticAiProvider::failureReason($empty))->toBe('empty')
        // Like OpenAIClient::getContent(): an empty or failed response never yields an empty string.
        ->and($provider->getContent($refusal))->toBe('{}')
        ->and($provider->getContent([]))->toBe('{}')
        // OpenAI shaped responses are never judged here.
        ->and(AnthropicAgenticAiProvider::failureReason(['choices' => [['finish_reason' => 'length']]]))->toBeNull()
        ->and(AnthropicAgenticAiProvider::failureReason([]))->toBeNull();
});

test('anthropic provider translates content arrays: text, image and PDF file parts', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage())]);

    (new AnthropicAgenticAiProvider())->createCompletion(anthropicChatPayload([
        'messages' => [
            ['role' => 'user', 'content' => [
                ['type' => 'file', 'file' => ['filename' => 'scan.pdf', 'file_data' => 'data:application/pdf;base64,JVBERi0xLjQ=']],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=', 'detail' => 'high']],
                ['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/a.jpg']],
                ['type' => 'text', 'text' => 'Read both.'],
                ['type' => 'text', 'text' => '  '],
            ]],
        ],
    ]));

    expect(anthropicSentBody()['messages'][0]['content'])->toBe([
        ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'JVBERi0xLjQ=']],
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'iVBORw0KGgo=']],
        ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://example.test/a.jpg']],
        ['type' => 'text', 'text' => 'Read both.'],
    ]);
});

test('anthropic provider refuses a file it cannot express instead of dropping it silently', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage())]);
    Log::spy();

    $result = (new AnthropicAgenticAiProvider())->createCompletion(anthropicChatPayload([
        'messages' => [['role' => 'user', 'content' => [
            ['type' => 'file', 'file' => ['filename' => 'a.docx', 'file_data' => 'data:application/vnd.openxmlformats;base64,AAAA']],
        ]]],
    ]));

    Http::assertNothingSent();
    expect($result)->toBe([]);
});

test('anthropic provider uses the result tool when the schema has open objects', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage(['stop_reason' => 'tool_use', 'content' => [
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'submit_extraction', 'input' => ['tags' => ['a' => 'b']]],
    ]]))]);
    $provider = new AnthropicAgenticAiProvider();

    $response = $provider->createCompletion(anthropicChatPayload([
        'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 's', 'schema' => [
            'type' => 'object',
            'required' => ['tags'],
            'properties' => ['tags' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
        ]]],
    ]));

    $body = anthropicSentBody();

    expect($body)->not->toHaveKey('output_config')
        ->and($body['tools'][0]['name'])->toBe('submit_extraction')
        ->and($provider->getContent($response))->toBe('{"tags":{"a":"b"}}');
});

test('anthropic base uri is normalized without a trailing v1', function (string $configured, string $expected): void {
    expect(AnthropicAgenticAiProvider::normalizeBaseUri($configured))->toBe($expected);
})->with([
    ['https://api.anthropic.com', 'https://api.anthropic.com/'],
    ['https://api.anthropic.com/', 'https://api.anthropic.com/'],
    ['https://api.anthropic.com/v1', 'https://api.anthropic.com/'],
    ['https://api.anthropic.com/v1/', 'https://api.anthropic.com/'],
    ['https://proxy.test/anthropic/v1/', 'https://proxy.test/anthropic/'],
    ['', 'https://api.anthropic.com/'],
]);

test('extraction service reports an Anthropic refusal with the same error as the OpenAI path', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage(['stop_reason' => 'refusal', 'content' => []]))]);

    $result = anthropicExtractionService()->extract('source text', anthropicExtractionSchema(), 'Extract.', [], ['cache_enabled' => false]);

    expect($result->ok)->toBeFalse()
        ->and($result->errors)->toBe(['AI provider refused extraction.']);
});

test('extraction service treats Anthropic max_tokens like OpenAI finish_reason length', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage(['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"ok"']]]))]);

    $result = anthropicExtractionService()->extract('source text', anthropicExtractionSchema(), 'Extract.', [], ['cache_enabled' => false]);

    expect($result->ok)->toBeFalse()
        ->and($result->errors)->toBe(['AI provider response was truncated.']);
});

test('extraction service extracts through Anthropic and maps the default model', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage())]);

    $result = anthropicExtractionService()->extract('source text', anthropicExtractionSchema(), 'Extract.', [], [
        'cache_enabled' => false,
        'model' => 'gpt-5.6-luna',
        'reasoning_effort' => 'minimal',
    ]);

    $body = anthropicSentBody();

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toBe(['ok' => true])
        ->and($body['model'])->toBe('claude-haiku-5-5')
        ->and($body['output_config']['effort'])->toBe('low')
        ->and($body['messages'][0]['content'])->toContain('SOURCE TEXT:');
});

test('extraction service sends an attached PDF to Claude as a document block', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage())]);

    $result = anthropicExtractionService()->extractFile(
        'data:application/pdf;base64,JVBERi0xLjQ=',
        'report.pdf',
        'sha256:abc',
        anthropicExtractionSchema(),
        'Extract.',
        [],
        ['cache_enabled' => false, 'model' => 'gpt-4o'],
    );

    $content = anthropicSentBody()['messages'][0]['content'];

    expect($result->ok)->toBeTrue()
        ->and(anthropicSentBody()['model'])->toBe('claude-sonnet-5-5')
        ->and($content[0])->toBe(['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'JVBERi0xLjQ=']])
        ->and($content[1]['type'])->toBe('text')
        ->and($content[1]['text'])->toContain('ATTACHED SOURCE:');
});

function anthropicExtractionService(): AiStructuredExtractionService
{
    config()->set('core.ai_structured_extraction.enabled', true);
    config()->set('core.ai_structured_extraction.system_prompt', 'Extract structured data.');

    return new AiStructuredExtractionService(new AnthropicAgenticAiProvider(), new JsonSchemaShapeValidator());
}

function anthropicExtractionSchema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['ok'],
        'properties' => ['ok' => ['type' => 'boolean']],
    ];
}

test('the agentic ai binding stays openai by default and follows the driver for any factory provider', function (): void {
    $resolve = function (string $driver): AgenticAiProviderInterface {
        config()->set('core.agentic.ai_provider.driver', $driver);
        foreach ([AgenticAiProviderInterface::class, ReasoningEngineInterface::class, AiTaskRuntime::class, AiStructuredExtractionService::class, AgenticAiProviderFactory::class] as $abstract) {
            app()->forgetInstance($abstract);
        }

        return app(AgenticAiProviderInterface::class);
    };

    expect($resolve('openai'))->toBeInstanceOf(OpenAiAgenticAiProvider::class)
        ->and($resolve('anthropic'))->toBeInstanceOf(AnthropicAgenticAiProvider::class)
        ->and($resolve('Claude'))->toBeInstanceOf(AnthropicAgenticAiProvider::class)
        ->and($resolve('null'))->toBeInstanceOf(NullAgenticAiProvider::class)
        ->and($resolve('agentic_ai'))->toBeInstanceOf(NullAgenticAiProvider::class)
        ->and($resolve('no-such-driver'))->toBeInstanceOf(NullAgenticAiProvider::class);
});

test('agentic tool selection stays on OpenAI whatever the ai provider driver is', function (): void {
    $engineProvider = function (string $driver): ?AgenticAiProviderInterface {
        config()->set('core.agentic.ai_provider.driver', $driver);
        foreach ([AgenticAiProviderInterface::class, ReasoningEngineInterface::class, AiTaskRuntime::class] as $abstract) {
            app()->forgetInstance($abstract);
        }

        $engine = app(ReasoningEngineInterface::class);
        if (! $engine instanceof OpenAiReasoningEngine) {
            return null;
        }

        return (new ReflectionProperty($engine, 'aiProvider'))->getValue($engine);
    };

    expect($engineProvider('openai'))->toBeInstanceOf(OpenAiAgenticAiProvider::class)
        ->and($engineProvider('anthropic'))->toBeInstanceOf(OpenAiAgenticAiProvider::class)
        ->and($engineProvider('null'))->toBeNull();

    config()->set('core.agentic.ai_provider.driver', 'null');
    app()->forgetInstance(ReasoningEngineInterface::class);
    expect(app(ReasoningEngineInterface::class))->toBeInstanceOf(NullReasoningEngine::class);
});
