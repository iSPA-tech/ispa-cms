<?php

use Cms\Core\Admin\Resources\Field;
use Cms\Core\Admin\Resources\Fields\EnumerateField;

it('creates an enumerate typed field', function () {
    expect(Field::enumerate('status', 'status')->toSchema()['type'])->toBe('enumerate');
});

it('exports the group and options endpoint without closures', function () {
    $schema = Field::enumerate('status', 'semester_status')->toSchema();

    expect($schema['enumerate'])->toBe([
        'endpoint' => 'enumerate',
        'group' => 'semester_status',
        'value_key' => 'enum_key',
        'label_key' => 'name',
        'alias_key' => 'name_alias',
    ]);
    expect(json_encode($schema))->not->toBeFalse();
    expect($schema)->not->toHaveKey('options');
});

it('checks the value exists in the group and handles nullable and required', function () {
    $rules = Field::enumerate('status', 'status')->rulesFor('create');
    expect($rules[0])->toBe('nullable')->and($rules[1])->toBe('string');

    $exists = (string) $rules[2];
    expect($exists)->toStartWith('exists:enumerates,enum_key')
        ->and($exists)->toContain('type')->toContain('status')->toContain('deleted_at');

    expect(Field::enumerate('status', 'status')->required()->rulesFor('create')[0])->toBe('required');
});

it('exposes the group and the endpoint constant', function () {
    expect((new EnumerateField('status'))->group())->toBe('status')
        ->and(EnumerateField::ENDPOINT)->toBe('enumerate');
});

it('keeps other field types free of enumerate config', function () {
    expect(Field::text('name')->toSchema())->not->toHaveKey('enumerate');
});
