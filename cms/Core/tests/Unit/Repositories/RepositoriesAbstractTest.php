<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\StatusEnum;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function dummyRepositoryModelClass(): string
{
    static $class;

    if (!$class) {
        $class = get_class(new class extends Model {
            use SoftDeletes;

            protected $table = 'dummy_records';

            protected $fillable = [
                'name',
                'content',
                'status',
                'language_id',
            ];
        });
    }

    return $class;
}

test('RepositoriesAbstract setModel and reset manage model instance', function () {
    $repo = new class extends RepositoriesAbstract {};
    $dummyClass = dummyRepositoryModelClass();

    $repo->setModel($dummyClass);

    expect($repo->getModelClass())->toBe($dummyClass);
    expect($repo->table())->toBe('dummy_records');

    $repo->reset();

    expect($repo->model())->toBeInstanceOf($dummyClass);
});

test('RepositoriesAbstract apply builds where clauses', function () {
    $repo = new class extends RepositoriesAbstract {
        public function applyPublic(array $where): void
        {
            $this->apply($where);
        }
    };
    $dummyClass = dummyRepositoryModelClass();

    $repo->setModel($dummyClass);

    $repo->applyPublic([
        'status' => StatusEnum::PUBLISHED->value,
        ['id', 'IN', [1, 2]],
        ['name', 'NOT_IN', ['a', 'b']],
        ['slug', '!=', 'x'],
    ]);

    $wheres = $repo->model()->getQuery()->wheres;
    $types = collect($wheres)->pluck('type')->all();

    expect($types)->toContain('Basic', 'In', 'NotIn');
    expect(collect($wheres)->firstWhere('column', 'slug')['operator'])->toBe('!=');
});

test('RepositoriesAbstract returns empty arrays for invalid updateOrCreate calls', function () {
    $repo = new class extends RepositoriesAbstract {};
    $repo->setModel(dummyRepositoryModelClass());

    expect($repo->updateOrCreate([], ['name' => 'x']))->toBe([]);
    expect($repo->updateOrCreate(['name' => 'x'], []))->toBe([]);
});

test('RepositoriesAbstract returns empty arrays for invalid firstOrCreate calls', function () {
    $repo = new class extends RepositoriesAbstract {};
    $repo->setModel(dummyRepositoryModelClass());

    expect($repo->firstOrCreate([], ['name' => 'x']))->toBe([]);
    expect($repo->firstOrCreate(['name' => 'x'], []))->toBe([]);
});

test('RepositoriesAbstract insert returns 0 for empty data', function () {
    $repo = new class extends RepositoriesAbstract {};
    $repo->setModel(dummyRepositoryModelClass());

    expect($repo->insert([]))->toBe(0);
});

/**
 * Model with local scopes, so the `scopes` option can be tested for real
 * instead of by inspecting the builder.
 */
function scopedRepositoryModelClass(): string
{
    static $class;

    if (!$class) {
        $class = get_class(new class extends Model {
            public $timestamps = false;

            protected $table = 'scoped_records';

            protected $fillable = ['name', 'status'];

            public function scopeOnlyPublished(Builder $query): Builder
            {
                return $query->where('status', 'published');
            }

            public function scopeNamedLike(Builder $query, string $needle): Builder
            {
                return $query->where('name', 'like', $needle . '%');
            }
        });
    }

    return $class;
}

function withScopedRecordsTable(Closure $callback): void
{
    Schema::create('scoped_records', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('name');
        $table->string('status');
    });

    try {
        $callback();
    } finally {
        Schema::dropIfExists('scoped_records');
    }
}

test('RepositoriesAbstract get applies a bare local scope', function () {
    withScopedRecordsTable(function (): void {
        $modelClass = scopedRepositoryModelClass();
        $modelClass::query()->insert([
            ['name' => 'alpha', 'status' => 'published'],
            ['name' => 'beta', 'status' => 'draft'],
        ]);

        $repo = new class extends RepositoriesAbstract {};
        $repo->setModel($modelClass);

        $result = $repo->get(['scopes' => ['onlyPublished']]);

        expect($result->pluck('name')->all())->toBe(['alpha']);
    });
});

test('RepositoriesAbstract get applies a local scope with arguments', function () {
    withScopedRecordsTable(function (): void {
        $modelClass = scopedRepositoryModelClass();
        $modelClass::query()->insert([
            ['name' => 'alpha', 'status' => 'published'],
            ['name' => 'alpine', 'status' => 'draft'],
            ['name' => 'beta', 'status' => 'published'],
        ]);

        $repo = new class extends RepositoriesAbstract {};
        $repo->setModel($modelClass);

        $result = $repo->get(['scopes' => ['namedLike' => ['alp']]]);

        expect($result->pluck('name')->all())->toBe(['alpha', 'alpine']);
    });
});

test('RepositoriesAbstract get without scopes leaves the query untouched', function () {
    withScopedRecordsTable(function (): void {
        $modelClass = scopedRepositoryModelClass();
        $modelClass::query()->insert([
            ['name' => 'alpha', 'status' => 'published'],
            ['name' => 'beta', 'status' => 'draft'],
        ]);

        $repo = new class extends RepositoriesAbstract {};
        $repo->setModel($modelClass);

        expect($repo->get([])->pluck('name')->all())->toBe(['alpha', 'beta']);
    });
});

test('RepositoriesAbstract checkUnique recurses on collision', function () {
    Str::createRandomStringsUsingSequence(['unique-slug']);
    $dummyClass = dummyRepositoryModelClass();

    $repo = new class($dummyClass) extends RepositoriesAbstract {
        public function __construct(private string $dummyClass)
        {
            $this->setModel($this->dummyClass);
        }

        public function findBy(array $where = [], array $select = ['*'], array $with = []): ?object
        {
            return ($where['slug'] ?? null) === 'taken' ? (object)['id' => 1] : null;
        }
    };

    $result = $repo->checkUnique('slug', 'taken');

    expect($result)->toBe('unique-slug');

    Str::createRandomStringsUsing();
});
