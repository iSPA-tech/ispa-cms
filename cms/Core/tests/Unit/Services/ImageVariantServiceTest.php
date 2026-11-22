<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use Cms\Core\Models\Upload;
use Cms\Core\Models\UploadR2Object;
use Cms\Core\Services\ImageVariantService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

function makeImageVariantUpload(array $overrides = []): Upload
{
    return new Upload([
        'disk' => 'public',
        'type' => 'image',
        'path' => 'image/2026/06',
        'name' => 'source.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1000,
        'dimensions' => ['width' => 800, 'height' => 600],
        'config' => [],
        ...$overrides,
    ]);
}

test('variant url falls back to original image when variant is missing', function () {
    Storage::fake('public');

    $upload = makeImageVariantUpload();
    $url = app(ImageVariantService::class)->url($upload, 'cover_lg');

    expect($url)->toContain('/storage/image/2026/06/source.jpg');
});

test('variant url uses an existing legacy derivative before falling back', function () {
    Storage::fake('public');
    Storage::disk('public')->put('image/2026/06/source.jpg.600x360.webp', 'variant');

    $upload = makeImageVariantUpload();
    $url = app(ImageVariantService::class)->url($upload, 'cover_lg');

    expect($url)->toContain('/storage/image/2026/06/source.jpg.600x360.webp');
});

test('variant url uses nearest existing same family variant before original fallback', function () {
    Storage::fake('public');
    Storage::disk('public')->put('image/2026/06/source.jpg.600x360.webp', 'variant');

    $upload = makeImageVariantUpload();
    $url = app(ImageVariantService::class)->url($upload, 'cover_sm');

    expect($url)->toContain('/storage/image/2026/06/source.jpg.600x360.webp');
});

test('context url generates missing allowed variant before falling back', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('source.jpg', 1600, 1000);
    Storage::disk('public')->putFileAs('image/2026/06', $file, 'source.jpg');

    $upload = makeImageVariantUpload();
    $url = app(ImageVariantService::class)->urlForContext($upload, 'post.card', 'cover_lg');

    expect($url)->toContain('/storage/image/2026/06/source.jpg.600x360.webp');
    Storage::disk('public')->assertExists('image/2026/06/source.jpg.600x360.webp');
    expect(data_get($upload->config, 'variants.cover_lg.status'))->toBe('ready');
});

test('a project with orphan locks does not create a variant for an upload already claimed for deletion', function () {
    config()->set('ispa-cms.upload.orphan_lock_prefix', 'acme');
    Storage::fake('public');
    Storage::disk('public')->putFileAs('image/2026/06', UploadedFile::fake()->image('source.jpg', 800, 600), 'source.jpg');
    $upload = makeImageVariantUpload();
    $upload->save();
    $upload->delete();

    expect(app(ImageVariantService::class)->ensureVariant($upload, 'cover_sm'))->toBeNull();
    Storage::disk('public')->assertMissing('image/2026/06/source.jpg.160x96.webp');
});

test('a project with orphan locks does not create a variant while the image path is being pruned', function () {
    config()->set('ispa-cms.upload.orphan_lock_prefix', 'acme');
    Storage::fake('public');
    Storage::disk('public')->putFileAs('image/2026/06', UploadedFile::fake()->image('source.jpg', 800, 600), 'source.jpg');
    $upload = makeImageVariantUpload();
    $upload->save();
    $lock = Cache::lock('acme:orphan-path:' . sha1($upload->getTargetFile()), 300);
    expect($lock->get())->toBeTrue();

    try {
        expect(app(ImageVariantService::class)->ensureVariant($upload, 'cover_sm'))->toBeNull();
        Storage::disk('public')->assertMissing('image/2026/06/source.jpg.160x96.webp');
    } finally {
        $lock->release();
    }
});

test('post card context generates the intermediate responsive cover', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('source.jpg', 1600, 1000);
    Storage::disk('public')->putFileAs('image/2026/06', $file, 'source.jpg');

    $upload = makeImageVariantUpload();
    $url = app(ImageVariantService::class)->urlForContext($upload, 'post.card', 'cover_card');

    expect($url)->toContain('/storage/image/2026/06/source.jpg.480x288.webp');
    Storage::disk('public')->assertExists('image/2026/06/source.jpg.480x288.webp');
    expect(data_get($upload->config, 'variants.cover_card.status'))->toBe('ready');
});

