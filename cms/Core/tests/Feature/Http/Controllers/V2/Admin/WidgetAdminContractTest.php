<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Gate 0 Task 1 contract tests for the legacy V2 Admin Widget surface.
 *
 * Characterizes and locks:
 * - permission gates on widget search (viewAny) and preview (view),
 * - widget-scoped ownership of nested Theme/Input child routes
 *   (lookups must constrain by BOTH {widgetId} and the child {id}),
 * - denial of cross-Widget child mutation,
 * - GET-only top-level widget-template / widget-input compatibility routes.
 *
 * Legacy response shape is a frozen compatibility contract: permission and
 * model-missing denials answer HTTP 400 with a JSON body of
 * {status: "error", message: ...}. Do not "modernize" these assertions.
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

const WIDGET_ADMIN_BASE = '/api/v2/admin';

beforeEach(function () {
    // These tests assert the export step, which only runs on an authoring environment.
    config(['ispa-cms.widget.catalog_authoring' => true]);
    // HttpsProtocol middleware redirects plain-http requests outside the
    // local environment; keep the full middleware stack by requesting HTTPS.
    URL::forceScheme('https');

    // The real cms:generate-widget-template command runs after mutations
    // (post-commit sync semantics are Task 2 scope); isolate its filesystem
    // output so test data never lands in the project storage directory.
    $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-contract-' . uniqid();
    File::ensureDirectoryExists($this->isolatedStoragePath . '/app/generated_widgets');
    $this->app->useStoragePath($this->isolatedStoragePath);

    // Seed the real permission naming convention: group = singular table name.
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
    // convention so every pre-existing mutation assertion below keeps
    // exercising its characterized envelope; the trust DENIAL contracts
    // live in cms/Core/tests/Feature/Security/WidgetTrustBoundaryTest.php.
    foreach (['widget', 'widget_theme'] as $group) {
        $permissionIds[] = Permission::query()->create([
            'group' => $group,
            'name' => 'trust',
            'title' => $group . ' trust',
        ])->id;
    }

    $this->allowedUser = User::query()->create([
        'name' => 'Widget Admin',
        'email' => 'widget-admin@test.local',
        'password' => Hash::make('password'),
    ]);
    $this->allowedUser->forceFill(['type' => UserType::ADMIN])->save();

    $role = Role::query()->create(['name' => 'widget-admin', 'user_id' => $this->allowedUser->id]);
    $role->permissions()->attach($permissionIds);
    $this->allowedUser->roles()->attach($role->id);

    // Admin-type user that passes api.admin but holds no widget permissions.
    $this->deniedUser = User::query()->create([
        'name' => 'No Widget Access',
        'email' => 'no-widget@test.local',
        'password' => Hash::make('password'),
    ]);
    $this->deniedUser->forceFill(['type' => UserType::ADMIN])->save();

    $this->widgetA = Widget::query()->create(['name' => 'Widget A']);
    $this->widgetB = Widget::query()->create(['name' => 'Widget B']);

    $this->themeA1 = WidgetTheme::query()->create([
        'widget_id' => $this->widgetA->id, 'name' => 'Theme A1', 'content' => '<div>A1 {title}</div>',
    ]);
    $this->themeA2 = WidgetTheme::query()->create([
        'widget_id' => $this->widgetA->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>',
    ]);
    $this->themeB1 = WidgetTheme::query()->create([
        'widget_id' => $this->widgetB->id, 'name' => 'Theme B1', 'content' => '<div>B1</div>',
    ]);

    $this->inputA1 = WidgetInput::query()->create([
        'widget_id' => $this->widgetA->id, 'name' => 'title', 'type' => 'text',
    ]);
    $this->inputA2 = WidgetInput::query()->create([
        'widget_id' => $this->widgetA->id, 'name' => 'subtitle', 'type' => 'text',
    ]);
    $this->inputB1 = WidgetInput::query()->create([
        'widget_id' => $this->widgetB->id, 'name' => 'body', 'type' => 'text',
    ]);
});

