<?php

declare(strict_types=1);

use App\Enums\StatusEnum;
use Carbon\CarbonImmutable;
use Cms\Content\Services\Agentic\AgentAnswerCorpusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

/*
 * `cms:agent-answer-digests {uuid*}` prints the approved-map entries the corpus service will accept, and
 * refuses anything the corpus would never serve (draft, future, deleted, page, missing, malformed uuid).
 */

beforeEach(function (): void {
    config()->set('ispa-cms.skip_languages_query', true);
    DB::table('languages')->insertOrIgnore(['id' => 0, 'name' => 'Tiếng Việt', 'code' => 'vi', 'path' => 'vi']);
    foreach ([StatusEnum::PUBLISHED->value, StatusEnum::DRAFT->value] as $status) {
        DB::table('enumerates')->insertOrIgnore(['name' => $status, 'enum_key' => $status, 'type' => 'status']);
    }
    $this->asOf = CarbonImmutable::now();
});

/** Insert one post and return its uuid. */
function digestPost(int $index, string $title, array $overrides = []): string
{
    $uuid = sprintf('%08d-0000-4000-8000-%012d', $index, $index);
    $id = DB::table('posts')->insertGetId([
        'uuid' => $uuid,
        'name' => $title,
        'description' => null,
        'content' => json_encode([['type' => 'text', 'value' => $title . '.']], JSON_UNESCAPED_UNICODE),
        'type' => 'post',
        'language_id' => 0,
        'status' => StatusEnum::PUBLISHED->value,
        'published_at' => now()->subDay()->format('Y-m-d H:i:s'),
        'created_at' => '2026-08-01 01:00:00',
        'updated_at' => '2026-09-05 01:00:00',
        'deleted_at' => null,
    ]);
    if ($overrides !== []) {
        DB::table('posts')->where('id', $id)->update($overrides);
    }

    return $uuid;
}

/** Run the command; returns [exit code, stdout, stderr]. */
function runDigests(array $uuids): array
{
    $out = new Symfony\Component\Console\Output\BufferedOutput();
    $code = Artisan::call('cms:agent-answer-digests', ['uuid' => $uuids], $out);

    return [$code, trim($out->fetch())];
}

it('prints one JSON object of uuid to digest that the corpus accepts as an approval', function (): void {
    $a = digestPost(1, 'Thay dầu nhớt định kỳ');
    $b = digestPost(2, 'Kiểm tra hệ thống phanh');

    [$code, $out] = runDigests([$a, strtoupper($b), $a]);

    $map = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    expect($code)->toBe(0)
        ->and(array_keys($map))->toBe([$a, $b])
        ->and($out)->not->toContain("\n");
    foreach ($map as $digest) {
        expect($digest)->toMatch('/\A[0-9a-f]{64}\z/');
    }

    // Approving exactly what was printed makes both posts reach the corpus (digests computed as the corpus does).
    config()->set('content.agent_answers.approved_posts', $map);
    $corpus = app(AgentAnswerCorpusService::class);
    expect(array_column($corpus->retrieve('Thay dầu nhớt', $this->asOf, 5), 'id'))->toBe(['urn:ispa:content:' . $a])
        ->and(array_column($corpus->retrieve('hệ thống phanh', $this->asOf, 5), 'id'))->toBe(['urn:ispa:content:' . $b]);
});

it('gives the same digest after a change that is not part of the projection', function (): void {
    $a = digestPost(1, 'Thay dầu nhớt định kỳ');
    [, $before] = runDigests([$a]);
    DB::table('posts')->where('uuid', $a)->update(['updated_at' => '2026-11-01 09:00:00']);
    [, $after] = runDigests([$a]);
    DB::table('posts')->where('uuid', $a)->update(['name' => 'Thay dầu nhớt (sửa)']);
    [, $changed] = runDigests([$a]);

    expect($after)->toBe($before)->and($changed)->not->toBe($before);
});

it('refuses drafts, pages, future, deleted, missing and malformed uuids and prints no map at all', function (string $case): void {
    $good = digestPost(1, 'Bài tốt');
    $bad = match ($case) {
        'draft' => digestPost(2, 'Bản nháp', ['status' => StatusEnum::DRAFT->value]),
        'page' => digestPost(2, 'Trang', ['type' => 'page']),
        'future' => digestPost(2, 'Tương lai', ['published_at' => now()->addDay()->format('Y-m-d H:i:s')]),
        'deleted' => digestPost(2, 'Đã xoá', ['deleted_at' => '2026-09-03 01:00:00']),
        'missing' => '99999999-0000-4000-8000-000000000099',
        'malformed' => 'not-a-uuid',
    };

    [$code, $out] = runDigests([$good, $bad]);

    expect($code)->toBe(1)->and($out)->not->toContain('{')->and($out)->not->toContain($good . '"');
})->with(['draft', 'page', 'future', 'deleted', 'missing', 'malformed']);

it('fails without any uuid', function (): void {
    [$code, $out] = runDigests([]);

    expect($code)->toBe(1)->and($out)->not->toContain('{');
});
