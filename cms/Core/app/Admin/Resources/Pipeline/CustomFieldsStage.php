<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources\Pipeline;

use Cms\Core\Admin\CustomFields\CustomFieldMapper;
use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\Resources\Field;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Second pipeline stage: adds the resource's custom fields to the schema (spec 6.6).
 *
 * Only a resource that opted in with {@see \Cms\Core\Admin\Resources\Resource::supportsCustomFields()} is touched; any
 * other resource is returned unchanged even when attribute rows exist for its key. The non-archived fields come from
 * one {@see CustomFieldService::fieldsFor()} query and are appended to the create and update forms, the detail
 * sections and, by their flags, to the list columns, filters and search fields. Custom fields are registered on the
 * {@see SchemaContext}, so {@see PermissionStage} treats them like declared fields.
 */
final class CustomFieldsStage implements SchemaStage
{
    /** Create the stage; collaborators are resolved from the container when not given. */
    public function __construct(
        private readonly ?CustomFieldService $service = null,
        private readonly ?CustomFieldMapper $mapper = null,
    ) {}

    /** Append the custom fields of an opted-in resource. */
    public function apply(array $schema, SchemaContext $context): array
    {
        $resource = $context->resource;
        if (! $resource->supportsCustomFields()) {
            return $schema;
        }

        $service = $this->service ?? app(CustomFieldService::class);
        $mapper = $this->mapper ?? new CustomFieldMapper;

        /** @var array<int, Field> $fields */
        $fields = [];
        foreach ($service->fieldsFor($resource->key()) as $attribute) {
            try {
                $fields[] = $mapper->toField($attribute);
            } catch (InvalidArgumentException $e) {
                Log::warning("Custom field [{$attribute->name}] of [{$resource->key()}] skipped: {$e->getMessage()}");
            }
        }

        foreach ($fields as $field) {
            $context->registerField($field);
            $schema = $this->add($schema, $field);
        }

        return $schema;
    }

    /**
     * Add one field to every view it belongs in.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function add(array $schema, Field $field): array
    {
        $entry = $field->toSchema();

        if (is_array($schema['form'] ?? null)) {
            foreach (['create', 'update'] as $form) {
                if (isset($schema['form'][$form]['fields']) && $field->availableIn($form)) {
                    $schema['form'][$form]['fields'][] = $entry;
                }
            }
        }

        if (is_array($schema['detail'] ?? null)) {
            $schema['detail']['sections'][] = $entry;
        }

        if (is_array($schema['list'] ?? null)) {
            if (($entry['list'] ?? false) === true) {
                $schema['list']['columns'][] = $this->withLabelText(DefinitionStage::columnOf($field), $entry);
            }
            if ($field->isFilterable() || $this->filterableByType($field)) {
                $schema['list']['filters'][] = $this->filterEntry($field, $entry);
            }
            if ($field->isSearchable() && isset($schema['list']['search'])) {
                $schema['list']['search']['fields'][] = $field->name();
            }
        }

        return $schema;
    }

    /**
     * Whether the type of a custom field is filterable without an operator flag: select, multiselect,
     * boolean, integer and date. Text and textarea fields are found through search instead.
     */
    private function filterableByType(Field $field): bool
    {
        return in_array($field->toSchema()['type'] ?? null, ['enum', 'boolean', 'number', 'date'], true);
    }

    /**
     * List filter of a custom field: the common filter shape plus the query parameter the list endpoint reads
     * (`cf[<key>]`) and, for integer and date fields, `range` (the bounds go as `cf[<key>][from]` and `[to]`).
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function filterEntry(Field $field, array $entry): array
    {
        $filter = $this->withLabelText(DefinitionStage::filterOf($field), $entry);
        $key = substr((string) $field->name(), strlen('custom_fields.'));
        $filter['param'] = 'cf['.$key.']';
        if (in_array($entry['type'] ?? null, ['number', 'date'], true)) {
            $filter['range'] = true;
        }
        if (($entry['multiple'] ?? false) === true) {
            $filter['multiple'] = true;
        }

        return $filter;
    }

    /**
     * Carry the literal per-locale label of a custom field into a list entry.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function withLabelText(array $item, array $entry): array
    {
        if (isset($entry['label_text'])) {
            $item['label_text'] = $entry['label_text'];
        }
        $item['custom'] = true;

        return $item;
    }
}
