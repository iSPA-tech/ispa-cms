<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use Throwable;
use App\Constants\Tables;
use Illuminate\Console\Command;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Exports the rows of a whitelisted database table to a PHP seeder array file.
 *
 * Queries the specified table, applies stable ordering (by id when available, or all
 * columns otherwise), and writes the result as a PHP array to
 * cms/Core/database/seeders/exported/{table}.php. Only tables enumerated in the allowlist
 * may be exported, preventing accidental export of sensitive tables.
 *
 * Usage: php artisan cms:export-db {table}
 * Arguments:
 *   table  The database table name to export (must be in the allowed list)
 *
 * @package Cms\Core\Console
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ExportDatabaseTable extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:export-db {table} {--target= : Output directory override (tests only need this to export elsewhere)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export data from a table to a PHP seeder array file';

    /**
     * Execute the console command.
     *
     * Validates the requested table against the allowlist, queries all rows with stable
     * ordering, and writes a PHP array seeder file to the Core module exported directory.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            $allowedExportDataTables = [
                CoreTableConstant::TRANSLATES, CoreTableConstant::SETTINGS, CoreTableConstant::ENUMERATES, CoreTableConstant::ENUMERATE_TYPES,
                Tables::PROVINCES, CoreTableConstant::LANGUAGES, CoreTableConstant::META_ATTRIBUTES, CoreTableConstant::META_CLASSES,
                CoreTableConstant::META_VALUES_TINY_NUM, CoreTableConstant::META_VALUES_BIG_NUM, CoreTableConstant::META_VALUES_BOOLEAN,
                CoreTableConstant::META_VALUES_STRING, CoreTableConstant::META_VALUES_TEXT, CoreTableConstant::META_VALUES_DATE,
                CoreTableConstant::META_VALUES_RANGE,
                CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT,
                CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT, CoreTableConstant::WIDGET, CoreTableConstant::WIDGET_THEME,
                CoreTableConstant::WIDGET_INPUT,
                CoreTableConstant::EMAIL_TEMPLATES,
            ];

            $table = (string)$this->argument('table');

            if (!in_array($table, $allowedExportDataTables, true)) {
                $this->error('Table name is not allowed to export data');
                return;
            }

            $fileName = $table . '.php';
            $outputPath = $this->resolveOutputDir() . '/' . $fileName;
            File::ensureDirectoryExists(dirname($outputPath));

            $rows = $this->exportRows($table);
            $this->writePhpArray($outputPath, $rows);

            $this->info('Data from table ' . $table . ' has been exported to ' . $fileName);
        } catch (Throwable $e) {
            CMSLogger::error([
                'message' => $e->getMessage(),
                'exception' => $e,
                'isAttach' => true,
            ]);
            $this->error($e->getMessage());
        }
    }

    /**
     * Resolve the export directory. An explicit --target always wins; while the
     * test suite runs, exports are redirected below storage/framework/testing so
     * tests never rewrite the tracked seeders. Production keeps the tracked path.
     *
     * @return string Absolute output directory.
     */
    private function resolveOutputDir(): string
    {
        $target = $this->option('target');
        if (is_string($target) && $target !== '') {
            return rtrim($target, '/');
        }

        if (app()->runningUnitTests()) {
            return storage_path('framework/testing/exported/core');
        }

        return base_path('cms/Core/database/seeders/exported');
    }

    /**
     * Fetch rows for export with stable ordering.
     *
     * @return array<int, array<string, mixed>>
     */
    private function exportRows(string $table): array
    {
        $query = DB::table($table);

        $hasId = $this->hasColumn($table, 'id');
        if ($hasId) {
            $query->orderBy('id');
        }

        // Fallback stable ordering: all columns alphabetically to reduce diff noise
        if (!$hasId) {
            $columns = $this->getTableColumns($table);
            foreach ($columns as $column) {
                $query->orderBy($column);
            }
        }

        $rows = $query->get()->map(fn($row) => $this->castRow((array)$row))->all();

        if ($table === CoreTableConstant::SETTINGS) {
            $rows = array_map($this->prepareSettingForExport(...), $rows);
        }

        return $rows;
    }

    /**
     * Export setting metadata without environment-bound ciphertext or credentials.
     */
    private function prepareSettingForExport(array $row): array
    {
        if ($this->settingRequiresEncryptedStorage($row)) {
            $row['value'] = null;
        } elseif (isset($row['value']) && is_string($row['value'])) {
            $row['value'] = $this->normalizeSelfHostedStorageUrls($row['value']);
        }

        return $row;
    }

    private function normalizeSelfHostedStorageUrls(string $value): string
    {
        $appUrl = rtrim((string)config('app.url'), '/');
        if ($appUrl === '') {
            return $value;
        }

        $storageUrl = $appUrl . '/storage/';
        $escapedStorageUrl = str_replace('/', '\/', $storageUrl);

        return str_replace(
            [$storageUrl, $escapedStorageUrl],
            ['/storage/', '\/storage\/'],
            $value
        );
    }

    private function settingRequiresEncryptedStorage(array $row): bool
    {
        if (strtolower((string)($row['type'] ?? '')) === 'password') {
            return true;
        }

        $inputSetting = $this->decodeSettingInput($row['input_setting'] ?? null);
        $isEnv = $this->settingBoolean($inputSetting['isEnv'] ?? $inputSetting['is_env'] ?? null, false);
        $isPublic = $this->settingBoolean($inputSetting['isPublic'] ?? $inputSetting['is_public'] ?? null, true);

        return $isEnv || !$isPublic;
    }

    /**
     * Legacy rows may contain JSON encoded twice; unwrap only to the metadata object.
     *
     * @return array<string, mixed>
     */
    private function decodeSettingInput(mixed $value): array
    {
        for ($depth = 0; $depth < 2 && is_string($value); ++$depth) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            $value = $decoded;
        }

        return is_array($value) ? $value : [];
    }

    private function settingBoolean(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->getTableColumns($table), true);
    }

    /**
     * @return array<int, string>
     */
    private function getTableColumns(string $table): array
    {
        return DB::getSchemaBuilder()->getColumnListing($table);
    }

    private function castRow(array $row): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return $value;
            }

            if (is_numeric($value)) {
                return $value + 0;
            }

            return $value;
        }, $row);
    }

    /**
     * Write deterministic PHP array export.
     */
    private function writePhpArray(string $path, array $rows): void
    {
        $export = var_export($rows, true);
        $content = "<?php\nreturn " . $export . ";\n";
        \Cms\Core\Support\Testing\TranslationFileGuard::assertWritable($path);
        File::put($path, $content);
    }
}
