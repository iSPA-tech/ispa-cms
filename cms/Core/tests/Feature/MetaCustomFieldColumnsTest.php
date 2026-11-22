<?php

uses(\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaClass;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! Schema::hasTable('meta_attributes')) {
        $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
    }
});

/**
 * Load a fresh instance of the custom field columns migration.
 *
 * @return \Illuminate\Database\Migrations\Migration
 */
function metaCustomFieldMigration(): \Illuminate\Database\Migrations\Migration
{
    return require base_path('cms/Core/database/migrations/2026_11_07_090000_add_custom_field_columns_to_meta_tables.php');
}

/**
 * The columns the migration adds, keyed by table.
 *
 * @return array<string, array<int, string>>
 */
function metaCustomFieldNewColumns(): array
{
    return [
        CoreTableConstant::META_ATTRIBUTES => [
            'uuid', 'labels', 'help', 'rules', 'is_custom', 'show_in_list', 'is_searchable', 'archived_at',
        ],
        CoreTableConstant::META_CLASSES => ['resource_key'],
    ];
}

/**
 * Create a legacy-shaped attribute (old fields only).
 *
 * @param array<string, mixed> $overrides
 * @return MetaAttribute
 */
function metaCustomFieldAttribute(array $overrides = []): MetaAttribute
{
    return MetaAttribute::create(array_merge([
        'name' => 'field_' . uniqid(),
        'label' => 'Field',
        'type' => 'string',
        'input_type' => 'text',
        'is_filter' => false,
        'is_enabled' => true,
        'is_required' => false,
        'is_multiple' => false,
    ], $overrides));
}

it('adds every custom field column to both meta tables', function () {
    foreach (metaCustomFieldNewColumns() as $table => $columns) {
        foreach ($columns as $column) {
            expect(Schema::hasColumn($table, $column))->toBeTrue("{$table}.{$column} missing");
        }
    }

    expect(Schema::hasIndex(CoreTableConstant::META_ATTRIBUTES, 'uq_meta_attributes_uuid'))->toBeTrue()
        ->and(Schema::hasIndex(CoreTableConstant::META_ATTRIBUTES, 'ix_meta_attributes_is_custom'))->toBeTrue()
        ->and(Schema::hasIndex(CoreTableConstant::META_CLASSES, 'uq_meta_classes_resource_key'))->toBeTrue();
});

it('backfills a uuid on existing attributes without touching any other column', function () {
    $table = CoreTableConstant::META_ATTRIBUTES;
    $id = DB::table($table)->insertGetId([
        'name' => 'legacy_attr',
        'label' => 'Legacy',
        'type' => 'string',
        'input_type' => 'select',
        'is_filter' => 1,
        'is_enabled' => 1,
        'is_required' => 0,
        'is_multiple' => 1,
        'value_kind' => 'enum',
        'created_at' => '2024-01-02 03:04:05',
        'updated_at' => '2024-02-03 04:05:06',
    ]);
    $trashedId = DB::table($table)->insertGetId([
        'name' => 'legacy_trashed',
        'label' => 'Trashed',
        'type' => 'string',
        'created_at' => '2024-01-02 03:04:05',
        'updated_at' => '2024-02-03 04:05:06',
        'deleted_at' => '2024-03-04 05:06:07',
    ]);
    DB::table($table)->whereIn('id', [$id, $trashedId])->update(['uuid' => null]);

    $before = (array) DB::table($table)->where('id', $id)->first();
    $trashedBefore = (array) DB::table($table)->where('id', $trashedId)->first();

    metaCustomFieldMigration()->up();

    $after = (array) DB::table($table)->where('id', $id)->first();
    $trashedAfter = (array) DB::table($table)->where('id', $trashedId)->first();

    expect(Str::isUuid($after['uuid']))->toBeTrue()
        ->and(Str::isUuid($trashedAfter['uuid']))->toBeTrue()
        ->and($after['uuid'])->not->toBe($trashedAfter['uuid']);

    unset($before['uuid'], $after['uuid'], $trashedBefore['uuid'], $trashedAfter['uuid']);

    expect($after)->toBe($before)
        ->and($trashedAfter)->toBe($trashedBefore);
});

it('is a no-op when up() runs again', function () {
    $migration = metaCustomFieldMigration();

    $migration->up();
    $migration->up();

    foreach (metaCustomFieldNewColumns() as $table => $columns) {
        foreach ($columns as $column) {
            expect(Schema::hasColumn($table, $column))->toBeTrue();
        }
    }
});

