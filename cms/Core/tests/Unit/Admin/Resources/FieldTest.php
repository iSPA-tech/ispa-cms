<?php

use Cms\Core\Admin\Resources\Field;

it('builds rules for text and email fields', function () {
    expect(Field::text('name')->required()->max(191)->rulesFor('create'))
        ->toBe(['required', 'string', 'max:191']);
    expect(Field::email('owner_email')->required()->max(191)->rulesFor('create'))
        ->toBe(['required', 'email', 'max:191']);
    expect(Field::text('note')->rulesFor('create'))->toBe(['nullable', 'string']);
});

it('builds Rule::in for enum and status fields', function () {
    $rules = Field::enum('status', ['active', 'suspended'])->required()->rulesFor('create');

    expect($rules)->toHaveCount(2)
        ->and($rules[0])->toBe('required')
        ->and((string) $rules[1])->toBe('in:"active","suspended"');

    $status = Field::status('state', ['a'])->rulesFor('update');
    expect($status[0])->toBe('nullable')
        ->and((string) $status[1])->toBe('in:"a"');
});

it('evaluates requiredWhen against the input', function () {
    $f = Field::text('reason')->requiredWhen(['field' => 'status', 'op' => 'eq', 'value' => 'suspended']);

    expect($f->rulesFor('create', ['status' => 'suspended']))->toBe(['required', 'string']);
    expect($f->rulesFor('create', ['status' => 'active']))->toBe(['nullable', 'string']);
    expect($f->rulesFor('create'))->toBe(['nullable', 'string']);
});

it('lets required() win over requiredWhen', function () {
    $f = Field::text('reason')->required()->requiredWhen(['field' => 'a', 'op' => 'empty']);
    expect($f->rulesFor('create', ['a' => 'x']))->toBe(['required', 'string']);
});

it('honours onlyOn contexts', function () {
    $f = Field::text('slug')->onlyOn('create');

    expect($f->rulesFor('update'))->toBeNull()
        ->and($f->rulesFor('create'))->toBe(['nullable', 'string'])
        ->and($f->availableIn('update'))->toBeFalse()
        ->and($f->availableIn('create'))->toBeTrue()
        ->and($f->contexts())->toBe(['create'])
        ->and(Field::text('x')->contexts())->toBe([])
        ->and(Field::text('x')->availableIn('anything'))->toBeTrue();
});

it('returns null rules for read-only and count fields', function () {
    expect(Field::text('a')->readOnly()->rulesFor('create'))->toBeNull();
    expect(Field::count('n')->rulesFor('create'))->toBeNull();
    expect(Field::count('n')->isReadOnly())->toBeTrue();
    expect(Field::text('a')->isReadOnly())->toBeFalse();
    expect(Field::text('a')->readOnly()->isReadOnly())->toBeTrue();
});

it('orders min, max, pattern and extra rules', function () {
    $f = Field::text('code')->required()->rule('unique:t,c')->pattern('/^[a-z]+$/')->min(2)->max(9)->rule('alpha');

    expect($f->rulesFor('create'))->toBe([
        'required', 'string', 'max:9', 'min:2', 'regex:/^[a-z]+$/', 'unique:t,c', 'alpha',
    ]);
});

it('uses the right base rule per type', function (string $type, string $base) {
    expect(Field::$type('f')->rulesFor('create'))->toBe(['nullable', $base]);
})->with([
    ['text', 'string'],
    ['textarea', 'string'],
    ['email', 'email'],
    ['number', 'numeric'],
    ['boolean', 'boolean'],
    ['date', 'date'],
    ['datetime', 'date'],
]);

it('exposes simple getters', function () {
    $f = Field::text('name')->label('text.name')->permission('x.edit')->searchable()->filterable()->sortable();

    expect($f->name())->toBe('name')
        ->and($f->type())->toBe('text')
        ->and($f->labelKey())->toBe('text.name')
        ->and($f->permissionAbility())->toBe('x.edit')
        ->and($f->isSearchable())->toBeTrue()
        ->and($f->isFilterable())->toBeTrue()
        ->and($f->isSortable())->toBeTrue()
        ->and(Field::text('b')->permissionAbility())->toBeNull()
        ->and(Field::text('b')->isSearchable())->toBeFalse()
        ->and(Field::text('b')->labelKey())->toBe('b');
});

it('exports a minimal schema', function () {
    expect(Field::text('name')->toSchema())->toBe([
        'field' => 'name', 'type' => 'text', 'label' => 'name', 'rules' => [],
    ]);
});

it('exports rules in the schema without server-only data', function () {
    $schema = Field::text('name')->label('text.name')->required()->max(191)->min(2)->pattern('/x/')
        ->rule('unique:t,c')->permission('a.b')->toSchema();

    expect($schema)->toBe([
        'field' => 'name',
        'type' => 'text',
        'label' => 'text.name',
        'rules' => ['required' => true, 'max' => 191, 'min' => 2, 'pattern' => '/x/'],
    ]);
});

