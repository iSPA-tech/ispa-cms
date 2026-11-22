<?php

declare(strict_types=1);

use App\Enums\StatusEnum;
use Carbon\CarbonImmutable;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Models\Post;
use Cms\Content\Services\Agentic\AgentAnswerCorpusService;
use Cms\Content\Services\Agentic\AgentReadablePostProjector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * retrieval evaluation (pilot Task 6, step 4): the real PostRepository::searchAgentEligible and
 * AgentAnswerCorpusService against the labeled set in tests/Fixtures/AgentContent/agent-answer-retrieval.php.
 *
 * Gates: Recall@5 = 1.0 per exact, diacritic-folded and natural query (sign-off needs >= 0.90 overall), zero forbidden
 * (unapproved, draft, future, deleted, page) documents, empty result for punctuation/whitespace-only input. Typo
 * queries are measured, not gated (no v1 threshold). AGENT_ANSWER_EVAL_REPORT=1 prints the summary.
 */

uses(Tests\TestCase::class, RefreshDatabase::class);

$GLOBALS['retrievalEvaluation'] = [];

beforeEach(function (): void {
    config()->set('ispa-cms.skip_languages_query', true);
    $this->asOf = CarbonImmutable::parse('2026-09-06T03:00:00Z');
    $this->set = require __DIR__ . '/../Fixtures/AgentContent/agent-answer-retrieval.php';
    $this->uuids = retrievalEvaluationSeed($this->set['posts']);
});

