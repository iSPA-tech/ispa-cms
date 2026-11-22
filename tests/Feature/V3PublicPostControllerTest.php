<?php

namespace Tests\Feature;

use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use Cms\Content\Interfaces\PostInterface;
use App\Http\Middleware\ApiLocalization;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;
use Tests\Traits\WithApiVerifyHeader;

uses(TestCase::class, MockeryPHPUnitIntegration::class, WithApiVerifyHeader::class);

beforeEach(function () {
    $this->withoutMiddleware([
        HttpsProtocol::class,
        Localization::class,
        ApiLocalization::class,
    ]);
});

test('v3 post all accepts pageSize and uses page cursor', function () {
    $postRepository = Mockery::mock(PostInterface::class);

    $postRepository->shouldReceive('get')
        ->once()
        ->with(Mockery::on(function (array $payload): bool {
            $whereClosures = array_filter($payload['where'], fn ($value): bool => $value instanceof Closure);

            foreach ($whereClosures as $closure) {
                $query = new class {
                    public bool $whereCalled = false;
                    public bool $orWhereNullCalled = false;

                    public function where(): self
                    {
                        $this->whereCalled = true;

                        return $this;
                    }

                    public function orWhereNull(): self
                    {
                        $this->orWhereNullCalled = true;

                        return $this;
                    }
                };

                $result = $closure($query);

                if ($result !== $query || !$query->whereCalled || !$query->orWhereNullCalled) {
                    return false;
                }
            }

            //  `images` is deliberately absent: withImage() loads the singular
            //  relation, so preparePostListImage() always takes that branch and
            //  unsets the collection before serializing. Loading it cost a
            //  query and a full Upload hydration per row, discarded.
            expect($payload['with'])->not->toContain('images');

            return isset($payload['page'])
                && $payload['page'] === 100
                && ($payload['latest'] ?? false)
                && in_array('latestSlug', $payload['with'], true);
        }))
        ->andReturn(buildPostPaginator());

    $this->app->instance(PostInterface::class, $postRepository);

    $response = $this->withHeaders($this->withApiVerify())
        ->getJson('/api/v3/post/all?page=17&pageSize=100')
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect($response->json('data.current_page'))->toBe(17);
});

function buildPostPaginator(): LengthAwarePaginator
{
    return new LengthAwarePaginator(
        collect([
            ['id' => 1, 'name' => 'Post 1'],
            ['id' => 2, 'name' => 'Post 2'],
        ]),
        200,
        100,
        17,
        ['path' => '/api/v3/post/all']
    );
}

test('v3 post all batches the R2 ledger probe and drops the duplicate slug load', function () {
    $postRepository = Mockery::mock(PostInterface::class);

    $postRepository->shouldReceive('get')
        ->once()
        ->with(Mockery::on(function (array $payload): bool {
            //  withImage() is what registers the afterQuery hook that answers
            //  every R2 ledger probe in one query. Without it the image
            //  accessor ran one EXISTS per image path: 172 of this endpoint's
            //  182 production queries.
            expect($payload['scopes'] ?? [])->toContain('withImage');

            //  withTotalView() must NOT be here. preparePostListImage() narrows
            //  the appends to slug, has_video, published_date and image, so
            //  `total_view` never reaches the response and its batched SUM over
            //  `tracks` would be a query paid for and thrown away.
            expect($payload['scopes'] ?? [])->not->toContain('withTotalView');

            //  The slug accessor reads the projected alias and falls back to
            //  latestSlug, never the full collection — eager loading `slugs`
            //  bought one query and 8 KB of duplicate rows in the response.
            expect($payload['with'])->not->toContain('slugs:target_id,slug');
            expect($payload['with'])->toContain('latestSlug');

            //  Same reasoning for the plural relation: withImage() loads the
            //  singular one, and preparePostListImage() unsets this before
            //  serializing.
            expect($payload['with'])->not->toContain('images');

            return true;
        }))
        ->andReturn(buildPostPaginator());

    $this->app->instance(PostInterface::class, $postRepository);

    $this->withHeaders($this->withApiVerify())
        ->getJson('/api/v3/post/all')
        ->assertOk();
});

test('v3 post all image payload is limited to what a card renders', function () {
    //  `config` alone was 1.4 KB per row of post-level settings that have
    //  nothing to do with a thumbnail, and the image object was 83% of a
    //  393 KB response. Locking the list keeps a future column off the wire.
    $visible = (new \ReflectionClass(\Cms\Content\Http\Controllers\V3\Public\PostController::class))
        ->getConstant('PUBLIC_LIST_IMAGE_VISIBLE');

    expect($visible)->toBe([
        'id',
        'name',
        'alt_text',
        'dimensions',
        'thumbnail_sm',
        'cover_sm',
        'sm',
        'cover_md',
        'cover_lg',
        'cover_xl',
    ]);

    foreach (['config', 'custom', 'exif', 'path', 'disk', 'mime_type', 'file_size', 'r2_synced_at'] as $column) {
        expect($visible)->not->toContain($column);
    }
});

test('v3 post all keyword search decorates previews without a 500', function () {
    $postRepository = Mockery::mock(PostInterface::class);

    // The filtered branch goes through advancedSearch AND
    // decoratePostPreviewImages — which used to live only on
    // CategoryController and 500'd every keyword search.
    $postRepository->shouldReceive('advancedSearch')
        ->once()
        ->with(Mockery::on(fn (array $filter): bool => ($filter['keyword'] ?? '') === 'Buick Roadmaster Series 36-80'
            && $filter['pageSize'] === 4))
        ->andReturn(buildPostPaginator());

    $this->app->instance(PostInterface::class, $postRepository);

    $this->withHeaders($this->withApiVerify())
        ->getJson('/api/v3/post/all?pageSize=4&keyword=Buick+Roadmaster+Series+36-80')
        ->assertOk()
        ->assertJsonPath('status', 'success');
});
