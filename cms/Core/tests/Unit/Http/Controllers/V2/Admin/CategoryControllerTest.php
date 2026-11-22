<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Http\Request;
use Cms\Core\Models\Category;
use App\Services\SlugService;
use Cms\Core\Repositories\Interfaces\SeoInterface;
use Cms\Core\Repositories\Interfaces\SlugInterface;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Http\Controllers\V2\Admin\CategoryController;

test('CategoryController::fill populates model and sets uid for new model', function () {
    $req = Request::create('/', 'POST', [
        'name' => 'My Category',
        'config' => ['a' => 1],
        'featured' => '1',
        'parent_id' => [1, 2, 3],
        'description' => 'Desc',
        'language_id' => 5,
        'status' => 'published',
        'type' => 0,
    ]);
    $this->app->instance('request', $req);

    $categoryRepo = $this->createMock(CategoryInterface::class);
    $categoryRepo->method('model')->willReturn(new Category());
    $categoryRepo->method('checkUnique')->willReturn('ABC123');

    $controller = new CategoryController(
        $categoryRepo,
        $this->createMock(SlugInterface::class),
        $this->createMock(SeoInterface::class),
        $this->createMock(UploadInterface::class),
        $this->createMock(SlugService::class)
    );

    $ref = new ReflectionClass($controller);
    $method = $ref->getMethod('fill');
    $method->setAccessible(true);
    /** @var Category $model */
    $model = $method->invoke($controller, null);

    expect($model)->toBeInstanceOf(Category::class);
    expect($model->name)->toBe('My Category');
    expect($model->config)->toBe(['a' => 1]);
    expect($model->featured)->toBeTrue();
    expect($model->parent_id)->toBe(3);
    expect($model->description)->toBe('Desc');
    expect($model->language_id)->toBe(5);
    expect($model->status)->toBe('published');
    expect($model->type)->toBe(0);
    expect($model->uid)->toBe('ABC123');
});

test('CategoryController::fill does not change uid for existing model', function () {
    $req = Request::create('/', 'POST', [
        'name' => 'Updated Name',
        'config' => null,
        'featured' => false,
        'parent_id' => [10],
        'description' => null,
        'language_id' => 0, // should become null
        'status' => 'draft',
        'type' => 2,
    ]);
    $this->app->instance('request', $req);

    $existing = new Category();
    $existing->id = 42; // simulate persisted
    $existing->uid = 'PRESET-UID';

    $categoryRepo = $this->createMock(CategoryInterface::class);
    $categoryRepo->method('model')->willReturn(new Category());
    $categoryRepo->method('checkUnique')->willReturn('SHOULD-NOT-BE-USED');

    $controller = new CategoryController(
        $categoryRepo,
        $this->createMock(SlugInterface::class),
        $this->createMock(SeoInterface::class),
        $this->createMock(UploadInterface::class),
        $this->createMock(SlugService::class)
    );

    $ref = new ReflectionClass($controller);
    $method = $ref->getMethod('fill');
    $method->setAccessible(true);
    /** @var Category $model */
    $model = $method->invoke($controller, $existing);

    expect($model->name)->toBe('Updated Name');
    expect($model->config)->toBeNull();
    expect($model->featured)->toBeFalse();
    expect($model->parent_id)->toBe(10);
    expect($model->description)->toBeNull();
    expect($model->language_id)->toBeNull();
    expect($model->status)->toBe('draft');
    expect($model->type)->toBe(2);
    // uid remains unchanged for existing model
    expect($model->uid)->toBe('PRESET-UID');
});

test('CategoryController::multi flattens tree with mapped names', function () {
    $list = [
        ['id' => 1, 'name' => 'Root A', 'parent_id' => null],
        ['id' => 2, 'name' => 'Child A1', 'parent_id' => 1],
        ['id' => 3, 'name' => 'Child A2', 'parent_id' => 1],
        ['id' => 4, 'name' => 'Sub A2-1', 'parent_id' => 3],
        ['id' => 5, 'name' => 'Root B', 'parent_id' => null],
    ];

    $categoryRepo = $this->createMock(CategoryInterface::class);
    $controller = new CategoryController(
        $categoryRepo,
        $this->createMock(SlugInterface::class),
        $this->createMock(SeoInterface::class),
        $this->createMock(UploadInterface::class),
        $this->createMock(SlugService::class)
    );

    $ref = new ReflectionClass($controller);
    $method = $ref->getMethod('multi');
    $method->setAccessible(true);
    $result = $method->invoke($controller, $list);

    // Expect ROOT + 5 items
    expect($result)->toBeArray()->toHaveCount(6);
    // Check order and mapped_name indentation
    $names = array_column($result, 'name');
    expect($names[0])->toBe('ROOT');
    expect($names)->toContain('Root A')->toContain('Child A1')->toContain('Child A2')->toContain('Sub A2-1')->toContain('Root B');

    $mapped = collect($result)->keyBy('id');
    expect($mapped[1]['mapped_name'])->toBe(' Root A');
    expect($mapped[2]['mapped_name'])->toBe(' |-- Child A1');
    expect($mapped[3]['mapped_name'])->toBe(' |-- Child A2');
    expect($mapped[4]['mapped_name'])->toBe(' |-- |-- Sub A2-1');
});

