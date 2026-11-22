<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * (pilot Task 5): POST /api/v2/public/agent/answers. Every response, success or error, has the exact
 * envelope; the request id is in the body and in X-Request-ID; the content.answer_posts tool runs end to end through
 * the ephemeral executor with a fake provider.
 */

require_once __DIR__ . '/AgentAnswerFixture.php';

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-06T03:00:00Z');
    $this->provider = agentAnswerArrange();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('answers an authenticated question with the exact envelope and server-hydrated citations', function (): void {
    Sanctum::actingAs(agentAnswerUser());

    $response = $this->postJson(AGENT_ANSWER_URL, ['question' => 'ABS là gì?', 'language' => 'vi']);

    $requestId = agentAnswerExpectEnvelope($response, 200);
    $response->assertExactJson(['data' => [
        'request_id' => $requestId,
        'status' => 'answered',
        'answer' => 'ABS giúp tránh bó cứng bánh xe.',
        'conflict' => false,
        'as_of' => '2026-09-06T03:00:00Z',
        'citations' => [[
            'document_id' => 'urn:ispa:content:' . AGENT_ANSWER_POST_UUID,
            'section_id' => 'body-1',
            'title' => 'ABS là gì?',
            'canonical_url' => null,
            'published_at' => $response->json('data.citations.0.published_at'),
            'modified_at' => $response->json('data.citations.0.modified_at'),
            'content_digest' => config('content.agent_answers.approved_posts')[AGENT_ANSWER_POST_UUID],
            'quote' => AGENT_ANSWER_QUOTE,
            'excerpt' => AGENT_ANSWER_QUOTE,
        ]],
        'error' => null,
    ]]);
    expect($this->provider->payloads)->toHaveCount(1)
        ->and($response->json('data.citations.0.published_at'))->toBeString();
});

it('returns the localized insufficient evidence answer without calling the provider', function (string $question, string $language, string $answer): void {
    Sanctum::actingAs(agentAnswerUser());

    $response = $this->postJson(AGENT_ANSWER_URL, ['question' => $question, 'language' => $language]);

    $response->assertExactJson(['data' => [
        'request_id' => agentAnswerExpectEnvelope($response, 200),
        'status' => 'insufficient_evidence',
        'answer' => $answer,
        'conflict' => false,
        'as_of' => '2026-09-06T03:00:00Z',
        'citations' => [],
        'error' => null,
    ]]);
    expect($this->provider->payloads)->toBe([]);
})->with([
    'punctuation only' => ['???', 'vi', 'Không đủ dữ liệu đã được phê duyệt để trả lời câu hỏi này.'],
    'no approved match, vi' => ['Thời tiết hôm nay', 'vi', 'Không đủ dữ liệu đã được phê duyệt để trả lời câu hỏi này.'],
    'no approved match, en' => ['Weather today', 'en', 'The approved corpus does not contain enough evidence to answer this question.'],
]);

it('requires authentication', function (): void {
    agentAnswerExpectError($this->postJson(AGENT_ANSWER_URL, ['question' => 'ABS là gì?']), 401, 'authentication_required', 'Authentication required.');
    expect($this->provider->payloads)->toBe([]);
});

it('requires the agent_profile.answer ability', function (): void {
    Sanctum::actingAs(agentAnswerUser(canAnswer: false));

    agentAnswerExpectError($this->postJson(AGENT_ANSWER_URL, ['question' => 'ABS là gì?']), 403, 'agent_answer_forbidden', 'You are not allowed to use agent answers.');
    expect($this->provider->payloads)->toBe([]);
});

it('rejects an invalid request', function (array $body): void {
    Sanctum::actingAs(agentAnswerUser());

    agentAnswerExpectError($this->postJson(AGENT_ANSWER_URL, $body), 422, 'invalid_request', 'The request is invalid.');
    expect($this->provider->payloads)->toBe([]);
})->with([
    'missing question' => [['language' => 'vi']],
    'empty question' => [['question' => '']],
    'whitespace only' => [['question' => "  \t\n "]],
    'oversized question' => [['question' => str_repeat('Q', 1201)]],
    'non-string question' => [['question' => ['ABS']]],
    'unsupported language' => [['question' => 'ABS là gì?', 'language' => 'fr']],
]);

