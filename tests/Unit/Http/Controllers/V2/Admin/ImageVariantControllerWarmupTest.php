<?php

uses(\Cms\Core\Tests\TestCase::class);

use App\Http\Controllers\V2\Admin\BannerController;
use App\Http\Controllers\V2\Admin\GalleryController;
use App\Repositories\Interfaces\BannerInterface;
use App\Repositories\Interfaces\GalleryInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Core\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

function imageVariantControllerUpload(string $name): Upload
{
    return new Upload([
        'disk' => 'public',
        'type' => 'image',
        'path' => 'image/2026/06',
        'name' => $name,
        'mime_type' => 'image/jpeg',
        'file_size' => 1000,
        'dimensions' => ['width' => 1800, 'height' => 1200],
        'config' => [],
    ]);
}

function imageVariantControllerOwner(array $images): object
{
    return new class($images) {
        public int $id = 123;

        public function __construct(private readonly array $images)
        {
        }

        public function images(): object
        {
            return new class($this->images) {
                public function __construct(private readonly array $images)
                {
                }

                public function get(): Collection
                {
                    return collect($this->images);
                }
            };
        }
    };
}

function invokeImageVariantControllerWarmup(object $controller, string $method, object $owner): void
{
    $reflection = new ReflectionMethod($controller, $method);
    $reflection->setAccessible(true);
    $reflection->invoke($controller, $owner);
}

test('gallery warmup scales gallery items and cover crops the first image only', function () {
    Storage::fake('public');

    Storage::disk('public')->putFileAs(
        'image/2026/06',
        UploadedFile::fake()->image('cover.jpg', 1800, 1200),
        'cover.jpg',
    );
    Storage::disk('public')->putFileAs(
        'image/2026/06',
        UploadedFile::fake()->image('item.jpg', 1800, 1200),
        'item.jpg',
    );

    $cover = imageVariantControllerUpload('cover.jpg');
    $item = imageVariantControllerUpload('item.jpg');

    $controller = new GalleryController(
        $this->createMock(GalleryInterface::class),
        $this->createMock(CategoryInterface::class),
    );

    invokeImageVariantControllerWarmup($controller, 'warmGalleryImageVariants', imageVariantControllerOwner([$cover, $item]));

    Storage::disk('public')->assertExists('image/2026/06/cover.jpg.768x0.webp');
    Storage::disk('public')->assertExists('image/2026/06/cover.jpg.1200x720.webp');
    Storage::disk('public')->assertExists('image/2026/06/cover.jpg.1200x630.webp');
    Storage::disk('public')->assertExists('image/2026/06/item.jpg.768x0.webp');
    Storage::disk('public')->assertMissing('image/2026/06/item.jpg.600x360.webp');
});

test('banner warmup scales banner images without cover cropping', function () {
    Storage::fake('public');

    Storage::disk('public')->putFileAs(
        'image/2026/06',
        UploadedFile::fake()->image('banner.jpg', 1800, 1200),
        'banner.jpg',
    );

    $controller = new BannerController(
        $this->createMock(BannerInterface::class),
        $this->createMock(UploadInterface::class),
    );

    invokeImageVariantControllerWarmup(
        $controller,
        'warmBannerImageVariants',
        imageVariantControllerOwner([imageVariantControllerUpload('banner.jpg')]),
    );

    Storage::disk('public')->assertExists('image/2026/06/banner.jpg.768x0.webp');
    Storage::disk('public')->assertExists('image/2026/06/banner.jpg.1200x0.webp');
    Storage::disk('public')->assertExists('image/2026/06/banner.jpg.1440x0.webp');
    Storage::disk('public')->assertMissing('image/2026/06/banner.jpg.600x360.webp');
});
