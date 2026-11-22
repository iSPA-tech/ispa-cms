<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Widget hub distribution, Task 6: site copies keep their own preview image. A clone gets its own copy of each hub
 * design's preview (marked stale), and the admin uploads a fresh capture after a design save.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\Catalog\WidgetCloneService;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(TestCase::class, RefreshDatabase::class);

function widgetPreviewPng(int $width = 40, int $height = 30): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

beforeEach(function () {
    URL::forceScheme('https');
    config(['ispa-cms.widget.catalog_authoring' => false]);

    // Cloning publishes generated templates: keep them out of the tracked storage tree. Previews go to a fake disk.
    $this->storageRoot = sys_get_temp_dir() . '/ispa-widget-preview-' . uniqid('', true);
    File::ensureDirectoryExists($this->storageRoot . '/app');
    $this->app->useStoragePath($this->storageRoot);
    Storage::fake('public');

    $permissionIds = [];
    foreach (['widget', 'widget_theme'] as $group) {
        foreach (['viewAny', 'view', 'create', 'update', 'delete', 'trust'] as $action) {
            $permissionIds[] = Permission::query()->create(['group' => $group, 'name' => $action, 'title' => "$group $action"])->id;
        }
    }
    $this->user = User::query()->create(['name' => 'Editor', 'email' => 'editor@test.local', 'password' => Hash::make('password')]);
    $this->user->forceFill(['type' => UserType::ADMIN])->save();
    $this->role = Role::query()->create(['name' => 'editor', 'user_id' => $this->user->id]);
    $this->role->permissions()->attach($permissionIds);
    $this->user->roles()->attach($this->role->id);
    Sanctum::actingAs($this->user);

    $this->hub = Widget::query()->forceCreate(['widget_key' => 'ispa.hero', 'name' => 'Hero', 'origin' => Widget::ORIGIN_HUB, 'publisher' => 'ispa']);
    Storage::disk('public')->put('widget-previews/ispa.hero/default-v1.png', widgetPreviewPng());
    $this->hubTheme = WidgetTheme::query()->forceCreate([
        'widget_id' => $this->hub->id, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<div>a</div>',
        'preview_image' => 'widget-previews/ispa.hero/default-v1.png', 'preview_stale' => false,
    ]);
    $this->hubBare = WidgetTheme::query()->forceCreate([
        'widget_id' => $this->hub->id, 'theme_key' => 'dark', 'name' => 'Dark', 'content' => '<div>b</div>',
    ]);

    $this->site = Widget::query()->forceCreate(['widget_key' => 'site.custom', 'name' => 'Custom', 'origin' => Widget::ORIGIN_SITE]);
    Storage::disk('public')->put('widget-previews/site.custom/default-old.png', widgetPreviewPng());
    $this->siteTheme = WidgetTheme::query()->forceCreate([
        'widget_id' => $this->site->id, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<div>s</div>',
        'preview_image' => 'widget-previews/site.custom/default-old.png', 'preview_stale' => true,
    ]);

    $this->upload = fn (WidgetTheme $theme, UploadedFile $file) => $this->post(
        "/api/v2/admin/widget-template/{$theme->id}/preview-image",
        ['image' => $file],
        ['Accept' => 'application/json'],
    );
});

afterEach(function () {
    File::deleteDirectory($this->storageRoot);
});

