<?php

use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Services\Agentic\AgenticAiProviderFactory;
use Cms\Core\Services\Agentic\AnthropicAgenticAiProvider;
use Cms\Core\Services\Agentic\DeepSeekAgenticAiProvider;
use Cms\Core\Services\Agentic\GeminiAgenticAiProvider;
use Cms\Core\Services\Agentic\GrokAgenticAiProvider;
use Cms\Core\Services\Agentic\OpenAiCompatibleHttpAgenticAiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;

uses(\Tests\TestCase::class);

test('factory resolves explicit provider drivers for structured extraction', function (): void {
    config()->set('core.ai_structured_extraction.providers.gemini.api_key', 'gemini-key');
    config()->set('core.ai_structured_extraction.providers.deepseek.api_key', 'deepseek-key');
    config()->set('core.ai_structured_extraction.providers.grok.api_key', 'xai-key');
    config()->set('core.ai_structured_extraction.providers.anthropic.api_key', 'anthropic-key');

    $factory = app(AgenticAiProviderFactory::class);

    expect($factory->make('gemini'))->toBeInstanceOf(GeminiAgenticAiProvider::class)
        ->and($factory->make('deepseek'))->toBeInstanceOf(DeepSeekAgenticAiProvider::class)
        ->and($factory->make('grok'))->toBeInstanceOf(GrokAgenticAiProvider::class)
        ->and($factory->make('xai'))->toBeInstanceOf(GrokAgenticAiProvider::class)
        ->and($factory->make('anthropic'))->toBeInstanceOf(AnthropicAgenticAiProvider::class)
        ->and($factory->make('claude'))->toBeInstanceOf(AnthropicAgenticAiProvider::class)
        ->and($factory->make('agentic_ai'))->toBeInstanceOf(AgenticAiProviderInterface::class);
});

test('gemini provider uses openai compatible chat completions with json schema', function (): void {
    $history = [];
    $provider = new GeminiAgenticAiProvider(guzzleClient([
        new Response(200, [], json_encode([
            'choices' => [
                ['message' => ['content' => '{"ok":true}']],
            ],
        ], JSON_THROW_ON_ERROR)),
    ], $history));

    $response = $provider->createCompletion(sampleProviderPayload());

    expect($provider->getContent($response))->toBe('{"ok":true}');

    $request = $history[0]['request'];
    $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect((string) $request->getUri())->toContain('/chat/completions')
        ->and($body['response_format']['type'])->toBe('json_schema')
        ->and($body['response_format']['json_schema']['name'])->toBe('sample_schema');
});

test('deepseek provider downgrades schema request to json object mode', function (): void {
    config()->set('core.ai_structured_extraction.providers.deepseek.max_tokens', 1234);
    config()->set('core.ai_structured_extraction.providers.deepseek.thinking_enabled', false);

    $history = [];
    $provider = new DeepSeekAgenticAiProvider(guzzleClient([
        new Response(200, [], json_encode([
            'choices' => [
                ['message' => ['content' => '{"ok":true}']],
            ],
        ], JSON_THROW_ON_ERROR)),
    ], $history));

    $provider->createCompletion(sampleProviderPayload());

    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($body['response_format'])->toBe(['type' => 'json_object'])
        ->and($body['thinking'])->toBe(['type' => 'disabled'])
        ->and($body['max_tokens'])->toBe(1234)
        ->and($body['messages'][0]['content'])->toContain('JSON')
        ->and($body['messages'][0]['content'])->toContain('EXAMPLE JSON OUTPUT')
        ->and($body['messages'][0]['content'])->toContain('"ok": true')
        ->and($body['messages'][0]['content'])->toContain('sample_schema');
});

test('grok provider keeps json schema response format', function (): void {
    $history = [];
    $provider = new GrokAgenticAiProvider(guzzleClient([
        new Response(200, [], json_encode([
            'choices' => [
                ['message' => ['content' => '{"ok":true}']],
            ],
        ], JSON_THROW_ON_ERROR)),
    ], $history));

    $provider->createCompletion(sampleProviderPayload());

    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($body['response_format']['type'])->toBe('json_schema')
        ->and($body['response_format']['json_schema']['strict'])->toBeTrue();
});

