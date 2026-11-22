<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage\Sections;

use Cms\Core\Admin\CustomFields\CustomFieldException;
use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\CustomFields\CustomFieldType;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Admin\Resources\SchemaCache;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaClass;
use Cms\Core\Models\ResourceOverlay;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\ApplyResult;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\SectionDiff;
use Cms\Core\Services\ConfigPackage\SectionExport;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Custom fields section of the configuration package.
 *
 * Carries definitions only: the custom fields of each admin resource and the per-site schema overlay. Record
 * values (`meta_data` rows) never travel and are never touched by an import. Rows are matched by resource key plus
 * field key (`meta_attributes.name` is `cf.<resource>.<key>`), never by id or uuid.
 *
 * Row shapes (one flat list, sorted by the exporter):
 * - field:   `{ resource, kind: "field", key, type, labels, help, rules, is_required, show_in_list, is_searchable,
 *               is_filterable, archived, position }`
 * - overlay: `{ resource, kind: "overlay", hidden, order, labels }`
 *
 * Import rules (additive): a missing field is created through CustomFieldService (so limits and validation are the
 * same as in the admin); an existing field with the same type has its definition updated through the service (which
 * refuses to drop an option that records use); an existing field with a different type is a blocked conflict and
 * is never changed; archived state of an existing field is never changed; fields are never deleted or purged. A
 * resource that is unknown or has not opted in to custom fields blocks its field rows. Every write goes through the
 * service or the overlay model, which bump the schema cache epochs.
 */
class CustomFieldsSection implements SectionAdapter
{
    /** Item key suffix of an overlay row. */
    public const OVERLAY_SUFFIX = '#overlay';

    /** Resource keys written by the last apply (used to refresh schema caches in afterApply). */
    private array $touched = [];

