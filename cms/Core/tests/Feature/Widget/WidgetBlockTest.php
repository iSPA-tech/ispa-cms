<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Unified widget catalog part 1, Task 7: an admin can block a widget everywhere it renders (every render
 * path goes through LegacyWidgetRenderer) with a reason, and unblock it. Both are audited.
 */

use App\Constants\UserType;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetRenderer;
use App\Models\User;
use App\View\Components\WidgetView;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\Catalog\WidgetAuditRecorder;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(TestCase::class, RefreshDatabase::class);

const WIDGET_BLOCK_BASE = '/api/v2/admin/widgets';

beforeEach(function () {
    URL::forceScheme('https');
    config()->set('ispa-cms.folder_name', 'Default');

    $this->storageRoot = sys_get_temp_dir() . '/ispa-widget-block-' . uniqid('', true);
    File::ensureDirectoryExists($this->storageRoot . '/app/generated_widgets/default');
    $this->app->useStoragePath($this->storageRoot);

    // A hub widget: blocking is site runtime state, so it applies to hub widgets too.
    $this->widget = Widget::query()->forceCreate([
        'widget_key' => 'ispa.hero', 'name' => 'Hero', 'origin' => Widget::ORIGIN_HUB, 'publisher' => 'ispa',
    ]);
    $this->theme = WidgetTheme::query()->forceCreate([
        'widget_id' => $this->widget->id, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<p>{title}</p>',
    ]);
    WidgetInput::query()->forceCreate(['widget_id' => $this->widget->id, 'input_key' => 'title', 'name' => 'title', 'type' => 'text']);
    File::put(
        storage_path("app/generated_widgets/default/widget_{$this->widget->id}_theme_{$this->theme->id}.blade.php"),
        '<section data-hero>{title}</section>',
    );

    // Renders the widget the way a page block does (<x-widget-view :widget=… :template-id=…>).
    $this->renderPageBlock = fn (): string => (string) (new WidgetView(
        widget: $this->widget->id,
        templateId: $this->theme->id,
        value: ['title' => 'Hello'],
        inputs: [['name' => 'title', 'type' => 'text']],
    ))->render()->render();

    $this->renderResult = fn () => app(WidgetRenderer::class)->render(
        new WidgetRenderSpec($this->widget->id, $this->theme->id, ['title' => 'Hello']),
        new WidgetRenderContext(projectName: 'Default'),
    );

    $makeUser = function (string $email, array $actions): User {
        $ids = array_map(
            static fn (string $action): int => Permission::query()->firstOrCreate(['group' => 'widget', 'name' => $action], ['title' => "widget $action"])->id,
            $actions,
        );
        $user = User::query()->create(['name' => $email, 'email' => $email, 'password' => Hash::make('password')]);
        $user->forceFill(['type' => UserType::ADMIN])->save();
        $role = Role::query()->create(['name' => $email, 'user_id' => $user->id]);
        $role->permissions()->attach($ids);
        $user->roles()->attach($role->id);

        return $user;
    };
    $this->blocker = $makeUser('blocker@test.local', ['viewAny', 'update', 'block']);
    $this->editor = $makeUser('editor@test.local', ['viewAny', 'update']);
});

afterEach(function () {
    File::deleteDirectory($this->storageRoot);
});

it('blocked widget renders empty with WIDGET_BLOCKED in page blocks', function () {
    expect(($this->renderPageBlock)())->toContain('Hello');

    Sanctum::actingAs($this->blocker);
    $this->postJson(WIDGET_BLOCK_BASE . "/{$this->widget->id}/block", ['reason' => 'XSS in hero'])->assertOk();

    $widget = $this->widget->fresh();
    expect($widget->isBlocked())->toBeTrue()
        ->and($widget->blocked_by)->toBe($this->blocker->id)
        ->and($widget->blocked_reason)->toBe('XSS in hero');

    $result = ($this->renderResult)();
    expect($result->html)->toBe('')
        ->and($result->ok)->toBeFalse()
        ->and($result->errorCode)->toBe('WIDGET_BLOCKED')
        ->and(($this->renderPageBlock)())->not->toContain('Hello');

    $audit = AuditLog::query()->where('target_type', 'widget')->sole();
    expect($audit->action)->toBe(WidgetAuditRecorder::ACTION_BLOCKED)
        ->and((int) $audit->target_id)->toBe($this->widget->id)
        ->and((int) $audit->user_id)->toBe($this->blocker->id)
        ->and($audit->options)->toBe(['reason' => 'XSS in hero']);
});

