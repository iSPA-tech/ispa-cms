<?php

use App\Models\Place;
use Cms\Content\Models\Post;
use Cms\Core\Models\UploadR2Object;
use Cms\Core\Services\UploadR2SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

it('uploads bytes from a consistent snapshot so the recorded hash always matches what was actually uploaded', function () {
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'original-bytes-at-queue-time');

    $object = UploadR2Object::create([
        'relative_path' => 'image/2026/07/photo.jpg', 'kind' => 'original', 'source_disk' => 'public',
        'source_sha256' => hash('sha256', 'original-bytes-at-queue-time'), 'status' => 'pending',
    ]);

    app(UploadR2SyncService::class)->syncObject($object);

    $uploadedHash = hash('sha256', Storage::disk('r2')->get('image/2026/07/photo.jpg'));
    expect($object->fresh()->status)->toBe('synced')
        ->and($object->fresh()->source_sha256)->toBe($uploadedHash);
});

it('derives Content-Type from the full relative path, not a truncated fragment — jpg originals and webp variants must differ', function () {
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'original-bytes');
    Storage::disk('public')->put('image/2026/07/photo.jpg.160x96.webp', 'variant-bytes');

    $originalObject = UploadR2Object::create(['relative_path' => 'image/2026/07/photo.jpg', 'kind' => 'original', 'source_disk' => 'public', 'source_sha256' => hash('sha256', 'original-bytes'), 'status' => 'pending']);
    $variantObject = UploadR2Object::create(['relative_path' => 'image/2026/07/photo.jpg.160x96.webp', 'kind' => 'variant', 'source_disk' => 'public', 'source_sha256' => hash('sha256', 'variant-bytes'), 'status' => 'pending']);

    app(UploadR2SyncService::class)->syncObject($originalObject);
    app(UploadR2SyncService::class)->syncObject($variantObject);

    expect(Storage::disk('r2')->mimeType('image/2026/07/photo.jpg'))->toBe('image/jpeg')
        ->and(Storage::disk('r2')->mimeType('image/2026/07/photo.jpg.160x96.webp'))->toBe('image/webp');
});

it('quarantines instead of uploading when the snapshot hash differs from an already-synced object\'s recorded hash', function () {
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'new-content-that-differs');

    $object = UploadR2Object::create([
        'relative_path' => 'image/2026/07/photo.jpg', 'kind' => 'original', 'source_disk' => 'public',
        'source_sha256' => hash('sha256', 'old-content-already-on-r2'), // deliberately different from what's on disk now
        'status' => 'synced', // this object was ALREADY successfully synced before
    ]);

    app(UploadR2SyncService::class)->syncObject($object);

    expect($object->fresh()->status)->toBe('content_changed')
        ->and($object->fresh()->previous_sha256)->toBe(hash('sha256', 'old-content-already-on-r2'))
        ->and(Storage::disk('r2')->exists('image/2026/07/photo.jpg'))->toBeFalse(); // must NOT have uploaded
});

it('never processes an object that is already quarantined', function () {
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'content');
    $object = UploadR2Object::create(['relative_path' => 'image/2026/07/photo.jpg', 'source_disk' => 'public', 'status' => 'content_changed']);

    app(UploadR2SyncService::class)->syncObject($object);

    expect($object->fresh()->status)->toBe('content_changed') // untouched
        ->and(Storage::disk('r2')->exists('image/2026/07/photo.jpg'))->toBeFalse();
});

it('does not recreate an R2 image after cleanup marks a stale pending object deleting', function () {
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/deleting.jpg', 'bytes');
    $staleObject = UploadR2Object::create([
        'relative_path' => 'image/2026/07/deleting.jpg',
        'source_disk' => 'public',
        'status' => 'pending',
    ]);
    UploadR2Object::query()->whereKey($staleObject->id)->update(['status' => 'deleting']);

    app(UploadR2SyncService::class)->syncObject($staleObject);

    expect($staleObject->fresh()->status)->toBe('deleting')
        ->and(Storage::disk('r2')->exists('image/2026/07/deleting.jpg'))->toBeFalse();
});

