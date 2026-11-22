<?php

declare(strict_types=1);

namespace Cms\Core\Admin\CustomFields;

use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Admin\Resources\SchemaCache;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaClass;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates, updates and reorders the custom fields of one admin resource.
 *
 * A field is a `meta_attributes` row marked `is_custom`, attached to the resource's `meta_classes` row through the
 * class pivot. Defining a field never creates value rows or option pivot rows: those exist only for values that
 * records hold (see MetaAssignmentService). Select options live in `rules.options`.
 *
 * Decisions: archived fields count toward the limit (they keep their values); labels need both languages; help is
 * optional but, when present, needs both languages.
 */
class CustomFieldService
{
    /** Most custom fields one resource may have (archived included). */
    public const MAX_FIELDS = 30;

    /** Field key pattern. */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/';

    /** Locales every label (and help, when given) must carry. */
    private const LOCALES = ['vi', 'en'];

    /** Create the service. */
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly CustomFieldRules $rules,
        private readonly ?BackupGate $backupGate = null,
    ) {}

    /**
     * Custom fields of a resource ordered by priority.
     *
     * @return Collection<int, MetaAttribute>
     */
    public function fieldsFor(string $resourceKey, bool $withArchived = false): Collection
    {
        return MetaAttribute::query()
            ->forResource($resourceKey)
            ->when(! $withArchived, fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * Validate a new definition and return its parts. The one place where create() and draft() check a definition.
     *
     * @param  array<string, mixed>  $data  key, type, labels{vi,en}, help?, rules?, options?
     * @return array{key: string, type: CustomFieldType, labels: array{vi: string, en: string}, help: array{vi: string, en: string}|null, rules: array<string, mixed>|null}
     *
     * @throws CustomFieldException
     */
    private function definitionFrom(array $data): array
    {
        $key = $data['key'] ?? null;
        $errors = [];
        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            $errors[] = 'key must start with a lowercase letter and contain 2 to 40 lowercase letters, digits or underscores';
        }
        $type = $this->resolveType($data['type'] ?? null, $errors);
        $labels = $this->localized($data['labels'] ?? null, 'labels', true, $errors);
        $help = $this->localized($data['help'] ?? null, 'help', false, $errors);
        $rules = null;
        if ($type !== null) {
            $rules = $this->buildRules($type, $data['rules'] ?? [], $data['options'] ?? null, $errors);
        }
        if ($errors !== [] || $type === null || $labels === null) {
            throw CustomFieldException::invalidDefinition($errors);
        }

        return ['key' => $key, 'type' => $type, 'labels' => $labels, 'help' => $help, 'rules' => $rules];
    }

    /**
     * Build the unsaved attribute of a definition after the same checks create() runs, without writing anything.
     * The preview endpoint uses it, so there is no second copy of the checks.
     *
     * @param  array<string, mixed>  $data  same shape as create()
     *
     * @throws CustomFieldException when the resource does not take fields or the definition is invalid
     */
    public function draft(string $resourceKey, array $data): MetaAttribute
    {
        $this->assertSupported($resourceKey);
        $definition = $this->definitionFrom($data);
        $type = $definition['type'];

        return new MetaAttribute([
            'name' => $this->attributeName($resourceKey, $definition['key']),
            'labels' => $definition['labels'],
            'help' => $definition['help'],
            'rules' => $definition['rules'],
            'type' => $type->metaType(),
            'input_type' => $type->inputType(),
            'is_multiple' => $type->isMultiple(),
            'is_required' => (bool) ($data['is_required'] ?? false),
            'show_in_list' => (bool) ($data['show_in_list'] ?? false),
            'is_searchable' => (bool) ($data['is_searchable'] ?? false),
            'is_filter' => (bool) ($data['is_filterable'] ?? false),
            'is_custom' => true,
        ]);
    }

    /**
     * Define a new custom field.
     *
     * @param  array<string, mixed>  $data  key, type, labels{vi,en}, help?, rules?, options?, is_required?, show_in_list?, is_searchable?, is_filterable?
     *
     * @throws CustomFieldException
     */
    public function create(string $resourceKey, array $data, ?int $userId): MetaAttribute
    {
        $this->assertSupported($resourceKey);
        ['key' => $key, 'type' => $type, 'labels' => $labels, 'help' => $help, 'rules' => $rules] = $this->definitionFrom($data);

        $created = DB::transaction(function () use ($resourceKey, $key, $type, $labels, $help, $rules, $data, $userId): MetaAttribute {
            $name = $this->attributeName($resourceKey, $key);
            if (MetaAttribute::withTrashed()->where('name', $name)->exists()) {
                throw CustomFieldException::duplicateKey($key);
            }
            if (MetaAttribute::query()->forResource($resourceKey)->count() >= self::MAX_FIELDS) {
                throw CustomFieldException::tooManyFields(self::MAX_FIELDS);
            }

            $class = $this->classFor($resourceKey, $userId);
            $priority = (int) MetaAttribute::query()->forResource($resourceKey)->max('priority') + 1;

            $field = MetaAttribute::create([
                'name' => $name,
                'label' => $labels['en'],
                'labels' => $labels,
                'help' => $help,
                'rules' => $rules,
                'type' => $type->metaType(),
                'input_type' => $type->inputType(),
                'is_multiple' => $type->isMultiple(),
                'is_enabled' => true,
                'is_required' => (bool) ($data['is_required'] ?? false),
                'show_in_list' => (bool) ($data['show_in_list'] ?? false),
                'is_searchable' => (bool) ($data['is_searchable'] ?? false),
                'is_filter' => (bool) ($data['is_filterable'] ?? false),
                'is_custom' => true,
                'priority' => $priority,
            ]);

            $class->options()->attach($field->id);

            return $field->fresh();
        });
        $this->invalidateSchemas([$resourceKey]);

        return $created;
    }

    /**
     * Change an existing custom field. Absent keys keep their value.
     *
     * @param  array<string, mixed>  $data  type?, labels?, help?, rules?, options?, is_required?, show_in_list?, is_searchable?, is_filterable?; key only if unchanged
     *
     * @throws CustomFieldException
     */
    public function update(MetaAttribute $field, array $data, ?int $userId = null): MetaAttribute
    {
        $currentKey = $this->keyOf($field);
        if (array_key_exists('key', $data) && $data['key'] !== $currentKey) {
            throw CustomFieldException::keyImmutable($currentKey);
        }

        $errors = [];
        $currentType = $this->typeOf($field);
        $type = array_key_exists('type', $data) ? $this->resolveType($data['type'], $errors) : $currentType;
        $labels = array_key_exists('labels', $data) ? $this->localized($data['labels'], 'labels', true, $errors) : null;
        $help = array_key_exists('help', $data) ? $this->localized($data['help'], 'help', false, $errors) : null;

        $existingRules = (array) ($field->rules ?? []);
        $existingOptions = $existingRules['options'] ?? null;
        unset($existingRules['options']);
        $rules = null;
        if ($type !== null) {
            $rules = $this->buildRules(
                $type,
                array_key_exists('rules', $data) ? $data['rules'] : $existingRules,
                array_key_exists('options', $data) ? $data['options'] : (array_key_exists('rules', $data) && isset($data['rules']['options']) ? null : $existingOptions),
                $errors,
            );
        }
        if ($errors !== [] || $type === null) {
            throw CustomFieldException::invalidDefinition($errors);
        }

        $updated = DB::transaction(function () use ($field, $data, $type, $currentType, $labels, $help, $rules, $currentKey): MetaAttribute {
            if ($type !== $currentType && $this->valueCount($field) > 0) {
                throw CustomFieldException::typeLocked($currentKey);
            }

            foreach ($this->removedOptions((array) ($field->rules['options'] ?? []), $rules['options'] ?? []) as $value) {
                if ($this->optionInUse($field, $value)) {
                    throw CustomFieldException::optionInUse($currentKey, $value);
                }
            }

            $changes = [
                'rules' => $rules,
                'type' => $type->metaType(),
                'input_type' => $type->inputType(),
                'is_multiple' => $type->isMultiple(),
            ];
            if ($labels !== null) {
                $changes['labels'] = $labels;
                $changes['label'] = $labels['en'];
            }
            if (array_key_exists('help', $data)) {
                $changes['help'] = $help;
            }
            foreach (['is_required', 'show_in_list', 'is_searchable'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $changes[$flag] = (bool) $data[$flag];
                }
            }
            if (array_key_exists('is_filterable', $data)) {
                $changes['is_filter'] = (bool) $data['is_filterable'];
            }

            $field->update($changes);

            return $field->fresh();
        });
        $this->invalidateSchemas($this->resourceKeysOf($field));

        return $updated;
    }

    /**
     * Set the display order of a resource's fields.
     *
     * Listed fields get priority 1..n in the given order; fields not listed (for example archived ones) follow,
     * keeping their relative order.
     *
     * @param  list<string>  $uuids  field uuids of this resource
     *
     * @throws CustomFieldException
     */
    public function reorder(string $resourceKey, array $uuids): void
    {
        $this->assertSupported($resourceKey);

        DB::transaction(function () use ($resourceKey, $uuids): void {
            $fields = $this->fieldsFor($resourceKey, true)->keyBy('uuid');
            $seen = [];
            foreach ($uuids as $uuid) {
                if (! is_string($uuid) || ! $fields->has($uuid) || isset($seen[$uuid])) {
                    throw CustomFieldException::unknownField(is_string($uuid) ? $uuid : '');
                }
                $seen[$uuid] = true;
            }

            $ordered = array_merge(array_keys($seen), $fields->keys()->reject(fn ($uuid) => isset($seen[$uuid]))->all());
            foreach ($ordered as $position => $uuid) {
                $field = $fields->get($uuid);
                if ((int) $field->priority !== $position + 1) {
                    $field->update(['priority' => $position + 1]);
                }
            }
        });
        $this->invalidateSchemas([$resourceKey]);
    }

    /**
     * Hide a custom field without losing data: sets `archived_at` and disables it. Values stay and the field can be
     * restored. Archiving an archived field changes nothing.
     *
     * @throws CustomFieldException when the attribute is not a custom field or was purged
     */
    public function archive(MetaAttribute $field): MetaAttribute
    {
        $this->assertLifecycle($field);
        if ($field->archived_at === null) {
            $field->update(['archived_at' => now(), 'is_enabled' => false]);
            $this->invalidateSchemas($this->resourceKeysOf($field));
        }

        return $field->fresh();
    }

    /**
     * Bring an archived custom field back: clears `archived_at` and enables it. Its values were kept, so they show
     * again. A purged field cannot be restored.
     *
     * @throws CustomFieldException when the attribute is not a custom field or was purged
     */
    public function restore(MetaAttribute $field): MetaAttribute
    {
        $this->assertLifecycle($field);
        $field->update(['archived_at' => null, 'is_enabled' => true]);
        $this->invalidateSchemas($this->resourceKeysOf($field));

        return $field->fresh();
    }

    /**
     * Number of distinct records (target type plus target id) holding at least one value for the field.
     */
    public function usage(MetaAttribute $field): int
    {
        if (! Schema::hasTable(CoreTableConstant::META_DATA)) {
            return 0;
        }

        return DB::table(CoreTableConstant::META_DATA)
            ->where('meta_attribute_id', $field->id)
            ->distinct()
            ->get(['target_type', 'target_id'])
            ->count();
    }

    /**
     * Take the safety backup that must exist before a purge.
     *
     * Checks the field and the retyped key first, so a wrong key never starts a backup, then takes a full database
     * backup through the BackupGate safety backup (the same one that guards imports) while every value row still exists. Call it
     * outside any open transaction: the backup records must survive whatever happens next.
     *
     * @param  string  $confirmKey  the retyped field key
     * @param  int|null  $userId  user who asked for the purge
     * @return Backup the stored backup to pass to purge()
     *
     * @throws CustomFieldException when the key does not match, the attribute is not a custom field or was purged, or
     *                              no copy of the backup could be stored
     */
    public function backupBeforePurge(MetaAttribute $field, string $confirmKey, ?int $userId): Backup
    {
        $this->assertPurgeable($field, $confirmKey);

        try {
            return ($this->backupGate ?? app(BackupGate::class))->ensure(Backup::REASON_PRE_PURGE, $userId);
        } catch (BackupRequiredException) {
            throw CustomFieldException::backupRequired();
        }
    }

    /**
     * Permanently delete the values of a custom field and retire the field, only against a stored backup.
     *
     * The operator must retype the field key exactly (case-sensitive) and the backup from backupBeforePurge() must be
     * a saved, not failed record with at least one stored copy; otherwise nothing is deleted. In one transaction every
     * assignment row of the field is deleted, then the attribute is soft-deleted (never force-deleted). The key stays
     * reserved forever after a purge: create() refuses to reuse it, which avoids ghost data resurfacing under a new
     * definition.
     *
     * @param  string  $confirmKey  the retyped field key
     * @param  Backup  $backup  backup taken by backupBeforePurge()
     * @return int assignment rows removed
     *
     * @throws CustomFieldException when the key does not match, the backup is not usable, or the attribute is not a
     *                              custom field or was purged
     */
    public function purge(MetaAttribute $field, string $confirmKey, Backup $backup): int
    {
        $this->assertPurgeable($field, $confirmKey);
        if (! $backup->exists
            || $backup->status === Backup::STATUS_FAILED
            || ! $backup->copies()->where('status', BackupCopy::STATUS_STORED)->exists()) {
            throw CustomFieldException::backupRequired();
        }

        $resourceKeys = $this->resourceKeysOf($field);
        $removed = DB::transaction(function () use ($field): int {
            $removed = DB::table(CoreTableConstant::META_DATA)->where('meta_attribute_id', $field->id)->delete();
            $field->delete();

            return $removed;
        });
        $this->invalidateSchemas($resourceKeys);

        return $removed;
    }

    /** Shared checks of the two purge steps: a live custom field and the exact retyped key. */
    private function assertPurgeable(MetaAttribute $field, string $confirmKey): void
    {
        $this->assertLifecycle($field);
        $key = $this->keyOf($field);
        if ($confirmKey !== $key) {
            throw CustomFieldException::confirmMismatch($key);
        }
    }

    /**
     * Resource keys whose class holds the field (normally exactly one).
     *
     * @return array<int, string>
     */
    private function resourceKeysOf(MetaAttribute $field): array
    {
        $pivot = CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT;
        $classes = CoreTableConstant::META_CLASSES;

        return DB::table($pivot)
            ->join($classes, "{$classes}.id", '=', "{$pivot}.meta_class_id")
            ->where("{$pivot}.meta_attribute_id", $field->id)
            ->pluck("{$classes}.resource_key")
            ->filter(static fn ($key): bool => is_string($key) && $key !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Invalidate the cached admin schemas of the resources, now and again once the surrounding transaction (if any)
     * commits, so a schema rebuilt from not yet committed data cannot outlive the change. Never throws.
     *
     * @param  array<int, string>  $resourceKeys
     */
    private function invalidateSchemas(array $resourceKeys): void
    {
        $bump = static function () use ($resourceKeys): void {
            foreach ($resourceKeys as $key) {
                app(SchemaCache::class)->bumpCustomFields($key);
            }
        };
        $bump();
        DB::afterCommit($bump);
    }

    /** Records holding a value for the field; type change and option removal are refused while this is above zero. */
    protected function valueCount(MetaAttribute $field): int
    {
        return $this->usage($field);
    }

    /** @throws CustomFieldException when the attribute is not custom or was purged */
    private function assertLifecycle(MetaAttribute $field): void
    {
        if (! $field->is_custom) {
            throw CustomFieldException::notCustomField();
        }
        if ($field->trashed()) {
            throw CustomFieldException::fieldPurged($this->keyOf($field));
        }
    }

    /** Whether any record holds this option value for the field (read-only). */
    protected function optionInUse(MetaAttribute $field, string $value): bool
    {
        $valueClass = get_value_class((string) $field->type);
        $valueTable = (new $valueClass())->getTable();
        $data = CoreTableConstant::META_DATA;

        return DB::table($data)
            ->join($valueTable, "{$valueTable}.id", '=', "{$data}.value_id")
            ->where("{$data}.meta_attribute_id", $field->id)
            ->where("{$data}.value_type", $valueClass)
            ->where("{$valueTable}.value", $value)
            ->exists();
    }

    /** @throws CustomFieldException when the resource is unknown or has not opted in */
    private function assertSupported(string $resourceKey): void
    {
        if (! $this->registry->has($resourceKey)) {
            throw CustomFieldException::resourceNotSupported($resourceKey);
        }
        $resource = $this->registry->make($resourceKey);
        if (! method_exists($resource, 'supportsCustomFields') || ! $resource->supportsCustomFields()) {
            throw CustomFieldException::resourceNotSupported($resourceKey);
        }
    }

    /** Find or create the meta class that holds a resource's custom fields. */
    private function classFor(string $resourceKey, ?int $userId): MetaClass
    {
        $class = MetaClass::withTrashed()->where('resource_key', $resourceKey)->first();
        if ($class !== null) {
            if ($class->trashed()) {
                $class->restore();
            }

            return $class;
        }

        $class = MetaClass::create([
            'name' => $resourceKey,
            'target' => $resourceKey,
            'type' => 'class',
            'resource_key' => $resourceKey,
        ]);
        // The observer stamps the signed-in user; the caller's id wins.
        MetaClass::query()->whereKey($class->id)->update(['user_id' => $userId]);

        return $class;
    }

    /** Attribute name for a field: cf.<resource with dots>.<key>. */
    private function attributeName(string $resourceKey, string $key): string
    {
        return 'cf.'.str_replace('/', '.', $resourceKey).'.'.$key;
    }

    /** Field key recovered from the attribute name (the part after the last dot). */
    private function keyOf(MetaAttribute $field): string
    {
        $name = (string) $field->name;

        return substr($name, (int) strrpos($name, '.') + 1);
    }

    /** Custom field type of a stored attribute. */
    private function typeOf(MetaAttribute $field): ?CustomFieldType
    {
        foreach (CustomFieldType::cases() as $case) {
            if ($case->inputType() === $field->input_type && $case->isMultiple() === (bool) $field->is_multiple) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $errors
     */
    private function resolveType(mixed $type, array &$errors): ?CustomFieldType
    {
        if ($type instanceof CustomFieldType) {
            return $type;
        }
        $resolved = is_string($type) ? CustomFieldType::tryFrom($type) : null;
        if ($resolved === null) {
            $errors[] = 'type must be one of: '.implode(', ', array_column(CustomFieldType::cases(), 'value'));
        }

        return $resolved;
    }

    /**
     * Validate a {vi, en} map. Required maps need both non-empty texts; optional ones may be null or all empty.
     *
     * @param  list<string>  $errors
     * @return array{vi: string, en: string}|null
     */
    private function localized(mixed $value, string $name, bool $required, array &$errors): ?array
    {
        if ($value === null || $value === []) {
            if ($required) {
                $errors[] = "{$name} need both vi and en texts";
            }

            return null;
        }
        $texts = [];
        foreach (self::LOCALES as $locale) {
            $text = is_array($value) && isset($value[$locale]) && is_string($value[$locale]) ? trim($value[$locale]) : '';
            $texts[$locale] = $text;
        }
        if (! $required && implode('', $texts) === '') {
            return null;
        }
        if (in_array('', $texts, true)) {
            $errors[] = "{$name} need both vi and en texts";

            return null;
        }

        return $texts;
    }

    /**
     * Validate rules and options and return the `rules` json to store.
     *
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function buildRules(CustomFieldType $type, mixed $rules, mixed $options, array &$errors): ?array
    {
        $rules = is_array($rules) ? $rules : [];
        if ($options === null && isset($rules['options'])) {
            $options = $rules['options'];
        }
        unset($rules['options']);

        $problems = $this->rules->validateDefinition($type, $rules);
        $normalized = null;
        $isSelect = in_array($type, [CustomFieldType::Select, CustomFieldType::Multiselect], true);
        if ($isSelect) {
            $normalized = $this->normalizeOptions($options, $problems);
        } elseif ($options !== null && $options !== []) {
            $problems[] = 'options are only allowed for select and multiselect fields';
        }
        array_push($errors, ...$problems);

        if ($problems !== []) {
            return null;
        }
        if ($normalized !== null) {
            $rules['options'] = $normalized;
        }

        return $rules === [] ? null : $rules;
    }

    /**
     * @param  list<string>  $errors
     * @return list<array{value: string, labels: array{vi: string, en: string}}>|null
     */
    private function normalizeOptions(mixed $options, array &$errors): ?array
    {
        if (! is_array($options) || $options === []) {
            $errors[] = 'options need at least one entry';

            return null;
        }
        $result = [];
        $values = [];
        $before = count($errors);
        foreach (array_values($options) as $index => $option) {
            $value = is_array($option) && isset($option['value']) && is_string($option['value']) ? trim($option['value']) : '';
            if ($value === '') {
                $errors[] = 'option #'.($index + 1).' needs a value';
                continue;
            }
            if (isset($values[$value])) {
                $errors[] = "option value [{$value}] is repeated";
                continue;
            }
            $values[$value] = true;
            $labelErrors = [];
            $labels = $this->localized($option['labels'] ?? null, 'labels', true, $labelErrors);
            if ($labels === null) {
                $errors[] = "option [{$value}] needs both vi and en labels";
                continue;
            }
            $result[] = ['value' => $value, 'labels' => $labels];
        }

        return count($errors) > $before ? null : $result;
    }

    /**
     * Option values present in the old list and missing from the new one.
     *
     * @param  array<int, array<string, mixed>>  $old
     * @param  array<int, array<string, mixed>>  $new
     * @return list<string>
     */
    private function removedOptions(array $old, array $new): array
    {
        $kept = array_column($new, 'value');

        return array_values(array_diff(array_column($old, 'value'), $kept));
    }
}
