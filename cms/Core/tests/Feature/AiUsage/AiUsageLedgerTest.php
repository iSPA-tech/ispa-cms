<?php

declare(strict_types=1);

use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\Usage\AiBudgetGuard;
use Cms\Core\Services\Ai\Usage\AiGateway;
use Cms\Core\Services\Ai\Usage\AiUsageLedger;
use Cms\Core\Services\Ai\Usage\AiUsageTokens;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery as m;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
    DB::table('core_ai_usage_daily')->delete();
    Cache::flush();
    config()->set('core.ai_usage.pricing', ['m-a' => ['input_per_1m_usd' => 1.0, 'cached_input_per_1m_usd' => 0.1, 'output_per_1m_usd' => 2.0]]);
});

afterEach(function (): void {
    m::close();
});

function aiUsageSetting(string $key, string $value): void
{
    Setting::query()->create([
        'setting_key' => $key, 'type' => 'text', 'group_name' => 'core.ai_runtime',
        'name' => mb_substr($key, -50),
        'input_setting' => ['title' => $key, 'description' => 'test', 'isPublic' => true, 'isEnv' => false],
        'value' => $value,
    ]);
    app(AiRuntimeConfigResolver::class)->flushCache();
}

function aiUsageOk(int $in = 1000, int $out = 500): array
{
    return ['choices' => [], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out]];
}