it('defers R2 sync while cleanup owns the upload lock', function () {
    config()->set('ispa-cms.upload.orphan_lock_prefix', 'acme');
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/locked.jpg', 'bytes');
    $uploadId = DB::table('uploads')->insertGetId([
        'path' => 'image/2026/07', 'name' => 'locked.jpg', 'disk' => 'public', 'type' => 'image',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $object = UploadR2Object::create([
        'upload_id' => $uploadId, 'relative_path' => 'image/2026/07/locked.jpg',
        'source_disk' => 'public', 'status' => 'pending',
    ]);
    $lock = Cache::lock('acme:orphan-upload:' . $uploadId, 300);
    expect($lock->get())->toBeTrue();

    try {
        app(UploadR2SyncService::class)->syncObject($object);
        expect($object->fresh()->status)->toBe('pending')
            ->and(Storage::disk('r2')->exists('image/2026/07/locked.jpg'))->toBeFalse();
    } finally {
        $lock->release();
    }
});

it('defers an unlinked crop sync while cleanup owns its base-path lock', function () {
    config()->set('ispa-cms.upload.orphan_lock_prefix', 'acme');
    Storage::fake('public');
    Storage::fake('r2');
    $crop = 'image/2026/07/unlinked.jpg.300x180.webp';
    Storage::disk('public')->put($crop, 'bytes');
    $object = UploadR2Object::create(['relative_path' => $crop, 'source_disk' => 'public', 'status' => 'pending']);
    $lock = Cache::lock('acme:orphan-path:' . sha1('image/2026/07/unlinked.jpg'), 300);
    expect($lock->get())->toBeTrue();

    try {
        app(UploadR2SyncService::class)->syncObject($object);
        expect($object->fresh()->status)->toBe('pending')
            ->and(Storage::disk('r2')->exists($crop))->toBeFalse();
    } finally {
        $lock->release();
    }
});

function seedStatusEnums(): void
{
    DB::table('enumerates')->insert([
        ['enum_key' => 'status_published', 'type' => 'status', 'name' => 'Published', 'created_at' => now(), 'updated_at' => now()],
        ['enum_key' => 'status_draft', 'type' => 'status', 'name' => 'Draft', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

it('does not duplicate an object when its upload is tied to multiple posts', function () {
    seedStatusEnums();
    $uploadId = DB::table('uploads')->insertGetId(['path' => 'image/2026/07', 'name' => 'photo-' . Str::random(8) . '.jpg', 'disk' => 'public', 'type' => 'image', 'created_at' => now(), 'updated_at' => now()]);
    $postA = Post::create(['status' => 'status_published', 'published_at' => now()->subDays(10)]);
    $postB = Post::create(['status' => 'status_published', 'published_at' => now()->subDay()]);

    DB::table('upload_target_pivot')->insert([
        ['upload_id' => $uploadId, 'target_type' => Post::class, 'target_id' => $postA->id, 'category' => 'content', 'main' => 1, 'order' => 0],
        ['upload_id' => $uploadId, 'target_type' => Post::class, 'target_id' => $postB->id, 'category' => 'content', 'main' => 1, 'order' => 0],
    ]);

    $object = UploadR2Object::create(['upload_id' => $uploadId, 'relative_path' => 'image/2026/07/photo.jpg', 'source_disk' => 'public', 'status' => 'pending']);

    $results = app(UploadR2SyncService::class)->pendingObjectsInPriorityOrder(10);

    expect($results->where('id', $object->id))->toHaveCount(1);
});

it('never ranks a draft-tied upload above a published-post-tied upload, regardless of recency', function () {
    seedStatusEnums();
    $recentDraftUploadId = DB::table('uploads')->insertGetId(['path' => 'image/2026/07', 'name' => 'a-' . Str::random(8) . '.jpg', 'disk' => 'public', 'type' => 'image', 'created_at' => now(), 'updated_at' => now()]);
    $olderPublishedUploadId = DB::table('uploads')->insertGetId(['path' => 'image/2026/07', 'name' => 'b-' . Str::random(8) . '.jpg', 'disk' => 'public', 'type' => 'image', 'created_at' => now(), 'updated_at' => now()]);

    // draftPost is created LAST (naturally the most recent created_at, since published_at is null
    // and post_recency falls back to created_at) — proving recency alone can't override the bucket.
    $publishedPost = Post::create(['status' => 'status_published', 'published_at' => now()->subDays(60)]);
    $draftPost = Post::create(['status' => 'status_draft', 'published_at' => null]);

    DB::table('upload_target_pivot')->insert([
        ['upload_id' => $recentDraftUploadId, 'target_type' => Post::class, 'target_id' => $draftPost->id, 'category' => 'content', 'main' => 1, 'order' => 0],
        ['upload_id' => $olderPublishedUploadId, 'target_type' => Post::class, 'target_id' => $publishedPost->id, 'category' => 'content', 'main' => 1, 'order' => 0],
    ]);

    UploadR2Object::create(['upload_id' => $recentDraftUploadId, 'relative_path' => 'image/2026/07/a.jpg', 'source_disk' => 'public', 'status' => 'pending']);
    UploadR2Object::create(['upload_id' => $olderPublishedUploadId, 'relative_path' => 'image/2026/07/b.jpg', 'source_disk' => 'public', 'status' => 'pending']);

    $results = app(UploadR2SyncService::class)->pendingObjectsInPriorityOrder(10);

    expect($results->first()->upload_id)->toBe($olderPublishedUploadId);
});

it('excludes content_changed (quarantined) objects from the sync candidate set', function () {
    $quarantined = UploadR2Object::create(['relative_path' => 'image/2026/07/q.jpg', 'source_disk' => 'public', 'status' => 'content_changed']);
    $pending = UploadR2Object::create(['relative_path' => 'image/2026/07/p.jpg', 'source_disk' => 'public', 'status' => 'pending']);

    $results = app(UploadR2SyncService::class)->pendingObjectsInPriorityOrder(10);

    expect($results->pluck('id'))->not->toContain($quarantined->id)
        ->and($results->pluck('id'))->toContain($pending->id);
});

it('still orders pending objects on a site without the posts and places tables (Content or GeoPlaces off)', function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('posts');
    Schema::dropIfExists('places');
    Schema::enableForeignKeyConstraints();
    $uploadId = DB::table('uploads')->insertGetId(['path' => 'image/2026/07', 'name' => 'c-' . Str::random(8) . '.jpg', 'disk' => 'public', 'type' => 'image', 'created_at' => now(), 'updated_at' => now()]);
    $pending = UploadR2Object::create(['upload_id' => $uploadId, 'relative_path' => 'image/2026/07/c.jpg', 'source_disk' => 'public', 'status' => 'pending']);

    $results = app(UploadR2SyncService::class)->pendingObjectsInPriorityOrder(10);

    expect($results->pluck('id')->all())->toBe([$pending->id]);
});

it('takes no orphan lock and writes the neutral source hash key unless the project configures them', function () {
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/free.jpg', 'bytes');
    $object = UploadR2Object::create(['relative_path' => 'image/2026/07/free.jpg', 'source_disk' => 'public', 'status' => 'pending']);
    $lock = Cache::lock('orphan-path:' . sha1('image/2026/07/free.jpg'), 300);
    $lock->get();

    try {
        app(UploadR2SyncService::class)->syncObject($object);
        expect($object->fresh()->status)->not->toBe('pending');
    } finally {
        $lock->release();
    }
    expect(config('ispa-cms.upload.source_hash_metadata_key', 'source-sha256'))->toBe('source-sha256');
});

