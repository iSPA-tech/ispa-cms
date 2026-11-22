<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The admin image block picker allows two images per block; every public
// content view must render all of them, not only `value[0]`.
function sharedTwoImageBlock(): array
{
    return [
        'type' => 'image',
        'value' => [
            ['name' => 'first.jpg', 'url' => 'https://cdn.example.test/image/first.jpg'],
            ['name' => 'second.jpg', 'url' => 'https://cdn.example.test/image/second.jpg'],
        ],
        'options' => ['caption' => 'Chú thích ảnh'],
    ];
}

it('renders every image of an image block in the shared content views', function (string $view, array $data): void {
    $html = view($view, $data)->render();

    expect($html)->toContain('src="https://cdn.example.test/image/first.jpg"')
        ->toContain('src="https://cdn.example.test/image/second.jpg"')
        ->and(substr_count($html, 'Chú thích ảnh'))->toBe(1)
        ->and(strpos($html, 'second.jpg'))->toBeLessThan(strpos($html, 'Chú thích ảnh'));
})->with([
    'contents-view' => ['components.contents-view', ['content' => [sharedTwoImageBlock()]]],
    'page-content-view' => ['components.page-content-view', ['content' => [sharedTwoImageBlock()]]],
    'static-page-view' => ['components.static-page-view', ['page' => (object) ['content' => [sharedTwoImageBlock()]]]],
]);

it('skips empty image items and keeps relative legacy urls', function (): void {
    $html = view('components.contents-view', ['content' => [[
        'type' => 'image',
        'value' => [null, ['name' => 'old.jpg', 'alt_text' => 'Nhà thờ cũ', 'url' => 'storage/image/old.jpg']],
        'options' => ['caption' => 'Ảnh cũ', 'alt' => null],
    ]]])->render();

    expect($html)->toContain('src="/storage/image/old.jpg"')
        ->toContain('alt="Nhà thờ cũ"')
        ->toContain('>Ảnh cũ</figcaption>');
});