afterEach(function () {
    if (!empty($this->isolatedStoragePath)) {
        File::deleteDirectory($this->isolatedStoragePath);
    }
});

// ---------------------------------------------------------------------------
// Widget search: requires widget.viewAny
// ---------------------------------------------------------------------------

test('widget search denies a user without widget.viewAny', function () {
    Sanctum::actingAs($this->deniedUser);

    $this->getJson(WIDGET_ADMIN_BASE . '/widget/search?keywords=Widget')
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.no.permission'));
});

test('widget search returns data for a user with widget.viewAny', function () {
    Sanctum::actingAs($this->allowedUser);

    $this->getJson(WIDGET_ADMIN_BASE . '/widget/search?keywords=Widget')
        ->assertOk()
        ->assertJsonPath('status', 'success');
});

// ---------------------------------------------------------------------------
// Widget preview: requires widget.view (characterization)
// ---------------------------------------------------------------------------

test('widget preview denies a user without widget.view', function () {
    Sanctum::actingAs($this->deniedUser);

    $this->postJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/preview", [
        'template_id' => $this->themeA1->id,
    ])
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.no.permission'));
});

test('widget preview renders for a user with widget.view', function () {
    Sanctum::actingAs($this->allowedUser);

    $response = $this->postJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/preview", [
        'template_id' => $this->themeA1->id,
    ]);

    $response->assertOk();
    // The success path returns a rendered string, not the JSON error envelope.
    expect((string) $response->headers->get('content-type'))
        ->not->toContain('application/json');
});

test('widget preview without edit_markers has none', function () {
    Sanctum::actingAs($this->allowedUser);
    $this->withoutVite();
    File::ensureDirectoryExists(storage_path('app/generated_widgets/default'));
    File::put(
        storage_path("app/generated_widgets/default/widget_{$this->widgetA->id}_theme_{$this->themeA1->id}.blade.php"),
        '<div>A1 {title}</div>',
    );

    $response = $this->postJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/preview", [
        'template_id' => $this->themeA1->id,
    ]);

    $response->assertOk();
    expect($response->getContent())->toContain('<div>A1 ')
        ->and($response->getContent())->not->toContain('ispa-edit-fields')
        ->and($response->getContent())->not->toContain("\u{2063}");
});

test('widget preview with edit_markers returns markers', function () {
    Sanctum::actingAs($this->allowedUser);
    $this->withoutVite();
    File::ensureDirectoryExists(storage_path('app/generated_widgets/default'));
    File::put(
        storage_path("app/generated_widgets/default/widget_{$this->widgetA->id}_theme_{$this->themeA1->id}.blade.php"),
        '<div>A1 {title}</div>',
    );

    $response = $this->postJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/preview", [
        'template_id' => $this->themeA1->id,
        'edit_markers' => true,
    ]);

    $response->assertOk();
    expect($response->getContent())->toContain('<ispa-field data-field="title"')
        ->and($response->getContent())->toContain('<script type="application/json" id="ispa-edit-fields">')
        ->and($response->getContent())->not->toContain("\u{2063}");
});

test('widget preview with edit_markers never renders literal placeholders for missing inputs', function () {
    Sanctum::actingAs($this->allowedUser);
    $this->withoutVite();
    File::ensureDirectoryExists(storage_path('app/generated_widgets/default'));
    File::put(
        storage_path("app/generated_widgets/default/widget_{$this->widgetA->id}_theme_{$this->themeA1->id}.blade.php"),
        '<div>A1 {title}</div>',
    );
    $url = WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/preview";

    $plain = $this->postJson($url, ['template_id' => $this->themeA1->id]);
    $marked = $this->postJson($url, ['template_id' => $this->themeA1->id, 'edit_markers' => true]);

    expect($plain->getContent())->toContain('{title}')
        ->and($marked->getContent())->not->toContain('{title}');
});

test('widget preview returns 404 for a missing widget', function () {
    Sanctum::actingAs($this->allowedUser);

    $this->postJson(WIDGET_ADMIN_BASE . '/widget/999999/preview', [
        'template_id' => $this->themeA1->id,
    ])->assertStatus(404);
});

