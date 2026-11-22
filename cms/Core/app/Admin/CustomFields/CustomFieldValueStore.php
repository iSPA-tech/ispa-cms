<?php

declare(strict_types=1);

namespace Cms\Core\Admin\CustomFields;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaClass;
use Cms\Core\Models\MetaData;
use Cms\Core\Services\MetaAssignmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the custom field values of admin records.
 *
 * Writes go only through {@see MetaAssignmentService}: value rows are pooled, shared between records and never
 * edited in place. This class never writes to a value table. Removing an assignment deletes `meta_data` rows only,
 * so pooled value rows survive.
 *
 * Field definitions are memoized per resource in the instance (one query per resource, archived fields included);
 * call {@see forgetDefinitions()} after changing definitions on a long-lived instance.
 */
class CustomFieldValueStore
{
    /** @var array<string, array{fields: Collection<int, MetaAttribute>, classId: int|null}> */
    private array $definitions = [];

    /** Create the store. */
    public function __construct(
        private readonly MetaAssignmentService $assignments,
        private readonly CustomFieldService $fields,
    ) {}

    /**
     * Drop the memoized field definitions.
     */
    public function forgetDefinitions(): void
    {
        $this->definitions = [];
    }

    /**
     * Values of one record, keyed by field key.
     *
     * Every non-archived field has a key; a field without a value is null so a form always has all keys.
     * Multiselect is a list, integer an int, boolean a bool, date a `Y-m-d` string.
     *
     * @return array<string, mixed>
     */
    public function valuesFor(Model $record, string $resourceKey): array
    {
        return $this->valuesForMany(new Collection([$record]), $resourceKey)[$record->getKey()] ?? [];
    }

