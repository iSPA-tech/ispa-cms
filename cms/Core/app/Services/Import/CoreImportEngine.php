<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import;

use Throwable;
use RuntimeException;
use Psr\Log\LoggerInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Events\Dispatcher;
use Cms\Core\Services\Import\Support\CastHelper;
use Cms\Core\Services\Import\Parser\ParserRegistry;
use Cms\Core\Services\Import\Support\ErrorCsvExporter;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;

/**
 * Core engine for importing data from CSV and spreadsheet files.
 *
 * Orchestrates the full import pipeline: parsing, preprocessing, default
 * values, transformers, casts, unique-key matching, validation, upsert,
 * and pivot relation handling. Supports single and chunk transaction modes,
 * dry-run simulation, strict header validation, and error CSV export.
 *
 * @see ImportDefinitionInterface
 * @see ParserRegistry
 * @package Cms\Core\Services\Import
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CoreImportEngine
{
    /**
     * Initialize the import engine with parser, validation, logging, and event dependencies.
     *
     * @param ParserRegistry   $parserRegistry   Registry for resolving file parsers by extension.
     * @param ValidatorFactory $validatorFactory  Factory for creating row validators.
     * @param LoggerInterface  $logger            Logger for import progress and error reporting.
     * @param Dispatcher       $dispatcher        Event dispatcher for import lifecycle events.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected ParserRegistry   $parserRegistry,
        protected ValidatorFactory $validatorFactory,
        protected LoggerInterface  $logger,
        protected Dispatcher       $dispatcher
    )
    {
    }

    /**
     * Execute a full import run for the given definition and file.
     *
     * @param ImportDefinitionInterface $def     The import definition.
     * @param UploadedFile              $file    The uploaded file to import.
     * @param ImportOptions             $options Import options (transaction mode, dry_run, etc.).
     * @param ImportContext             $ctx     Shared import context.
     * @return ImportReport The completed import report.
     * @throws Throwable On fatal import errors.
     */
    public function run(
        ImportDefinitionInterface $def,
        UploadedFile              $file,
        ImportOptions             $options,
        ImportContext             $ctx
    ): ImportReport
    {
        $report = new ImportReport($file->getClientOriginalName(), $file->getSize(), $file->getClientOriginalExtension(), $options->dry_run);

        $errorExporter = null;
        if (!$options->dry_run) {
            // Create error exporter filename
            $errorFilename = 'import_errors_' . date('Ymd_His') . '_' . uniqid('', true) . '.csv';
            $errorExporter = new ErrorCsvExporter($errorFilename);
            $report->export_errors_path = $errorFilename; // Just the filename or relative path? Reqs say "Return export_errors_path"
        }

        try {
            $parser = $this->parserRegistry->getParser($file);

            // Validate headers if strict_headers is enabled
            if ($options->strict_headers) {
                $this->validateHeaders($file, $parser, $def, $options);
            }

            $rows = $parser->rows($file, $options);

            $batch = [];
            $batchSize = $options->chunk_size;

            // Transaction handling for 'single' mode
            if ($options->transaction_mode === 'single' && !$options->dry_run) {
                DB::beginTransaction();
            }

            foreach ($rows as $currIndex => $rawRow) {
                // Check preview limit
                if ($options->limit_preview_rows && $report->total_rows >= $options->limit_preview_rows) {
                    break;
                }

                $batch[$currIndex] = $rawRow;

                if (count($batch) >= $batchSize) {
                    $this->processBatch($batch, $def, $options, $ctx, $report, $errorExporter);
                    $batch = [];
                }
            }

            // Process remaining
            if (!empty($batch)) {
                $this->processBatch($batch, $def, $options, $ctx, $report, $errorExporter);
            }

            if ($options->transaction_mode === 'single' && !$options->dry_run) {
                DB::commit();
            }

            $report->finish(true);

        } catch (Throwable $e) {
            if ($options->transaction_mode === 'single' && !$options->dry_run) {
                DB::rollBack();
            }
            $report->finish(false);
            $this->logger->error("Import failed: " . $e->getMessage(), ['exception' => $e]);
            // Re-throw or just report error?
            // "FATAL exceptions rollback current transaction scope and abort"
            // So we should probably let it bubble up or mark report as failed with message.
            // Let's mark report as failed but maybe not recheck distinct requirements.
            // Just returning the report with failure is safer for UI.
            // But if it's a critical error (like DB connection), throwing might be better.
            // Users usually want to see "Import Failed" with reason.
            throw $e;
        } finally {
            if ($errorExporter) {
                $startPath = $errorExporter->close();
                $report->export_errors_path = $startPath;
            }
        }

        return $report;
    }

    /**
     * @throws Throwable
     */
    protected function processBatch(
        array                     $batch,
        ImportDefinitionInterface $def,
        ImportOptions             $options,
        ImportContext             $ctx,
        ImportReport              $report,
        ?ErrorCsvExporter         $errorExporter
    ): void
    {
        $runBatch = function () use ($batch, $def, $options, $ctx, $report, $errorExporter) {
            foreach ($batch as $rowIndex => $rawRow) {
                // Ensure rawRow is array
                if (!is_array($rawRow)) continue;

                try {
                    $result = $this->processRow($rawRow, $def, $options, $ctx, $rowIndex);
                } catch (Throwable $e) {
                    // Create a failed result for the report/export
                    $result = new RowResult(
                        $rowIndex,
                        false,
                        RowResult::ACTION_FAIL,
                        [],
                        'Hook/Pivot error: ' . $e->getMessage(),
                        [] // identifiers unknown here
                    );

                    $report->addResult($result);
                    if ($errorExporter) {
                        $errorExporter->addRow($result);
                    }

                    // Rethrow to abort and rollback
                    throw $e;
                }
                // Note: rowIndex is now passed from the parser yield key.

                $report->addResult($result);

                if (!$result->success && $options->stop_on_error) {
                    throw new RuntimeException("Stopped on error at row {$result->rowIndex}: " . $result->message);
                }

                if ($errorExporter) {
                    $errorExporter->addRow($result);
                }
            }
        };

        if ($options->transaction_mode === 'chunk' && !$options->dry_run) {
            DB::transaction($runBatch);
        } else {
            $runBatch();
        }
    }

    protected function processRow(
        array                     $raw,
        ImportDefinitionInterface $def,
        ImportOptions             $options,
        ImportContext             $ctx,
        int                       $rowIndex
    ): RowResult
    {
        // 1. Global Preprocessors
        $globalPre = config('imports.global_preprocessors', []);
        foreach ($globalPre as $pre) {
            if (is_callable($pre)) {
                $raw = $pre($raw);
            }
        }

        // Standard trim strings & empty -> null
        $raw = array_map(static function ($v) {
            if (is_string($v)) {
                $v = trim($v);
                return $v === '' ? null : $v;
            }
            return $v;
        }, $raw);

        // 2. Definition Defaults
        $defaults = $def->defaults();
        foreach ($defaults as $k => $v) {
            if (!array_key_exists($k, $raw) || $raw[$k] === null) {
                $raw[$k] = $v;
            }
        }

        // 3. Definition Transformers
        $transformers = $def->transformers();
        foreach ($transformers as $field => $cb) {
            if (is_callable($cb)) {
                $raw[$field] = $cb($raw, $ctx);
            }
        }

        // 4. Casts
        $casts = $def->casts();
        foreach ($casts as $field => $type) {
            if (array_key_exists($field, $raw)) {
                $raw[$field] = CastHelper::cast($raw[$field], $type);
            }
        }

        // Capture present keys (keys currently in $raw)
        $presentKeys = array_keys($raw);

        // 5. ResolveRow
        $resolvedRow = $def->resolveRow($raw, $ctx);

        // Auto-populate presentKeys if not provided by definition
        if (empty($resolvedRow->presentKeys)) {
            // Assume mapping is 1:1 or keys in data match presentKeys logic
            // We use intersection of data keys and source keys if possible,
            // but if mapping changed keys, we might fallback to data keys?
            // "ResolvedRow must carry 'present_keys' derived from raw headers"
            // Let's attach the raw keys we know.
            $resolvedRow->presentKeys = $presentKeys;
        }

        // 6. Find Existing Model
        $modelClass = $def->modelClass();
        $uniqueBy = $def->uniqueBy();
        $usesSoftDeletes = in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($modelClass), true);

        $model = null;
        $uniqueSets = $def->uniqueSets();
        $matchedBySet = false;

        // Prioritize uniqueSets if available
        if (!empty($uniqueSets)) {
            foreach ($uniqueSets as $set) {
                // Check if we have all keys for this set
                if (!$this->hasAllKeys($set, $resolvedRow)) {
                    continue;
                }

                $q = $modelClass::query();
                if ($usesSoftDeletes) $q->withTrashed();

                $this->buildUniqueQuery($q, $set, $resolvedRow);
                $model = $q->first();
                if ($model) {
                    $matchedBySet = true;
                    break;
                }
            }
        }

        // Fallback to uniqueBy if no model found via sets
        // Requirement: "If no match found after uniqueSets, ALSO try uniqueBy() as last resort if uniqueBy not empty."
        if (!$model && !$matchedBySet && !empty($uniqueBy)) {
            if ($this->hasAllKeys($uniqueBy, $resolvedRow)) {
                $query = $modelClass::query();
                if ($usesSoftDeletes) {
                    $query->withTrashed();
                }

                $this->buildUniqueQuery($query, $uniqueBy, $resolvedRow);
                $model = $query->first();
            }
        }

        // 7. Validation
        $def->beforeValidate($resolvedRow, $ctx);

        $valCtx = new ImportValidationContext(
            $resolvedRow,
            $ctx,
            $rowIndex,
            $raw,
            $model,
            $options
        );

        $rules = $def->rules($valCtx);

        if (!empty($rules)) {
            $validator = $this->validatorFactory->make($resolvedRow->data, $rules);
            if ($validator->fails()) {
                return new RowResult(
                    $rowIndex,
                    false,
                    RowResult::ACTION_FAIL,
                    $validator->errors()->toArray(),
                    'Validation failed',
                    $this->extractIdentifiers($resolvedRow, $def)
                );
            }
        }

        // 8. After Validate Hook
        $def->afterValidate($resolvedRow, $ctx);

        // 9. Determine Action
        $action = RowResult::ACTION_CREATE;
        $dataToFill = [];

        $fillable = $def->fillable();
        // Filtering logic:
        // Only update columns that are: in fillable AND (present in present_keys).

        $targetKeys = $resolvedRow->presentKeys;

        if (empty($fillable) || $fillable === ['*']) {
            // If fillable *, use all present keys that exist in data
            foreach ($targetKeys as $key) {
                if (array_key_exists($key, $resolvedRow->data)) {
                    $dataToFill[$key] = $resolvedRow->data[$key];
                }
            }
        } else {
            foreach ($fillable as $f) {
                // Check if field is present in import/transformers
                // AND exists in resolved data
                if (in_array($f, $targetKeys, true) && array_key_exists($f, $resolvedRow->data)) {
                    $dataToFill[$f] = $resolvedRow->data[$f];
                }
            }
        }

        if ($model) {
            if ($usesSoftDeletes && $model->trashed()) {
                $action = RowResult::ACTION_UPDATE;
                // Will restore later
            } else if ($options->always_update) {
                $action = RowResult::ACTION_UPDATE;
            } else {
                $hasChanges = false;
                foreach ($dataToFill as $k => $v) {
                    // Compare weakly or strictly? Eloquent uses weak comparison usually or original.
                    // Let's use value comparison.
                    if ($model->getAttribute($k) !== $v) {
                        $hasChanges = true;
                        break;
                    }
                }
                $action = $hasChanges ? RowResult::ACTION_UPDATE : RowResult::ACTION_SKIP;
            }
        }

        if ($options->dry_run) {
            return new RowResult(
                $rowIndex,
                true,
                $action,
                [],
                'Dry run simulation: ' . $action,
                $this->extractIdentifiers($resolvedRow, $def)
            );
        }

        // 10. Execute
        if ($action === RowResult::ACTION_SKIP) {
            return new RowResult(
                $rowIndex,
                true,
                RowResult::ACTION_SKIP,
                [],
                'Skipped (no changes)',
                $this->extractIdentifiers($resolvedRow, $def)
            );
        }

        $def->beforeUpsert($resolvedRow, $ctx);

        if (!$model) {
            $model = new $modelClass();
        } else {
            if ($usesSoftDeletes && $model->trashed()) {
                $model->restore();
            }
        }

        $model->fill($dataToFill); // Fill sanitized data
        $model->save();

        try {
            // 11. After Upsert Hook
            $def->afterUpsert($model, $resolvedRow, $ctx);

            // 12. Handle Pivot
            $def->handlePivotRelations($resolvedRow, $model, $ctx);
        } catch (Throwable $e) {
            // "Treat as fatal for that row and rollback current transaction scope (single or chunk)"
            // "Mark row as fail with the exception message and abort import"
            // To abort import and rollback, we must throw.
            // But we also want to mark result as fail in report if possible?
            // If we throw, the current function (processRow) exits, processBatch exits loop, transaction rolls back.
            // The result for this row is never returned to `processBatch` to include in report.
            // However, usually fatal abort means we might not care about the report for this row, OR we construct a fail status and throw it?
            // Let's modify processRow to not return result but throw, relying on logger or upper catcher.
            // Requirement: "Mark row as fail ... and abort".
            // Maybe we throw a specific exception that Upper layer catches?

            // Re-throw to ensure rollback
            throw new RuntimeException("Error processing row {$rowIndex}: " . $e->getMessage(), 0, $e);
        }

        $result = new RowResult(
            $rowIndex,
            true,
            $action,
            [],
            null,
            $this->extractIdentifiers($resolvedRow, $def)
        );

        // Global After Row Hook
        $globalAfter = config('imports.global_after_row', []);
        foreach ($globalAfter as $hook) {
            if (is_callable($hook)) {
                $hook($result, $ctx);
            }
        }

        return $result;
    }

    protected function hasAllKeys(array $keys, ResolvedRow $resolvedRow): bool
    {
        foreach ($keys as $key) {
            if (is_array($key)) {
                continue;
            }
            // Key must be present in presentKeys? Or just have a non-null value?
            // "Skip a set if any column value is missing/null."
            // Value missing = get() returns null/default (if we pass null).
            // Value null = explicit null.
            if ($resolvedRow->get($key) === null) {
                return false;
            }
        }
        return true;
    }

    protected function buildUniqueQuery($query, array $uniqueKeys, ResolvedRow $resolvedRow): void
    {
        foreach ($uniqueKeys as $key) {
            if (is_array($key)) {
                continue;
            } // should be flat strings
            $val = $resolvedRow->get($key);
            if ($val === null) {
                $query->whereNull($key);
            } else {
                $query->where($key, $val);
            }
        }
    }

    protected function extractIdentifiers(ResolvedRow $row, ImportDefinitionInterface $def): array
    {
        $ids = [];
        foreach ($def->uniqueBy() as $k) {
            if (is_string($k)) {
                $ids[$k] = $row->get($k);
            }
        }
        return $ids;
    }

    /**
     * Validate that file headers match allowed headers
     *
     * @throws RuntimeException
     */
    protected function validateHeaders(
        UploadedFile $file,
        Parser\ParserInterface $parser,
        ImportDefinitionInterface $def,
        ImportOptions $options
    ): void
    {
        // Get file headers by reading first row
        $fileHeaders = $this->extractFileHeaders($file, $parser);
        
        if (empty($fileHeaders)) {
            throw new RuntimeException('File has no headers');
        }

        // Build allowed headers list
        $allowedHeaders = $this->getAllowedHeaders($def);

        // Find unknown headers
        $unknownHeaders = array_diff($fileHeaders, $allowedHeaders);

        if (!empty($unknownHeaders)) {
            $unknownList = implode(', ', $unknownHeaders);
            $allowedList = implode(', ', $allowedHeaders);
            throw new RuntimeException(
                "Strict header validation failed. Unknown headers found: [{$unknownList}]. " .
                "Allowed headers: [{$allowedList}]"
            );
        }
    }

    /**
     * Extract headers from file
     */
    protected function extractFileHeaders(UploadedFile $file, Parser\ParserInterface $parser): array
    {
        // Create a temporary options object just for header extraction
        $tempOptions = new ImportOptions();
        
        $headers = [];
        foreach ($parser->rows($file, $tempOptions) as $row) {
            // The parser yields associative arrays with headers as keys
            // So we can get headers from the first row
            $headers = array_keys($row);
            break; // Only need first row
        }
        
        return $headers;
    }

    /**
     * Get list of allowed headers for a definition
     */
    protected function getAllowedHeaders(ImportDefinitionInterface $def): array
    {
        // Try to get from templateHeaders() if available
        if (method_exists($def, 'templateHeaders')) {
            return $def->templateHeaders();
        }

        // Fallback: combine fillable + virtual headers from transformers
        $fillable = $def->fillable();
        $transformers = $def->transformers();
        
        // Virtual headers are keys in transformers that aren't in fillable
        $virtualHeaders = array_keys($transformers);
        
        return array_unique(array_merge($fillable, $virtualHeaders));
    }
}
