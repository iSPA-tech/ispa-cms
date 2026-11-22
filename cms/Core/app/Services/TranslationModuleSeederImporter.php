<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services;

use Cms\Core\Models\Translate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Import translations from module seeder exports into the `translates` table.
 *
 * Missing keys are created and module lists of existing keys are merged. Source wins for
 * wording: the text of an existing key is overwritten by an authored admin sidecar record
 * (`database/seeders/sql/translate-admin-*.php`) whenever at least one non-empty locale of the
 * record differs from the row, regardless of any timestamp. Exported records (`translate.php`,
 * `exported/*.php`) never change the text of an existing key.
 *
 * @package Cms\Core\Services
 */
class TranslationModuleSeederImporter
{
    private int $chunkSize = 500;

    /**
     * Ids of rows this importer created (one instance per application: bound as a singleton). Their ids were given by
     * the database, so they say nothing about the ids of an export. Linking an exported record to one of them by id
     * turned a fresh install's keys into "links" to unrelated rows: 1,462 Core admin keys were never created and the
     * admin showed raw key names (2026-11-19).
     *
     * @var array<int, true>
     */
    private array $createdIds = [];

    /**
     * Import translations for the given module using its standard translate.php export.
     *
     * @param string $moduleName
     * @param string|null $modulePath Optional explicit module path.
     * @param bool $dryRun When true, nothing is written and the result reports what would change.
     *
     * @return array{
     *     found: bool,
     *     valid: bool,
     *     created: int,
     *     linked: int,
     *     updated: int,
     *     skipped: int,
     *     total: int,
     *     path: string,
     *     dry_run: bool,
     *     rewords: array<int, array{name: string, before: array<string, mixed>, after: array<string, mixed>}>,
     *     duplicates: array<int, array{name: string, count: int}>
     * }
     */
    public function importFromModule(string $moduleName, ?string $modulePath = null, bool $dryRun = false): array
    {
        $modulePath ??= function_exists('module_path')
            ? module_path($moduleName)
            : base_path('cms' . DIRECTORY_SEPARATOR . $moduleName);
        $file = $modulePath . DIRECTORY_SEPARATOR . 'database'
            . DIRECTORY_SEPARATOR . 'seeders' . DIRECTORY_SEPARATOR . 'sql'
            . DIRECTORY_SEPARATOR . 'translate.php';
        $sidecarDirectory = $modulePath . DIRECTORY_SEPARATOR . 'database'
            . DIRECTORY_SEPARATOR . 'seeders' . DIRECTORY_SEPARATOR . 'sql';

        return $this->importFromFile($moduleName, $file, $sidecarDirectory, $dryRun);
    }

    /**
     * Import translations for the given module from a specific file.
     *
     * Records of `$filePath` are exported data and never reword existing keys. Records of the
     * admin sidecars found in `$sidecarDirectory` are authored and may apply explicit rewords.
     *
     * @param string $moduleName
     * @param string $filePath
     * @param string|null $sidecarDirectory Directory holding authored `translate-admin-*.php` sidecars.
     * @param bool $dryRun When true, nothing is written and the result reports what would change.
     *
     * @return array{
     *     found: bool,
     *     valid: bool,
     *     created: int,
     *     linked: int,
     *     updated: int,
     *     skipped: int,
     *     total: int,
     *     path: string,
     *     dry_run: bool,
     *     rewords: array<int, array{name: string, before: array<string, mixed>, after: array<string, mixed>}>,
     *     duplicates: array<int, array{name: string, count: int}>
     * }
     */
    public function importFromFile(
        string $moduleName,
        string $filePath,
        ?string $sidecarDirectory = null,
        bool $dryRun = false
    ): array
    {
        if (! File::exists($filePath)) {
            return $this->result($filePath, found: false, valid: false, dryRun: $dryRun);
        }

        $records = include $filePath;

        if (! is_array($records)) {
            return $this->result($filePath, found: true, valid: false, dryRun: $dryRun);
        }

        $authoredRecords = $sidecarDirectory !== null
            ? $this->loadAdminSidecarRecords($sidecarDirectory)
            : [];

        return $this->importRecords($moduleName, $records, $filePath, $authoredRecords, $dryRun);
    }