it('clone copies preview and marks it stale', function () {
    $result = app(WidgetCloneService::class)->cloneForSite($this->hub, (int) $this->user->id);

    $copy = WidgetTheme::query()->where('widget_id', $result->clone->id)->where('theme_key', 'default')->firstOrFail();
    $bare = WidgetTheme::query()->where('widget_id', $result->clone->id)->where('theme_key', 'dark')->firstOrFail();

    expect($copy->preview_image)->not->toBeNull()
        ->and($copy->preview_image)->not->toBe($this->hubTheme->preview_image)
        ->and(str_starts_with((string) $copy->preview_image, 'widget-previews/'))->toBeTrue()
        ->and((bool) $copy->preview_stale)->toBeTrue()
        ->and(Storage::disk('public')->get($copy->preview_image))->toBe(Storage::disk('public')->get($this->hubTheme->preview_image))
        ->and($bare->preview_image)->toBeNull()
        // A design without a hub picture is marked stale too: it has no picture of the site copy yet.
        ->and((bool) $bare->preview_stale)->toBeTrue();

    // The hub design keeps its own file and flag.
    $hub = $this->hubTheme->fresh();
    expect($hub->preview_image)->toBe('widget-previews/ispa.hero/default-v1.png')
        ->and((bool) $hub->preview_stale)->toBeFalse();
    Storage::disk('public')->assertExists('widget-previews/ispa.hero/default-v1.png');
});

it('upload stores image and clears stale', function () {
    $bytes = widgetPreviewPng(1200, 600);
    $response = ($this->upload)($this->siteTheme, UploadedFile::fake()->createWithContent('preview.png', $bytes));

    $response->assertOk();
    $theme = $this->siteTheme->fresh();
    expect($theme->preview_image)->not->toBe('widget-previews/site.custom/default-old.png')
        ->and(str_starts_with((string) $theme->preview_image, 'widget-previews/'))->toBeTrue()
        ->and((bool) $theme->preview_stale)->toBeFalse();

    // Stored re-encoded (never the uploaded bytes), same size in pixels.
    $stored = Storage::disk('public')->get($theme->preview_image);
    $info = getimagesizefromstring($stored);
    expect($stored)->not->toBe($bytes)
        ->and([$info[0], $info[1]])->toBe([1200, 600])
        ->and(imagecreatefromstring($stored))->not->toBeFalse();
    $response->assertJsonPath('data.preview_stale', false)
        ->assertJsonPath('data.preview_image_url', Storage::disk('public')->url($theme->preview_image));

    // The replaced file belonged only to this design, so it is gone; no temporary files remain.
    Storage::disk('public')->assertMissing('widget-previews/site.custom/default-old.png');
    expect(collect(Storage::disk('public')->allFiles())->filter(fn ($f) => str_contains($f, '.tmp-'))->all())->toBe([]);

    // The library edit payload exposes the public URL and the flag.
    $this->getJson("/api/v2/admin/widget/{$this->site->id}/edit")
        ->assertOk()
        ->assertJsonPath('data.template.0.preview_image_url', Storage::disk('public')->url($theme->preview_image))
        ->assertJsonPath('data.template.0.preview_stale', false);
});

it('upload over 1 MB refused', function () {
    $response = ($this->upload)($this->siteTheme, UploadedFile::fake()->createWithContent('preview.png', widgetPreviewPng() . str_repeat("\0", 1024 * 1024)));

    $response->assertStatus(422)->assertJsonPath('error_code', 'WIDGET_PREVIEW_INVALID');
    $theme = $this->siteTheme->fresh();
    expect($theme->preview_image)->toBe('widget-previews/site.custom/default-old.png')
        ->and((bool) $theme->preview_stale)->toBeTrue();
    expect(Storage::disk('public')->allFiles())->toHaveCount(2);
});

it('upload refuses a file that is not really an image', function () {
    ($this->upload)($this->siteTheme, UploadedFile::fake()->createWithContent('preview.png', "\x89PNG\r\n\x1a\nnot an image"))
        ->assertStatus(422);

    expect($this->siteTheme->fresh()->preview_image)->toBe('widget-previews/site.custom/default-old.png');
});

it('upload refuses a PNG header followed by junk', function () {
    // A real signature and IHDR chunk (header readers accept it), then bytes that do not decode.
    $png = widgetPreviewPng(40, 30);
    $headerOnly = substr($png, 0, 33) . str_repeat('<script>alert(1)</script>', 20);
    expect(getimagesizefromstring($headerOnly))->not->toBeFalse();

    ($this->upload)($this->siteTheme, UploadedFile::fake()->createWithContent('preview.png', $headerOnly))
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'WIDGET_PREVIEW_INVALID');
    expect($this->siteTheme->fresh()->preview_image)->toBe('widget-previews/site.custom/default-old.png');
});