it('normalizes OpenAI, Anthropic and missing usage', function (): void {
    $o = AiUsageTokens::fromResponse(['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 7, 'prompt_tokens_details' => ['cached_tokens' => 40]]]);
    expect([$o->input, $o->cached, $o->output])->toBe([60, 40, 7]);

    $a = AiUsageTokens::fromResponse(['usage' => ['input_tokens' => 10, 'cache_creation_input_tokens' => 5, 'cache_read_input_tokens' => 20, 'output_tokens' => 3]]);
    expect([$a->input, $a->cached, $a->output])->toBe([15, 20, 3]);

    $z = AiUsageTokens::fromResponse([]);
    expect([$z->input, $z->cached, $z->output])->toBe([0, 0, 0]);
});

it('estimates cost with cached tokens, settings override and unpriced models', function (): void {
    $ledger = new AiUsageLedger();
    $tokens = new AiUsageTokens(1_000_000, 1_000_000, 1_000_000);
    expect($ledger->estimateCostUsd('m-a', $tokens))->toBe(3.1)
        ->and($ledger->estimateCostUsd('unknown-model', $tokens))->toBe(0.0)
        ->and($ledger->price('unknown-model'))->toBeNull();

    aiUsageSetting('core.ai_runtime.pricing.m-a.input_per_1m_usd', '10');
    expect($ledger->estimateCostUsd('m-a', $tokens))->toBe(12.1);
});

it('prices model names that contain dots from config and settings', function (): void {
    config()->set('core.ai_usage.pricing', ['gpt-5.6-luna' => ['input_per_1m_usd' => 0.2, 'cached_input_per_1m_usd' => 0.02, 'output_per_1m_usd' => 1.2]]);
    $ledger = new AiUsageLedger();
    $tokens = new AiUsageTokens(1_000_000, 1_000_000, 1_000_000);

    expect($ledger->price('gpt-5.6-luna'))->not->toBeNull()
        ->and($ledger->estimateCostUsd('gpt-5.6-luna', $tokens))->toBe(1.42);

    aiUsageSetting('core.ai_runtime.pricing.grok-4.3.input_per_1m_usd', '1.25');
    aiUsageSetting('core.ai_runtime.pricing.grok-4.3.output_per_1m_usd', '2.5');
    expect($ledger->estimateCostUsd('grok-4.3', $tokens))->toBe(5.0);
});

it('sums counters per day row and splits by task', function (): void {
    $ledger = new AiUsageLedger();
    $ledger->record('openai', 'm-a', 't1', aiUsageOk(), false, false, 10);
    $ledger->record('openai', 'm-a', 't1', [], true, true, 5);
    $ledger->record('openai', 'm-a', 't2', aiUsageOk(), false, false, 1);

    expect(DB::table('core_ai_usage_daily')->count())->toBe(2);
    $row = DB::table('core_ai_usage_daily')->where('task_key', 't1')->first();
    expect([(int) $row->request_count, (int) $row->failed_count, (int) $row->fallback_count, (int) $row->input_tokens, (int) $row->latency_ms_total])
        ->toBe([2, 1, 1, 1000, 15]);

    $summary = $ledger->summary();
    expect($summary['request_count'])->toBe(3)
        ->and($summary['by_task'])->toHaveCount(2)
        ->and($summary['by_model'][0]['priced'])->toBeTrue()
        ->and($summary['remaining_usd'])->toBeNull();
});

it('guards the budget: no cap, kill switch, cap reached, ledger failure', function (): void {
    $guard = app(AiBudgetGuard::class);
    app(AiUsageLedger::class)->record('openai', 'm-a', 't1', aiUsageOk(1_000_000, 0), false, false, 1);
    expect($guard->check()->allowed)->toBeTrue();

    aiUsageSetting('core.ai_runtime.budget.monthly_usd', '5');
    expect($guard->check()->allowed)->toBeTrue();

    app(AiRuntimeConfigResolver::class)->flushCache();
    Setting::query()->where('setting_key', 'core.ai_runtime.budget.monthly_usd')->update(['value' => '1']);
    app(AiRuntimeConfigResolver::class)->flushCache();
    $d = $guard->check();
    expect($d->allowed)->toBeFalse()->and($d->reason)->toBe('budget_exceeded');

    aiUsageSetting('core.ai_runtime.budget.kill_switch', '1');
    expect($guard->check()->reason)->toBe('kill_switch');

    Illuminate\Support\Facades\Schema::drop('core_ai_usage_daily');
    app(AiRuntimeConfigResolver::class)->flushCache();
    Setting::query()->where('setting_key', 'core.ai_runtime.budget.kill_switch')->delete();
    app(AiRuntimeConfigResolver::class)->flushCache();
    expect($guard->check()->allowed)->toBeTrue();
});

it('falls back to the next model and records both attempts', function (): void {
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->once()->with(['model' => 'm-a'])->andReturn([]);
    $provider->shouldReceive('createCompletion')->once()->with(['model' => 'm-b'])->andReturn(aiUsageOk());

    $result = app(AiGateway::class)->complete($provider, 'openai', 'task1', ['model' => 'm-a'], ['m-a', 'm-b']);

    expect($result->model)->toBe('m-b')->and($result->fallbackUsed)->toBeTrue()->and($result->attempts)->toBe(2);
    expect((int) DB::table('core_ai_usage_daily')->where('model', 'm-a')->value('failed_count'))->toBe(1);
    expect((int) DB::table('core_ai_usage_daily')->where('model', 'm-b')->value('fallback_count'))->toBe(1);
});

it('does not call the fallback when the primary succeeds', function (): void {
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->once()->andReturn(aiUsageOk());

    $result = app(AiGateway::class)->complete($provider, 'openai', 'task1', ['model' => 'm-a'], ['m-b']);

    expect($result->fallbackUsed)->toBeFalse()->and($result->model)->toBe('m-a');
});

it('refuses without calling the provider when the kill switch is on', function (): void {
    aiUsageSetting('core.ai_runtime.budget.kill_switch', '1');
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->never();

    $result = app(AiGateway::class)->complete($provider, 'openai', 'task1', ['model' => 'm-a']);

    expect($result->response)->toBe([])->and($result->refusedReason)->toBe('kill_switch');
    expect((int) DB::table('core_ai_usage_daily')->value('refused_count'))->toBe(1);
});

it('sees a kill switch saved by another process without flushing the singleton resolver', function (): void {
    $guard = app(AiBudgetGuard::class);
    expect($guard->check()->allowed)->toBeTrue();

    // Written straight to the table (no flushCache), as the admin web process does for a long-running queue worker.
    Setting::query()->create([
        'setting_key' => 'core.ai_runtime.budget.kill_switch', 'type' => 'text', 'group_name' => 'core.ai_runtime',
        'name' => 'kill_switch',
        'input_setting' => ['title' => 'kill_switch', 'description' => 'test', 'isPublic' => true, 'isEnv' => false],
        'value' => '1',
    ]);

    expect($guard->check()->reason)->toBe('kill_switch');
});

it('sends threshold alerts once per month', function (): void {
    aiUsageSetting('core.ai_runtime.budget.monthly_usd', '1');
    app(AiUsageLedger::class)->record('openai', 'm-a', 't1', aiUsageOk(1_000_000, 0), false, false, 1);

    CMSLogger::shouldReceive('warning')->once();
    CMSLogger::shouldReceive('error')->once();

    $guard = app(AiBudgetGuard::class);
    $guard->alertIfThresholdCrossed();
    $guard->alertIfThresholdCrossed();
});

it('lists ledger-metered tasks in the admin task matrix', function (): void {
    app(AiUsageLedger::class)->record('openai', 'm-a', 'sampletool_final_content', aiUsageOk(), false, false, 10);
    app(AiUsageLedger::class)->record('openai', 'm-a', 'sampletool_final_content', aiUsageOk(), false, false, 10);
    app(AiUsageLedger::class)->record('openai', 'm-a', '', aiUsageOk(), false, false, 10);

    $tasks = collect(app(Cms\Core\Services\Ai\AiRuntimeSettingsService::class)->overview()['tasks']);

    expect($tasks->firstWhere('task', 'sampletool_final_content')['runs'] ?? null)->toBe(2)
        ->and($tasks->firstWhere('task', 'unattributed'))->toBeNull();
});

it('exposes fallback models, budget and usage through the admin API', function (): void {
    $user = new App\Models\User();
    $user->forceFill(['id' => 1, 'uuid' => '00000000-0000-4000-8000-000000000002', 'name' => 'x', 'email' => 'x@example.test', 'type' => 0, 'level' => 0]);
    $user->exists = true;
    Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
    $this->withoutMiddleware([
        App\Http\Middleware\ApiLocalization::class, App\Http\Middleware\HttpsProtocol::class,
        App\Http\Middleware\Localization::class, Illuminate\Routing\Middleware\ThrottleRequests::class,
    ]);

    $this->putJson('/api/v2/admin/ai-runtime/tasks', ['tasks' => [['task' => 'demo', 'fallback_models' => 'a, b,a,c,d']]])
        ->assertOk()->assertJsonPath('data.tasks.0.fallback_models', 'a,b,c');
    $this->putJson('/api/v2/admin/ai-runtime/budget', ['monthly_usd' => 12.5, 'kill_switch' => true, 'warning_percent' => 70])
        ->assertOk()->assertJsonPath('data.budget.monthly_usd', 12.5)->assertJsonPath('data.budget.warning_percent', 70);
    $this->getJson('/api/v2/admin/ai-runtime/usage?month=2026-10')
        ->assertOk()->assertJsonPath('data.month', '2026-10')->assertJsonPath('data.kill_switch', true);
});

it('meters a direct call: row via=direct with tokens', function (): void {
    $result = app(AiGateway::class)->meter('openai', 'm-a', 'direct_task', static fn (): array => aiUsageOk(1000, 500));

    $row = DB::table('core_ai_usage_daily')->where('task_key', 'direct_task')->first();
    expect($result->response)->not->toBe([])->and($result->refusedReason)->toBeNull()
        ->and($row->via)->toBe('direct')
        ->and((int) $row->request_count)->toBe(1)->and((int) $row->input_tokens)->toBe(1000)->and((int) $row->output_tokens)->toBe(500);
});

it('refuses a direct call without invoking the closure', function (): void {
    aiUsageSetting('core.ai_runtime.budget.kill_switch', '1');
    $called = false;

    $result = app(AiGateway::class)->meter('openai', 'm-a', 'direct_task', function () use (&$called): array {
        $called = true;

        return aiUsageOk();
    });

    $row = DB::table('core_ai_usage_daily')->where('task_key', 'direct_task')->first();
    expect($called)->toBeFalse()->and($result->response)->toBe([])->and($result->refusedReason)->toBe('kill_switch')
        ->and($row->via)->toBe('direct')->and((int) $row->refused_count)->toBe(1);
});

it('records a failed direct call and rethrows when the closure throws', function (): void {
    expect(fn () => app(AiGateway::class)->meter('openai', 'm-a', 'direct_task', static function (): array {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    $row = DB::table('core_ai_usage_daily')->where('task_key', 'direct_task')->first();
    expect((int) $row->failed_count)->toBe(1)->and($row->via)->toBe('direct');
});

it('prefers the provider-reported cost over the price table', function (): void {
    $response = ['usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 0, 'cost_usd' => 0.0123456789]];
    app(AiGateway::class)->meter('typesafe', 'm-a', 'direct_task', static fn (): array => $response);

    expect((float) DB::table('core_ai_usage_daily')->value('estimated_cost_usd'))->toBe(0.012346);
});

it('normalizes the OpenAI Responses API usage shape', function (): void {
    $t = AiUsageTokens::fromResponse(['usage' => ['input_tokens' => 100, 'input_tokens_details' => ['cached_tokens' => 30], 'output_tokens' => 9]]);

    expect([$t->input, $t->cached, $t->output])->toBe([70, 30, 9]);
});

it('lists direct tasks in the Tasks matrix as read-only next to runtime ones', function (): void {
    $ledger = app(AiUsageLedger::class);
    $ledger->record('openai', 'm-a', 'runtime_task', aiUsageOk(), false, false, 10);
    $ledger->record('openai', 'm-a', 'direct_task', aiUsageOk(), false, false, 10, AiUsageLedger::VIA_DIRECT);

    $tasks = collect(app(Cms\Core\Services\Ai\AiRuntimeSettingsService::class)->overview()['tasks']);

    expect($tasks->firstWhere('task', 'runtime_task')['configurable'])->toBeTrue()
        ->and($tasks->firstWhere('task', 'direct_task')['configurable'])->toBeFalse()
        ->and($tasks->firstWhere('task', 'direct_task')['runs'])->toBe(1)
        ->and(app(AiUsageLedger::class)->summary()['by_task'])->toHaveCount(2);
});

it('meters the OpenAI embedding provider as a direct embeddings call with input cost', function (): void {
    config()->set('core.ai_usage.pricing', (require base_path('cms/Core/config/ai_usage.php'))['pricing']);
    $client = m::mock(\App\Clients\OpenAIClient::class);
    $client->shouldReceive('createEmbeddingResponse')->once()->with('hello', 'text-embedding-3-small')->andReturn([
        'data' => [['embedding' => [0.1, 0.2]]],
        'usage' => ['prompt_tokens' => 1000, 'total_tokens' => 1000],
    ]);

    $provider = new \Cms\Core\Services\Agentic\OpenAiAgenticEmbeddingProvider($client, app(AiGateway::class));

    expect($provider->createEmbedding('hello'))->toBe([0.1, 0.2]);
    $row = DB::table('core_ai_usage_daily')->where('task_key', 'embeddings')->first();
    expect($row)->not->toBeNull()
        ->and($row->via)->toBe('direct')
        ->and((int) $row->input_tokens)->toBe(1000)
        ->and((float) $row->estimated_cost_usd)->toBe(0.00002);
});

it('shows the admin provider model as effective for a provider-only task row', function (): void {
    aiUsageSetting('core.ai_runtime.tasks.prov_only_task.provider', 'gemini');
    aiUsageSetting('core.ai_runtime.providers.gemini.model', 'gemini-admin-model');

    $row = collect(app(Cms\Core\Services\Ai\AiRuntimeSettingsService::class)->overview()['tasks'])->firstWhere('task', 'prov_only_task');

    expect($row['effective'])->toBe(['provider' => 'gemini', 'model' => 'gemini-admin-model', 'source' => 'task']);
});
