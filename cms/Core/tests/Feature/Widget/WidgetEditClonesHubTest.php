<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Unified widget catalog part 1, Task 6: on a live site, saving a design change to a hub widget
 * creates a site clone (moving page uses to it) and applies the edit to the clone. The hub row
 * never changes, and a hub widget cannot be deleted on a site.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Content\Models\Post;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\Catalog\WidgetAuditRecorder;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(TestCase::class, RefreshDatabase::class);

const EDIT_CLONE_BASE = '/api/v2/admin/widget';

beforeEach(function () {
    URL::forceScheme('https');
    config(['ispa-cms.widget.catalog_authoring' => false]);

    // Sync publishes generated artifacts: keep them out of the tracked storage tree.
    $this->storageRoot = sys_get_temp_dir() . '/ispa-widget-edit-clone-' . uniqid('', true);
    File::ensureDirectoryExists($this->storageRoot . '/app');
    $this->app->useStoragePath($this->storageRoot);

    $crud = [];
    foreach (['widget', 'widget_theme', 'widget_input'] as $group) {
        foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $action) {
            $crud[] = Permission::query()->create(['group' => $group, 'name' => $action, 'title' => "$group $action"])->id;
        }
    }
    $trust = [
        Permission::query()->create(['group' => 'widget', 'name' => 'trust', 'title' => 'widget trust'])->id,
        Permission::query()->create(['group' => 'widget_theme', 'name' => 'trust', 'title' => 'widget_theme trust'])->id,
    ];
    $makeUser = function (string $email, array $permissionIds): User {
        $user = User::query()->create(['name' => $email, 'email' => $email, 'password' => Hash::make('password')]);
        $user->forceFill(['type' => UserType::ADMIN])->save();
        $role = Role::query()->create(['name' => $email, 'user_id' => $user->id]);
        $role->permissions()->attach($permissionIds);
        $user->roles()->attach($role->id);

        return $user;
    };
    $this->trusted = $makeUser('trusted@test.local', [...$crud, ...$trust]);
    $this->untrusted = $makeUser('editor@test.local', $crud);

    DB::table('languages')->insert([
        'id' => 1, 'name' => 'Vietnamese', 'path' => 'vi', 'path_alias' => 'vi', 'currency' => 'VND',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->hub = Widget::query()->forceCreate([
        'widget_key' => 'ispa.hero_banner', 'name' => 'Hero banner', 'origin' => Widget::ORIGIN_HUB, 'publisher' => 'ispa',
    ]);
    $this->hubTheme = WidgetTheme::query()->forceCreate([
        'widget_id' => $this->hub->id, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<div>{title}</div>',
    ]);
    $this->hubInput = WidgetInput::query()->forceCreate([
        'widget_id' => $this->hub->id, 'input_key' => 'title', 'name' => 'title', 'type' => 'text',
    ]);

    $post = new Post();
    $post->forceFill(['name' => 'Home', 'type' => 'page', 'language_id' => 1, 'content' => [[
        'type' => 'widget', 'widget_id' => $this->hub->id, 'template_id' => $this->hubTheme->id,
        'widget_key' => 'ispa.hero_banner', 'theme_key' => 'default', 'value' => ['title' => 'Hi'],
    ]]])->save();
    $this->post = $post;

    // Edit payload as the admin form sends it: hub ids for existing rows.
    $this->payload = fn (array $overrides = []): array => array_replace_recursive([
        'name' => 'Hero banner (site)',
        'template' => [['id' => $this->hubTheme->id, 'name' => 'Default', 'content' => '<div>{title}</div>']],
        'inputs' => [['id' => $this->hubInput->id, 'name' => 'Title', 'type' => 'text']],
    ], $overrides);

    $this->hubSnapshot = fn (): array => [
        Widget::query()->find($this->hub->id)->only(['name', 'origin', 'publisher', 'widget_key']),
        WidgetTheme::query()->where('widget_id', $this->hub->id)->get(['id', 'name', 'content'])->toArray(),
        WidgetInput::query()->where('widget_id', $this->hub->id)->get(['id', 'name'])->toArray(),
    ];
});

afterEach(function () {
    File::deleteDirectory($this->storageRoot);
});

it('editing a hub widget on a site creates a clone and leaves the hub row unchanged', function () {
    Sanctum::actingAs($this->trusted);
    $before = ($this->hubSnapshot)();

    $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)([
        'template' => [
            ['content' => '<section>{title}</section>'],
            ['name' => 'Extra', 'content' => '<p>new</p>'],
        ],
        // Catalog columns in an admin request are ignored.
        'origin' => 'hub', 'publisher' => 'ispa',
    ]))->assertOk()->assertJsonPath('status', 'success');

    expect(($this->hubSnapshot)())->toBe($before);

    $clone = Widget::query()->where('cloned_from_widget_key', 'ispa.hero_banner')->sole();
    expect($clone->origin)->toBe('site')
        ->and($clone->publisher)->toBeNull()
        ->and($clone->name)->toBe('Hero banner (site)')
        ->and($clone->template()->orderBy('id')->pluck('content')->all())->toBe(['<section>{title}</section>', '<p>new</p>'])
        ->and($clone->template()->orderBy('id')->value('theme_key'))->toBe('default')
        ->and($clone->inputs()->pluck('name', 'input_key')->all())->toBe(['title' => 'Title']);

    $block = json_decode((string) DB::table('posts')->where('id', $this->post->id)->value('content'), true)[0];
    expect($block['widget_id'])->toBe($clone->id)->and($block['widget_key'])->toBe($clone->widget_key);

    // The sync after the save published a generated template for the clone theme pages now use.
    $manifest = LegacyWidgetArtifactSetPublisher::readActiveManifest();
    expect($manifest)->not->toBeNull()
        ->and(LegacyWidgetArtifactSetPublisher::resolveProjectFilePath(
            $manifest, 'Default', "widget_{$clone->id}_theme_{$block['template_id']}.blade.php"
        ))->not->toBeNull();
});

