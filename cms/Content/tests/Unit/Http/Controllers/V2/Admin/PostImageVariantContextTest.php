<?php

uses(\Cms\Core\Tests\TestCase::class);

use App\Clients\CmsClient;
use App\Clients\OpenAIClient;
use Cms\Content\Http\Controllers\V2\Admin\PostController;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Models\Post;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Interfaces\CategoryTargetInterface;
use Cms\Core\Models\Upload;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    if (!Schema::hasTable('uploads')) {
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->string('disk')->nullable();
            $table->string('type')->nullable();
            $table->string('path')->nullable();
            $table->string('name')->nullable();
            $table->string('mime_type')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
});

test('admin post cover warmup generates card detail and social variants', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('post-cover.jpg', 1600, 1000);
    Storage::disk('public')->putFileAs('image/2026/06', $file, 'post-cover.jpg');

    $upload = new Upload([
        'disk' => 'public',
        'type' => 'image',
        'path' => 'image/2026/06',
        'name' => 'post-cover.jpg',
        'mime_type' => 'image/jpeg',
        'config' => [],
    ]);

    $post = new Post();
    $post->setRelation('image', $upload);

    $postRepository = \Mockery::mock(PostInterface::class);
    $postRepository->shouldReceive('model')->andReturn(new Post());

    $controller = new PostController(
        \Mockery::mock(CategoryInterface::class),
        \Mockery::mock(CategoryTargetInterface::class),
        $postRepository,
        \Mockery::mock(CmsClient::class),
        \Mockery::mock(OpenAIClient::class),
    );

    $method = new \ReflectionMethod($controller, 'warmPostCoverVariants');
    $method->setAccessible(true);
    $method->invoke($controller, $post);

    Storage::disk('public')->assertExists('image/2026/06/post-cover.jpg.600x360.webp');
    Storage::disk('public')->assertExists('image/2026/06/post-cover.jpg.1200x720.webp');
    Storage::disk('public')->assertExists('image/2026/06/post-cover.jpg.1200x630.webp');
    Storage::disk('public')->assertExists('image/2026/06/post-cover.jpg.1024x512.webp');
});

test('admin post content warmup scales block and inline images without cover cropping', function () {
    Storage::fake('public');

    Storage::disk('public')->putFileAs(
        'image/2026/06',
        UploadedFile::fake()->image('content-block.jpg', 1600, 1000),
        'content-block.jpg'
    );
    Storage::disk('public')->putFileAs(
        'image/2026/06',
        UploadedFile::fake()->image('content-inline.jpg', 1200, 900),
        'content-inline.jpg'
    );

    $blockUpload = Upload::query()->create([
        'disk' => 'public',
        'type' => 'image',
        'path' => 'image/2026/06',
        'name' => 'content-block.jpg',
        'mime_type' => 'image/jpeg',
        'config' => [],
    ]);
    $inlineUpload = Upload::query()->create([
        'disk' => 'public',
        'type' => 'image',
        'path' => 'image/2026/06',
        'name' => 'content-inline.jpg',
        'mime_type' => 'image/jpeg',
        'config' => [],
    ]);

    $post = new Post([
        'content' => [
            ['type' => 'image', 'value' => [['id' => $blockUpload->id]]],
            [
                'type' => 'text',
                'value' => '<figure><img src="/storage/content-inline.jpg" data-upload-id="' . $inlineUpload->id . '"></figure>',
            ],
        ],
    ]);

    $controller = makePostImageVariantController();
    $method = new \ReflectionMethod($controller, 'warmPostContentImageVariants');
    $method->setAccessible(true);
    $method->invoke($controller, $post);

    foreach (['content-block.jpg', 'content-inline.jpg'] as $name) {
        Storage::disk('public')->assertExists('image/2026/06/' . $name . '.768x0.webp');
        Storage::disk('public')->assertExists('image/2026/06/' . $name . '.1200x0.webp');
        Storage::disk('public')->assertExists('image/2026/06/' . $name . '.1440x0.webp');
        Storage::disk('public')->assertMissing('image/2026/06/' . $name . '.600x360.webp');
    }
});

function makePostImageVariantController(): PostController
{
    $postRepository = \Mockery::mock(PostInterface::class);
    $postRepository->shouldReceive('model')->andReturn(new Post());

    return new PostController(
        \Mockery::mock(CategoryInterface::class),
        \Mockery::mock(CategoryTargetInterface::class),
        $postRepository,
        \Mockery::mock(CmsClient::class),
        \Mockery::mock(OpenAIClient::class),
    );
}
