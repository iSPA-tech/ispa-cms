<?php

use Cms\Core\Models\UploadR2Object;
use Cms\Core\Services\R2ObjectTracker;
use Cms\Core\Services\UploadR2ObjectRepository;
use Cms\Core\Support\TrackingOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

it('trackFreshWriteSafely never throws, even when the repository fails internally', function () {
    Storage::fake('public');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'content');

    $failingRepository = Mockery::mock(UploadR2ObjectRepository::class);
    $failingRepository->shouldReceive('findByPath')->andThrow(new RuntimeException('simulated DB outage'));

    $tracker = new R2ObjectTracker($failingRepository);
    $outcome = $tracker->trackFreshWriteSafely('image/2026/07/photo.jpg', null, 'public');

    expect($outcome)->toBe(TrackingOutcome::Failed); // distinct from MissingSource — the file exists, the WRITE failed
});

it('discoverExistingFileOrFail DOES throw on the same failure — operational commands must detect it', function () {
    Storage::fake('public');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'content');

    $failingRepository = Mockery::mock(UploadR2ObjectRepository::class);
    $failingRepository->shouldReceive('findByPath')->andThrow(new RuntimeException('simulated DB outage'));

    $tracker = new R2ObjectTracker($failingRepository);

    expect(fn () => $tracker->discoverExistingFileOrFail('image/2026/07/photo.jpg', null, 'public'))
        ->toThrow(RuntimeException::class);
});

it('trackFreshWriteSafely always re-hashes, never using the fast path — two writes within the same mtime resolution window must not be missed', function () {
    Storage::fake('public');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'first-write');
    $tracker = app(R2ObjectTracker::class);
    $tracker->trackFreshWriteSafely('image/2026/07/photo.jpg', null, 'public');
    $firstHash = UploadR2Object::first()->source_sha256;

    // simulate a second write with the SAME size (a same-second overwrite could plausibly share mtime too)
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'second-write!'); // same length as 'first-write'

    $tracker->trackFreshWriteSafely('image/2026/07/photo.jpg', null, 'public');

    expect(UploadR2Object::first()->source_sha256)->not->toBe($firstHash); // must have re-hashed, not skipped via fast path
});

it('discoverExistingFileOrFail uses the fast path and skips hashing when size+mtime are unchanged', function () {
    Storage::fake('public');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'content');
    $tracker = app(R2ObjectTracker::class);
    $tracker->discoverExistingFileOrFail('image/2026/07/photo.jpg', null, 'public');
    $originalHash = UploadR2Object::first()->source_sha256;

    $outcome = $tracker->discoverExistingFileOrFail('image/2026/07/photo.jpg', null, 'public'); // file untouched

    expect($outcome)->toBe(TrackingOutcome::Unchanged)
        ->and(UploadR2Object::first()->source_sha256)->toBe($originalHash);
});

it('quarantines instead of silently resetting to pending when content changes at an already-synced path', function () {
    Storage::fake('public');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'version-one');
    $tracker = app(R2ObjectTracker::class);
    $tracker->trackFreshWriteSafely('image/2026/07/photo.jpg', null, 'public');
    UploadR2Object::first()->update(['status' => 'verified', 'verified_at' => now()]); // simulate a completed sync+verify

    Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'quarantined'));
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'a-very-different-version-two');

    $outcome = $tracker->trackFreshWriteSafely('image/2026/07/photo.jpg', null, 'public');

    $object = UploadR2Object::first();
    expect($outcome)->toBe(TrackingOutcome::Quarantined)
        ->and($object->status)->toBe('content_changed')
        ->and($object->previous_sha256)->toBe(hash('sha256', 'version-one'))
        ->and($object->needs_cache_purge)->toBeTrue();
});

it('does NOT quarantine a fingerprint change on an object that was never successfully synced', function () {
    Storage::fake('public');
    Storage::disk('public')->put('image/2026/07/photo.jpg', 'draft-version');
    $tracker = app(R2ObjectTracker::class);
    $tracker->trackFreshWriteSafely('image/2026/07/photo.jpg', null, 'public'); // status stays 'pending' — never synced

    Storage::disk('public')->put('image/2026/07/photo.jpg', 'still-being-finalized');
    $outcome = $tracker->trackFreshWriteSafely('image/2026/07/photo.jpg', null, 'public');

    expect($outcome)->toBe(TrackingOutcome::Tracked)
        ->and(UploadR2Object::first()->status)->toBe('pending'); // ordinary update, not quarantine
});

it('never requeues a deleting object when a stale image write is discovered', function () {
    Storage::fake('public');
    Storage::disk('public')->put('image/2026/07/deleting.jpg', 'first');
    $tracker = app(R2ObjectTracker::class);
    $tracker->trackFreshWriteSafely('image/2026/07/deleting.jpg', null, 'public');
    UploadR2Object::query()->where('relative_path', 'image/2026/07/deleting.jpg')->update(['status' => 'deleting']);
    Storage::disk('public')->put('image/2026/07/deleting.jpg', 'changed');

    $outcome = $tracker->trackFreshWriteSafely('image/2026/07/deleting.jpg', null, 'public');

    expect($outcome)->toBe(TrackingOutcome::Unchanged)
        ->and(UploadR2Object::query()->where('relative_path', 'image/2026/07/deleting.jpg')->value('status'))->toBe('deleting');
});
