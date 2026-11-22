<?php

use Cms\Core\Services\Import\CoreImportEngine;
use Cms\Core\Services\Import\ImportContext;
use Cms\Core\Services\Import\ImportOptions;
use Cms\Core\Services\Import\Parser\CsvParser;
use Cms\Core\Services\Import\Parser\ParserInterface;
use Cms\Core\Services\Import\Parser\ParserRegistry;
use Cms\Core\Services\Import\Parser\SpreadsheetParser;
use Cms\Core\Services\Import\ResolvedRow;
use Cms\Core\Services\Import\RowResult;
use Cms\Core\Tests\TestCase;
use Cms\Core\Tests\Unit\Services\Import\PestTestImportDefinition;
use Cms\Core\Tests\Unit\Services\Import\PestTestImportModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(TestCase::class); // Explicitly bind TestCase

beforeEach(function () {
    Schema::create('pest_import_models', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('email')->unique();
        $table->integer('age')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

// --- Tests ---

test('CSV parser yields correct row_index', function () {
    $content = "name,email\nJohn,john@example.com\n\nJane,jane@example.com";
    // Row 1: Header
    // Row 2: John
    // Row 3: Empty
    // Row 4: Jane
    
    $path = sys_get_temp_dir() . '/test.csv';
    file_put_contents($path, $content);
    $file = new UploadedFile($path, 'test.csv', 'text/csv', null, true);
    
    $parser = new CsvParser();
    $options = new ImportOptions();
    
    $rows = iterator_to_array($parser->rows($file, $options));
    
    // keys of $rows should be the file index
    expect($rows)->toHaveKey(2);
    expect($rows)->toHaveKey(4);
    
    expect($rows[2]['name'])->toBe('John');
    expect($rows[4]['name'])->toBe('Jane');
    
    expect(array_key_exists(3, $rows))->toBeFalse(); // Skipped empty
    
    unlink($path);
});

test('Spreadsheet parser yields correct row_index', function () {
    if (!class_exists(Spreadsheet::class)) {
        $this->markTestSkipped('PhpSpreadsheet not installed');
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'name');
    $sheet->setCellValue('B1', 'email');
    $sheet->setCellValue('A2', 'Row 2');
    $sheet->setCellValue('B2', 'row2@email.com');
    $sheet->setCellValue('A3', 'Row 3');
    $sheet->setCellValue('B3', 'row3@email.com');
    $sheet->setCellValue('A4', 'Row 4');
    $sheet->setCellValue('B4', 'row4@email.com');
    
    $path = sys_get_temp_dir() . '/pest_test.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($path);
    
    $file = new UploadedFile($path, 'pest_test.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    
    $parser = new SpreadsheetParser();
    $options = new ImportOptions();
    
    $rows = iterator_to_array($parser->rows($file, $options));
    
    expect($rows)->toHaveKey(2);
    expect($rows)->toHaveKey(3);
    expect($rows)->toHaveKey(4);
    
    expect($rows[2]['name'])->toBe('Row 2');
    expect($rows[4]['name'])->toBe('Row 4');
    
    unlink($path);
});

test('Dry-run does not write DB but detects actions', function () {
    // 1. New Record -> Create
    $rows = new ArrayIterator([
        2 => ['name' => 'John', 'email' => 'john@test.com']
    ]);
    
    $engine = createEngine($rows);
    $def = new PestTestImportDefinition();
    $options = new ImportOptions(dry_run: true);
    $ctx = new ImportContext($def, $options, 'u1');
    $file = UploadedFile::fake()->create('test.csv');
    
    $report = $engine->run($def, $file, $options, $ctx);
    
    expect($report->ok)->toBeTrue();
    expect($report->row_results[0]->action)->toBe(RowResult::ACTION_CREATE);
    expect(PestTestImportModel::count())->toBe(0);
    
    // 2. Insert record manually
    PestTestImportModel::create(['name' => 'John', 'email' => 'john@test.com']);
    
    // 3. Import same data -> Skip
    $engine = createEngine($rows);
    $report = $engine->run($def, $file, $options, $ctx);
    expect($report->row_results[0]->action)->toBe(RowResult::ACTION_SKIP);
    
    // 4. Import changed data -> Update
    $rowsUpdated = new ArrayIterator([
        2 => ['name' => 'John Updated', 'email' => 'john@test.com']
    ]);
    $engine = createEngine($rowsUpdated);
    $report = $engine->run($def, $file, $options, $ctx);
    expect($report->row_results[0]->action)->toBe(RowResult::ACTION_UPDATE);
    expect(PestTestImportModel::first()->name)->toBe('John'); // DB unchanged
});

test('Upsert restores soft deleted record', function () {
    $model = PestTestImportModel::create(['name' => 'Old', 'email' => 'deleted@test.com']);
    $model->delete();
    
    expect(PestTestImportModel::withTrashed()->find($model->id)->trashed())->toBeTrue();
    
    $rows = new ArrayIterator([
        2 => ['name' => 'Restored', 'email' => 'deleted@test.com']
    ]);
    
    $engine = createEngine($rows);
    $def = new PestTestImportDefinition();
    $options = new ImportOptions(); // Commit mode
    $ctx = new ImportContext($def, $options, 'u1');
    $file = UploadedFile::fake()->create('test.csv');
    
    $report = $engine->run($def, $file, $options, $ctx);
    
    expect($report->ok)->toBeTrue();
    expect($report->row_results[0]->action)->toBe(RowResult::ACTION_UPDATE);
    
    $model->refresh();
    expect($model->trashed())->toBeFalse();
    expect($model->name)->toBe('Restored');
});

test('Transaction mode single rolls back on pivot failure', function () {
    $rows = new ArrayIterator([
        2 => ['name' => 'Valid', 'email' => 'valid@test.com'],
        3 => ['name' => 'Fail', 'email' => 'fail@test.com']
    ]);
    
    $def = new class extends PestTestImportDefinition {
        public function handlePivotRelations(ResolvedRow $row, Model $model, ImportContext $ctx): void {
            if ($row->get('email') === 'fail@test.com') {
                throw new Exception('Pivot failure');
            }
        }
    };
    
    $engine = createEngine($rows);
    $options = new ImportOptions(transaction_mode: 'single');
    $ctx = new ImportContext($def, $options, 'u1');
    $file = UploadedFile::fake()->create('test.csv');
    
    try {
        $engine->run($def, $file, $options, $ctx);
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('Pivot failure');
    }
    
    expect(PestTestImportModel::count())->toBe(0); // Valid row rolled back
});

test('Transaction mode chunk rolls back failing chunk', function () {
    $rows = new ArrayIterator([
        2 => ['name' => 'Chunk1-A', 'email' => 'c1a@test.com'], // Chunk 1
        3 => ['name' => 'Chunk1-B', 'email' => 'c1b@test.com'], // Chunk 1
        4 => ['name' => 'Chunk2-Valid', 'email' => 'c2valid@test.com'], // Chunk 2
        5 => ['name' => 'Chunk2-Fail', 'email' => 'c2fail@test.com'],   // Chunk 2
    ]);
    
    $def = new class extends PestTestImportDefinition {
        public function handlePivotRelations(ResolvedRow $row, Model $model, ImportContext $ctx): void {
            if ($row->get('email') === 'c2fail@test.com') {
                throw new Exception('Pivot failure');
            }
        }
    };
    
    $engine = createEngine($rows);
    $options = new ImportOptions(transaction_mode: 'chunk', chunk_size: 2);
    $ctx = new ImportContext($def, $options, 'u1');
    $file = UploadedFile::fake()->create('test.csv');
    
    try {
        $engine->run($def, $file, $options, $ctx);
    } catch (Throwable $e) {
        // Expected
    }
    
    expect(PestTestImportModel::where('email', 'c1a@test.com')->exists())->toBeTrue();
    expect(PestTestImportModel::where('email', 'c1b@test.com')->exists())->toBeTrue();
    expect(PestTestImportModel::where('email', 'c2valid@test.com')->exists())->toBeFalse(); // Rolled back
});

test('Error CSV export is generated', function () {
    $rows = new ArrayIterator([
        2 => ['name' => 'Invalid', 'email' => 'not-an-email']
    ]);
    
    $engine = createEngine($rows);
    $def = new PestTestImportDefinition();
    $options = new ImportOptions();
    $ctx = new ImportContext($def, $options, 'u1');
    $file = UploadedFile::fake()->create('test.csv');
    
    $report = $engine->run($def, $file, $options, $ctx);
    
    expect($report->row_results[0]->success)->toBeFalse();
    expect($report->export_errors_path)->not->toBeNull();
    
    $fullPath = storage_path('app/' . $report->export_errors_path);
    expect(file_exists($fullPath))->toBeTrue();
    
    // Check content
    $content = file_get_contents($fullPath);
    expect($content)->toContain('not-an-email');
    expect($content)->toContain('2'); // Row index
    
    @unlink($fullPath);
});

// Helpers
function createEngine($rows) {
    if ($rows instanceof IteratorAggregate) {
        $rows = $rows; 
    }
    $parser = Mockery::mock(ParserInterface::class);
    $parser->shouldReceive('rows')->andReturn($rows);
    
    $registry = Mockery::mock(ParserRegistry::class);
    $registry->shouldReceive('getParser')->andReturn($parser);
    
    return app(CoreImportEngine::class, ['parserRegistry' => $registry]);
}