test('widget preview rejects a template belonging to another widget', function () {
    Sanctum::actingAs($this->allowedUser);

    $this->postJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/preview", [
        'template_id' => $this->themeB1->id,
    ])->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Nested theme child routes: scoped by widget_id AND child id
// ---------------------------------------------------------------------------

test('nested theme index lists only the owning widget themes', function () {
    Sanctum::actingAs($this->allowedUser);

    $response = $this->getJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/template")
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($this->themeA1->id, $this->themeA2->id)
        ->not->toContain($this->themeB1->id);
});

test('nested theme edit is scoped to the owning widget', function () {
    Sanctum::actingAs($this->allowedUser);

    // Cross-widget read through widget A must not resolve widget B's theme.
    $this->getJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/template/{$this->themeB1->id}/edit")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.model.404'));

    // The correctly scoped child resolves to the requested id.
    $this->getJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/template/{$this->themeA2->id}/edit")
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.id', $this->themeA2->id);
});

test('nested theme update stays scoped to the owning widget', function () {
    Sanctum::actingAs($this->allowedUser);

    $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/template/{$this->themeB1->id}", [
        'name' => 'Hijacked',
    ])
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.model.404'));

    expect($this->themeB1->fresh()->name)->toBe('Theme B1');

    $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/template/{$this->themeA2->id}", [
        'name' => 'Theme A2 Renamed',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect($this->themeA2->fresh()->name)->toBe('Theme A2 Renamed');
});

test('nested theme delete denies cross-widget mutation and never misbinds ids', function () {
    Sanctum::actingAs($this->allowedUser);

    $this->deleteJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/template/{$this->themeB1->id}")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.model.404'));

    // The requested cross-widget child must survive, and no other theme may be
    // deleted by positional id misbinding (theme id == widget id).
    expect(WidgetTheme::query()->find($this->themeB1->id))->not->toBeNull();
    expect(WidgetTheme::query()->find($this->themeA1->id))->not->toBeNull();
    expect(WidgetTheme::query()->count())->toBe(3);
});

test('nested theme delete removes a correctly scoped child', function () {
    Sanctum::actingAs($this->allowedUser);

    $this->deleteJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetB->id}/template/{$this->themeB1->id}")
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect(WidgetTheme::query()->find($this->themeB1->id))->toBeNull();
    expect(WidgetTheme::query()->count())->toBe(2);
});

test('nested theme store enforces route widget ownership', function () {
    Sanctum::actingAs($this->allowedUser);

    $this->postJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/template", [
        'widget_id' => $this->widgetB->id,
        'name' => 'Smuggled Theme',
        'content' => '<div>smuggled</div>',
    ])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');

    expect(WidgetTheme::query()->where('name', 'Smuggled Theme')->exists())->toBeFalse();

    $this->postJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/template", [
        'widget_id' => $this->widgetA->id,
        'name' => 'Theme A3',
        'content' => '<div>A3</div>',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect(
        WidgetTheme::query()->where('name', 'Theme A3')->value('widget_id')
    )->toBe($this->widgetA->id);
});

// ---------------------------------------------------------------------------
// Nested input child routes: scoped by widget_id AND child id
// ---------------------------------------------------------------------------

test('nested input index lists only the owning widget inputs', function () {
    Sanctum::actingAs($this->allowedUser);

    $response = $this->getJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/inputs")
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($this->inputA1->id, $this->inputA2->id)
        ->not->toContain($this->inputB1->id);
});

test('nested input edit is scoped to the owning widget', function () {
    Sanctum::actingAs($this->allowedUser);

    // Cross-widget read through widget A must not resolve widget B's input.
    $this->getJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/inputs/{$this->inputB1->id}/edit")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.model.404'));

    // The correctly scoped child resolves to the requested id.
    $this->getJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/inputs/{$this->inputA2->id}/edit")
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.id', $this->inputA2->id);
});