afterAll(function (): void {
    if (getenv('AGENT_ANSWER_EVAL_REPORT') !== '1') {
        return;
    }

    $rows = $GLOBALS['retrievalEvaluation'];
    $byClass = [];
    foreach ($rows as $row) {
        $byClass[$row['class']]['queries'] = ($byClass[$row['class']]['queries'] ?? 0) + 1;
        $byClass[$row['class']]['recall_sum'] = ($byClass[$row['class']]['recall_sum'] ?? 0) + $row['recall'];
        $byClass[$row['class']]['top1'] = ($byClass[$row['class']]['top1'] ?? 0) + ($row['top1'] ? 1 : 0);
    }
    foreach ($byClass as $class => $stats) {
        $byClass[$class]['recall_at_5'] = round($stats['recall_sum'] / $stats['queries'], 3);
        unset($byClass[$class]['recall_sum']);
    }
    $gated = array_filter($rows, static fn (array $row): bool => in_array($row['class'], ['exact', 'folded', 'natural'], true));
    fwrite(STDERR, PHP_EOL . 'AGENT_RETRIEVAL_EVAL ' . json_encode([
        'queries' => count($rows),
        'recall_at_5_exact_folded_natural' => $gated === [] ? null : round(array_sum(array_column($gated, 'recall')) / count($gated), 3),
        'forbidden_documents_returned' => array_sum(array_column($rows, 'forbidden')),
        'by_class' => $byClass,
        'typo' => array_values(array_map(static fn (array $row): array => [$row['query'], $row['returned']], array_filter($rows, static fn (array $row): bool => $row['class'] === 'typo'))),
        'ranking' => array_values(array_map(static fn (array $row): array => [$row['query'], $row['returned']], array_filter($rows, static fn (array $row): bool => $row['ordered'] && $row['class'] !== 'empty'))),
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL);
});

it('retrieves the labeled documents and never a forbidden one', function (string $label): void {
    [$query, $class, $relevant, $ordered] = $this->set['queries'][$label];
    $byUuid = array_flip($this->uuids);

    $returned = app(PostInterface::class)->searchAgentEligible($query, $this->asOf, 5)
        ->map(static fn (Post $post): string => $byUuid[$post->uuid] ?? 'unknown')
        ->values()
        ->all();
    $hits = (float) count(array_intersect($relevant, $returned));
    $recall = $relevant === [] ? ($returned === [] ? 1.0 : 0.0) : $hits / count($relevant);
    $forbidden = count(array_intersect($this->set['forbidden'], $returned));

    $GLOBALS['retrievalEvaluation'][] = [
        'query' => $query, 'class' => $class, 'ordered' => $ordered, 'returned' => $returned,
        'recall' => $recall, 'forbidden' => $forbidden,
        'top1' => $relevant !== [] && ($returned[0] ?? null) === $relevant[0],
    ];

    expect($forbidden)->toBe(0)
        ->and(count($returned))->toBeLessThanOrEqual(5);
    if ($class === 'typo') {
        return; // measured only: typo tolerance has no v1 threshold.
    }
    expect($recall)->toBe(1.0);
    if ($ordered) {
        // The labeled keys lead the result in this order (lower-scoring partial matches may follow).
        expect(array_slice($returned, 0, count($relevant)))->toBe($relevant);
        if ($relevant === []) {
            expect($returned)->toBe([]);
        }
    }
})->with(fn (): array => array_keys((require __DIR__ . '/../Fixtures/AgentContent/agent-answer-retrieval.php')['queries']));

it('drops a post from the corpus after a semantic change until its new digest is approved', function (): void {
    $corpus = app(AgentAnswerCorpusService::class);
    $before = array_column($corpus->retrieve('EBD', $this->asOf, 5), 'id');

    DB::table('posts')->where('uuid', $this->uuids['ebd'])->update(['name' => 'EBD phân phối lực phanh điện tử (sửa)']);
    $drifted = array_column($corpus->retrieve('EBD', $this->asOf, 5), 'id');
    $stillFound = app(PostInterface::class)->searchAgentEligible('EBD', $this->asOf, 5)->pluck('uuid')->all();

    DB::table('posts')->where('uuid', $this->uuids['ebd'])->update(['updated_at' => '2026-09-06 09:00:00', 'name' => 'EBD phân phối lực phanh điện tử']);
    $modifiedOnly = array_column($corpus->retrieve('EBD', $this->asOf, 5), 'id');

    expect($before)->toBe(['urn:ispa:content:' . $this->uuids['ebd']])
        ->and($stillFound)->toBe([$this->uuids['ebd']])
        ->and($drifted)->toBe([])
        ->and($modifiedOnly)->toBe($before);
});

it('reflects a status change on the next call because nothing is cached', function (): void {
    $repository = app(PostInterface::class);
    $first = $repository->searchAgentEligible('EBD', $this->asOf, 5)->pluck('uuid')->all();

    DB::table('posts')->where('uuid', $this->uuids['ebd'])->update(['status' => StatusEnum::DRAFT->value]);
    $afterUnpublish = $repository->searchAgentEligible('EBD', $this->asOf, 5)->pluck('uuid')->all();

    DB::table('posts')->where('uuid', $this->uuids['ebd'])->update(['status' => StatusEnum::PUBLISHED->value]);
    $afterRepublish = $repository->searchAgentEligible('EBD', $this->asOf, 5)->pluck('uuid')->all();

    expect($first)->toBe([$this->uuids['ebd']])
        ->and($afterUnpublish)->toBe([])
        ->and($afterRepublish)->toBe([$this->uuids['ebd']]);
});

it('drops a post unpublished between the ranking read and the hydration read', function (): void {
    $reads = 0;
    $uuid = $this->uuids['ebd'];
    DB::listen(static function (QueryExecuted $query) use (&$reads, $uuid): void {
        $sql = strtolower(ltrim($query->sql));
        if (! str_starts_with($sql, 'select') || ! str_contains($sql, 'posts')) {
            return;
        }
        if (++$reads === 1) {
            DB::table('posts')->where('uuid', $uuid)->update(['status' => StatusEnum::DRAFT->value]);
        }
    });

    $result = app(PostInterface::class)->searchAgentEligible('EBD', $this->asOf, 5);

    expect($reads)->toBe(2)->and($result)->toBeEmpty();
});

/**
 * Seed the evaluation posts; approve every post without the `unapproved` flag with its real projection digest.
 *
 * @param array<string, array{0: string, 1: string, 2: list<string>}> $posts
 * @return array<string, string> key => uuid
 */
function retrievalEvaluationSeed(array $posts): array
{
    DB::table('languages')->insertOrIgnore(['id' => 0, 'name' => 'Tiếng Việt', 'code' => 'vi', 'path' => 'vi']);
    foreach ([StatusEnum::PUBLISHED->value, StatusEnum::DRAFT->value] as $status) {
        DB::table('enumerates')->insertOrIgnore(['name' => $status, 'enum_key' => $status, 'type' => 'status']);
    }

    $uuids = [];
    $approved = [];
    $index = 0;
    foreach ($posts as $key => $post) {
        [$title, $publishedAt, $flags] = $post;
        $body = $post[3] ?? $title . '.';
        $index++;
        $uuid = sprintf('%08d-0000-4000-8000-%012d', $index, $index);
        $id = DB::table('posts')->insertGetId([
            'uuid' => $uuid,
            'name' => $title,
            'description' => null,
            'content' => json_encode([['type' => 'text', 'value' => '<p>' . $body . '</p>']], JSON_UNESCAPED_UNICODE),
            'type' => in_array('page', $flags, true) ? 'page' : 'post',
            'language_id' => 0,
            'status' => in_array('draft', $flags, true) ? StatusEnum::DRAFT->value : StatusEnum::PUBLISHED->value,
            'published_at' => in_array('future', $flags, true) ? '2026-09-07 08:00:00' : $publishedAt,
            'created_at' => '2026-08-01 01:00:00',
            'updated_at' => '2026-09-05 01:00:00',
            'deleted_at' => in_array('deleted', $flags, true) ? '2026-09-03 01:00:00' : null,
        ]);
        $uuids[$key] = $uuid;
        if (! in_array('unapproved', $flags, true)) {
            $post = Post::query()->withTrashed()->withoutGlobalScope(Post::APPENDED_RELATIONS_SCOPE)
                ->with(['categories.slugs', 'language'])->findOrFail($id);
            $approved[$uuid] = app(AgentReadablePostProjector::class)->project($post)['content_digest'];
        }
    }
    config()->set('content.agent_answers.approved_posts', $approved);

    return $uuids;
}
