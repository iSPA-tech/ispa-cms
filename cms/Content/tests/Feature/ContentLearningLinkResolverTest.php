<?php

declare(strict_types=1);

namespace Cms\Content\Tests\Feature;

use Cms\Content\Models\Post;
use Cms\Core\Models\Category;
use Cms\Core\Models\Language;
use App\Models\Slug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ContentLearningLinkResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_only_a_published_current_language_post_with_a_reachable_category_route(): void
    {
        Route::get('/{cate}/{slug}', static fn () => '')->name('article.vi');
        Language::withoutEvents(static fn (): Language => Language::query()->create(['name' => 'Tiếng Việt', 'path' => 'vi', 'code' => 'vi', 'status' => 'published']));
        DB::table('enumerates')->insert(['name' => 'Published', 'enum_key' => 'status_published', 'type' => 'status', 'created_at' => now(), 'updated_at' => now()]);
        $category = Category::query()->create(['name' => 'Đức tin', 'slug' => 'duc-tin', 'type' => 0, 'language_id' => 1]);
        Slug::query()->create(['slug' => 'duc-tin', 'target_id' => $category->id, 'target_type' => Category::class, 'language_id' => 1]);
        $post = Post::withoutEvents(static fn (): Post => Post::query()->create([
            'uuid' => '00000000-0000-4000-8000-000000038001',
            'name' => 'Bài viết',
            'type' => 'post',
            'category_id' => $category->id,
            'language_id' => 1,
            'status' => 'status_published',
            'published_at' => now()->subMinute(),
        ]));
        Slug::query()->create(['slug' => 'bai-viet', 'target_id' => $post->id, 'target_type' => Post::class, 'language_id' => 1]);

        $resolverClass = 'Cms\\Content\\Services\\ContentLearningLinkResolver';
        self::assertTrue(class_exists($resolverClass), 'Learning-link resolver is missing.');

        $resolved = app($resolverClass)->resolve($post->uuid, 'Communication', null);

        self::assertSame('Bài viết', $resolved['title']);
        self::assertSame(route('article.vi', ['cate' => 'duc-tin', 'slug' => 'bai-viet']), $resolved['url']);
    }
}
