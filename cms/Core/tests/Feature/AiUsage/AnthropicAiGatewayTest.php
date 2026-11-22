<?php

declare(strict_types=1);

use Cms\Core\Services\Agentic\AnthropicAgenticAiProvider;
use Cms\Core\Services\Ai\Usage\AiGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
    DB::table('core_ai_usage_daily')->delete();
    Cache::flush();
    config()->set('core.ai_structured_extraction.providers.anthropic.api_key', 'test-key');
    config()->set('core.ai_structured_extraction.providers.anthropic.model', 'claude-sonnet-5-5');
    config()->set('core.ai_structured_extraction.providers.anthropic.model_map', [
        '/(?<![a-z])(mini|nano|luna|lite|flash)(?![a-z])/i' => 'claude-haiku-5-5',
        '/./' => 'claude-sonnet-5-5',
    ]);
});

/**
 * @param list<array<string, mixed>> $bodies Messages API bodies, one per expected request.
 * @param list<array<string, mixed>> $history Filled with the sent requests.
 */
function anthropicGatewayProvider(array $bodies, array &$history): AnthropicAgenticAiProvider
{
    $stack = HandlerStack::create(new MockHandler(array_map(
        static fn (array $body): Response => new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR)),
        $bodies,
    )));
    $stack->push(Middleware::history($history));

    return new AnthropicAgenticAiProvider(new Client(['base_uri' => 'https://anthropic.test/', 'handler' => $stack]));
}

function anthropicGatewayBody(string $model, array $overrides = []): array
{
    return [
        'model' => $model,
        'content' => [['type' => 'text', 'text' => '{"ok":true}']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ...$overrides,
    ];
}

it('runs the fallback model when Anthropic refuses, truncates or answers without text', function (array $failure): void {
    $history = [];
    $provider = anthropicGatewayProvider([
        anthropicGatewayBody('claude-haiku-5-5', $failure),
        anthropicGatewayBody('claude-sonnet-5-5'),
    ], $history);

    $result = app(AiGateway::class)->complete(
        $provider,
        'anthropic',
        'task1',
        ['model' => 'gpt-4o-mini', 'messages' => [['role' => 'user', 'content' => 'hi']]],
        ['gpt-4o'],
    );

    $sent = array_map(static fn (array $entry): string => json_decode((string) $entry['request']->getBody(), true)['model'], $history);

    expect($sent)->toBe(['claude-haiku-5-5', 'claude-sonnet-5-5'])
        ->and($result->fallbackUsed)->toBeTrue()
        ->and($result->attempts)->toBe(2)
        // The answering Claude model is reported, not the OpenAI name the caller asked for.
        ->and($result->model)->toBe('claude-sonnet-5-5')
        ->and((int) DB::table('core_ai_usage_daily')->where('model', 'claude-haiku-5-5')->value('failed_count'))->toBe(1)
        ->and((int) DB::table('core_ai_usage_daily')->where('model', 'claude-sonnet-5-5')->value('failed_count'))->toBe(0)
        // Priced on the real model: 1000 in / 500 out at 2 / 10 USD per 1M tokens.
        ->and((float) DB::table('core_ai_usage_daily')->where('model', 'claude-sonnet-5-5')->value('estimated_cost_usd'))->toBe(0.007);
})->with([
    'refusal' => [['stop_reason' => 'refusal', 'content' => []]],
    'max_tokens' => [['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"ok"']]]],
    'no text' => [['content' => [['type' => 'thinking', 'thinking' => 'only thoughts']]]],
]);

it('does not call a fallback that maps to the Claude model it already tried, and returns the failed response', function (): void {
    $history = [];
    $provider = anthropicGatewayProvider([
        anthropicGatewayBody('claude-haiku-5-5', ['stop_reason' => 'refusal', 'content' => []]),
        anthropicGatewayBody('claude-haiku-5-5'),
    ], $history);

    $result = app(AiGateway::class)->complete(
        $provider,
        'anthropic',
        'task1',
        ['model' => 'gpt-4o-mini', 'messages' => [['role' => 'user', 'content' => 'hi']]],
        ['gpt-5.6-luna'],
    );

    expect($history)->toHaveCount(1)
        ->and($result->attempts)->toBe(1)
        ->and($result->fallbackUsed)->toBeFalse()
        ->and($result->response['stop_reason'])->toBe('refusal');
});
