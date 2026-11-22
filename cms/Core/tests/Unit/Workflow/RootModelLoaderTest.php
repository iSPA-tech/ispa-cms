<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Exceptions\InvalidRootModelException;
use Cms\Core\Workflows\Runtime\Exceptions\WorkflowRootNotFoundException;
use Cms\Core\Workflows\Runtime\RootModelLoader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Cms\Core\Tests\TestCase;

class RootModelLoaderTest extends TestCase
{
    public function test_invalid_class_throws_exception(): void
    {
        $loader = new RootModelLoader();

        $this->expectException(InvalidRootModelException::class);

        $loader->load('App\\Models\\NotExists', 1);
    }

    public function test_non_model_class_throws_exception(): void
    {
        $loader = new RootModelLoader();

        $this->expectException(InvalidRootModelException::class);

        $loader->load(DummyNonModel::class, 1);
    }

    public function test_not_found_throws_model_not_found(): void
    {
        $loader = new RootModelLoader();

        $this->expectException(WorkflowRootNotFoundException::class);

        $loader->load(DummyModel::class, 9999);
    }

    public function test_loads_existing_model(): void
    {
        $model = new DummyModel();
        $model->setAttribute($model->getKeyName(), 7);
        $model->exists = true;

        $this->app->instance(DummyModel::class, $model);

        $loader = new RootModelLoader();

        $found = $loader->load(DummyModel::class, 7);

        $this->assertSame(7, $found->getKey());
    }

    public function test_loads_soft_deleted_when_requested(): void
    {
        $model = new DummySoftDeletesModel();
        $model->setAttribute($model->getKeyName(), 3);
        $model->exists = true;
        $model->setAttribute('deleted_at', now());

        $this->app->instance(DummySoftDeletesModel::class, $model);

        $loader = new RootModelLoader();

        $found = $loader->load(DummySoftDeletesModel::class, 3, true);

        $this->assertSame(3, $found->getKey());
    }
}

class DummyNonModel
{
}

class DummyModel extends Model
{
    protected $table = 'dummy_models';
    protected $guarded = [];

    public static function query()
    {
        return new class {
            public function find($id)
            {
                if (app()->bound(DummyModel::class) && app(DummyModel::class)->getKey() === $id) {
                    return app(DummyModel::class);
                }
                return null;
            }
        };
    }
}

class DummySoftDeletesModel extends Model
{
    use SoftDeletes;

    protected $table = 'dummy_soft_models';
    protected $guarded = [];

    public static function query()
    {
        return new class {
            public function withTrashed()
            {
                return $this;
            }

            public function find($id)
            {
                if (app()->bound(DummySoftDeletesModel::class) && app(DummySoftDeletesModel::class)->getKey() === $id) {
                    return app(DummySoftDeletesModel::class);
                }
                return null;
            }
        };
    }
}
