<?php

use Cms\Core\Models\UploadR2Object;
use Cms\Core\Services\UploadR2ReplicationHook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

it('tracks a fresh local write for R2 replication when the hook is enabled', function () {
    config(['filesystems.disks.r2.replication_hook_enabled' => true]);
    Storage::fake('public');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'content');

    app(UploadR2ReplicationHook::class)->afterLocalWrite('image/2026/07/photo.jpg', null, 'public');

    expect(UploadR2Object::where('relative_path', 'image/2026/07/photo.jpg')->exists())->toBeTrue();
});

it('does nothing when the hook is disabled via config', function () {
    config(['filesystems.disks.r2.replication_hook_enabled' => false]);
    Storage::fake('public');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'content');

    app(UploadR2ReplicationHook::class)->afterLocalWrite('image/2026/07/photo.jpg', null, 'public');

    expect(UploadR2Object::where('relative_path', 'image/2026/07/photo.jpg')->exists())->toBeFalse();
});
