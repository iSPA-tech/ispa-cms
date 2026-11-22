<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources;

use Cms\Core\Admin\Resources\Fields\EnumerateField;
use Cms\Core\Admin\Resources\Fields\RelationField;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Fluent admin resource field definition.
 *
 * A field produces both Laravel validation rules (server side) and a
 * JSON-compatible UI schema (client side). Server-only data (extra rules and
 * the permission ability) is never exported to the schema.
 */
final class Field
{
    private string $labelKey;

    private bool $required = false;

    /** Value a create form starts with (scalar), or null for none. */
    private string|int|float|bool|null $default = null;

    private ?int $max = null;

    private ?int $min = null;

    private ?string $pattern = null;

    /** @var array<int, mixed> */
    private array $extraRules = [];

    private bool $searchable = false;

    private bool $filterable = false;

    private bool $sortable = false;

    /** @var array<int, string> */
    private array $contexts = [];

    private bool $readOnly = false;

    /** @var array<string, mixed>|null */
    private ?array $colors = null;

    /** @var array<string, mixed>|null */
    private ?array $link = null;

    /** @var array<string, mixed>|null */
    private ?array $visibleWhen = null;

    /** @var array<string, mixed>|null */
    private ?array $requiredWhen = null;

    private ?string $ability = null;

    /** @var array<string, string> */
    private array $optionLabels = [];

    private ?int $width = null;

    private ?string $filterAllLabel = null;

    private ?RelationField $relation = null;

    private ?EnumerateField $enumerate = null;

    private ?string $unit = null;

    private ?string $slugFrom = null;

    /** @var array{value: string, label: ?string}|null */
    private ?array $fromRecord = null;

    /** @var array{warn_days: int, expired_label: string, warning_label: string}|null */
    private ?array $expiry = null;

    private bool $dateOnly = false;

    private ?string $secondary = null;

    private ?string $rangeStart = null;

    private ?string $rangeEnd = null;

    /** @var array<string, string>|null literal per-locale label (custom fields) */
    private ?array $labelText = null;

    /** @var array<string, string>|null literal per-locale help text (custom fields) */
    private ?array $helpText = null;

    /** @var array<string, array<string, string>>|null literal per-locale option labels keyed by option value */
    private ?array $optionText = null;

    private bool $custom = false;

    private bool $multiple = false;

    private bool $listed = false;

    /** @var array<int, mixed>|null prebuilt Laravel rules that replace the generated ones */
    private ?array $prebuiltRules = null;

    /** @var array<string, mixed>|null prebuilt schema rules that replace the generated ones */
    private ?array $prebuiltSchemaRules = null;

    /**
     * @param  array<int, mixed>  $values  allowed values for enum/status fields
     */
    private function __construct(
        private readonly string $name,
        private readonly string $type,
        private readonly array $values = [],
    ) {
        $this->labelKey = $name;
        $this->readOnly = in_array($type, ['count', 'date_range'], true);
    }

    /** Create a single-line text field. */
    public static function text(string $name): static
    {
        return new self($name, 'text');
    }

    /** Create a multi-line text field. */
    public static function textarea(string $name): static
    {
        return new self($name, 'textarea');
    }

    /** Create an email field. */
    public static function email(string $name): static
    {
        return new self($name, 'email');
    }

    /** Create a numeric field. */
    public static function number(string $name): static
    {
        return new self($name, 'number');
    }

    /** Create a boolean field. */
    public static function boolean(string $name): static
    {
        return new self($name, 'boolean');
    }

    /**
     * Create an enum field restricted to the given values.
     *
     * @param  array<int, mixed>  $values
     */
    public static function enum(string $name, array $values): static
    {
        return new self($name, 'enum', $values);
    }

    /**
     * Create a status field (an enum rendered as a badge) restricted to the given values.
     *
     * @param  array<int, mixed>  $values
     */
    public static function status(string $name, array $values): static
    {
        return new self($name, 'status', $values);
    }

    /**
     * Create a relation field: a record picked by label through a lookup endpoint.
     *
     * @param  string  $endpoint  lookup endpoint name or path called by the frontend
     * @param  string  $labelKey  record attribute shown as the label
     * @param  string  $valueKey  record attribute submitted as the value
     */
    public static function relation(string $name, string $endpoint, string $labelKey = 'name', string $valueKey = 'uuid'): static
    {
        $field = new self($name, 'relation');
        $field->relation = new RelationField($endpoint, $labelKey, $valueKey);

        return $field;
    }

