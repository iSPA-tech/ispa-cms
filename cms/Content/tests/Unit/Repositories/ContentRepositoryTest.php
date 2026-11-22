<?php

uses(\Tests\TestCase::class);

use Cms\Content\Repositories\ContentRepository;
use Illuminate\Foundation\Http\FormRequest;

function makeContentRepositoryStub(): ContentRepository
{
    return new class extends ContentRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return ['ok'];
        }
    };
}

test('content repository getContents builds query', function () {
    $repo = makeContentRepositoryStub();
    $request = FormRequest::create('/contents', 'GET', ['per_page' => 25]);

    $repo->getContents($request, [3, 5], 'App\\Models\\Thing');

    expect($repo->lastGet['whereIn']['target_id'])->toBe([3, 5]);
    expect($repo->lastGet['where']['target_type'])->toBe('App\\Models\\Thing');
    expect($repo->lastGet['page'])->toBe(25);
    expect($repo->lastGet['latest'])->toBeTrue();
    expect($repo->lastGet['with'])->toHaveKey('target');
    expect($repo->lastGet['with'])->toHaveKey('language');
    expect($repo->lastGet['select'])->toContain('uuid');
    expect($repo->lastGet['when'])->toHaveCount(8);
});

test('content repository getWhenCondition builds filter flags', function () {
    $repo = new ContentRepository();
    $request = FormRequest::create('/contents', 'GET', [
        'query' => 'hello',
        'filter_by' => 'description',
        'user_id' => 12,
        'language_id' => 5,
        'startDate' => '2024-01-01',
        'endDate' => '2024-01-31',
        'type' => 'draft',
    ]);

    $conditions = $repo->getWhenCondition($request);

    expect($conditions)->toHaveCount(8);
    expect($conditions[0][0])->toBeTrue();
    expect($conditions[1][0])->toBeTrue();
    expect($conditions[2][0])->toBeTrue();
    expect($conditions[3][0])->toBe('2024-01-01');
    expect($conditions[4][0])->toBeTrue();
    expect($conditions[5][0])->toBeFalse();
    expect($conditions[6][0])->toBeFalse();
    expect($conditions[7][0])->toBeFalse();
});
