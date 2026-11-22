<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Models\User;
use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\Usage\AiBudgetGuard;
use Cms\Core\Services\Ai\Usage\AiCallLog;
use Cms\Core\Services\Ai\Usage\AiGateway;
use Cms\Core\Services\Ai\Usage\AiUsageLedger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery as m;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
    DB::table('core_ai_usage_daily')->delete();
    DB::table('core_ai_call_logs')->delete();
    Cache::flush();
    Context::flush();
    config()->set('core.ai_usage.pricing', ['m-a' => ['input_per_1m_usd' => 1.0, 'cached_input_per_1m_usd' => 0.1, 'output_per_1m_usd' => 2.0]]);
});

afterEach(function (): void {
    m::close();
    Context::flush();
});

function callLogSetting(string $key, string $value): void
{
    Setting::query()->create([
        'setting_key' => $key, 'type' => 'text', 'group_name' => 'core.ai_runtime',
        'name' => mb_substr($key, -50),
        'input_setting' => ['title' => $key, 'description' => 'test', 'isPublic' => true, 'isEnv' => false],
        'value' => $value,
    ]);
    app(AiRuntimeConfigResolver::class)->flushCache();
}

function callLogOk(int $in = 1000, int $out = 500): array
{
    return ['choices' => [], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out]];
}

function callLogAuth(): void
{
    $user = new User();
    $user->forceFill(['id' => 1, 'uuid' => '00000000-0000-4000-8000-000000000003', 'name' => 'Call log admin', 'email' => 'calllog@example.test', 'type' => 0, 'level' => 0]);
    $user->exists = true;
    Sanctum::actingAs($user, ['*']);
}

class AiCallLogProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** @var array<string, mixed> */
    public static array $seen = [];

    public function handle(): void
    {
        app(AiCallLog::class)->record('openai', 'm-a', 'job_task', 'runtime', 1, false, false, 3, callLogOk());
        self::$seen = ['request_id' => Context::get('request_id')];
    }
}

it('logs a successful complete() call without storing content', function (): void {
    Context::add('request_id', 'rid-1');
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->once()->andReturn(callLogOk(1000, 500));

    app(AiGateway::class)->complete($provider, 'openai', 't1', ['model' => 'm-a', 'temperature' => 0.2, 'messages' => [['role' => 'user', 'content' => 'SECRET-PROMPT']]]);

    $row = DB::table('core_ai_call_logs')->first();
    expect($row->task_key)->toBe('t1')->and($row->model)->toBe('m-a')->and($row->via)->toBe('runtime')
        ->and((int) $row->attempt)->toBe(1)->and((bool) $row->is_fallback)->toBeFalse()->and((bool) $row->failed)->toBeFalse()
        ->and($row->refused_reason)->toBeNull()->and((int) $row->input_tokens)->toBe(1000)->and((int) $row->output_tokens)->toBe(500)
        ->and((float) $row->cost_usd)->toBe(0.002)->and($row->request_id)->toBe('rid-1')->and($row->origin)->toBe('console')
        ->and($row->prompt_hash)->toHaveLength(64);
    expect(json_encode((array) $row))->not->toContain('SECRET-PROMPT');
});

it('logs both attempts when falling back', function (): void {
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->once()->with(['model' => 'm-a'])->andReturn([]);
    $provider->shouldReceive('createCompletion')->once()->with(['model' => 'm-b'])->andReturn(callLogOk());

    app(AiGateway::class)->complete($provider, 'openai', 't1', ['model' => 'm-a'], ['m-b']);

    $rows = DB::table('core_ai_call_logs')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and([(int) $rows[0]->attempt, (bool) $rows[0]->is_fallback, (bool) $rows[0]->failed])->toBe([1, false, true])
        ->and([(int) $rows[1]->attempt, (bool) $rows[1]->is_fallback, (bool) $rows[1]->failed])->toBe([2, true, false]);
});

it('logs a thrown provider call as failed', function (): void {
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->once()->andThrow(new RuntimeException('boom'));

    expect(fn () => app(AiGateway::class)->complete($provider, 'openai', 't1', ['model' => 'm-a']))->toThrow(RuntimeException::class);
    expect((bool) DB::table('core_ai_call_logs')->value('failed'))->toBeTrue();
});

it('logs a budget refusal with its reason', function (): void {
    callLogSetting('core.ai_runtime.budget.kill_switch', '1');
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldNotReceive('createCompletion');

    app(AiGateway::class)->complete($provider, 'openai', 't1', ['model' => 'm-a']);
    app(AiGateway::class)->meter('openai', 'm-a', 't2', static fn (): array => callLogOk());

    $rows = DB::table('core_ai_call_logs')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)->and($rows[0]->refused_reason)->toBe('kill_switch')->and($rows[1]->via)->toBe('direct');
});

