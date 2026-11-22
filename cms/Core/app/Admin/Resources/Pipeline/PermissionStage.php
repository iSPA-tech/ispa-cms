<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources\Pipeline;

use App\Models\User;
use Cms\Core\Admin\Resources\Field;
use Cms\Core\Services\DataScope\DataScope;
use Illuminate\Support\Facades\Gate;

/**
 * Last pipeline stage: removes what the user may not use (spec 6.6).
 *
 * Every field registered on the {@see SchemaContext} (declared, custom, or added by an overlay) is dropped from the
 * list columns, filters and search fields, the form fields and the detail sections when the user lacks its permission
 * ability or the data scope hides it. Action input fields are checked the same way. When the first list column carried
 * the `detail` link and is removed, the link moves to the new first column.
 *
 * A resource that declares {@see \Cms\Core\Admin\Resources\Resource::abilities()} also gets `abilities: {view, manage}`
 * (what the user holds); without manage the form, the actions and the list row/bulk actions are removed so the backend
 * never offers writes. A resource without that declaration is left untouched.
 *
 * When the resource names a scoped resource, top-level fields whose name equals a path hidden by
 * `DataScope::hiddenFields()` are hidden (nested paths such as `a.b` hide only part of a field and are left to the
 * data response). A user that is not a Cms user counts as no actor (all marked fields hidden).
 */
final class PermissionStage implements SchemaStage
{
    /** Remove the entries of fields the user may not use. */
    public function apply(array $schema, SchemaContext $context): array
    {
        $gate = Gate::forUser($context->user);
        $hidden = $this->hiddenFieldNames($context);
        $usable = static fn (Field $field): bool => ($field->permissionAbility() === null || $gate->allows($field->permissionAbility()))
            && ! in_array($field->name(), $hidden, true);

        $denied = [];
        foreach ($context->fields() as $name => $field) {
            if (! $usable($field)) {
                $denied[] = $name;
            }
        }

        if (is_array($schema['list'] ?? null)) {
            $schema['list'] = $this->stripList($schema['list'], $denied);
        }
        if (is_array($schema['form'] ?? null)) {
            foreach (['create', 'update'] as $form) {
                if (isset($schema['form'][$form]['fields'])) {
                    $schema['form'][$form]['fields'] = $this->without($schema['form'][$form]['fields'], $denied);
                }
            }
        }
        if (is_array($schema['detail'] ?? null)) {
            $schema['detail']['sections'] = $this->without($schema['detail']['sections'] ?? [], $denied);
        }
        if (is_array($schema['actions'] ?? null)) {
            foreach ($schema['actions'] as $index => $item) {
                if (! isset($item['fields'])) {
                    continue;
                }
                $kept = [];
                foreach ($context->actionInputs($item['name']) as $position => $field) {
                    if ($usable($field) && isset($item['fields'][$position])) {
                        $kept[] = $item['fields'][$position];
                    }
                }
                if ($kept === []) {
                    unset($schema['actions'][$index]['fields']);
                } else {
                    $schema['actions'][$index]['fields'] = $kept;
                }
            }
        }

        return $this->applyAbilities($schema, $context, $gate);
    }

    /**
     * Export the held view/manage abilities and make the schema read-only for a user without manage.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function applyAbilities(array $schema, SchemaContext $context, \Illuminate\Contracts\Auth\Access\Gate $gate): array
    {
        $declared = $context->resource->abilities();
        if ($declared === null) {
            return $schema;
        }

        $manage = $gate->allows($declared['manage']);
        $schema['abilities'] = ['view' => $gate->allows($declared['view']), 'manage' => $manage];

        if (! $manage) {
            $schema['form'] = null;
            $schema['actions'] = [];
            if (is_array($schema['list'] ?? null)) {
                unset($schema['list']['rowActions'], $schema['list']['bulkActions'], $schema['list']['selectable']);
            }
        }

        return $schema;
    }

    /**
     * Remove denied columns, filters and search fields of a list.
     *
     * @param  array<string, mixed>  $list
     * @param  array<int, string>  $denied
     * @return array<string, mixed>
     */
    private function stripList(array $list, array $denied): array
    {
        $columns = $list['columns'] ?? [];
        $linked = ($columns[0]['link'] ?? null) === 'detail' && in_array($columns[0]['field'], $denied, true);
        $columns = $this->without($columns, $denied);
        if ($linked && $columns !== []) {
            $columns[0]['link'] = 'detail';
        }
        $list['columns'] = $columns;
        $list['filters'] = $this->without($list['filters'] ?? [], $denied);

        if (isset($list['search']['fields'])) {
            $list['search']['fields'] = array_values(array_diff($list['search']['fields'], $denied));
        }

        return $list;
    }

    /**
     * Drop the schema entries whose `field` is denied.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int, string>  $denied
     * @return array<int, array<string, mixed>>
     */
    private function without(array $entries, array $denied): array
    {
        return array_values(array_filter($entries, static fn (array $entry): bool => ! in_array($entry['field'] ?? null, $denied, true)));
    }

    /**
     * Field paths the data scope hides from the user for the resource's scoped resource.
     *
     * @return array<int, string>
     */
    private function hiddenFieldNames(SchemaContext $context): array
    {
        $key = $context->resource->scopedResource();
        if ($key === null) {
            return [];
        }

        return app(DataScope::class)->hiddenFields($key, $context->user instanceof User ? $context->user : null);
    }
}