it('response reports the clone and pages updated', function () {
    Sanctum::actingAs($this->trusted);

    $response = $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)())->assertOk();

    $clone = Widget::query()->where('cloned_from_widget_key', 'ispa.hero_banner')->sole();
    $response->assertJsonPath('data.id', $clone->id)
        ->assertJsonPath('data.cloned.widget_id', $clone->id)
        ->assertJsonPath('data.cloned.widget_key', 'site.ispa.hero_banner-1')
        ->assertJsonPath('data.cloned.pages_updated', 1);

    expect(AuditLog::query()->where('action', WidgetAuditRecorder::ACTION_DESIGN_SAVED)->where('target_id', $clone->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', WidgetAuditRecorder::ACTION_CLONED)->where('target_id', $clone->id)->count())->toBe(1);
});

it('editing a hub widget with authoring on saves in place', function () {
    config(['ispa-cms.widget.catalog_authoring' => true]);
    Sanctum::actingAs($this->trusted);
    // Authoring sync runs the catalog export; that writes repo files and is covered by Task 4 tests.
    \Illuminate\Support\Facades\Artisan::shouldReceive('call')->with('cms:export-widgets')->andReturn(0);

    $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)())
        ->assertOk()
        ->assertJsonPath('data.id', $this->hub->id)
        ->assertJsonMissingPath('data.cloned');

    expect(Widget::query()->count())->toBe(1)
        ->and($this->hub->fresh()->name)->toBe('Hero banner (site)')
        ->and($this->hubInput->fresh()->name)->toBe('Title')
        ->and(AuditLog::query()->where('action', WidgetAuditRecorder::ACTION_DESIGN_SAVED)->where('target_id', $this->hub->id)->count())->toBe(1);
});