it('upload refuses huge declared dimensions before decoding', function () {
    // Rewrite the IHDR width/height of a small PNG to 60000 x 60000 (and fix the chunk CRC).
    $png = widgetPreviewPng(40, 30);
    $ihdr = 'IHDR' . pack('NN', 60000, 60000) . substr($png, 24, 5);
    $bomb = substr($png, 0, 12) . $ihdr . pack('N', crc32($ihdr)) . substr($png, 33);
    expect(array_slice(getimagesizefromstring($bomb), 0, 2))->toBe([60000, 60000]);

    ($this->upload)($this->siteTheme, UploadedFile::fake()->createWithContent('preview.png', $bomb))
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'WIDGET_PREVIEW_INVALID');
    expect($this->siteTheme->fresh()->preview_image)->toBe('widget-previews/site.custom/default-old.png');
});

it('upload accepts a WebP capture', function () {
    $image = imagecreatetruecolor(1200, 750);
    ob_start();
    imagewebp($image, null, 85);
    $webp = (string) ob_get_clean();

    ($this->upload)($this->siteTheme, UploadedFile::fake()->createWithContent('preview.png', $webp))->assertOk();
    $stored = Storage::disk('public')->get($this->siteTheme->fresh()->preview_image);
    expect(array_slice(getimagesizefromstring($stored), 0, 2))->toBe([1200, 750]);
});

it('upload refuses a hub design this site may not edit, and without design-save permission', function () {
    ($this->upload)($this->hubTheme, UploadedFile::fake()->createWithContent('preview.png', widgetPreviewPng()))
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_HUB_READONLY');
    expect($this->hubTheme->fresh()->preview_image)->toBe('widget-previews/ispa.hero/default-v1.png');

    // An admin without `widget.update` (what the design editor's save checks) is refused too, even with every
    // design (widget_theme) permission.
    $viewer = User::query()->create(['name' => 'Viewer', 'email' => 'viewer@test.local', 'password' => Hash::make('password')]);
    $viewer->forceFill(['type' => UserType::ADMIN])->save();
    $role = Role::query()->create(['name' => 'viewer', 'user_id' => $viewer->id]);
    $role->permissions()->attach(Permission::query()
        ->where(fn ($q) => $q->where('group', 'widget_theme')->orWhere(fn ($q) => $q->where('group', 'widget')->where('name', '!=', 'update')))
        ->pluck('id'));
    $viewer->roles()->attach($role->id);
    Sanctum::actingAs($viewer);
    ($this->upload)($this->siteTheme, UploadedFile::fake()->createWithContent('preview.png', widgetPreviewPng()))
        ->assertStatus(403);
    expect($this->siteTheme->fresh()->preview_image)->toBe('widget-previews/site.custom/default-old.png');
});

it('replacing a clone image never touches a file another design still uses', function () {
    // A clone made before Task 6 shares the hub's file path.
    $this->siteTheme->forceFill(['preview_image' => 'widget-previews/ispa.hero/default-v1.png'])->save();

    ($this->upload)($this->siteTheme, UploadedFile::fake()->createWithContent('preview.png', widgetPreviewPng()))->assertOk();

    Storage::disk('public')->assertExists('widget-previews/ispa.hero/default-v1.png');
});

it('upload without an image or with a non-image type answers WIDGET_PREVIEW_INVALID', function () {
    $this->post("/api/v2/admin/widget-template/{$this->siteTheme->id}/preview-image", [], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('error_code', 'WIDGET_PREVIEW_INVALID');
    ($this->upload)($this->siteTheme, UploadedFile::fake()->createWithContent('preview.txt', 'hello'))
        ->assertStatus(422)->assertJsonPath('error_code', 'WIDGET_PREVIEW_INVALID');
});
