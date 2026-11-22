<?php

declare(strict_types=1);

use App\Enums\StatusEnum;
use Carbon\CarbonImmutable;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Models\Post;
use Cms\Content\Repositories\PostRepository;
use Cms\Core\Helpers\CacheHelper;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    config()->set('ispa-cms.skip_languages_query', true);
    config()->set('content.agent_answers.approved_posts', []);

    Schema::dropIfExists('posts');
    Schema::create('posts', static function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->string('name');
        $table->text('description')->nullable();
        $table->json('content')->nullable();
        $table->string('type', 50)->default('post');
        $table->unsignedBigInteger('language_id')->default(0);
        $table->string('status', 50);
        $table->timestamp('published_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function (): void {
    CacheHelper::clearResolvedInstance('CacheHelper');
    Schema::dropIfExists('posts');
});

it('centralizes public visibility at one captured time', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-06T10:00:00+07:00');
    agentAnswerInsertPost('11111111-1111-4111-8111-111111111111', 'Published before cutoff', [
        'published_at' => '2026-09-06 09:59:59',
    ]);
    agentAnswerInsertPost('22222222-2222-4222-8222-222222222222', 'Published at cutoff', [
        'published_at' => '2026-09-06 10:00:00',
    ]);
    agentAnswerInsertPost('33333333-3333-4333-8333-333333333333', 'Published without date');
    agentAnswerInsertPost('44444444-4444-4444-8444-444444444444', 'Future', [
        'published_at' => '2026-09-06 10:00:01',
    ]);
    agentAnswerInsertPost('55555555-5555-4555-8555-555555555555', 'Draft', [
        'status' => StatusEnum::DRAFT->value,
    ]);
    agentAnswerInsertPost('66666666-6666-4666-8666-666666666666', 'Deleted', [
        'deleted_at' => '2026-09-06 01:00:00',
    ]);

    $uuids = Post::query()->publicAt($asOf)->orderBy('id')->pluck('uuid')->all();

    expect($uuids)->toBe([
        '11111111-1111-4111-8111-111111111111',
        '22222222-2222-4222-8222-222222222222',
        '33333333-3333-4333-8333-333333333333',
    ]);
});

it('exposes the fixed server-owned repository contract without a caller allowlist', function (): void {
    expect(method_exists(PostInterface::class, 'searchAgentEligible'))->toBeTrue();

    $method = new ReflectionMethod(PostInterface::class, 'searchAgentEligible');

    expect(array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $method->getParameters(),
    ))->toBe(['keywords', 'asOf', 'limit']);
});

it('returns only allowlisted public posts in the current language without using application cache', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-06T10:00:00+07:00');
    $eligible = agentAnswerInsertPost('11111111-1111-4111-8111-111111111111', 'Hệ thống phanh ABS');
    agentAnswerInsertPost('22222222-2222-4222-8222-222222222222', 'Phanh ABS unlisted');
    $draft = agentAnswerInsertPost('33333333-3333-4333-8333-333333333333', 'Phanh ABS draft', [
        'status' => StatusEnum::DRAFT->value,
    ]);
    $future = agentAnswerInsertPost('44444444-4444-4444-8444-444444444444', 'Phanh ABS future', [
        'published_at' => '2026-09-06 10:00:01',
    ]);
    $deleted = agentAnswerInsertPost('55555555-5555-4555-8555-555555555555', 'Phanh ABS deleted', [
        'deleted_at' => '2026-09-06 01:00:00',
    ]);
    $otherLanguage = agentAnswerInsertPost('66666666-6666-4666-8666-666666666666', 'Phanh ABS English', [
        'language_id' => 2,
    ]);
    $wrongType = agentAnswerInsertPost('77777777-7777-4777-8777-777777777777', 'Phanh ABS page', [
        'type' => 'page',
    ]);

    config()->set('content.agent_answers.approved_posts', [
        $eligible->uuid => str_repeat('a', 64),
        $draft->uuid => str_repeat('b', 64),
        $future->uuid => str_repeat('c', 64),
        $deleted->uuid => str_repeat('d', 64),
        $otherLanguage->uuid => str_repeat('e', 64),
        $wrongType->uuid => str_repeat('f', 64),
    ]);
    Cache::spy();
    $cacheRecords = new class extends CacheHelper {
        /** @var list<array{string, string, int}> */
        public array $stored = [];

        public function store(string $cacheKey, string $title, int $ttl = 0): void
        {
            $this->stored[] = [$cacheKey, $title, $ttl];
        }
    };
    app()->instance('CacheHelper', $cacheRecords);
    CacheHelper::clearResolvedInstance('CacheHelper');

    $results = (new PostRepository())->searchAgentEligible('phanh ABS', $asOf, 5);

    expect($results->pluck('uuid')->all())->toBe([$eligible->uuid]);
    Cache::shouldNotHaveReceived('remember');
    Cache::shouldNotHaveReceived('rememberForever');
    Cache::shouldNotHaveReceived('put');
    Cache::shouldNotHaveReceived('add');
    Cache::shouldNotHaveReceived('forever');
    expect($cacheRecords->stored)->toBe([]);
});

