<?php

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\StarterPackApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function starterPackMigration(): object
{
    return require base_path('cms/Core/database/migrations/2026_11_06_090000_create_starter_pack_applications_table.php');
}

test('a row is created with an automatic uuid and persisted fields', function () {
    $row = StarterPackApplication::query()->create([
        'pack_key' => 'church-basic',
        'pack_version' => '1.0.0',
        'applied_by' => 7,
        'background_task_uuid' => 'abc',
        'started_at' => now(),
    ]);

    $fresh = StarterPackApplication::query()->findOrFail($row->id);
    expect($fresh->uuid)->not->toBeEmpty()
        ->and($fresh->pack_key)->toBe('church-basic')
        ->and($fresh->pack_version)->toBe('1.0.0')
        ->and($fresh->status)->toBe(StarterPackApplication::STATUS_STARTED)
        ->and($fresh->applied_by)->toBe(7)
        ->and($fresh->background_task_uuid)->toBe('abc')
        ->and($fresh->started_at)->not->toBeNull()
        ->and($fresh->finished_at)->toBeNull();
});

test('steps round-trips as an array', function () {
    $steps = [['key' => 'menus', 'result' => 'created', 'count' => 3], ['key' => 'pages', 'result' => 'skipped']];
    $row = StarterPackApplication::query()->create([
        'pack_key' => 'p', 'pack_version' => '1', 'steps' => $steps, 'started_at' => now(),
    ]);

    expect(StarterPackApplication::query()->findOrFail($row->id)->steps)->toBe($steps);
});

test('succeeded scope returns only succeeded rows', function () {
    foreach ([StarterPackApplication::STATUS_STARTED, StarterPackApplication::STATUS_SUCCEEDED, StarterPackApplication::STATUS_FAILED, StarterPackApplication::STATUS_DRY_RUN] as $status) {
        StarterPackApplication::query()->create(['pack_key' => 'p', 'pack_version' => '1', 'status' => $status, 'started_at' => now()]);
    }

    $rows = StarterPackApplication::query()->succeeded()->get();
    expect($rows)->toHaveCount(1)->and($rows->first()->status)->toBe(StarterPackApplication::STATUS_SUCCEEDED);
});

test('migration is idempotent and reversible', function () {
    $migration = starterPackMigration();

    $migration->up();
    expect(Schema::hasTable(CoreTableConstant::STARTER_PACK_APPLICATIONS))->toBeTrue();

    $migration->down();
    expect(Schema::hasTable(CoreTableConstant::STARTER_PACK_APPLICATIONS))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable(CoreTableConstant::STARTER_PACK_APPLICATIONS))->toBeTrue();
});
