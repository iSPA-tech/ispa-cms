<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Database\Seeders\WidgetSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Sites seeded by the old WidgetSeeder hold widget rows inserted with explicit ids, so on PostgreSQL the id sequences
 * stay behind MAX(id) and the next insert hits `widgets_pkey` (two PostgreSQL development sites, 2026-11-24 and 2026-11-27). The proof
 * needs PostgreSQL (DB_CONNECTION=pgsql plus PTG_*); on other drivers the same contract holds trivially. Only the
 * widget migrations run, so the test works on a PostgreSQL database without the other modules' tables.
 */
function widgetSequenceMigrationArgs(): array
{
    $files = [
        '2025_03_16_193358_create_widgets_feature_tables.php',
        '2026_09_29_120000_add_stable_widget_definition_keys.php',
        '2026_11_21_100000_add_catalog_columns_to_widgets_table.php',
        '2026_11_21_100100_add_version_to_widgets_table.php',
        '2026_12_10_100001_add_preview_columns_to_widget_themes.php',
        '2026_12_10_100002_add_name_translations_to_widgets_and_themes.php',
    ];

    return [
        '--path' => array_map(static fn (string $file): string => base_path("cms/Core/database/migrations/{$file}"), $files),
        '--realpath' => true,
    ];
}

beforeEach(fn () => Artisan::call('migrate', widgetSequenceMigrationArgs()));
afterEach(fn () => Artisan::call('migrate:rollback', widgetSequenceMigrationArgs()));

test('widget seeder heals id sequences left behind by explicit-id rows and never moves them back', function () {
    config()->set('ispa-cms.folder_name', 'Default');
    DB::table('widgets')->insert([
        ['id' => 1, 'widget_key' => 'manual.one', 'name' => 'Old one'],
        ['id' => 2, 'widget_key' => 'manual.two', 'name' => 'Old two'],
        ['id' => 3, 'widget_key' => 'manual.three', 'name' => 'Old three'],
    ]);
    DB::table('widget_themes')->insert([
        ['id' => 1, 'widget_id' => 1, 'theme_key' => 'manual', 'name' => 'Default', 'content' => '<p>1</p>'],
        ['id' => 2, 'widget_id' => 2, 'theme_key' => 'manual', 'name' => 'Default', 'content' => '<p>2</p>'],
    ]);
    DB::table('widget_inputs')->insert([
        ['id' => 1, 'widget_id' => 1, 'input_key' => 'manual', 'name' => 'title', 'type' => 'text'],
        ['id' => 2, 'widget_id' => 2, 'input_key' => 'manual', 'name' => 'title', 'type' => 'text'],
    ]);

    $this->seed(WidgetSeeder::class);

    expect(DB::table('widgets')->where('widget_key', 'core.widget-2')->exists())->toBeTrue();
    $newRows = [
        'widgets' => ['widget_key' => 'manual.after', 'name' => 'After'],
        'widget_themes' => ['widget_id' => 3, 'theme_key' => 'after', 'name' => 'After', 'content' => '<p>after</p>'],
        'widget_inputs' => ['widget_id' => 3, 'input_key' => 'after', 'name' => 'After'],
    ];
    foreach ($newRows as $table => $row) {
        $maxId = (int) DB::table($table)->max('id');
        expect(DB::table($table)->insertGetId($row))->toBeGreaterThan($maxId, $table);
    }

    // A deleted newest widget keeps its id retired: a later seed never moves the sequence back onto it.
    $retiredId = (int) DB::table('widgets')->max('id');
    DB::table('widgets')->where('id', $retiredId)->delete();
    $this->seed(WidgetSeeder::class);

    expect(DB::table('widgets')->insertGetId(['widget_key' => 'manual.later', 'name' => 'Later']))
        ->toBeGreaterThan($retiredId);
});