it('ranks title hits above description hits above body hits, then newest, then id', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-06T10:00:00+07:00');
    $body = agentAnswerInsertPost('11111111-1111-4111-8111-111111111111', 'Bảo dưỡng định kỳ', [
        'published_at' => '2026-09-05 03:00:00',
        'content' => agentAnswerBody('Kiểm tra phanh ABS mỗi tháng.'),
    ]);
    $description = agentAnswerInsertPost('22222222-2222-4222-8222-222222222222', 'Bảo dưỡng xe', [
        'published_at' => '2026-09-04 03:00:00',
        'description' => 'Phanh ABS và lốp.',
    ]);
    $titleOlder = agentAnswerInsertPost('33333333-3333-4333-8333-333333333333', 'Phanh ABS', [
        'published_at' => '2026-09-01 03:00:00',
    ]);
    $titleSameTimeLowerId = agentAnswerInsertPost('44444444-4444-4444-8444-444444444444', 'Phanh chống bó cứng dùng ABS', [
        'published_at' => '2026-09-02 03:00:00',
    ]);
    $titleSameTimeHigherId = agentAnswerInsertPost('55555555-5555-4555-8555-555555555555', 'ABS cho phanh trước', [
        'published_at' => '2026-09-02 03:00:00',
    ]);
    config()->set('content.agent_answers.approved_posts', collect([$body, $description, $titleOlder, $titleSameTimeLowerId, $titleSameTimeHigherId])
        ->mapWithKeys(static fn (Post $post): array => [$post->uuid => str_repeat('a', 64)])->all());

    $uuids = (new PostRepository())->searchAgentEligible('phanh ABS', $asOf, 5)->pluck('uuid')->all();

    expect($uuids)->toBe([
        $titleSameTimeHigherId->uuid,
        $titleSameTimeLowerId->uuid,
        $titleOlder->uuid,
        $description->uuid,
        $body->uuid,
    ]);
});

it('answers natural questions from title, description and body words in any order', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-06T10:00:00+07:00');
    $oil = agentAnswerInsertPost('11111111-1111-4111-8111-111111111111', 'Thay dầu nhớt định kỳ cho xe tay ga', [
        'content' => agentAnswerBody('Nên thay dầu nhớt sau mỗi 1.500 đến 2.000 km.'),
    ]);
    $brake = agentAnswerInsertPost('22222222-2222-4222-8222-222222222222', 'Hệ thống phanh chống bó cứng ABS');
    config()->set('content.agent_answers.approved_posts', [$oil->uuid => str_repeat('a', 64), $brake->uuid => str_repeat('b', 64)]);
    $repository = new PostRepository();

    expect($repository->searchAgentEligible('Thay dầu nhớt sau bao nhiêu km?', $asOf)->pluck('uuid')->all())->toBe([$oil->uuid])
        ->and($repository->searchAgentEligible('sau bao nhiêu km thì thay nhớt', $asOf)->pluck('uuid')->all())->toBe([$oil->uuid])
        ->and($repository->searchAgentEligible('ABS phanh', $asOf)->pluck('uuid')->all())->toBe([$brake->uuid])
        ->and($repository->searchAgentEligible('phanh ABS', $asOf)->pluck('uuid')->all())->toBe([$brake->uuid])
        ->and($repository->searchAgentEligible('he thong phanh hoat dong the nao', $asOf)->pluck('uuid')->all())->toBe([$brake->uuid]);
});