test('variant generation retries with gd when imagick exhausts resources', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('source.jpg', 1600, 1000);
    Storage::disk('public')->putFileAs('image/2026/06', $file, 'source.jpg');

    $service = new class extends ImageVariantService {
        public array $drivers = [];

        protected function preferredImageDriver(): string
        {
            return 'imagick';
        }

        protected function imageManager(string $driver): \Intervention\Image\ImageManager
        {
            $this->drivers[] = $driver;
            if ($driver === 'imagick') {
                throw new RuntimeException('cache resources exhausted');
            }

            return parent::imageManager($driver);
        }
    };

    $url = $service->urlForContext(makeImageVariantUpload(), 'post.card', 'cover_lg');

    expect($service->drivers)->toBe(['imagick', 'gd'])
        ->and($url)->toContain('/storage/image/2026/06/source.jpg.600x360.webp');
    Storage::disk('public')->assertExists('image/2026/06/source.jpg.600x360.webp');
});

test('context url does not generate a preset outside the requested context', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('source.jpg', 1600, 1000);
    Storage::disk('public')->putFileAs('image/2026/06', $file, 'source.jpg');

    $upload = makeImageVariantUpload();
    $url = app(ImageVariantService::class)->urlForContext($upload, 'post.card', 'facebook');

    expect($url)->toContain('/storage/image/2026/06/source.jpg');
    Storage::disk('public')->assertMissing('image/2026/06/source.jpg.1200x630.webp');
    expect(data_get($upload->config, 'variants.facebook'))->toBeNull();
});

test('context url falls back to original when source image is missing', function () {
    Storage::fake('public');

    $upload = makeImageVariantUpload();
    $url = app(ImageVariantService::class)->urlForContext($upload, 'post.card', 'cover_lg');

    expect($url)->toContain('/storage/image/2026/06/source.jpg');
    expect(data_get($upload->config, 'variants.cover_lg.status'))->toBe('failed');
});

test('media grid context generates only small preview variants and records manifest', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('source.jpg', 800, 600);
    Storage::disk('public')->putFileAs('image/2026/06', $file, 'source.jpg');

    $upload = makeImageVariantUpload();
    $variants = app(ImageVariantService::class)->ensureContext($upload, 'media.grid');

    Storage::disk('public')->assertExists('image/2026/06/source.jpg.80x80.webp');
    Storage::disk('public')->assertExists('image/2026/06/source.jpg.160x96.webp');
    Storage::disk('public')->assertMissing('image/2026/06/source.jpg.600x360.webp');

    expect($variants)->toHaveKeys(['thumbnail_sm', 'cover_sm']);
    expect(data_get($upload->config, 'variants.thumbnail_sm.status'))->toBe('ready');
    expect(data_get($upload->config, 'variants.cover_sm.status'))->toBe('ready');
});

test('post cover context generates card detail and social variants', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('source.jpg', 1600, 1000);
    Storage::disk('public')->putFileAs('image/2026/06', $file, 'source.jpg');

    $upload = makeImageVariantUpload();
    $variants = app(ImageVariantService::class)->ensureContext($upload, 'post.cover');

    Storage::disk('public')->assertExists('image/2026/06/source.jpg.600x360.webp');
    Storage::disk('public')->assertExists('image/2026/06/source.jpg.1200x720.webp');
    Storage::disk('public')->assertExists('image/2026/06/source.jpg.1200x630.webp');
    Storage::disk('public')->assertExists('image/2026/06/source.jpg.1024x512.webp');

    expect($variants)->toHaveKeys(['cover_lg', 'cover_xl', 'facebook', 'twitter']);
});

test('post content context scales inline images without cover cropping', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('source.jpg', 1600, 1000);
    Storage::disk('public')->putFileAs('image/2026/06', $file, 'source.jpg');

    $upload = makeImageVariantUpload();
    $variants = app(ImageVariantService::class)->ensureContext($upload, 'post.content');

    Storage::disk('public')->assertExists('image/2026/06/source.jpg.768x0.webp');
    Storage::disk('public')->assertExists('image/2026/06/source.jpg.1200x0.webp');
    Storage::disk('public')->assertExists('image/2026/06/source.jpg.1440x0.webp');
    Storage::disk('public')->assertMissing('image/2026/06/source.jpg.600x360.webp');

    expect($variants)->toHaveKeys(['md', 'lg', 'xl']);
});

