<?php

use Cms\Core\Services\Agentic\OpenAiModelParameters;

test('reasoning-only models reject non-default sampling params', function (string $model, bool $rejects): void {
    expect(OpenAiModelParameters::rejectsSamplingParams($model))->toBe($rejects);
})->with([
    'gpt-5.6 family' => ['gpt-5.6-luna', true],
    'gpt-5.6 mini' => ['gpt-5.6-mini', true],
    'gpt-6 family' => ['gpt-6', true],
    'gpt-6 variant' => ['gpt-6-turbo', true],
    'o1' => ['o1', true],
    'o3-mini' => ['o3-mini', true],
    'o4-mini' => ['o4-mini', true],
    'gpt-5' => ['gpt-5', true],
    'gpt-5-mini' => ['gpt-5-mini', true],
    'gpt-5-nano' => ['gpt-5-nano', true],
    'gpt-5-pro' => ['gpt-5-pro', true],
    'dated gpt-5' => ['gpt-5-2025-08-07', true],
    'dated gpt-5-mini' => ['gpt-5-mini-2025-08-07', true],
    'mixed case with spaces' => ['  GPT-5.6-Luna ', true],
    'gpt-5.4' => ['gpt-5.4', false],
    'gpt-5.4-mini' => ['gpt-5.4-mini', false],
    'gpt-5.5' => ['gpt-5.5', false],
    'gpt-5-chat-latest' => ['gpt-5-chat-latest', false],
    'gpt-4o-mini' => ['gpt-4o-mini', false],
    'gpt-4.1-mini' => ['gpt-4.1-mini', false],
    'empty model' => ['', false],
    'non-openai model' => ['gemini-2.5-flash', false],
]);

test('gpt-5, gpt-6 and o-series models use max_completion_tokens', function (string $model, bool $uses): void {
    expect(OpenAiModelParameters::usesMaxCompletionTokens($model))->toBe($uses);
})->with([
    ['gpt-5.6-luna', true],
    ['gpt-5.4-mini', true],
    ['gpt-5', true],
    ['gpt-6', true],
    ['o1', true],
    ['o3-mini', true],
    ['GPT-5.4-Mini', true],
    ['gpt-4o-mini', false],
    ['gpt-4.1-mini', false],
    ['', false],
]);

test('normalize drops sampling params and renames max_tokens for a reasoning-only model', function (): void {
    $payload = OpenAiModelParameters::normalize([
        'model' => 'gpt-5.6-luna',
        'messages' => [['role' => 'user', 'content' => 'hi']],
        'temperature' => 0.0,
        'top_p' => 1,
        'max_tokens' => 800,
        'reasoning_effort' => 'medium',
    ]);

    expect($payload)->toBe([
        'model' => 'gpt-5.6-luna',
        'messages' => [['role' => 'user', 'content' => 'hi']],
        'reasoning_effort' => 'medium',
        'max_completion_tokens' => 800,
    ]);
});

test('normalize keeps sampling params but still renames max_tokens for gpt-5.4', function (): void {
    expect(OpenAiModelParameters::normalize([
        'model' => 'gpt-5.4-mini',
        'temperature' => 0.3,
        'top_p' => 0.9,
        'max_tokens' => 800,
    ]))->toBe([
        'model' => 'gpt-5.4-mini',
        'temperature' => 0.3,
        'top_p' => 0.9,
        'max_completion_tokens' => 800,
    ]);
});

test('normalize leaves older models untouched', function (string $model): void {
    $payload = [
        'model' => $model,
        'temperature' => 0.3,
        'top_p' => 0.9,
        'max_tokens' => 800,
        'messages' => [],
    ];

    expect(OpenAiModelParameters::normalize($payload))->toBe($payload);
})->with(['gpt-4o-mini', 'gpt-4.1-mini']);

test('normalize does not overwrite an explicit max_completion_tokens', function (): void {
    expect(OpenAiModelParameters::normalize([
        'model' => 'gpt-5.6-luna',
        'max_tokens' => 800,
        'max_completion_tokens' => 1200,
    ]))->toBe([
        'model' => 'gpt-5.6-luna',
        'max_completion_tokens' => 1200,
    ]);
});

test('normalize removes an empty reasoning_effort on every model and keeps a real one', function (string $model): void {
    expect(OpenAiModelParameters::normalize(['model' => $model, 'reasoning_effort' => '']))->not->toHaveKey('reasoning_effort')
        ->and(OpenAiModelParameters::normalize(['model' => $model, 'reasoning_effort' => '  ']))->not->toHaveKey('reasoning_effort')
        ->and(OpenAiModelParameters::normalize(['model' => $model, 'reasoning_effort' => 'xhigh'])['reasoning_effort'])->toBe('xhigh');
})->with(['gpt-5.6-luna', 'gpt-4o-mini']);

test('normalize treats a payload without a model as a legacy model', function (): void {
    $payload = ['temperature' => 0.2, 'max_tokens' => 100];

    expect(OpenAiModelParameters::normalize($payload))->toBe($payload);
});
