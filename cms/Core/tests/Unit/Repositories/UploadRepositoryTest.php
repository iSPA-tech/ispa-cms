<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Repositories\UploadRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

test('UploadRepository findByCache returns null without required keys', function () {
    $repo = new UploadRepository();

    expect($repo->findByCache([]))->toBeNull();
    expect($repo->findByCache(['id' => 1]))->toBeNull();
    expect($repo->findByCache(['slug' => 'file']))->toBeNull();
});

test('UploadRepository updateAttach updates target id for attachments', function () {
    $repo = new class extends UploadRepository {
        public array $updates = [];

        public function __construct() {}

        public function update(array $condition, array $data): bool
        {
            $this->updates[] = [$condition, $data];

            return true;
        }
    };

    $repo->updateAttach([['id' => 1], ['id' => 2]], (object)['id' => 9]);

    expect($repo->updates)->toHaveCount(1);
    expect($repo->updates[0][0])->toBe([['id', 'IN', [1, 2]]]);
    expect($repo->updates[0][1])->toBe(['target_id' => 9]);
});

test('UploadRepository updateImage uses request images', function () {
    $repo = new class extends UploadRepository {
        public array $updates = [];

        public function __construct() {}

        public function update(array $condition, array $data): bool
        {
            $this->updates[] = [$condition, $data];

            return true;
        }
    };

    $request = Request::create('/', 'POST', [
        'images' => [
            ['id' => 5],
            ['id' => 6],
        ],
    ]);
    $this->app->instance('request', $request);

    $repo->updateImage((object)['id' => 11]);

    expect($repo->updates)->toHaveCount(1);
    expect($repo->updates[0][0])->toBe([['id', 'IN', [5, 6]]]);
    expect($repo->updates[0][1])->toBe(['target_id' => 11]);
});

test('UploadRepository updateLogo uses request logo ids', function () {
    $repo = new class extends UploadRepository {
        public array $updates = [];

        public function __construct() {}

        public function update(array $condition, array $data): bool
        {
            $this->updates[] = [$condition, $data];

            return true;
        }
    };

    $request = Request::create('/', 'POST', [
        'logo' => [
            ['id' => 7],
        ],
    ]);
    $this->app->instance('request', $request);

    $repo->updateLogo((object)['id' => 12]);

    expect($repo->updates)->toHaveCount(1);
    expect($repo->updates[0][0])->toBe([['id', 'IN', [7]]]);
    expect($repo->updates[0][1])->toBe(['target_id' => 12]);
});

test('UploadRepository updateContent collects image ids', function () {
    $repo = new class extends UploadRepository {
        public array $updates = [];

        public function __construct() {}

        public function update(array $condition, array $data): bool
        {
            $this->updates[] = [$condition, $data];

            return true;
        }
    };

    $request = Request::create('/', 'POST', [
        'content' => [
            [
                'type' => 'image',
                'value' => [
                    ['id' => 13],
                    ['id' => 14],
                ],
            ],
            [
                'type' => 'text',
                'value' => [],
            ],
        ],
    ]);
    $this->app->instance('request', $request);

    $repo->updateContent((object)['id' => 15]);

    expect($repo->updates)->toHaveCount(1);
    expect($repo->updates[0][0])->toBe([['id', 'IN', [13, 14]]]);
    expect($repo->updates[0][1])->toBe(['target_id' => 15]);
});

test('UploadRepository getContent replaces image ids with uploads', function () {
    $builder = new class extends Model {
        public array $ids = [];

        public function whereIn($column, $ids)
        {
            $this->ids = $ids;

            return new class($ids) {
                public array $ids;

                public function __construct(array $ids)
                {
                    $this->ids = $ids;
                }

                public function get()
                {
                    return collect(array_map(static fn($id) => (object)['id' => $id], $this->ids));
                }
            };
        }
    };

    $repo = new class($builder) extends UploadRepository {
        public Model $builder;

        public function __construct(Model $builder)
        {
            $this->builder = $builder;
        }

        public function model(): Model|Builder
        {
            return $this->builder;
        }
    };

    $model = (object)[
        'id' => 1,
        'content' => [
            [
                'type' => 'image',
                'value' => [
                    ['id' => 5],
                    ['id' => 6],
                ],
            ],
            [
                'type' => 'text',
                'value' => [],
            ],
        ],
    ];

    $result = $repo->getContent($model);

    expect($builder->ids)->toBe([5, 6]);
    expect($result->content)->toHaveCount(1);
    expect($result->content[0]['value'])->toBeInstanceOf(Collection::class);
    expect($result->content[0]['value']->pluck('id')->toArray())->toBe([5, 6]);
});
