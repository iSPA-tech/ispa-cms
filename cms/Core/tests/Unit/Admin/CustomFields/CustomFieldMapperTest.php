<?php

use Cms\Core\Admin\CustomFields\CustomFieldMapper;
use Cms\Core\Admin\CustomFields\CustomFieldRules;
use Cms\Core\Admin\CustomFields\CustomFieldType;
use Cms\Core\Models\MetaAttribute;

function customAttribute(CustomFieldType $type, array $overrides = []): MetaAttribute
{
    return (new MetaAttribute)->forceFill(array_merge([
        'name' => 'cf.customers.industry',
        'input_type' => $type->inputType(),
        'is_multiple' => $type->isMultiple(),
        'labels' => ['vi' => 'Ngành', 'en' => 'Industry'],
        'help' => null,
        'rules' => [],
        'is_required' => false,
        'show_in_list' => false,
        'is_searchable' => false,
        'is_filter' => false,
    ], $overrides));
}

$options = [
    ['value' => 'retail', 'labels' => ['vi' => 'Bán lẻ', 'en' => 'Retail']],
    ['value' => 'it', 'labels' => ['vi' => 'Công nghệ', 'en' => 'IT']],
];

it('maps every type to a custom field with its rules', function (CustomFieldType $type, string $fieldType, array $rules, array $options) {
    $attribute = customAttribute($type, ['rules' => $rules + ($options ? ['options' => $options] : []), 'is_required' => true]);
    $field = (new CustomFieldMapper)->toField($attribute);
    $values = array_column($options, 'value');

    $schema = $field->toSchema();
    expect($field->name())->toBe('custom_fields.industry')
        ->and($schema['field'])->toBe('custom_fields.industry')
        ->and($schema['type'])->toBe($fieldType)
        ->and($schema['custom'])->toBeTrue()
        ->and($schema['label'])->toBe('industry')
        ->and($schema['label_text'])->toBe(['vi' => 'Ngành', 'en' => 'Industry'])
        ->and($schema['rules'])->toBe(
            array_diff_key((new CustomFieldRules)->toSchema($type, $rules, true, $values), ['options' => 1])
        )
        ->and($field->rulesFor('create'))->toEqual((new CustomFieldRules)->toLaravel($type, $rules, true, $values));
})->with([
    'text' => [CustomFieldType::Text, 'text', ['min' => 2, 'max' => 40], []],
    'textarea' => [CustomFieldType::Textarea, 'textarea', [], []],
    'integer' => [CustomFieldType::Integer, 'number', ['min' => 1, 'max' => 9], []],
    'boolean' => [CustomFieldType::Boolean, 'boolean', [], []],
    'date' => [CustomFieldType::Date, 'date', ['date_min' => '2026-01-01'], []],
    'select' => [CustomFieldType::Select, 'enum', [], $options],
    'multiselect' => [CustomFieldType::Multiselect, 'enum', ['max' => 2], $options],
]);

it('carries literal option labels and the multiple flag for selects', function () use ($options) {
    $select = (new CustomFieldMapper)->toField(customAttribute(CustomFieldType::Select, ['rules' => ['options' => $options]]))->toSchema();
    expect($select['options'][0])->toBe([
        'value' => 'retail',
        'label' => 'industry_retail',
        'label_text' => ['vi' => 'Bán lẻ', 'en' => 'Retail'],
    ])->and($select)->not->toHaveKey('multiple');

    $multi = (new CustomFieldMapper)->toField(customAttribute(CustomFieldType::Multiselect, ['rules' => ['options' => $options]]))->toSchema();
    expect($multi['multiple'])->toBeTrue();
});

it('maps flags and help text', function () {
    $field = (new CustomFieldMapper)->toField(customAttribute(CustomFieldType::Text, [
        'help' => ['vi' => 'Chọn', 'en' => 'Pick'],
        'show_in_list' => true, 'is_searchable' => true, 'is_filter' => true, 'is_required' => true,
    ]));
    $schema = $field->toSchema();

    expect($schema['list'])->toBeTrue()
        ->and($schema['searchable'])->toBeTrue()
        ->and($schema['filterable'])->toBeTrue()
        ->and($schema['help_text'])->toBe(['vi' => 'Chọn', 'en' => 'Pick'])
        ->and($schema['rules']['required'])->toBeTrue()
        ->and($field->rulesFor('create')[0])->toBe('required');

    $plain = (new CustomFieldMapper)->toField(customAttribute(CustomFieldType::Text))->toSchema();
    expect($plain)->not->toHaveKeys(['list', 'searchable', 'filterable', 'help_text'])
        ->and($plain['rules'])->not->toHaveKey('required');
});

it('falls back from a missing locale to vi, then en, then the field key', function (?array $labels, array $expected) {
    $schema = (new CustomFieldMapper)->toField(customAttribute(CustomFieldType::Text, ['labels' => $labels]))->toSchema();
    expect($schema['label_text'])->toBe($expected);
})->with([
    'en missing' => [['vi' => 'Ngành'], ['vi' => 'Ngành', 'en' => 'Ngành']],
    'vi missing' => [['en' => 'Industry'], ['vi' => 'Industry', 'en' => 'Industry']],
    'blank vi' => [['vi' => ' ', 'en' => 'Industry'], ['vi' => 'Industry', 'en' => 'Industry']],
    'none' => [null, ['vi' => 'industry', 'en' => 'industry']],
]);

it('rejects an attribute of an unknown type', function () {
    (new CustomFieldMapper)->toField(customAttribute(CustomFieldType::Text, ['input_type' => 'weird']));
})->throws(InvalidArgumentException::class);
