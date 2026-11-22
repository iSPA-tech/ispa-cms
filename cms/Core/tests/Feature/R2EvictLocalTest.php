<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\UploadR2Object;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Schema::dropIfExists('upload_r2_objects');
    Schema::create('upload_r2_objects', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('upload_id')->nullable();
        $table->string('relative_path')->unique();
        $table->string('source_disk', 32)->default('public');
        $table->string('kind', 16)->default('original');
        $table->unsignedBigInteger('source_size')->nullable();
        $table->timestamp('source_mtime')->nullable();
        $table->char('source_sha256', 64)->nullable();
        $table->char('previous_sha256', 64)->nullable();
        $table->string('status', 24)->default('pending');
        $table->text('last_error')->nullable();
        $table->timestamp('verified_at')->nullable();
        $table->timestamp('evicted_at')->nullable();
        $table->timestamps();
    });

    Storage::fake('public');
    Storage::fake('r2');
});

function r2EvictRow(string $path, string $content, array $overrides = []): UploadR2Object
{
    Storage::disk('public')->put($path, $content);
    Storage::disk('r2')->put($path, $content);

    $row = UploadR2Object::create(array_merge([
        'relative_path' => $path,
        'source_disk' => 'public',
        'status' => 'verified',
        'source_sha256' => hash('sha256', $content),
        'source_size' => strlen($content),
        'source_mtime' => now()->subDays(30),
        'verified_at' => now(),
    ], $overrides));

    return $row->fresh();
}

it('refuses to evict while the master flag is off, but allows dry runs', function () {
    config(['filesystems.disks.r2.delete_local_after_sync' => false]);
    $row = r2EvictRow('image/a.jpg', 'verified-bytes');

    $this->artisan('upload:r2-evict-local')->assertFailed();
    $this->artisan('upload:r2-evict-local --dry-run')->assertSuccessful();

    expect(Storage::disk('public')->exists('image/a.jpg'))->toBeTrue()
        ->and($row->fresh()->evicted_at)->toBeNull();
});

it('keeps local bytes when R2 is missing or its size changed', function () {
    config(['filesystems.disks.r2.delete_local_after_sync' => true]);
    $missing = r2EvictRow('image/missing.jpg', 'original');
    $changed = r2EvictRow('image/changed.jpg', 'original');
    Storage::disk('r2')->delete($missing->relative_path);
    Storage::disk('r2')->put($changed->relative_path, 'short');

    $this->artisan('upload:r2-evict-local')->assertFailed();

    foreach ([$missing, $changed] as $row) {
        Storage::disk('public')->assertExists($row->relative_path);
        expect($row->fresh()->evicted_at)->toBeNull();
    }
});

it('limits eviction to the requested directory prefix', function () {
    config(['filesystems.disks.r2.delete_local_after_sync' => true]);
    $july = r2EvictRow('image/2026/07/a.jpg', 'july');
    $august = r2EvictRow('image/2026/08/a.jpg', 'august');

    $this->artisan('upload:r2-evict-local --prefix=image/2026/07/')->assertSuccessful();

    Storage::disk('public')->assertMissing($july->relative_path);
    Storage::disk('public')->assertExists($august->relative_path);
});

it('evicts only old verified files and quarantines drifted content instead of deleting it', function () {
    config(['filesystems.disks.r2.delete_local_after_sync' => true]);

    $verified = r2EvictRow('image/old-verified.jpg', 'verified-bytes');
    $drifted = r2EvictRow('image/drifted.jpg', 'original-bytes');
    Storage::disk('public')->put('image/drifted.jpg', 'CHANGED-bytes');
    $syncedOnly = r2EvictRow('image/synced-only.jpg', 'synced-bytes', ['status' => 'synced', 'verified_at' => null]);
    $fresh = r2EvictRow('image/fresh.jpg', 'fresh-bytes', ['source_mtime' => now()]);
    $olderThanDefaultRetention = r2EvictRow(
        'image/older-than-default-retention.jpg',
        'older-than-default-retention-bytes',
        ['source_mtime' => now()->subDays(8)],
    );

    $this->artisan('upload:r2-evict-local')->assertSuccessful();

    expect(Storage::disk('public')->exists('image/old-verified.jpg'))->toBeFalse()
        ->and($verified->fresh()->evicted_at)->not->toBeNull()
        // drifted: kept on disk, quarantined
        ->and(Storage::disk('public')->exists('image/drifted.jpg'))->toBeTrue()
        ->and($drifted->fresh()->status)->toBe('content_changed')
        ->and($drifted->fresh()->evicted_at)->toBeNull()
        // synced-but-unverified: untouched
        ->and(Storage::disk('public')->exists('image/synced-only.jpg'))->toBeTrue()
        // newer than the buffer: untouched
        ->and(Storage::disk('public')->exists('image/fresh.jpg'))->toBeTrue()
        // default retention is now 7 days
        ->and(Storage::disk('public')->exists('image/older-than-default-retention.jpg'))->toBeFalse()
        ->and($olderThanDefaultRetention->fresh()->evicted_at)->not->toBeNull();
});
