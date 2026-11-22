<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetDefinitionReconciler;
use Cms\Core\Database\Seeders\WidgetSeeder;

test('legacy widget definitions have stable identity columns', function () {
    expect(Schema::hasColumn('widgets', 'widget_key'))->toBeTrue()
        ->and(Schema::hasColumn('widget_themes', 'theme_key'))->toBeTrue()
        ->and(Schema::hasColumn('widget_inputs', 'input_key'))->toBeTrue();
});

test('Core widget seeder never removes operator definitions or reassigns synced IDs', function () {
    DB::table('widgets')->insert(['id' => 200, 'widget_key' => 'manual.operator', 'name' => 'Operator block']);
    config()->set('ispa-cms.folder_name', 'Default');

    $this->seed(WidgetSeeder::class);
    $firstId = DB::table('widgets')->where('widget_key', 'core.widget-2')->value('id');
    $this->seed(WidgetSeeder::class);

    expect($firstId)->toBeInt()->not->toBe(2)
        ->and(DB::table('widgets')->where('widget_key', 'core.widget-2')->value('id'))->toBe($firstId)
        ->and(DB::table('widgets')->where('id', 200)->value('name'))->toBe('Operator block');
});

test('duplicate source keys roll back reconciliation', function () {
    $reconciler = app(LegacyWidgetDefinitionReconciler::class);
    $widgets = [
        ['id' => 1, 'widget_key' => 'core.same', 'name' => 'First'],
        ['id' => 2, 'widget_key' => 'core.same', 'name' => 'Second'],
    ];

    expect(fn () => $reconciler->reconcile($widgets, [], [], 'iSPA'))
        ->toThrow(\InvalidArgumentException::class, 'Duplicate source widget key');
    expect(DB::table('widgets')->count())->toBe(0);
});

