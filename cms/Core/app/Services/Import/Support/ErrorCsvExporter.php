<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import\Support;

use JsonException;
use Cms\Core\Services\Import\RowResult;
use Illuminate\Support\Facades\Storage;

/**
 * Exports failed import rows to a CSV file for review.
 *
 * Creates a CSV file in storage/imports/errors/ with columns for
 * row index, identifiers, error message, and validation errors.
 * Only failed rows are written; successful rows are skipped.
 *
 * @see CoreImportEngine
 * @package Cms\Core\Services\Import\Support
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ErrorCsvExporter
{
    protected $fileHandle;
    protected string $path;

    /**
     * Initialize the exporter, creating the error CSV file with headers.
     *
     * @param string $filename The output filename (stored under imports/errors/).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(string $filename)
    {
        $this->path = 'imports/errors/' . $filename;
        Storage::disk('local')->makeDirectory('imports/errors');
        $fullPath = Storage::disk('local')->path($this->path);

        $this->fileHandle = fopen($fullPath, 'w');
        fputcsv($this->fileHandle, ['row_index', 'identifiers', 'message', 'errors']); // Header
    }

    /**
     * @param  RowResult $result
     * @return void
     * @throws JsonException
     */
    public function addRow(RowResult $result): void
    {
        if ($result->success) {
            return;
        }

        fputcsv($this->fileHandle, [
            $result->rowIndex,
            json_encode($result->identifiers, JSON_THROW_ON_ERROR),
            $result->message,
            json_encode($result->errors, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Close the file handle and return the storage path.
     *
     * @return string The relative storage path of the error CSV.
     */
    public function close(): string
    {
        if (is_resource($this->fileHandle)) {
            fclose($this->fileHandle);
        }
        return $this->path;
    }
}