it('drops only the new columns on down()', function () {
    $migration = metaCustomFieldMigration();

    $migration->down();

    foreach (metaCustomFieldNewColumns() as $table => $columns) {
        foreach ($columns as $column) {
            expect(Schema::hasColumn($table, $column))->toBeFalse("{$table}.{$column} still present");
        }
    }

    foreach (['name', 'label', 'value_kind', 'unit', 'is_enabled', 'deleted_at'] as $column) {
        expect(Schema::hasColumn(CoreTableConstant::META_ATTRIBUTES, $column))->toBeTrue();
    }
    foreach (['name', 'target', 'type', 'parent_id'] as $column) {
        expect(Schema::hasColumn(CoreTableConstant::META_CLASSES, $column))->toBeTrue();
    }

    // down() twice must also be safe; then restore the schema.
    $migration->down();
    $migration->up();

    expect(Schema::hasColumn(CoreTableConstant::META_ATTRIBUTES, 'uuid'))->toBeTrue()
        ->and(Schema::hasColumn(CoreTableConstant::META_CLASSES, 'resource_key'))->toBeTrue();
});

it('rejects two meta classes sharing a resource_key but allows many nulls', function () {
    MetaClass::create(['name' => 'Legacy A', 'target' => 'Place', 'type' => 'class']);
    MetaClass::create(['name' => 'Legacy B', 'target' => 'Place', 'type' => 'class']);
    MetaClass::create(['name' => 'Posts', 'target' => 'Post', 'type' => 'class', 'resource_key' => 'content.posts']);

    expect(MetaClass::query()->whereNull('resource_key')->count())->toBe(2)
        ->and(MetaClass::query()->where('resource_key', 'content.posts')->count())->toBe(1);

    expect(fn () => MetaClass::create([
        'name' => 'Posts again', 'target' => 'Post', 'type' => 'class', 'resource_key' => 'content.posts',
    ]))->toThrow(QueryException::class);
});

it('keeps legacy attribute creation working with is_custom false and a uuid', function () {
    $attribute = metaCustomFieldAttribute()->fresh();

    expect($attribute->is_custom)->toBeFalse()
        ->and($attribute->show_in_list)->toBeFalse()
        ->and($attribute->is_searchable)->toBeFalse()
        ->and($attribute->labels)->toBeNull()
        ->and($attribute->archived_at)->toBeNull()
        ->and(Str::isUuid($attribute->uuid))->toBeTrue();
});

it('casts the new attribute columns', function () {
    $attribute = metaCustomFieldAttribute([
        'labels' => ['vi' => 'Màu', 'en' => 'Color'],
        'help' => ['vi' => 'Chọn màu', 'en' => 'Pick a color'],
        'rules' => ['max' => 50],
        'is_custom' => true,
        'show_in_list' => true,
        'is_searchable' => true,
        'archived_at' => '2026-11-07 09:00:00',
    ])->fresh();

    expect($attribute->labels)->toBe(['vi' => 'Màu', 'en' => 'Color'])
        ->and($attribute->help)->toBe(['vi' => 'Chọn màu', 'en' => 'Pick a color'])
        ->and($attribute->rules)->toBe(['max' => 50])
        ->and($attribute->is_custom)->toBeTrue()
        ->and($attribute->show_in_list)->toBeTrue()
        ->and($attribute->is_searchable)->toBeTrue()
        ->and($attribute->archived_at)->toBeInstanceOf(\Carbon\CarbonInterface::class);
});

it('scopes custom attributes and custom attributes of one resource', function () {
    $posts = MetaClass::create(['name' => 'Posts', 'target' => 'Post', 'type' => 'class', 'resource_key' => 'content.posts']);
    $places = MetaClass::create(['name' => 'Places', 'target' => 'Place', 'type' => 'class', 'resource_key' => 'geo.places']);
    $legacyClass = MetaClass::create(['name' => 'Legacy', 'target' => 'Post', 'type' => 'class']);

    $postCustom = metaCustomFieldAttribute(['is_custom' => true]);
    $postLegacy = metaCustomFieldAttribute();
    $placeCustom = metaCustomFieldAttribute(['is_custom' => true]);
    $unattachedCustom = metaCustomFieldAttribute(['is_custom' => true]);
    $legacyClassCustom = metaCustomFieldAttribute(['is_custom' => true]);

    $posts->options()->attach([$postCustom->id, $postLegacy->id]);
    $places->options()->attach([$placeCustom->id]);
    $legacyClass->options()->attach([$legacyClassCustom->id]);

    expect(MetaAttribute::query()->custom()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$postCustom->id, $placeCustom->id, $unattachedCustom->id, $legacyClassCustom->id])->sort()->values()->all())
        ->and(MetaAttribute::query()->forResource('content.posts')->pluck('id')->all())->toBe([$postCustom->id])
        ->and(MetaAttribute::query()->forResource('geo.places')->pluck('id')->all())->toBe([$placeCustom->id])
        ->and(MetaAttribute::query()->forResource('missing.resource')->count())->toBe(0);
});
