<?php

use Cms\Core\Admin\Resources\Field;

it('exports the declared default in the schema for every field type', function () {
    expect(Field::text('a')->default('x')->toSchema()['default'])->toBe('x');
    expect(Field::number('b')->default(0)->toSchema()['default'])->toBe(0);
    expect(Field::number('b')->default(2.5)->toSchema()['default'])->toBe(2.5);
    expect(Field::boolean('c')->default(false)->toSchema()['default'])->toBeFalse();
    expect(Field::boolean('c')->default(true)->toSchema()['default'])->toBeTrue();
    expect(Field::enum('d', ['one', 'two'])->default('two')->toSchema()['default'])->toBe('two');
    expect(Field::status('e', ['draft', 'published'])->default('draft')->toSchema()['default'])->toBe('draft');
});

it('omits the default key when no default is declared', function () {
    expect(Field::text('a')->toSchema())->not->toHaveKey('default');
    expect(Field::boolean('c')->toSchema())->not->toHaveKey('default');
    expect(Field::status('e', ['draft'])->toSchema())->not->toHaveKey('default');
});
