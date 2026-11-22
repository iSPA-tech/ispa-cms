<?php

declare(strict_types=1);

// Widget module API routes.
//
// Root/Core files must never route into Cms\Widget\* controllers directly
// (see docs/architecture/adr-widget-module-boundary.md).

use Cms\Widget\Http\Controllers\V2\Admin\WidgetInstanceController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetInstanceDraftRepinController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetLayoutBootstrapController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetLayoutDraftController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetLayoutPreviewController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetLayoutPublishController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetLayoutRollbackController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetManifestApprovalController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetManifestEligibilityCatalogController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetManifestMaterializationController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetManifestRuntimeBlockController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetPlacementContentController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetSlotCatalogController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetTargetContextCatalogController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetTargetCatalogController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetDefinitionCatalogController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetLayoutReleaseController;
use Cms\Widget\Http\Controllers\V2\Admin\WidgetRuntimeHealthController;
use Illuminate\Support\Facades\Route;

// Chunk 2 Task 15a: `/api/v2/admin/widget/*` admin surface (Task 12 frozen
// contract), auth:sanctum + api.admin per that contract's fixture and the
// established Achievements-module route-group precedent
// (cms/Achievements/routes/api.php). Permission itself
// (`widget_template.approve`) is checked explicitly inside the controller,
// not via route middleware, so a denial renders through this module's own
// envelope shape rather than Laravel's default authorization response.
Route::prefix('v2/admin/widget')
    ->middleware(['auth:sanctum', 'api.admin'])
    ->group(static function (): void {
        // Static routes registered BEFORE the /manifests/{manifest_hash}
        // wildcard below (Task 15a checklist item 6), so literal segments
        // ("materialize", and the bare collection GET) are never captured by
        // the wildcard. Task 15b adds the eligibility catalog inventory and
        // the approve/revoke transition routes.
        // Route 32 (disclosed addendum, routes 31->32): pure-read companion
        // for the browser materialize flow — the CURRENT source fingerprint
        // the client echoes back as expected_source_fingerprint.
        Route::get('catalog/manifests/source-fingerprint', [WidgetManifestMaterializationController::class, 'sourceFingerprint']);
        Route::post('catalog/manifests/materialize', [WidgetManifestMaterializationController::class, 'materialize']);
        Route::get('catalog/manifests', [WidgetManifestEligibilityCatalogController::class, 'index']);

        // Task 22: placement-selectable, approved-only Definition/Theme
        // catalog. A DIFFERENT literal first segment ("definitions") from
        // the "manifests" routes immediately above -- no ambiguity with
        // them regardless of declaration order. Static collection route
        // registered before the "{widget}" wildcard below, same
        // static-before-wildcard precedent used throughout this file.
        Route::get('catalog/definitions', [WidgetDefinitionCatalogController::class, 'index']);
        Route::get('catalog/definitions/{widget}', [WidgetDefinitionCatalogController::class, 'show']);

        Route::post('catalog/manifests/{manifest_hash}/approval', [WidgetManifestApprovalController::class, 'approve']);
        Route::delete('catalog/manifests/{manifest_hash}/approval', [WidgetManifestApprovalController::class, 'revoke']);

        // Task 15c: emergency runtime block/clear. Separate `{manifest_hash}`
        // wildcard segment from `/approval` above -- both are literal-suffix
        // wildcards (no ambiguity with the static `materialize`/bare-collection
        // routes registered first), so ordering relative to them is
        // unaffected; kept immediately after `/approval` for readability.
        Route::post('catalog/manifests/{manifest_hash}/runtime-block', [WidgetManifestRuntimeBlockController::class, 'block']);
        Route::delete('catalog/manifests/{manifest_hash}/runtime-block', [WidgetManifestRuntimeBlockController::class, 'clear']);

        // Task 16: Widget Instance identity + immutable Version CRUD. Static
        // action suffixes (/usage, /clone, /archive) are registered as their
        // own literal-suffix routes alongside the bare {uuid} wildcard --
        // same non-ambiguous shape as the manifest routes above (Laravel
        // matches the more specific literal segment first regardless of
        // declaration order here, but kept grouped for readability).
        Route::get('instances', [WidgetInstanceController::class, 'index']);
        Route::post('instances', [WidgetInstanceController::class, 'store']);
        Route::get('instances/{uuid}', [WidgetInstanceController::class, 'show']);
        Route::patch('instances/{uuid}', [WidgetInstanceController::class, 'update']);
        Route::delete('instances/{uuid}', [WidgetInstanceController::class, 'destroy']);
        Route::get('instances/{uuid}/usage', [WidgetInstanceController::class, 'usage']);
        Route::post('instances/{uuid}/clone', [WidgetInstanceController::class, 'clone']);
        Route::post('instances/{uuid}/archive', [WidgetInstanceController::class, 'archive']);

        // Task 17: Layout identity (list/bootstrap/detail). The literal
        // "by-key/{layout_key}" route is registered before the generic
        // "{uuid}" wildcard below -- same static-before-wildcard precedent
        // as the manifest routes above, so "by-key" can never be captured
        // as a {uuid} value regardless of Laravel's own disambiguation.
        Route::get('layouts', [WidgetLayoutBootstrapController::class, 'index']);
        Route::put('layouts/by-key/{layout_key}', [WidgetLayoutBootstrapController::class, 'bootstrap']);
        Route::get('layouts/{uuid}', [WidgetLayoutBootstrapController::class, 'show']);

        // Task 17: Draft Save -- owns Placement/scope/order only (never
        // Instance mutation, never repin: Task 18's own route).
        Route::put('layouts/{uuid}/draft', [WidgetLayoutDraftController::class, 'update']);

        // Backend addendum: disclosed post-freeze contract evolution
        // (routes 30->31; see docs/contracts/widget-site-layout-api.md).
        // Pure read companion returning the current Draft's
        // version/lock_version/placements[] so the admin canvas can reload
        // and safely edit a saved Draft -- the frozen contract previously
        // exposed only {version, lock_version} (WidgetLayoutResource) and
        // Draft Save itself is write-only. Same "layouts/{uuid}/draft" URI
        // as the PUT above -- HTTP method alone disambiguates them, no
        // route-order concern (Laravel matches on method+path together).
        Route::get('layouts/{uuid}/draft', [WidgetLayoutDraftController::class, 'show']);
        // Unified Widget Catalog Part 2 Task 5: the publish dialog's change list (pure read).
        Route::get('layouts/{uuid}/draft/changes', [WidgetLayoutDraftController::class, 'changes']);

        // Task 18: explicit shared-instance repin orchestration. Registered
        // after the static "layouts/{uuid}/draft" route above -- this
        // route's extra literal "instances/{instance_uuid}/versions-and-repin"
        // suffix is never ambiguous with it regardless of declaration order,
        // same non-ambiguous-wildcard reasoning as every other static-vs-
        // wildcard pairing in this file.
        Route::post('layouts/{uuid}/draft/instances/{instance_uuid}/versions-and-repin', [WidgetInstanceDraftRepinController::class, 'repin']);

        // Unified Widget Catalog Part 2, Task 3: each placement owns its content. Adding a
        // widget to an area creates its instance with it; editing content creates a new version
        // of that placement's instance; duplicating copies the instance. Literal suffixes, no
        // ambiguity with the routes above.
        Route::post('layouts/{uuid}/draft/placements', [WidgetPlacementContentController::class, 'store']);
        Route::patch('layouts/{uuid}/draft/placements/{placement_uuid}/content', [WidgetPlacementContentController::class, 'updateContent']);
        Route::post('layouts/{uuid}/draft/placements/{placement_uuid}/duplicate', [WidgetPlacementContentController::class, 'duplicate']);

        // Task 20: atomic publish -- Release promotion plus a fresh Draft,
        // both pointers switched in one transaction. Registered after the
        // static "layouts/{uuid}/draft..." routes above -- this route's own
        // literal "publish" suffix is never ambiguous with them regardless
        // of declaration order, same non-ambiguous-wildcard reasoning as
        // every other static-vs-wildcard pairing in this file.
        Route::post('layouts/{uuid}/publish', [WidgetLayoutPublishController::class, 'publish']);

        // Task 19: signed full-page preview session mint. Registered after
        // the static "layouts/{uuid}/draft..."/"publish" routes above --
        // this route's own literal "preview-sessions" suffix is never
        // ambiguous with them regardless of declaration order, same
        // non-ambiguous-wildcard reasoning as every other static-vs-
        // wildcard pairing in this file. The returned preview_url targets a
        // PUBLIC route through the `web` middleware group
        // (ResolveWidgetPreviewSession, pushed by WidgetServiceProvider),
        // never this admin-only group.
        Route::post('layouts/{uuid}/preview-sessions', [WidgetLayoutPreviewController::class, 'mint']);

        // Task 21: rollback-by-copy -- restores a historical Release's
        // Placements into a NEW Release without ever mutating the source.
        // Registered after the static "layouts/{uuid}/draft.../publish/
        // preview-sessions" routes above -- this route's own literal
        // "rollback" suffix is never ambiguous with them regardless of
        // declaration order, same non-ambiguous-wildcard reasoning as every
        // other static-vs-wildcard pairing in this file.
        Route::post('layouts/{uuid}/rollback', [WidgetLayoutRollbackController::class, 'rollback']);

        // Task 22: Release history for a Layout. Registered after every
        // other static "layouts/{uuid}/..." suffix above -- this route's
        // own literal "releases" suffix is never ambiguous with them
        // regardless of declaration order, same non-ambiguous-wildcard
        // reasoning as every other static-vs-wildcard pairing in this file.
        Route::get('layouts/{uuid}/releases', [WidgetLayoutReleaseController::class, 'index']);

        // Task 22: authorized Slot / Target Context catalogs (code-registered
        // in-memory registries, never database tables), generic target
        // search/detail (dispatches the Task 7 typed resolver), and the
        // aggregate runtime health report. "target-contexts" static
        // collection route registered before its own "{type}/targets..."
        // wildcards below, same static-before-wildcard precedent used
        // throughout this file.
        Route::get('slots', [WidgetSlotCatalogController::class, 'index']);
        Route::get('target-contexts', [WidgetTargetContextCatalogController::class, 'index']);
        Route::get('target-contexts/{type}/targets', [WidgetTargetCatalogController::class, 'search']);
        Route::get('target-contexts/{type}/targets/{target_id}', [WidgetTargetCatalogController::class, 'show']);
        Route::get('health', [WidgetRuntimeHealthController::class, 'index']);
    });