test('openai compatible provider logs omit provider response body', function (): void {
    $canary = 'COMPATIBLE_PROVIDER_BODY_CANARY_MUST_NOT_BE_LOGGED';
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($canary): bool {
            return $message === 'AI provider completion failed'
                && ($context['status_code'] ?? null) === 502
                && isset($context['error_type'])
                && !array_key_exists('error', $context)
                && !str_contains($message, $canary)
                && !str_contains(json_encode($context, JSON_THROW_ON_ERROR), $canary);
        });

    $history = [];
    $provider = new GeminiAgenticAiProvider(guzzleClient([
        new Response(502, [], $canary),
    ], $history));

    expect($provider->createCompletion(sampleProviderPayload()))->toBe([]);
});

test('openai compatible provider logs structured error codes but never the error message', function (): void {
    $canary = 'ERROR_MESSAGE_CANARY_MUST_NOT_BE_LOGGED';
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($canary): bool {
            return $message === 'AI provider completion failed'
                && ($context['status_code'] ?? null) === 400
                && ($context['provider_error'] ?? null) === ['type' => 'invalid_request_error', 'param' => 'response_format']
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $canary);
        });

    $history = [];
    $provider = new GeminiAgenticAiProvider(guzzleClient([
        new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => [
            'message' => "Invalid schema: {$canary}",
            'type' => 'invalid_request_error',
            'param' => 'response_format',
            'code' => null,
        ]], JSON_THROW_ON_ERROR)),
    ], $history));

    expect($provider->createCompletion(sampleProviderPayload()))->toBe([]);
});

test('factory builds an openai compatible http provider when an openai api key is configured', function (): void {
    config()->set('core.ai_structured_extraction.providers.openai.api_key', 'sk-test');

    expect(app(AgenticAiProviderFactory::class)->make('openai'))
        ->toBeInstanceOf(OpenAiCompatibleHttpAgenticAiProvider::class);
});

test('openai provider drops sampling params, renames max_tokens and keeps reasoning_effort for reasoning models', function (): void {
    $history = [];
    $provider = new OpenAiCompatibleHttpAgenticAiProvider(guzzleClient([openAiOkResponse()], $history), 'openai');

    $response = $provider->createCompletion([
        ...sampleProviderPayload(),
        'model' => 'gpt-5.6-luna',
        'temperature' => 0.0,
        'top_p' => 0.9,
        'max_tokens' => 500,
        'reasoning_effort' => 'low',
    ]);

    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($provider->getContent($response))->toBe('{"ok":true}')
        ->and($body)->not->toHaveKeys(['temperature', 'top_p', 'max_tokens'])
        ->and($body['max_completion_tokens'])->toBe(500)
        ->and($body['reasoning_effort'])->toBe('low')
        ->and($body['model'])->toBe('gpt-5.6-luna')
        // Request controls are transport options, never part of the JSON body.
        ->and($body)->not->toHaveKeys(['request_timeout_seconds', 'request_connect_timeout_seconds', 'request_max_attempts']);
});

test('openai provider keeps sampling params for models that accept them and drops an empty reasoning_effort', function (): void {
    $history = [];
    $provider = new OpenAiCompatibleHttpAgenticAiProvider(guzzleClient([openAiOkResponse(), openAiOkResponse()], $history), 'openai');

    $provider->createCompletion([
        ...sampleProviderPayload(),
        'model' => 'gpt-4o-mini',
        'temperature' => 0.2,
        'top_p' => 0.8,
        'max_tokens' => 300,
        'reasoning_effort' => '',
    ]);
    $provider->createCompletion([
        ...sampleProviderPayload(),
        'model' => 'gpt-5.4-mini',
        'temperature' => 0.2,
        'max_tokens' => 300,
    ]);

    $legacy = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $current = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($legacy['temperature'])->toBe(0.2)
        ->and($legacy['top_p'])->toBe(0.8)
        ->and($legacy['max_tokens'])->toBe(300)
        ->and($legacy)->not->toHaveKeys(['max_completion_tokens', 'reasoning_effort'])
        // GPT-5.4 still accepts sampling params but, like every GPT-5.x model, wants max_completion_tokens.
        ->and($current['temperature'])->toBe(0.2)
        ->and($current)->not->toHaveKey('max_tokens')
        ->and($current['max_completion_tokens'])->toBe(300);
});