it('logs meter() calls with and without a hash payload', function (): void {
    app(AiGateway::class)->meter('openai', 'm-a', 'direct', static fn (): array => callLogOk());
    app(AiGateway::class)->meter('openai', 'm-a', 'direct', static fn (): array => callLogOk(), ['model' => 'm-a', 'input' => 'x']);

    $rows = DB::table('core_ai_call_logs')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)->and($rows[0]->via)->toBe('direct')->and($rows[0]->prompt_hash)->toBeNull()
        ->and($rows[1]->prompt_hash)->toBe(AiCallLog::promptHash(['input' => 'x']));
});

it('hashes only prompt parts in canonical key order', function (): void {
    $a = AiCallLog::promptHash(['model' => 'a', 'temperature' => 1, 'messages' => [['role' => 'user', 'content' => 'hi']], 'system' => 's']);
    $b = AiCallLog::promptHash(['system' => 's', 'model' => 'b', 'messages' => [['content' => 'hi', 'role' => 'user']]]);
    expect($a)->toBe($b)->and(AiCallLog::promptHash(['model' => 'a']))->toBeNull();
});

it('skips sampled-out successes but always keeps failures', function (): void {
    callLogSetting('core.ai_runtime.trace.sample_percent', '0');
    $log = app(AiCallLog::class);
    $log->record('openai', 'm-a', 't1', 'runtime', 1, false, false, 1, callLogOk());
    expect(DB::table('core_ai_call_logs')->count())->toBe(0);

    $log->record('openai', 'm-a', 't1', 'runtime', 1, false, true, 1, []);
    expect(DB::table('core_ai_call_logs')->count())->toBe(1);
});

it('never lets a logging failure break the gateway', function (): void {
    DB::statement('DROP TABLE core_ai_call_logs');
    $provider = m::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->once()->andReturn(callLogOk());

    $result = app(AiGateway::class)->complete($provider, 'openai', 't1', ['model' => 'm-a']);

    expect($result->response)->not->toBe([]);
    $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
});

it('assigns, reuses and rejects request ids in the middleware', function (): void {
    $next = static fn (Request $r) => response('ok');

    $resp = (new AssignRequestId())->handle(Request::create('/x'), $next);
    $generated = $resp->headers->get('X-Request-Id');
    expect($generated)->toMatch('/^[0-9a-f-]{36}$/')->and(Context::get('request_id'))->toBe($generated);

    $req = Request::create('/x');
    $req->headers->set('X-Request-Id', 'abc.DEF_1-2');
    expect((new AssignRequestId())->handle($req, $next)->headers->get('X-Request-Id'))->toBe('abc.DEF_1-2');

    foreach (['bad id!', str_repeat('a', 65)] as $bad) {
        $req = Request::create('/x');
        $req->headers->set('X-Request-Id', $bad);
        expect((new AssignRequestId())->handle($req, $next)->headers->get('X-Request-Id'))->not->toBe($bad);
    }
});

it('shares the request id with a dispatched job and marks its origin as queue', function (): void {
    config()->set('queue.default', 'sync');
    (new AssignRequestId())->handle(Request::create('/x'), static fn () => response('ok'));
    $id = Context::get('request_id');

    AiCallLogProbeJob::dispatch();

    $row = DB::table('core_ai_call_logs')->first();
    expect(AiCallLogProbeJob::$seen['request_id'])->toBe($id)->and($row->request_id)->toBe($id)->and($row->origin)->toBe('queue');

    app(AiCallLog::class)->record('openai', 'm-a', 'after', 'runtime', 1, false, false, 1, callLogOk());
    expect(DB::table('core_ai_call_logs')->where('task_key', 'after')->value('origin'))->toBe('http');
});

it('prunes only rows older than the retention window', function (): void {
    $row = static fn (string $task, string $when): array => [
        'called_at' => $when, 'task_key' => $task, 'provider' => 'openai', 'model' => 'm-a', 'via' => 'runtime',
        'attempt' => 1, 'origin' => 'console',
    ];
    DB::table('core_ai_call_logs')->insert([
        $row('old', now('UTC')->subDays(20)->format('Y-m-d H:i:s')),
        $row('new', now('UTC')->subDays(2)->format('Y-m-d H:i:s')),
    ]);

    $this->artisan('cms:ai-call-log-prune', ['--days' => 10])->assertSuccessful();

    expect(DB::table('core_ai_call_logs')->pluck('task_key')->all())->toBe(['new']);
});

