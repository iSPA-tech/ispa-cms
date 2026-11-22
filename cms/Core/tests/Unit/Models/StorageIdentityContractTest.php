<?php

uses(\Tests\TestCase::class);

use Cms\Core\Models\Storage;
use Cms\Core\Traits\HasUuid;

test('storage model advertises uuid identity support', function () {
    $traits = class_uses_recursive(Storage::class);
    $fillable = (new Storage())->getFillable();
    $migration = file_get_contents(
        'cms/Core/database/migrations/2022_02_09_105250_create_storages_table.php'
    );

    expect($traits)->toContain(HasUuid::class)
        ->and($fillable)->toContain('uuid')
        ->and($migration)->toContain("uuid()");
});