    /**
     * Values of many records of one resource, keyed by record key, each shaped like {@see valuesFor()}.
     *
     * Once the (memoized) field definitions are known this runs exactly two queries: one `meta_data` query for all
     * records and one UNION ALL query over the value tables in use.
     *
     * @param  Collection<int, Model>  $records
     * @return array<int|string, array<string, mixed>>
     */
    public function valuesForMany(Collection $records, string $resourceKey): array
    {
        $fields = $this->active($resourceKey);
        $empty = $fields->mapWithKeys(fn (MetaAttribute $field) => [$this->keyOf($field) => null])->all();
        $result = $records->mapWithKeys(fn (Model $record) => [$record->getKey() => $empty])->all();
        if ($records->isEmpty() || $fields->isEmpty()) {
            return $result;
        }

        $assignments = MetaData::query()
            ->where('target_type', get_class($records->first()))
            ->whereIn('target_id', $records->map->getKey()->all())
            ->whereIn('meta_attribute_id', $fields->pluck('id')->all())
            ->orderBy('id')
            ->get(['target_id', 'meta_attribute_id', 'value_type', 'value_id']);
        if ($assignments->isEmpty()) {
            return $result;
        }

        $pool = $this->loadPool($assignments);
        $byId = $fields->keyBy('id');
        foreach ($assignments as $row) {
            $field = $byId->get($row->meta_attribute_id);
            $raw = $pool[$row->value_type.'|'.$row->value_id] ?? null;
            if ($field === null || $raw === null) {
                continue;
            }
            $value = $this->cast($field, $raw);
            $key = $this->keyOf($field);
            if ($field->is_multiple) {
                $result[$row->target_id][$key] = [...(array) ($result[$row->target_id][$key] ?? []), $value];
            } else {
                $result[$row->target_id][$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Save field values for a record inside one transaction.
     *
     * Null, '' or an empty list removes the field's assignment. A multiselect list is synced as a set: new values
     * are assigned, dropped values are removed. Assigning an existing value reuses the assignment row. Keys that are
     * unknown or belong to an archived field are not written.
     *
     * Removal choice: a single dropped value of a multiple field is removed by deleting that `meta_data` row
     * (MetaAssignmentService only unassigns a whole attribute), so pooled value rows are never touched.
     *
     * @param  array<string, mixed>  $values  field key => value
     * @return list<string> ignored keys (unknown or archived)
     */
    public function save(Model $record, string $resourceKey, array $values, ?int $userId): array
    {
        $definition = $this->definition($resourceKey);
        $byKey = $definition['fields']->whereNull('archived_at')->keyBy(fn (MetaAttribute $field) => $this->keyOf($field));
        $ignored = [];

        DB::transaction(function () use ($record, $values, $byKey, $definition, &$ignored): void {
            foreach ($values as $key => $value) {
                $field = $byKey->get((string) $key);
                if ($field === null) {
                    $ignored[] = (string) $key;
                    continue;
                }
                $field->is_multiple
                    ? $this->syncMultiple($record, $definition['classId'], $field, $value)
                    : $this->setSingle($record, $definition['classId'], $field, $value);
            }
        });

        return $ignored;
    }

    /**
     * Delete every assignment of a field (all records). Pooled value rows stay.
     *
     * @return int deleted assignment rows
     */
    public function purge(MetaAttribute $field): int
    {
        return MetaData::query()->where('meta_attribute_id', $field->id)->delete();
    }

    /** Set or clear a single-value field. */
    private function setSingle(Model $record, ?int $classId, MetaAttribute $field, mixed $value): void
    {
        if ($this->isBlank($value) || $this->assignments->assign($record, $classId, $field, $value, false, 'manual') === null) {
            $this->assignments->unassign($record, $field);
        }
    }

    /** Sync a multiple field to exactly the given list. */
    private function syncMultiple(Model $record, ?int $classId, MetaAttribute $field, mixed $value): void
    {
        $wanted = [];
        foreach (is_array($value) ? $value : ($this->isBlank($value) ? [] : [$value]) as $item) {
            if ($this->isBlank($item)) {
                continue;
            }
            $row = $this->assignments->resolveValue((string) $field->type, $item);
            if ($row === null) {
                continue;
            }
            $this->assignments->assign($record, $classId, $field, $item, false, 'manual');
            $wanted[$row->getKey()] = true;
        }

        $stale = MetaData::query()
            ->where('target_type', get_class($record))
            ->where('target_id', $record->getKey())
            ->where('meta_attribute_id', $field->id);
        if ($wanted !== []) {
            $stale->whereNotIn('value_id', array_keys($wanted));
        }
        $stale->delete();
    }

    /**
     * Load the pooled values referenced by the assignments with one UNION ALL query.
     *
     * @param  Collection<int, MetaData>  $assignments
     * @return array<string, mixed> "valueClass|id" => raw value
     */
    private function loadPool(Collection $assignments): array
    {
        $query = null;
        foreach ($assignments->groupBy('value_type') as $class => $rows) {
            $table = (new $class())->getTable();
            $part = DB::table($table)
                ->selectRaw('? as vt, id, value', [$class])
                ->whereIn('id', $rows->pluck('value_id')->unique()->all());
            $query = $query === null ? $part : $query->unionAll($part);
        }

        $pool = [];
        foreach ($query->get() as $row) {
            $pool[$row->vt.'|'.$row->id] = $row->value;
        }

        return $pool;
    }

    /** Cast a raw stored value to the PHP value of the field's type. */
    private function cast(MetaAttribute $field, mixed $raw): mixed
    {
        return match ((string) $field->type) {
            'bigInt', 'tinyInt' => (int) $raw,
            'boolean' => (bool) $raw,
            'date' => substr((string) $raw, 0, 10),
            default => (string) $raw,
        };
    }

    /** Whether a submitted value means "no value". */
    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }

    /**
     * Non-archived fields of a resource by priority.
     *
     * @return Collection<int, MetaAttribute>
     */
    private function active(string $resourceKey): Collection
    {
        return $this->definition($resourceKey)['fields']->whereNull('archived_at')->values();
    }

    /**
     * Memoized definitions (archived included) and the resource's meta class id.
     *
     * @return array{fields: Collection<int, MetaAttribute>, classId: int|null}
     */
    private function definition(string $resourceKey): array
    {
        return $this->definitions[$resourceKey] ??= [
            'fields' => $this->fields->fieldsFor($resourceKey, true),
            'classId' => MetaClass::query()->where('resource_key', $resourceKey)->value('id'),
        ];
    }

    /** Field key recovered from the attribute name (the part after the last dot). */
    private function keyOf(MetaAttribute $field): string
    {
        $name = (string) $field->name;

        return substr($name, (int) strrpos($name, '.') + 1);
    }
}
