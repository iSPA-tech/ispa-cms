<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import;

/**
 * Aggregated report for an import operation.
 *
 * Tracks total/processed rows, created/updated/skipped/failed counts,
 * individual row results, timing, and the error CSV export path.
 *
 * @see CoreImportEngine
 * @see RowResult
 * @package Cms\Core\Services\Import
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ImportReport
{
    public bool $ok = false;
    public string $start_time;
    public ?string $end_time = null;
    public float $duration_ms = 0;
    
    public int $total_rows = 0;
    public int $processed_rows = 0;
    public int $created_count = 0;
    public int $updated_count = 0;
    public int $skipped_count = 0;
    public int $failed_count = 0;
    
    public ?string $export_errors_path = null;
    
    /** @var RowResult[] */
    public array $row_results = []; // Note: For very large imports, we might not want to store all results in memory, but per requirements we need "rows results"

    /**
     * Initialize the report with file metadata and dry-run flag.
     *
     * @param string $fileName      The name of the imported file.
     * @param int    $fileSize      The file size in bytes.
     * @param string $fileExtension The file extension (e.g., 'csv', 'xlsx').
     * @param bool   $dry_run       Whether this is a dry-run (no actual database writes).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        public string $fileName,
        public int $fileSize,
        public string $fileExtension,
        public bool $dry_run
    ) {
        $this->start_time = now()->toIso8601String();
    }
    
    /**
     * Add a row result to the report and update counters.
     *
     * @param RowResult $result The row processing result.
     * @return void
     */
    public function addResult(RowResult $result): void
    {
        $this->total_rows++;
        $this->processed_rows++;
        
        switch ($result->action) {
            case RowResult::ACTION_CREATE:
                $this->created_count++;
                break;
            case RowResult::ACTION_UPDATE:
                $this->updated_count++;
                break;
            case RowResult::ACTION_SKIP:
                $this->skipped_count++;
                break;
            case RowResult::ACTION_FAIL:
                $this->failed_count++;
                break;
        }
        
        // Optimize: maybe only keep failures or sample success?
        // Requirement says "rows results", assuming all for now.
        $this->row_results[] = $result;
    }
    
    /**
     * Finalize the report with success status and timing.
     *
     * @param bool $success Whether the import completed successfully.
     * @return void
     */
    public function finish(bool $success = true): void
    {
        $this->ok = $success;
        $this->end_time = now()->toIso8601String();
        $this->duration_ms = (microtime(true) - strtotime($this->start_time)) * 1000;
    }
}
