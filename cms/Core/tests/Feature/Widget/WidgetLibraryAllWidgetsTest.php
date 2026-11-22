<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Widget hub distribution, Task 1: the widget library ("Thư viện widget") lists every widget in the site database.
 * Its own request opts in with `scope=library` and gets every design, each marked `usable_on_site`. Without it the
 * list endpoints keep leaving out designs (and widgets) this site cannot use, as the pickers need.
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
    $theme($this->mixed, 'own', ['SiteA']);
    $theme($this->mixed, 'foreign', ['SiteB']);
    $theme($this->mixed, 'shared', ['Default']);
    $theme($this->mixed, 'legacy', null);
    $theme($this->mixed, 'none', []);

    $this->foreignOnly = Widget::query()->forceCreate(['widget_key' => 'site.foreign', 'name' => 'Foreign only']);
    $theme($this->foreignOnly, 'other', ['SiteB']);

    $ids = [];
    foreach ([['widget', 'viewAny'], ['widget_theme', 'viewAny']] as [$group, $action]) {
        $ids[] = Permission::query()->firstOrCreate(['group' => $group, 'name' => $action], ['title' => "$group $action"])->id;
    }
    $user = User::query()->create(['name' => 'library', 'email' => 'library@test.local', 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    $role = Role::query()->create(['name' => 'library', 'user_id' => $user->id]);
    $role->permissions()->attach($ids);
    $user->roles()->attach($role->id);
    Sanctum::actingAs($user);
});

/** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
function libraryScopeRows(\Illuminate\Testing\TestResponse $response): \Illuminate\Support\Collection
{
    return collect($response->assertOk()->json('data.data') ?? $response->json('data'));
}

dataset('library list endpoints', ['/widget', '/widget/admin-list']);

it('library scope lists widgets whose designs belong only to other projects', function (string $path) {
    $rows = libraryScopeRows($this->getJson('/api/v2/admin' . $path . '?scope=library'));

    expect($rows->pluck('id')->all())->toContain($this->mixed->id, $this->foreignOnly->id);
    expect(collect($rows->firstWhere('id', $this->mixed->id)['template'])->pluck('name')->sort()->values()->all())
        ->toBe(['foreign', 'legacy', 'none', 'own', 'shared']);
})->with('library list endpoints');

it('designs carry usable_on_site', function (string $path) {
    $rows = libraryScopeRows($this->getJson('/api/v2/admin' . $path . '?scope=library&with_usage=1'));

    $usable = collect($rows->firstWhere('id', $this->mixed->id)['template'])->pluck('usable_on_site', 'name')->sortKeys()->all();
    expect($usable)->toBe(['foreign' => false, 'legacy' => true, 'none' => false, 'own' => true, 'shared' => true]);
    expect(collect($rows->firstWhere('id', $this->foreignOnly->id)['template'])->pluck('usable_on_site')->all())
        ->toBe([false]);
})->with('library list endpoints');

it('picker lists stay filtered without library scope', function (string $query) {
    foreach (['/widget', '/widget/admin-list'] as $path) {
        $rows = libraryScopeRows($this->getJson('/api/v2/admin' . $path . $query));

        expect($rows->pluck('id')->all())->toContain($this->mixed->id)->not->toContain($this->foreignOnly->id);
        expect(collect($rows->firstWhere('id', $this->mixed->id)['template'])->pluck('name')->sort()->values()->all())
            ->toBe(['legacy', 'own', 'shared']);
    }
})->with(['' => '', 'placeable' => '?placeable=1', 'other scope' => '?scope=picker']);