test('nested input update denies cross-widget mutation and never misbinds ids', function () {
    Sanctum::actingAs($this->allowedUser);

    $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/inputs/{$this->inputB1->id}", [
        'name' => 'hacked',
    ])
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.model.404'));

    // Neither the requested cross-widget child nor the positionally misbound
    // child (input id == widget id) may change.
    expect($this->inputB1->fresh()->name)->toBe('body');
    expect($this->inputA1->fresh()->name)->toBe('title');

    $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/inputs/{$this->inputA2->id}", [
        'name' => 'subtitle_renamed',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect($this->inputA2->fresh()->name)->toBe('subtitle_renamed');
});

test('nested input delete denies cross-widget mutation and never misbinds ids', function () {
    Sanctum::actingAs($this->allowedUser);

    $this->deleteJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/inputs/{$this->inputB1->id}")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.model.404'));

    expect(WidgetInput::query()->find($this->inputB1->id))->not->toBeNull();
    expect(WidgetInput::query()->find($this->inputA1->id))->not->toBeNull();
    expect(WidgetInput::query()->count())->toBe(3);

    $this->deleteJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetB->id}/inputs/{$this->inputB1->id}")
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect(WidgetInput::query()->find($this->inputB1->id))->toBeNull();
});

test('nested input store enforces route widget ownership', function () {
    Sanctum::actingAs($this->allowedUser);

    $this->postJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/inputs", [
        'widget_id' => $this->widgetB->id,
        'name' => 'smuggled_input',
        'type' => 'text',
    ])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');

    expect(WidgetInput::query()->where('name', 'smuggled_input')->exists())->toBeFalse();

    $this->postJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}/inputs", [
        'widget_id' => $this->widgetA->id,
        'name' => 'footer',
        'type' => 'text',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect(
        WidgetInput::query()->where('name', 'footer')->value('widget_id')
    )->toBe($this->widgetA->id);
});

test('nested child mutations still require the child permissions', function () {
    Sanctum::actingAs($this->deniedUser);

    $this->deleteJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetB->id}/template/{$this->themeB1->id}")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.no.permission'));
    expect(WidgetTheme::query()->find($this->themeB1->id))->not->toBeNull();

    $this->deleteJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetB->id}/inputs/{$this->inputB1->id}")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.no.permission'));
    expect(WidgetInput::query()->find($this->inputB1->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Top-level widget-template / widget-input compatibility routes: GET-only
// ---------------------------------------------------------------------------

test('top-level theme and input index routes stay readable', function () {
    Sanctum::actingAs($this->allowedUser);

    $this->getJson(WIDGET_ADMIN_BASE . '/widget-template')
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $this->getJson(WIDGET_ADMIN_BASE . '/widget-input')
        ->assertOk()
        ->assertJsonPath('status', 'success');
});

test('top-level theme and input routes reject mutation verbs', function () {
    Sanctum::actingAs($this->allowedUser);

    // The collection URIs still exist for GET, so mutating verbs are 405.
    // Unscoped member mutation URIs no longer exist in the API: they answer
    // 405 while the site-wide GET catch-all covers the URI, 404 otherwise.
    $this->postJson(WIDGET_ADMIN_BASE . '/widget-template', [
        'widget_id' => $this->widgetA->id,
        'name' => 'Top-level Theme',
        'content' => '<div>x</div>',
    ])->assertStatus(405);

    expect($this->putJson(WIDGET_ADMIN_BASE . "/widget-template/{$this->themeA1->id}", ['name' => 'X'])
        ->getStatusCode())->toBeIn([404, 405]);

    expect($this->deleteJson(WIDGET_ADMIN_BASE . "/widget-template/{$this->themeA1->id}")
        ->getStatusCode())->toBeIn([404, 405]);

    $this->postJson(WIDGET_ADMIN_BASE . '/widget-input', [
        'widget_id' => $this->widgetA->id,
        'name' => 'top_level_input',
        'type' => 'text',
    ])->assertStatus(405);

    expect($this->putJson(WIDGET_ADMIN_BASE . "/widget-input/{$this->inputA1->id}", ['name' => 'X'])
        ->getStatusCode())->toBeIn([404, 405]);

    expect($this->deleteJson(WIDGET_ADMIN_BASE . "/widget-input/{$this->inputA1->id}")
        ->getStatusCode())->toBeIn([404, 405]);

    // Nothing was created, renamed, or deleted through top-level verbs.
    expect(WidgetTheme::query()->count())->toBe(3);
    expect(WidgetInput::query()->count())->toBe(3);
    expect($this->themeA1->fresh()->name)->toBe('Theme A1');
    expect($this->inputA1->fresh()->name)->toBe('title');
});

// ---------------------------------------------------------------------------
// Gate 0 Task 3: aggregate widget update staged child deletion via
// deleted_template_ids / deleted_input_ids. Additive, optional fields on the
// PUT widget/{id} payload — omitting them preserves the legacy
// whole-array-replace behavior exactly. When present, deletion is validated
// (ownership + overlap + last-Template) and staged inside the same
// transaction as the rest of the Save, with exactly one post-commit sync.
// ---------------------------------------------------------------------------

test('aggregate widget update stages an explicit template deletion inside the transaction with one post-commit sync', function () {
    Sanctum::actingAs($this->allowedUser);

    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(0);

    $response = $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->themeA2->id, 'name' => 'Theme A2', 'content' => '<div>A2 kept</div>'],
        ],
        'deleted_template_ids' => [$this->themeA1->id],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.sync_status', 'ready');

    expect(WidgetTheme::query()->find($this->themeA1->id))->toBeNull();
    expect(WidgetTheme::query()->find($this->themeA2->id))->not->toBeNull();
});

