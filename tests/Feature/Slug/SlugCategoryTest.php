<?php

declare(strict_types=1);

use App\Models\Gallery;
use App\Models\Slug;
use Cms\Content\Models\Post;
use Cms\Core\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class)->beforeEach(function (): void {
    DB::table('enumerates')->insertOrIgnore([['enum_key' => 'status_published']]);
    DB::table('languages')->insertOrIgnore(['id' => 1, 'name' => 'Tiếng Việt', 'path' => 'vi', 'for_web' => true, 'default_web' => true]);
});

// two records may share a slug when they live under different URLs; the slug's category says
// which kind of record it points to, and every resolver asks for its kind. The category comes from the record the slug
// belongs to, never from the caller: a post slug takes the post's type (page, post, ...), any other record its kind.
// Before this, only the admin post screen sent a category; a gallery named like a page got a NULL category and the
// same slug, and onnievent.com/to-chuc-su-kien rendered the gallery's slug row instead of the page.

function slugTestPost(string $type): int
{
    return DB::table('posts')->insertGetId([
        'name' => 'Bài ' . $type, 'type' => $type, 'status' => 'status_published', 'language_id' => 1,
        'content' => '[]', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('stores the post type as the category of a post slug, whatever the caller sends', function (): void {
    $pageId = slugTestPost('page');
    $articleId = slugTestPost('post');

    $page = Slug::query()->create(['slug' => 'gioi-thieu', 'target_type' => Post::class, 'target_id' => $pageId, 'language_id' => 1, 'category' => 'gallery']);
    $article = Slug::query()->create(['slug' => 'tin-moi', 'target_type' => Post::class, 'target_id' => $articleId, 'language_id' => 1]);

    expect($page->fresh()->category)->toBe('page')
        ->and($article->fresh()->category)->toBe('post');
});

it('stores the kind of any other record as its slug category', function (): void {
    $gallery = Slug::query()->create(['slug' => 'album', 'target_type' => Gallery::class, 'target_id' => 1, 'language_id' => 1]);
    $category = Slug::query()->create(['slug' => 'danh-muc', 'target_type' => Category::class, 'target_id' => 1, 'language_id' => 1, 'category' => 'page']);

    expect($gallery->fresh()->category)->toBe('gallery')
        ->and($category->fresh()->category)->toBe('category');
});

it('gives the slug saved from a record screen the record kind', function (): void {
    $post = Post::query()->findOrFail(slugTestPost('page'));

    $post->syncSlug(['slug' => 'lien-he', 'language_id' => 1]);

    expect(Slug::query()->where('target_type', Post::class)->where('target_id', $post->id)->value('category'))->toBe('page');
});

it('fills the category of existing slugs from the record they point to', function (): void {
    $pageId = slugTestPost('page');
    $articleId = slugTestPost('post');
    $rows = [
        ['slug' => 'to-chuc-su-kien', 'target_type' => Post::class, 'target_id' => $pageId, 'category' => null],
        ['slug' => 'bai-a', 'target_type' => Post::class, 'target_id' => $articleId, 'category' => ''],
        ['slug' => 'to-chuc-su-kien', 'target_type' => Gallery::class, 'target_id' => 1, 'category' => null],
        ['slug' => 'may-anh', 'target_type' => 'Legacy\\Models\\ProductCategory', 'target_id' => 3, 'category' => null],
        ['slug' => 'giu-nguyen', 'target_type' => Category::class, 'target_id' => 2, 'category' => 'custom'],
    ];
    foreach ($rows as $row) {
        DB::table('slugs')->insert($row + ['language_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    $migration = require base_path('cms/Content/database/migrations/2026_11_21_210000_fill_slug_category_from_target.php');
    $migration->up();
    $migration->up(); // idempotent

    expect(DB::table('slugs')->orderBy('id')->pluck('category')->all())
        ->toBe(['page', 'post', 'gallery', 'product_category', 'custom']);
});
