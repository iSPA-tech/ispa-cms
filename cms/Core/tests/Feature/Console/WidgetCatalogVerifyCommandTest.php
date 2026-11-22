<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use Cms\Core\Database\Seeders\WidgetSeeder;
use Cms\Core\Models\Widget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Write a throwaway catalog into a temp directory; one default theme and one text input per widget.
 *
 * @param array<int, array<string, mixed>> $widgets
 * @param array<int, array<string, mixed>>|null $inputs Override the default inputs.
 * @param array<int, array<string, mixed>>|null $themes Override the default themes.
 */
function writeVerifyCatalog(string $directory, array $widgets, ?array $inputs = null, ?array $themes = null): void
{
    File::ensureDirectoryExists($directory);
    $themes ??= array_map(static fn (array $w): array => [
        'id' => $w['id'] * 10, 'widget_id' => $w['id'], 'theme_key' => 'default', 'name' => 'Default',
        'content' => '<p>{title}</p>', 'css' => null, 'query' => null, 'project_name' => '["Default"]',
    ], $widgets);
    $inputs ??= array_map(static fn (array $w): array => [
        'id' => $w['id'] * 10, 'widget_id' => $w['id'], 'input_key' => 'title', 'name' => 'title',
        'type' => 'text', 'default_value' => null, 'options' => null,
    ], $widgets);
    File::put($directory . '/widgets.php', '<?php return ' . var_export($widgets, true) . ';');
    File::put($directory . '/widget_themes.php', '<?php return ' . var_export($themes, true) . ';');
    File::put($directory . '/widget_inputs.php', '<?php return ' . var_export($inputs, true) . ';');
}

/** Seed the catalog and write the generated theme templates the renderer reads (deploys publish them from the DB). */
function seedVerifyCatalog(object $test): void
{
    $test->seed(WidgetSeeder::class);
    foreach (DB::table('widget_themes')->get() as $theme) {
        File::put(
            $test->root . "/storage/app/generated_widgets/default/widget_{$theme->widget_id}_theme_{$theme->id}.blade.php",
            (string) $theme->content,
        );
    }
}

function runVerify(array $args = []): array
{
    $exit = Artisan::call('cms:widget-catalog-verify', $args);

    return [$exit, Artisan::output()];
}

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/ispa-widget-verify-' . uniqid();
    $this->dir = $this->root . '/pub';
    File::ensureDirectoryExists($this->root . '/storage/app/generated_widgets/default');
    $this->app->useStoragePath($this->root . '/storage');
    config()->set('ispa-cms.folder_name', 'Default');
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $this->dir]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('passes on a valid synced catalog', function () {
    writeVerifyCatalog($this->dir, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero', 'version' => 1]]);
    seedVerifyCatalog($this);

    [$exit, $output] = runVerify();

    expect($exit)->toBe(0)->and($output)->toContain('PASSED')->and($output)->toContain('no catalog.lock.php yet');
});

it('fails when a publisher directory exists without widgets.php', function () {
    File::ensureDirectoryExists($this->dir);

    [$exit, $output] = runVerify();

    expect($exit)->toBe(1)->and($output)->toContain('TestPub (catalog): widgets.php is missing');
});

it('fails on a duplicate widget key', function () {
    writeVerifyCatalog($this->dir, [
        ['id' => 1, 'widget_key' => 'testpub.a', 'name' => 'A'],
        ['id' => 2, 'widget_key' => 'testpub.a', 'name' => 'B'],
    ]);

    [$exit, $output] = runVerify();

    expect($exit)->toBe(1)->and($output)->toContain('TestPub testpub.a: duplicate widget_key');
});

it('fails when an input is retyped without a version bump and passes with one', function () {
    $widgets = [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero', 'version' => 2]];
    writeVerifyCatalog($this->dir, $widgets);
    seedVerifyCatalog($this);
    File::put($this->dir . '/catalog.lock.php', '<?php return ' . var_export(['testpub.hero' => ['version' => 2, 'inputs' => ['title' => 'textarea']]], true) . ';');

    [$exit, $output] = runVerify();
    expect($exit)->toBe(1)->and($output)->toContain('TestPub testpub.hero: input title retyped (textarea -> text)');

    // A fresh directory avoids opcache serving the rewritten catalog files from cache within one process.
    $widgets[0]['version'] = 3;
    $bumped = $this->root . '/bumped';
    writeVerifyCatalog($bumped, $widgets);
    copy($this->dir . '/catalog.lock.php', $bumped . '/catalog.lock.php');
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $bumped]);
    seedVerifyCatalog($this);

    [$exit, $output] = runVerify();
    expect($output)->toContain('PASSED')->and($exit)->toBe(0);
});

