<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import\Parser;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Registry that maps file extensions to parser implementations.
 *
 * Maps csv/txt to CsvParser and xlsx/xls to SpreadsheetParser.
 * Resolves the appropriate parser based on the uploaded file's extension.
 *
 * @see ParserInterface
 * @see CsvParser
 * @see SpreadsheetParser
 * @package Cms\Core\Services\Import\Parser
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ParserRegistry
{
    protected array $parsers = [];

    /**
     * Initialize the registry with CSV and spreadsheet parser implementations.
     *
     * @param CsvParser         $csvParser         Parser for CSV and TXT files.
     * @param SpreadsheetParser $spreadsheetParser Parser for XLSX and XLS files.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        CsvParser $csvParser,
        SpreadsheetParser $spreadsheetParser
    ) {
        $this->parsers = [
            'csv' => $csvParser,
            'txt' => $csvParser,
            'xlsx' => $spreadsheetParser,
            'xls' => $spreadsheetParser,
        ];
    }

    /**
     * Resolve the appropriate parser for the given uploaded file.
     *
     * @param UploadedFile $file The uploaded file.
     * @return ParserInterface The resolved parser.
     * @throws InvalidArgumentException If the file extension is unsupported.
     */
    public function getParser(UploadedFile $file): ParserInterface
    {
        $extension = strtolower($file->getClientOriginalExtension());
        
        if (!isset($this->parsers[$extension])) {
            throw new InvalidArgumentException("Unsupported file extension: {$extension}");
        }

        return $this->parsers[$extension];
    }
}
