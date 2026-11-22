<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources;

use Cms\Core\Admin\CustomFields\CustomFieldMapper;
use Cms\Core\Admin\CustomFields\CustomFieldService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Base class of a declarative admin resource: fields, views, actions and
 * the validation rule contexts derived from them.
 */
abstract class Resource
{
    /** Resource key, for example helpdesk/organizations. */
    abstract public function key(): string;

    /** Permission ability needed to view the resource. */
    abstract public function ability(): string;

    /**
     * Optional split of the resource permission into viewing and managing.
     *
     * Return `['view' => '<ability>', 'manage' => '<ability>']` to let the schema export which of the two the user
     * holds (`abilities: {view, manage}`) and drop the form, create/edit/delete and actions for a user without manage.
     * The default null keeps the single {@see ability()} behaviour and exports nothing extra.
     *
     * @return array{view: string, manage: string}|null
     */
    public function abilities(): ?array
    {
        return null;
    }

    /**
     * All fields of the resource.
     *
     * @return array<int, Field>
     */
    abstract public function fields(): array;

    /**
     * Key of the DataScope scoped resource whose field visibility applies to this schema, or null for none.
     *
     * Fields the user may not see there (`DataScope::hiddenFields`) are dropped from the built schema.
     */
    public function scopedResource(): ?string
    {
        return null;
    }

    /**
     * Whether the resource accepts custom fields (admin-defined fields stored as meta attributes).
     *
     * Opt-in: the schema builder only adds custom fields to a resource that returns true here.
     */
    public function supportsCustomFields(): bool
    {
        return false;
    }

    /** List view, if the resource has one. */
    public function list(): ?ListView
    {
        return null;
    }

    /** Form view, if the resource has one. */
    public function form(): ?FormView
    {
        return null;
    }

    /** Detail view, if the resource has one. */
    public function detail(): ?DetailView
    {
        return null;
    }

    /**
     * Resource actions.
     *
     * @return array<int, Action>
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Extra named rule contexts, each a list of fields.
     *
     * @return array<string, array<int, Field>>
     */
    public function ruleSets(): array
    {
        return [];
    }

    /**
     * Look up a field by name.
     *
     * @throws InvalidArgumentException when the field is unknown
     */
    final public function field(string $name): Field
    {
        foreach ($this->fields() as $field) {
            if ($field->name() === $name) {
                return $field;
            }
        }

        throw new InvalidArgumentException("Unknown field [{$name}] on resource [{$this->key()}].");
    }

    /**
     * Build validation rules for a context: create/update, an action name or a rule set key.
     *
     * @param  array<string, mixed>  $input  submitted values, used by requiredWhen
     * @return array<string, array<int, mixed>> rules keyed by field name
     *
     * @throws InvalidArgumentException when the context is unknown
     */
    final public function rules(string $context, array $input = []): array
    {
        $fields = null;

        if (in_array($context, ['create', 'update'], true)) {
            $names = $this->form()?->fieldsFor($context);
            if ($names !== null) {
                $fields = array_map(fn (string $name) => $this->field($name), $names);
            }
        } else {
            foreach ($this->actions() as $action) {
                if ($action->name() === $context) {
                    $fields = $action->getFields();
                    break;
                }
            }
            $fields ??= $this->ruleSets()[$context] ?? null;
        }

        if ($fields === null) {
            throw new InvalidArgumentException("Unknown rule context [{$context}] on resource [{$this->key()}].");
        }

        $rules = [];
        foreach ($fields as $field) {
            $fieldRules = $field->rulesFor($context, $input);
            if ($fieldRules !== null) {
                $rules[$field->name()] = $fieldRules;
            }
        }

        if (in_array($context, ['create', 'update'], true) && $this->supportsCustomFields()) {
            $rules += $this->customFieldRules($context, $input);
        }

        return $rules;
    }

    /**
     * Validation rules for the custom values of an opted-in resource.
     *
     * Adds the container rule, one rule set per active custom field (prefixed with `sometimes` on update so a
     * partial update may omit a key) and a `prohibited` rule for every submitted key that is not an active field
     * (unknown or archived). A broken definition is logged and skipped, like the schema stage does.
     *
     * @param  array<string, mixed>  $input  submitted values
     * @return array<string, array<int, mixed>> rules keyed by `custom_fields` or `custom_fields.<key>`
     */
    private function customFieldRules(string $context, array $input): array
    {
        $mapper = new CustomFieldMapper;
        $rules = ['custom_fields' => ['sometimes', 'array']];
        $active = [];

        foreach (app(CustomFieldService::class)->fieldsFor($this->key()) as $attribute) {
            try {
                $field = $mapper->toField($attribute);
            } catch (InvalidArgumentException $e) {
                Log::warning("Custom field [{$attribute->name}] of [{$this->key()}] skipped: {$e->getMessage()}");

                continue;
            }

            $active[$field->name()] = true;
            $fieldRules = $field->rulesFor($context, $input);
            if ($fieldRules !== null) {
                $rules[$field->name()] = $context === 'update' ? ['sometimes', ...$fieldRules] : $fieldRules;
            }
        }

        $submitted = $input['custom_fields'] ?? null;
        if (is_array($submitted)) {
            foreach (array_keys($submitted) as $key) {
                $name = 'custom_fields.'.$key;
                if (! isset($active[$name])) {
                    $rules[$name] = ['prohibited'];
                }
            }
        }

        return $rules;
    }
}
