<?php

namespace Cms\Core\Tests\Unit\Services\Template;

use Cms\Core\Services\Import\ImportContext;
use Cms\Core\Services\Import\ImportDefinitionInterface;
use Cms\Core\Services\Import\ImportValidationContext;
use Cms\Core\Services\Import\ResolvedRow;
use Cms\Core\Services\Template\ImportTemplateGenerator;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DropdownTemplateModel extends Model
{
    protected $table = 'dropdown_template_models';
}

class DropdownTemplateImportDefinition implements ImportDefinitionInterface
{
    public function modelClass(): string
    {
        return DropdownTemplateModel::class;
    }

    public function fillable(): array
    {
        return ['name', 'status', 'data_status'];
    }

    public function uniqueBy(): array
    {
        return ['name'];
    }

    public function uniqueSets(): ?array
    {
        return null;
    }

    public function casts(): array
    {
        return [];
    }

    public function defaults(): array
    {
        return [];
    }

    public function transformers(): array
    {
        return [];
    }

    public function resolveRow(array $raw, ImportContext $ctx): ResolvedRow
    {
        return new ResolvedRow($raw, 0);
    }

    public function rules(ImportValidationContext $ctx): array
    {
        return [];
    }

    public function beforeValidate(ResolvedRow $row, ImportContext $ctx): void
    {
    }

    public function afterValidate(ResolvedRow $row, ImportContext $ctx): void
    {
    }

    public function beforeUpsert(ResolvedRow $row, ImportContext $ctx): void
    {
    }

    public function afterUpsert(Model $model, ResolvedRow $row, ImportContext $ctx): void
    {
    }

    public function handlePivotRelations(ResolvedRow $row, Model $model, ImportContext $ctx): void
    {
    }

    public function templateKey(): string
    {
        return 'App/dropdown-test';
    }

    public function templateHeaders(): array
    {
        return ['name', 'status', 'data_status'];
    }

    public function templateNotes(): array
    {
        return ['status' => 'draft, published'];
    }

    public function templateDropdowns(): array
    {
        return [
            'status' => ['draft', 'published'],
            'data_status' => ['pending_verification', 'verified_public'],
            'unknown_header' => ['ignored'],
        ];
    }

    public function inputMappings(): array
    {
        return [];
    }
}

final class ImportTemplateGeneratorTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('template/App/dropdown-test'));

        parent::tearDown();
    }

    public function test_xlsx_template_adds_dropdown_validations_for_declared_columns(): void
    {
        $result = $this->app->make(ImportTemplateGenerator::class)
            ->generate(new DropdownTemplateImportDefinition(), ['xlsx'], true);

        $path = storage_path('template/App/dropdown-test/template.xlsx');

        $this->assertSame([$path], $result['generated']);
        $this->assertSame([], $result['warnings']);
        $this->assertFileExists($path);

        $spreadsheet = IOFactory::load($path);
        $templateSheet = $spreadsheet->getSheetByName('Template');
        $dropdownSheet = $spreadsheet->getSheetByName('Dropdowns');

        $this->assertNotNull($templateSheet);
        $this->assertNotNull($dropdownSheet);
        $this->assertSame(Worksheet::SHEETSTATE_HIDDEN, $dropdownSheet->getSheetState());

        $this->assertSame('name', $templateSheet->getCell('A1')->getValue());
        $this->assertSame('status', $templateSheet->getCell('B1')->getValue());
        $this->assertSame('data_status', $templateSheet->getCell('C1')->getValue());
        $this->assertSame('draft', $dropdownSheet->getCell('A1')->getValue());
        $this->assertSame('published', $dropdownSheet->getCell('A2')->getValue());
        $this->assertSame('pending_verification', $dropdownSheet->getCell('B1')->getValue());
        $this->assertSame('verified_public', $dropdownSheet->getCell('B2')->getValue());

        $statusValidation = $templateSheet->getCell('B2')->getDataValidation();
        $this->assertSame(DataValidation::TYPE_LIST, $statusValidation->getType());
        $this->assertSame("'Dropdowns'!\$A\$1:\$A\$2", $statusValidation->getFormula1());
        $this->assertTrue($statusValidation->getAllowBlank());
        $this->assertTrue($statusValidation->getShowDropDown());

        $dataStatusValidation = $templateSheet->getCell('C1001')->getDataValidation();
        $this->assertSame(DataValidation::TYPE_LIST, $dataStatusValidation->getType());
        $this->assertSame("'Dropdowns'!\$B\$1:\$B\$2", $dataStatusValidation->getFormula1());

        $this->assertSame(DataValidation::TYPE_NONE, $templateSheet->getCell('A2')->getDataValidation()->getType());

        $spreadsheet->disconnectWorksheets();
    }
}
