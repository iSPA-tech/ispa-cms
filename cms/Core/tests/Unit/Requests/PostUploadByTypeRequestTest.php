<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Http\Requests\Upload\PostUploadByTypeRequest;

test('typed upload max file size accepts byte config and converts it to laravel kilobytes', function () {
    config()->set('setting.upload.max_file_size.image', 5 * 1024 * 1024);

    $request = PostUploadByTypeRequest::create('/api/admin/upload/image', 'POST', ['type' => 'image']);
    app()->instance('request', $request);

    expect($request->rules()['file'])->toContain('max:5120');
});

test('typed upload max file size preserves legacy kilobyte config', function () {
    config()->set('setting.upload.max_file_size.image', 12 * 1024);

    $request = PostUploadByTypeRequest::create('/api/admin/upload/image', 'POST', ['type' => 'image']);
    app()->instance('request', $request);

    expect($request->rules()['file'])->toContain('max:12288');
});