it('writes the lock with --update-lock only after the checks pass', function () {
    writeVerifyCatalog($this->dir, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero', 'version' => 4]]);
    seedVerifyCatalog($this);

    [$exit] = runVerify(['--update-lock' => true]);

    expect($exit)->toBe(0)
        ->and(require $this->dir . '/catalog.lock.php')->toBe(['testpub.hero' => ['version' => 4, 'inputs' => ['title' => 'text']]]);
});

it('fails when a theme does not render', function () {
    writeVerifyCatalog($this->dir, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero']]);
    seedVerifyCatalog($this);
    // Break the pairing in the local database: the theme now belongs to another widget.
    $other = Widget::forceCreate(['widget_key' => 'site.other', 'name' => 'Other']);
    DB::table('widget_themes')->update(['widget_id' => $other->id]);

    [$exit, $output] = runVerify();

    expect($exit)->toBe(1)->and($output)->toContain('TestPub testpub.hero: theme default not in local database');
});

it('fails when a catalog widget is not in the local database', function () {
    writeVerifyCatalog($this->dir, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero']]);

    [$exit, $output] = runVerify();

    expect($exit)->toBe(1)->and($output)->toContain('TestPub testpub.hero: not in local database (run cms:sync-widgets)');
});

it('skips the render check for a blocked widget with a warning', function () {
    writeVerifyCatalog($this->dir, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero']]);
    seedVerifyCatalog($this);
    DB::table('widgets')->where('widget_key', 'testpub.hero')->update(['blocked_at' => now()]);

    [$exit, $output] = runVerify();

    expect($exit)->toBe(0)->and($output)->toContain('blocked in local database, render check skipped');
});

it('fails on a test row', function () {
    writeVerifyCatalog($this->dir, [
        ['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Widget Site Layout Fixture Widget'],
        ['id' => 2, 'widget_key' => 'e2e-test-2', 'name' => 'Two'],
    ]);
    seedVerifyCatalog($this);

    [$exit, $output] = runVerify();

    expect($exit)->toBe(1)
        ->and($output)->toContain('TestPub testpub.hero: test row must not ship')
        ->and($output)->toContain('TestPub e2e-test-2: test row must not ship');
});

it('fails on an unexported edit only on authoring environments', function () {
    writeVerifyCatalog($this->dir, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero']]);
    seedVerifyCatalog($this);
    DB::table('widgets')->where('widget_key', 'testpub.hero')->update(['name' => 'Edited locally']);

    expect(runVerify()[0])->toBe(0);

    config()->set('ispa-cms.widget.catalog_authoring', true);
    [$exit, $output] = runVerify();

    expect($exit)->toBe(1)->and($output)->toContain('TestPub testpub.hero: unexported edit in name');
});

it('fails when a theme template is broken and sends no alert', function () {
    writeVerifyCatalog($this->dir, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero']], null, [[
        'id' => 10, 'widget_id' => 1, 'theme_key' => 'default', 'name' => 'Default',
        'content' => '@if(<p>{title}</p>', 'css' => null, 'query' => null, 'project_name' => '["Default"]',
    ]]);
    seedVerifyCatalog($this);

    $spy = new class {
        public int $calls = 0;

        public function __call(string $name, array $arguments): void
        {
            $this->calls++;
        }
    };
    app()->instance('CMSLogger', $spy);
    \Cms\Core\Facades\CMSLogger::clearResolvedInstance('CMSLogger');

    [$exit, $output] = runVerify();

    expect($exit)->toBe(1)
        ->and($output)->toContain('TestPub testpub.hero: theme default threw while rendering')
        ->and($spy->calls)->toBe(0)
        ->and(app('CMSLogger'))->toBe($spy);
});

it('skips client-project themes with a note', function () {
    // The sync keeps only Default and this project's themes, so the client theme is not in the database at all.
    writeVerifyCatalog($this->dir, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero']], null, [
        ['id' => 10, 'widget_id' => 1, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<p>{title}</p>',
            'css' => null, 'query' => null, 'project_name' => '["Default"]'],
        ['id' => 11, 'widget_id' => 1, 'theme_key' => 'client', 'name' => 'Client', 'content' => '@if(<p>{title}</p>',
            'css' => null, 'query' => null, 'project_name' => '["SomeClient"]'],
    ]);
    seedVerifyCatalog($this);

    [$exit, $output] = runVerify();

    expect($exit)->toBe(0)->and($output)->toContain('theme client skipped (client-project theme)');
});

it('passes a theme that renders empty output', function () {
    writeVerifyCatalog($this->dir, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero']], null, [[
        'id' => 10, 'widget_id' => 1, 'theme_key' => 'default', 'name' => 'Default',
        'content' => '', 'css' => null, 'query' => null, 'project_name' => '["Default"]',
    ]]);
    seedVerifyCatalog($this);

    expect(runVerify()[0])->toBe(0);
});

