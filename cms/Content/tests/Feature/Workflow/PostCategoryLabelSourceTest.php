<?php

declare(strict_types=1);

/*
 * The Content preset `post_categories` (classify labels, source `content.categories`): the site's post categories
 * read through the Core category repository, keyed by uuid, merged into the designer presets.
 */

use Cms\Content\Services\Workflow\PostCategoryLabelSource;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Models\Category;
use Cms\Core\Services\Workflow\Presets\WorkflowPresetCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(\Tests\TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach ([\App\Enums\StatusEnum::DRAFT->value, \App\Enums\StatusEnum::PUBLISHED->value] as $key) {
        DB::table('enumerates')->insertOrIgnore(['enum_key' => $key, 'type' => 'status', 'name' => $key]);
    }
    DB::table('languages')->insertOrIgnore(['id' => 1, 'name' => 'Vietnamese', 'code' => 'vi', 'path' => 'vi', 'default_web' => true]);
});

it('lists the post categories the repository returns as {key: uuid, label: name}', function (): void {
    $news = Category::query()->create(['name' => 'Tin tức', 'type' => 0, 'language_id' => 1]);
    $news->forceFill(['uuid' => (string) Str::uuid()])->save();
    $events = Category::query()->create(['name' => 'Sự kiện', 'type' => 0, 'language_id' => 1]);
    $events->forceFill(['uuid' => (string) Str::uuid()])->save();

    $repository = Mockery::mock(CategoryInterface::class);
    $repository->shouldReceive('getCategoriesByType')->with('post')->andReturn(collect([
        (object) ['id' => $news->id, 'name' => 'Tin tức'],
        (object) ['id' => $events->id, 'name' => 'Sự kiện'],
    ]));
    app()->instance(CategoryInterface::class, $repository);

    $set = app(WorkflowPresetCatalog::class)->find('post_categories');

    expect($set)->toMatchArray(['module' => 'content', 'source' => 'content.categories'])
        ->and($set['items'])->toBe([
            ['key' => $news->fresh()->uuid, 'label' => 'Tin tức'],
            ['key' => $events->fresh()->uuid, 'label' => 'Sự kiện'],
        ])
        ->and(app(PostCategoryLabelSource::class))->toBeInstanceOf(\Cms\Core\Services\Workflow\Presets\WorkflowPresetSource::class);
});
