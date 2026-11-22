<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Chunk 1 Task 9b contract tests: trust authorization and the legacy
 * mutation guard.
 *
 * Proves two independent gates on the legacy V2 Admin Widget surface:
 *
 * 1. Trust boundary (WidgetTrustAuthorizer, FR-14): adding, changing,
 *    clearing, or DELETING an executable field (widgets.js;
 *    widget_themes.content/css) requires widget.trust /
 *    widget_theme.trust on EVERY mutation path — standalone theme
 *    store/update/destroy, the aggregate widget update's existing-row
 *    edits, brand-new nested rows, staged deletion arrays, the legacy
 *    whole-array-replace sweep, and widget destroy (including its
 *    cascadeOnDelete removal of content-bearing Theme rows). There are
 *    no exempt paths: the same row must be denied identically whether
 *    reached via the nested PUT or the aggregate PUT. Non-executable
 *    edits (name, description, query, widget_inputs) never require
 *    trust, proven with a fixture user who holds ordinary CRUD
 *    permissions but NOT trust. WidgetAdminContractTest.php's
 *    permissioned fixture holds the full seeded convention INCLUDING
 *    trust (Task 9b fixture evolution), so its characterization
 *    assertions coexist with these denial contracts.
 *
 * 2. Mutation guard (WidgetMutationGuard, Chunk 1 Task 7 contract): every
 *    legacy mutation path consults it before writing and fails closed for
 *    a persisted safe-import lock marker, independent of trust. The
 *    marker column does not exist on any table yet, so these tests add it
 *    at runtime — safe ONLY because the suite runs on sqlite :memory:
 *    (RefreshDatabase gives every test its own schema); see the identical,
 *    documented caveat in tests/Feature/Widget/WidgetModuleOffCompatibilityTest.php.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Database\Seeders\PermissionTableSeed;
use Cms\Core\Database\Seeders\RoleTableSeed;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\WidgetTrustAuthorizer;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(TestCase::class, RefreshDatabase::class);

const WIDGET_TRUST_ADMIN_BASE = '/api/v2/admin';

beforeEach(function () {
    // HttpsProtocol middleware redirects plain-http requests outside the
    // local environment; keep the full middleware stack by requesting HTTPS.
    URL::forceScheme('https');

    $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-trust-' . uniqid('', true);
    File::ensureDirectoryExists($this->isolatedStoragePath . '/app/generated_widgets');
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
    $widgetTrustId = Permission::query()->create([
        'group' => 'widget',
        'name' => 'trust',
        'title' => 'widget trust',
    ])->id;
    $themeTrustId = Permission::query()->create([
        'group' => 'widget_theme',
        'name' => 'trust',
        'title' => 'widget_theme trust',
    ])->id;

    // Untrusted: full CRUD on widget/widget_theme/widget_input, no trust —
    // the actor every trust DENIAL below is proven against.
    $this->untrustedUser = User::query()->create([
        'name' => 'Widget Editor',
        'email' => 'widget-editor@test.local',
        'password' => Hash::make('password'),
    ]);
    $this->untrustedUser->forceFill(['type' => UserType::ADMIN])->save();
    $untrustedRole = Role::query()->create(['name' => 'widget-editor', 'user_id' => $this->untrustedUser->id]);
    $untrustedRole->permissions()->attach($permissionIds);
    $this->untrustedUser->roles()->attach($untrustedRole->id);

    // Trusted: identical CRUD set plus both widget.trust / widget_theme.trust.
    $this->trustedUser = User::query()->create([
        'name' => 'Widget Trusted Editor',
        'email' => 'widget-trusted@test.local',
        'password' => Hash::make('password'),
    ]);
    $this->trustedUser->forceFill(['type' => UserType::ADMIN])->save();
    $trustedRole = Role::query()->create(['name' => 'widget-trusted-editor', 'user_id' => $this->trustedUser->id]);
    $trustedRole->permissions()->attach([...$permissionIds, $widgetTrustId, $themeTrustId]);
    $this->trustedUser->roles()->attach($trustedRole->id);

    // Theme-trusted only: CRUD + widget_theme.trust but NOT widget.trust —
    // isolates the widgets.js gate from the theme content gate on paths
    // (like store) where both can fire.
    $this->themeTrustedUser = User::query()->create([
        'name' => 'Widget Theme-Trusted Editor',
        'email' => 'widget-theme-trusted@test.local',
        'password' => Hash::make('password'),
    ]);
    $this->themeTrustedUser->forceFill(['type' => UserType::ADMIN])->save();
    $themeTrustedRole = Role::query()->create(['name' => 'widget-theme-trusted-editor', 'user_id' => $this->themeTrustedUser->id]);
    $themeTrustedRole->permissions()->attach([...$permissionIds, $themeTrustId]);
    $this->themeTrustedUser->roles()->attach($themeTrustedRole->id);

    $this->widget = Widget::query()->create(['name' => 'Widget A']);
    $this->theme = WidgetTheme::query()->create([
        'widget_id' => $this->widget->id,
        'name' => 'Theme A1',
        'content' => '<div>A1</div>',
    ]);
});

