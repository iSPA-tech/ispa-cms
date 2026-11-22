<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Per-language names of widgets and designs: storage, locale resolution, the admin
 * payloads (`display_name`, `name_translations`) and the save validation.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(TestCase::class, RefreshDatabase::class);

const WIDGET_NAMES_BASE = '/api/v2/admin/widget';

beforeEach(function () {
    URL::forceScheme('https');
    config(['ispa-cms.widget.catalog_authoring' => false, 'ispa-cms.available_languages' => ['vi', 'en']]);

    $this->storageRoot = sys_get_temp_dir() . '/ispa-widget-names-' . uniqid('', true);
    File::ensureDirectoryExists($this->storageRoot . '/app');
    $this->app->useStoragePath($this->storageRoot);

    $permissions = [];
    foreach (['widget', 'widget_theme', 'widget_input'] as $group) {
        foreach (['viewAny', 'view', 'create', 'update', 'delete', 'trust'] as $action) {
            $permissions[] = Permission::query()->create(['group' => $group, 'name' => $action, 'title' => "$group $action"])->id;
        }
    }
    $user = User::query()->create(['name' => 'admin', 'email' => 'names@test.local', 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    $role = Role::query()->create(['name' => 'names', 'user_id' => $user->id]);
    $role->permissions()->attach($permissions);
    $user->roles()->attach($role->id);
    Sanctum::actingAs($user);

    $this->site = Widget::query()->forceCreate([
        'widget_key' => 'site.hero', 'name' => 'Hero Slider', 'description' => 'Big banner', 'origin' => Widget::ORIGIN_SITE,
        'name_translations' => ['vi' => 'Ảnh bìa trượt', 'en' => 'Hero Slider'],
        'description_translations' => ['vi' => 'Băng rôn lớn'],
    ]);
    $this->siteTheme = WidgetTheme::query()->forceCreate([
        'widget_id' => $this->site->id, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<div>{title}</div>',
        'project_name' => ['Default'], 'name_translations' => ['vi' => 'Mặc định'],
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->storageRoot);
});

it('stores names in normal form and resolves them per locale with English then name as fallback', function () {
    $widget = Widget::query()->forceCreate([
        'widget_key' => 'site.x', 'name' => 'Social Embeded',
        'name_translations' => ['vi' => '  Nhúng mạng xã hội ', 'en' => ' ', 'fr' => 'Intégrer'],
    ]);
    $raw = DB::table('widgets')->where('id', $widget->id)->value('name_translations');

    expect(json_decode($raw, true))->toBe(['vi' => 'Nhúng mạng xã hội'])
        ->and($raw)->toContain('Nhúng')
        ->and($widget->fresh()->localizedName('vi'))->toBe('Nhúng mạng xã hội')
        ->and($widget->fresh()->localizedName('en'))->toBe('Social Embeded')
        ->and($this->site->localizedName('ja'))->toBe('Hero Slider')
        ->and($this->site->localizedDescription('en'))->toBe('Big banner')
        ->and($this->site->localizedDescription('vi'))->toBe('Băng rôn lớn')
        ->and($this->siteTheme->localizedName('en'))->toBe('Default');

    app()->setLocale('vi');
    expect($this->site->localizedName())->toBe('Ảnh bìa trượt');

    $widget->forceFill(['name_translations' => ['vi' => '']])->save();
    expect(DB::table('widgets')->where('id', $widget->id)->value('name_translations'))->toBeNull();

    // A row read without the column and saved keeps its names.
    $partial = Widget::query()->select(['id', 'widget_key', 'name'])->find($this->site->id);
    $partial->forceFill(['name' => 'Hero Slider 2'])->save();
    expect($this->site->fresh()->name_translations)->toBe(['en' => 'Hero Slider', 'vi' => 'Ảnh bìa trượt']);
});

it('edit, admin-list, list and design payloads carry display_name in the request locale', function () {
    $edit = $this->withHeader('X-Locale', 'vi')->getJson(WIDGET_NAMES_BASE . '/' . $this->site->id . '/edit')->assertOk();
    expect($edit->json('data.name'))->toBe('Hero Slider')
        ->and($edit->json('data.display_name'))->toBe('Ảnh bìa trượt')
        ->and($edit->json('data.display_description'))->toBe('Băng rôn lớn')
        ->and($edit->json('data.name_translations'))->toBe(['en' => 'Hero Slider', 'vi' => 'Ảnh bìa trượt'])
        ->and($edit->json('data.template.0.display_name'))->toBe('Mặc định')
        ->and($edit->json('data.template.0.name_translations'))->toBe(['vi' => 'Mặc định']);

    $en = $this->withHeader('X-Locale', 'en')->getJson(WIDGET_NAMES_BASE . '/' . $this->site->id . '/edit')->assertOk();
    expect($en->json('data.display_name'))->toBe('Hero Slider')
        ->and($en->json('data.display_description'))->toBe('Big banner')
        ->and($en->json('data.template.0.display_name'))->toBe('Default');

    foreach (['/admin-list?scope=library', '?scope=library'] as $path) {
        $rows = $this->withHeader('X-Locale', 'vi')->getJson(WIDGET_NAMES_BASE . $path)->assertOk()->json('data.data');
        $row = collect($rows)->firstWhere('id', $this->site->id);
        expect($row['display_name'])->toBe('Ảnh bìa trượt')
            ->and($row['name'])->toBe('Hero Slider')
            ->and($row['template'][0]['display_name'])->toBe('Mặc định')
            ->and($row['template'][0]['name_translations'])->toBe(['vi' => 'Mặc định']);
    }

    $search = $this->withHeader('X-Locale', 'vi')->getJson(WIDGET_NAMES_BASE . '/search?id=' . $this->site->id)->assertOk();
    expect(collect($search->json('data.data'))->firstWhere('id', $this->site->id)['display_name'])->toBe('Ảnh bìa trượt');

    $design = $this->withHeader('X-Locale', 'vi')
        ->getJson(WIDGET_NAMES_BASE . '/' . $this->site->id . '/template/' . $this->siteTheme->id . '/edit')->assertOk();
    expect($design->json('data.display_name'))->toBe('Mặc định');
});

it('X-Admin-Locale picks the name language without changing the app locale', function () {
    $edit = fn (array $headers) => $this->withHeaders($headers)->getJson(WIDGET_NAMES_BASE . '/' . $this->site->id . '/edit')->assertOk();

    // Without the header: names follow the app locale, as before.
    $baseline = $edit([]);
    $appLocale = app()->getLocale();
    expect($baseline->json('data.display_name'))->toBe($appLocale === 'vi' ? 'Ảnh bìa trượt' : 'Hero Slider');

    // The other language through X-Admin-Locale: names switch, the app locale does not.
    $other = $appLocale === 'vi' ? 'en' : 'vi';
    $switched = $edit(['X-Admin-Locale' => $other]);
    expect($switched->json('data.display_name'))->toBe($other === 'vi' ? 'Ảnh bìa trượt' : 'Hero Slider')
        ->and($switched->json('data.template.0.display_name'))->toBe($other === 'vi' ? 'Mặc định' : 'Default')
        ->and(app()->getLocale())->toBe($appLocale);

    // With the API locale pinned to vi, an English admin still sees English names and the app stays vi.
    $pinned = $edit(['X-Locale' => 'vi', 'X-Admin-Locale' => 'en']);
    expect($pinned->json('data.display_name'))->toBe('Hero Slider')
        ->and($pinned->json('data.display_description'))->toBe('Big banner')
        ->and(app()->getLocale())->toBe('vi');

    // Unknown values are ignored.
    expect($edit(['X-Locale' => 'vi', 'X-Admin-Locale' => 'fr'])->json('data.display_name'))->toBe('Ảnh bìa trượt');
});

it('the widget save stores trimmed names for the widget and its designs', function () {
    $this->putJson(WIDGET_NAMES_BASE . '/' . $this->site->id, [
        'name' => 'Hero Slider',
        'name_translations' => ['vi' => ' Ảnh bìa ', 'en' => 'Hero'],
        'description_translations' => ['en' => 'Large banner'],
        'template' => [
            ['id' => $this->siteTheme->id, 'name' => 'Default', 'content' => '<div>{title}</div>', 'name_translations' => ['vi' => 'Kiểu chính ', 'en' => '']],
            ['name' => 'Dark', 'content' => '<div class="d">{title}</div>', 'name_translations' => ['vi' => 'Nền tối']],
        ],
    ])->assertOk();

    $widget = $this->site->fresh();
    $themes = WidgetTheme::query()->where('widget_id', $widget->id)->orderBy('id')->get();
    expect($widget->name_translations)->toBe(['en' => 'Hero', 'vi' => 'Ảnh bìa'])
        ->and($widget->description_translations)->toBe(['en' => 'Large banner'])
        ->and($themes[0]->name_translations)->toBe(['vi' => 'Kiểu chính'])
        ->and($themes[1]->name_translations)->toBe(['vi' => 'Nền tối']);

    // The design endpoint takes them too.
    $this->putJson(WIDGET_NAMES_BASE . '/' . $this->site->id . '/template/' . $this->siteTheme->id, [
        'name_translations' => ['en' => 'Main'],
    ])->assertOk();
    expect($this->siteTheme->fresh()->name_translations)->toBe(['en' => 'Main']);
});

it('the saves refuse other languages and over-long names', function (string $target, array $payload) {
    $before = [$this->site->fresh()->name_translations, $this->siteTheme->fresh()->name_translations];
    $base = ['name' => 'Hero Slider', 'template' => [['id' => $this->siteTheme->id, 'name' => 'Default', 'content' => '<div>{title}</div>']]];
    $url = $target === 'widget'
        ? WIDGET_NAMES_BASE . '/' . $this->site->id
        : WIDGET_NAMES_BASE . '/' . $this->site->id . '/template/' . $this->siteTheme->id;

    $response = $this->putJson($url, $target === 'widget' ? array_replace_recursive($base, $payload) : $payload);

    expect($response->status())->toBe(406)
        ->and([$this->site->fresh()->name_translations, $this->siteTheme->fresh()->name_translations])->toBe($before);
})->with([
    'unknown language on the widget' => ['widget', ['name_translations' => ['fr' => 'Héros']]],
    'too long on the widget' => ['widget', ['name_translations' => ['vi' => str_repeat('a', 256)]]],
    'description too long' => ['widget', ['description_translations' => ['en' => str_repeat('a', 501)]]],
    'not a string on a design row' => ['widget', ['template' => [['name_translations' => ['vi' => ['x']]]]]],
    'unknown language on the design endpoint' => ['design', ['name_translations' => ['de' => 'Standard']]],
]);

it('a hub widget is cloned when only its names change, and its design endpoint stays read-only', function () {
    $hub = Widget::query()->forceCreate([
        'widget_key' => 'ispa.hero', 'name' => 'Hero', 'origin' => Widget::ORIGIN_HUB, 'publisher' => 'iSPA',
        'name_translations' => ['vi' => 'Ảnh bìa'],
    ]);
    $hubTheme = WidgetTheme::query()->forceCreate([
        'widget_id' => $hub->id, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<div>{title}</div>', 'project_name' => ['Default'],
    ]);

    $this->putJson(WIDGET_NAMES_BASE . '/' . $hub->id . '/template/' . $hubTheme->id, ['name_translations' => ['vi' => 'Mặc định']])
        ->assertStatus(409)->assertJsonPath('error_code', 'WIDGET_HUB_READONLY');

    $response = $this->putJson(WIDGET_NAMES_BASE . '/' . $hub->id, [
        'name' => 'Hero',
        'name_translations' => ['vi' => 'Ảnh bìa lớn'],
        'template' => [['id' => $hubTheme->id, 'name' => 'Default', 'content' => '<div>{title}</div>', 'project_name' => ['Default']]],
    ])->assertOk();

    $clone = Widget::query()->find($response->json('data.cloned.widget_id'));
    expect($hub->fresh()->name_translations)->toBe(['vi' => 'Ảnh bìa'])
        ->and($hubTheme->fresh()->name_translations)->toBeNull()
        ->and($clone->origin)->toBe(Widget::ORIGIN_SITE)
        ->and($clone->name_translations)->toBe(['vi' => 'Ảnh bìa lớn']);
});

it('a clone keeps the hub names with the copy suffix of each language', function () {
    $hub = Widget::query()->forceCreate([
        'widget_key' => 'ispa.hero', 'name' => 'Hero', 'origin' => Widget::ORIGIN_HUB, 'publisher' => 'iSPA',
        'name_translations' => ['vi' => 'Ảnh bìa', 'en' => 'Hero'],
    ]);
    $hubTheme = WidgetTheme::query()->forceCreate([
        'widget_id' => $hub->id, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<div>{title}</div>', 'project_name' => ['Default'],
    ]);

    // Unchanged hub names in the form become the clone's own names.
    $response = $this->putJson(WIDGET_NAMES_BASE . '/' . $hub->id, [
        'name' => 'Hero',
        'name_translations' => ['vi' => 'Ảnh bìa', 'en' => 'Hero'],
        'template' => [['id' => $hubTheme->id, 'name' => 'Default', 'content' => '<div>{title} </div>', 'project_name' => ['Default']]],
    ])->assertOk();

    expect(Widget::query()->find($response->json('data.cloned.widget_id'))->name_translations)
        ->toBe(['en' => 'Hero (own copy)', 'vi' => 'Ảnh bìa (bản riêng)']);
});
