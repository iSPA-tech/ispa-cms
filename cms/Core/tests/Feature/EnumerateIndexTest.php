<?php

use Cms\Core\Models\Enumerate;
use Cms\Core\Models\EnumerateType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function makeEnumerateType(string $name, string $key): EnumerateType
{
    return EnumerateType::create([
        'name' => $name,
        'key_name' => $key,
    ]);
}

function makeEnumerate(array $overrides = []): Enumerate
{
    return Enumerate::create(array_merge([
        'name' => 'Enum',
        'name_alias' => 'Alias',
        'enum_key' => 'enum_key_' . random_int(1000, 9999),
        'type' => 'default',
        'type_id' => null,
        'input_type' => 'text',
        'is_filter' => false,
        'status' => true,
    ], $overrides));
}

test('enumerate index filters by type_id', function () {
    $this->withoutMiddleware();

    $typeA = makeEnumerateType('Type A', 'type_a');
    $typeB = makeEnumerateType('Type B', 'type_b');

    makeEnumerate(['type_id' => $typeA->id, 'type' => 'type_a', 'enum_key' => 'alpha']);
    makeEnumerate(['type_id' => $typeB->id, 'type' => 'type_b', 'enum_key' => 'beta']);

    $response = $this->getJson("/api/v2/admin/enumerate?type_id={$typeA->id}&per_page=10")
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['data', 'current_page', 'per_page', 'total'],
        ]);

    $items = $response->json('data.data');

    expect($items)->not->toBeEmpty()
        ->and(collect($items)->pluck('type_id')->unique()->values()->all())
        ->toBe([$typeA->id]);
});

test('enumerate index filters by keyword across key and names', function () {
    $this->withoutMiddleware();

    $type = makeEnumerateType('Type C', 'type_c');

    makeEnumerate([
        'type_id' => $type->id,
        'type' => 'type_c',
        'enum_key' => 'color_primary',
        'name' => 'Primary Color',
        'name_alias' => 'Primary',
    ]);
    makeEnumerate([
        'type_id' => $type->id,
        'type' => 'type_c',
        'enum_key' => 'secondary',
        'name' => 'Secondary',
        'name_alias' => 'Accent',
    ]);

    $response = $this->getJson('/api/v2/admin/enumerate?keyword=Primary&per_page=10')->assertOk();

    $items = collect($response->json('data.data'));

    expect($items)->not->toBeEmpty()
        ->and($items->pluck('enum_key')->all())->toContain('color_primary')
        ->and($items->pluck('enum_key')->all())->not->toContain('secondary');
});

test('enumerate index combines type_id and keyword filters', function () {
    $this->withoutMiddleware();

    $typeA = makeEnumerateType('Type A2', 'type_a2');
    $typeB = makeEnumerateType('Type B2', 'type_b2');

    makeEnumerate([
        'type_id' => $typeA->id,
        'type' => 'type_a2',
        'enum_key' => 'status_active',
        'name' => 'Active',
        'name_alias' => 'Running',
    ]);
    makeEnumerate([
        'type_id' => $typeB->id,
        'type' => 'type_b2',
        // enum_key is unique since fbec0d064 (MySQL 9 FK targets).
        'enum_key' => 'status_active_b',
        'name' => 'Active B',
        'name_alias' => 'Running B',
    ]);

    $response = $this->getJson("/api/v2/admin/enumerate?type_id={$typeA->id}&keyword=Active&per_page=10")
        ->assertOk();

    $items = collect($response->json('data.data'));

    expect($items)->toHaveCount(1)
        ->and($items->first()['type_id'])->toBe($typeA->id);
});