    /**
     * Load all module admin sidecar payloads from the given seeder directory.
     *
     * @param string $sidecarDirectory
     * @return array<int, mixed>
     */
    private function loadAdminSidecarRecords(string $sidecarDirectory): array
    {
        if (! File::isDirectory($sidecarDirectory)) {
            return [];
        }

        $records = [];
        $sidecarPaths = File::glob($sidecarDirectory . DIRECTORY_SEPARATOR . 'translate-admin-*.php');
        sort($sidecarPaths);

        foreach ($sidecarPaths as $sidecarPath) {
            if (! File::exists($sidecarPath)) {
                continue;
            }

            $sidecarRecords = include $sidecarPath;
            if (is_array($sidecarRecords)) {
                $records = array_merge($records, $sidecarRecords);
            }
        }

        return $records;
    }

    /**
     * Import the provided translation records.
     *
     * `$records` are exported data: they create missing keys and merge module lists, but never
     * change the text of an existing key. `$authoredRecords` (admin sidecars) are processed first
     * so their wording wins when a key is created, and they overwrite the text of existing keys
     * when it differs (source wins, see the class docblock). A name defined more than once in
     * `$authoredRecords` is collapsed to its last definition and reported in `duplicates` when the
     * definitions disagree.
     *
     * @param string $moduleName
     * @param array<int, mixed> $records Exported records; never reword existing keys.
     * @param string $filePath
     * @param array<int, mixed> $authoredRecords Authored sidecar records; may reword existing keys.
     * @param bool $dryRun When true, nothing is written and the result reports what would change.
     *
     * @return array{
     *     found: bool,
     *     valid: bool,
     *     created: int,
     *     linked: int,
     *     updated: int,
     *     skipped: int,
     *     total: int,
     *     path: string,
     *     dry_run: bool,
     *     rewords: array<int, array{name: string, before: array<string, mixed>, after: array<string, mixed>}>,
     *     duplicates: array<int, array{name: string, count: int}>
     * }
     */
    public function importRecords(
        string $moduleName,
        array $records,
        string $filePath,
        array $authoredRecords = [],
        bool $dryRun = false
    ): array
    {
        $created = 0;
        $linked = 0;
        $updated = 0;
        $skipped = 0;
        $rewords = [];
        $now = Carbon::now();

        [$authoredRecords, $superseded, $duplicates] = $this->collapseAuthoredRecords($authoredRecords);
        $total = $superseded;

        // Rows created or changed during this run. They override fresh per-chunk lookups so a
        // dry run sees its own simulated changes; in a real run they equal the stored rows.
        $touchedByName = [];
        $touchedById = [];

        foreach ([[$authoredRecords, true], [$records, false]] as [$batch, $mayReword]) {
            foreach (array_chunk($batch, $this->chunkSize) as $chunk) {
                [$existingByName, $existingById] = $this->loadExistingTranslations($chunk);
                $existingByName = $touchedByName + $existingByName;
                $existingById = $touchedById + $existingById;

                foreach ($chunk as $row) {
                    ++$total;

                    if (! is_array($row)) {
                        ++$skipped;
                        continue;
                    }

                    $name = $row['name'] ?? null;
                    if (! is_string($name) || trim($name) === '') {
                        ++$skipped;
                        continue;
                    }

                    $name = trim($name);
                    $id = $this->normalizeId($row['id'] ?? null);
                    $modules = $this->mergeModules($row['modules'] ?? null, [$moduleName]);
                    $groupName = $this->normalizeGroupName($row['group_name'] ?? null);

                    $payload = [
                        'modules' => $modules,
                        'group_name' => $groupName,
                        'text' => $this->normalizeTextPayload($row['text'] ?? []),
                        'for_web' => (bool) ($row['for_web'] ?? false),
                        'for_adm' => (bool) ($row['for_adm'] ?? false),
                        'for_app' => (bool) ($row['for_app'] ?? false),
                        'status' => $row['status'] ?? null,
                    ];

                    $normalizedName = $this->normalizeTranslationName($name);

                    $existing = $this->resolveExistingTranslation(
                        $normalizedName,
                        $groupName,
                        $id,
                        $existingByName,
                        $existingById
                    );

                    if ($existing) {
                        $existingModules = $this->normalizeModuleList($existing->modules ?? []);
                        $mergedModules = $this->mergeModules($existingModules, $modules);
                        $reword = $mayReword ? $this->planReword($existing, $normalizedName, $row) : null;

                        if ($mergedModules === $existingModules && $reword === null) {
                            continue;
                        }

                        if ($mergedModules !== $existingModules) {
                            $existing->modules = $mergedModules;

                            if ($this->shouldRefreshStatus($existing->status, $payload['status'] ?? null)) {
                                $existing->status = $payload['status'];
                            }

                            ++$linked;
                        }

                        if ($reword !== null) {
                            $existing->text = $reword['after'];
                            $rewords[] = ['name' => (string) $existing->name] + $reword;
                            ++$updated;
                        }

                        if ($dryRun) {
                            // Mirror the timestamp bump a real save() would make.
                            $existing->updated_at = $now;
                        } else {
                            $existing->save();
                        }

                        $touchedByName[$this->normalizeTranslationName((string) $existing->name)] = $existing;
                        $touchedById[(int) $existing->getKey()] = $existing;

                        continue;
                    }

                    if ($dryRun) {
                        $createdTranslation = new Translate(array_merge($payload, ['name' => $name]));
                        $createdTranslation->updated_at = $now;
                    } else {
                        try {
                            $createdTranslation = Translate::query()->create(array_merge($payload, ['name' => $name]));
                        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                            // Record was inserted by another chunk or concurrent process — link instead
                            $existing = Translate::query()->where('name', $name)->first();
                            if ($existing) {
                                $existingByName[$normalizedName] = $existing;
                                ++$linked;
                            } else {
                                ++$skipped;
                            }

                            continue;
                        }
                    }

                    $existingByName[$normalizedName] = $createdTranslation;
                    $touchedByName[$normalizedName] = $createdTranslation;
                    if ($id !== null) {
                        $existingById[$id] = $createdTranslation;
                    }
                    if ($createdTranslation->getKey() !== null) {
                        $touchedById[(int) $createdTranslation->getKey()] = $createdTranslation;
                        if (! $dryRun) {
                            $this->createdIds[(int) $createdTranslation->getKey()] = true;
                        }
                    }
                    ++$created;
                }
            }
        }

        return $this->result(
            $filePath,
            found: true,
            valid: true,
            created: $created,
            linked: $linked,
            updated: $updated,
            skipped: $skipped,
            total: $total,
            dryRun: $dryRun,
            rewords: $rewords,
            duplicates: $duplicates
        );
    }

