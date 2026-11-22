<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Template;

use Cms\Core\Services\Import\ImportDefinitionInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Generates downloadable template files for import definitions.
 *
 * Creates CSV, TXT, XLSX, and XLS template files with headers and
 * optional notes sheets derived from the import definition. Templates
 * are stored in module-specific storage directories.
 *
 * @see ImportDefinitionInterface
 * @package Cms\Core\Services\Template
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ImportTemplateGenerator
{
    private const DROPDOWN_MAX_ROWS = 1000;

    /**
     * Generate template files for an import definition in the specified formats.
     *
     * @param ImportDefinitionInterface $definition The import definition.
     * @param array<int, string>       $formats    Formats to generate (csv, xlsx, xls).
     * @param bool                     $force      Whether to overwrite existing files.
     * @return array{generated: array<int, string>, skipped: array<int, string>, warnings: array<int, string>}
     */
    public function generate(ImportDefinitionInterface $definition, array $formats = ['csv', 'xlsx', 'xls'], bool $force = false): array
    {
        $result = [
            'generated' => [],
            'skipped' => [],
            'warnings' => [],
        ];

        $templateKey = $definition->templateKey();
        $headers = $definition->templateHeaders();
        $notes = $definition->templateNotes();
        $labels = method_exists($definition, 'templateLabels')
            ? (array) $definition->templateLabels()
            : [];
        $dropdowns = method_exists($definition, 'templateDropdowns')
            ? (array) $definition->templateDropdowns()
            : [];
        
        // Parse template key to determine module and entity
        // Example: "Education/instructors" => module=Education, entity=instructors
        $parts = explode('/', $templateKey);
        $module = $parts[0] ?? 'Core';
        $entity = $parts[1] ?? 'import';
        
        // Determine base path — App-level definitions use storage/template/
        // Under tests, module templates go below storage/framework/testing so
        // the tracked cms/<Module>/storage/template files are never rewritten.
        $basePath = strtolower($module) === 'app'
            ? storage_path("template/{$module}/{$entity}")
            : (app()->runningUnitTests()
                ? storage_path("framework/testing/template/{$module}/{$entity}")
                : base_path("cms/{$module}/storage/template/{$entity}"));
        
        // Create directory if not exists
        if (!File::exists($basePath)) {
            File::makeDirectory($basePath, 0755, true);
        }
        
        // Generate each format
        foreach ($formats as $format) {
            $filePath = "{$basePath}/template.{$format}";
            
            // Check if file exists and force is false
            if (File::exists($filePath) && !$force) {
                $result['skipped'][] = $filePath;
                continue;
            }
            
            try {
                switch ($format) {
                    case 'csv':
                        $this->generateCsv($filePath, $headers);
                        $result['generated'][] = $filePath;
                        break;

                    case 'txt':
                        $result['warnings'][] = 'TXT template export is disabled; use csv, xlsx, or xls.';
                        break;

                    case 'xlsx':
                        $this->generateXlsx($filePath, $headers, $notes, $dropdowns, $labels);
                        $result['generated'][] = $filePath;
                        break;

                    case 'xls':
                        try {
                            $this->generateXls($filePath, $headers, $notes, $dropdowns, $labels);
                            $result['generated'][] = $filePath;
                        } catch (\Exception $e) {
                            $result['warnings'][] = "XLS format not supported or failed: " . $e->getMessage();
                            Log::warning("Failed to generate XLS template for {$templateKey}: " . $e->getMessage());
                        }
                        break;

                    default:
                        $result['warnings'][] = "Unknown format: {$format}";
                }
            } catch (\Exception $e) {
                $result['warnings'][] = "Failed to generate {$format}: " . $e->getMessage();
                Log::error("Template generation error for {$templateKey}.{$format}: " . $e->getMessage());
            }
        }
        
        return $result;
    }
    
    /**
     * Generate CSV template
     */
    protected function generateCsv(string $filePath, array $headers): void
    {
        $fp = fopen($filePath, 'w');
        fputcsv($fp, $headers);
        fclose($fp);
    }
    
    /**
     * Generate XLSX template
     */
    protected function generateXlsx(string $filePath, array $headers, array $notes, array $dropdowns, array $labels = []): void
    {
        $spreadsheet = new Spreadsheet();
        $labelRows = $this->populateTemplateSheet($spreadsheet->getActiveSheet(), $headers, $labels);
        $this->addNotesSheet($spreadsheet, $notes);
        $this->applyDropdowns($spreadsheet, $headers, $dropdowns, 2 + $labelRows);

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
    }

    /**
     * Generate XLS template (may fail on some systems)
     */
    protected function generateXls(string $filePath, array $headers, array $notes, array $dropdowns, array $labels = []): void
    {
        $spreadsheet = new Spreadsheet();
        $labelRows = $this->populateTemplateSheet($spreadsheet->getActiveSheet(), $headers, $labels);
        $this->addNotesSheet($spreadsheet, $notes);
        $this->applyDropdowns($spreadsheet, $headers, $dropdowns, 2 + $labelRows);

        $writer = new Xls($spreadsheet);
        $writer->save($filePath);
    }

    /**
     * Write the header row and, when labels exist, an explanation row that
     * shows which admin label each column maps to. The label row starts with
     * '#' so import parsers treat it as a comment and data starts one row
     * lower. Returns the number of label rows written (0 or 1).
     *
     * @param list<string> $headers
     * @param array<string, string> $labels
     */
    protected function populateTemplateSheet(Worksheet $sheet, array $headers, array $labels): int
    {
        $sheet->setTitle('Template');

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $col++;
        }

        if ($labels === []) {
            return 0;
        }

        $col = 'A';
        foreach ($headers as $index => $header) {
            $label = trim((string) ($labels[$header] ?? ''));
            if ($index === 0) {
                $label = '# ' . ($label !== '' ? $label : 'Giải thích cột — xóa hoặc giữ dòng này đều được');
            }

            $sheet->setCellValue($col . '2', $label);
            $style = $sheet->getStyle($col . '2');
            $style->getFont()->setItalic(true)->getColor()->setRGB('808080');
            $col++;
        }

        return 1;
    }

    /**
     * @param array<string, string> $notes
     */
    protected function addNotesSheet(Spreadsheet $spreadsheet, array $notes): void
    {
        if (empty($notes)) {
            return;
        }

        $notesSheet = $spreadsheet->createSheet();
        $notesSheet->setTitle('Notes');

        $row = 1;
        foreach ($notes as $key => $value) {
            $notesSheet->setCellValue('A' . $row, $key);
            $notesSheet->setCellValue('B' . $row, $value);
            $row++;
        }
    }

    /**
     * @param list<string> $headers
     * @param array<string, mixed> $dropdowns
     * @param int $dataStartRow First data row on the Template sheet (2 without a label row, 3 with one).
     */
    protected function applyDropdowns(Spreadsheet $spreadsheet, array $headers, array $dropdowns, int $dataStartRow = 2): void
    {
        $prepared = [];

        foreach ($dropdowns as $header => $values) {
            $headerIndex = array_search($header, $headers, true);
            $options = $this->normalizeDropdownValues($values);

            if ($headerIndex === false || $options === []) {
                continue;
            }

            $prepared[] = [
                'header' => (string) $header,
                'headerIndex' => $headerIndex,
                'options' => $options,
            ];
        }

        if ($prepared === []) {
            return;
        }

        $templateSheet = $spreadsheet->getSheetByName('Template') ?? $spreadsheet->getActiveSheet();
        $dropdownSheet = $spreadsheet->createSheet();
        $dropdownSheet->setTitle('Dropdowns');
        $dropdownSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        foreach ($prepared as $dropdownIndex => $dropdown) {
            $sourceColumn = Coordinate::stringFromColumnIndex($dropdownIndex + 1);

            foreach ($dropdown['options'] as $rowIndex => $value) {
                $dropdownSheet->setCellValue($sourceColumn . ($rowIndex + 1), $value);
            }

            $formula = sprintf(
                "'Dropdowns'!$%s$1:$%s$%d",
                $sourceColumn,
                $sourceColumn,
                count($dropdown['options'])
            );
            $targetColumn = Coordinate::stringFromColumnIndex($dropdown['headerIndex'] + 1);

            for ($row = $dataStartRow; $row <= self::DROPDOWN_MAX_ROWS + $dataStartRow - 1; $row++) {
                $validation = $templateSheet->getCell($targetColumn . $row)->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST)
                    ->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setAllowBlank(true)
                    ->setShowDropDown(true)
                    ->setShowInputMessage(true)
                    ->setShowErrorMessage(true)
                    ->setErrorTitle('Invalid value')
                    ->setError('Select a value from the list.')
                    ->setPromptTitle((string) $dropdown['header'])
                    ->setPrompt('Select a value from the list.')
                    ->setFormula1($formula);
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function normalizeDropdownValues(mixed $values): array
    {
        if ($values instanceof \Traversable) {
            $values = iterator_to_array($values);
        }

        if (!is_array($values)) {
            return [];
        }

        $normalized = array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values
        );

        return array_values(array_unique(array_filter(
            $normalized,
            static fn (string $value): bool => $value !== ''
        )));
    }
}
