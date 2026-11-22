<?php

namespace Cms\Core\Tests\Unit\Services\Import;

use Cms\Core\Services\Import\CoreImportEngine;
use Cms\Core\Services\Import\ImportContext;
use Cms\Core\Services\Import\ImportDefinitionInterface;
use Cms\Core\Services\Import\ImportOptions;
use Cms\Core\Services\Import\ImportValidationContext;
use Cms\Core\Services\Import\Parser\ParserInterface;
use Cms\Core\Services\Import\Parser\ParserRegistry;
use Cms\Core\Services\Import\ResolvedRow;
use Cms\Core\Services\Import\RowResult;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;

class TestImportModel extends Model
{
    use SoftDeletes;
    protected $table = 'test_import_models';
    protected $guarded = [];
}

class TestImportDefinition implements ImportDefinitionInterface
{
    public function modelClass(): string { return TestImportModel::class; }
    public function fillable(): array { return ['name', 'email', 'age']; }
    public function uniqueBy(): array { return ['email']; }
    public function uniqueSets(): ?array { return null; }
    public function casts(): array { return ['age' => 'int']; }
    public function defaults(): array { return ['age' => 18]; }
    public function transformers(): array { 
        return [
            'name' => function(array $row, ImportContext $ctx) { return strtoupper($row['name']); }
        ];
    }
    public function resolveRow(array $raw, ImportContext $ctx): ResolvedRow {
        return new ResolvedRow($raw, 0); 
    }
    public function rules(ImportValidationContext $ctx): array {
        return [
            'email' => 'required|email',
            'name' => 'required'
        ];
    }
    public function beforeValidate(ResolvedRow $row, ImportContext $ctx): void {}
    public function afterValidate(ResolvedRow $row, ImportContext $ctx): void {}
    public function beforeUpsert(ResolvedRow $row, ImportContext $ctx): void {}
    public function afterUpsert(Model $model, ResolvedRow $row, ImportContext $ctx): void {}
    public function handlePivotRelations(ResolvedRow $row, Model $model, ImportContext $ctx): void {}
    public function templateKey(): string { return 'tests/import-models'; }
    public function templateHeaders(): array { return ['name', 'email', 'age']; }
    public function templateNotes(): array { return []; }
    public function inputMappings(): array { return []; }
}

class CoreImportEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        Schema::create('test_import_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->integer('age')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    protected function createEngine(iterable $rows): CoreImportEngine
    {
        // Mock Parser
        $parser = $this->createMock(ParserInterface::class);
        $parser->method('rows')->willReturn($rows);
        
        // Mock Registry
        $registry = $this->createMock(ParserRegistry::class);
        $registry->method('getParser')->willReturn($parser);
        
        return $this->app->make(CoreImportEngine::class, ['parserRegistry' => $registry]);
    }

    public function test_dry_run_does_not_write()
    {
        $rows = new \ArrayIterator([
            2 => ['name' => 'test', 'email' => 'test@example.com'] 
        ]);
        
        $engine = $this->createEngine($rows);
        $def = new TestImportDefinition();
        $options = new ImportOptions(dry_run: true);
        $file = UploadedFile::fake()->create('test.csv');
        $ctx = new ImportContext($def, $options, 'user_1');
        
        $report = $engine->run($def, $file, $options, $ctx);
        
        $this->assertTrue($report->ok);
        $this->assertEquals(1, $report->total_rows);
        // Requirement: Dry run returns action create/update properly but logic says it's simulated.
        // My implementation returns RowResult::ACTION_CREATE/UPDATE/SKIP even in dry run results, but marked as dry run message?
        // Wait, step 160 impl: "if ($options->dry_run) { return new RowResult(..., $action, ... 'Dry run simulation: ' . $action, ...); }"
        // So checking action is correct.
        $this->assertEquals(RowResult::ACTION_CREATE, $report->row_results[0]->action);
        
        $this->assertDatabaseCount('test_import_models', 0);
    }

    public function test_upsert_restore_soft_deleted()
    {
        // Create trashed
        TestImportModel::create(['name' => 'Should Restore', 'email' => 'restore@example.com'])->delete();
        
        $rows = new \ArrayIterator([
            2 => ['name' => 'Restored Name', 'email' => 'restore@example.com'],
            3 => ['name' => 'New User', 'email' => 'new@example.com']
        ]);
        
        $engine = $this->createEngine($rows);
        $def = new TestImportDefinition();
        $options = new ImportOptions();
        $file = UploadedFile::fake()->create('test.csv');
        $ctx = new ImportContext($def, $options, 'user_1');
        
        $report = $engine->run($def, $file, $options, $ctx);
        
        $this->assertTrue($report->ok);
        
        $this->assertDatabaseHas('test_import_models', ['email' => 'new@example.com', 'name' => 'NEW USER']);
        $restored = TestImportModel::withTrashed()->where('email', 'restore@example.com')->first();
        $this->assertFalse($restored->trashed());
        $this->assertEquals('RESTORED NAME', $restored->name);
    }

    public function test_skip_if_no_changes()
    {
        // Existing
        TestImportModel::create(['name' => 'EXISTING USER', 'email' => 'exist@example.com', 'age' => 20]);
        
        $rows = new \ArrayIterator([
            2 => ['name' => 'Existing User', 'email' => 'exist@example.com', 'age' => 20] // Same data (after transform)
        ]);
        
        $engine = $this->createEngine($rows);
        $def = new TestImportDefinition();
        $options = new ImportOptions();
        $file = UploadedFile::fake()->create('test.csv');
        $ctx = new ImportContext($def, $options, 'user_1');
        
        $report = $engine->run($def, $file, $options, $ctx);
        
        $this->assertEquals(RowResult::ACTION_SKIP, $report->row_results[0]->action);
        $this->assertEquals(1, $report->skipped_count);
        $this->assertEquals(0, $report->updated_count);
    }

    public function test_always_update_flag()
    {
        // Existing
        TestImportModel::create(['name' => 'EXISTING USER', 'email' => 'exist@example.com', 'age' => 20]);
        
        $rows = new \ArrayIterator([
            2 => ['name' => 'Existing User', 'email' => 'exist@example.com', 'age' => 20] // Same data
        ]);
        
        $engine = $this->createEngine($rows);
        $def = new TestImportDefinition();
        $options = new ImportOptions(always_update: true); // Force update
        $file = UploadedFile::fake()->create('test.csv');
        $ctx = new ImportContext($def, $options, 'user_1');
        
        $report = $engine->run($def, $file, $options, $ctx);
        
        $this->assertEquals(RowResult::ACTION_UPDATE, $report->row_results[0]->action);
        $this->assertEquals(1, $report->updated_count);
    }

    public function test_validation_context_has_existing_model()
    {
        $existing = TestImportModel::create(['name' => 'OLD NAME', 'email' => 'exist@example.com']);
        
        $rows = new \ArrayIterator([
            2 => ['name' => 'New Name', 'email' => 'exist@example.com']
        ]);
        
        $def = new class extends TestImportDefinition {
             public function rules(ImportValidationContext $ctx): array {
                 if ($ctx->existingModel && $ctx->existingModel->name === 'OLD NAME') {
                     return ['name' => 'required|in:ALLOWED']; // Will fail
                 }
                 return [];
             }
        };
        
        $engine = $this->createEngine($rows);
        $options = new ImportOptions();
        $file = UploadedFile::fake()->create('test.csv');
        $ctx = new ImportContext($def, $options, 'user_1');
        
        $report = $engine->run($def, $file, $options, $ctx);
        
        $this->assertEquals(RowResult::ACTION_FAIL, $report->row_results[0]->action);
        $this->assertStringContainsString('Validation failed', $report->row_results[0]->message);
    }

    public function test_transaction_rollback_single_mode()
    {
        $rows = new \ArrayIterator([
            2 => ['name' => 'Row 1', 'email' => 'row1@example.com'],
            3 => ['name' => 'Row 2', 'email' => 'row2@example.com']
        ]);
        
        $def = new class extends TestImportDefinition {
             public function afterUpsert(Model $model, ResolvedRow $row, ImportContext $ctx): void {
                 if ($row->get('email') === 'row2@example.com') {
                     throw new \Exception('Boom');
                 }
             }
        };
        
        $engine = $this->createEngine($rows);
        $options = new ImportOptions(transaction_mode: 'single');
        $file = UploadedFile::fake()->create('test.csv');
        $ctx = new ImportContext($def, $options, 'user_1');
        
        try {
            $engine->run($def, $file, $options, $ctx);
        } catch (\Exception $e) {
            $this->assertStringContainsString('Boom', $e->getMessage());
            $this->assertStringContainsString('Error processing row 3', $e->getMessage());
        }
        
        $this->assertDatabaseCount('test_import_models', 0);
    }

    public function test_transaction_chunk_mode_commits_partial()
    {
        $rows = new \ArrayIterator([
             2 => ['name' => 'Row 1', 'email' => 'row1@example.com'],
             3 => ['name' => 'Row 2', 'email' => 'row2@example.com']
        ]);
        
        $def = new class extends TestImportDefinition {
             public function afterUpsert(Model $model, ResolvedRow $row, ImportContext $ctx): void {
                 if ($row->get('email') === 'row2@example.com') {
                     throw new \Exception('Boom');
                 }
             }
        };
        
        $engine = $this->createEngine($rows);
        $options = new ImportOptions(transaction_mode: 'chunk', chunk_size: 1); 
        $file = UploadedFile::fake()->create('test.csv');
        $ctx = new ImportContext($def, $options, 'user_1');
        
        try {
            $engine->run($def, $file, $options, $ctx);
        } catch (\Exception $e) {
        }
        
        $this->assertDatabaseHas('test_import_models', ['email' => 'row1@example.com']);
        $this->assertDatabaseMissing('test_import_models', ['email' => 'row2@example.com']);
    }

    public function test_error_export_creation()
    {
        $rows = new \ArrayIterator([
            2 => ['name' => '', 'email' => 'invalid-email'] 
        ]);
        
        $engine = $this->createEngine($rows);
        $def = new TestImportDefinition();
        $options = new ImportOptions();
        $file = UploadedFile::fake()->create('test.csv');
        $ctx = new ImportContext($def, $options, 'user_1');
        
        $report = $engine->run($def, $file, $options, $ctx);
        
        $this->assertFalse($report->row_results[0]->success);
        $this->assertNotNull($report->export_errors_path);
        
        $this->assertFileExists(storage_path('app/' . $report->export_errors_path));
        @unlink(storage_path('app/' . $report->export_errors_path));
    }

    public function test_does_not_overwrite_missing_keys()
    {
        // Model with age
        TestImportModel::create(['name' => 'Existing', 'email' => 'exist@example.com', 'age' => 25]);
        
        // Import without age column
        $rows = new \ArrayIterator([
            2 => ['name' => 'Updated Name', 'email' => 'exist@example.com'] 
        ]);
        
        // Disable defaults for this test to ensure "age" is truly missing
        $def = new class extends TestImportDefinition {
            public function defaults(): array { return []; }
        };
        
        $engine = $this->createEngine($rows);
        $options = new ImportOptions();
        $file = UploadedFile::fake()->create('test.csv');
        $ctx = new ImportContext($def, $options, 'user_1');
        
        $report = $engine->run($def, $file, $options, $ctx);
        
        $this->assertTrue($report->ok);
        $model = TestImportModel::where('email', 'exist@example.com')->first();
        $this->assertEquals('UPDATED NAME', $model->name);
        $this->assertEquals(25, $model->age); 
    }

    public function test_unique_sets_priority()
    {
        // Two models
        $m1 = TestImportModel::create(['name' => 'By Email', 'email' => 'match@email.com', 'age' => 10]);
        $m2 = TestImportModel::create(['name' => 'By Age', 'email' => 'other@email.com', 'age' => 20]);
        
        // Row matches m1 by email, m2 by age
        // Set uniqueSets to match by age first
        // We use a non-conflicting email for the update to ensure success
        // BUT to test priority against uniqueBy, we usually rely on 'uniqueBy' matching m1.
        // If we change email here, uniqueBy (email) WON'T match M1. 
        // So we can't test "priority" if only one matches.
        // We need BOTH to match different records.
        // If both match different records, we must pick one.
        // Priority says: uniqueSets. So we match M2.
        // If we match M2, we try to update M2 with "email" => 'match@email.com'.
        // This causes collision with M1. 
        // So we EXPECT a QueryException.
        
        $rows = new \ArrayIterator([
            2 => ['name' => 'UPDATED BY AGE', 'email' => 'match@email.com', 'age' => 20]
        ]);
        
        $def = new class extends TestImportDefinition {
             public function uniqueSets(): ?array {
                 return [['age']];
             }
        };
        
        $engine = $this->createEngine($rows);
        $options = new ImportOptions();
        $file = UploadedFile::fake()->create('test.csv');
        $ctx = new ImportContext($def, $options, 'user_1');
        
        try {
            $engine->run($def, $file, $options, $ctx);
            $this->fail('Should have failed with unique constraint.');
        } catch (\Illuminate\Database\QueryException $e) {
            // Success: it tried to update m2 to m1's email
            $this->assertStringContainsString('UNIQUE constraint failed', $e->getMessage());
        }
    }
}
