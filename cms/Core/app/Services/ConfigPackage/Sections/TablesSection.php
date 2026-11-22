<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage\Sections;

use Cms\Core\Models\Language;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\ApplyResult;
use Cms\Core\Services\ConfigPackage\ConfigExportRegistry;
use Cms\Core\Services\ConfigPackage\ConfigTableRegistry;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\SecretGuard;
use Cms\Core\Services\ConfigPackage\SectionDiff;
use Cms\Core\Services\ConfigPackage\SectionExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generic table section of the configuration package: rows of registered tables listed by ConfigExportRegistry.
 *
 * Row format: `{ table, key, language, values }`.
 * - `key` maps each natural key column of the registration to its value; rows are matched by that key and the diff
 *   item key (also the choice key) is `table|keyValue[|keyValue...]`.
 * - `language` is the language `path_alias ?? path` (null for none) and is present for tables registered with the
 *   `language_id` foreign key; `language_id` itself never leaves the site.
 * - `values` holds every other column as stored, except `id`, `user_id`/`created_by`, timestamps, `deleted_at` and
 *   the registered excluded columns.
 *
 * Import is additive: rows are created or updated, never deleted. A created row gets `user_id`/`created_by` set
 * to the importing user (null when the context has none); an updated row keeps its owner. Incoming columns outside
 * the exportable set (a forged `id` or `user_id`), tables that are not registered and listed, unknown languages and
 * secret-looking rows are blocked. Writes go straight through the query builder (generic tables have no model).
 */
class TablesSection implements SectionAdapter
{
    /** Columns that never leave the site and are never written from a package. */
    public const ALWAYS_EXCLUDED = ['id', 'user_id', 'created_by', 'created_at', 'updated_at', 'deleted_at'];

    /** Owner columns set to the importing user on create. */
    private const OWNER_COLUMNS = ['user_id', 'created_by'];