    /**
     * Create an enumerate field: a value picked from one group of the Core `enumerates` table.
     *
     * @param  string  $group  enumerates `type`; the submitted value is the entry's `enum_key`
     */
    public static function enumerate(string $name, string $group): static
    {
        $field = new self($name, 'enumerate');
        $field->enumerate = new EnumerateField($group);

        return $field;
    }

    /** Create a read-only count field. */
    public static function count(string $name): static
    {
        return new self($name, 'count');
    }

    /**
     * Create a display-only date range column built from two record date fields.
     *
     * @param  string  $startField  record attribute holding the start date
     * @param  string  $endField  record attribute holding the end date
     */
    public static function dateRange(string $name, string $startField, string $endField): static
    {
        $field = new self($name, 'date_range');
        $field->rangeStart = $startField;
        $field->rangeEnd = $endField;

        return $field;
    }

    /**
     * Create a slug field: a URL-safe text derived from another form field until the user edits it by hand.
     *
     * @param  string  $fromField  name of the form field the slug follows
     */
    public static function slug(string $name, string $fromField): static
    {
        $field = new self($name, 'slug');
        $field->slugFrom = $fromField;

        return $field;
    }

    /** Create a date field. */
    public static function date(string $name): static
    {
        return new self($name, 'date');
    }

    /** Create a date-time field. */
    public static function datetime(string $name): static
    {
        return new self($name, 'datetime');
    }

    /**
     * Set the server-side existence/scope check of a relation field (revalidated on submit, never exported).
     *
     * @param  callable(string): bool  $check
     *
     * @throws \LogicException when the field is not a relation field
     */
    public function relationExists(callable $check): static
    {
        if ($this->relation === null) {
            throw new \LogicException("Field [{$this->name}] is not a relation field.");
        }
        $this->relation = $this->relation->withExists(Closure::fromCallable($check));

        return $this;
    }

    /**
     * Set the query parameter the relation lookup endpoint reads the search term from (default `search`).
     *
     * @throws \LogicException when the field is not a relation field
     */
    public function searchParam(string $param): static
    {
        if ($this->relation === null) {
            throw new \LogicException("Field [{$this->name}] is not a relation field.");
        }
        $this->relation = $this->relation->withSearchParam($param);

        return $this;
    }

    /**
     * Scope a relation field by another form field: the lookup sends the parent field's value as query parameter `$param`,
     * the picker is disabled while the parent is empty and clears when the parent changes.
     *
     * @param  string  $field  name of the parent form field
     * @param  string  $param  query-string parameter the lookup endpoint reads the parent value from
     *
     * @throws \LogicException when the field is not a relation field
     */
    public function dependsOn(string $field, string $param): static
    {
        if ($this->relation === null) {
            throw new \LogicException("Field [{$this->name}] is not a relation field.");
        }
        $this->relation = $this->relation->withDependsOn($field, $param);

        return $this;
    }

    /**
     * Pick a relation field's lookup endpoint by another form field's value (parent value => endpoint). The picker is
     * disabled while the parent has no mapped value and clears when the parent changes.
     *
     * @param  string  $field  name of the parent form field
     * @param  array<string, string>  $map  parent value => lookup endpoint
     *
     * @throws \LogicException when the field is not a relation field
     * @throws \InvalidArgumentException when the field or map is invalid
     */
    public function endpointsBy(string $field, array $map): static
    {
        if ($this->relation === null) {
            throw new \LogicException("Field [{$this->name}] is not a relation field.");
        }
        $this->relation = $this->relation->endpointsBy($field, $map);

        return $this;
    }

    /**
     * Name the record keys that hold this field's current value and label when the API payload calls them differently
     * from the form field (for example the form field `product_variant_uuid` reads `variant_uuid` / `variant_name`).
     * The relation widget prefills an empty value and shows the label without a lookup.
     *
     * @param  string  $valueKey  record key holding the current value
     * @param  string|null  $labelKey  record key holding the current label
     */
    public function fromRecord(string $valueKey, ?string $labelKey = null): static
    {
        $this->fromRecord = ['value' => $valueKey, 'label' => $labelKey];

        return $this;
    }

