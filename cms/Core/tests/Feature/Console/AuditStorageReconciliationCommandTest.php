<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

it('runs read-only against an empty dataset and writes csv reports for every category', function () {
    Storage::fake('public');
    $csvDir = sys_get_temp_dir() . '/storage-audit-' . uniqid();

    $this->artisan('storage:audit-reconciliation', ['--csv-dir' => $csvDir])
        ->assertExitCode(0);

    foreach (['unexpected_new_roots', 'pivot_points_to_missing_upload', 'upload_missing_original_file', 'filesystem_orphan_files', 'variant_without_original'] as $category) {
        expect(file_exists("{$csvDir}/{$category}.csv"))->toBeTrue();
    }
});