it('serves recent calls and the 7-day duplicate prompt rate', function (): void {
    callLogAuth();
    $row = static fn (string $task, ?string $hash, string $when): array => [
        'called_at' => $when, 'task_key' => $task, 'provider' => 'openai', 'model' => 'm-a', 'via' => 'runtime',
        'attempt' => 1, 'origin' => 'http', 'prompt_hash' => $hash, 'request_id' => 'r1',
    ];
    $recent = now('UTC')->subHour()->format('Y-m-d H:i:s');
    DB::table('core_ai_call_logs')->insert([
        $row('t1', str_repeat('a', 64), $recent),
        $row('t1', str_repeat('a', 64), $recent),
        $row('t1', str_repeat('b', 64), $recent),
        $row('t1', null, $recent),
        $row('t1', str_repeat('a', 64), now('UTC')->subDays(9)->format('Y-m-d H:i:s')),
        $row('other', str_repeat('a', 64), $recent),
    ]);

    $json = $this->withoutMiddleware()->getJson('/api/v2/admin/ai-runtime/calls?task=t1&limit=3')->assertOk()->json('data');

    expect($json['task'])->toBe('t1')->and($json['calls'])->toHaveCount(3)
        ->and($json['calls'][0])->toHaveKeys(['called_at', 'provider', 'model', 'via', 'attempt', 'is_fallback', 'failed', 'refused_reason', 'latency_ms', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'cost_usd', 'request_id', 'origin'])
        ->and($json['duplicate_prompt_7d'])->toBe(['calls_with_hash' => 3, 'duplicate_calls' => 2, 'rate' => 0.6667]);

    $this->getJson('/api/v2/admin/ai-runtime/calls')->assertStatus(422);
    $this->getJson('/api/v2/admin/ai-runtime/calls?task=t1&limit=101')->assertStatus(422);
});

it('round-trips spike alert settings through PUT budget and overview', function (): void {
    callLogAuth();
    $base = $this->withoutMiddleware()->getJson('/api/v2/admin/ai-runtime')->assertOk()->json('data.budget.spike');
    expect($base)->toEqual(['enabled' => true, 'multiplier' => 3, 'min_usd' => 2, 'baseline_days' => 7]);

    $this->putJson('/api/v2/admin/ai-runtime/budget', ['spike_enabled' => false, 'spike_multiplier' => 4.5, 'spike_min_usd' => 10])
        ->assertOk()->assertJsonPath('data.budget.spike.multiplier', 4.5)->assertJsonPath('data.budget.spike.enabled', false)->assertJsonPath('data.budget.spike.min_usd', 10);

    // Keys not sent leave the stored spike settings untouched.
    $this->putJson('/api/v2/admin/ai-runtime/budget', ['monthly_usd' => 5])->assertOk()->assertJsonPath('data.budget.spike.multiplier', 4.5);

    $this->putJson('/api/v2/admin/ai-runtime/budget', ['spike_multiplier' => 25])->assertStatus(406);
    $this->putJson('/api/v2/admin/ai-runtime/budget', ['spike_min_usd' => -1])->assertStatus(406);
});

it('counts only sent first attempts in the duplicate-prompt rate', function (): void {
    $now = now('UTC')->format('Y-m-d H:i:s');
    $row = static fn (int $attempt, ?string $refused, string $hash): array => [
        'called_at' => $now, 'task_key' => 'dup_task', 'provider' => 'openai', 'model' => 'm', 'via' => 'runtime',
        'attempt' => $attempt, 'is_fallback' => $attempt > 1, 'failed' => false, 'refused_reason' => $refused,
        'latency_ms' => 1, 'input_tokens' => 0, 'cached_input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0,
        'prompt_hash' => str_repeat($hash, 64), 'request_id' => null, 'origin' => 'console',
    ];
    Illuminate\Support\Facades\DB::table('core_ai_call_logs')->insert([
        $row(1, null, 'a'), $row(2, null, 'a'),          // one request with a fallback attempt: not a duplicate
        $row(1, 'kill_switch', 'b'), $row(1, null, 'b'), // a refusal sent nothing: not a duplicate
        $row(1, null, 'c'), $row(1, null, 'c'),          // two real calls with the same prompt
    ]);

    $stats = app(Cms\Core\Services\Ai\Usage\AiCallLog::class)->forTask('dup_task')['duplicate_prompt_7d'];

    expect($stats['calls_with_hash'])->toBe(4)->and($stats['duplicate_calls'])->toBe(2)->and($stats['rate'])->toBe(0.5);
});