    /**
     * Collapse authored records that define the same name more than once.
     *
     * The last definition in load order (sidecar files sorted by path, entries in file order) wins
     * and earlier ones are dropped, so the outcome is deterministic and a second run changes
     * nothing. A name is reported as a duplicate only when its definitions carry different text.
     *
     * @param array<int, mixed> $records
     * @return array{0: array<int, mixed>, 1: int, 2: array<int, array{name: string, count: int}>}
     *     Remaining records, number of dropped records, duplicate reports.
     */
    private function collapseAuthoredRecords(array $records): array
    {
        $records = array_values($records);
        $definitions = [];

        foreach ($records as $index => $row) {
            $name = is_array($row) && is_string($row['name'] ?? null) ? trim($row['name']) : '';
            if ($name !== '') {
                $definitions[$this->normalizeTranslationName($name)][$index] = [$name, $this->textSignature($row['text'] ?? [])];
            }
        }

        $dropped = [];
        $duplicates = [];

        foreach ($definitions as $byIndex) {
            if (count($byIndex) < 2) {
                continue;
            }

            $dropped += array_diff_key($byIndex, [array_key_last($byIndex) => true]);

            if (count(array_unique(array_column($byIndex, 1))) > 1) {
                $duplicates[] = ['name' => $byIndex[array_key_last($byIndex)][0], 'count' => count($byIndex)];
            }
        }

        return [array_values(array_diff_key($records, $dropped)), count($dropped), $duplicates];
    }

    /**
     * Fingerprint the non-empty locales of a text payload, ignoring surrounding whitespace.
     */
    private function textSignature(mixed $text): string
    {
        $locales = [];

        foreach ($this->normalizeTextPayload($text) as $locale => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $locales[$locale] = trim((string) $value);
            }
        }

