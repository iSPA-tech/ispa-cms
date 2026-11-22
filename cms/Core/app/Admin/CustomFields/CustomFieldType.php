<?php

declare(strict_types=1);

namespace Cms\Core\Admin\CustomFields;

/**
 * Closed set of custom field types an operator can pick.
 *
 * Each type maps onto the existing Core meta attribute storage (`meta_attributes.type`, `input_type`, `is_multiple`)
 * and declares which validation rule names (see {@see CustomFieldRules}) it accepts.
 */
enum CustomFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Date = 'date';
    case Select = 'select';
    case Multiselect = 'multiselect';

    /**
     * MetaAttribute `type` (value storage table) used for this field type.
     */
    public function metaType(): string
    {
        return match ($this) {
            self::Text, self::Select, self::Multiselect => 'string',
            self::Textarea => 'text',
            self::Integer => 'bigInt',
            self::Boolean => 'boolean',
            self::Date => 'date',
        };
    }

    /**
     * MetaAttribute `input_type` hint for the admin form control.
     */
    public function inputType(): string
    {
        return match ($this) {
            self::Text => 'text',
            self::Textarea => 'textarea',
            self::Integer => 'number',
            self::Boolean => 'checkbox',
            self::Date => 'date',
            self::Select, self::Multiselect => 'select',
        };
    }

    /**
     * MetaAttribute `is_multiple` flag: only a multiselect stores several values.
     */
    public function isMultiple(): bool
    {
        return $this === self::Multiselect;
    }

    /**
     * Rule names an operator may set for this type; any other name is rejected.
     *
     * @return list<string>
     */
    public function allowedRules(): array
    {
        return match ($this) {
            self::Text => ['min', 'max', 'pattern'],
            self::Textarea, self::Integer => ['min', 'max'],
            self::Date => ['date_min', 'date_max'],
            self::Multiselect => ['max'],
            self::Boolean, self::Select => [],
        };
    }
}
