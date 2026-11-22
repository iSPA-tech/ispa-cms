<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import\Parser;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Cms\Core\Services\Import\ImportOptions;

/**
 * Spreadsheet (XLSX/XLS) parser for the import engine.
 *
 * Reads spreadsheet files using PhpSpreadsheet with chunked reading
 * via ChunkReadFilter to manage memory for large files. Extracts
 * headers from the first row and yields subsequent rows as
 * associative arrays with explicit garbage collection between chunks.
 *
 * @see ParserInterface
 * @see ChunkReadFilter
 * @package Cms\Core\Services\Import\Parser
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class SpreadsheetParser implements ParserInterface
{
    /**
     * {@inheritdoc}
     * @param  UploadedFile $file
     * @param  ImportOptions $options
     * @return iterable
     */
    public function rows(UploadedFile $file, ImportOptions $options): iterable
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);

        // We will read in chunks to avoid memory issues with large files
        $chunkSize = $options->chunk_size;
        $filter = new ChunkReadFilter();
        $reader->setReadFilter($filter);

        // Need to know how many rows there are to loop chunks?
        // Or just loop until empty?
        // PhpSpreadsheet doesn't give row count easily without loading.
        // We can load specific info.
        $info = $reader->listWorksheetInfo($file->getRealPath());
        $totalRows = $info[0]['totalRows'];
        $worksheetName = $info[0]['worksheetName'];

        $filter->setRows(1, 1); // Read header first
        $spreadsheet = $reader->load($file->getRealPath());
        $worksheet = $spreadsheet->getSheetByName($worksheetName);
        $headerRow = $worksheet->toArray()[0];
        $header = array_map('trim', $headerRow);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        // Now loop chunks
        for ($startRow = 2; $startRow <= $totalRows; $startRow += $chunkSize) {
            $endRow = $startRow + $chunkSize - 1;
            if ($endRow > $totalRows) {
                $endRow = $totalRows;
            }

            $filter->setRows($startRow, $chunkSize);
            $reader->setReadFilter($filter);
            $spreadsheet = $reader->load($file->getRealPath());
            $worksheet = $spreadsheet->getSheetByName($worksheetName);
            $iterator = $worksheet->getRowIterator($startRow, $endRow);

            foreach ($iterator as $rowObj) {
                $rowIndex = $rowObj->getRowIndex();
                $cellIterator = $rowObj->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);

                $row = [];
                foreach ($cellIterator as $cell) {
                    $row[] = $cell->getValue();
                }

                // Skip if empty
                if ($this->isEmptyRow($row)) {
                    continue;
                }

                // Comment / label rows (e.g. the template's explanation row)
                // start with '#'; data begins on the first row after them.
                if (is_string($row[0] ?? null) && str_starts_with(ltrim($row[0]), '#')) {
                    continue;
                }

                // Combine
                if (count($header) === count($row)) {
                    yield $rowIndex => array_combine($header, $row);
                } else {
                    $paddedRow = array_pad($row, count($header), null);
                    yield $rowIndex => array_combine($header, array_slice($paddedRow, 0, count($header)));
                }
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            // Memory cleanup
            gc_collect_cycles();
        }
    }

    protected function isEmptyRow($row): bool
    {
        return empty(array_filter($row, static function ($val) {
            return $val !== null && $val !== '';
        }));
    }
}