it('handles Vietnamese diacritics while stop words, punctuation, typos and weak overlaps return nothing', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-06T10:00:00+07:00');
    $post = agentAnswerInsertPost(
        '11111111-1111-4111-8111-111111111111',
        'Hệ thống phanh chống bó cứng ABS',
    );
    config()->set('content.agent_answers.approved_posts', [$post->uuid => str_repeat('a', 64)]);
    $repository = new PostRepository();

    expect($repository->searchAgentEligible('hệ thống phanh', $asOf)->pluck('uuid')->all())
        ->toBe([$post->uuid])
        ->and($repository->searchAgentEligible('he thong phanh', $asOf)->pluck('uuid')->all())
        ->toBe([$post->uuid])
        ->and($repository->searchAgentEligible('!!! hệ thống phanh ???', $asOf)->pluck('uuid')->all())
        ->toBe([$post->uuid])
        ->and($repository->searchAgentEligible('hethongg phanhh', $asOf))->toBeEmpty()
        ->and($repository->searchAgentEligible('là gì vậy?', $asOf))->toBeEmpty()
        ->and($repository->searchAgentEligible('phanh xe tay ga điện đời mới', $asOf))->toBeEmpty()
        ->and($repository->searchAgentEligible(" \t\n ", $asOf))->toBeEmpty()
        ->and($repository->searchAgentEligible('...?!—', $asOf))->toBeEmpty();
});

it('returns at most the requested number of posts', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-06T10:00:00+07:00');
    $approved = [];
    foreach (range(1, 7) as $index) {
        $post = agentAnswerInsertPost(sprintf('%08d-0000-4000-8000-%012d', $index, $index), 'Phanh ABS bài ' . $index);
        $approved[$post->uuid] = str_repeat('a', 64);
    }
    config()->set('content.agent_answers.approved_posts', $approved);

    expect((new PostRepository())->searchAgentEligible('phanh ABS', $asOf, 3))->toHaveCount(3)
        ->and((new PostRepository())->searchAgentEligible('phanh ABS', $asOf, 0))->toBeEmpty();
});

it('rechecks every eligibility predicate during second hydration', function (array $mutation): void {
    $asOf = CarbonImmutable::parse('2026-09-06T10:00:00+07:00');
    $post = agentAnswerInsertPost('11111111-1111-4111-8111-111111111111', 'Hệ thống phanh ABS');
    config()->set('content.agent_answers.approved_posts', [$post->uuid => str_repeat('a', 64)]);

    $postReads = 0;
    $firstReadSql = null;
    DB::listen(static function (QueryExecuted $query) use (&$postReads, &$firstReadSql, $post, $mutation): void {
        $sql = strtolower(ltrim($query->sql));
        if (! str_starts_with($sql, 'select') || ! str_contains($sql, 'posts')) {
            return;
        }

        $postReads++;
        if ($postReads !== 1) {
            return;
        }

        $firstReadSql = $sql;
        DB::table('posts')->where('id', $post->id)->update($mutation + [
            'updated_at' => '2026-09-06 09:30:00',
        ]);
    });

    $results = (new PostRepository())->searchAgentEligible('phanh ABS', $asOf, 5);

    expect($postReads)->toBe(2)
        ->and($firstReadSql)->toMatch('/^select\s+(?:["`]?posts["`]?\.)?["`]?id["`]?(?:\s+as\s+["`]?id["`]?)?\s+from\s+/')
        ->and($results)->toBeEmpty();
})->with([
    'status changed to draft' => [['status' => StatusEnum::DRAFT->value]],
    'publication moved beyond as-of time' => [['published_at' => '2026-09-06 10:00:01']],
    'post soft deleted' => [['deleted_at' => '2026-09-06 09:30:00']],
    'type changed outside the corpus' => [['type' => 'page']],
    'language changed outside the request scope' => [['language_id' => 2]],
    'approved UUID changed' => [['uuid' => '99999999-9999-4999-8999-999999999999']],
]);

/** @param array<string, mixed> $overrides */
function agentAnswerInsertPost(string $uuid, string $name, array $overrides = []): Post
{
    $now = '2026-09-06 02:00:00';
    $attributes = array_replace([
        'uuid' => $uuid,
        'name' => $name,
        'description' => 'Mô tả kiểm thử.',
        'content' => json_encode([
            ['type' => 'text', 'value' => '<p>ABS giúp bánh xe không bị bó cứng.</p>'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'type' => 'post',
        'language_id' => 0,
        'status' => StatusEnum::PUBLISHED->value,
        'published_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ], $overrides);
    $id = DB::table('posts')->insertGetId($attributes);

    $post = new Post;
    $post->setRawAttributes(['id' => $id, ...$attributes]);
    $post->exists = true;

    return $post;
}

/** Content JSON with one text block, as the admin stores it. */
function agentAnswerBody(string $text): string
{
    return json_encode([['type' => 'text', 'value' => '<p>' . $text . '</p>']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