test('openai compatible providers other than openai never send reasoning_effort', function (): void {
    $history = [];
    $provider = new GeminiAgenticAiProvider(guzzleClient([openAiOkResponse()], $history));

    $provider->createCompletion([
        ...sampleProviderPayload(),
        'model' => 'gemini-model',
        'temperature' => 0.1,
        'max_tokens' => 200,
        'reasoning_effort' => 'high',
    ]);

    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($body)->not->toHaveKey('reasoning_effort')
        ->and($body['temperature'])->toBe(0.1)
        ->and($body['max_tokens'])->toBe(200)
        ->and($body)->not->toHaveKey('max_completion_tokens');
});

test('openai provider retries once without a sampling param the model rejects with 400', function (string $param): void {
    $history = [];
    $provider = new OpenAiCompatibleHttpAgenticAiProvider(guzzleClient([
        openAiErrorResponse(400, 'unsupported_value', $param),
        openAiOkResponse(),
    ], $history), 'openai');

    $response = $provider->createCompletion([
        ...sampleProviderPayload(),
        'model' => 'gpt-5.4-mini',
        'temperature' => 0.2,
        'top_p' => 0.7,
    ]);

    $first = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $second = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $kept = $param === 'temperature' ? 'top_p' : 'temperature';

    expect($history)->toHaveCount(2)
        ->and($provider->getContent($response))->toBe('{"ok":true}')
        ->and($first)->toHaveKeys(['temperature', 'top_p'])
        ->and($second)->not->toHaveKey($param)
        ->and($second)->toHaveKey($kept)
        ->and($second['model'])->toBe('gpt-5.4-mini')
        ->and($second['messages'])->toBe($first['messages']);
})->with(['temperature', 'top_p']);

test('a repeated sampling param rejection is not retried a second time', function (): void {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'AI provider completion failed'
            && ($context['status_code'] ?? null) === 400
            && ($context['provider_error']['param'] ?? null) === 'temperature'
            && isset($context['attempts']));

    $history = [];
    $provider = new OpenAiCompatibleHttpAgenticAiProvider(guzzleClient([
        openAiErrorResponse(400, 'unsupported_value', 'temperature'),
        openAiErrorResponse(400, 'unsupported_value', 'temperature'),
        openAiOkResponse(),
    ], $history), 'openai');

    expect($provider->createCompletion([
        ...sampleProviderPayload(),
        'model' => 'gpt-5.4-mini',
        'temperature' => 0.2,
        'request_max_attempts' => 3,
    ]))->toBe([])
        ->and($history)->toHaveCount(2);
});

test('other 400 errors and sampling errors without a sent param are not retried', function (string $param, array $extra): void {
    Log::shouldReceive('error')->once();

    $history = [];
    $provider = new OpenAiCompatibleHttpAgenticAiProvider(guzzleClient([
        openAiErrorResponse(400, 'invalid_request_error', $param),
        openAiOkResponse(),
    ], $history), 'openai');

    expect($provider->createCompletion([
        ...sampleProviderPayload(),
        'model' => 'gpt-5.4-mini',
        'request_max_attempts' => 3,
        ...$extra,
    ]))->toBe([])
        ->and($history)->toHaveCount(1);
})->with([
    'unrelated param' => ['response_format', ['temperature' => 0.2]],
    'sampling param that was never sent' => ['temperature', []],
]);

