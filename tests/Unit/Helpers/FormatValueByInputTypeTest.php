<?php

use Tests\TestCase;

uses(TestCase::class);

test('string setting values remain strings', function (): void {
    expect(formatValueByInputType('Acme Events', 'text'))->toBe('Acme Events');
});

test('structured values are serialized for string setting types', function (string $type): void {
    expect(formatValueByInputType(['label' => 'Acme Events'], $type))
        ->toBe('{"label":"Acme Events"}');
})->with(['text', 'textarea', 'password', 'select']);