it('exports enum options with default and explicit labels', function () {
    $schema = Field::enum('status', ['active', 'suspended'])
        ->label('text.status')
        ->options(['suspended' => 'text.custom_suspended'])
        ->toSchema();

    expect($schema['options'])->toBe([
        ['value' => 'active', 'label' => 'text.status_active'],
        ['value' => 'suspended', 'label' => 'text.custom_suspended'],
    ]);

    expect(Field::status('s', ['a'])->toSchema()['options'])->toBe([
        ['value' => 'a', 'label' => 's_a'],
    ]);
});

it('exports colors, link, conditions and flags', function () {
    $visible = ['field' => 'a', 'op' => 'not_empty'];
    $reqWhen = ['field' => 'b', 'op' => 'eq', 'value' => 1];

    $schema = Field::status('status', ['on'])
        ->colors(['on' => 'green'])
        ->link(['name' => 'x', 'params' => ['id' => 1]])
        ->visibleWhen($visible)
        ->requiredWhen($reqWhen)
        ->searchable()->filterable()->sortable()->readOnly()
        ->toSchema();

    expect($schema)->toBe([
        'field' => 'status',
        'type' => 'status',
        'label' => 'status',
        'rules' => [],
        'options' => [['value' => 'on', 'label' => 'status_on']],
        'colors' => ['on' => 'green'],
        'link' => ['name' => 'x', 'params' => ['id' => 1]],
        'visible' => $visible,
        'required_when' => $reqWhen,
        'searchable' => true,
        'filterable' => true,
        'sortable' => true,
        'read_only' => true,
    ]);
});

it('exports count as read-only', function () {
    expect(Field::count('items')->toSchema())->toBe([
        'field' => 'items', 'type' => 'count', 'label' => 'items', 'rules' => [], 'read_only' => true,
    ]);
});

it('fails fast on malformed conditions', function () {
    expect(fn () => Field::text('a')->visibleWhen(['op' => 'eq']))->toThrow(InvalidArgumentException::class);
    expect(fn () => Field::text('a')->requiredWhen(['field' => 'a', 'op' => 'bogus']))->toThrow(InvalidArgumentException::class);
});

it('carries width and filter all label only when set', function () {
    $plain = Field::text('a');
    expect($plain->getWidth())->toBeNull()
        ->and($plain->getFilterAllLabel())->toBeNull()
        ->and($plain->toSchema())->not->toHaveKeys(['width', 'allLabel']);

    $f = Field::enum('s', ['a'])->width(120)->filterAllLabel('x.all');
    expect($f->getWidth())->toBe(120)
        ->and($f->getFilterAllLabel())->toBe('x.all');
});

it('rejects a width below 1', function () {
    Field::text('a')->width(0);
})->throws(InvalidArgumentException::class);

it('exports the secondary field only when set', function () {
    expect(Field::text('name')->toSchema())->not->toHaveKey('secondary');

    $f = Field::text('name')->secondary('code');
    expect($f->toSchema()['secondary'])->toBe('code')
        ->and($f->rulesFor('create'))->toBe(['nullable', 'string']);
});

it('builds a display-only date range column', function () {
    $f = Field::dateRange('period', 'starts_on', 'ends_on');
    $schema = $f->toSchema();

    expect($f->type())->toBe('date_range')
        ->and($f->isReadOnly())->toBeTrue()
        ->and($f->rulesFor('create'))->toBeNull()
        ->and($f->rulesFor('update'))->toBeNull()
        ->and($schema['type'])->toBe('date_range')
        ->and($schema['start'])->toBe('starts_on')
        ->and($schema['end'])->toBe('ends_on')
        ->and($schema['read_only'])->toBeTrue();
});

it('does not export date range keys on other fields', function () {
    expect(Field::date('d')->toSchema())->not->toHaveKeys(['start', 'end']);
});

it('emits custom-field schema keys only when set', function () {
    $plain = Field::text('name')->toSchema();
    expect($plain)->not->toHaveKeys(['label_text', 'help_text', 'custom', 'multiple', 'list']);

    $custom = Field::text('custom_fields.x')->labelText(['vi' => 'A', 'en' => 'B'])->custom()->toSchema();
    expect($custom['label_text'])->toBe(['vi' => 'A', 'en' => 'B'])
        ->and($custom['custom'])->toBeTrue();
});

it('uses prebuilt rules instead of generated ones', function () {
    $f = Field::text('custom_fields.x')->prebuiltRules(['nullable', 'string', 'max:5'], ['max' => 5]);

    expect($f->rulesFor('create'))->toBe(['nullable', 'string', 'max:5'])
        ->and($f->toSchema()['rules'])->toBe(['max' => 5])
        ->and($f->readOnly()->rulesFor('create'))->toBeNull();
});

it('exports the value format of datetime fields only', function () {
    expect(Field::datetime('at')->toSchema()['value_format'])->toBe('YYYY-MM-DD HH:mm:ss')
        ->and(Field::date('d')->toSchema())->not->toHaveKey('value_format');
});