it('fails when the generated template file is missing', function () {
    writeVerifyCatalog($this->dir, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero']]);
    $this->seed(WidgetSeeder::class);

    [$exit, $output] = runVerify();

    expect($exit)->toBe(1)->and($output)->toContain('TestPub testpub.hero: theme default has no generated template');
});

it('syncs the catalog version to the database and fails when it drops below the lock', function () {
    writeVerifyCatalog($this->dir, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero', 'version' => 5]]);
    seedVerifyCatalog($this);

    expect((int) DB::table('widgets')->where('widget_key', 'testpub.hero')->value('version'))->toBe(5);

    File::put($this->dir . '/catalog.lock.php', '<?php return ' . var_export(['testpub.hero' => ['version' => 6, 'inputs' => ['title' => 'text']]], true) . ';');
    [$exit, $output] = runVerify(['--files-only' => true]);

    expect($exit)->toBe(1)->and($output)->toContain('TestPub testpub.hero: version lowered below lock (5 < 6)');
});

it('keeps private Internal inputs out of the lock and rule 2', function () {
    $exported = $this->root . '/seeders/exported';
    writeVerifyCatalog($exported, [['id' => 1, 'widget_key' => 'ispa.hero', 'name' => 'Hero']]);
    File::ensureDirectoryExists($this->root . '/seeders/Internal');
    File::put($this->root . '/seeders/Internal/widget_inputs.php', '<?php return ' . var_export([[
        'id' => 99, 'widget_id' => 1, 'input_key' => 'client_only', 'name' => 'client_only', 'type' => 'text',
        'default_value' => null, 'options' => null,
    ]], true) . ';');
    config()->set('ispa-cms.widget_catalog_sources', ['iSPA' => $exported]);

    [$exit] = runVerify(['--files-only' => true, '--update-lock' => true]);
    $lock = require $exported . '/catalog.lock.php';

    expect($exit)->toBe(0)->and($lock['ispa.hero']['inputs'])->toBe(['title' => 'text']);

    // The open-source export has no Internal folder: the lock must still hold.
    File::deleteDirectory($this->root . '/seeders/Internal');
    expect(runVerify(['--files-only' => true])[0])->toBe(0);
});

it('makes deploy preflight fail on a catalog problem and checks files only', function () {
    app()->instance(\App\Support\Modules\ModuleDeploymentPreflightRegistry::class, new \App\Support\Modules\ModuleDeploymentPreflightRegistry());
    writeVerifyCatalog($this->dir, [
        ['id' => 1, 'widget_key' => 'testpub.a', 'name' => 'A'],
        ['id' => 2, 'widget_key' => 'testpub.a', 'name' => 'B'],
    ]);

    $exit = Artisan::call('cms:deploy-preflight');
    $output = Artisan::output();

    expect($exit)->toBe(1)->and($output)->toContain('testpub.a: duplicate widget_key')->and($output)->toContain('Widget catalog verification');

    // A valid catalog that was never synced to this database still passes the files-only check.
    writeVerifyCatalog($this->root . '/ok', [['id' => 1, 'widget_key' => 'testpub.ok', 'name' => 'Ok']]);
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $this->root . '/ok']);
    Artisan::call('cms:deploy-preflight');

    expect(Artisan::output())->not->toContain('not in local database');
});

it('accepts well-formed per-language names, compares them by meaning and refuses malformed ones', function () {
    $widgets = [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero', 'name_translations' => '{"en": "Hero", "vi": "Ảnh bìa"}']];
    $themes = [[
        'id' => 10, 'widget_id' => 1, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<p>{title}</p>',
        'css' => null, 'query' => null, 'project_name' => '["Default"]', 'name_translations' => '{"vi": "Mặc định"}',
    ]];
    writeVerifyCatalog($this->dir, $widgets, null, $themes);
    seedVerifyCatalog($this);
    config()->set('ispa-cms.widget.catalog_authoring', true);

    // The database may hold the same names in another JSON spacing: not an unexported edit.
    DB::table('widgets')->where('widget_key', 'testpub.hero')->update(['name_translations' => '{"en":"Hero","vi":"Ảnh bìa"}']);
    [$exit, $output] = runVerify();
    expect($exit)->toBe(0)->and($output)->toContain('PASSED');

    DB::table('widget_themes')->where('theme_key', 'default')->update(['name_translations' => '{"vi":"Kiểu khác"}']);
    [$exit, $output] = runVerify();
    expect($exit)->toBe(1)->and($output)->toContain('unexported edit in theme default name_translations');

    config()->set('ispa-cms.widget.catalog_authoring', false);
    $themes[0]['name_translations'] = '{"fr": "Défaut"}';
    writeVerifyCatalog($this->dir, [['name_translations' => '{"vi": ""}'] + $widgets[0]], null, $themes);
    [$exit, $output] = runVerify(['--files-only' => true]);
    expect($exit)->toBe(1)
        ->and($output)->toContain('TestPub testpub.hero: widget name_translations values must be trimmed')
        ->and($output)->toContain('TestPub testpub.hero: theme default name_translations may only have the keys en, vi');
});