test('aggregate widget update rejects deleted_template_ids not owned by the widget', function () {
    Sanctum::actingAs($this->allowedUser);

    $response = $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}", [
        'name' => 'Widget A Renamed',
        'template' => [
            ['id' => $this->themeA2->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>'],
        ],
        'deleted_template_ids' => [$this->themeB1->id],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');

    // Nothing mutated: cross-widget theme survives and widget A themes intact.
    expect(WidgetTheme::query()->find($this->themeB1->id))->not->toBeNull();
    expect(WidgetTheme::query()->find($this->themeA1->id))->not->toBeNull();
    expect(WidgetTheme::query()->find($this->themeA2->id))->not->toBeNull();
    // The parent attribute update ($model->save() runs before the deletion
    // validation throws) rolled back with the rest of the transaction.
    expect($this->widgetA->fresh()->name)->toBe('Widget A');
});

test('aggregate widget update rejects a template id present in both the upsert and deletion arrays', function () {
    Sanctum::actingAs($this->allowedUser);

    $response = $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->themeA1->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
        ],
        'deleted_template_ids' => [$this->themeA1->id],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');

    expect(WidgetTheme::query()->find($this->themeA1->id))->not->toBeNull();
    expect($this->themeA1->fresh()->name)->toBe('Theme A1');
});

test('aggregate widget update rejects a template deletion set that would leave zero themes', function () {
    Sanctum::actingAs($this->allowedUser);

    $response = $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}", [
        'name' => 'Widget A',
        'template' => [],
        'deleted_template_ids' => [$this->themeA1->id, $this->themeA2->id],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');

    expect(WidgetTheme::query()->find($this->themeA1->id))->not->toBeNull();
    expect(WidgetTheme::query()->find($this->themeA2->id))->not->toBeNull();
});

