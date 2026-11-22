<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services;

use JsonException;
use RuntimeException;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Export translations into per-module seeder PHP files under cms/<Module>/database/seeders/sql.
 * @package Cms\Core\Services
 */
class TranslationModuleSeederExporter
{
    /**
     * Export the provided translations into module seeders.
     *
     * @param Collection $translates
     * @param array<int, string> $onlyModules
     *
     * @return array{
     *     modules: array<int, array{module: string, records: int, file: string}>,
     *     skipped: array<int, string>
     * }
     * @throws JsonException
     * @throws RuntimeException When the CMS modules directory is not found.
     */
    public function export(Collection $translates, array $onlyModules = []): array
    {
        $only = collect($onlyModules)
            ->filter(fn($name) => is_string($name) && $name !== '')
            ->map(fn($name) => strtolower($name))
            ->unique()
            ->values();

        $perModule = [];

        foreach ($translates as $row) {
            $moduleNames = $this->normalizeModuleList($row->modules ?? null);

            if (empty($moduleNames)) {
                $moduleNames = ['Core'];
            }

            $text = $this->normalizeTextPayload($row->text ?? []);
            $id = filter_var($row->id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $groupName = trim((string)($row->group_name ?? ''));
            $fullName = trim((string)($row->name ?? ''));

            if ($groupName === '' || $fullName === '') {
                continue;
            }

            $payload = [
                'modules' => $moduleNames,
                'group_name' => $groupName,
                'name' => $this->normalizeExportedTranslationName(
                    $groupName,
                    $fullName
                ),
                'text' => $text,
                'for_web' => (int)($row->for_web ?? 0),
                'for_adm' => (int)($row->for_adm ?? 0),
                'for_app' => (int)($row->for_app ?? 0),
                'status' => $row->status !== null ? (string)$row->status : null,
                'created_at' => $this->formatDateTime($row->created_at ?? null),
                'updated_at' => $this->formatDateTime($row->updated_at ?? null),
            ];
            if ($id !== false) {
                $payload = ['id' => $id] + $payload;
            }

            foreach ($moduleNames as $moduleName) {
                $key = strtolower($moduleName);
                if ($only->isNotEmpty() && !$only->contains($key)) {
                    continue;
                }

                if (!isset($perModule[$key])) {
                    $perModule[$key] = [
                        'label' => $moduleName,
                        'rows' => [],
                    ];
                }

                $rowKey = $payload['group_name'] . '|' . $payload['name'];
                if (!array_key_exists($rowKey, $perModule[$key]['rows'])) {
                    $perModule[$key]['rows'][$rowKey] = $payload;
                }
            }
        }

        if (empty($perModule)) {
            return [
                'modules' => [],
                'skipped' => [],
            ];
        }

        $cmsRoot = base_path('cms');
        if (!File::exists($cmsRoot) || !File::isDirectory($cmsRoot)) {
            throw new RuntimeException("CMS modules directory not found: {$cmsRoot}");
        }

        $modulePaths = [];
        foreach (File::directories($cmsRoot) as $dir) {
            $modulePaths[strtolower(basename($dir))] = $dir;
        }

        $generatedAt = now()->toDateTimeString();
        $results = [];
        $skipped = [];

        foreach ($perModule as $moduleKey => $data) {
            $rows = array_values($data['rows']);
            if (empty($rows)) {
                continue;
            }

            if (!isset($modulePaths[$moduleKey])) {
                $skipped[] = $data['label'];
                continue;
            }

            usort($rows, static function ($a, $b) {
                return [$a['group_name'], $a['name']] <=> [$b['group_name'], $b['name']];
            });

            $targetDir = $modulePaths[$moduleKey] . DIRECTORY_SEPARATOR . 'database'
                . DIRECTORY_SEPARATOR . 'seeders' . DIRECTORY_SEPARATOR . 'sql';

            if (!File::exists($targetDir)) {
                File::makeDirectory($targetDir, 0755, true);
            }

            $targetFile = $targetDir . DIRECTORY_SEPARATOR . 'translate.php';
            $export = var_export($rows, true);
            $moduleName = $data['label'];
            $header = <<<PHP
<?php
/**
 * Auto-generated translate seeder for module: {$moduleName}
 * Generated at: {$generatedAt}
 * Note: Exported values mirror the translates table schema.
 */

return {$export};

PHP;

            \Cms\Core\Support\Testing\TranslationFileGuard::assertWritable($targetFile);
            File::put($targetFile, $header . "\n");

            $results[] = [
                'module' => $moduleName,
                'records' => count($rows),
                'file' => str_replace(base_path() . DIRECTORY_SEPARATOR, '', $targetFile),
            ];
        }

        return [
            'modules' => $results,
            'skipped' => array_values(array_unique($skipped)),
        ];
    }

    /**
     * Normalize the modules attribute into an array of non-empty strings.
     *
     * @param mixed $modules
     *
     * @return array<int, string>
     */
    private function normalizeModuleList(mixed $modules): array
    {
        if ($modules instanceof Collection) {
            $modules = $modules->all();
        }

        if (is_string($modules)) {
            $decoded = json_decode($modules, true);
            if (is_array($decoded)) {
                $modules = $decoded;
            } else {
                $trimmed = trim($modules);
                $modules = $trimmed === '' ? [] : [$trimmed];
            }
        }

        if (!is_array($modules)) {
            return [];
        }

        $normalized = [];
        foreach ($modules as $module) {
            if ($module instanceof Collection) {
                $module = $module->first();
            }

            if (!is_scalar($module)) {
                continue;
            }

            $name = trim((string)$module);
            if ($name === '') {
                continue;
            }

            $normalized[] = $name;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normalize the text payload to an associative array with stable ordering.
     *
     * @param mixed $text
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function normalizeTextPayload(mixed $text): array
    {
        if ($text instanceof Collection) {
            $text = $text->all();
        }

        if (is_string($text)) {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            $text = is_array($decoded) ? $decoded : [$text];
        }

        if (!is_array($text)) {
            return [];
        }

        ksort($text);

        return $text;
    }

    /**
     * Convert supported datetime representations into strings.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    private function normalizeExportedTranslationName(string $groupName, string $fullName): string
    {
        $groupName = trim($groupName);
        $fullName = trim($fullName);
        $name = (string)str($fullName)->replaceFirst($groupName . '.', '');

        if ($groupName !== 'validation') {
            return $name;
        }

        return match (true) {
            str_starts_with($name, 'a.') => 'attributes.' . substr($name, 2),
            str_starts_with($name, 'v.a.') => 'attributes.' . substr($name, 4),
            default => $name,
        };
    }
}