        return json_encode($locales, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Format the duplicate authored definitions of an import result as one console line per key.
     *
     * @param array<string, mixed> $result Result returned by one of the import methods.
     * @return array<int, string>
     */
    public static function describeDuplicates(array $result): array
    {
        return array_map(
            static fn (array $duplicate): string => sprintf(
                'duplicate: %s defined %d times with different text',
                $duplicate['name'],
                $duplicate['count']
            ),
            array_values((array) ($result['duplicates'] ?? []))
        );
    }

    /**
     * Format the rewords of an import result as one console line per key.
     *
     * Shows the Vietnamese text (or the first changed locale when `vi` did not change) as
     * `old → new`, both truncated to `$width` characters.
     *
     * @param array<string, mixed> $result Result returned by one of the import methods.
     * @param int $width Maximum characters per text fragment.
     * @return array<int, string>
     */
    public static function describeRewords(array $result, int $width = 60): array
    {
        $lines = [];

        foreach ((array) ($result['rewords'] ?? []) as $reword) {
            $before = (array) ($reword['before'] ?? []);
            $after = (array) ($reword['after'] ?? []);
            $changed = array_keys(array_filter(
                $after,
                static fn (mixed $value, string|int $locale): bool => ($before[$locale] ?? null) !== $value,
                ARRAY_FILTER_USE_BOTH
            ));
            $locale = in_array('vi', $changed, true) ? 'vi' : ($changed[0] ?? 'vi');

            $lines[] = sprintf(
                '%s [%s] "%s" → "%s"',
                (string) ($reword['name'] ?? ''),
                $locale,
                Str::limit(is_scalar($before[$locale] ?? null) ? (string) $before[$locale] : '', $width),
                Str::limit(is_scalar($after[$locale] ?? null) ? (string) $after[$locale] : '', $width)
            );
        }

        return $lines;
    }

    /**
     * Decide whether an authored sidecar record overwrites the text of an existing row.
     *
     * Source wins: no timestamp is consulted. Returns null unless the row was matched by its own
     * name (never through the id fallback, which can point at another key), the row is not
     * soft-deleted, and at least one non-empty locale of the record differs from the row after
     * trimming. Locales absent from the record are kept.
     *
     * @param array<string, mixed> $row Authored sidecar record.
     * @return array{before: array<string, mixed>, after: array<string, mixed>}|null
     */
    private function planReword(Translate $existing, string $normalizedName, array $row): ?array
    {
        if ($existing->trashed()
            || $this->normalizeTranslationName((string) $existing->name) !== $normalizedName) {
            return null;
        }

        $before = $this->normalizeTextPayload($existing->text ?? []);
        $changes = [];

        foreach ($this->normalizeTextPayload($row['text'] ?? []) as $locale => $value) {
            if (! is_string($locale) || ! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $current = $before[$locale] ?? null;
            if (is_scalar($current) && trim((string) $current) === trim((string) $value)) {
                continue;
            }

            $changes[$locale] = (string) $value;
        }

        if ($changes === []) {
            return null;
        }

        $after = array_merge($before, $changes);
        ksort($after);

        return ['before' => $before, 'after' => $after];
    }

    /**
     * Resolve an existing translation by name, or by id when provided and compatible.
     */
    private function resolveExistingTranslation(
        string $normalizedName,
        ?string $groupName,
        ?int $id,
        array $existingByName = [],
        array $existingById = []
    ): ?Translate
    {
        $existing = $existingByName[$normalizedName] ?? null;
        if ($existing) {
            return $existing;
        }

        if ($id === null) {
            return null;
        }

        $existing = $existingById[$id] ?? null;
        if (! $existing || isset($this->createdIds[(int) $existing->getKey()])) {
            return null;
        }

        if ($groupName !== null && (string) $existing->group_name !== $groupName) {
            return null;
        }

        return $existing;
    }

    /**
     * @param array<int, mixed> $records
     * @return array{0: array<string, Translate>, 1: array<int, Translate>}
     */
    private function loadExistingTranslations(array $records): array
    {
        $names = [];
        $ids = [];

        foreach ($records as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $row['name'] ?? null;
            if (is_string($name) && trim($name) !== '') {
                $names[] = $this->normalizeTranslationName(trim($name));
            }

            $id = $this->normalizeId($row['id'] ?? null);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $existingByName = Translate::query()
            ->when(
                $names !== [],
                static fn ($query) => $query->whereIn(
                    \Illuminate\Support\Facades\DB::raw('LOWER(name)'),
                    array_values(array_unique($names))
                ),
                static fn ($query) => $query->whereRaw('1 = 0')
            )
            ->get()
            ->keyBy(fn (Translate $translate) => $this->normalizeTranslationName((string) $translate->name))
            ->all();

        $existingById = Translate::query()
            ->when(
                $ids !== [],
                static fn ($query) => $query->whereKey(array_values(array_unique($ids))),
                static fn ($query) => $query->whereRaw('1 = 0')
            )
            ->get()
            ->keyBy(static fn (Translate $translate) => (int) $translate->getKey())
            ->all();

        return [$existingByName, $existingById];
    }

    private function normalizeTranslationName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * Normalize the incoming group name into a trimmed string or null.
     */
    private function normalizeGroupName(mixed $groupName): ?string
    {
        if (! is_string($groupName)) {
            return null;
        }

        $groupName = trim($groupName);

        return $groupName === '' ? null : $groupName;
    }

    /**
     * Normalize id values into integers.
     */
    private function normalizeId(mixed $id): ?int
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /**
     * Determine whether the status should be updated on existing rows.
     */
    private function shouldRefreshStatus(?string $current, ?string $incoming): bool
    {
        if ($incoming === null) {
            return false;
        }

        if ($current === null) {
            return true;
        }

        return Str::lower($current) !== Str::lower($incoming);
    }

    /**
     * Merge the existing module list with additional modules.
     *
     * @param mixed $existing
     * @param array<int, string> $additional
     *
     * @return array<int, string>
     */
    private function mergeModules(mixed $existing, array $additional): array
    {
        $base = $this->normalizeModuleList($existing);
        $extra = $this->normalizeModuleList($additional);

        return $this->uniqueModules(array_merge($base, $extra));
    }

    /**
     * Normalise the modules attribute to a de-duplicated string array.
     *
     * @param mixed $modules
     * @return array<int, string>
     */
    private function normalizeModuleList(mixed $modules): array
    {
        if ($modules instanceof Collection) {
            $modules = $modules->all();
        }

        if (is_string($modules)) {
            $decoded = json_decode($modules, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $modules = $decoded;
            } elseif (trim($modules) !== '') {
                $modules = [trim($modules)];
            } else {
                $modules = [];
            }
        }

        if (! is_array($modules)) {
            return [];
        }

        $clean = [];

        foreach ($modules as $module) {
            if ($module instanceof Collection) {
                $module = $module->first();
            }

            if (! is_scalar($module)) {
                continue;
            }

            $trimmed = trim((string) $module);
            if ($trimmed !== '') {
                $clean[] = $trimmed;
            }
        }

        return $this->uniqueModules($clean);
    }

    /**
     * Ensure module names remain unique while preserving case.
     *
     * @param array<int, string> $modules
     * @return array<int, string>
     */
    private function uniqueModules(array $modules): array
    {
        $seen = [];
        $result = [];

        foreach ($modules as $module) {
            $key = strtolower($module);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $module;
        }

        return $result;
    }

    /**
     * Normalise translation payload text to a keyed array.
     *
     * @return array<string, mixed>
     */
    private function normalizeTextPayload(mixed $text): array
    {
        if ($text instanceof Collection) {
            $text = $text->all();
        }

        if (is_string($text)) {
            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $text = $decoded;
            } else {
                return ['value' => $text];
            }
        }

        if (! is_array($text)) {
            return [];
        }

        ksort($text);

        return $text;
    }

    /**
     * Build the response payload.
     *
     * @param array<int, array{name: string, before: array<string, mixed>, after: array<string, mixed>}> $rewords
     * @param array<int, array{name: string, count: int}> $duplicates
     *
     * @return array{
     *     found: bool,
     *     valid: bool,
     *     created: int,
     *     linked: int,
     *     updated: int,
     *     skipped: int,
     *     total: int,
     *     path: string,
     *     dry_run: bool,
     *     rewords: array<int, array{name: string, before: array<string, mixed>, after: array<string, mixed>}>,
     *     duplicates: array<int, array{name: string, count: int}>
     * }
     */
    private function result(
        string $path,
        bool $found,
        bool $valid,
        int $created = 0,
        int $linked = 0,
        int $updated = 0,
        int $skipped = 0,
        int $total = 0,
        bool $dryRun = false,
        array $rewords = [],
        array $duplicates = []
    ): array {
        return [
            'found' => $found,
            'valid' => $valid,
            'created' => $created,
            'linked' => $linked,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => $total,
            'path' => $path,
            'dry_run' => $dryRun,
            'rewords' => $rewords,
            'duplicates' => $duplicates,
        ];
    }
}
