<?php

declare(strict_types=1);

use Cms\Content\Http\Controllers\V3\Public\TagController as PublicTagController;
use App\Services\AdminUpdateContentService;
use Cms\Content\Http\Controllers\V2\Admin\TagController as AdminTagController;
use Cms\Content\Interfaces\TagInterface;
use Cms\Content\Models\Post;
use Cms\Content\Repositories\TagRepository;
use Cms\Content\Services\Cache\ContentPublicTagCacheService;
use Cms\Core\Models\Tag;
use Cms\Core\Models\TagData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Cache;

uses(\Tests\TestCase::class, RefreshDatabase::class);

/** One post tag and one user tag with different slugs. */
function seedPostAndUserTag(): array
{
    return [
        Tag::query()->create(['name' => 'Xe', 'slug' => 'xe']),
        Tag::query()->create(['name' => 'Niên khóa 2024', 'slug' => 'nien-khoa-2024', 'type' => Tag::TYPE_USER]),
    ];
}

/** Sign in as an admin who may list tags: since 2026-11-21 the admin tag API answers a permission error without `tag.viewAny`. */
function userTagsActingAsTagViewer(): void
{
    $role = Role::query()->create(['name' => 'Tag viewer']);
    $permission = Permission::query()->firstOrCreate(['group' => 'tag', 'name' => 'viewAny'], ['title' => 'viewAny']);
    $role->permissions()->attach($permission->id);
    $user = User::forceCreate([
        'name' => 'Tag Viewer', 'email' => 'tag-viewer@test.local', 'password' => bcrypt('secret'), 'type' => 0,
    ]);
    $user->roles()->attach($role->id);
    Sanctum::actingAs($user);
}

test('the Content tag repository only sees and creates post tags', function () {
    [$post, $user] = seedPostAndUserTag();
    $repo = new TagRepository();

    expect($repo->get(['latest' => true])->pluck('slug')->all())->toBe(['xe']);
    expect($repo->find($user->id))->toBeNull();
    expect($repo->find($post->id)?->slug)->toBe('xe');
    expect($repo->create(['name' => 'Moi', 'slug' => 'moi'])->type)->toBe(Tag::TYPE_POST);
    expect($repo->table())->toBe('tags');
});

test('the admin tag list/search endpoint and the post tag picker never return user tags', function () {
    seedPostAndUserTag();
    userTagsActingAsTagViewer();
    $controller = new AdminTagController(app(TagInterface::class));

    foreach (['', 'nien', 'Niên'] as $keywords) {
        request()->merge(['keywords' => $keywords]);
        $slugs = collect($controller->search()->toResponse(request())->getData(true)['data']['data'] ?? [])->pluck('slug')->all();
        expect($slugs)->not->toContain('nien-khoa-2024');
    }
    request()->merge(['keywords' => '']);
    $all = collect($controller->index()->toResponse(request())->getData(true)['data'] ?? [])->pluck('slug')->all();
    expect($all)->toBe(['xe']);
});

test('Post::tags and the tag sync ignore user tags', function () {
    [$post, $user] = seedPostAndUserTag();
    \Cms\Core\Models\Language::withoutEvents(static fn () => \Cms\Core\Models\Language::query()->create(["name" => "Tieng Viet", "path" => "vi", "code" => "vi", "status" => "published"]));
    \Illuminate\Support\Facades\DB::table("enumerates")->insert(["name" => "Published", "enum_key" => "status_published", "type" => "status", "created_at" => now(), "updated_at" => now()]);
    $content = Post::withoutEvents(static fn () => Post::query()->forceCreate(['name' => 'P', 'type' => 'post', 'status' => 'status_published', 'language_id' => 1]));
    foreach ([$post, $user] as $tag) {
        TagData::query()->create(['target_type' => Post::class, 'target_id' => $content->id, 'tag_id' => $tag->id]);
    }

    expect($content->tags()->pluck('slug')->all())->toBe(['xe']);

    app(AdminUpdateContentService::class)->syncTag(Request::create('/', 'POST', ['tag_id' => [$post->id, $user->id]]), $content);
    expect(TagData::query()->where('target_id', $content->id)->pluck('tag_id')->all())->toBe([$post->id]);
});

test('the public tag page does not resolve a user tag', function () {
    seedPostAndUserTag();
    Cache::flush();
    $controller = new PublicTagController(app(TagInterface::class), app(ContentPublicTagCacheService::class));
    $request = Request::create('/api/v3/tag/nien-khoa-2024', 'GET');

    expect($controller->show('nien-khoa-2024', $request)->toResponse($request)->getStatusCode())->toBe(404);
    $request = Request::create('/api/v3/tag/xe', 'GET');
    expect($controller->show('xe', $request)->toResponse($request)->getData(true)['status'])->toBe('success');
});