test('reconciliation preserves existing IDs and absent operator rows across repeated runs', function () {
    DB::table('widgets')->insert([
        ['id' => 7, 'widget_key' => 'legacy.widget-7', 'name' => 'Hero'],
        ['id' => 70, 'widget_key' => 'manual.operator', 'name' => 'Operator block'],
    ]);
    DB::table('widget_themes')->insert([
        'id' => 17, 'widget_id' => 7, 'theme_key' => 'legacy.theme-17',
        'name' => 'Default', 'content' => '<p>old</p>',
    ]);
    DB::table('widget_inputs')->insert([
        'id' => 27, 'widget_id' => 7, 'input_key' => 'legacy.input-27',
        'name' => 'title', 'type' => 'text',
    ]);

    $widgets = [['id' => 7, 'widget_key' => 'core.hero', 'name' => 'Hero', 'description' => 'Updated']];
    $themes = [['id' => 17, 'widget_id' => 7, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<p>new</p>']];
    $inputs = [['id' => 27, 'widget_id' => 7, 'input_key' => 'title', 'name' => 'title', 'type' => 'textarea']];

    $reconciler = app(LegacyWidgetDefinitionReconciler::class);
    $reconciler->reconcile($widgets, $themes, $inputs, 'iSPA');
    $reconciler->reconcile($widgets, $themes, $inputs, 'iSPA');

    expect(DB::table('widgets')->where('widget_key', 'core.hero')->value('id'))->toBe(7)
        ->and(DB::table('widgets')->where('id', 7)->value('description'))->toBe('Updated')
        ->and(DB::table('widget_themes')->where('widget_id', 7)->where('theme_key', 'default')->value('id'))->toBe(17)
        ->and(DB::table('widget_themes')->where('id', 17)->value('content'))->toBe('<p>new</p>')
        ->and(DB::table('widget_inputs')->where('widget_id', 7)->where('input_key', 'title')->value('id'))->toBe(27)
        ->and(DB::table('widget_inputs')->where('id', 27)->value('type'))->toBe('textarea')
        ->and(DB::table('widgets')->where('id', 70)->value('name'))->toBe('Operator block')
        ->and(DB::table('widgets')->count())->toBe(2);
});

test('reconciliation adopts a uniquely named legacy row after numeric IDs drift', function () {
    DB::table('widgets')->insert(['id' => 77, 'widget_key' => 'legacy.widget-77', 'name' => 'Hero']);
    DB::table('widget_themes')->insert([
        'id' => 117, 'widget_id' => 77, 'theme_key' => 'legacy.theme-117',
        'name' => 'Default', 'content' => '<p>old</p>',
    ]);
    DB::table('widget_inputs')->insert([
        'id' => 127, 'widget_id' => 77, 'input_key' => 'legacy.input-127',
        'name' => 'title', 'type' => 'text',
    ]);

    app(LegacyWidgetDefinitionReconciler::class)->reconcile(
        [['id' => 7, 'widget_key' => 'core.hero', 'name' => 'Hero']],
        [['id' => 17, 'widget_id' => 7, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<p>new</p>']],
        [['id' => 27, 'widget_id' => 7, 'input_key' => 'title', 'name' => 'title', 'type' => 'textarea']],
        'iSPA',
    );

    expect(DB::table('widgets')->where('widget_key', 'core.hero')->value('id'))->toBe(77)
        ->and(DB::table('widget_themes')->where('theme_key', 'default')->value('id'))->toBe(117)
        ->and(DB::table('widget_inputs')->where('input_key', 'title')->value('id'))->toBe(127)
        ->and(DB::table('widgets')->count())->toBe(1);
});

test('ambiguous legacy names fail without claiming either row', function () {
    DB::table('widgets')->insert([
        ['id' => 77, 'widget_key' => 'legacy.widget-77', 'name' => 'Hero'],
        ['id' => 78, 'widget_key' => 'legacy.widget-78', 'name' => 'Hero'],
    ]);

    expect(fn () => app(LegacyWidgetDefinitionReconciler::class)->reconcile(
        [['id' => 7, 'widget_key' => 'core.hero', 'name' => 'Hero']], [], [], 'iSPA',
    ))->toThrow(\RuntimeException::class, 'Ambiguous legacy widgets name');
    expect(DB::table('widgets')->where('widget_key', 'core.hero')->exists())->toBeFalse()
        ->and(DB::table('widgets')->count())->toBe(2);
});

test('operator-created definitions receive immutable manual keys', function () {
    $widget = Widget::create(['name' => 'Operator block']);
    $theme = WidgetTheme::create(['widget_id' => $widget->id, 'name' => 'Operator theme', 'content' => '<p>ok</p>']);
    $input = WidgetInput::create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);

    expect($widget->widget_key)->toMatch('/^manual\.[0-9a-f-]{36}$/')
        ->and($theme->theme_key)->toMatch('/^manual-[0-9a-f-]{36}$/')
        ->and($input->input_key)->toMatch('/^manual-[0-9a-f-]{36}$/');

    $widget->widget_key = 'changed.key';
    expect(fn () => $widget->save())->toThrow(\LogicException::class);
});

test('migration backfills legacy keys on existing IDs', function () {
    $default = DB::getDefaultConnection();
    config()->set('database.connections.widget_identity_test', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
    DB::setDefaultConnection('widget_identity_test');
    try {
        (require base_path('cms/Core/database/migrations/2025_03_16_193358_create_widgets_feature_tables.php'))->up();
        DB::table('widgets')->insert(['id' => 41, 'name' => 'Hero Slider']);
        DB::table('widget_themes')->insert([
            'id' => 51, 'widget_id' => 41, 'name' => 'Hero',
            'content' => '<section>Hero</section>', 'project_name' => '["Default"]',
        ]);
        DB::table('widget_inputs')->insert([
            'id' => 61, 'widget_id' => 41, 'name' => 'hero_image', 'type' => 'image',
        ]);

        $migration = require base_path('cms/Core/database/migrations/2026_09_29_120000_add_stable_widget_definition_keys.php');
        $migration->up();

        expect(DB::table('widgets')->where('id', 41)->value('widget_key'))->toBe('legacy.widget-41')
            ->and(DB::table('widget_themes')->where('id', 51)->value('theme_key'))->toBe('legacy.theme-51')
            ->and(DB::table('widget_inputs')->where('id', 61)->value('input_key'))->toBe('legacy.input-61');

        $migration->down();
        expect(DB::table('widget_themes')->where('id', 51)->exists())->toBeTrue()
            ->and(DB::table('widget_inputs')->where('id', 61)->exists())->toBeTrue();
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('widget_identity_test');
    }
});
