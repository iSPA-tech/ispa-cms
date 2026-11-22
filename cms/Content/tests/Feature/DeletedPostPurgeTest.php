<?php

use App\Constants\Tables;
use Cms\Content\Models\Post;
use Cms\Content\Services\DeletedPostPurgeService;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Upload;
use App\Jobs\RemoveDeletedUploadFilesJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(\Tests\TestCase::class);
uses(RefreshDatabase::class);

function deletedPostForPurge(int $daysAgo): Post
{
    return Post::withoutEvents(static function () use ($daysAgo): Post {
        $post = Post::query()->create([
            'uuid' => (string) str()->uuid(),
            'name' => 'Deleted post',
            'type' => 'post',
            'status' => null,
        ]);

        $post->forceFill(['deleted_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $post;
    });
}

test('purges old deleted posts and orphaned media while preserving reused uploads', function () {
    Storage::fake('public');

    $deletedPost = deletedPostForPurge(31);
    $youngPost = deletedPostForPurge(29);
    $livePost = Post::withoutEvents(static fn (): Post => Post::query()->create([
        'uuid' => (string) str()->uuid(),
        'name' => 'Live post',
        'type' => 'post',
        'status' => null,
    ]));

    $orphan = Upload::withoutEvents(static fn (): Upload => Upload::query()->create([
        'disk' => 'public',
        'type' => 'image',
        'mime_type' => 'image/jpeg',
        'path' => 'image/2026/07',
        'name' => 'orphan.jpg',
    ]));
    $shared = Upload::withoutEvents(static fn (): Upload => Upload::query()->create([
        'disk' => 'public',
        'type' => 'image',
        'mime_type' => 'image/jpeg',
        'path' => 'image/2026/07',
        'name' => 'shared.jpg',
    ]));

    Storage::disk('public')->put('image/2026/07/orphan.jpg', 'orphan');
    Storage::disk('public')->put('image/2026/07/orphan.jpg.100x100.webp', 'orphan variant');
    Storage::disk('public')->put('image/2026/07/shared.jpg', 'shared');
    Storage::disk('public')->put('image/2026/07/shared.jpg.100x100.webp', 'shared variant');

    DB::table(CoreTableConstant::UPLOAD_TARGET_PIVOT)->insert([
        ['upload_id' => $orphan->id, 'target_id' => $deletedPost->id, 'target_type' => Post::class, 'category' => 'images', 'main' => true, 'order' => 0],
        ['upload_id' => $shared->id, 'target_id' => $deletedPost->id, 'target_type' => Post::class, 'category' => 'images', 'main' => true, 'order' => 0],
        ['upload_id' => $shared->id, 'target_id' => $livePost->id, 'target_type' => Post::class, 'category' => 'images', 'main' => true, 'order' => 0],
    ]);

    $deletedPost->slugs()->create(['slug' => 'deleted-post', 'language_id' => null]);
    $deletedPost->seos()->create(['title' => 'Deleted post SEO', 'language_id' => null]);
    AuditLog::query()->create(['action' => 'delete', 'target_type' => Post::class, 'target_id' => $deletedPost->id]);

    Artisan::call('content:purge-deleted-posts', ['--days' => 30, '--limit' => 50, '--apply' => true]);

    expect(Post::withTrashed()->find($deletedPost->id))->toBeNull()
        ->and(Post::withTrashed()->find($youngPost->id))->not->toBeNull()
        ->and(Upload::find($orphan->id))->toBeNull()
        ->and(Upload::find($shared->id))->not->toBeNull()
        ->and(Storage::disk('public')->exists('image/2026/07/orphan.jpg'))->toBeFalse()
        ->and(Storage::disk('public')->exists('image/2026/07/orphan.jpg.100x100.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('image/2026/07/shared.jpg'))->toBeTrue()
        ->and(DB::table(Tables::SLUGS)->where('target_type', Post::class)->where('target_id', $deletedPost->id)->exists())->toBeFalse()
        ->and(DB::table(Tables::SEOS)->where('target_type', Post::class)->where('target_id', $deletedPost->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('target_type', Post::class)->where('target_id', $deletedPost->id)->exists())->toBeFalse();
});

test('purge command reports candidates without deleting in dry run', function () {
    $post = deletedPostForPurge(31);

    Artisan::call('content:purge-deleted-posts', ['--days' => 30, '--limit' => 50]);

    expect(Post::withTrashed()->find($post->id))->not->toBeNull()
        ->and(Artisan::output())->toContain('Dry-run');
});

test('purges only explicitly selected posts and their orphaned media', function () {
    Storage::fake('public');

    $selectedPost = Post::withoutEvents(static fn (): Post => Post::query()->create([
        'uuid' => (string) str()->uuid(),
        'name' => 'Selected archived post',
        'type' => 'post',
        'status' => null,
    ]));
    $otherOldPost = deletedPostForPurge(60);
    $upload = Upload::withoutEvents(static fn (): Upload => Upload::query()->create([
        'disk' => 'public',
        'type' => 'image',
        'mime_type' => 'image/jpeg',
        'path' => 'image/2026/07',
        'name' => 'selected.jpg',
    ]));

    Storage::disk('public')->put('image/2026/07/selected.jpg', 'selected');
    Storage::disk('public')->put('image/2026/07/selected.jpg.100x100.webp', 'selected variant');
    DB::table(CoreTableConstant::UPLOAD_TARGET_PIVOT)->insert([
        'upload_id' => $upload->id,
        'target_id' => $selectedPost->id,
        'target_type' => Post::class,
        'category' => 'images',
        'main' => true,
        'order' => 0,
    ]);

    $result = app(DeletedPostPurgeService::class)->purgeSelected(
        postIds: [$selectedPost->id],
        apply: true,
    );

    expect($result['candidate_count'])->toBe(1)
        ->and($result['purged'])->toBe(1)
        ->and($result['upload_purged'])->toBe(1)
        ->and(Post::withTrashed()->find($selectedPost->id))->toBeNull()
        ->and(Post::withTrashed()->find($otherOldPost->id))->not->toBeNull()
        ->and(Upload::withTrashed()->find($upload->id))->toBeNull()
        ->and(Storage::disk('public')->exists('image/2026/07/selected.jpg'))->toBeFalse()
        ->and(Storage::disk('public')->exists('image/2026/07/selected.jpg.100x100.webp'))->toBeFalse();
});

test('can defer image cleanup to a project owner while retaining upload identity', function () {
    Storage::fake('public');
    $post = deletedPostForPurge(31);
    $image = Upload::withoutEvents(static fn (): Upload => Upload::query()->create([
        'disk' => 'public',
        'type' => 'image',
        'path' => 'image/2026/07',
        'name' => 'deferred.jpg',
    ]));
    Storage::disk('public')->put('image/2026/07/deferred.jpg', 'image');
    DB::table(CoreTableConstant::UPLOAD_TARGET_PIVOT)->insert([
        'upload_id' => $image->id,
        'target_id' => $post->id,
        'target_type' => Post::class,
        'category' => 'images',
        'main' => true,
        'order' => 0,
    ]);

    $result = app(DeletedPostPurgeService::class)->purge(days: 30, apply: true, deferImages: true);

    expect($result['deferred_image_ids'])->toBe([$image->id])
        ->and(Post::withTrashed()->find($post->id))->toBeNull()
        ->and(Upload::withTrashed()->find($image->id))->not->toBeNull()
        ->and(Storage::disk('public')->exists('image/2026/07/deferred.jpg'))->toBeTrue();
});

test('legacy deleted upload cleanup keeps recent and reused uploads', function () {
    Storage::fake('public');

    $recent = Upload::withoutEvents(static fn (): Upload => Upload::query()->create([
        'disk' => 'public',
        'type' => 'image',
        'path' => 'image/2026/07',
        'name' => 'recent.jpg',
    ]));
    $reused = Upload::withoutEvents(static fn (): Upload => Upload::query()->create([
        'disk' => 'public',
        'type' => 'image',
        'path' => 'image/2026/07',
        'name' => 'reused.jpg',
    ]));
    $recent->forceFill(['deleted_at' => now()->subDays(29)])->saveQuietly();
    $reused->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();
    $post = deletedPostForPurge(1);

    DB::table(CoreTableConstant::UPLOAD_TARGET_PIVOT)->insert([
        'upload_id' => $reused->id,
        'target_id' => $post->id,
        'target_type' => Post::class,
        'category' => 'images',
        'main' => true,
        'order' => 0,
    ]);
    Storage::disk('public')->put('image/2026/07/recent.jpg', 'recent');
    Storage::disk('public')->put('image/2026/07/reused.jpg', 'reused');

    app(RemoveDeletedUploadFilesJob::class)->handle();

    expect(Upload::withTrashed()->find($recent->id))->not->toBeNull()
        ->and(Upload::withTrashed()->find($reused->id))->not->toBeNull()
        ->and(Storage::disk('public')->exists('image/2026/07/recent.jpg'))->toBeTrue()
        ->and(Storage::disk('public')->exists('image/2026/07/reused.jpg'))->toBeTrue();
});