test('openai compatible provider retries transient failures up to request_max_attempts', function (Response|ConnectException $transient): void {
    $history = [];
    $provider = new GeminiAgenticAiProvider(guzzleClient([$transient, openAiOkResponse()], $history));

    $response = $provider->createCompletion([...sampleProviderPayload(), 'request_max_attempts' => 2]);

    expect($history)->toHaveCount(2)
        ->and($provider->getContent($response))->toBe('{"ok":true}');
})->with([
    'connection error' => fn () => new ConnectException('connection reset', new Request('POST', 'chat/completions')),
    'rate limited' => fn () => new Response(429, [], '{}'),
    'server error' => fn () => new Response(503, [], '{}'),
]);

test('openai compatible provider logs the attempt count once retries are exhausted', function (): void {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'AI provider completion failed'
            && ($context['status_code'] ?? null) === 500
            && ($context['attempts'] ?? null) === 2);

    $history = [];
    $provider = new GeminiAgenticAiProvider(guzzleClient([
        new Response(500, [], '{}'),
        new Response(500, [], '{}'),
        openAiOkResponse(),
    ], $history));

    expect($provider->createCompletion([...sampleProviderPayload(), 'request_max_attempts' => 2]))->toBe([])
        ->and($history)->toHaveCount(2);
});

test('openai compatible provider makes a single attempt by default and never retries client errors', function (int $status, array $extra): void {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => ($context['status_code'] ?? null) === $status
            && ($context['attempts'] ?? null) === 1);

    $history = [];
    $provider = new GeminiAgenticAiProvider(guzzleClient([
        new Response($status, [], '{}'),
        openAiOkResponse(),
    ], $history));

    expect($provider->createCompletion([...sampleProviderPayload(), ...$extra]))->toBe([])
        ->and($history)->toHaveCount(1);
})->with([
    'server error without request_max_attempts' => [503, []],
    'client error with retries allowed' => [401, ['request_max_attempts' => 3]],
]);

test('anthropic provider maps chat payload to messages api output config', function (): void {
    $history = [];
    $provider = new AnthropicAgenticAiProvider(guzzleClient([
        new Response(200, [], json_encode([
            'content' => [
                ['type' => 'text', 'text' => '{"ok":true}'],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
        ], JSON_THROW_ON_ERROR)),
    ], $history));

    $response = $provider->createCompletion(sampleProviderPayload());

    expect($provider->getContent($response))->toBe('{"ok":true}');

    $request = $history[0]['request'];
    $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect((string) $request->getUri())->toContain('/v1/messages')
        ->and($request->getHeaderLine('anthropic-version'))->not->toBe('')
        ->and($body['system'])->toContain('Return JSON')
        ->and($body['messages'][0]['role'])->toBe('user')
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['output_config']['format']['schema']['required'])->toBe(['ok']);
});

test('anthropic provider drops temperature for models that reject sampling and sends the configured effort', function (): void {
    config()->set('core.ai_structured_extraction.providers.anthropic.effort', 'low');
    $history = [];
    $provider = new AnthropicAgenticAiProvider(guzzleClient([
        new Response(200, [], json_encode(['content' => [['type' => 'text', 'text' => '{"ok":true}']]], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['content' => [['type' => 'text', 'text' => '{"ok":true}']]], JSON_THROW_ON_ERROR)),
    ], $history));

    $provider->createCompletion([...sampleProviderPayload(), 'model' => 'claude-opus-5-5', 'temperature' => 0.0]);
    $provider->createCompletion([...sampleProviderPayload(), 'model' => 'claude-sonnet-4-6', 'temperature' => 0.0]);

    $current = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $legacy = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($current)->not->toHaveKey('temperature')
        ->and($current['output_config']['effort'])->toBe('low')
        ->and($current['output_config']['format']['type'])->toBe('json_schema')
        ->and($legacy['temperature'])->toBe(0)
        ->and(AnthropicAgenticAiProvider::acceptsSamplingParams('claude-haiku-5-5'))->toBeFalse()
        ->and(AnthropicAgenticAiProvider::acceptsSamplingParams('claude-opus-4-8'))->toBeFalse()
        ->and(AnthropicAgenticAiProvider::acceptsSamplingParams('claude-haiku-4-5'))->toBeTrue();
});

