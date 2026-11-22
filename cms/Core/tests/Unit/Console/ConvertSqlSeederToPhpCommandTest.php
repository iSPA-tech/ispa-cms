<?php

use Cms\Core\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

uses(TestCase::class);

it('converts single-row insert SQL to php array file', function () {
    $tempDir = storage_path('framework/testing/sql-to-php');
    File::deleteDirectory($tempDir);
    File::ensureDirectoryExists($tempDir);

    $input = $tempDir . '/input.sql';
    $output = $tempDir . '/output.php';

    $sql = "INSERT INTO `demo` (`id`, `name`, `score`, `active`, `note`) VALUES (1, 'Alice', 9.5, 1, 'O\'Connor');\n" .
        "INSERT INTO `demo` (`id`, `name`, `score`, `active`, `note`) VALUES (2, 'Bob', NULL, 0, 'Line \\n break');\n";

    File::put($input, $sql);

    $exit = Artisan::call('cms:convert-sql-to-php', [
        '--input' => $input,
        '--output' => $output,
    ]);

    expect($exit)->toBe(0);
    expect(File::exists($output))->toBeTrue();

    $rows = require $output;
    expect($rows)->toBeArray();
    expect($rows)->toHaveCount(2);
    expect($rows[0]['id'])->toBe(1)
        ->and($rows[0]['name'])->toBe('Alice')
        ->and($rows[0]['score'])->toBe(9.5)
        ->and($rows[0]['active'])->toBe(1)
        ->and($rows[0]['note'])->toBe("O'Connor");

    expect($rows[1]['id'])->toBe(2)
        ->and($rows[1]['score'])->toBeNull()
        ->and($rows[1]['note'])->toBe("Line \n break");
});

