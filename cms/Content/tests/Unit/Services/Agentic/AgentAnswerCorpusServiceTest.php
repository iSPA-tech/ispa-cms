<?php

declare(strict_types=1);

use App\Enums\StatusEnum;
use Carbon\CarbonImmutable;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Models\Post;
use Cms\Content\Services\Agentic\AgentAnswerCorpusService;
use Cms\Content\Services\Agentic\AgentReadablePostProjector;
use Cms\Core\Models\Language;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    config()->set('content.agent_answers.max_corpus_chars', 60000);
    config()->set('content.agent_answers.approved_posts', []);
});

it('returns only snapshots whose server-approved digest still matches', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-06T03:00:00Z');
    $approved = agentCorpusPost();
    $changed = agentCorpusPost([
        'uuid' => '22222222-2222-4222-8222-222222222222',
        'name' => 'Nội dung đã thay đổi',
    ]);
    $projector = app(AgentReadablePostProjector::class);
    $approvedDocument = $projector->project($approved);
    config()->set('content.agent_answers.approved_posts', [
        $approved->uuid => $approvedDocument['content_digest'],
        $changed->uuid => str_repeat('b', 64),
    ]);

    $posts = Mockery::mock(PostInterface::class);
    $posts->shouldReceive('searchAgentEligible')
        ->once()
        ->with('phanh ABS', $asOf, 5)
        ->andReturn(new EloquentCollection([$approved, $changed]));

    $documents = agentCorpusService($posts, $projector)->retrieve('phanh ABS', $asOf, 5);

    expect($documents)->toBe([$approvedDocument]);
});

it('rejects the whole approval map when any UUID or digest is invalid', function (array $map): void {
    config()->set('content.agent_answers.approved_posts', $map);
    $posts = Mockery::mock(PostInterface::class);
    $posts->shouldNotReceive('searchAgentEligible');

    $documents = agentCorpusService($posts)->retrieve(
        'ABS là gì?',
        CarbonImmutable::parse('2026-09-06T03:00:00Z'),
        5,
    );

    expect($documents)->toBe([]);
})->with([
    'malformed config value' => [['not-an-object']],
    'invalid UUID among valid entries' => [[
        '11111111-1111-4111-8111-111111111111' => str_repeat('a', 64),
        'not-a-uuid' => str_repeat('b', 64),
    ]],
    'uppercase digest among valid entries' => [[
        '11111111-1111-4111-8111-111111111111' => str_repeat('a', 64),
        '22222222-2222-4222-8222-222222222222' => str_repeat('B', 64),
    ]],
    'wrong digest length' => [[
        '11111111-1111-4111-8111-111111111111' => str_repeat('a', 63),
    ]],
]);

it('ignores modified time drift but rejects semantic drift', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-06T03:00:00Z');
    $post = agentCorpusPost();
    $projector = app(AgentReadablePostProjector::class);
    $approvedDigest = $projector->project($post)['content_digest'];
    config()->set('content.agent_answers.approved_posts', [$post->uuid => $approvedDigest]);
    $posts = Mockery::mock(PostInterface::class);
    $posts->shouldReceive('searchAgentEligible')
        ->twice()
        ->with('phanh ABS', $asOf, 5)
        ->andReturn(new EloquentCollection([$post]));
    $service = agentCorpusService($posts, $projector);

    $post->setAttribute('updated_at', '2026-09-06 04:00:00+00:00');
    $modifiedOnly = $service->retrieve('phanh ABS', $asOf, 5);
    $post->setAttribute('name', 'Tiêu đề thay đổi');
    $semanticDrift = $service->retrieve('phanh ABS', $asOf, 5);

    expect($modifiedOnly)->toHaveCount(1)
        ->and($modifiedOnly[0]['content_digest'])->toBe($approvedDigest)
        ->and($semanticDrift)->toBe([]);
});

it('applies total corpus cap in retrieval order', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-06T03:00:00Z');
    $first = agentCorpusPost(['content' => [['type' => 'text', 'value' => str_repeat('A', 80)]]]);
    $second = agentCorpusPost([
        'uuid' => '22222222-2222-4222-8222-222222222222',
        'content' => [['type' => 'text', 'value' => str_repeat('B', 80)]],
    ]);
    $projector = app(AgentReadablePostProjector::class);
    $firstDocument = $projector->project($first);
    $secondDocument = $projector->project($second);
    config()->set('content.agent_answers.approved_posts', [
        $first->uuid => $firstDocument['content_digest'],
        $second->uuid => $secondDocument['content_digest'],
    ]);
    config()->set(
        'content.agent_answers.max_corpus_chars',
        mb_strlen(json_encode($firstDocument, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'UTF-8') + 1,
    );
    $posts = Mockery::mock(PostInterface::class);
    $posts->shouldReceive('searchAgentEligible')
        ->once()
        ->andReturn(new EloquentCollection([$first, $second]));

    $documents = agentCorpusService($posts, $projector)->retrieve('ABS', $asOf, 5);

    expect($documents)->toBe([$firstDocument]);
});

it('keeps returned projection unchanged after later model mutation', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-06T03:00:00Z');
    $post = agentCorpusPost();
    $projector = app(AgentReadablePostProjector::class);
    $expected = $projector->project($post);
    config()->set('content.agent_answers.approved_posts', [$post->uuid => $expected['content_digest']]);
    $posts = Mockery::mock(PostInterface::class);
    $posts->shouldReceive('searchAgentEligible')->once()->andReturn(new EloquentCollection([$post]));

    $snapshot = agentCorpusService($posts, $projector)->retrieve('ABS', $asOf, 5);
    $post->setAttribute('name', 'DB row changed later');
    $post->setAttribute('updated_at', '2026-09-06 06:00:00+00:00');

    expect($snapshot)->toBe([$expected]);
});

function agentCorpusService(
    PostInterface $posts,
    ?AgentReadablePostProjector $projector = null,
): AgentAnswerCorpusService {
    expect(class_exists(AgentAnswerCorpusService::class))->toBeTrue();

    app()->instance(PostInterface::class, $posts);
    app()->instance(
        AgentReadablePostProjector::class,
        $projector ?? app(AgentReadablePostProjector::class),
    );

    return app(AgentAnswerCorpusService::class);
}

/** @param array<string, mixed> $overrides */
function agentCorpusPost(array $overrides = []): Post
{
    $post = new Post;
    $post->forceFill(array_replace([
        'uuid' => '11111111-1111-4111-8111-111111111111',
        'name' => 'ABS hoạt động thế nào?',
        'description' => 'Giải thích ngắn.',
        'content' => [['type' => 'text', 'value' => 'ABS giúp bánh xe không bị bó cứng.']],
        'type' => 'post',
        'status' => StatusEnum::PUBLISHED->value,
        'published_at' => '2026-09-04 01:00:00+00:00',
        'updated_at' => '2026-09-05 01:00:00+00:00',
        'config' => [],
    ], $overrides));
    $post->setRelation('categories', new EloquentCollection());
    $post->setRelation('language', new Language(['code' => 'vi', 'name' => 'Tiếng Việt']));

    return $post;
}
