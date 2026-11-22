<?php

declare(strict_types=1);

namespace Cms\Core\Admin\CustomFields;

use Cms\Core\Admin\Resources\Field;
use Cms\Core\Models\MetaAttribute;

/**
 * Turns a custom-field {@see MetaAttribute} into the resource {@see Field} a developer would write by hand.
 *
 * The field is named `custom_fields.<key>`, flagged `custom`, carries literal per-locale labels (vi, en) and uses the
 * rules from {@see CustomFieldRules}, so the schema renderer and the validation path need no special case.
 */
final class CustomFieldMapper
{
    /** Locales a literal label is resolved for. */
    private const LOCALES = ['vi', 'en'];

    /** Create the mapper. */
    public function __construct(private readonly CustomFieldRules $rules = new CustomFieldRules) {}

    /**
     * Build the resource field of a custom field.
     *
     * @throws \InvalidArgumentException when the attribute has no known custom field type or an invalid definition
     */
    public function toField(MetaAttribute $attribute): Field
    {
        $type = $this->typeOf($attribute);
        $key = $this->keyOf($attribute);
        $name = 'custom_fields.'.$key;

        $field = match ($type) {
            CustomFieldType::Text => Field::text($name),
            CustomFieldType::Textarea => Field::textarea($name),
            CustomFieldType::Integer => Field::number($name),
            CustomFieldType::Boolean => Field::boolean($name),
            CustomFieldType::Date => Field::date($name),
            CustomFieldType::Select, CustomFieldType::Multiselect => Field::enum($name, $this->optionValues($attribute)),
        };

        $rules = (array) ($attribute->rules ?? []);
        $options = $this->optionValues($attribute);
        $required = (bool) $attribute->is_required;
        unset($rules['options']);

        $schemaRules = $this->rules->toSchema($type, $rules, $required, $options);
        unset($schemaRules['options']);
        if (! $required) {
            unset($schemaRules['required']);
        }

        $field->custom()
            ->label($key)
            ->labelText($this->localized($attribute->labels, $key))
            ->prebuiltRules($this->rules->toLaravel($type, $rules, $required, $options), $schemaRules);

        if ($required) {
            $field->required();
        }
        if (is_array($attribute->help) && $this->localized($attribute->help, '') !== ['vi' => '', 'en' => '']) {
            $field->helpText($this->localized($attribute->help, ''));
        }
        if ($type === CustomFieldType::Select || $type === CustomFieldType::Multiselect) {
            $field->optionText($this->optionLabels($attribute, $key));
        }
        if ($type === CustomFieldType::Multiselect) {
            $field->multiple();
        }
        if ($attribute->show_in_list) {
            $field->listed();
        }
        if ($attribute->is_searchable) {
            $field->searchable();
        }
        if ($attribute->is_filter) {
            $field->filterable();
        }

        return $field;
    }

    /** Custom field type of a stored attribute, from its input type and multiple flag. */
    private function typeOf(MetaAttribute $attribute): CustomFieldType
    {
        foreach (CustomFieldType::cases() as $case) {
            if ($case->inputType() === $attribute->input_type && $case->isMultiple() === (bool) $attribute->is_multiple) {
                return $case;
            }
        }

        throw new \InvalidArgumentException("Attribute [{$attribute->name}] is not a custom field of a known type.");
    }

    /** Field key recovered from the attribute name (the part after the last dot). */
    private function keyOf(MetaAttribute $attribute): string
    {
        $name = (string) $attribute->name;

        return substr($name, (int) strrpos($name, '.') + 1);
    }

    /**
     * Option values of a select or multiselect field, in stored order.
     *
     * @return list<string>
     */
    private function optionValues(MetaAttribute $attribute): array
    {
        $values = [];
        foreach ((array) ($attribute->rules['options'] ?? []) as $option) {
            if (is_array($option) && isset($option['value'])) {
                $values[] = (string) $option['value'];
            }
        }

        return $values;
    }

    /**
     * Literal option labels keyed by option value.
     *
     * @return array<string, array<string, string>>
     */
    private function optionLabels(MetaAttribute $attribute, string $fallback): array
    {
        $labels = [];
        foreach ((array) ($attribute->rules['options'] ?? []) as $option) {
            if (is_array($option) && isset($option['value'])) {
                $value = (string) $option['value'];
                $labels[$value] = $this->localized($option['labels'] ?? null, $value);
            }
        }

        return $labels;
    }

    /**
     * Resolve a label for every locale: own text, else vi, else en, else the fallback.
     *
     * @return array<string, string>
     */
    private function localized(mixed $texts, string $fallback): array
    {
        $texts = is_array($texts) ? $texts : [];
        $pick = static fn (string $locale): string => isset($texts[$locale]) && is_string($texts[$locale]) && trim($texts[$locale]) !== ''
            ? $texts[$locale]
            : '';

        $resolved = [];
        foreach (self::LOCALES as $locale) {
            $text = $pick($locale);
            if ($text === '') {
                $text = $pick('vi');
            }
            if ($text === '') {
                $text = $pick('en');
            }
            $resolved[$locale] = $text !== '' ? $text : $fallback;
        }

        return $resolved;
    }
}
