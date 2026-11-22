<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * The widget lists the design selects read (library, area add drawer, page block picker) offer only the designs
 * this site can use: its own project, Default, or a legacy design with no project. A design of another project
 * renders nothing here, so it is left out, and a widget with no usable design drops out of the widget lists.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    URL::forceScheme('https');
    config()->set('ispa-cms.folder_name', 'SiteA');

    $theme = fn (Widget $widget, string $key, mixed $projects): WidgetTheme => WidgetTheme::query()->forceCreate([
        'widget_id' => $widget->id, 'theme_key' => $key, 'name' => $key, 'content' => '<p>x</p>', 'project_name' => $projects,
    ]);

    $this->mixed = Widget::query()->forceCreate(['widget_key' => 'site.mixed', 'name' => 'Mixed']);
    $this->own = $theme($this->mixed, 'own', ['SiteA']);
    $this->foreign = $theme($this->mixed, 'foreign', ['SiteB']);
    $this->shared = $theme($this->mixed, 'shared', ['Default']);
    $this->legacy = $theme($this->mixed, 'legacy', null);

    $this->foreignOnly = Widget::query()->forceCreate(['widget_key' => 'site.foreign', 'name' => 'Foreign only']);
    $this->foreignOnlyTheme = $theme($this->foreignOnly, 'other', ['SiteB']);

    $ids = [];
    foreach ([['widget', 'viewAny'], ['widget_theme', 'viewAny']] as [$group, $action]) {
        $ids[] = Permission::query()->firstOrCreate(['group' => $group, 'name' => $action], ['title' => "$group $action"])->id;
    }
    $user = User::query()->create(['name' => 'designs', 'email' => 'designs@test.local', 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    $role = Role::query()->create(['name' => 'designs', 'user_id' => $user->id]);
    $role->permissions()->attach($ids);
    $user->roles()->attach($role->id);
    Sanctum::actingAs($user);
});

/** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
function siteDesignRows(\Illuminate\Testing\TestResponse $response): \Illuminate\Support\Collection
{
    return collect($response->assertOk()->json('data.data') ?? $response->json('data'));
}

dataset('site design lists', ['/widget', '/widget/admin-list']);

it('widget lists carry only the designs usable on this site', function (string $path) {
    $rows = siteDesignRows($this->getJson('/api/v2/admin' . $path));

    expect($rows->pluck('id')->all())->toContain($this->mixed->id)->not->toContain($this->foreignOnly->id);
    expect(collect($rows->firstWhere('id', $this->mixed->id)['template'])->pluck('name')->sort()->values()->all())
        ->toBe(['legacy', 'own', 'shared']);
})->with('site design lists');

it('keeps the same rule on a local environment', function (string $path) {
    $this->app['env'] = 'local';

    $rows = siteDesignRows($this->getJson('/api/v2/admin' . $path));

    expect($rows->pluck('id')->all())->not->toContain($this->foreignOnly->id);
    expect(collect($rows->firstWhere('id', $this->mixed->id)['template'])->pluck('name')->all())->not->toContain('foreign');
})->with('site design lists');

it('widget search leaves out widgets without a usable design', function () {
    $rows = siteDesignRows($this->getJson('/api/v2/admin/widget/search'));

    expect($rows->pluck('id')->all())->toContain($this->mixed->id)->not->toContain($this->foreignOnly->id);
});

it('the page block design list leaves out designs of other projects', function () {
    $ids = siteDesignRows($this->getJson('/api/v2/admin/widget-template'))->pluck('id')->all();

    expect($ids)->toContain($this->own->id, $this->shared->id, $this->legacy->id)
        ->not->toContain($this->foreign->id)
        ->not->toContain($this->foreignOnlyTheme->id);
});

it('a widget editor still reads every design of its widget', function () {
    $ids = siteDesignRows($this->getJson("/api/v2/admin/widget/{$this->mixed->id}/template"))->pluck('id')->all();

    expect($ids)->toContain($this->foreign->id);
});

it('the area add drawer list offers the same designs as pages', function () {
    $rows = siteDesignRows($this->getJson('/api/v2/admin/widget/admin-list?placeable=1'));

    expect(collect($rows->firstWhere('id', $this->mixed->id)['template'])->pluck('name')->sort()->values()->all())
        ->toBe(['legacy', 'own', 'shared']);
    expect($rows->pluck('id')->all())->not->toContain($this->foreignOnly->id);
});

it('a design whose project is empty counts as Default, for pages and shared areas alike', function () {
    $blank = WidgetTheme::query()->forceCreate([
        'widget_id' => $this->mixed->id, 'theme_key' => 'blank', 'name' => 'blank', 'content' => '<p>x</p>', 'project_name' => '',
    ]);
    $blankEntry = WidgetTheme::query()->forceCreate([
        'widget_id' => $this->mixed->id, 'theme_key' => 'blank-entry', 'name' => 'blank-entry', 'content' => '<p>x</p>', 'project_name' => [''],
    ]);
    $none = WidgetTheme::query()->forceCreate([
        'widget_id' => $this->mixed->id, 'theme_key' => 'none', 'name' => 'none', 'content' => '<p>x</p>', 'project_name' => [],
    ]);
    expect($blank->getRawOriginal('project_name'))->toBe('""');

    $pages = WidgetTheme::query()->usableOnSite()->pluck('id')->all();
    expect(WidgetTheme::query()->usableOnSite(true)->pluck('id')->all())->toEqualCanonicalizing($pages);
    expect($pages)->toContain($blank->id, $blankEntry->id, $this->legacy->id, $this->shared->id, $this->own->id)
        ->not->toContain($this->foreign->id)
        // An explicit empty list generates no design files anywhere, so it is usable nowhere.
        ->not->toContain($none->id);

    // The same answer, row by row, as the model's own per-theme rule the Widget module reads.
    foreach (WidgetTheme::query()->get() as $theme) {
        expect($theme->isUsableOnProject('SiteA'))->toBe(in_array($theme->id, $pages, true), "theme {$theme->name}");
    }
});
