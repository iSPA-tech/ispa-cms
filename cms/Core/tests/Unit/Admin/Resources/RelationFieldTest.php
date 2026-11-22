<?php

use Cms\Core\Admin\Resources\Field;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;

/** Build a standalone validator (unit tests do not boot the framework). */
function relationValidator(array $data, array $rules): Validator
{
    return new Validator(new Translator(new ArrayLoader, 'en'), $data, $rules);
}

it('exports lookup config without the exists closure', function () {
    $field = Field::relation('owner_uuid', 'helpdesk/users', 'full_name')->relationExists(fn (string $v) => true);
    $schema = $field->toSchema();

    expect($schema['type'])->toBe('relation')
        ->and($schema['relation'])->toBe([
            'endpoint' => 'helpdesk/users',
            'label_key' => 'full_name',
            'value_key' => 'uuid',
            'page_size' => 20,
            'search_param' => 'search',
        ]);
    expect(json_encode($schema))->not->toBeFalse();
});

it('has only nullable or required plus uuid without an exists check', function () {
    expect(Field::relation('o', 'x')->rulesFor('create'))->toBe(['nullable', 'uuid']);
    expect(Field::relation('o', 'x')->required()->rulesFor('create'))->toBe(['required', 'uuid']);
});

it('runs the exists callable during validation', function () {
    $uuid = '5f1d6b0e-1c2a-4c63-9d57-0a3f4b9d7e11';
    $seen = [];
    $field = Field::relation('o', 'x')->relationExists(function (string $v) use (&$seen, $uuid) {
        $seen[] = $v;

        return $v === $uuid;
    });

    $ok = relationValidator(['o' => $uuid], ['o' => $field->rulesFor('create')]);
    expect($ok->passes())->toBeTrue();

    $other = '6f1d6b0e-1c2a-4c63-9d57-0a3f4b9d7e11';
    $bad = relationValidator(['o' => $other], ['o' => $field->rulesFor('create')]);
    expect($bad->fails())->toBeTrue()->and($bad->errors()->has('o'))->toBeTrue();
    expect($seen)->toBe([$uuid, $other]);

    expect(relationValidator(['o' => 'nope'], ['o' => $field->rulesFor('create')])->fails())->toBeTrue();
});

it('exports the unit on number fields', function () {
    expect(Field::number('weight')->unit('text.unit.kg')->toSchema()['unit'])->toBe('text.unit.kg');
    expect(Field::number('weight')->toSchema())->not->toHaveKey('unit');
});

it('rejects relationExists on non relation fields', function () {
    Field::text('a')->relationExists(fn () => true);
})->throws(LogicException::class);

it('exports a custom search parameter', function () {
    expect(Field::relation('o', 'x')->searchParam('q')->toSchema()['relation']['search_param'])->toBe('q');
});

it('keeps the exists check when the search parameter is set', function () {
    $uuid = '5f1d6b0e-1c2a-4c63-9d57-0a3f4b9d7e11';
    $field = Field::relation('o', 'x')->relationExists(fn (string $v) => false)->searchParam('q');

    expect(relationValidator(['o' => $uuid], ['o' => $field->rulesFor('create')])->fails())->toBeTrue();
    expect(json_encode($field->toSchema()))->not->toBeFalse();
});

it('rejects searchParam on non relation fields', function () {
    Field::text('a')->searchParam('q');
})->throws(LogicException::class);

it('exports endpoints chosen by another field', function () {
    $field = Field::relation('target_uuid', 'default/lookup')->endpointsBy('scope_type', ['book' => 'a/books/lookup', 'classification' => 'a/classes/lookup']);
    $relation = $field->toSchema()['relation'];

    expect($relation['endpoint'])->toBe('default/lookup')
        ->and($relation['endpoints_by'])->toBe(['field' => 'scope_type', 'map' => ['book' => 'a/books/lookup', 'classification' => 'a/classes/lookup']]);
});

it('does not export endpoints_by by default and keeps depends_on alongside', function () {
    expect(Field::relation('o', 'x')->toSchema()['relation'])->not->toHaveKey('endpoints_by');
    $relation = Field::relation('o', 'x')->dependsOn('p', 'q')->endpointsBy('t', ['a' => 'e'])->searchParam('s')->toSchema()['relation'];
    expect($relation)->toHaveKeys(['depends_on', 'endpoints_by']);
    $again = Field::relation('o', 'x')->endpointsBy('t', ['a' => 'e'])->dependsOn('p', 'q')->toSchema()['relation'];
    expect($again['endpoints_by'])->toBe(['field' => 't', 'map' => ['a' => 'e']]);
});

it('rejects invalid endpointsBy input', function (string $field, array $map) {
    Field::relation('o', 'x')->endpointsBy($field, $map);
})->with([
    'empty field' => ['', ['a' => 'e']],
    'empty map' => ['t', []],
    'list keys' => ['t', ['e1']],
    'non string endpoint' => ['t', ['a' => 5]],
    'empty endpoint' => ['t', ['a' => '']],
])->throws(InvalidArgumentException::class);

it('rejects endpointsBy on non relation fields', function () {
    Field::text('a')->endpointsBy('t', ['a' => 'e']);
})->throws(LogicException::class);

it('keeps the relation immutable when endpointsBy is applied', function () {
    $base = new Cms\Core\Admin\Resources\Fields\RelationField('x');
    $with = $base->endpointsBy('t', ['a' => 'e']);

    expect($base->toSchema())->not->toHaveKey('endpoints_by')
        ->and($with)->not->toBe($base)
        ->and($with->toSchema())->toHaveKey('endpoints_by');
});
