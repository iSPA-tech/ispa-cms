<?php

use Cms\Core\Models\UploadR2Object;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

it('dry-run reports the count without writing anything', function () {
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'content');
    $object = UploadR2Object::create(['relative_path' => 'image/2026/07/photo.jpg', 'source_disk' => 'public', 'status' => 'pending']);

    $this->artisan('upload:sync-to-r2', ['--dry-run' => true, '--limit' => 20])
        ->assertExitCode(0);

    expect($object->fresh()->status)->toBe('pending')
        ->and(Storage::disk('r2')->exists('image/2026/07/photo.jpg'))->toBeFalse();
});

it('syncs eligible pending objects and exits successfully', function () {
    Storage::fake('public');
    Storage::fake('r2');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'content');
    $object = UploadR2Object::create([
        'relative_path' => 'image/2026/07/photo.jpg', 'source_disk' => 'public',
        'source_sha256' => hash('sha256', 'content'), 'status' => 'pending',
    ]);

    $this->artisan('upload:sync-to-r2', ['--limit' => 20, '--max-runtime' => 60])
        ->assertExitCode(0);

    expect($object->fresh()->status)->toBe('synced')
        ->and(Storage::disk('r2')->exists('image/2026/07/photo.jpg'))->toBeTrue();
});