it('deleting a hub widget on a site returns 409 WIDGET_HUB_READONLY', function () {
    Sanctum::actingAs($this->trusted);

    $this->deleteJson(EDIT_CLONE_BASE . '/' . $this->hub->id)
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_HUB_READONLY')
        ->assertJsonPath('message', 'Mẫu này thuộc kho gốc. Hãy chỉnh sửa để tạo bản riêng cho site.');

    expect(Widget::query()->find($this->hub->id))->not->toBeNull()
        ->and(WidgetTheme::query()->where('widget_id', $this->hub->id)->count())->toBe(1);
});

it('concurrent edits on a hub widget produce one clone', function () {
    Sanctum::actingAs($this->trusted);

    // Two editors opened the hub widget; both save. The second save waits on the hub row lock,
    // then finds the fresh clone and edits it instead of cloning again.
    $first = $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)(['name' => 'First']))->assertOk();
    $second = $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)([
        'name' => 'Second',
        'inputs' => [['name' => 'Heading']],
    ]))->assertOk();

    $clone = Widget::query()->where('cloned_from_widget_key', 'ispa.hero_banner')->sole();
    expect($first->json('data.cloned.widget_id'))->toBe($clone->id)
        ->and($second->json('data.cloned.widget_id'))->toBe($clone->id)
        ->and($second->json('data.cloned.pages_updated'))->toBe(0)
        ->and($clone->name)->toBe('Second')
        ->and($clone->template()->count())->toBe(1)
        ->and($clone->inputs()->pluck('name', 'input_key')->all())->toBe(['title' => 'Heading'])
        ->and($this->hub->fresh()->name)->toBe('Hero banner');
});

it('trust permission still required for theme content edits on the clone', function () {
    Sanctum::actingAs($this->untrusted);
    $before = ($this->hubSnapshot)();

    $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)([
        'template' => [['content' => '<script>x</script>']],
    ]))->assertStatus(400)->assertJsonPath('message', __('mgs.no.permission'));

    // The refused edit rolled the clone back with it.
    expect(Widget::query()->count())->toBe(1)->and(($this->hubSnapshot)())->toBe($before);

    // A non-executable edit by the same user still clones.
    $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)())->assertOk();
    expect(Widget::query()->where('cloned_from_widget_key', 'ispa.hero_banner')->count())->toBe(1);
});

it('js trust check compares against a reused clone, not the hub', function () {
    Sanctum::actingAs($this->trusted);
    $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)(['js' => 'console.log(1)']))->assertOk();

    // A stale editor still showing the hub's empty js saves within the reuse window: on the clone
    // that clears executable js, so widget.trust is required.
    Sanctum::actingAs($this->untrusted);
    $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)(['js' => '']))
        ->assertStatus(400)->assertJsonPath('message', __('mgs.no.permission'));

    expect(Widget::query()->where('cloned_from_widget_key', 'ispa.hero_banner')->sole()->js)->toBe('console.log(1)');
});

it('reuse skips a blocked clone and makes a new one', function () {
    Sanctum::actingAs($this->trusted);
    $first = $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)())->assertOk();
    Widget::query()->whereKey($first->json('data.cloned.widget_id'))->update(['blocked_at' => now(), 'blocked_reason' => 'test']);

    $second = $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)(['name' => 'After block']))->assertOk();

    expect($second->json('data.cloned.widget_id'))->not->toBe($first->json('data.cloned.widget_id'))
        ->and($second->json('data.cloned.widget_key'))->toBe('site.ispa.hero_banner-2')
        ->and(Widget::query()->find($first->json('data.cloned.widget_id'))->name)->toBe('Hero banner (site)');
});