    /**
     * Create the section.
     */
    public function __construct(
        private readonly CustomFieldService $service,
        private readonly ResourceRegistry $registry,
    ) {}

    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'custom_fields';
    }

    /**
     * @inheritDoc
     *
     * Every custom field (archived ones included, flagged) and every overlay row. No values, ids or uuids.
     */
    public function export(ExportContext $c): SectionExport
    {
        $keys = MetaClass::query()->whereNotNull('resource_key')->pluck('resource_key')->all();
        $keys = array_unique(array_merge($keys, ResourceOverlay::query()->pluck('resource_key')->all()));
        sort($keys, SORT_STRING);

        $rows = [];
        foreach ($keys as $resource) {
            $resource = (string) $resource;
            $position = 0;
            foreach ($this->service->fieldsFor($resource, true) as $field) {
                $type = $this->typeOf($field);
                if ($type === null) {
                    continue;
                }
                $rows[] = [
                    'resource' => $resource,
                    'kind' => 'field',
                    'key' => $this->keyOf($field),
                    'type' => $type->value,
                    'labels' => (array) $field->labels,
                    'help' => $field->help === null ? null : (array) $field->help,
                    'rules' => $field->rules === null ? null : (array) $field->rules,
                    'is_required' => (bool) $field->is_required,
                    'show_in_list' => (bool) $field->show_in_list,
                    'is_searchable' => (bool) $field->is_searchable,
                    'is_filterable' => (bool) $field->is_filter,
                    'archived' => $field->archived_at !== null,
                    'position' => ++$position,
                ];
            }

            $overlay = ResourceOverlay::forResource($resource);
            if ($overlay !== null) {
                $rows[] = [
                    'resource' => $resource,
                    'kind' => 'overlay',
                    'hidden' => (array) ($overlay->hidden ?? []),
                    'order' => (array) ($overlay->order ?? []),
                    'labels' => (array) ($overlay->labels ?? []),
                ];
            }
        }

        return new SectionExport($rows);
    }

    /**
     * @inheritDoc
     */
    public function diff(array $rows, DiffContext $c): SectionDiff
    {
        $items = [];
        foreach ($this->ordered($rows) as $row) {
            $items[] = $this->plan($row);
        }

        return new SectionDiff($items);
    }

    /**
     * @inheritDoc
     */
    public function apply(array $rows, ApplyContext $c): ApplyResult
    {
        $counts = ['updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'blocked' => 0];
        $items = [];
        $this->touched = [];

        foreach ($this->ordered($rows) as $row) {
            // Fresh plan per row: earlier rows may have created fields.
            $plan = $this->plan($row);
            $key = (string) $plan['key'];
            $reason = $plan['reason'];

            if (in_array($plan['action'], ['create', 'update'], true) && ! $c->isChosen($key)) {
                $items[] = ['key' => $key, 'outcome' => 'skipped', 'reason' => 'not chosen'];
                ++$counts['skipped'];

                continue;
            }

            switch ($plan['action']) {
                case 'create':
                case 'update':
                    try {
                        $this->write($row, $plan, $c->userId);
                    } catch (CustomFieldException $e) {
                        $items[] = ['key' => $key, 'outcome' => 'blocked', 'reason' => $e->getMessage()];
                        ++$counts['blocked'];

                        continue 2;
                    }
                    $outcome = 'updated';
                    ++$counts['updated'];
                    $this->touched[(string) $row['resource']] = true;
                    break;
                case 'unchanged':
                    $outcome = 'unchanged';
                    ++$counts['unchanged'];
                    break;
                default:
                    $outcome = 'blocked';
                    ++$counts['blocked'];
            }

            $items[] = ['key' => $key, 'outcome' => $outcome, 'reason' => $reason];
        }

        return new ApplyResult($counts['updated'], $counts['unchanged'], $counts['skipped'], $counts['blocked'], $items);
    }

    /**
     * @inheritDoc
     *
     * The service and the overlay model already bump the schema epochs on every write; this bumps them once more
     * for every resource the apply touched, so a failed `afterCommit` hook cannot leave a stale schema behind.
     */
    public function afterApply(ApplyContext $c): void
    {
        $cache = app(SchemaCache::class);
        foreach (array_keys($this->touched) as $resource) {
            $cache->bumpCustomFields($resource);
            $cache->bumpOverlay($resource);
        }
    }

    /**
     * Rows that are objects, fields ordered by their `position` so creation keeps the display order.
     *
     * @param  array<int, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function ordered(array $rows): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        usort($rows, static fn (array $a, array $b): int => [(string) ($a['resource'] ?? ''), (int) ($a['position'] ?? 0)]
            <=> [(string) ($b['resource'] ?? ''), (int) ($b['position'] ?? 0)]);

        return $rows;
    }

    /**
     * Compare one incoming row with this site (reads only).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed> `{key, action, reason, before, after}`
     */
    private function plan(array $row): array
    {
        $resource = is_string($row['resource'] ?? null) ? $row['resource'] : '';
        $kind = $row['kind'] ?? null;
        $item = ['key' => $resource, 'action' => 'blocked', 'reason' => null, 'before' => null, 'after' => null];

        if ($resource === '' || ! in_array($kind, ['field', 'overlay'], true)) {
            $item['reason'] = 'row needs a resource and a kind of field or overlay';

            return $item;
        }

        return $kind === 'overlay' ? $this->planOverlay($row, $resource, $item) : $this->planField($row, $resource, $item);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function planField(array $row, string $resource, array $item): array
    {
        $key = is_string($row['key'] ?? null) ? $row['key'] : '';
        $item['key'] = $resource.':'.$key;

        try {
            $draft = $this->service->draft($resource, $this->definition($row));
        } catch (CustomFieldException $e) {
            $item['reason'] = $e->getMessage();

            return $item;
        }

        $name = (string) $draft->name;
        $existing = MetaAttribute::withTrashed()->where('name', $name)->first();
        $after = $this->shape($draft);

        if ($existing === null) {
            if ($this->service->fieldsFor($resource, true)->count() >= CustomFieldService::MAX_FIELDS) {
                $item['reason'] = 'the resource already has the most custom fields allowed';

                return $item;
            }

            return ['action' => 'create', 'reason' => null, 'after' => $after] + $item;
        }

        if ($existing->trashed() || ! $existing->is_custom) {
            $item['reason'] = 'this key was retired or belongs to a non-custom attribute on this site';

            return $item;
        }

        $item['before'] = $this->shape($existing);
        $item['after'] = $after;

        if ($this->typeOf($existing)?->value !== $row['type']) {
            $item['reason'] = 'a field with this key exists with a different type; it is never overwritten';

            return $item;
        }

        $item['action'] = PackageFormat::canonicalJson($item['before']) === PackageFormat::canonicalJson($after) ? 'unchanged' : 'update';

        return $item;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function planOverlay(array $row, string $resource, array $item): array
    {
        $item['key'] = $resource.self::OVERLAY_SUFFIX;

        if (! $this->registry->has($resource)) {
            $item['reason'] = 'the resource does not exist on this site';

            return $item;
        }

        $after = $this->overlayShape($row);
        if ($after === null) {
            $item['reason'] = 'overlay needs hidden and order as lists of names and labels as name => locale => text';

            return $item;
        }

        $existing = ResourceOverlay::forResource($resource);
        $item['after'] = $after;
        if ($existing === null) {
            $item['action'] = 'create';

            return $item;
        }

        $item['before'] = $this->overlayShape($existing->only(['hidden', 'order', 'labels']));
        $item['action'] = PackageFormat::canonicalJson($item['before']) === PackageFormat::canonicalJson($after) ? 'unchanged' : 'update';

        return $item;
    }

    /**
     * Write one planned row through the service or the overlay model.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $plan
     *
     * @throws CustomFieldException
     */
    private function write(array $row, array $plan, ?int $userId): void
    {
        $resource = (string) $row['resource'];

        if (($row['kind'] ?? null) === 'overlay') {
            ResourceOverlay::query()->updateOrCreate(['resource_key' => $resource], $plan['after'] + ['updated_by' => $userId]);

            return;
        }

        $data = $this->definition($row);

        if ($plan['action'] === 'update') {
            $field = MetaAttribute::query()->where('name', $this->nameOf($resource, (string) $row['key']))->firstOrFail();
            unset($data['key'], $data['type']);
            $this->service->update($field, $data, $userId);

            return;
        }

        $field = $this->service->create($resource, $data, $userId);
        if (($row['archived'] ?? false) === true) {
            $this->service->archive($field);
        }
    }

    /**
     * Service input of a field row.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function definition(array $row): array
    {
        return [
            'key' => $row['key'] ?? null,
            'type' => $row['type'] ?? null,
            'labels' => $row['labels'] ?? null,
            'help' => $row['help'] ?? null,
            'rules' => $row['rules'] ?? [],
            'is_required' => (bool) ($row['is_required'] ?? false),
            'show_in_list' => (bool) ($row['show_in_list'] ?? false),
            'is_searchable' => (bool) ($row['is_searchable'] ?? false),
            'is_filterable' => (bool) ($row['is_filterable'] ?? false),
        ];
    }

    /**
     * Comparable definition of an attribute (stored or draft).
     *
     * @return array<string, mixed>
     */
    private function shape(MetaAttribute $field): array
    {
        return [
            'labels' => (array) $field->labels,
            'help' => $field->help === null ? null : (array) $field->help,
            'rules' => $field->rules === null ? null : (array) $field->rules,
            'is_required' => (bool) $field->is_required,
            'show_in_list' => (bool) $field->show_in_list,
            'is_searchable' => (bool) $field->is_searchable,
            'is_filterable' => (bool) $field->is_filter,
        ];
    }

    /**
     * Normalized overlay content, or null when the shape is wrong.
     *
     * @param  array<string, mixed>  $source
     * @return array{hidden: list<string>, order: list<string>, labels: array<string, array<string, string>>}|null
     */
    private function overlayShape(array $source): ?array
    {
        $hidden = $source['hidden'] ?? [];
        $order = $source['order'] ?? [];
        $labels = $source['labels'] ?? [];

        foreach ([$hidden, $order] as $list) {
            if (! is_array($list) || ! array_is_list($list) || array_filter($list, static fn ($v): bool => ! is_string($v) || $v === '') !== []) {
                return null;
            }
        }
        if (! is_array($labels)) {
            return null;
        }
        foreach ($labels as $name => $texts) {
            if (! is_string($name) || ! is_array($texts) || array_filter($texts, static fn ($v, $k): bool => ! is_string($k) || ! is_string($v), ARRAY_FILTER_USE_BOTH) !== []) {
                return null;
            }
        }

        return ['hidden' => $hidden, 'order' => $order, 'labels' => $labels];
    }

    /** Attribute name of a field: `cf.<resource with dots>.<key>` (same rule as CustomFieldService). */
    private function nameOf(string $resource, string $key): string
    {
        return 'cf.'.str_replace('/', '.', $resource).'.'.$key;
    }

    /** Field key recovered from the attribute name. */
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
}