test('catalog contexts cover avatar gallery banner and real estate image types', function (string $context, array $expected, array $missing = []) {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('source.jpg', 1800, 1200);
    Storage::disk('public')->putFileAs('image/2026/06', $file, 'source.jpg');

    $upload = makeImageVariantUpload();
    app(ImageVariantService::class)->ensureContext($upload, $context);

    foreach ($expected as $path) {
        Storage::disk('public')->assertExists('image/2026/06/source.jpg.' . $path . '.webp');
    }

    foreach ($missing as $path) {
        Storage::disk('public')->assertMissing('image/2026/06/source.jpg.' . $path . '.webp');
    }
})->with([
    'avatar' => ['avatar', ['80x80', '300x300'], ['600x360']],
    'gallery.cover' => ['gallery.cover', ['600x360', '1200x720', '1200x630', '1024x512']],
    'gallery.item' => ['gallery.item', ['768x0', '1200x0', '1440x0'], ['600x360']],
    'banner' => ['banner', ['768x0', '1200x0', '1440x0'], ['600x360']],
    'real_estate.asset' => ['real_estate.asset', ['300x300', '600x600', '1000x1000', '768x0', '1200x0', '1440x0']],
]);

test('upload warmup contexts are selected from upload type folder and category', function (array $attributes, array $expected) {
    $upload = makeImageVariantUpload($attributes);

    expect(app(ImageVariantService::class)->contextsForUpload($upload))->toBe($expected);
})->with([
    'generic image' => [[], ['media.grid']],
    'avatar upload' => [['type' => 'avatar'], ['media.grid', 'avatar']],
    'banner folder' => [['target_folder' => 'Banner'], ['media.grid', 'banner']],
    'real estate folder' => [['target_folder' => 'RealEstateProperty'], ['media.grid', 'real_estate.asset']],
    'page image' => [['type' => 'page', 'mime_type' => 'image/jpeg'], ['media.grid', 'page.content']],
    'comment image category' => [['category' => 'commentImages'], ['media.grid', 'comment.image']],
]);

test('generate restores the source from R2 when the local original was evicted after sync', function () {
    Storage::fake('public');
    Storage::fake('r2');

    $file = UploadedFile::fake()->image('source.jpg', 800, 600);
    $bytes = file_get_contents($file->getRealPath());
    Storage::disk('r2')->put('image/2026/06/source.jpg', $bytes);
    UploadR2Object::create([
        'relative_path' => 'image/2026/06/source.jpg', 'kind' => 'original', 'source_disk' => 'public',
        'source_sha256' => hash('sha256', $bytes), 'status' => 'synced',
    ]);

    $upload = makeImageVariantUpload(); // no local file at all — public disk is empty
    $variant = app(ImageVariantService::class)->ensureVariant($upload, 'cover_sm');

    expect($variant['status'])->toBe('ready');
    Storage::disk('public')->assertExists('image/2026/06/source.jpg'); // restored
    Storage::disk('public')->assertExists('image/2026/06/source.jpg.160x96.webp');
});

test('generate still fails source_missing when local is gone and R2 has no tracked copy either', function () {
    Storage::fake('public');
    Storage::fake('r2');

    $upload = makeImageVariantUpload();
    $variant = app(ImageVariantService::class)->ensureVariant($upload, 'cover_sm');

    expect($variant['status'])->toBe('failed')
        ->and($variant['reason'])->toBe('source_missing');
});

test('served-from-R2 variant survives local eviction via its ready manifest', function () {
    Storage::fake('public'); // local disk empty — the variant was evicted after sync
    config()->set('filesystems.disks.r2.url', 'https://cdn.acme.test');

    $upload = makeImageVariantUpload([
        'config' => ['variants' => ['thumbnail_sm' => [
            'status' => 'ready',
            'path' => 'image/2026/06/source.jpg.80x80.webp',
        ]]],
    ]);
    $upload->r2_synced_at = now();
    UploadR2Object::create([
        'relative_path' => 'image/2026/06/source.jpg.80x80.webp',
        'kind' => 'variant',
        'source_disk' => 'public',
        'source_sha256' => hash('sha256', 'variant'),
        'status' => 'synced',
    ]);

    $url = app(ImageVariantService::class)->url($upload, 'thumbnail_sm');

    expect($url)->toBe('https://cdn.acme.test/image/2026/06/source.jpg.80x80.webp');
});