it('unblock restores rendering', function () {
    Sanctum::actingAs($this->blocker);
    $this->postJson(WIDGET_BLOCK_BASE . "/{$this->widget->id}/block", ['reason' => 'investigating'])->assertOk();
    expect(($this->renderPageBlock)())->not->toContain('Hello');

    $this->deleteJson(WIDGET_BLOCK_BASE . "/{$this->widget->id}/block")->assertOk();

    $widget = $this->widget->fresh();
    expect($widget->isBlocked())->toBeFalse()
        ->and($widget->blocked_by)->toBeNull()
        ->and($widget->blocked_reason)->toBeNull()
        ->and(($this->renderResult)()->ok)->toBeTrue()
        ->and(($this->renderPageBlock)())->toContain('Hello')
        ->and(AuditLog::query()->where('target_type', 'widget')->orderBy('id')->pluck('action')->all())
        ->toBe([WidgetAuditRecorder::ACTION_BLOCKED, WidgetAuditRecorder::ACTION_UNBLOCKED]);
});

it('block requires a reason and the widget.block permission', function () {
    $url = WIDGET_BLOCK_BASE . "/{$this->widget->id}/block";

    Sanctum::actingAs($this->editor);
    $this->postJson($url, ['reason' => 'no permission'])
        ->assertStatus(403)->assertJsonPath('error_code', 'WIDGET_BLOCK_FORBIDDEN');
    $this->deleteJson($url)->assertStatus(403)->assertJsonPath('error_code', 'WIDGET_BLOCK_FORBIDDEN');

    Sanctum::actingAs($this->blocker);
    $this->postJson($url, [])->assertStatus(406);
    $this->postJson($url, ['reason' => '   '])->assertStatus(406);
    $this->postJson($url, ['reason' => str_repeat('a', 501)])->assertStatus(406);
    $this->postJson(WIDGET_BLOCK_BASE . '/999999/block', ['reason' => 'missing'])
        ->assertStatus(404)->assertJsonPath('error_code', 'WIDGET_NOT_FOUND');

    expect($this->widget->fresh()->isBlocked())->toBeFalse()
        ->and(AuditLog::query()->where('target_type', 'widget')->count())->toBe(0);

    $this->postJson($url, ['reason' => str_repeat('a', 500)])->assertOk();
    expect($this->widget->fresh()->isBlocked())->toBeTrue();
});

it('block flushes rendered widget caches', function () {
    // First render populates every per-request cache on the path (block-state memo, template path cache).
    expect(($this->renderPageBlock)())->toContain('Hello');

    DB::enableQueryLog();
    ($this->renderPageBlock)();
    $blockLookups = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'blocked_at'))->count();
    DB::disableQueryLog();
    expect($blockLookups)->toBe(0);

    app(\Cms\Core\Services\Widget\Catalog\WidgetBlockService::class)->block($this->widget, 'emergency', $this->blocker->id);
    expect(($this->renderPageBlock)())->toBe('')
        ->and(($this->renderResult)()->errorCode)->toBe('WIDGET_BLOCKED');

    app(\Cms\Core\Services\Widget\Catalog\WidgetBlockService::class)->unblock($this->widget->fresh(), $this->blocker->id);
    expect(($this->renderPageBlock)())->toContain('Hello');
});