it('is unavailable before any post access when a server gate is closed', function (Closure $close): void {
    Sanctum::actingAs(agentAnswerUser());
    $close();
    $postReads = 0;
    DB::listen(static function (QueryExecuted $query) use (&$postReads): void {
        if (str_contains(strtolower($query->sql), '"posts"') || str_contains(strtolower($query->sql), '`posts`')) {
            $postReads++;
        }
    });

    agentAnswerExpectError($this->postJson(AGENT_ANSWER_URL, ['question' => 'ABS là gì?']), 503, 'agent_unavailable', 'Agent answers are unavailable.');
    expect($postReads)->toBe(0)
        ->and($this->provider->payloads)->toBe([]);
})->with([
    'feature disabled' => [fn () => config()->set('core.agent_answers.enabled', false)],
    'server project not allowed' => [fn () => config()->set('ispa-cms.folder_name', 'OtherProject')],
    'Content inactive' => [fn () => agentApiModuleActivator(contentEnabled: false)],
    'kill switch' => [fn () => config()->set('core.agentic.kill_switch', true)],
    'profile inactive' => [fn () => DB::table('agent_profiles')->where('slug', 'public-content-assistant')->update(['status' => 'disabled'])],
    'grant missing' => [fn () => DB::table('agent_profile_tools')->delete()],
]);

it('is unavailable when the provider fails or no provider task is set', function (Closure $break): void {
    Sanctum::actingAs(agentAnswerUser());
    $break($this->provider);

    agentAnswerExpectError($this->postJson(AGENT_ANSWER_URL, ['question' => 'ABS là gì?']), 503, 'agent_unavailable', 'Agent answers are unavailable.');
})->with([
    'provider exception' => [fn (AgentAnswerFakeProvider $provider) => $provider->throws = true],
    'empty provider output' => [fn (AgentAnswerFakeProvider $provider) => $provider->content = ''],
    'no provider task' => [fn () => DB::table('settings')->where('setting_key', 'like', 'core.ai_runtime.tasks.content_agent_answer.%')->delete()],
]);

it('maps an invalid model evidence response to 502', function (): void {
    Sanctum::actingAs(agentAnswerUser());
    $this->provider->content = 'not-json';

    agentAnswerExpectError($this->postJson(AGENT_ANSWER_URL, ['question' => 'ABS là gì?']), 502, 'agent_response_invalid', 'The agent returned an invalid evidence response.');
});

it('rate limits per authenticated user', function (): void {
    config()->set('core.agent_answers.rate_limit_per_minute', 2);
    Sanctum::actingAs(agentAnswerUser());

    $this->postJson(AGENT_ANSWER_URL, ['question' => 'ABS là gì?'])->assertOk();
    $this->postJson(AGENT_ANSWER_URL, ['question' => 'ABS là gì?'])->assertOk();

    agentAnswerExpectError($this->postJson(AGENT_ANSWER_URL, ['question' => 'ABS là gì?']), 429, 'rate_limited', 'Too many agent answer requests.');
});