    /**
     * Show the date as an expiry date: expired dates in danger colours, dates within the threshold in warning
     * colours. The state is also written out with the given labels so colour is never the only signal.
     *
     * @param  int  $warnDays  days before the date from which it counts as expiring
     * @param  string  $expiredLabel  translation key of the expired state
     * @param  string  $warningLabel  translation key of the expiring state
     */
    public function expiry(int $warnDays, string $expiredLabel, string $warningLabel): static
    {
        $this->expiry = ['warn_days' => $warnDays, 'expired_label' => $expiredLabel, 'warning_label' => $warningLabel];

        return $this;
    }

    /** Display the date without its time part. */
    public function dateOnly(): static
    {
        $this->dateOnly = true;

        return $this;
    }

    /** Set the unit translation key shown after a number value. */
    public function unit(string $unitKey): static
    {
        $this->unit = $unitKey;

        return $this;
    }

    /** Set the label translation key. */
    public function label(string $key): static
    {
        $this->labelKey = $key;

        return $this;
    }

    /**
     * Set literal per-locale label text (operator-written labels that are not translation keys).
     *
     * @param  array<string, string>  $labels  label keyed by locale
     */
    public function labelText(array $labels): static
    {
        $this->labelText = $labels;

        return $this;
    }

    /**
     * Set literal per-locale help text.
     *
     * @param  array<string, string>  $help  help text keyed by locale
     */
    public function helpText(array $help): static
    {
        $this->helpText = $help;

        return $this;
    }

    /**
     * Set literal per-locale option labels for an enum field.
     *
     * @param  array<string, array<string, string>>  $labelsByValue  per-locale label keyed by option value
     */
    public function optionText(array $labelsByValue): static
    {
        $this->optionText = $labelsByValue;

        return $this;
    }

    /** Mark the field as an operator-defined custom field. */
    public function custom(): static
    {
        $this->custom = true;

        return $this;
    }

    /** Mark the field as accepting several values (a relation field then picks a list of keys). */
    public function multiple(): static
    {
        $this->multiple = true;
        if ($this->relation !== null) {
            $this->relation = $this->relation->withMultiple();
        }

        return $this;
    }

    /** Show the field as a list column. */
    public function listed(): static
    {
        $this->listed = true;

        return $this;
    }

    /**
     * Use prebuilt rules instead of the generated ones.
     *
     * @param  array<int, mixed>  $laravelRules  complete server rule list (including required or nullable)
     * @param  array<string, mixed>  $schemaRules  rules exported to the UI schema
     */
    public function prebuiltRules(array $laravelRules, array $schemaRules): static
    {
        $this->prebuiltRules = $laravelRules;
        $this->prebuiltSchemaRules = $schemaRules;

        return $this;
    }

    /**
     * Value a create form starts with, so the operator sees what the server would otherwise fill in silently.
     * Edit forms keep the stored value. Exported to the UI schema as `default`.
     */
    public function default(string|int|float|bool $value): static
    {
        $this->default = $value;

        return $this;
    }

    /** Mark the field as always required. */
    public function required(): static
    {
        $this->required = true;

        return $this;
    }

    /** Set the maximum length or value. */
    public function max(int $max): static
    {
        $this->max = $max;

        return $this;
    }

    /** Set the minimum length or value. */
    public function min(int $min): static
    {
        $this->min = $min;

        return $this;
    }

    /** Set a regular expression the value must match. */
    public function pattern(string $regex): static
    {
        $this->pattern = $regex;

        return $this;
    }

    /** Add an extra server-only validation rule; may be called several times. */
    public function rule(mixed $rule): static
    {
        $this->extraRules[] = $rule;

        return $this;
    }

    /** Include the field in text search. */
    public function searchable(): static
    {
        $this->searchable = true;

        return $this;
    }

    /** Offer the field as a list filter. */
    public function filterable(): static
    {
        $this->filterable = true;

        return $this;
    }

    /** Allow sorting lists by this field. */
    public function sortable(): static
    {
        $this->sortable = true;

        return $this;
    }

    /** Restrict the field to the given contexts (for example create, update). */
    public function onlyOn(string ...$contexts): static
    {
        $this->contexts = array_values($contexts);

        return $this;
    }

