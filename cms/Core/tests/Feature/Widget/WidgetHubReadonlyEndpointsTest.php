<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Unified widget catalog part 1, Task 6b: the widget-scoped theme and input endpoints refuse to write
 * a hub widget on a site (409 WIDGET_HUB_READONLY, before any write). Site widgets, and hub widgets on
 * an authoring environment, keep working as before.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    URL::forceScheme('https');
    config(['ispa-cms.widget.catalog_authoring' => false]);

    $this->storageRoot = sys_get_temp_dir() . '/ispa-widget-hub-readonly-' . uniqid('', true);
    File::ensureDirectoryExists($this->storageRoot . '/app');
    $this->app->useStoragePath($this->storageRoot);

    $permissionIds = [];
    foreach (['widget', 'widget_theme', 'widget_input'] as $group) {
        foreach (['viewAny', 'view', 'create', 'update', 'delete', 'trust'] as $action) {
            $permissionIds[] = Permission::query()->create(['group' => $group, 'name' => $action, 'title' => "$group $action"])->id;
        }
    }
    $user = User::query()->create(['name' => 'Editor', 'email' => 'editor@test.local', 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    $role = Role::query()->create(['name' => 'editor', 'user_id' => $user->id]);
    $role->permissions()->attach($permissionIds);
    $user->roles()->attach($role->id);
    Sanctum::actingAs($user);

    $make = function (string $key, string $origin): array {
        $widget = Widget::query()->forceCreate([
            'widget_key' => $key, 'name' => $key, 'origin' => $origin, 'publisher' => $origin === 'hub' ? 'ispa' : null,
        ]);
        $theme = WidgetTheme::query()->forceCreate(['widget_id' => $widget->id, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<div>a</div>']);
        $extraTheme = WidgetTheme::query()->forceCreate(['widget_id' => $widget->id, 'theme_key' => 'dark', 'name' => 'Dark', 'content' => '<div>b</div>']);
        $input = WidgetInput::query()->forceCreate(['widget_id' => $widget->id, 'input_key' => 'title', 'name' => 'title', 'type' => 'text']);

        return [$widget, $theme, $extraTheme, $input];
    };
    [$this->hub, $this->hubTheme, $this->hubExtraTheme, $this->hubInput] = $make('ispa.hero', Widget::ORIGIN_HUB);
    [$this->site, $this->siteTheme, $this->siteExtraTheme, $this->siteInput] = $make('site.custom', Widget::ORIGIN_SITE);

    $this->rowsOf = fn (Widget $w): array => [
        WidgetTheme::query()->where('widget_id', $w->id)->orderBy('id')->get(['id', 'name', 'content'])->toArray(),
        WidgetInput::query()->where('widget_id', $w->id)->orderBy('id')->get(['id', 'name', 'type'])->toArray(),
    ];

    // [method, url, body] for each of the six endpoints on a given widget.
    $this->calls = fn (Widget $w, WidgetTheme $theme, WidgetTheme $deletableTheme, WidgetInput $input): array => [
        'theme store' => ['postJson', "/api/v2/admin/widget/{$w->id}/template", ['widget_id' => $w->id, 'name' => 'New', 'content' => '<p>n</p>']],
        'theme update' => ['putJson', "/api/v2/admin/widget/{$w->id}/template/{$theme->id}", ['name' => 'Renamed', 'content' => '<p>c</p>']],
        'theme destroy' => ['deleteJson', "/api/v2/admin/widget/{$w->id}/template/{$deletableTheme->id}", []],
        'input store' => ['postJson', "/api/v2/admin/widget/{$w->id}/inputs", ['widget_id' => $w->id, 'name' => 'subtitle', 'type' => 'text']],
        'input update' => ['putJson', "/api/v2/admin/widget/{$w->id}/inputs/{$input->id}", ['name' => 'Heading']],
        'input destroy' => ['deleteJson', "/api/v2/admin/widget/{$w->id}/inputs/{$input->id}", []],
    ];
});

afterEach(function () {
    File::deleteDirectory($this->storageRoot);
});

it('theme store/update/destroy on a hub widget returns 409 on a site, same for inputs', function (string $endpoint) {
    $before = ($this->rowsOf)($this->hub);
    [$method, $url, $body] = ($this->calls)($this->hub, $this->hubTheme, $this->hubExtraTheme, $this->hubInput)[$endpoint];

    // Readable sentence from the tracked lang files, never the raw key (default locale is vi).
    $this->{$method}($url, $body)
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_HUB_READONLY')
        ->assertJsonPath('message', 'Mẫu này thuộc kho gốc. Hãy chỉnh sửa để tạo bản riêng cho site.');

    // Request middleware picks the locale, so the English line is checked through the translator.
    expect(trans('mgs.widget_hub_readonly', [], 'en'))->toBe('This widget comes from the shared catalog. Edit it to create a site copy.');

    expect(($this->rowsOf)($this->hub))->toBe($before)
        ->and(Widget::query()->count())->toBe(2);
})->with(['theme store', 'theme update', 'theme destroy', 'input store', 'input update', 'input destroy']);

it('theme and input endpoints still work on site widgets and with authoring on', function (bool $authoring) {
    if ($authoring) {
        config(['ispa-cms.widget.catalog_authoring' => true]);
        // The authoring sync runs the catalog export, which writes repo files (covered by Task 4 tests).
        Artisan::shouldReceive('call')->with('cms:export-widgets')->andReturn(0);
    }
    $targets = $authoring
        ? [[$this->hub, $this->hubTheme, $this->hubExtraTheme, $this->hubInput], [$this->site, $this->siteTheme, $this->siteExtraTheme, $this->siteInput]]
        : [[$this->site, $this->siteTheme, $this->siteExtraTheme, $this->siteInput]];

    foreach ($targets as [$widget, $theme, $extraTheme, $input]) {
        foreach (($this->calls)($widget, $theme, $extraTheme, $input) as $endpoint => [$method, $url, $body]) {
            $this->{$method}($url, $body)->assertOk()->assertJsonPath('status', 'success');
        }

        expect(WidgetTheme::query()->where('widget_id', $widget->id)->pluck('name')->sort()->values()->all())->toBe(['New', 'Renamed'])
            ->and(WidgetInput::query()->where('widget_id', $widget->id)->pluck('name')->all())->toBe(['subtitle']);
    }
})->with(['site widget on a site' => false, 'authoring on' => true]);