test('aggregate widget update rolls back a staged template deletion when a later child mutation fails', function () {
    Sanctum::actingAs($this->allowedUser);

    // Deletion of A1 is valid and staged first; the inputs upsert below then
    // fails because inputB1 does not belong to widget A, so the whole
    // transaction — including the already-executed template DELETE — must
    // roll back.
    $response = $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->themeA2->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>'],
        ],
        'deleted_template_ids' => [$this->themeA1->id],
        'inputs' => [
            ['id' => $this->inputB1->id, 'name' => 'hijacked', 'type' => 'text'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');

    // Rollback proof: the staged deletion never survives the commit.
    expect(WidgetTheme::query()->find($this->themeA1->id))->not->toBeNull();
    expect($this->inputB1->fresh()->name)->toBe('body');
});

test('aggregate widget update stages an explicit input deletion inside the transaction', function () {
    Sanctum::actingAs($this->allowedUser);

    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(0);

    $response = $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->themeA1->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
            ['id' => $this->themeA2->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>'],
        ],
        'deleted_input_ids' => [$this->inputA1->id],
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');

    expect(WidgetInput::query()->find($this->inputA1->id))->toBeNull();
    // inputA2 was never resubmitted and never listed for deletion — the
    // staged contract must leave it untouched (unlike the legacy
    // whole-array-replace behavior).
    expect(WidgetInput::query()->find($this->inputA2->id))->not->toBeNull();
});

test('aggregate widget update rejects deleted_input_ids not owned by the widget', function () {
    Sanctum::actingAs($this->allowedUser);

    $response = $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->themeA1->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
            ['id' => $this->themeA2->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>'],
        ],
        'deleted_input_ids' => [$this->inputB1->id],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');

    expect(WidgetInput::query()->find($this->inputB1->id))->not->toBeNull();
});

test('aggregate widget update rejects an input id present in both the upsert and deletion arrays', function () {
    Sanctum::actingAs($this->allowedUser);

    $response = $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->themeA1->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
            ['id' => $this->themeA2->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>'],
        ],
        'inputs' => [
            ['id' => $this->inputA1->id, 'name' => 'title', 'type' => 'text'],
        ],
        'deleted_input_ids' => [$this->inputA1->id],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');

    expect(WidgetInput::query()->find($this->inputA1->id))->not->toBeNull();
});

test('aggregate widget update preserves the legacy whole-array-replace behavior when deletion arrays are omitted', function () {
    Sanctum::actingAs($this->allowedUser);

    // Without deleted_template_ids, `template` keeps its required|min:1 rule
    // exactly as before — proving the additive fields changed nothing for
    // callers who never send them.
    $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}", [
        'name' => 'Widget A',
        'template' => [],
    ])->assertStatus(406);

    expect(WidgetTheme::query()->count())->toBe(3);
});

test('aggregate widget update still sweeps unsubmitted children when both deletion arrays are omitted', function () {
    Sanctum::actingAs($this->allowedUser);

    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(0);

    // Legacy whole-array-replace contract: with NEITHER deletion field
    // present, anything missing from the resubmitted arrays is deleted by
    // the whereNotIn sweep. This pins the staged-mode guards — the sweep
    // must keep firing for callers who never opt in.
    $response = $this->putJson(WIDGET_ADMIN_BASE . "/widget/{$this->widgetA->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->themeA2->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>'],
        ],
        'inputs' => [
            ['id' => $this->inputA1->id, 'name' => 'title', 'type' => 'text'],
        ],
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');

    // Unsubmitted children are swept...
    expect(WidgetTheme::query()->find($this->themeA1->id))->toBeNull();
    expect(WidgetInput::query()->find($this->inputA2->id))->toBeNull();
    // ...while the resubmitted ones survive.
    expect(WidgetTheme::query()->find($this->themeA2->id))->not->toBeNull();
    expect(WidgetInput::query()->find($this->inputA1->id))->not->toBeNull();
});

test('widget store rejects deleted_template_ids and deleted_input_ids as prohibited fields', function () {
    Sanctum::actingAs($this->allowedUser);

    $this->postJson(WIDGET_ADMIN_BASE . '/widget', [
        'name' => 'New Widget',
        'template' => [
            ['name' => 'Theme', 'content' => '<div>x</div>'],
        ],
        'deleted_template_ids' => [1],
    ])->assertStatus(406);

    $this->postJson(WIDGET_ADMIN_BASE . '/widget', [
        'name' => 'New Widget',
        'template' => [
            ['name' => 'Theme', 'content' => '<div>x</div>'],
        ],
        'deleted_input_ids' => [1],
    ])->assertStatus(406);

    expect(Widget::query()->where('name', 'New Widget')->exists())->toBeFalse();
});
