<?php

use Cms\Core\Models\Upload;
use Cms\Core\Models\UploadR2Object;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

it('preloads the upload path-to-id map once, issuing a single query rather than one per file', function () {
    Storage::fake('public');
    foreach (['a', 'b', 'c'] as $letter) {
        Upload::create(['path' => 'image/2026/07', 'name' => "{$letter}.jpg", 'disk' => 'public', 'type' => 'image']);
        Storage::disk('public')->put("image/2026/07/{$letter}.jpg", 'content');
    }

    DB::enableQueryLog();
    Artisan::call('upload:r2-discover', ['--full' => true, '--root' => 'image']);
    $uploadLookupQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "uploads"') || str_contains($q['query'], 'from `uploads`'));

    expect($uploadLookupQueries)->toHaveCount(1); // one preload query, not one per file
});

it('incremental mode filters by real file mtime, catching a newly-generated variant whose parent upload row was never touched', function () {
    Storage::fake('public');
    $upload = Upload::create(['path' => 'image/2026/07', 'name' => 'photo.jpg', 'disk' => 'public', 'type' => 'image']);
    DB::table('uploads')->where('id', $upload->id)->update(['updated_at' => now()->subDays(10)]); // upload row NOT recently touched

    Storage::disk('public')->put('image/2026/07/photo.jpg', 'original'); // old file
    touch(Storage::disk('public')->path('image/2026/07/photo.jpg'), now()->subDays(10)->timestamp);

    Storage::disk('public')->put('image/2026/07/photo.jpg.160x96.webp', 'freshly generated variant'); // new file, right now — parent upload row untouched

    Artisan::call('upload:r2-discover', ['--recent-hours' => 24]);

    expect(UploadR2Object::where('relative_path', 'image/2026/07/photo.jpg.160x96.webp')->exists())->toBeTrue()
        ->and(UploadR2Object::where('relative_path', 'image/2026/07/photo.jpg')->exists())->toBeFalse(); // correctly NOT picked up — it's old
});
