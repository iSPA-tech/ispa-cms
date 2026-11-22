<?php

use Cms\Core\Services\StorageReconciliationService;
use Illuminate\Support\Facades\Storage;

uses(\Cms\Core\Tests\TestCase::class);

it('matches only the original file and dot-suffixed variants, not unrelated files sharing a prefix', function () {
    Storage::fake('public');
    Storage::disk('public')->put('image/2026/07/photo-1700000000.jpg', 'original');
    Storage::disk('public')->put('image/2026/07/photo-1700000000.jpg.160x96.webp', 'variant');
    Storage::disk('public')->put('image/2026/07/photo-1700000000.jpgXYZ-decoy.jpg', 'unrelated'); // no dot boundary — must NOT match

    $files = app(StorageReconciliationService::class)->filesForBasePath('image/2026/07/photo-1700000000.jpg');

    expect($files)->toHaveCount(2)
        ->and($files)->not->toContain('image/2026/07/photo-1700000000.jpgXYZ-decoy.jpg');
});
