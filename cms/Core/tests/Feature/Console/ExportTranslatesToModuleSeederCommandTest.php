<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Interfaces\TranslateInterface;
use Cms\Core\Services\TranslationModuleSeederExporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

test('exports module translation seeders with filter option', function () {
    $translates = new Collection([
        (object)['group_name' => 'group', 'name' => 'key', 'modules' => ['Core']],
    ]);

    $translateRepo = Mockery::mock(TranslateInterface::class);
    $translateRepo->shouldReceive('get')
        ->once()
        ->andReturn($translates);

    $exporter = Mockery::mock(TranslationModuleSeederExporter::class);
    $exporter->shouldReceive('export')
        ->once()
        ->with($translates, ['core'])
        ->andReturn([
            'modules' => [
                [
                    'module' => 'Core',
                    'records' => 1,
                    'file' => 'cms/Core/database/seeders/sql/translate.php',
                ],
            ],
            'skipped' => ['Missing'],
        ]);

    app()->instance(TranslateInterface::class, $translateRepo);
    app()->instance(TranslationModuleSeederExporter::class, $exporter);

    $exit = Artisan::call('cms:translation-export-modules', ['--only' => ['Core']]);
    expect($exit)->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('Exporting translates into per-module seeder files')
        ->toContain('Wrote 1 records to cms/Core/database/seeders/sql/translate.php')
        ->toContain("Module folder not found for 'Missing'")
        ->toContain('Export done. Updated 1 module file(s).');
});

test('warns when no translations available for export', function () {
    $translateRepo = Mockery::mock(TranslateInterface::class);
    $translateRepo->shouldReceive('get')
        ->once()
        ->andReturn(new Collection());

    $exporter = Mockery::mock(TranslationModuleSeederExporter::class);
    $exporter->shouldReceive('export')->never();

    app()->instance(TranslateInterface::class, $translateRepo);
    app()->instance(TranslationModuleSeederExporter::class, $exporter);

    Artisan::call('cms:translation-export-modules');
    expect(Artisan::output())->toContain('No translates found.');
});

test('handles exporter runtime exceptions', function () {
    $translates = new Collection([
        (object)['group_name' => 'group', 'name' => 'key', 'modules' => ['Core']],
    ]);

    $translateRepo = Mockery::mock(TranslateInterface::class);
    $translateRepo->shouldReceive('get')
        ->once()
        ->andReturn($translates);

    $exporter = Mockery::mock(TranslationModuleSeederExporter::class);
    $exporter->shouldReceive('export')
        ->once()
        ->andThrow(new \RuntimeException('missing modules dir'));

    app()->instance(TranslateInterface::class, $translateRepo);
    app()->instance(TranslationModuleSeederExporter::class, $exporter);

    $exit = Artisan::call('cms:translation-export-modules');
    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('missing modules dir');
});
