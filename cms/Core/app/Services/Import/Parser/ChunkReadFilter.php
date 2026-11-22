<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import\Parser;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * PhpSpreadsheet read filter for chunked row reading.
 *
 * Limits which rows are loaded into memory by defining a start/end
 * row window. Used by SpreadsheetParser to read large files in chunks
 * without exhausting memory.
 *
 * @see SpreadsheetParser
 * @package Cms\Core\Services\Import\Parser
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ChunkReadFilter implements IReadFilter
{
    private int $startRow = 0;
    private int $endRow = 0;

    /**
     * Set the row range to read.
     *
     * @param int $startRow  The first row to include (1-based).
     * @param int $chunkSize The number of rows to read.
     * @return void
     */
    public function setRows(int $startRow, int $chunkSize): void
    {
        $this->startRow = $startRow;
        $this->endRow = $startRow + $chunkSize - 1;
    }

    /**
     * Determine whether a cell should be read based on the row range.
     *
     * @param string $columnAddress The column address.
     * @param int    $row           The row number (1-based).
     * @param string $worksheetName The worksheet name.
     * @return bool True if the cell is within the configured row range.
     */
    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        // Read header row 1 always? No, we handle header separately in main loop logic above
        // But for header reading we did setRows(0,1) which might be row 1.
        // The $row is 1-based index in PhpSpreadsheet.
        
        if ($row >= $this->startRow && $row <= $this->endRow) {
            return true;
        }
        return false;
    }
}
