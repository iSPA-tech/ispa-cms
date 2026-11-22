<?php

use Tests\TestCase;

uses(TestCase::class);

test('admin gallery index loads upload fields required for thumbnails', function () {
    $source = file_get_contents(base_path('app/Http/Controllers/V2/Admin/GalleryController.php'));

    expect($source)->toMatch("/'images:([^']+)'/");

    preg_match("/'images:([^']+)'/", $source, $matches);

    $fields = collect(explode(',', $matches[1]))
        ->map(static fn(string $field) => trim($field))
        ->all();

    expect($fields)->toContain(
        'id',
        'target_id',
        'disk',
        'type',
        'name',
        'path',
        'mime_type',
        'file_size',
        'target_folder',
        'config',
    );
});