    /** Mark the field as read-only. */
    public function readOnly(): static
    {
        $this->readOnly = true;

        return $this;
    }

    /**
     * Set badge colors keyed by value.
     *
     * @param  array<string, mixed>  $colors
     */
    public function colors(array $colors): static
    {
        $this->colors = $colors;

        return $this;
    }

    /**
     * Set the route location the value links to.
     *
     * @param  array<string, mixed>  $routeLocation
     */
    public function link(array $routeLocation): static
    {
        $this->link = $routeLocation;

        return $this;
    }

    /**
     * Show the field only when the condition holds.
     *
     * @param  array<string, mixed>  $condition
     *
     * @throws \InvalidArgumentException when the condition is malformed
     */
    public function visibleWhen(array $condition): static
    {
        Condition::validate($condition);
        $this->visibleWhen = $condition;

        return $this;
    }

    /**
     * Require the field only when the condition holds.
     *
     * @param  array<string, mixed>  $condition
     *
     * @throws \InvalidArgumentException when the condition is malformed
     */
    public function requiredWhen(array $condition): static
    {
        Condition::validate($condition);
        $this->requiredWhen = $condition;

        return $this;
    }

    /** Set the permission ability needed to use the field. */
    public function permission(string $ability): static
    {
        $this->ability = $ability;

        return $this;
    }

    /**
     * Set explicit option label translation keys keyed by option value.
     *
     * @param  array<string, string>  $labelKeysByValue
     */
    public function options(array $labelKeysByValue): static
    {
        $this->optionLabels = $labelKeysByValue;

        return $this;
    }

    /**
     * Set the list column width in pixels.
     *
     * @throws \InvalidArgumentException when the width is below 1
     */
    public function width(int $px): static
    {
        if ($px < 1) {
            throw new \InvalidArgumentException("Column width must be at least 1 pixel, [{$px}] given.");
        }
        $this->width = $px;

        return $this;
    }

    /** Set the translation key of the "no filter" entry of a list filter. */
    public function filterAllLabel(string $key): static
    {
        $this->filterAllLabel = $key;

        return $this;
    }

    /** List column width in pixels, if set. */
    public function getWidth(): ?int
    {
        return $this->width;
    }

    /** Translation key of the "no filter" entry, if set. */
    public function getFilterAllLabel(): ?string
    {
        return $this->filterAllLabel;
    }

    /** Field name. */
    public function name(): string
    {
        return $this->name;
    }

    /** Field type. */
    public function type(): string
    {
        return $this->type;
    }

    /** Show another record field as a muted second line under this field's value. */
    public function secondary(string $fieldName): static
    {
        $this->secondary = $fieldName;

        return $this;
    }

    /** Label translation key (the field name when none was set). */
    public function labelKey(): string
    {
        return $this->labelKey;
    }

    /** Whether the field is read-only (always true for count). */
    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * Contexts the field is restricted to; an empty array means all contexts.
     *
     * @return array<int, string>
     */
    public function contexts(): array
    {
        return $this->contexts;
    }

    /** Whether the field is available in the given context. */
    public function availableIn(string $context): bool
    {
        return $this->contexts === [] || in_array($context, $this->contexts, true);
    }

    /** Permission ability needed to use the field, if any. */
    public function permissionAbility(): ?string
    {
        return $this->ability;
    }

    /** Whether the field takes part in text search. */
    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    /** Whether the field is offered as a filter. */
    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    /** Whether lists can be sorted by the field. */
    public function isSortable(): bool
    {
        return $this->sortable;
    }

    /**
     * Build Laravel validation rules for a context.
     *
     * @param  array<string, mixed>  $input  submitted values, used by requiredWhen
     * @return array<int, mixed>|null null when the field takes no input in this context
     */
    public function rulesFor(string $context, array $input = []): ?array
    {
        if ($this->readOnly || ! $this->availableIn($context)) {
            return null;
        }

        if ($this->prebuiltRules !== null) {
            return $this->prebuiltRules;
        }

        if ($this->required) {
            $rules = ['required'];
        } elseif ($this->requiredWhen !== null) {
            $rules = [Condition::evaluate($this->requiredWhen, $input) ? 'required' : 'nullable'];
        } else {
            $rules = ['nullable'];
        }

        $base = match ($this->type) {
            'text', 'textarea', 'slug' => 'string',
            'email' => 'email',
            'number' => 'numeric',
            'boolean' => 'boolean',
            'date', 'datetime' => 'date',
            default => null,
        };
        if ($base !== null) {
            $rules[] = $base;
        }

        if ($this->max !== null) {
            $rules[] = 'max:'.$this->max;
        }
        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }
        if ($this->pattern !== null) {
            $rules[] = 'regex:'.$this->pattern;
        }

