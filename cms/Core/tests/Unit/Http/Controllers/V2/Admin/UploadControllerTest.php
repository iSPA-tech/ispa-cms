<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use Cms\Content\Interfaces\ContentInterface;
use Cms\Core\Http\Controllers\V2\Admin\UploadController;
use Cms\Core\Interfaces\CategoryTargetInterface;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Core\Models\Upload;
use Cms\Core\Models\UploadR2Object;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('parsePathsToTree builds nested structure', function () {
    $paths = [
        'images/2025/09',
        'images/2025/10',
        'docs/2025',
    ];

    $controller = new UploadController(
        $this->createMock(UploadInterface::class),
        $this->createMock(CategoryTargetInterface::class),
        $this->createMock(ContentInterface::class),
    );

    $tree = $controller->parsePathsToTree($paths);

    expect($tree)->toHaveCount(2);
    $keys = collect($tree)->pluck('key')->all();
    expect($keys)->toContain('images/2025')->toContain('docs/2025');

    $images2025 = collect($tree)->firstWhere('key', 'images/2025');
    expect($images2025)->toBeArray()->toHaveKey('children');
    expect($images2025['children'])->not->toBeEmpty();

    $months = collect($images2025['children'])->pluck('title')->all();
    expect($months)->toContain('09')->toContain('10');

    $y2025 = collect($images2025['children'])->firstWhere('title', '09');
    expect($y2025['key'])->toBe('images/2025/09');
});

test('upload warmup uses all contexts selected from upload metadata', function () {
    Storage::fake('public');

    Storage::disk('public')->putFileAs(
        'image/2026/06',
        UploadedFile::fake()->image('banner.jpg', 1600, 1000),
        'banner.jpg'
    );

    $upload = new Upload([
        'disk' => 'public',
        'type' => 'image',
        'path' => 'image/2026/06',
        'name' => 'banner.jpg',
        'mime_type' => 'image/jpeg',
        'target_folder' => 'Banner',
        'config' => [],
    ]);

    $controller = new UploadController(
        $this->createMock(UploadInterface::class),
        $this->createMock(CategoryTargetInterface::class),
        $this->createMock(ContentInterface::class),
    );

    $method = new ReflectionMethod($controller, 'warmUploadPreviewVariants');
    $method->setAccessible(true);
    $method->invoke($controller, $upload);

    Storage::disk('public')->assertExists('image/2026/06/banner.jpg.80x80.webp');
    Storage::disk('public')->assertExists('image/2026/06/banner.jpg.160x96.webp');
    Storage::disk('public')->assertExists('image/2026/06/banner.jpg.768x0.webp');
    Storage::disk('public')->assertExists('image/2026/06/banner.jpg.1200x0.webp');
    Storage::disk('public')->assertExists('image/2026/06/banner.jpg.1440x0.webp');
});

test('upload warmup also syncs the original and every generated variant to R2', function () {
    Storage::fake('public');
    Storage::fake('r2');

    Storage::disk('public')->putFileAs(
        'image/2026/06',
        UploadedFile::fake()->image('banner.jpg', 1600, 1000),
        'banner.jpg'
    );

    $upload = new Upload([
        'disk' => 'public',
        'type' => 'image',
        'path' => 'image/2026/06',
        'name' => 'banner.jpg',
        'mime_type' => 'image/jpeg',
        'target_folder' => 'Banner',
        'config' => [],
    ]);
    $upload->save();

    $controller = new UploadController(
        $this->createMock(UploadInterface::class),
        $this->createMock(CategoryTargetInterface::class),
        $this->createMock(ContentInterface::class),
    );

    $method = new ReflectionMethod($controller, 'warmUploadPreviewVariants');
    $method->setAccessible(true);
    $method->invoke($controller, $upload);

    expect(UploadR2Object::where('status', 'synced')->count())->toBe(6); // original + 5 variants
    expect(UploadR2Object::where('relative_path', 'image/2026/06/banner.jpg')->first()->status)->toBe('synced');
    expect(UploadR2Object::where('relative_path', 'image/2026/06/banner.jpg.80x80.webp')->first()->status)->toBe('synced');
});