afterEach(function () {
    if (!empty($this->isolatedStoragePath)) {
        File::deleteDirectory($this->isolatedStoragePath);
    }
});

// ---------------------------------------------------------------------------
// Pure unit-level WidgetTrustAuthorizer semantics — no DB, no HTTP.
// ---------------------------------------------------------------------------

test('widgetJsTouched ignores a field absent from the payload', function () {
    $authorizer = new WidgetTrustAuthorizer();

    expect($authorizer->widgetJsTouched('console.log(1)', ['name' => 'x']))->toBeFalse();
});

test('widgetJsTouched is a no-op between two empty values', function () {
    $authorizer = new WidgetTrustAuthorizer();

    expect($authorizer->widgetJsTouched(null, ['js' => '']))->toBeFalse();
    expect($authorizer->widgetJsTouched('', ['js' => null]))->toBeFalse();
});

test('widgetJsTouched detects add, change, and clear transitions but not a no-op resubmit', function () {
    $authorizer = new WidgetTrustAuthorizer();

    expect($authorizer->widgetJsTouched(null, ['js' => 'alert(1)']))->toBeTrue();       // add
    expect($authorizer->widgetJsTouched('alert(1)', ['js' => 'alert(2)']))->toBeTrue();  // change
    expect($authorizer->widgetJsTouched('alert(1)', ['js' => null]))->toBeTrue();        // clear
    expect($authorizer->widgetJsTouched('alert(1)', ['js' => 'alert(1)']))->toBeFalse(); // no-op
});

test('widgetDeletionTouchesJs is true only for a non-empty persisted value', function () {
    $authorizer = new WidgetTrustAuthorizer();

    expect($authorizer->widgetDeletionTouchesJs(null))->toBeFalse();
    expect($authorizer->widgetDeletionTouchesJs(''))->toBeFalse();
    expect($authorizer->widgetDeletionTouchesJs('alert(1)'))->toBeTrue();
});

test('newThemeRowTouchesExecutable is true when either content or css is non-empty', function () {
    $authorizer = new WidgetTrustAuthorizer();

    expect($authorizer->newThemeRowTouchesExecutable(null, null))->toBeFalse();
    expect($authorizer->newThemeRowTouchesExecutable('', ''))->toBeFalse();
    expect($authorizer->newThemeRowTouchesExecutable('<div>x</div>', null))->toBeTrue();
    expect($authorizer->newThemeRowTouchesExecutable(null, '.a{}'))->toBeTrue();
});

test('themeDeletionTouchesExecutable is true only when persisted content or css is non-empty', function () {
    $authorizer = new WidgetTrustAuthorizer();

    expect($authorizer->themeDeletionTouchesExecutable(null, null))->toBeFalse();
    expect($authorizer->themeDeletionTouchesExecutable('', ''))->toBeFalse();
    expect($authorizer->themeDeletionTouchesExecutable('<div>x</div>', null))->toBeTrue();
    expect($authorizer->themeDeletionTouchesExecutable('', '.a{}'))->toBeTrue();
});

