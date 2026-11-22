<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources\Fields;

use Closure;

/**
 * Relation field configuration: a record picked by human-readable label through a lookup endpoint.
 *
 * Holds the UI lookup config (exported to the schema) and an optional server-side existence/scope check that is only
 * used to build validation rules and is never exported.
 */
final class RelationField
{
    /** Page size the UI requests from the lookup endpoint. */
    public const PAGE_SIZE = 20;

    /**
     * @param  string  $endpoint  lookup endpoint name or path called by the frontend
     * @param  string  $labelKey  record attribute shown as the label
     * @param  string  $valueKey  record attribute submitted as the value
     * @param  Closure(string): bool|null  $exists  server-side existence/scope check
     * @param  string  $searchParam  query-string parameter the lookup endpoint reads the search term from
     * @param  bool  $multiple  whether several records can be picked (the value is a list of keys)
     * @param  array{field: string, param: string}|null  $dependsOn  parent form field whose value scopes the lookup
     * @param  array{field: string, map: array<string, string>}|null  $endpointsBy  parent form field whose value picks the lookup endpoint
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $labelKey = 'name',
        private readonly string $valueKey = 'uuid',
        private readonly ?Closure $exists = null,
        private readonly string $searchParam = 'search',
        private readonly bool $multiple = false,
        private readonly ?array $dependsOn = null,
        private readonly ?array $endpointsBy = null,
    ) {}

    /** Return a copy that revalidates submitted values with the given check. */
    public function withExists(Closure $exists): self
    {
        return new self($this->endpoint, $this->labelKey, $this->valueKey, $exists, $this->searchParam, $this->multiple, $this->dependsOn, $this->endpointsBy);
    }

    /** Return a copy whose lookup endpoint reads the search term from the given query parameter. */
    public function withSearchParam(string $searchParam): self
    {
        return new self($this->endpoint, $this->labelKey, $this->valueKey, $this->exists, $searchParam, $this->multiple, $this->dependsOn, $this->endpointsBy);
    }

    /** Return a copy that lets several records be picked (the value is a list of keys). */
    public function withMultiple(): self
    {
        return new self($this->endpoint, $this->labelKey, $this->valueKey, $this->exists, $this->searchParam, true, $this->dependsOn, $this->endpointsBy);
    }

    /**
     * Return a copy scoped by another form field: the lookup sends that field's value as the query parameter `$param`.
     *
     * @param  string  $field  name of the parent form field
     * @param  string  $param  query-string parameter the lookup endpoint reads the parent value from
     */
    public function withDependsOn(string $field, string $param): self
    {
        return new self($this->endpoint, $this->labelKey, $this->valueKey, $this->exists, $this->searchParam, $this->multiple, ['field' => $field, 'param' => $param], $this->endpointsBy);
    }

    /**
     * Return a copy whose lookup endpoint is chosen by another form field: the parent value maps to an endpoint.
     *
     * The constructor `endpoint` stays exported as the default/fallback; the UI resolves the real endpoint from the map.
     *
     * @param  string  $field  name of the parent form field
     * @param  array<string, string>  $map  parent value => lookup endpoint
     *
     * @throws \InvalidArgumentException when the field is empty or the map is empty or not string => non-empty string
     */
    public function endpointsBy(string $field, array $map): self
    {
        if ($field === '' || $map === []) {
            throw new \InvalidArgumentException('endpointsBy needs a parent field and a non-empty map.');
        }
        foreach ($map as $value => $endpoint) {
            if (! is_string($value) || $value === '' || ! is_string($endpoint) || $endpoint === '') {
                throw new \InvalidArgumentException('endpointsBy map must use non-empty string keys and string endpoints.');
            }
        }

        return new self($this->endpoint, $this->labelKey, $this->valueKey, $this->exists, $this->searchParam, $this->multiple, $this->dependsOn, ['field' => $field, 'map' => $map]);
    }

    /**
     * Export the lookup config for the UI schema; the existence check is never included.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $schema = [
            'endpoint' => $this->endpoint,
            'label_key' => $this->labelKey,
            'value_key' => $this->valueKey,
            'page_size' => self::PAGE_SIZE,
            'search_param' => $this->searchParam,
        ];
        if ($this->multiple) {
            $schema['multiple'] = true;
        }
        if ($this->dependsOn !== null) {
            $schema['depends_on'] = $this->dependsOn;
        }
        if ($this->endpointsBy !== null) {
            $schema['endpoints_by'] = $this->endpointsBy;
        }

        return $schema;
    }

    /**
     * Validation rules for a submitted value: a uuid, plus the existence check when one is set.
     *
     * A multiple relation expects a list: every item must be a uuid (and pass the existence check).
     *
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        if ($this->multiple) {
            return $this->multipleRules();
        }

        $rules = ['uuid'];

        if ($this->exists !== null) {
            $check = $this->exists;
            $rules[] = function (string $attribute, mixed $value, Closure $fail) use ($check): void {
                if (is_string($value) && $value !== '' && ! $check($value)) {
                    $fail('validation.exists')->translate();
                }
            };
        }

        return $rules;
    }

    /**
     * Rules of a multiple relation: an array whose items are uuids passing the existence check.
     *
     * @return array<int, mixed>
     */
    private function multipleRules(): array
    {
        $check = $this->exists;

        return [
            'array',
            function (string $attribute, mixed $value, Closure $fail) use ($check): void {
                foreach (is_array($value) ? $value : [] as $item) {
                    if (! is_string($item) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $item)) {
                        $fail('validation.uuid')->translate();

                        return;
                    }
                    if ($check !== null && ! $check($item)) {
                        $fail('validation.exists')->translate();

                        return;
                    }
                }
            },
        ];
    }
}