    public function __construct(
        private readonly ConfigExportRegistry $exports,
        private readonly ConfigTableRegistry $tables,
        private readonly SecretGuard $guard = new SecretGuard(),
    ) {
    }

    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'tables';
    }

    /**
     * @inheritDoc
     *
     * The context key patterns are setting key patterns and are not used here.
     */
    public function export(ExportContext $c): SectionExport
    {
        $rows = [];
        $skipped = [];
        $secrets = [];

        foreach ($this->exports->tables() as $table) {
            $registration = $this->tables->get($table);

            if ($registration === null || !Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table);

            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            foreach ($registration['key_columns'] as $column) {
                $query->orderBy($column);
            }

            foreach ($query->get() as $record) {
                $record = (array) $record;
                $row = [
                    'table' => $table,
                    'key' => $this->keyOf($registration, $record),
                    'language' => null,
                    'values' => $this->valuesOf($table, $registration, $record),
                ];

                if (in_array('language_id', $registration['foreign_keys'], true)) {
                    $language = $record['language_id'] !== null ? $this->languagePathById((int) $record['language_id']) : null;

                    if ($record['language_id'] !== null && $language === null) {
                        $skipped['language_missing'] = ($skipped['language_missing'] ?? 0) + 1;

                        continue;
                    }

                    $row['language'] = $language;
                } else {
                    unset($row['language']);
                }

                if ($this->guard->findSecrets([$row]) !== []) {
                    $skipped['secret'] = ($skipped['secret'] ?? 0) + 1;
                    $secrets[] = $this->itemKey($table, $row['key']);

                    continue;
                }

                $rows[] = $row;
            }
        }

        ksort($skipped);

        return new SectionExport($rows, $skipped, $secrets);
    }

    /**
     * @inheritDoc
     */
    public function diff(array $rows, DiffContext $c): SectionDiff
    {
        $items = [];

        foreach ($rows as $row) {
            $item = $this->diffRow(is_array($row) ? $row : []);
            unset($item['plan']);
            $items[] = $item;
        }

        return new SectionDiff($items);
    }

    /**
     * @inheritDoc
     *
     * Created and updated rows are both counted in ApplyResult::$updated.
     */
    public function apply(array $rows, ApplyContext $c): ApplyResult
    {
        $counts = ['updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'blocked' => 0];
        $items = [];

        foreach ($rows as $row) {
            $diff = $this->diffRow(is_array($row) ? $row : []);
            $key = (string) $diff['key'];
            $reason = $diff['reason'];

            if ($diff['action'] === 'blocked') {
                $outcome = 'blocked';
            } elseif ($diff['action'] === 'unchanged') {
                $outcome = 'unchanged';
            } elseif (!$c->isChosen($key)) {
                $outcome = 'skipped';
                $reason = 'deselected';
            } else {
                $this->write($diff['plan'], $c);
                $outcome = 'updated';
            }

            ++$counts[$outcome];
            $items[] = ['key' => $key, 'outcome' => $outcome, 'reason' => $reason];
        }

        return new ApplyResult($counts['updated'], $counts['unchanged'], $counts['skipped'], $counts['blocked'], $items);
    }

    /**
     * @inheritDoc
     *
     * Nothing to synchronize: generic tables have no runtime cache of their own.
     */
    public function afterApply(ApplyContext $c): void
    {
    }

    /**
     * Compare one incoming row with the target; the result carries a `plan` used by apply().
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function diffRow(array $row): array
    {
        $table = is_string($row['table'] ?? null) ? $row['table'] : '';
        $key = is_array($row['key'] ?? null) ? $row['key'] : [];
        $item = ['key' => $this->itemKey($table, $key), 'action' => 'blocked', 'reason' => null];
        $registration = $this->tables->get($table);

        if ($registration === null || !in_array($table, $this->exports->tables(), true) || !Schema::hasTable($table)) {
            $item['reason'] = 'table is not registered for configuration export';

            return $item;
        }

        $columns = $this->exportableColumns($table, $registration);
        $keyValues = [];

        foreach ($registration['key_columns'] as $column) {
            if (!isset($key[$column]) || !is_scalar($key[$column]) || (string) $key[$column] === '') {
                $item['reason'] = 'row has no value for natural key column ' . $column;

                return $item;
            }

            $keyValues[$column] = $key[$column];
        }

        $values = is_array($row['values'] ?? null) ? $row['values'] : [];

        if (array_diff(array_keys($key), $registration['key_columns']) !== []) {
            $item['reason'] = 'row carries unknown key columns';

            return $item;
        }

        foreach (array_keys($values) as $column) {
            if (!in_array($column, $columns, true)) {
                $item['reason'] = 'column is not part of the exported set: ' . $column;

                return $item;
            }
        }

        if ($this->guard->findSecrets([$row]) !== []) {
            $item['reason'] = 'secret';

            return $item;
        }

        $hasLanguage = in_array('language_id', $registration['foreign_keys'], true);
        $languageId = null;

        if ($hasLanguage && ($row['language'] ?? null) !== null) {
            $languageId = $this->languageIdByPath((string) $row['language']);

            if ($languageId === null) {
                $item['reason'] = 'language does not exist on this site';

                return $item;
            }
        }

        $query = DB::table($table)->where($keyValues);
        $current = $query->first();
        $current = $current !== null ? (array) $current : null;

        if ($current !== null && Schema::hasColumn($table, 'deleted_at') && $current['deleted_at'] !== null) {
            $item['reason'] = 'row is soft deleted on this site';

            return $item;
        }

        $changes = $values;

        if ($hasLanguage) {
            $changes['language_id'] = $languageId;
        }

        $item['plan'] = ['table' => $table, 'key' => $keyValues, 'changes' => $changes, 'current' => $current];

        if ($current === null) {
            $item['action'] = 'create';
            $item['after'] = $values + ($hasLanguage ? ['language' => $row['language'] ?? null] : []);

            return $item;
        }

        $before = [];
        $after = [];

        foreach ($changes as $column => $value) {
            if (PackageFormat::canonicalJson($current[$column] ?? null) !== PackageFormat::canonicalJson($value)) {
                $before[$column === 'language_id' ? 'language' : $column] = $column === 'language_id'
                    ? ($current[$column] !== null ? $this->languagePathById((int) $current[$column]) : null)
                    : ($current[$column] ?? null);
                $after[$column === 'language_id' ? 'language' : $column] = $column === 'language_id' ? ($row['language'] ?? null) : $value;
            }
        }

        $item['action'] = $after === [] ? 'unchanged' : 'update';
        $item['before'] = $before;
        $item['after'] = $after;

        return $item;
    }

    /**
     * Insert or update according to a plan produced by diffRow().
     *
     * @param array{table: string, key: array<string, mixed>, changes: array<string, mixed>, current: array<string, mixed>|null} $plan
     */
    private function write(array $plan, ApplyContext $c): void
    {
        $table = $plan['table'];
        $now = now();

        if ($plan['current'] === null) {
            $record = $plan['key'] + $plan['changes'];

            foreach (self::OWNER_COLUMNS as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $record[$column] = $c->userId;
                }
            }

            foreach (['created_at', 'updated_at'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $record[$column] = $now;
                }
            }

            DB::table($table)->insert($record);

            return;
        }

        $changes = $plan['changes'];

        if (Schema::hasColumn($table, 'updated_at')) {
            $changes['updated_at'] = $now;
        }

        DB::table($table)->where($plan['key'])->update($changes);
    }

    /**
     * Natural key values of a stored record.
     *
     * @param array<string, mixed> $registration
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function keyOf(array $registration, array $record): array
    {
        $key = [];

        foreach ($registration['key_columns'] as $column) {
            $key[$column] = $record[$column] ?? null;
        }

        return $key;
    }

    /**
     * Exportable values of a stored record (everything except key, foreign key and excluded columns).
     *
     * @param array<string, mixed> $registration
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function valuesOf(string $table, array $registration, array $record): array
    {
        $values = [];

        foreach ($this->exportableColumns($table, $registration) as $column) {
            $values[$column] = $record[$column] ?? null;
        }

        return $values;
    }

    /**
     * Value columns of a table: all columns minus always-excluded, registered excluded, key and foreign key columns.
     *
     * @param array<string, mixed> $registration
     *
     * @return list<string>
     */
    private function exportableColumns(string $table, array $registration): array
    {
        $dropped = array_merge(
            self::ALWAYS_EXCLUDED,
            $registration['exclude_columns'],
            $registration['key_columns'],
            $registration['foreign_keys'],
        );

        return array_values(array_diff(Schema::getColumnListing($table), $dropped));
    }

    /**
     * Diff item key `table|keyValue[|keyValue...]`.
     *
     * @param array<array-key, mixed> $key
     */
    private function itemKey(string $table, array $key): string
    {
        return $table . '|' . implode('|', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', array_values($key)));
    }

    /**
     * Language path (`path_alias ?? path`) of a language id, or null when missing.
     */
    private function languagePathById(int $id): ?string
    {
        $language = Language::query()->find($id);

        return $language !== null ? (string) ($language->path_alias ?? $language->path) : null;
    }

    /**
     * Language id for a path (`path_alias ?? path`), or null when no language has it.
     */
    private function languageIdByPath(string $path): ?int
    {
        foreach (Language::query()->get() as $language) {
            if ((string) ($language->path_alias ?? $language->path) === $path) {
                return (int) $language->id;
            }
        }

        return null;
    }
}
