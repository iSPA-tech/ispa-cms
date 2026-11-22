<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import\Parser;

use Illuminate\Http\UploadedFile;
use Cms\Core\Services\Import\ImportOptions;
use SplFileObject;

/**
 * CSV file parser for the import engine.
 *
 * Reads CSV/TXT files using SplFileObject, auto-detects headers from
 * the first row, strips BOM characters, and yields associative arrays.
 * Handles mismatched column counts via padding/truncation.
 *
 * @see ParserInterface
 * @see ParserRegistry
 * @package Cms\Core\Services\Import\Parser
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CsvParser implements ParserInterface
{
    /**
     * {@inheritdoc}
     * @param  UploadedFile $file
     * @param  ImportOptions $options
     * @return iterable
     */
    public function rows(UploadedFile $file, ImportOptions $options): iterable
    {
        $csv = new SplFileObject($file->getRealPath());
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE); // Do NOT use SKIP_EMPTY
        
        $header = null;
        
        foreach ($csv as $row) {
             // With SKIP_EMPTY off, key() is 0-based sequence which IS the line number (-1).
             // Verify: Line 1 = key 0. Line 2 = key 1.
             $rowIndex = $csv->key() + 1;
             
             // Manual empty check
             // SplFileObject with CSV flag yields [null] on empty line
             if (empty($row) || (count($row) === 1 && $row[0] === null)) {
                 continue;
             }
             
             // Also check purely empty strings joined? No, $row is array.
             // If row is [null], it's empty line.
             // If row is [''], it's a line with empty string - arguably data, but usually CSV empty line is [null].
             // Robust check:
             $isEmpty = count($row) === 1 && ($row[0] === null || trim((string)$row[0]) === '');
             if ($isEmpty) {
                 continue;
             }

            if (!$header) {
                // Header row logic
                // Remove BOM from first column if present
                if (isset($row[0])) {
                    $row[0] = preg_replace('/\x{FEFF}/u', '', $row[0]);
                }
                $header = array_map('trim', $row);
                continue;
            }

            // Comment / label rows (e.g. the template's explanation row) start
            // with '#'; data begins on the first row that is not one of them.
            if (is_string($row[0] ?? null) && str_starts_with(ltrim($row[0]), '#')) {
                continue;
            }

            // Combine logic
            $data = [];
            // Handle mismatched columns
            if (count($header) === count($row)) {
                $data = array_combine($header, $row);
            } else {
                // Pad or truncate
                // If row > header, truncate. If row < header, pad.
                // array_slice($padded, 0, count) handles both truncate and fit from pad.
                $paddedRow = array_pad($row, count($header), null);
                $data = array_combine($header, array_slice($paddedRow, 0, count($header)));
            }

            yield $rowIndex => $data;
        }
    }
}