        if (in_array($this->type, ['enum', 'status'], true)) {
            $rules[] = Rule::in($this->values);
        }

        if ($this->relation !== null) {
            array_push($rules, ...$this->relation->rules());
        }

        if ($this->enumerate !== null) {
            array_push($rules, ...$this->enumerate->rules());
        }

        foreach ($this->extraRules as $extra) {
            $rules[] = $extra;
        }

        return $rules;
    }

    /**
     * Export the UI schema; server-only rules and permissions are omitted.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $rules = [];
        if ($this->prebuiltSchemaRules !== null) {
            $rules = $this->prebuiltSchemaRules;
        } else {
            if ($this->required) {
                $rules['required'] = true;
            }
            if ($this->max !== null) {
                $rules['max'] = $this->max;
            }
            if ($this->min !== null) {
                $rules['min'] = $this->min;
            }
            if ($this->pattern !== null) {
                $rules['pattern'] = $this->pattern;
            }
        }

        $schema = [
            'field' => $this->name,
            'type' => $this->type,
            'label' => $this->labelKey,
            'rules' => $rules,
        ];

        if (in_array($this->type, ['enum', 'status'], true)) {
            $schema['options'] = array_map(
                function ($value) {
                    $option = [
                        'value' => $value,
                        'label' => $this->optionLabels[$value] ?? $this->labelKey.'_'.$value,
                    ];
                    if (isset($this->optionText[$value])) {
                        $option['label_text'] = $this->optionText[$value];
                    }

                    return $option;
                },
                array_values($this->values),
            );
        }
        if ($this->default !== null) {
            $schema['default'] = $this->default;
        }
        if ($this->labelText !== null) {
            $schema['label_text'] = $this->labelText;
        }
        if ($this->helpText !== null) {
            $schema['help_text'] = $this->helpText;
        }
        if ($this->custom) {
            $schema['custom'] = true;
        }
        if ($this->multiple) {
            $schema['multiple'] = true;
        }
        if ($this->listed) {
            $schema['list'] = true;
        }
        if ($this->relation !== null) {
            $schema['relation'] = $this->relation->toSchema();
        }
        if ($this->enumerate !== null) {
            $schema['enumerate'] = $this->enumerate->toSchema();
        }
        if ($this->secondary !== null) {
            $schema['secondary'] = $this->secondary;
        }
        if ($this->rangeStart !== null) {
            $schema['start'] = $this->rangeStart;
            $schema['end'] = $this->rangeEnd;
        }
        if ($this->slugFrom !== null) {
            $schema['from'] = $this->slugFrom;
        }
        if ($this->unit !== null) {
            $schema['unit'] = $this->unit;
        }
        if ($this->fromRecord !== null) {
            $schema['from_record'] = array_filter($this->fromRecord, static fn ($key) => $key !== null);
        }
        if ($this->expiry !== null) {
            $schema['expiry'] = $this->expiry;
        }
        if ($this->dateOnly) {
            $schema['date_only'] = true;
        }
        if ($this->type === 'datetime') {
            $schema['value_format'] = 'YYYY-MM-DD HH:mm:ss';
        }
        if ($this->colors !== null) {
            $schema['colors'] = $this->colors;
        }
        if ($this->link !== null) {
            $schema['link'] = $this->link;
        }
        if ($this->visibleWhen !== null) {
            $schema['visible'] = $this->visibleWhen;
        }
        if ($this->requiredWhen !== null) {
            $schema['required_when'] = $this->requiredWhen;
        }
        if ($this->searchable) {
            $schema['searchable'] = true;
        }
        if ($this->filterable) {
            $schema['filterable'] = true;
        }
        if ($this->sortable) {
            $schema['sortable'] = true;
        }
        if ($this->readOnly) {
            $schema['read_only'] = true;
        }

        return $schema;
    }
}