it('keeps question, answer and excerpt out of database rows, cache and logs', function (): void {
    $question = 'Canaryq91 ABS là gì';
    $answer = 'Trả lời canary-A-55 về ABS.';
    agentAnswerRetitle('Canaryq91 hệ thống ABS là gì');
    $this->provider->content = (string) json_encode([
        'status' => 'answered',
        'answer' => $answer,
        'conflict' => false,
        'references' => [['document_id' => 'urn:ispa:content:' . AGENT_ANSWER_POST_UUID, 'section_id' => 'body-1', 'quote' => AGENT_ANSWER_QUOTE]],
    ], JSON_UNESCAPED_UNICODE);
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message . ' ' . json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    });
    Sanctum::actingAs(agentAnswerUser());

    $response = $this->postJson(AGENT_ANSWER_URL, ['question' => $question, 'language' => 'vi']);
    $response->assertOk()->assertJsonPath('data.status', 'answered')->assertJsonPath('data.answer', $answer);

    $rows = agentAnswerAllRows();
    $cache = json_encode(agentAnswerCacheContents(), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    foreach ($rows as $table => $json) {
        expect($json)->not->toContain($question)->and($json)->not->toContain($answer);
        if (str_starts_with($table, 'agent_') || str_starts_with($table, 'workflow_')) {
            expect($json)->not->toContain(AGENT_ANSWER_QUOTE);
        }
    }
    foreach ([$question, $answer, AGENT_ANSWER_QUOTE] as $canary) {
        expect($cache)->not->toContain($canary);
        foreach ($logged as $line) {
            expect($line)->not->toContain($canary);
        }
    }
    expect(DB::table('agent_runs')->count())->toBe(0)
        ->and(DB::table('agent_tool_calls')->count())->toBe(0);
});

/** Assert status, envelope keys and request id header; return the request id. */
function agentAnswerExpectEnvelope(TestResponse $response, int $status): string
{
    $response->assertStatus($status);
    $requestId = (string) $response->json('data.request_id');

    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($response->json('data')))->toBe(['request_id', 'status', 'answer', 'conflict', 'as_of', 'citations', 'error'])
        ->and($requestId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($response->headers->get('X-Request-ID'))->toBe($requestId)
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('private');

    return $requestId;
}

function agentAnswerExpectError(TestResponse $response, int $status, string $code, string $message): void
{
    $response->assertExactJson(['data' => [
        'request_id' => agentAnswerExpectEnvelope($response, $status),
        'status' => 'error',
        'answer' => null,
        'conflict' => false,
        'as_of' => null,
        'citations' => [],
        'error' => ['code' => $code, 'message' => $message],
    ]]);
}

/** @return array<string, mixed> Raw contents of the array cache store used in tests. */
function agentAnswerCacheContents(): array
{
    $store = Cache::getStore();
    if (! $store instanceof \Illuminate\Cache\ArrayStore) {
        return [];
    }

    $storage = (new ReflectionProperty($store, 'storage'))->getValue($store);

    return is_array($storage) ? $storage : [];
}

it('meters an answer through the AI gateway without any question-derived value', function (): void {
    DB::table('core_ai_call_logs')->delete();
    $question = 'Canaryq91 ABS là gì';
    agentAnswerRetitle('Canaryq91 hệ thống ABS là gì');
    // Production wiring: the runtime meters through AiGateway (usage ledger + per-call log).
    $resolver = app(\Cms\Core\Services\Ai\AiRuntimeConfigResolver::class);
    app()->instance(\Cms\Core\Services\Ai\AiTaskRuntime::class, new \Cms\Core\Services\Ai\AiTaskRuntime(
        $resolver, null, $this->provider, app(\Cms\Core\Services\Ai\Usage\AiGateway::class),
    ));
    Sanctum::actingAs(agentAnswerUser());

    $this->postJson(AGENT_ANSWER_URL, ['question' => $question])->assertOk()->assertJsonPath('data.status', 'answered');

    $row = DB::table('core_ai_call_logs')->first();
    $sent = $this->provider->payloads[0];
    expect($row)->not->toBeNull()
        ->and($row->task_key)->toBe('content_agent_answer')
        ->and($row->prompt_hash)->toBeNull()
        ->and(json_encode((array) $row, JSON_UNESCAPED_UNICODE))->not->toContain($question)
        ->and(json_encode((array) $row))->not->toContain((string) \Cms\Core\Services\Ai\Usage\AiCallLog::promptHash($sent));
    foreach (agentAnswerAllRows() as $table => $json) {
        if ($table !== 'posts') {
            expect($json)->not->toContain($question)
                ->and($json)->not->toContain((string) \Cms\Core\Services\Ai\Usage\AiCallLog::promptHash($sent));
        }
    }
});
