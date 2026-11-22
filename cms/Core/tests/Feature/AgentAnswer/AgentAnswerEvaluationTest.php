<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/*
 * deterministic plumbing evaluation (pilot Task 6, step 3): every case of agent-answer-plumbing.php goes
 * through the real projector, corpus, tool, executor, controller and envelope with a fake provider.
 *
 * This is plumbing evidence only: injection cases prove that document text cannot change the fixed tool, profile or
 * policy and that server validation still runs; they say nothing about live-model behaviour. The timing is local
 * framework overhead with a fake provider, not end-to-end latency. Set AGENT_ANSWER_EVAL_REPORT=1 to print the
 * summary used in the evidence pack.
 */

require_once __DIR__ . '/AgentAnswerFixture.php';

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** @var array<string, array<string, mixed>> $GLOBALS['agentAnswerEvaluation'] */
$GLOBALS['agentAnswerEvaluation'] = [];

const AGENT_ANSWER_PRIVATE_KEYS = ['user_id', 'review_pipeline', 'config', 'provider_response', 'error_code', 'retrieval', 'raw', 'payload', 'api_key'];

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-06T03:00:00Z');
    $this->provider = agentAnswerArrange();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

afterAll(function (): void {
    if (getenv('AGENT_ANSWER_EVAL_REPORT') !== '1') {
        return;
    }

    $rows = $GLOBALS['agentAnswerEvaluation'];
    $timings = array_column($rows, 'ms');
    sort($timings);
    $pick = static fn (float $q): float => $timings === [] ? 0.0 : $timings[(int) min(count($timings) - 1, floor($q * count($timings)))];
    $byStatus = [];
    foreach ($rows as $row) {
        $byStatus[$row['http'] . ' ' . $row['status']] = ($byStatus[$row['http'] . ' ' . $row['status']] ?? 0) + 1;
    }
    fwrite(STDERR, PHP_EOL . 'AGENT_ANSWER_EVAL ' . json_encode([
        'cases' => count($rows),
        'exact_envelope_matches' => count(array_filter($rows, static fn (array $row): bool => $row['exact'])),
        'answered' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'answered')),
        'insufficient_evidence' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'insufficient_evidence')),
        'citations_checked' => array_sum(array_column($rows, 'citations')),
        'citations_valid' => array_sum(array_column($rows, 'valid_citations')),
        'by_http_status' => $byStatus,
        'local_framework_overhead_ms' => ['p50' => round($pick(0.5), 1), 'p95' => round($pick(0.95), 1)],
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL);
});

