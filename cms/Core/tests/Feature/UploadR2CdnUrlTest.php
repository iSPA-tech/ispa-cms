<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\Upload;
use Cms\Core\Services\UploadR2SyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach (['uploads', 'upload_r2_objects'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('uploads', function (Blueprint $table): void {
        $table->id();
        $table->string('disk')->nullable();
        $table->string('type')->nullable();
        $table->string('mime_type')->nullable();
        $table->string('path')->nullable();
        $table->string('name')->nullable();
        $table->string('name_alias')->nullable();
        $table->string('alt_text')->nullable();
        $table->unsignedBigInteger('file_size')->default(0);
        $table->boolean('main')->default(false);
        $table->text('exif')->nullable();
        $table->string('target_folder')->nullable();
        $table->string('target_type')->nullable();
        $table->unsignedBigInteger('target_id')->nullable();
        $table->string('category')->nullable();
        $table->text('dimensions')->nullable();
        $table->text('custom')->nullable();
        $table->text('config')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->char('status', 50)->nullable();
        $table->timestamp('r2_synced_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('upload_r2_objects', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('upload_id')->nullable();
        $table->string('relative_path')->unique();
        $table->string('source_disk', 32)->default('public');
        $table->string('kind', 16)->default('original');
        $table->string('status', 24)->default('pending');
        $table->timestamps();
    });

    Upload::flushEventListeners();
});

function r2CdnUpload(array $attributes = []): Upload
{
    $upload = new Upload();
    $upload->forceFill(array_merge([
        'disk' => 'public',
        'type' => 'image',
        'mime_type' => 'image/jpeg',
        'path' => 'image/2026/07',
        'name' => 'car-1.jpg',
    ], $attributes));
    $upload->save();

    return $upload->fresh();
}

it('serves local /storage urls until the upload is marked, then flips to the CDN base', function () {
    config(['filesystems.disks.r2.url' => 'https://cdn.example.test']);
    $upload = r2CdnUpload();

    expect($upload->url)->toContain('/storage/image/2026/07/car-1.jpg')
        ->and($upload->url)->not->toContain('cdn.example.test');

    $upload->forceFill(['r2_synced_at' => now()])->save();
    $upload = $upload->fresh();

    expect($upload->url)->toBe('https://cdn.example.test/image/2026/07/car-1.jpg')
        ->and($upload->publicUrlFor('image/2026/07/car-1.jpg.768x0.webp'))
            ->toBe('https://cdn.example.test/image/2026/07/car-1.jpg.768x0.webp');

    // No CDN base configured → never flips, even when marked.
    config(['filesystems.disks.r2.url' => null]);
    expect($upload->fresh()->url)->toContain('/storage/');
});

it('marks only uploads whose entire tracked object set is synced or verified', function () {
    $complete = r2CdnUpload(['name' => 'done.jpg']);
    $partial = r2CdnUpload(['name' => 'partial.jpg']);
    $untracked = r2CdnUpload(['name' => 'untracked.jpg']);

    DB::table('upload_r2_objects')->insert([
        ['upload_id' => $complete->id, 'relative_path' => 'a/done.jpg', 'status' => 'synced', 'created_at' => now(), 'updated_at' => now()],
        ['upload_id' => $complete->id, 'relative_path' => 'a/done.jpg.768x0.webp', 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()],
        ['upload_id' => $partial->id, 'relative_path' => 'a/partial.jpg', 'status' => 'synced', 'created_at' => now(), 'updated_at' => now()],
        ['upload_id' => $partial->id, 'relative_path' => 'a/partial.jpg.768x0.webp', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $flipped = app(UploadR2SyncService::class)->markFullySyncedUploads();

    expect($flipped)->toBe(1)
        ->and($complete->fresh()->r2_synced_at)->not->toBeNull()
        ->and($partial->fresh()->r2_synced_at)->toBeNull()
        ->and($untracked->fresh()->r2_synced_at)->toBeNull();

    // Idempotent: second pass flips nothing new.
    expect(app(UploadR2SyncService::class)->markFullySyncedUploads())->toBe(0);
});