it('stale or foreign theme id in an update payload is rejected and leaves hub and clone unchanged', function () {
    Sanctum::actingAs($this->trusted);
    // First save clones and drops the hub theme from the clone, replacing it with a new one.
    $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)([
        'template' => [['id' => 0, 'name' => 'Replacement', 'content' => '<p>r</p>']],
    ]))->assertOk();
    $clone = Widget::query()->where('cloned_from_widget_key', 'ispa.hero_banner')->sole();

    $foreign = Widget::query()->forceCreate(['widget_key' => 'site.other', 'name' => 'Other', 'origin' => Widget::ORIGIN_SITE]);
    $foreignTheme = WidgetTheme::query()->forceCreate(['widget_id' => $foreign->id, 'name' => 'F', 'content' => '<i>f</i>']);

    $hubBefore = ($this->hubSnapshot)();
    $cloneSnapshot = fn (): array => [
        Widget::query()->find($clone->id)->only(['name', 'js']),
        WidgetTheme::query()->where('widget_id', $clone->id)->orderBy('id')->get(['id', 'name', 'content'])->toArray(),
        WidgetTheme::query()->whereKey($foreignTheme->id)->first(['widget_id', 'content'])->toArray(),
    ];
    $cloneBefore = $cloneSnapshot();

    foreach ([$this->hubTheme->id, $foreignTheme->id] as $themeId) {
        // Stale: the hub theme no longer exists on the reused clone. Foreign: another widget's theme.
        $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)([
            'name' => 'Should not land',
            'template' => [['id' => $themeId, 'name' => 'X', 'content' => '<b>x</b>']],
        ]))->assertStatus(400)->assertJsonPath('status', 'error');

        expect(($this->hubSnapshot)())->toBe($hubBefore)
            ->and($cloneSnapshot())->toBe($cloneBefore)
            ->and(Widget::query()->count())->toBe(3);
    }
});

it('adding a site to a hub theme on a site returns 409 WIDGET_HUB_READONLY without cloning', function () {
    Sanctum::actingAs($this->trusted);
    $before = ($this->hubSnapshot)();
    $postBefore = DB::table('posts')->where('id', $this->post->id)->value('content');

    // What the Site Layout "enable for this site" sends: the edit payload unchanged except one project added.
    $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, [
        'id' => $this->hub->id,
        'name' => 'Hero banner',
        'template' => [['id' => $this->hubTheme->id, 'name' => 'Default', 'content' => '<div>{ title }</div>', 'project_name' => ['Default', 'ExampleSite']]],
        'inputs' => [['id' => $this->hubInput->id, 'name' => 'title', 'type' => 'text']],
    ])->assertStatus(409)->assertJsonPath('error_code', 'WIDGET_HUB_READONLY');

    expect(Widget::query()->count())->toBe(1)
        ->and(($this->hubSnapshot)())->toBe($before)
        ->and(WidgetTheme::query()->find($this->hubTheme->id)->project_name)->toBeNull()
        ->and(DB::table('posts')->where('id', $this->post->id)->value('content'))->toBe($postBefore);

    // A project change together with a design change is still a design edit: it clones.
    $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, [
        'name' => 'Hero banner',
        'template' => [['id' => $this->hubTheme->id, 'name' => 'Default', 'content' => '<p>{title}</p>', 'project_name' => ['ExampleSite']]],
    ])->assertOk();
    expect(Widget::query()->where('cloned_from_widget_key', 'ispa.hero_banner')->count())->toBe(1);
});

it('a clone saved with the unchanged hub name keeps its own name, so a later edit finds it by name', function () {
    Sanctum::actingAs($this->trusted);

    $this->putJson(EDIT_CLONE_BASE . '/' . $this->hub->id, ($this->payload)(['name' => 'Hero banner']))->assertOk();

    $clone = Widget::query()->where('cloned_from_widget_key', 'ispa.hero_banner')->sole();
    expect($clone->name)->toBe('Hero banner (bản riêng)')
        ->and($this->hub->fresh()->name)->toBe('Hero banner');

    // Editing the clone itself with its own name keeps that name (no second suffix).
    $this->putJson(EDIT_CLONE_BASE . '/' . $clone->id, ($this->payload)([
        'name' => 'Hero banner (bản riêng)',
        'template' => [['id' => $clone->template()->value('id')]],
        'inputs' => [['id' => $clone->inputs()->value('id')]],
    ]))->assertOk();
    expect($clone->fresh()->name)->toBe('Hero banner (bản riêng)')
        ->and(Widget::query()->count())->toBe(2);
});