test('themeFieldsTouched only considers keys actually present in the payload', function () {
    $authorizer = new WidgetTrustAuthorizer();

    expect($authorizer->themeFieldsTouched('<div>a</div>', null, ['name' => 'x']))->toBeFalse();
    expect($authorizer->themeFieldsTouched('<div>a</div>', null, ['content' => '<div>b</div>']))->toBeTrue();
    expect($authorizer->themeFieldsTouched('<div>a</div>', null, ['content' => '<div>a</div>']))->toBeFalse();
    expect($authorizer->themeFieldsTouched('<div>a</div>', '.a{}', ['css' => null]))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Trust boundary: WidgetController@store. FR-14: CREATING executable
// content is gated exactly like changing it — non-empty js requires
// widget.trust and any content-bearing template row requires
// widget_theme.trust. Empty js stays ungated. A template row with EMPTY
// content is unreachable here: StoreWidgetRequest's required rule answers
// 406 before the trust gate is ever consulted (proven below).
// ---------------------------------------------------------------------------

test('widget store denies creating a widget with js without widget.trust', function () {
    // Actor holds widget_theme.trust (covering the template content) but
    // NOT widget.trust — the denial is attributable to the js gate alone.
    Sanctum::actingAs($this->themeTrustedUser);

    $response = $this->postJson(WIDGET_TRUST_ADMIN_BASE . '/widget', [
        'name' => 'JS Widget',
        'js' => 'alert(1)',
        'template' => [
            ['name' => 'Theme', 'content' => '<div>x</div>'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error')->assertJsonPath('message', __('mgs.no.permission'));
    expect(Widget::query()->where('name', 'JS Widget')->exists())->toBeFalse();
});

test('widget store allows creating a widget with js when the actor holds widget.trust', function () {
    Sanctum::actingAs($this->trustedUser);

    $response = $this->postJson(WIDGET_TRUST_ADMIN_BASE . '/widget', [
        'name' => 'Trusted JS Widget',
        'js' => 'console.log(1)',
        'template' => [
            ['name' => 'Theme', 'content' => '<div>x</div>'],
        ],
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');
    expect(Widget::query()->where('name', 'Trusted JS Widget')->value('js'))->toBe('console.log(1)');
});

test('widget store denies a content-bearing template row without widget_theme.trust', function () {
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->postJson(WIDGET_TRUST_ADMIN_BASE . '/widget', [
        'name' => 'Content Widget',
        'template' => [
            ['name' => 'Theme', 'content' => '<div>injected</div>'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error')->assertJsonPath('message', __('mgs.no.permission'));
    expect(Widget::query()->where('name', 'Content Widget')->exists())->toBeFalse();
});

test('widget store with empty js stays ungated by widget.trust', function () {
    // Empty variant: js '' is a no-op (never gated); the content-bearing
    // template row is covered by the actor's widget_theme.trust. No
    // widget.trust needed.
    Sanctum::actingAs($this->themeTrustedUser);

    $response = $this->postJson(WIDGET_TRUST_ADMIN_BASE . '/widget', [
        'name' => 'No JS Widget',
        'js' => '',
        'template' => [
            ['name' => 'Theme', 'content' => '<div>x</div>'],
        ],
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');
    expect(Widget::query()->where('name', 'No JS Widget')->exists())->toBeTrue();
});

test('widget store with empty template content is rejected by validation before the trust gate', function () {
    // Characterizes why there is no "empty-content store allowed" case:
    // StoreWidgetRequest requires template.*.content, so the request dies
    // as a 406 validation failure — not a 400 trust denial — and no trust
    // permission could change that.
    Sanctum::actingAs($this->untrustedUser);

    $this->postJson(WIDGET_TRUST_ADMIN_BASE . '/widget', [
        'name' => 'Empty Content Widget',
        'template' => [
            ['name' => 'Theme', 'content' => ''],
        ],
    ])->assertStatus(406);

    expect(Widget::query()->where('name', 'Empty Content Widget')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Trust boundary: WidgetController@update — widgets.js (aggregate, direct).
// ---------------------------------------------------------------------------

test('widget update denies adding js without widget.trust', function () {
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'js' => 'alert(1)',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error')->assertJsonPath('message', __('mgs.no.permission'));
    expect($this->widget->fresh()->js)->toBeNull();
});

test('widget update allows adding js when the actor holds widget.trust', function () {
    Sanctum::actingAs($this->trustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'js' => 'alert(1)',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
        ],
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');
    expect($this->widget->fresh()->js)->toBe('alert(1)');
});

test('widget update denies changing an existing non-empty js without widget.trust', function () {
    $this->widget->forceFill(['js' => 'console.log(1)'])->save();
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'js' => 'console.log(2)',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect($this->widget->fresh()->js)->toBe('console.log(1)');
});

test('widget update denies clearing an existing non-empty js without widget.trust', function () {
    $this->widget->forceFill(['js' => 'console.log(1)'])->save();
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'js' => null,
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect($this->widget->fresh()->js)->toBe('console.log(1)');
});

test('widget update allows resubmitting the same js value without widget.trust', function () {
    $this->widget->forceFill(['js' => 'console.log(1)'])->save();
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A Renamed',
        'js' => 'console.log(1)',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
        ],
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');
    expect($this->widget->fresh()->name)->toBe('Widget A Renamed');
});

// Explicit Task 9b acceptance criterion: a user with widget.update but NOT
// widget.trust can still edit non-executable fields (name/query) but never
// js/content/css.
test('a user with widget.update but not widget.trust can edit name and query but never js', function () {
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A Renamed',
        'description' => 'updated description',
        'template' => [
            [
                'id' => $this->theme->id,
                'name' => 'Theme A1',
                'content' => '<div>A1</div>',
                'query' => [['name' => 'limit', 'value' => '5']],
            ],
        ],
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');
    expect($this->widget->fresh()->name)->toBe('Widget A Renamed');
    expect($this->widget->fresh()->description)->toBe('updated description');
    expect($this->widget->fresh()->js)->toBeNull();
    expect($this->theme->fresh()->query)->toBe([['name' => 'limit', 'value' => '5']]);
});

// ---------------------------------------------------------------------------
// Trust boundary: WidgetController@update — brand-new nested Theme row.
// ---------------------------------------------------------------------------

test('widget update denies adding a new theme row with content without widget_theme.trust', function () {
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
            ['name' => 'Theme A2 New', 'content' => '<div>injected</div>'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect(WidgetTheme::query()->where('name', 'Theme A2 New')->exists())->toBeFalse();
    // Rollback proof: the pre-existing theme is untouched too.
    expect($this->theme->fresh()->name)->toBe('Theme A1');
});

test('widget update allows adding a new theme row with content when the actor holds widget_theme.trust', function () {
    Sanctum::actingAs($this->trustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
            ['name' => 'Theme A2 New', 'content' => '<div>trusted</div>'],
        ],
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');
    expect(WidgetTheme::query()->where('name', 'Theme A2 New')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Trust boundary: WidgetController@update — EXISTING nested Theme row edits
// and every deletion path (staged arrays + legacy sweep). FR-14: the same
// row must be denied via the aggregate PUT exactly as it is via the nested
// PUT — no bypass.
// ---------------------------------------------------------------------------

test('aggregate update denies changing an existing theme row content without widget_theme.trust', function () {
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>changed via aggregate</div>'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error')->assertJsonPath('message', __('mgs.no.permission'));
    expect($this->theme->fresh()->content)->toBe('<div>A1</div>');
});

test('aggregate update allows changing an existing theme row content when the actor holds widget_theme.trust', function () {
    Sanctum::actingAs($this->trustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>changed via aggregate</div>'],
        ],
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');
    expect($this->theme->fresh()->content)->toBe('<div>changed via aggregate</div>');
});

test('aggregate update denies adding css to an existing theme row without widget_theme.trust', function () {
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>', 'css' => '.injected{}'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect($this->theme->fresh()->css)->toBeNull();
});

test('aggregate update denies a staged deletion of a content-bearing theme without widget_theme.trust', function () {
    $keeperTheme = WidgetTheme::query()->create([
        'widget_id' => $this->widget->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>',
    ]);
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $keeperTheme->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>'],
        ],
        'deleted_template_ids' => [$this->theme->id],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect(WidgetTheme::query()->find($this->theme->id))->not->toBeNull();
});

test('aggregate update denies the legacy sweep deletion of a content-bearing theme without widget_theme.trust', function () {
    $keeperTheme = WidgetTheme::query()->create([
        'widget_id' => $this->widget->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>',
    ]);
    Sanctum::actingAs($this->untrustedUser);

    // No deletion arrays: the legacy whole-array-replace sweep would
    // delete the unsubmitted, content-bearing themeA1 — denied sans trust.
    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $keeperTheme->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect(WidgetTheme::query()->find($this->theme->id))->not->toBeNull();
});

test('aggregate update allows the sweep deletion of an empty-content theme without widget_theme.trust', function () {
    $emptyTheme = WidgetTheme::query()->create([
        'widget_id' => $this->widget->id, 'name' => 'Theme Empty', 'content' => '',
    ]);
    Sanctum::actingAs($this->untrustedUser);

    // The unsubmitted swept row carries no executable content, so no
    // trust is needed; the resubmitted content-bearing row is a no-op.
    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
        ],
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');
    expect(WidgetTheme::query()->find($emptyTheme->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Trust boundary: WidgetController@destroy — widgets.js.
// ---------------------------------------------------------------------------

test('widget destroy denies deleting a widget with non-empty js without widget.trust', function () {
    $this->widget->forceFill(['js' => 'console.log(1)'])->save();
    Sanctum::actingAs($this->untrustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error');

    expect(Widget::query()->find($this->widget->id))->not->toBeNull();
});

test('widget destroy allows deleting a widget with non-empty js when the actor holds widget.trust', function () {
    $this->widget->forceFill(['js' => 'console.log(1)'])->save();
    Sanctum::actingAs($this->trustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}")
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect(Widget::query()->find($this->widget->id))->toBeNull();
});

test('widget destroy denies cascading a content-bearing theme without widget_theme.trust even when js is empty', function () {
    // FR-14 cascade rule: this widget's js is empty (no widget.trust
    // needed) but destroying it cascade-removes themeA1, whose content is
    // non-empty — that deletion of executable content requires
    // widget_theme.trust, closing the destroy-the-parent bypass of the
    // per-theme deletion gate.
    Sanctum::actingAs($this->untrustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.no.permission'));

    expect(Widget::query()->find($this->widget->id))->not->toBeNull();
    expect(WidgetTheme::query()->find($this->theme->id))->not->toBeNull();
});

test('widget destroy allows deleting a widget whose js and cascaded themes are all empty without trust', function () {
    $emptyWidget = Widget::query()->create(['name' => 'Widget Empty']);
    WidgetTheme::query()->create([
        'widget_id' => $emptyWidget->id, 'name' => 'Theme Empty', 'content' => '',
    ]);
    Sanctum::actingAs($this->untrustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$emptyWidget->id}")
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect(Widget::query()->find($emptyWidget->id))->toBeNull();
});

test('widget destroy allows cascading content-bearing themes when the actor holds widget_theme.trust', function () {
    // js empty, so only the cascade gate applies; trustedUser holds
    // widget_theme.trust and the deletion proceeds.
    Sanctum::actingAs($this->trustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}")
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect(Widget::query()->find($this->widget->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Trust boundary: WidgetThemeController@update — content/css (standalone).
// ---------------------------------------------------------------------------

test('theme update denies changing content without widget_theme.trust', function () {
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/template/{$this->theme->id}", [
        'content' => '<div>changed</div>',
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error')->assertJsonPath('message', __('mgs.no.permission'));
    expect($this->theme->fresh()->content)->toBe('<div>A1</div>');
});

test('theme update allows changing content when the actor holds widget_theme.trust', function () {
    Sanctum::actingAs($this->trustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/template/{$this->theme->id}", [
        'content' => '<div>changed</div>',
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');
    expect($this->theme->fresh()->content)->toBe('<div>changed</div>');
});

test('theme update denies adding css without widget_theme.trust', function () {
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/template/{$this->theme->id}", [
        'css' => '.injected { color: red; }',
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect($this->theme->fresh()->css)->toBeNull();
});

test('theme update denies clearing an existing non-empty css without widget_theme.trust', function () {
    $this->theme->forceFill(['css' => '.a { color: blue; }'])->save();
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/template/{$this->theme->id}", [
        'css' => null,
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect($this->theme->fresh()->css)->toBe('.a { color: blue; }');
});

test('theme update allows editing name without widget_theme.trust when content and css are untouched', function () {
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/template/{$this->theme->id}", [
        'name' => 'Theme A1 Renamed',
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');
    expect($this->theme->fresh()->name)->toBe('Theme A1 Renamed');
});

// ---------------------------------------------------------------------------
// Trust boundary: WidgetThemeController@store / @destroy (standalone).
// FR-14: creating a theme with executable content ADDS it; deleting a
// content-bearing theme REMOVES it — both require widget_theme.trust.
// ---------------------------------------------------------------------------

test('theme store denies creating a theme with content without widget_theme.trust', function () {
    Sanctum::actingAs($this->untrustedUser);

    $response = $this->postJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/template", [
        'widget_id' => $this->widget->id,
        'name' => 'Untrusted Theme',
        'content' => '<div>injected</div>',
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error')->assertJsonPath('message', __('mgs.no.permission'));
    expect(WidgetTheme::query()->where('name', 'Untrusted Theme')->exists())->toBeFalse();
});

test('theme store allows creating a theme with content when the actor holds widget_theme.trust', function () {
    Sanctum::actingAs($this->trustedUser);

    $response = $this->postJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/template", [
        'widget_id' => $this->widget->id,
        'name' => 'Trusted Theme',
        'content' => '<div>trusted</div>',
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');
    expect(WidgetTheme::query()->where('name', 'Trusted Theme')->value('widget_id'))->toBe($this->widget->id);
});

test('theme destroy denies deleting a content-bearing theme without widget_theme.trust', function () {
    Sanctum::actingAs($this->untrustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/template/{$this->theme->id}")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.no.permission'));

    expect(WidgetTheme::query()->find($this->theme->id))->not->toBeNull();
});

test('theme destroy allows deleting a content-bearing theme when the actor holds widget_theme.trust', function () {
    Sanctum::actingAs($this->trustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/template/{$this->theme->id}")
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect(WidgetTheme::query()->find($this->theme->id))->toBeNull();
});

test('theme destroy allows deleting an empty-content theme without widget_theme.trust', function () {
    $emptyTheme = WidgetTheme::query()->create([
        'widget_id' => $this->widget->id, 'name' => 'Theme Empty', 'content' => '',
    ]);
    Sanctum::actingAs($this->untrustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/template/{$emptyTheme->id}")
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect(WidgetTheme::query()->find($emptyTheme->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Mutation guard (Chunk 1 Task 7 contract): fails closed for a persisted
// safe-import lock marker, independent of trust — proven for a TRUSTED,
// fully-permissioned actor so the denial can only be attributed to the
// guard. Runtime ALTER TABLE is safe here ONLY on sqlite :memory:
// (RefreshDatabase gives every test its own schema); see the identical
// caveat in WidgetModuleOffCompatibilityTest.
// ---------------------------------------------------------------------------

test('widget update mutation guard denies a locked widget row even for a trusted user', function () {
    Schema::table('widgets', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    Widget::query()->whereKey($this->widget->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A Renamed',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error')->assertJsonPath('message', __('mgs.no.permission'));
    expect($this->widget->fresh()->name)->toBe('Widget A');
});

test('widget update mutation guard denies mutating a locked existing template row', function () {
    Schema::table('widget_themes', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    WidgetTheme::query()->whereKey($this->theme->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1 Renamed', 'content' => '<div>A1</div>'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect($this->theme->fresh()->name)->toBe('Theme A1');
});

test('widget update mutation guard denies a staged deletion of a locked template row', function () {
    $extraTheme = WidgetTheme::query()->create([
        'widget_id' => $this->widget->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>',
    ]);

    Schema::table('widget_themes', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    WidgetTheme::query()->whereKey($extraTheme->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
        ],
        'deleted_template_ids' => [$extraTheme->id],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect(WidgetTheme::query()->find($extraTheme->id))->not->toBeNull();
});

test('widget update mutation guard denies the legacy sweep deletion of a locked template row', function () {
    $extraTheme = WidgetTheme::query()->create([
        'widget_id' => $this->widget->id, 'name' => 'Theme A2', 'content' => '<div>A2</div>',
    ]);

    Schema::table('widget_themes', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    WidgetTheme::query()->whereKey($extraTheme->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    // No deletion arrays: the legacy whole-array-replace sweep would try to
    // delete $extraTheme (not resubmitted) — the guard must block it too.
    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect(WidgetTheme::query()->find($extraTheme->id))->not->toBeNull();
});

test('aggregate update mutation guard denies a staged deletion of a locked input row', function () {
    $input = WidgetInput::query()->create(['widget_id' => $this->widget->id, 'name' => 'title', 'type' => 'text']);

    Schema::table('widget_inputs', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    WidgetInput::query()->whereKey($input->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
        ],
        'deleted_input_ids' => [$input->id],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect(WidgetInput::query()->find($input->id))->not->toBeNull();
});

test('aggregate update mutation guard denies the legacy sweep deletion of a locked input row', function () {
    $lockedInput = WidgetInput::query()->create(['widget_id' => $this->widget->id, 'name' => 'title', 'type' => 'text']);
    $keeperInput = WidgetInput::query()->create(['widget_id' => $this->widget->id, 'name' => 'subtitle', 'type' => 'text']);

    Schema::table('widget_inputs', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    WidgetInput::query()->whereKey($lockedInput->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    // No deletion arrays: resubmitting only the keeper input makes the
    // legacy whole-array-replace sweep try to delete the locked,
    // unsubmitted input — the guard must block it.
    $response = $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}", [
        'name' => 'Widget A',
        'template' => [
            ['id' => $this->theme->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>'],
        ],
        'inputs' => [
            ['id' => $keeperInput->id, 'name' => 'subtitle', 'type' => 'text'],
        ],
    ]);

    $response->assertStatus(400)->assertJsonPath('status', 'error');
    expect(WidgetInput::query()->find($lockedInput->id))->not->toBeNull();
});

test('theme update mutation guard denies a locked theme row', function () {
    Schema::table('widget_themes', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    WidgetTheme::query()->whereKey($this->theme->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/template/{$this->theme->id}", [
        'name' => 'Theme A1 Renamed',
    ])->assertStatus(400)->assertJsonPath('status', 'error');

    expect($this->theme->fresh()->name)->toBe('Theme A1');
});

test('theme destroy mutation guard denies a locked theme row', function () {
    Schema::table('widget_themes', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    WidgetTheme::query()->whereKey($this->theme->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/template/{$this->theme->id}")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error');

    expect(WidgetTheme::query()->find($this->theme->id))->not->toBeNull();
});

test('widget destroy mutation guard denies a locked widget row', function () {
    Schema::table('widgets', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    Widget::query()->whereKey($this->widget->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('mgs.no.permission'));

    expect(Widget::query()->find($this->widget->id))->not->toBeNull();
});

test('widget destroy mutation guard denies a locked cascaded theme row under an unlocked widget', function () {
    // Only widget_themes carries the marker column here, so the widget
    // row itself stays unlocked — the denial can only come from the
    // guard cascade over the to-be-cascade-deleted theme rows.
    Schema::table('widget_themes', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    WidgetTheme::query()->whereKey($this->theme->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error');

    expect(Widget::query()->find($this->widget->id))->not->toBeNull();
    expect(WidgetTheme::query()->find($this->theme->id))->not->toBeNull();
});

test('widget destroy mutation guard denies a locked cascaded input row under an unlocked widget', function () {
    $input = WidgetInput::query()->create(['widget_id' => $this->widget->id, 'name' => 'title', 'type' => 'text']);

    Schema::table('widget_inputs', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    WidgetInput::query()->whereKey($input->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error');

    expect(Widget::query()->find($this->widget->id))->not->toBeNull();
    expect(WidgetInput::query()->find($input->id))->not->toBeNull();
});

test('input update mutation guard denies a locked input row', function () {
    $input = WidgetInput::query()->create(['widget_id' => $this->widget->id, 'name' => 'title', 'type' => 'text']);

    Schema::table('widget_inputs', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    WidgetInput::query()->whereKey($input->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/inputs/{$input->id}", [
        'name' => 'title_renamed',
    ])->assertStatus(400)->assertJsonPath('status', 'error');

    expect($input->fresh()->name)->toBe('title');
});

test('input destroy mutation guard denies a locked input row', function () {
    $input = WidgetInput::query()->create(['widget_id' => $this->widget->id, 'name' => 'title', 'type' => 'text']);

    Schema::table('widget_inputs', function ($table) {
        $table->timestamp('safe_import_locked_at')->nullable();
    });
    WidgetInput::query()->whereKey($input->id)->update(['safe_import_locked_at' => now()]);

    Sanctum::actingAs($this->trustedUser);

    $this->deleteJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/inputs/{$input->id}")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error');

    expect(WidgetInput::query()->find($input->id))->not->toBeNull();
});

test('input update mutation guard permits an ordinary unmarked input row', function () {
    $input = WidgetInput::query()->create(['widget_id' => $this->widget->id, 'name' => 'title', 'type' => 'text']);

    Sanctum::actingAs($this->trustedUser);

    $this->putJson(WIDGET_TRUST_ADMIN_BASE . "/widget/{$this->widget->id}/inputs/{$input->id}", [
        'name' => 'title_renamed',
    ])->assertOk()->assertJsonPath('status', 'success');

    expect($input->fresh()->name)->toBe('title_renamed');
});

// ---------------------------------------------------------------------------
// Seeder idempotency: run Permission then Role seeders twice — Webmaster
// receives both trust permissions with no duplicate Permission rows.
// ---------------------------------------------------------------------------

test('permission and role seeders are idempotent and grant Webmaster both trust permissions without duplicates', function () {
    (new PermissionTableSeed())->run();
    (new RoleTableSeed())->run();

    $widgetTrustCount = fn () => Permission::query()->where('group', 'widget')->where('name', 'trust')->count();
    $themeTrustCount = fn () => Permission::query()->where('group', 'widget_theme')->where('name', 'trust')->count();

    expect($widgetTrustCount())->toBe(1);
    expect($themeTrustCount())->toBe(1);

    $totalAfterFirstRun = Permission::query()->count();

    $webmaster = Role::query()->where('name', 'Webmaster')->firstOrFail();
    $widgetTrustPermission = Permission::query()->where('group', 'widget')->where('name', 'trust')->firstOrFail();
    $themeTrustPermission = Permission::query()->where('group', 'widget_theme')->where('name', 'trust')->firstOrFail();

    expect($webmaster->permissions()->whereKey($widgetTrustPermission->id)->count())->toBe(1);
    expect($webmaster->permissions()->whereKey($themeTrustPermission->id)->count())->toBe(1);

    // Run BOTH seeders a second time: additive/idempotent, never detaches
    // unrelated permissions, never duplicates a row, Webmaster keeps
    // exactly one of each trust permission.
    (new PermissionTableSeed())->run();
    (new RoleTableSeed())->run();

    expect(Permission::query()->count())->toBe($totalAfterFirstRun);
    expect($widgetTrustCount())->toBe(1);
    expect($themeTrustCount())->toBe(1);
    expect(Role::query()->where('name', 'Webmaster')->count())->toBe(1);

    $webmaster->refresh();
    expect($webmaster->permissions()->whereKey($widgetTrustPermission->id)->count())->toBe(1);
    expect($webmaster->permissions()->whereKey($themeTrustPermission->id)->count())->toBe(1);
});
