<?php

declare(strict_types=1);

use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Services\Ai\AiTaskCatalog;
use Cms\Core\Services\Ai\Usage\AiCallLog;
use Cms\Core\Services\Ai\Usage\AiGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery as m;

/*
 * a task whose prompt is private keeps no prompt hash (or anything else derived from the prompt) in the
 * per-call log; its usage metadata (provider, model, tokens, cost, latency, outcome) stays. Private is a task
 * property declared in module config (`<module>.ai_runtime_private_prompt_tasks`, Core:
 * `core.ai_usage.ai_runtime_private_prompt_tasks`), never a task name hard-coded in the gateway.
 */

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
    DB::table('core_ai_usage_daily')->delete();
    DB::table('core_ai_call_logs')->delete();
    Cache::flush();
    config()->set('core.ai_usage.pricing', ['m-a' => ['input_per_1m_usd' => 1.0, 'cached_input_per_1m_usd' => 0.1, 'output_per_1m_usd' => 2.0]]);
    config()->set('core.ai_usage.ai_runtime_private_prompt_tasks', ['core_private_probe']);
});

afterEach(function (): void {
    m::close();
});

function privatePromptPayload(): array
{
    return ['model' => 'm-a', 'messages' => [['role' => 'user', 'content' => 'PRIVATE-QUESTION-CANARY']]];
}

function privatePromptProvider(int $calls = 1): AgenticAiProviderInterface
{
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->times($calls)
        ->andReturn(['choices' => [], 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500]]);

    return $provider;
}

it('declares the content answer task private in the Content module config', function (): void {
    $catalog = new AiTaskCatalog();

    expect($catalog->isPromptPrivate('content_agent_answer'))->toBeTrue()
        ->and($catalog->isPromptPrivate('core_private_probe'))->toBeTrue()
        ->and($catalog->isPromptPrivate('content_post_translate_title_desc'))->toBeFalse()
        ->and($catalog->isPromptPrivate('unknown_task'))->toBeFalse();
});

it('keeps usage metadata but no prompt hash for a private task', function (string $task): void {
    app(AiGateway::class)->complete(privatePromptProvider(), 'openai', $task, privatePromptPayload());

    $row = DB::table('core_ai_call_logs')->first();
    $json = json_encode((array) $row);

    expect($row->task_key)->toBe($task)
        ->and($row->prompt_hash)->toBeNull()
        ->and((int) $row->input_tokens)->toBe(1000)
        ->and((int) $row->output_tokens)->toBe(500)
        ->and((float) $row->cost_usd)->toBe(0.002)
        ->and((bool) $row->failed)->toBeFalse()
        ->and($json)->not->toContain('PRIVATE-QUESTION-CANARY')
        ->and($json)->not->toContain((string) AiCallLog::promptHash(privatePromptPayload()));
})->with(['content_agent_answer', 'core_private_probe']);

it('keeps no prompt hash for a private task on failure and through meter()', function (): void {
    $failing = m::mock(AgenticAiProviderInterface::class);
    $failing->shouldReceive('createCompletion')->once()->andThrow(new RuntimeException('boom'));
    expect(fn () => app(AiGateway::class)->complete($failing, 'openai', 'content_agent_answer', privatePromptPayload()))
        ->toThrow(RuntimeException::class);
    app(AiGateway::class)->meter('openai', 'm-a', 'content_agent_answer', static fn (): array => ['usage' => ['prompt_tokens' => 1]], privatePromptPayload());

    $hashes = DB::table('core_ai_call_logs')->orderBy('id')->pluck('prompt_hash')->all();

    expect($hashes)->toBe([null, null]);
});

it('still hashes the prompt of every other task', function (): void {
    app(AiGateway::class)->complete(privatePromptProvider(), 'openai', 'content_post_translate_title_desc', privatePromptPayload());

    expect(DB::table('core_ai_call_logs')->value('prompt_hash'))->toBe(AiCallLog::promptHash(privatePromptPayload()));
});
