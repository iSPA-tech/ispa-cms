<?php

use Cms\Core\SavedViews\SavedViewStateValidator;
use Illuminate\Validation\ValidationException;

uses(\Cms\Core\Tests\TestCase::class);

/**
 * Run the validator and report whether it rejected the state.
 */
function savedViewStateRejected(mixed $state): bool
{
    try {
        (new SavedViewStateValidator())->validate($state);
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['state']);

        return true;
    }

    return false;
}

it('accepts filter state made of strings, ints and lists of them', function () {
    $state = ['q' => 'acme', 'page' => 2, 'status' => ['active', 'suspended'], 'tags' => [1, 2, 'x']];

    expect(savedViewStateRejected($state))->toBeFalse()
        ->and((new SavedViewStateValidator())->validate($state))->toBe($state);
});

it('accepts an empty state', function () {
    expect(savedViewStateRejected([]))->toBeFalse();
});

it('rejects a state that is not an object', function (mixed $state) {
    expect(savedViewStateRejected($state))->toBeTrue();
})->with([
    'string' => ['q=acme'],
    'int' => [5],
    'null' => [null],
    'list' => [['a', 'b']],
]);

it('rejects unsafe or malformed key names', function (string $key) {
    expect(savedViewStateRejected([$key => 'x']))->toBeTrue();
})->with([
    'proto' => ['__proto__'],
    'double underscore prefix' => ['__x'],
    'constructor' => ['constructor'],
    'prototype' => ['prototype'],
    'dot' => ['a.b'],
    'upper case' => ['Status'],
    'dash' => ['created-via'],
    'space' => ['a b'],
    'too long' => [str_repeat('a', 41)],
    'empty' => [''],
]);

it('rejects nested objects, nested lists and non-scalar values', function (mixed $value) {
    expect(savedViewStateRejected(['f' => $value]))->toBeTrue();
})->with([
    'nested object' => [['a' => 'b']],
    'nested list' => [[['a']]],
    'list with object' => [[['a' => 1]]],
    'float' => [1.5],
    'bool' => [true],
    'null' => [null],
    'list with bool' => [[true]],
    'list with null' => [[null]],
]);

it('rejects over-long strings and over-long lists', function () {
    expect(savedViewStateRejected(['q' => str_repeat('a', 201)]))->toBeTrue()
        ->and(savedViewStateRejected(['q' => str_repeat('a', 200)]))->toBeFalse()
        ->and(savedViewStateRejected(['l' => [str_repeat('a', 201)]]))->toBeTrue()
        ->and(savedViewStateRejected(['l' => range(1, 21)]))->toBeTrue()
        ->and(savedViewStateRejected(['l' => range(1, 20)]))->toBeFalse();
});

it('rejects more than 20 keys', function () {
    $state = [];
    foreach (range(1, 21) as $i) {
        $state['k' . $i] = 'v';
    }

    expect(savedViewStateRejected($state))->toBeTrue()
        ->and(savedViewStateRejected(array_slice($state, 0, 20, true)))->toBeFalse();
});

it('rejects a state whose JSON is larger than 2048 bytes', function () {
    $state = [];
    foreach (range(1, 11) as $i) {
        $state['k' . $i] = str_repeat('a', 200);
    }

    expect(strlen(json_encode($state)))->toBeGreaterThan(2048)
        ->and(savedViewStateRejected($state))->toBeTrue();
});