test('replicated derivative uses the CDN while its local retention copy still exists', function () {
    Storage::fake('public');
    config()->set('filesystems.disks.r2.url', 'https://cdn.acme.test');
    Storage::disk('public')->put('image/2026/06/source.jpg.600x360.webp', 'variant');

    $upload = makeImageVariantUpload([
        'config' => ['variants' => ['cover_lg' => [
            'status' => 'ready',
            'path' => 'image/2026/06/source.jpg.600x360.webp',
        ]]],
    ]);
    $upload->r2_synced_at = now();
    UploadR2Object::create([
        'relative_path' => 'image/2026/06/source.jpg.600x360.webp',
        'kind' => 'variant',
        'source_disk' => 'public',
        'source_sha256' => hash('sha256', 'variant'),
        'status' => 'synced',
    ]);

    $url = app(ImageVariantService::class)->url($upload, 'cover_lg');

    expect($url)->toBe('https://cdn.acme.test/image/2026/06/source.jpg.600x360.webp');
});

test('served-from-R2 legacy variant resolves via the R2 ledger after eviction', function () {
    Storage::fake('public'); // no local file, no manifest — only the ledger proves it
    config()->set('filesystems.disks.r2.url', 'https://cdn.acme.test');

    UploadR2Object::create([
        'relative_path' => 'image/2026/06/source.jpg.80x80.webp',
        'kind' => 'variant',
        'source_disk' => 'public',
        'source_sha256' => hash('sha256', 'variant'),
        'status' => 'verified',
    ]);

    $upload = makeImageVariantUpload(['config' => []]);
    $upload->r2_synced_at = now();

    $url = app(ImageVariantService::class)->url($upload, 'thumbnail_sm');

    expect($url)->toBe('https://cdn.acme.test/image/2026/06/source.jpg.80x80.webp');
});

test('served-from-R2 upload with no proven variant serves the CDN original, not a broken variant url', function () {
    Storage::fake('public'); // evicted, failed manifest, nothing in the ledger
    config()->set('filesystems.disks.r2.url', 'https://cdn.acme.test');

    $upload = makeImageVariantUpload([
        'config' => ['variants' => ['thumbnail_sm' => [
            'status' => 'failed',
            'path' => 'image/2026/06/source.jpg.80x80.webp',
        ]]],
    ]);
    $upload->r2_synced_at = now();

    $url = app(ImageVariantService::class)->url($upload, 'thumbnail_sm');

    expect($url)->toBe('https://cdn.acme.test/image/2026/06/source.jpg');
});

test('a locally served upload still requires the variant on disk (no R2 cutover)', function () {
    Storage::fake('public'); // r2_synced_at null → served from local storage
    config()->set('filesystems.disks.r2.url', 'https://cdn.acme.test');

    $upload = makeImageVariantUpload([
        'config' => ['variants' => ['thumbnail_sm' => [
            'status' => 'ready',
            'path' => 'image/2026/06/source.jpg.80x80.webp',
        ]]],
    ]);

    $url = app(ImageVariantService::class)->url($upload, 'thumbnail_sm');

    // Manifest is "ready" but the file is missing locally and there is no R2
    // cutover, so it must fall through to the original on /storage.
    expect($url)->toContain('/storage/image/2026/06/source.jpg')
        ->and($url)->not->toContain('80x80');
});