it('keeps the exact envelope, valid anchors and no local persistence for every plumbing case', function (array $case): void {
    $projections = agentAnswerSeedPosts($case['posts']);
    $documentIds = array_map(static fn (array $projection): string => (string) $projection['id'], $projections);
    $this->provider->content = strtr((string) $case['provider_output'], array_combine(
        array_map(static fn (int $index): string => '{doc' . $index . '}', array_keys($documentIds)),
        $documentIds,
    ) ?: []);
    $this->provider->throws = (bool) ($case['provider_throws'] ?? false);

    $arrange = $case['arrange'] ?? null;
    if ($arrange !== 'unauthenticated') {
        Sanctum::actingAs(agentAnswerUser(canAnswer: $arrange !== 'no_permission'));
    }
    match ($arrange) {
        'feature_off' => config()->set('core.agent_answers.enabled', false),
        'content_inactive' => agentApiModuleActivator(contentEnabled: false),
        default => null,
    };
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message . ' ' . json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    $started = hrtime(true);
    $response = $this->postJson(AGENT_ANSWER_URL, ['question' => $case['question'], 'language' => $case['language']]);
    $elapsedMs = (hrtime(true) - $started) / 1e6;

    $expected = $case['expected'];
    $requestId = (string) $response->json('data.request_id');
    $body = match ($expected['status']) {
        'error' => [
            'request_id' => $requestId, 'status' => 'error', 'answer' => null, 'conflict' => false, 'as_of' => null,
            'citations' => [], 'error' => agentAnswerEvaluationError($expected['error_code']),
        ],
        'insufficient_evidence' => [
            'request_id' => $requestId, 'status' => 'insufficient_evidence',
            'answer' => $case['language'] === 'en'
                ? 'The approved corpus does not contain enough evidence to answer this question.'
                : 'Không đủ dữ liệu đã được phê duyệt để trả lời câu hỏi này.',
            'conflict' => false, 'as_of' => '2026-09-06T03:00:00Z', 'citations' => [], 'error' => null,
        ],
        default => [
            'request_id' => $requestId, 'status' => 'answered', 'answer' => $expected['answer'],
            'conflict' => $expected['conflict'], 'as_of' => '2026-09-06T03:00:00Z',
            'citations' => array_map(static fn (array $citation): array => agentAnswerEvaluationCitation($projections[$citation[0]], $citation[1], $citation[2]), $expected['citations']),
            'error' => null,
        ],
    };

    $response->assertStatus($case['expected_http_status'])->assertExactJson(['data' => $body]);
    expect($requestId)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($response->headers->get('X-Request-Id'))->toBe($requestId)
        ->and($this->provider->payloads !== [])->toBe($case['provider_called'])
        ->and(array_values(array_intersect(agentAnswerEvaluationKeys($response->json()), AGENT_ANSWER_PRIVATE_KEYS)))->toBe([])
        ->and((string) $response->getContent())->not->toContain('provider-secret');

    // Every answered citation points at a seeded section, quotes it exactly and carries the approved digest.
    $valid = 0;
    foreach ((array) $response->json('data.citations') as $citation) {
        $projection = collect($projections)->firstWhere('id', $citation['document_id']);
        $section = collect($projection['sections'] ?? [])->firstWhere('id', $citation['section_id']);
        if ($projection !== null && $section !== null
            && $citation['quote'] !== '' && str_contains(trim((string) preg_replace('/\s+/u', ' ', $section['text'])), $citation['quote'])
            && $citation['content_digest'] === config('content.agent_answers.approved_posts')[substr($citation['document_id'], strlen('urn:ispa:content:'))]) {
            $valid++;
        }
    }
    expect($valid)->toBe(count((array) $response->json('data.citations')));

    // Nothing of the question or the answer is kept in any table, and nothing reaches the logs.
    $canaries = array_values(array_filter([trim($case['question']), (string) ($expected['answer'] ?? '')], static fn (string $text): bool => mb_strlen($text) >= 8));
    foreach (agentAnswerAllRows() as $table => $json) {
        if ($table === 'posts') {
            continue;
        }
        foreach ($canaries as $canary) {
            expect($json)->not->toContain($canary);
        }
    }
    foreach ($logged as $line) {
        foreach ([...$canaries, ...array_map(static fn (array $projection): string => (string) ($projection['sections'][0]['text'] ?? '#'), $projections)] as $canary) {
            expect($line)->not->toContain($canary);
        }
    }

    $GLOBALS['agentAnswerEvaluation'][] = [
        'http' => $response->status(),
        'status' => (string) $response->json('data.status'),
        'exact' => true,
        'citations' => count((array) $response->json('data.citations')),
        'valid_citations' => $valid,
        'ms' => $elapsedMs,
    ];
})->with(fn (): array => array_map(static fn (array $case): array => [$case], require __DIR__ . '/agent-answer-plumbing.php'));

it('keeps the route free of the request/response API log middleware', function (): void {
    $route = Route::getRoutes()->getByName('api.v2.public.agent.answers');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->not->toContain('api.log')
        ->and($route->gatherMiddleware())->not->toContain(\App\Http\Middleware\ApiLogsRequest::class);
});

/** @return array{code: string, message: string} */
function agentAnswerEvaluationError(string $code): array
{
    return [
        'authentication_required' => ['code' => 'authentication_required', 'message' => 'Authentication required.'],
        'agent_answer_forbidden' => ['code' => 'agent_answer_forbidden', 'message' => 'You are not allowed to use agent answers.'],
        'invalid_request' => ['code' => 'invalid_request', 'message' => 'The request is invalid.'],
        'agent_response_invalid' => ['code' => 'agent_response_invalid', 'message' => 'The agent returned an invalid evidence response.'],
        'agent_unavailable' => ['code' => 'agent_unavailable', 'message' => 'Agent answers are unavailable.'],
    ][$code];
}

/** @param array<string, mixed> $projection */
function agentAnswerEvaluationCitation(array $projection, string $sectionId, string $quote): array
{
    $section = (string) collect($projection['sections'])->firstWhere('id', $sectionId)['text'];

    return [
        'document_id' => $projection['id'],
        'section_id' => $sectionId,
        'title' => $projection['title'],
        'canonical_url' => $projection['canonical_url'],
        'published_at' => $projection['published_at'],
        'modified_at' => $projection['modified_at'],
        'content_digest' => $projection['content_digest'],
        'quote' => $quote,
        'excerpt' => $section,
    ];
}

/** @return list<string> Every key at any depth. */
function agentAnswerEvaluationKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];
    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = [...$keys, ...agentAnswerEvaluationKeys($child)];
    }

    return $keys;
}
