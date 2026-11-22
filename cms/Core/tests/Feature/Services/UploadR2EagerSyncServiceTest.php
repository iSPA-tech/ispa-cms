<?php

use Cms\Core\Models\UploadR2Object;
use Cms\Core\Services\UploadR2EagerSyncService;
use Cms\Core\Services\UploadR2SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

it('syncs every given path smallest-file-first', function () {
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/photo.jpg', str_repeat('x', 5000)); // largest
    Storage::disk('public')->put('image/2026/07/photo.jpg.80x80.webp', 'small'); // smallest
    Storage::disk('public')->put('image/2026/07/photo.jpg.768x0.webp', str_repeat('y', 500)); // medium

    $syncOrder = [];
    $spy = Mockery::mock(UploadR2SyncService::class);
    $spy->shouldReceive('syncObject')->andReturnUsing(function (UploadR2Object $object) use (&$syncOrder) {
        $syncOrder[] = $object->relative_path;
        $object->update(['status' => 'synced']);
    });
    app()->instance(UploadR2SyncService::class, $spy);

    app(UploadR2EagerSyncService::class)->syncEagerly(null, [
        'image/2026/07/photo.jpg',
        'image/2026/07/photo.jpg.80x80.webp',
        'image/2026/07/photo.jpg.768x0.webp',
    ], 'public');

    expect($syncOrder)->toBe([
        'image/2026/07/photo.jpg.80x80.webp',
        'image/2026/07/photo.jpg.768x0.webp',
        'image/2026/07/photo.jpg',
    ]);
});

it('keeps synced files for scheduled hash verification and retention even when eviction is enabled', function () {
    config(['filesystems.disks.r2.delete_local_after_sync' => true]);
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'original');
    Storage::disk('public')->put('image/2026/07/photo.jpg.80x80.webp', 'small');

    app(UploadR2EagerSyncService::class)->syncEagerly(null, [
        'image/2026/07/photo.jpg',
        'image/2026/07/photo.jpg.80x80.webp',
    ], 'public');

    expect(UploadR2Object::where('status', 'synced')->count())->toBe(2);
    Storage::disk('public')->assertExists('image/2026/07/photo.jpg');
    Storage::disk('public')->assertExists('image/2026/07/photo.jpg.80x80.webp');
});

it('does not delete local files when the flag is disabled (default)', function () {
    config(['filesystems.disks.r2.delete_local_after_sync' => false]);
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'original');

    app(UploadR2EagerSyncService::class)->syncEagerly(null, ['image/2026/07/photo.jpg'], 'public');

    Storage::disk('public')->assertExists('image/2026/07/photo.jpg');
});

it('does not delete ANY local file if even one path failed to sync', function () {
    config(['filesystems.disks.r2.delete_local_after_sync' => true]);
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'original');
    Storage::disk('public')->put('image/2026/07/photo.jpg.80x80.webp', 'small');

    $spy = Mockery::mock(UploadR2SyncService::class);
    $spy->shouldReceive('syncObject')->andReturnUsing(function (UploadR2Object $object) {
        // simulate the small variant syncing fine, the original failing
        $object->update(['status' => $object->relative_path === 'image/2026/07/photo.jpg' ? 'failed' : 'synced']);
    });
    app()->instance(UploadR2SyncService::class, $spy);

    app(UploadR2EagerSyncService::class)->syncEagerly(null, [
        'image/2026/07/photo.jpg',
        'image/2026/07/photo.jpg.80x80.webp',
    ], 'public');

    Storage::disk('public')->assertExists('image/2026/07/photo.jpg');
    Storage::disk('public')->assertExists('image/2026/07/photo.jpg.80x80.webp');
});

it('skips paths that no longer exist locally without erroring', function () {
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'content');

    app(UploadR2EagerSyncService::class)->syncEagerly(null, [
        'image/2026/07/photo.jpg',
        'image/2026/07/does-not-exist.jpg',
    ], 'public');

    expect(UploadR2Object::count())->toBe(1)
        ->and(UploadR2Object::first()->relative_path)->toBe('image/2026/07/photo.jpg');
});
