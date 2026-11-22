<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Database\Seeders\EnumerateTableSeed;
use Cms\Core\Models\Enumerate;
use Cms\Core\Models\EnumerateType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The enum rows carry a foreign key to enumerates_types, and cms:modules-sync
 * discovers seeders by class name — which put EnumerateTableSeed ahead of
 * EnumerateTypeTableSeed. On a live site that surfaced as a
 * 1452 on every deploy.
 */
beforeEach(function () {
    $this->originalDatabaseDefault = config('database.default');
    $this->originalSqliteDatabase = config('database.connections.sqlite.database');

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');

    Schema::create((new EnumerateType())->getTable(), function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('key_name')->nullable();
        $table->timestamps();
    });

    Schema::create((new Enumerate())->getTable(), function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('name_alias')->nullable();
        $table->string('enum_key')->unique();
        $table->string('type')->nullable();
        $table->unsignedBigInteger('type_id')->nullable();
        $table->string('input_type')->nullable();
        $table->boolean('is_filter')->default(false);
        $table->boolean('status')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function () {
    Schema::dropIfExists((new Enumerate())->getTable());
    Schema::dropIfExists((new EnumerateType())->getTable());
    config()->set('database.default', $this->originalDatabaseDefault);
    config()->set('database.connections.sqlite.database', $this->originalSqliteDatabase);
});

test('the enum seeder creates the parent types before the rows that reference them', function () {
    expect(EnumerateType::query()->count())->toBe(0);

    (new EnumerateTableSeed())->run();

    expect(EnumerateType::query()->count())->toBeGreaterThan(0);

    $orphans = Enumerate::query()
        ->whereNotNull('type_id')
        ->whereNotIn('type_id', EnumerateType::query()->select('id'))
        ->count();

    expect($orphans)->toBe(0);
});

test('every exported enum row names a type that the type export actually has', function () {
    $enums = require base_path('cms/Core/database/seeders/exported/enumerates.php');
    $types = require base_path('cms/Core/database/seeders/exported/enumerates_types.php');

    $knownTypeIds = array_fill_keys(array_column($types, 'id'), true);

    $orphans = array_values(array_map(
        static fn (array $enum): string => (string) $enum['enum_key'],
        array_filter(
            $enums,
            static fn (array $enum): bool => ($enum['type_id'] ?? null) !== null
                && !isset($knownTypeIds[(int) $enum['type_id']])
        )
    ));

    expect($orphans)->toBe([]);
});

test('an orphaned row is skipped rather than aborting the rows after it', function () {
    $seeder = new class extends EnumerateTableSeed {
        protected function records(): array
        {
            return [
                [
                    'id' => 1,
                    'name' => 'Before',
                    'name_alias' => 'Before',
                    'enum_key' => 'before_orphan',
                    'type' => 'status',
                    'type_id' => 1,
                    'input_type' => 'radio',
                    'is_filter' => 1,
                    'status' => 1,
                    'created_at' => null,
                    'updated_at' => null,
                    'deleted_at' => null,
                ],
                [
                    'id' => 2,
                    'name' => 'Orphan',
                    'name_alias' => 'Orphan',
                    'enum_key' => 'orphan',
                    'type' => 'order_status',
                    'type_id' => 9999,
                    'input_type' => 'radio',
                    'is_filter' => 1,
                    'status' => 1,
                    'created_at' => null,
                    'updated_at' => null,
                    'deleted_at' => null,
                ],
                [
                    'id' => 3,
                    'name' => 'After',
                    'name_alias' => 'After',
                    'enum_key' => 'after_orphan',
                    'type' => 'status',
                    'type_id' => 1,
                    'input_type' => 'radio',
                    'is_filter' => 1,
                    'status' => 1,
                    'created_at' => null,
                    'updated_at' => null,
                    'deleted_at' => null,
                ],
            ];
        }
    };

    $seeder->run();

    expect(Enumerate::query()->where('enum_key', 'before_orphan')->exists())->toBeTrue()
        ->and(Enumerate::query()->where('enum_key', 'after_orphan')->exists())->toBeTrue()
        ->and(Enumerate::query()->where('enum_key', 'orphan')->exists())->toBeFalse();
});
