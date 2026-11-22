<?php

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

uses(\Cms\Core\Tests\TestCase::class);

it('confirms R2 actually stores the ContentType, CacheControl, and custom Metadata we send', function () {
    $key = '__r2-test/' . Str::random(12) . '.txt';
    $hash = hash('sha256', 'integration-test-content');

    Storage::disk('r2')->put($key, 'integration-test-content', [
        'ContentType' => 'text/plain',
        'CacheControl' => 'public, max-age=60',
        'Metadata' => ['acme-source-sha256' => $hash],
    ]);

    $head = Storage::disk('r2')->getClient()->headObject([
        'Bucket' => config('filesystems.disks.r2.bucket'),
        'Key' => $key,
    ]);

    expect($head['ContentType'])->toBe('text/plain')
        ->and($head['CacheControl'])->toBe('public, max-age=60')
        ->and($head['Metadata']['acme-source-sha256'] ?? null)->toBe($hash);

    Storage::disk('r2')->delete($key);
})->group('integration')
    ->skip(fn (): bool => blank(config('filesystems.disks.r2.bucket')), 'Needs a real R2 bucket (the R2_* settings in .env).');
