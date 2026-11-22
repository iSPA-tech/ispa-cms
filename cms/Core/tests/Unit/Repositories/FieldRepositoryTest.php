<?php

uses(\Cms\Core\Tests\TestCase::class);

use App\Enums\StatusEnum;
use Cms\Core\Helpers\CacheHelper;
use Cms\Core\Repositories\FieldRepository;

test('FieldRepository getFieldByFormId builds expected query', function () {
    cache()->flush();

    $cacheHelper = new class extends CacheHelper {
        public array $stored = [];

        public function store(string $cacheKey, string $title, int $ttl = 0): void
        {
            $this->stored = [$cacheKey, $title, $ttl];
        }
    };

    CacheHelper::swap($cacheHelper);

    // Since c20845fbd the query runs on model() and accepts both the enum and
    // the legacy `published` status, so the builder calls are asserted here.
    $builder = Mockery::mock(\Illuminate\Database\Eloquent\Builder::class);
    $builder->shouldReceive('where')->once()->with('form_id', 'form-1')->andReturnSelf();
    $builder->shouldReceive('whereIn')->once()
        ->with('status', [StatusEnum::PUBLISHED->value, 'published'])->andReturnSelf();
    $builder->shouldReceive('with')->once()
        ->with(Mockery::on(fn (array $with) => array_key_exists('items', $with)))->andReturnSelf();
    $builder->shouldReceive('first')->once()->andReturn((object) ['id' => 1]);

    $repo = new class($builder) extends FieldRepository {
        public function __construct(private $fakeBuilder) {}

        public function model(): \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Builder
        {
            return $this->fakeBuilder;
        }
    };

    expect($repo->getFieldByFormId('form-1', true))->toEqual((object) ['id' => 1]);
    expect($cacheHelper->stored[1])->toBe('Form field form-1');
});
