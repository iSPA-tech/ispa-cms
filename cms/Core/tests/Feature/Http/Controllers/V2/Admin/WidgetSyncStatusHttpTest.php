<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Gate 0 Task 2: HTTP-level truthful sync_status contract tests.
 *
 * Proves at the real controller/route level ("Mutation and sync status"
 * frozen contract) that a database-committed Widget mutation:
 * - answers HTTP 200 with the legacy success envelope even when the
 *   post-commit sync fails (never 503 for that case),
 * - carries the committed resource identity (data.id) plus the additive
 *   sync_status / sync_incident_id / sync_error_code fields,
 * - actually committed the row (visible in the database after the response).
 *
 * Also covers the protected operator retry action
 * (POST /api/v2/admin/widget/retry-sync): permission gate on widget.update
 * and the ready response shape for a permitted user.
 *
 * The Artisan facade is mocked per-test so `cms:export-widgets` never runs
 * for real here (its exit code is the export failure/success switch); the
 * artifact publisher runs against isolated storage.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Cms\Core\Services\Widget\WidgetDefinitionSyncService;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;

uses(TestCase::class, RefreshDatabase::class);

const WIDGET_SYNC_ADMIN_BASE = '/api/v2/admin';

beforeEach(function () {
    // These tests assert the export step, which only runs on an authoring environment.
    config(['ispa-cms.widget.catalog_authoring' => true]);
    // HttpsProtocol middleware redirects plain-http requests outside the
    // local environment; keep the full middleware stack by requesting HTTPS.
    URL::forceScheme('https');

    $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-sync-http-' . uniqid('', true);
    File::ensureDirectoryExists($this->isolatedStoragePath . '/app');
    $this->app->useStoragePath($this->isolatedStoragePath);

    // Real permission naming convention: group = singular table name.
    $permissionIds = [];
    foreach (['widget', 'widget_theme', 'widget_input'] as $group) {
        foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $action) {
            $permissionIds[] = Permission::query()->create([
                'group' => $group,
                'name' => $action,
                'title' => $group . ' ' . $action,
            ])->id;
        }
    }

    // Chunk 1 Task 9b (FR-14): the seeded naming convention now also
    // includes the Core-owned trust permissions gating executable-content
    // mutation (PermissionTableSeed adds 'trust' to the widget and
    // widget_theme groups). The permissioned fixture mirrors the full
    // convention because the store tests below create widgets carrying
    // template content — executable content whose CREATION requires
    // widget_theme.trust. Trust DENIAL contracts live in
    // cms/Core/tests/Feature/Security/WidgetTrustBoundaryTest.php.
    foreach (['widget', 'widget_theme'] as $group) {
        $permissionIds[] = Permission::query()->create([
            'group' => $group,
            'name' => 'trust',
            'title' => $group . ' trust',
        ])->id;
    }

    $this->allowedUser = User::query()->create([
        'name' => 'Widget Sync Admin',
        'email' => 'widget-sync-admin@test.local',
        'password' => Hash::make('password'),
    ]);
    $this->allowedUser->forceFill(['type' => UserType::ADMIN])->save();

    $role = Role::query()->create(['name' => 'widget-sync-admin', 'user_id' => $this->allowedUser->id]);
    $role->permissions()->attach($permissionIds);
    $this->allowedUser->roles()->attach($role->id);

    // Admin-type user that passes api.admin but holds no widget permissions.
    $this->deniedUser = User::query()->create([
        'name' => 'No Widget Sync Access',
        'email' => 'no-widget-sync@test.local',
        'password' => Hash::make('password'),
    ]);
    $this->deniedUser->forceFill(['type' => UserType::ADMIN])->save();
});

afterEach(function () {
    if (!empty($this->isolatedStoragePath)) {
        File::deleteDirectory($this->isolatedStoragePath);
    }
});

// ---------------------------------------------------------------------------
// Truthful pending: committed mutation + failing post-commit sync => 200,
// sync_status pending, incident id, committed id. Never 503.
// ---------------------------------------------------------------------------

test('widget store answers 200 with committed id and sync_status pending when the post-commit sync fails', function () {
    Sanctum::actingAs($this->allowedUser);

    // Export failure is the post-commit sync failure trigger; the DB commit
    // has already happened by the time it runs.
    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(1);

    $response = $this->postJson(WIDGET_SYNC_ADMIN_BASE . '/widget', [
        'name' => 'Truthful Sync Widget',
        'template' => [
            ['name' => 'Truthful Theme', 'content' => '<section><h2>truthful</h2></section>'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.sync_status', 'pending')
        ->assertJsonPath('data.sync_error_code', WidgetDefinitionSyncService::ERROR_EXPORT_FAILED);

    expect($response->json('data.sync_incident_id'))->toBeString()->not->toBeEmpty();

    // Committed resource identity: the row exists and matches data.id.
    $committedId = $response->json('data.id');
    expect($committedId)->toBeInt();

    $widget = Widget::query()->find($committedId);
    expect($widget)->not->toBeNull()
        ->and($widget->name)->toBe('Truthful Sync Widget');
    expect(WidgetTheme::query()->where('widget_id', $committedId)->count())->toBe(1);
});

test('widget store answers 200 with committed id and sync_status ready when publication succeeds', function () {
    Sanctum::actingAs($this->allowedUser);

    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(0);

    $response = $this->postJson(WIDGET_SYNC_ADMIN_BASE . '/widget', [
        'name' => 'Ready Sync Widget',
        'template' => [
            ['name' => 'Ready Theme', 'content' => '<section><h2>ready</h2></section>'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.sync_status', 'ready')
        ->assertJsonPath('data.sync_incident_id', null)
        ->assertJsonPath('data.sync_error_code', null);

    $committedId = $response->json('data.id');
    expect($committedId)->toBeInt()
        ->and(Widget::query()->whereKey($committedId)->exists())->toBeTrue();

    // The real publisher ran: an active generation exists with the Default
    // project set (theme without project_name fans out to Default).
    $manifest = LegacyWidgetArtifactSetPublisher::readActiveManifest();
    expect($manifest)->not->toBeNull()
        ->and($manifest->projectEntry('Default'))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Protected retry action: permission gate + response shape.
// ---------------------------------------------------------------------------

test('retry sync denies a user without widget.update using the legacy error envelope', function () {
    Sanctum::actingAs($this->deniedUser);

    $this->postJson(WIDGET_SYNC_ADMIN_BASE . '/widget/retry-sync')
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.no.permission'));
});

test('retry sync runs a full resync for a permitted user and reports the sync result shape', function () {
    Sanctum::actingAs($this->allowedUser);

    $widget = Widget::query()->create(['name' => 'Retry Widget']);
    WidgetTheme::query()->create([
        'widget_id' => $widget->id,
        'name' => 'Retry Theme',
        'content' => '<section><h2>retry</h2></section>',
        'project_name' => ['Alpha'],
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(0);

    $response = $this->postJson(WIDGET_SYNC_ADMIN_BASE . '/widget/retry-sync');

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.sync_status', 'ready')
        ->assertJsonPath('data.sync_incident_id', null)
        ->assertJsonPath('data.sync_error_code', null);

    $manifest = LegacyWidgetArtifactSetPublisher::readActiveManifest();
    expect($manifest)->not->toBeNull()
        ->and($manifest->projectEntry('Alpha'))->not->toBeNull();
});
