<?php

uses(\Cms\Core\Tests\TestCase::class);

use App\Jobs\RequestCropImageJob;
use Cms\Core\Models\Upload;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('upload crop accessor is read safe and does not dispatch crop jobs', function () {
    Storage::fake('public');
    Queue::fake();

    $upload = new Upload([
        'disk' => 'public',
        'type' => 'image',
        'path' => 'image/2026/06',
        'name' => 'source.jpg',
        'mime_type' => 'image/jpeg',
        'config' => [],
    ]);

    $url = $upload->cropImage('cover_lg');

    expect($url)->toContain('/storage/image/2026/06/source.jpg');
    Queue::assertNotPushed(RequestCropImageJob::class);
});