test('anthropic provider strips schema constraints structured outputs reject but keeps property names', function (): void {
    $schema = AnthropicAgenticAiProvider::structuredOutputSchema([
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['maxItems', 'urls', 'score'],
        'properties' => [
            'maxItems' => ['type' => 'string', 'maxLength' => 20],
            'urls' => ['type' => 'array', 'maxItems' => 12, 'minItems' => 3, 'items' => ['type' => 'string']],
            'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
        ],
    ]);

    expect($schema['properties'])->toHaveKeys(['maxItems', 'urls', 'score'])
        ->and($schema['properties']['maxItems'])->toBe(['type' => 'string'])
        ->and($schema['properties']['urls'])->toBe(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($schema['properties']['score'])->toBe(['type' => 'number'])
        ->and($schema['required'])->toBe(['maxItems', 'urls', 'score']);
});

test('anthropic provider returns the result through a tool when the schema has more than 16 union parameters', function (): void {
    $properties = [];
    for ($i = 1; $i <= 17; $i++) {
        $properties['field_' . $i] = ['type' => ['string', 'null']];
    }
    $payload = sampleProviderPayload();
    $payload['response_format']['json_schema']['schema'] = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => array_keys($properties),
        'properties' => $properties,
    ];
    $history = [];
    $provider = new AnthropicAgenticAiProvider(guzzleClient([
        new Response(200, [], json_encode(['stop_reason' => 'tool_use', 'content' => [
            ['type' => 'thinking', 'thinking' => ''],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'submit_extraction', 'input' => ['field_1' => 'Thiên Hoa', 'field_2' => null]],
        ]], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['stop_reason' => 'end_turn', 'content' => [
            ['type' => 'text', 'text' => "```json\n{\"field_1\":null}\n```"],
        ]], JSON_THROW_ON_ERROR)),
    ], $history));

    $response = $provider->createCompletion($payload);
    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $textFallback = $provider->createCompletion($payload);

    expect(AnthropicAgenticAiProvider::unionParameterCount($payload['response_format']['json_schema']['schema']))->toBe(17)
        ->and($body['output_config']['format'] ?? null)->toBeNull()
        ->and($body['tools'][0]['name'])->toBe('submit_extraction')
        ->and($body['tools'][0]['input_schema']['properties'])->toHaveKey('field_17')
        ->and($body['tool_choice'])->toBe(['type' => 'auto'])
        ->and($body['system'])->toContain('submit_extraction')
        ->and($provider->getContent($response))->toBe('{"field_1":"Thiên Hoa","field_2":null}')
        ->and($provider->getContent($textFallback))->toBe('{"field_1":null}');
});

function guzzleClient(array $responses, array &$history): Client
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new Client([
        'base_uri' => 'https://provider.test/',
        'handler' => $stack,
    ]);
}

function openAiOkResponse(): Response
{
    return new Response(200, [], json_encode([
        'choices' => [['message' => ['content' => '{"ok":true}']]],
    ], JSON_THROW_ON_ERROR));
}

function openAiErrorResponse(int $status, string $code, string $param): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode(['error' => [
        'message' => 'Rejected by the provider.',
        'type' => 'invalid_request_error',
        'param' => $param,
        'code' => $code,
    ]], JSON_THROW_ON_ERROR));
}

function sampleProviderPayload(): array
{
    return [
        'model' => 'provider-model',
        'messages' => [
            ['role' => 'system', 'content' => 'Return JSON.'],
            ['role' => 'user', 'content' => 'Extract yes/no.'],
        ],
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'sample_schema',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['ok'],
                    'properties' => [
                        'ok' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ],
        'request_timeout_seconds' => 12,
        'request_connect_timeout_seconds' => 3,
    ];
}