test('generated variants carry no source metadata', function () {
    Storage::fake('public');

    //  A source that really carries EXIF/ICC, so the assertion is about the
    //  encoder and not about a fixture that never had profiles to begin with.
    $source = imagecreatetruecolor(640, 400);
    imagefilledrectangle($source, 0, 0, 639, 399, imagecolorallocate($source, 180, 60, 40));
    ob_start();
    imagejpeg($source, null, 90);
    $jpeg = ob_get_clean();
    imagedestroy($source);

    $withProfiles = new Imagick();
    $withProfiles->readImageBlob($jpeg);
    $withProfiles->setImageProfile('exif', str_repeat('E', 6000));
    $withProfiles->setImageProfile('xmp', str_repeat('X', 4000));
    Storage::disk('public')->put('image/2026/06/source.jpg', $withProfiles->getImageBlob());

    $upload = makeImageVariantUpload();
    app(ImageVariantService::class)->url($upload, 'cover_sm', true);

    $variant = new Imagick();
    $variant->readImageBlob(Storage::disk('public')->get('image/2026/06/source.jpg.160x96.webp'));

    expect($variant->getImageProfiles('*', false))->toBe([])
        ->and(strlen(Storage::disk('public')->get('image/2026/06/source.jpg.160x96.webp')))
        ->toBeLessThan(6000);
})->skip(! extension_loaded('imagick'), 'Metadata copying is an Imagick-driver behaviour.');

test('forget deletes a derivative and its manifest entry', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('source.jpg', 640, 400);
    Storage::disk('public')->putFileAs('image/2026/06', $file, 'source.jpg');

    $upload = makeImageVariantUpload();
    $service = app(ImageVariantService::class);
    $service->url($upload, 'cover_sm', true);

    Storage::disk('public')->assertExists('image/2026/06/source.jpg.160x96.webp');
    expect(data_get($upload->config, 'variants.cover_sm.status'))->toBe('ready');

    expect($service->forget($upload, 'cover_sm'))->toBeTrue();

    Storage::disk('public')->assertMissing('image/2026/06/source.jpg.160x96.webp');
    expect(data_get($upload->config, 'variants.cover_sm'))->toBeNull();
});

/** Minimal but structurally valid ICC profile carrying one v2 `desc` tag. */
function iccProfileNamed(string $name): string
{
    $desc = 'desc' . str_repeat("\0", 4) . pack('N', strlen($name) + 1) . $name . "\0";
    $descOffset = 132 + 12;
    $header = str_repeat("\0", 128);
    $table = pack('N', 1) . 'desc' . pack('N', $descOffset) . pack('N', strlen($desc));

    return $header . $table . $desc;
}

test('an sRGB profile is dropped from the derivative', function () {
    Storage::fake('public');

    $image = new Imagick();
    $image->newImage(640, 400, new ImagickPixel('rgb(180,60,40)'));
    $image->setImageFormat('jpeg');
    $image->setImageProfile('icc', iccProfileNamed('sRGB IEC61966-2.1'));
    Storage::disk('public')->put('image/2026/06/source.jpg', $image->getImageBlob());

    app(ImageVariantService::class)->url(makeImageVariantUpload(), 'cover_sm', true);

    $variant = new Imagick();
    $variant->readImageBlob(Storage::disk('public')->get('image/2026/06/source.jpg.160x96.webp'));

    expect($variant->getImageProfiles('*', false))->toBe([]);
})->skip(! extension_loaded('imagick'), 'Profile handling is an Imagick-driver behaviour.');

test('a wide-gamut profile survives the strip', function () {
    Storage::fake('public');

    //  Dropping this one would make a browser read Display P3 pixels as sRGB
    //  and render the photo flat — the bytes it costs are the cheaper mistake.
    $image = new Imagick();
    $image->newImage(640, 400, new ImagickPixel('rgb(180,60,40)'));
    $image->setImageFormat('jpeg');
    $image->setImageProfile('icc', iccProfileNamed('Display P3'));
    $image->setImageProfile('exif', str_repeat('E', 6000));
    Storage::disk('public')->put('image/2026/06/source.jpg', $image->getImageBlob());

    app(ImageVariantService::class)->url(makeImageVariantUpload(), 'cover_sm', true);

    $variant = new Imagick();
    $variant->readImageBlob(Storage::disk('public')->get('image/2026/06/source.jpg.160x96.webp'));
    $profiles = $variant->getImageProfiles('*', false);

    expect($profiles)->toContain('icc')
        ->and($profiles)->not->toContain('exif');
})->skip(! extension_loaded('imagick'), 'Profile handling is an Imagick-driver behaviour.');
