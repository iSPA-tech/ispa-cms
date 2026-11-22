<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use Cms\Core\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Persisted image upload whose source file really exists on the fake disk. */
function warmableUpload(string $path = 'image/vehicles/2026/08', string $name = 'hero.jpg'): Upload
{
    Storage::disk('public')->putFileAs(
        $path,
        UploadedFile::fake()->image($name, 640, 400),
        $name
    );

    return Upload::query()->create([
        'disk' => 'public',
        'type' => 'image',
        'path' => $path,
        'name' => $name,
        'mime_type' => 'image/jpeg',
        'file_size' => 1000,
        'dimensions' => ['width' => 640, 'height' => 400],
        'config' => [],
    ]);
}

test('it generates the requested preset for matching uploads', function () {
    Storage::fake('public');
    $upload = warmableUpload();

    $this->artisan('upload:warm-variants', [
        '--preset' => ['cover_md'],
        '--path' => 'image/vehicles',
    ])->assertSuccessful();

    Storage::disk('public')->assertExists('image/vehicles/2026/08/hero.jpg.300x180.webp');
    expect(data_get($upload->fresh()->config, 'variants.cover_md.status'))->toBe('ready');
});

test('it leaves uploads outside the path filter alone', function () {
    Storage::fake('public');
    warmableUpload('image/2026/08', 'article.jpg');

    $this->artisan('upload:warm-variants', [
        '--preset' => ['cover_md'],
        '--path' => 'image/vehicles',
    ])->assertSuccessful();

    Storage::disk('public')->assertMissing('image/2026/08/article.jpg.300x180.webp');
});

test('dry run reports the gap without writing anything', function () {
    Storage::fake('public');
    $upload = warmableUpload();

    $this->artisan('upload:warm-variants', [
        '--preset' => ['cover_md'],
        '--path' => 'image/vehicles',
        '--dry-run' => true,
    ])->assertSuccessful();

    Storage::disk('public')->assertMissing('image/vehicles/2026/08/hero.jpg.300x180.webp');
    expect(data_get($upload->fresh()->config, 'variants.cover_md'))->toBeNull();
});

test('a second run skips what the first one produced', function () {
    Storage::fake('public');
    warmableUpload();

    $this->artisan('upload:warm-variants', ['--preset' => ['cover_md']])->assertSuccessful();

    // Proves idempotence by outcome: the manifest short-circuit reports it as
    // already present instead of re-encoding.
    $this->artisan('upload:warm-variants', ['--preset' => ['cover_md']])
        ->expectsOutputToContain('1 already present')
        ->assertSuccessful();
});

test('a context warms every preset it declares', function () {
    Storage::fake('public');
    warmableUpload();

    $this->artisan('upload:warm-variants', ['--context' => 'vehicle.card'])->assertSuccessful();

    Storage::disk('public')->assertExists('image/vehicles/2026/08/hero.jpg.160x96.webp');
    Storage::disk('public')->assertExists('image/vehicles/2026/08/hero.jpg.300x180.webp');
});

test('force re-encodes a derivative that is already present', function () {
    Storage::fake('public');
    $upload = warmableUpload();

    $this->artisan('upload:warm-variants', ['--preset' => ['cover_md']])->assertSuccessful();
    Storage::disk('public')->put('image/vehicles/2026/08/hero.jpg.300x180.webp', 'stale bytes');

    $this->artisan('upload:warm-variants', ['--preset' => ['cover_md'], '--force' => true])
        ->expectsOutputToContain('0 already present')
        ->assertSuccessful();

    expect(Storage::disk('public')->get('image/vehicles/2026/08/hero.jpg.300x180.webp'))
        ->not->toBe('stale bytes');
    expect(data_get($upload->fresh()->config, 'variants.cover_md.status'))->toBe('ready');
});

test('a failed force re-encode leaves the old derivative in place', function () {
    Storage::fake('public');
    warmableUpload();
    $path = 'image/vehicles/2026/08/hero.jpg.300x180.webp';

    $this->artisan('upload:warm-variants', ['--preset' => ['cover_md']])->assertSuccessful();
    $original = Storage::disk('public')->get($path);

    //  Regression guard. --force used to forget() the variant and only then
    //  rebuild it, so the URL 404'd for the length of an encode — and if the
    //  encode failed, the derivative was gone for good. Removing the source
    //  makes the rebuild fail deterministically; the old bytes must survive.
    Storage::disk('public')->delete('image/vehicles/2026/08/hero.jpg');

    $this->artisan('upload:warm-variants', ['--preset' => ['cover_md'], '--force' => true])
        ->assertFailed();

    expect(Storage::disk('public')->exists($path))->toBeTrue()
        ->and(Storage::disk('public')->get($path))->toBe($original);
});

test('an unknown preset fails before touching storage', function () {
    Storage::fake('public');
    warmableUpload();

    $this->artisan('upload:warm-variants', ['--preset' => ['not_a_preset']])->assertFailed();

    Storage::disk('public')->assertMissing('image/vehicles/2026/08/hero.jpg.300x180.webp');
});

test('force re-encodes uploads that predate the variant manifest', function () {
    Storage::fake('public');
    $upload = warmableUpload();
    $path = 'image/vehicles/2026/08/hero.jpg.300x180.webp';

    $this->artisan('upload:warm-variants', ['--preset' => ['cover_md']])->assertSuccessful();

    //  Legacy shape: the derivative is on disk but nothing records it. Keying
    //  --force off the manifest made it a no-op on exactly these uploads —
    //  which is every upload on the older sites this was written for.
    $upload->config = [];
    $upload->saveQuietly();
    Storage::disk('public')->put($path, 'stale bytes');

    $this->artisan('upload:warm-variants', ['--preset' => ['cover_md'], '--force' => true])
        ->assertSuccessful();

    expect(Storage::disk('public')->get($path))->not->toBe('stale bytes');
});

test('without force a manifest-less upload keeps its existing derivative', function () {
    Storage::fake('public');
    $upload = warmableUpload();
    $path = 'image/vehicles/2026/08/hero.jpg.300x180.webp';

    $this->artisan('upload:warm-variants', ['--preset' => ['cover_md']])->assertSuccessful();
    $upload->config = [];
    $upload->saveQuietly();
    Storage::disk('public')->put($path, 'existing bytes');

    $this->artisan('upload:warm-variants', ['--preset' => ['cover_md']])->assertSuccessful();

    expect(Storage::disk('public')->get($path))->toBe('existing bytes');
});
