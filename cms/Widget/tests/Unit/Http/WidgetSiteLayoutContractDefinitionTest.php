<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Unit\Http;

use App\Contracts\Widget\DTO\AuthorizedProjectContext;
use Cms\Widget\Http\Resources\V2\Admin\WidgetArtifactManifestResource;
use Cms\Widget\Http\Resources\V2\Admin\WidgetDefinitionCatalogResource;
use Cms\Widget\Http\Resources\V2\Admin\WidgetInstanceResource;
use Cms\Widget\Http\Resources\V2\Admin\WidgetLayoutDraftResource;
use Cms\Widget\Http\Resources\V2\Admin\WidgetLayoutReleaseResource;
use Cms\Widget\Http\Resources\V2\Admin\WidgetLayoutResource;
use Cms\Widget\Http\Resources\V2\Admin\WidgetPlacementResource;
use Cms\Widget\Http\Resources\V2\Admin\WidgetSlotResource;
use Cms\Widget\Http\Resources\V2\Admin\WidgetTargetContextResource;
use Cms\Widget\Http\Resources\V2\Admin\WidgetTargetResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Cms\Widget\SiteLayout\Drafts\WidgetDraftUsageFingerprint;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

/**
 * Chunk 1 Task 12: passing static contract validator BEFORE CRUD (plan
 * lines 646-687; architecture.md "Frozen API, Error, and Status Contract";
 * adr-widget-mutation-idempotency.md; adr-widget-project-authorization.md;
 * adr-widget-layout-lifecycle.md; adr-widget-provider-lifecycle.md).
 *
 * This test calls NO endpoint (none exist yet -- no route file, no
 * controller, no WidgetServiceProvider change lands in this task). It
 * proves the machine fixture
 * (cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json) is
 * complete and internally consistent, and cross-checks it against the PHP
 * classes and the i18n sidecar this task also freezes.
 *
 * DISCLOSED DEVIATION: architecture.md's literal API surface block (lines
 * 698-727) lists 30 distinct method+path routes, not the 28 the plan
 * task's summary counts. This test freezes the full 30-route set exactly
 * as architecture.md lists it, per the task's own instruction: "READ
 * architecture.md's API surface block first -- it IS the URL contract."
 * See docs/contracts/widget-site-layout-api.md for the full discrepancy
 * note.
 *
 * BACKEND ADDENDUM CONTRACT EVOLUTION (routes 30->31, disclosed and
 * user-approved): `GET /layouts/{uuid}/draft` was added after the original
 * freeze so the base-admin canvas can reload and safely edit a saved
 * Draft -- the frozen contract previously exposed a Draft's
 * `{version, lock_version}` (via `WidgetLayoutResource.current_draft`) but
 * never its `placements[]`, and Draft Save (`PUT .../draft`) is write-only.
 * architecture.md's own "Module API surface" code block (lines 698-727)
 * was updated in lockstep with this route so the transcription below stays
 * a true independent copy, not a stale one. See
 * docs/contracts/widget-site-layout-api.md's own matching evolution note
 * for the full disclosure.
 */
final class WidgetSiteLayoutContractDefinitionTest extends TestCase
{
    private const FIXTURE_PATH = 'cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json';
    private const I18N_PATH = 'cms/Widget/database/seeders/exported/translates-admin-widget-site-layout.php';

    /**
     * The exact 36 method+path routes transcribed verbatim from
     * architecture.md lines 698-728 ("Module API surface"; 30 from the
     * original Task 12 freeze plus the disclosed `GET .../draft` backend
     * addendum, plus the disclosed `GET /catalog/manifests/source-fingerprint`
     * pure-read companion for the browser materialize flow, routes 31->32, plus the
     * three placement content routes of Unified Widget Catalog Part 2 Task 3, routes 32->35, plus the
     * draft change list of Part 2 Task 5, routes 35->36). This is the independent source-of-truth list the fixture
     * is checked against, so a typo in the fixture cannot silently
     * self-validate.
     *
     * @return list<array{method: string, path: string}>
     */
    private function architectureRoutes(): array
    {
        return [
            ['method' => 'GET', 'path' => '/slots'],
            ['method' => 'GET', 'path' => '/target-contexts'],
            ['method' => 'GET', 'path' => '/target-contexts/{type}/targets'],
            ['method' => 'GET', 'path' => '/target-contexts/{type}/targets/{target_id}'],
            ['method' => 'GET', 'path' => '/health'],
            ['method' => 'GET', 'path' => '/catalog/definitions'],
            ['method' => 'GET', 'path' => '/catalog/definitions/{widget}'],
            ['method' => 'GET', 'path' => '/catalog/manifests'],
            ['method' => 'GET', 'path' => '/catalog/manifests/source-fingerprint'],
            ['method' => 'POST', 'path' => '/catalog/manifests/materialize'],
            ['method' => 'POST', 'path' => '/catalog/manifests/{manifest_hash}/approval'],
            ['method' => 'DELETE', 'path' => '/catalog/manifests/{manifest_hash}/approval'],
            ['method' => 'POST', 'path' => '/catalog/manifests/{manifest_hash}/runtime-block'],
            ['method' => 'DELETE', 'path' => '/catalog/manifests/{manifest_hash}/runtime-block'],
            ['method' => 'GET', 'path' => '/instances'],
            ['method' => 'POST', 'path' => '/instances'],
            ['method' => 'GET', 'path' => '/instances/{uuid}'],
            ['method' => 'PATCH', 'path' => '/instances/{uuid}'],
            ['method' => 'DELETE', 'path' => '/instances/{uuid}'],
            ['method' => 'GET', 'path' => '/instances/{uuid}/usage'],
            ['method' => 'POST', 'path' => '/instances/{uuid}/clone'],
            ['method' => 'POST', 'path' => '/instances/{uuid}/archive'],
            ['method' => 'GET', 'path' => '/layouts'],
            ['method' => 'PUT', 'path' => '/layouts/by-key/{layout_key}'],
            ['method' => 'GET', 'path' => '/layouts/{uuid}'],
            ['method' => 'PUT', 'path' => '/layouts/{uuid}/draft'],
            ['method' => 'GET', 'path' => '/layouts/{uuid}/draft'],
            ['method' => 'POST', 'path' => '/layouts/{uuid}/draft/instances/{instance_uuid}/versions-and-repin'],
            ['method' => 'POST', 'path' => '/layouts/{uuid}/draft/placements'],
            ['method' => 'PATCH', 'path' => '/layouts/{uuid}/draft/placements/{placement_uuid}/content'],
            ['method' => 'POST', 'path' => '/layouts/{uuid}/draft/placements/{placement_uuid}/duplicate'],
            ['method' => 'GET', 'path' => '/layouts/{uuid}/draft/changes'],
            ['method' => 'GET', 'path' => '/layouts/{uuid}/releases'],
            ['method' => 'POST', 'path' => '/layouts/{uuid}/preview-sessions'],
            ['method' => 'POST', 'path' => '/layouts/{uuid}/publish'],
            ['method' => 'POST', 'path' => '/layouts/{uuid}/rollback'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = base_path(self::FIXTURE_PATH);
        $this->assertFileExists($path, 'Frozen fixture must exist: ' . self::FIXTURE_PATH);

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, 'Fixture must decode to an array.');

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function i18nRows(): array
    {
        $path = base_path(self::I18N_PATH);
        $this->assertFileExists($path, 'Frozen i18n sidecar must exist: ' . self::I18N_PATH);

        $rows = require $path;
        $this->assertIsArray($rows, 'i18n sidecar must return an array.');

        return $rows;
    }

    // -----------------------------------------------------------------
    // Fixture shape
    // -----------------------------------------------------------------

    public function test_fixture_file_exists_and_declares_every_required_top_level_section(): void
    {
        $fixture = $this->fixture();

        foreach (['status_enum', 'pagination', 'routes', 'resources', 'errors', 'permissions', 'core_owned_references', 'idempotency', 'i18n'] as $section) {
            $this->assertArrayHasKey($section, $fixture, "Fixture must declare top-level section '{$section}'.");
        }

        $this->assertSame([200, 201, 403, 404, 409, 422, 500, 503], $fixture['status_enum'], 'Status enum must be the exact closed set (never 202/410/401).');
    }

    // -----------------------------------------------------------------
    // Routes: exact 30-route surface, no extras, no omissions
    // -----------------------------------------------------------------

    public function test_fixture_declares_exactly_the_thirty_six_architecture_routes_with_no_extras(): void
    {
        $fixture = $this->fixture();
        $expected = $this->architectureRoutes();

        $this->assertCount(36, $expected, 'Sanity: the transcription must total 36 (30 original + GET .../draft + GET .../source-fingerprint + 3 placement content routes + GET .../draft/changes).');
        $this->assertCount(count($expected), $fixture['routes'], 'Fixture route count must equal architecture.md\'s literal 36-route surface.');

        $expectedPairs = array_map(static fn (array $r) => $r['method'] . ' ' . $r['path'], $expected);
        $actualPairs = array_map(static fn (array $r) => $r['method'] . ' ' . $r['path'], $fixture['routes']);

        sort($expectedPairs);
        sort($actualPairs);

        $this->assertSame($expectedPairs, $actualPairs, 'Fixture routes must be set-equal to architecture.md\'s literal API surface block (no extras, no omissions).');
    }

    public function test_every_route_declares_method_path_permission_and_statuses_from_the_closed_enum(): void
    {
        $fixture = $this->fixture();
        $statusEnum = $fixture['status_enum'];

        foreach ($fixture['routes'] as $route) {
            $label = $route['method'] . ' ' . $route['path'];

            $this->assertNotEmpty($route['method'] ?? null, "{$label}: method required.");
            $this->assertNotEmpty($route['path'] ?? null, "{$label}: path required.");
            $this->assertArrayHasKey('permission', $route, "{$label}: permission required.");
            $this->assertNotEmpty($route['permission'], "{$label}: permission must not be empty.");
            $this->assertArrayHasKey('statuses', $route, "{$label}: statuses required.");
            $this->assertNotEmpty($route['statuses'], "{$label}: statuses must not be empty.");

            foreach ($route['statuses'] as $status) {
                $this->assertContains($status, $statusEnum, "{$label}: status {$status} must be in the closed status enum.");
            }

            foreach ((array) $route['permission'] as $permissionKey) {
                $this->assertIsString($permissionKey, "{$label}: every permission entry must be a string key.");
            }
        }
    }

    public function test_every_route_permission_resolves_to_the_permission_inventory(): void
    {
        $fixture = $this->fixture();
        $knownKeys = array_column($fixture['permissions'], 'key');

        foreach ($fixture['routes'] as $route) {
            foreach ((array) $route['permission'] as $permissionKey) {
                $this->assertContains(
                    $permissionKey,
                    $knownKeys,
                    "{$route['method']} {$route['path']}: permission '{$permissionKey}' must be declared in the permissions inventory."
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Permission inventory: exact frozen keys
    // -----------------------------------------------------------------

    public function test_permission_inventory_matches_the_frozen_module_keys_exactly(): void
    {
        $fixture = $this->fixture();

        $expected = [
            'widget_instance.viewAny',
            'widget_instance.view',
            'widget_instance.create',
            'widget_instance.update',
            'widget_instance.delete',
            'widget_layout.viewAny',
            'widget_layout.view',
            'widget_layout.update',
            'widget_layout.preview',
            'widget_layout.publish',
            'widget_layout.rollback',
            'widget_template.approve',
            'widget_template.emergencyDisable',
            'widget_template.import',
        ];

        $actual = array_column($fixture['permissions'], 'key');
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, 'Permission inventory must match the frozen key set exactly (architecture.md "Authorization and Project Isolation").');

        $reserved = array_values(array_filter($fixture['permissions'], static fn (array $p) => $p['reserved'] === true));
        $this->assertCount(1, $reserved, 'Exactly one permission (widget_template.import) is reserved for Chunk 4.');
        $this->assertSame('widget_template.import', $reserved[0]['key']);
    }

    public function test_core_owned_trust_permissions_are_referenced_not_enforced_by_this_module(): void
    {
        $fixture = $this->fixture();

        $this->assertSame(['widget.trust', 'widget_theme.trust'], $fixture['core_owned_references']);

        $moduleKeys = array_column($fixture['permissions'], 'key');
        foreach ($fixture['core_owned_references'] as $coreKey) {
            $this->assertNotContains($coreKey, $moduleKeys, "Core-owned '{$coreKey}' must stay out of this module's own enforceable permission inventory.");
        }
    }

    // -----------------------------------------------------------------
    // Pagination
    // -----------------------------------------------------------------

    public function test_pagination_is_capped_at_one_hundred_and_consistent_across_every_list_route(): void
    {
        $fixture = $this->fixture();

        $this->assertSame(20, $fixture['pagination']['default_per_page']);
        $this->assertSame(100, $fixture['pagination']['max_per_page']);

        $paginatedRoutes = array_filter($fixture['routes'], static fn (array $r) => $r['paginated'] === true);
        $this->assertNotEmpty($paginatedRoutes, 'At least one route must be paginated.');
    }

    // -----------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------

    public function test_idempotency_scopes_are_complete_for_the_ten_frozen_actions(): void
    {
        $fixture = $this->fixture();
        $scopes = $fixture['idempotency']['scopes'];

        $this->assertCount(10, $scopes, 'Exactly ten idempotency-scoped actions: create/update/clone/repin/placement create/content/duplicate/publish/rollback/import-reserved.');

        $actions = array_column($scopes, 'action');
        $this->assertSame(
            ['instance_create', 'instance_update', 'instance_clone', 'layout_repin', 'placement_create', 'placement_content_update', 'placement_duplicate', 'layout_publish', 'layout_rollback', 'safe_import_store'],
            $actions
        );

        $reserved = array_values(array_filter($scopes, static fn (array $s) => $s['reserved'] === true));
        $this->assertCount(1, $reserved);
        $this->assertSame('safe_import_store', $reserved[0]['action']);
        $this->assertNull($reserved[0]['route'], 'The reserved Safe Import scope has no route in Chunk 1 (post-MVP).');

        $routeIndex = [];
        foreach ($fixture['routes'] as $route) {
            $routeIndex[$route['method'] . ' ' . $route['path']] = $route;
        }

        foreach ($scopes as $scope) {
            if ($scope['reserved'] === true) {
                continue;
            }

            $this->assertNotNull($scope['route'], "{$scope['action']}: bound scope must reference a route.");
            $key = $scope['route']['method'] . ' ' . $scope['route']['path'];
            $this->assertArrayHasKey($key, $routeIndex, "{$scope['action']}: referenced route {$key} must exist in routes[].");
            $this->assertSame($scope['action'], $routeIndex[$key]['idempotency_scope'], "{$key}: route's idempotency_scope must match the scope's own action name.");
        }
    }

    public function test_idempotency_never_retains_hidden_input_values_or_response_bodies(): void
    {
        $fixture = $this->fixture();
        $banned = $fixture['idempotency']['banned_retained_fields'];
        $retained = $fixture['idempotency']['retained_fields'];

        foreach ($banned as $bannedField) {
            $this->assertNotContains($bannedField, $retained, "Idempotency retained_fields must never include banned field '{$bannedField}'.");
        }

        foreach (['values', 'provider_payload', 'preview_overlay', 'response_body'] as $mustBeBanned) {
            $this->assertContains($mustBeBanned, $banned, "'{$mustBeBanned}' must be explicitly banned from idempotency retention.");
        }
    }

    // -----------------------------------------------------------------
    // Named contract details: bootstrap, repin, materialize, target search
    // -----------------------------------------------------------------

    public function test_layout_bootstrap_excludes_the_idempotency_header_protocol_and_allowlists_site_only(): void
    {
        $route = $this->findRoute('PUT', '/layouts/by-key/{layout_key}');

        $this->assertSame([200, 201, 403, 422], $route['statuses']);
        $this->assertNull($route['idempotency_scope'], 'Bootstrap converges via unique(project_name, key) + retry/readback, not the idempotency-key header protocol.');
        $this->assertSame(['site'], $route['allowlisted_keys']);
        $this->assertTrue($route['no_get_side_effect']);
    }

    public function test_repin_route_declares_the_frozen_request_fields(): void
    {
        $route = $this->findRoute('POST', '/layouts/{uuid}/draft/instances/{instance_uuid}/versions-and-repin');

        foreach (['expected_current_instance_version', 'expected_draft_id', 'expected_draft_lock_version', 'usage_fingerprint', 'widget_id', 'widget_theme_id', 'values', 'provider_contract'] as $field) {
            $this->assertContains($field, $route['request_fields'], "versions-and-repin must declare request field '{$field}'.");
        }

        $this->assertSame('layout_repin', $route['idempotency_scope']);
        $this->assertContains('widget_layout.update', $route['permission']);
        $this->assertContains('widget_instance.update', $route['permission'], 'Repin authorizes both the owning Layout and the target Instance.');
    }

    public function test_materialize_route_accepts_widget_and_theme_ids_only_and_no_project_selector(): void
    {
        $route = $this->findRoute('POST', '/catalog/manifests/materialize');

        $this->assertSame(['widget_id', 'widget_theme_id', 'expected_source_fingerprint'], $route['request_fields']);
        $this->assertContains('project_name', $route['forbidden_request_fields']);
        $this->assertContains(200, $route['statuses'], 'Exact reuse must return 200.');
        $this->assertContains(201, $route['statuses'], 'New pending Manifest must return 201.');
        $this->assertSame(['widget_template.approve'], $route['permission']);
    }

    public function test_materialize_route_error_codes_exact_list_is_frozen(): void
    {
        // Task 15a contract evolution: the materialize route is the first
        // route with an explicit per-route error mapping. Its exact list is
        // frozen alongside the three codes it introduced (403 permission,
        // 422 request-shape, 422 domain, 503 build/storage). Per-route
        // exact-list freezes stay per-route; the generalized resolve/status
        // check below covers this AND every later route the same way.
        $route = $this->findRoute('POST', '/catalog/manifests/materialize');

        $this->assertSame(
            [
                'WIDGET_PERMISSION_DENIED',
                'WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED',
                'WIDGET_MANIFEST_SOURCE_INVALID',
                'WIDGET_MANIFEST_MATERIALIZATION_FAILED',
            ],
            $route['error_codes'],
            'The materialize route error mapping is frozen: 403 permission, 422 request-shape, 422 domain, 503 build/storage.'
        );
    }

    public function test_approval_routes_error_codes_exact_list_is_frozen(): void
    {
        // Task 15b contract evolution: the approve/revoke transition routes
        // are the second pair with an explicit per-route error mapping,
        // introducing the enum's first 404 code (WIDGET_MANIFEST_NOT_FOUND)
        // plus a request-shape 422 of their own
        // (WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED) -- the 409 case
        // reuses the EXISTING WIDGET_ATTESTATION_VERSION_STALE rather than
        // adding a new code (see WidgetApiErrorResponse's class docblock
        // "TASK 15b CONTRACT EVOLUTION" for why one code covers both a
        // stale version and a wrong current state).
        $expected = [
            'WIDGET_PERMISSION_DENIED',
            'WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED',
            'WIDGET_MANIFEST_NOT_FOUND',
            'WIDGET_ATTESTATION_VERSION_STALE',
        ];

        $this->assertSame($expected, $this->findRoute('POST', '/catalog/manifests/{manifest_hash}/approval')['error_codes']);
        $this->assertSame($expected, $this->findRoute('DELETE', '/catalog/manifests/{manifest_hash}/approval')['error_codes']);
    }

    public function test_runtime_block_routes_error_codes_exact_list_is_frozen(): void
    {
        // Task 15c contract evolution: the emergency block/clear routes are
        // the third pair with an explicit per-route error mapping. Unlike
        // materialize/approval, these routes declared NO 422 at all in the
        // original Task 12 freeze (their `reason` field is genuinely
        // required, unlike approval's optional one -- see
        // WidgetApiErrorResponse's class docblock "TASK 15c CONTRACT
        // EVOLUTION"), so this evolution both ADDS 422 to the routes'
        // statuses AND mints WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED.
        // The 409 and 503 cases reuse EXISTING codes
        // (WIDGET_RUNTIME_CONTROL_VERSION_STALE, WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE)
        // that were already part of the original 20 -- this axis's
        // staleness/unavailability codes were anticipated before this task
        // even existed.
        $expected = [
            'WIDGET_PERMISSION_DENIED',
            'WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED',
            'WIDGET_MANIFEST_NOT_FOUND',
            'WIDGET_RUNTIME_CONTROL_VERSION_STALE',
            'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE',
        ];

        $postRoute = $this->findRoute('POST', '/catalog/manifests/{manifest_hash}/runtime-block');
        $deleteRoute = $this->findRoute('DELETE', '/catalog/manifests/{manifest_hash}/runtime-block');

        $this->assertSame($expected, $postRoute['error_codes']);
        $this->assertSame($expected, $deleteRoute['error_codes']);
        $this->assertSame([200, 403, 404, 409, 422, 503], $postRoute['statuses'], 'Task 15c adds 422 to the previously 422-less runtime-block statuses.');
        $this->assertSame([200, 403, 404, 409, 422, 503], $deleteRoute['statuses']);
        $this->assertSame(['expected_control_version', 'reason'], $postRoute['request_fields']);
    }

    public function test_every_route_error_codes_mapping_resolves_and_matches_declared_statuses(): void
    {
        // Generalized over EVERY route that carries an error_codes mapping,
        // so 15b/15c routes get this check for free the moment they add one:
        // each declared code must resolve in the errors{} dictionary AND its
        // frozen status must be among the route's own declared statuses (no
        // route may map to a status it doesn't itself list).
        $fixture = $this->fixture();

        $routesWithErrorCodes = array_values(array_filter(
            $fixture['routes'],
            static fn (array $route): bool => isset($route['error_codes']) && $route['error_codes'] !== []
        ));

        $this->assertNotEmpty(
            $routesWithErrorCodes,
            'At least one route must declare an error_codes mapping (the materialize route since Task 15a).'
        );

        foreach ($routesWithErrorCodes as $route) {
            $label = $route['method'] . ' ' . $route['path'];

            foreach ($route['error_codes'] as $code) {
                $this->assertArrayHasKey(
                    $code,
                    $fixture['errors'],
                    "{$label}: error code '{$code}' must resolve in the errors{} dictionary."
                );
                $this->assertContains(
                    WidgetApiErrorResponse::statusFor($code),
                    $route['statuses'],
                    "{$label}: error code '{$code}' must map to a status the route itself declares."
                );
            }
        }
    }

    public function test_target_search_and_detail_routes_are_capped_paginated_and_authorized(): void
    {
        $search = $this->findRoute('GET', '/target-contexts/{type}/targets');
        $detail = $this->findRoute('GET', '/target-contexts/{type}/targets/{target_id}');

        $this->assertTrue($search['paginated']);
        $this->assertContains(403, $search['statuses']);
        $this->assertContains(404, $search['statuses']);
        $this->assertContains(403, $detail['statuses']);
        $this->assertContains(404, $detail['statuses']);
        $this->assertSame('WidgetTargetResource', $search['response_resource']);
        $this->assertSame('WidgetTargetResource', $detail['response_resource']);
    }

    public function test_preview_sessions_overlay_is_placement_only_with_no_ephemeral_instance_values(): void
    {
        $route = $this->findRoute('POST', '/layouts/{uuid}/preview-sessions');

        $this->assertContains('overlay', $route['request_fields']);
        $this->assertArrayHasKey('overlay_constraints', $route, 'Preview overlay constraints must be pinned (architecture.md: preview overlays cannot contain ephemeral Instance values).');
        $this->assertTrue($route['overlay_constraints']['placement_overlay_only'], 'Preview overlay is Placement state only.');
        $this->assertSame('persisted_only', $route['overlay_constraints']['instance_version_references'], 'Preview accepts persisted Instance Version IDs only.');
        $this->assertSame('forbidden', $route['overlay_constraints']['ephemeral_instance_values'], 'Ephemeral/unsaved Instance values are forbidden in preview overlays.');
        $this->assertNull($route['idempotency_scope'], 'Preview session creation is not an idempotency-scoped mutation.');
    }

    public function test_sync_status_pending_is_gate_zero_legacy_only_and_site_layout_is_always_ready(): void
    {
        $fixture = $this->fixture();

        $this->assertArrayHasKey('sync_status', $fixture, 'sync_status semantics must be pinned (architecture.md "Committed mutation and sync semantics", D-008).');
        $this->assertSame(['ready', 'pending'], $fixture['sync_status']['values']);
        $this->assertSame('gate_0_legacy_definition_sync_only', $fixture['sync_status']['pending_scope'], 'sync_status: pending belongs to Gate-0 legacy Definition sync ONLY.');
        $this->assertTrue($fixture['sync_status']['site_layout_always_ready'], 'Every Site Layout Version mutation returns ready because Blob/approval prerequisites precede Version creation.');
    }

    public function test_eligibility_and_emergency_routes_use_distinct_permissions(): void
    {
        $approvalPost = $this->findRoute('POST', '/catalog/manifests/{manifest_hash}/approval');
        $approvalDelete = $this->findRoute('DELETE', '/catalog/manifests/{manifest_hash}/approval');
        $blockPost = $this->findRoute('POST', '/catalog/manifests/{manifest_hash}/runtime-block');
        $blockDelete = $this->findRoute('DELETE', '/catalog/manifests/{manifest_hash}/runtime-block');

        $this->assertSame(['widget_template.approve'], $approvalPost['permission']);
        $this->assertSame(['widget_template.approve'], $approvalDelete['permission']);
        $this->assertSame(['widget_template.emergencyDisable'], $blockPost['permission']);
        $this->assertSame(['widget_template.emergencyDisable'], $blockDelete['permission']);
        $this->assertContains(503, $blockPost['statuses'], 'Pre-mutation 503 (topology unavailable) must be a declared status for runtime-block.');
    }

    // -----------------------------------------------------------------
    // Resources: PHP classes exist and match the fixture field lists
    // -----------------------------------------------------------------

    public function test_every_response_resource_reference_resolves_to_a_defined_resource(): void
    {
        $fixture = $this->fixture();
        $resourceNames = array_keys($fixture['resources']);

        foreach ($fixture['routes'] as $route) {
            if ($route['response_resource'] === null) {
                continue;
            }

            $this->assertContains($route['response_resource'], $resourceNames, "{$route['method']} {$route['path']}: response_resource must be declared in resources{}.");
        }
    }

    public function test_resource_classes_exist_and_frozen_fields_match_the_fixture_exactly(): void
    {
        $fixture = $this->fixture();

        $classMap = [
            'WidgetInstanceResource' => WidgetInstanceResource::class,
            'WidgetDefinitionCatalogResource' => WidgetDefinitionCatalogResource::class,
            'WidgetLayoutResource' => WidgetLayoutResource::class,
            'WidgetLayoutDraftResource' => WidgetLayoutDraftResource::class,
            'WidgetPlacementResource' => WidgetPlacementResource::class,
            'WidgetSlotResource' => WidgetSlotResource::class,
            'WidgetTargetContextResource' => WidgetTargetContextResource::class,
            'WidgetTargetResource' => WidgetTargetResource::class,
            'WidgetArtifactManifestResource' => WidgetArtifactManifestResource::class,
            'WidgetLayoutReleaseResource' => WidgetLayoutReleaseResource::class,
        ];

        $expectedNames = array_keys($fixture['resources']);
        $actualNames = array_keys($classMap);
        sort($expectedNames);
        sort($actualNames);
        $this->assertSame($expectedNames, $actualNames, 'Every fixture resource must have exactly one corresponding PHP class, and vice versa.');

        foreach ($classMap as $name => $fqcn) {
            $this->assertTrue(class_exists($fqcn), "{$fqcn} must exist.");
            $this->assertTrue(method_exists($fqcn, 'frozenFields'), "{$fqcn}::frozenFields() must exist.");

            $expectedFields = $fixture['resources'][$name]['fields'];
            $actualFields = $fqcn::frozenFields();

            sort($expectedFields);
            sort($actualFields);

            $this->assertSame($expectedFields, $actualFields, "{$fqcn}::frozenFields() must match the fixture's declared field set for '{$name}'.");
        }
    }

    public function test_resource_toarray_projects_exactly_its_frozen_fields_from_a_plain_array_with_no_model_coupling(): void
    {
        $sample = [
            'uuid' => 'abc-123',
            'project_name' => 'Default',
            'name' => 'Test',
            'status' => 'active',
            'current_version' => ['version' => 1],
            'sync_status' => 'ready',
            'created_at' => '2026-07-17',
            'updated_at' => '2026-07-17',
        ];

        $resource = (new WidgetInstanceResource($sample))->toArray(request());

        $this->assertSame(WidgetInstanceResource::frozenFields(), array_keys($resource), 'toArray() output keys must equal frozenFields() exactly, proving no model coupling is required.');
        $this->assertSame('abc-123', $resource['uuid']);
    }

    // -----------------------------------------------------------------
    // Shared repin usage_fingerprint primitive
    // -----------------------------------------------------------------

    /**
     * {@see WidgetDraftUsageFingerprint::compute()} is the SINGLE source of
     * the repin `usage_fingerprint` identity, called by BOTH the
     * `POST .../versions-and-repin` validation and the
     * `GET /layouts/{uuid}/draft` read projection so the two can never drift.
     * This test independently recomputes the frozen identity set (the same
     * four keys, uuids sorted) via {@see CanonicalJson::hash()} and proves the
     * helper is byte-identical to it -- if the helper ever diverges from the
     * frozen formula, this fails.
     */
    public function test_shared_usage_fingerprint_helper_hashes_the_frozen_repin_identity_with_sorted_uuids(): void
    {
        $expected = CanonicalJson::hash([
            'draft_version' => 3,
            'draft_lock_version' => 5,
            'instance_current_version' => 7,
            'matching_placement_uuids' => ['aaa-1', 'bbb-2', 'ccc-3'],
        ]);

        $this->assertSame(
            $expected,
            WidgetDraftUsageFingerprint::compute(3, 5, 7, ['ccc-3', 'aaa-1', 'bbb-2']),
            'The helper must hash exactly the frozen repin identity set and sort the matching placement uuids first.'
        );

        $this->assertSame(
            CanonicalJson::hash([
                'draft_version' => 1,
                'draft_lock_version' => 0,
                'instance_current_version' => null,
                'matching_placement_uuids' => [],
            ]),
            WidgetDraftUsageFingerprint::compute(1, 0, null, []),
            'A null current version and an empty placement set must hash exactly as the frozen formula does.'
        );
    }

    // -----------------------------------------------------------------
    // Errors
    // -----------------------------------------------------------------

    public function test_error_dictionary_is_exactly_the_twenty_seven_frozen_codes_with_closed_status_enum(): void
    {
        $fixture = $this->fixture();
        $statusEnum = $fixture['status_enum'];

        // The complete frozen 26-code set — same freeze strength as routes and
        // permissions: exact set equality, not subset. WIDGET_INSTANCE_IN_USE
        // is architecture.md's own "Required conflict codes" trio member
        // (Chunk 2 Task 8 sequencing depends on it). Codes 21-23 are the Task
        // 15a contract evolution (spec-review sanctioned): the materialize
        // route always declared statuses 403/422, but the original 20-code
        // enum had zero 403 codes and no 422 fitting request-level pairing/
        // applicability/shape failures — the hole was in the contract itself,
        // closed when the first real controller landed. Codes 24-25 are the
        // Task 15b contract evolution, same precedent applied again: the
        // approve/revoke routes always declared statuses 404/422, but the
        // 23-code enum had zero 404 codes and no 422 fitting THIS route's own
        // request-shape failures. Code 26 is the Task 15c contract evolution,
        // same precedent a third time: the runtime-block routes declared NO
        // 422 at all (unlike materialize/approval, which always had it), but
        // this task's `reason` field is genuinely required, so a 422 became
        // unavoidable and needed both a status addition AND a new code. The
        // original 20 remain the closed domain-conflict core; all six
        // extension codes extend the 403/404/422 tiers. Code 27 (WIDGET_BLOCKED, 409) is the
        // Unified Widget Catalog Part 2 evolution: approval became automatic and the Core
        // emergency block is the only stop, so placing a blocked widget needs its own code.
        $frozen = [
            'WIDGET_DEFINITION_IN_USE', 'WIDGET_TEMPLATE_IN_USE', 'WIDGET_INSTANCE_IN_USE', 'WIDGET_MODULE_IN_USE',
            'WIDGET_USAGE_CHANGED', 'WIDGET_IDEMPOTENCY_IN_PROGRESS', 'WIDGET_IDEMPOTENCY_MISMATCH',
            'WIDGET_PROVIDER_UNAVAILABLE', 'WIDGET_PROVIDER_INVALID',
            'WIDGET_MANIFEST_MATERIALIZATION_FAILED', 'WIDGET_MANIFEST_APPROVAL_REQUIRED', 'WIDGET_MANIFEST_REVOKED',
            'WIDGET_ATTESTATION_VERSION_STALE', 'WIDGET_RUNTIME_CONTROL_VERSION_STALE', 'WIDGET_MANIFEST_RUNTIME_BLOCKED',
            'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE', 'WIDGET_REGISTRY_COMPATIBILITY_DRIFT',
            'WIDGET_ARTIFACT_INTEGRITY_FAILED', 'WIDGET_DRAFT_STALE', 'WIDGET_PREVIEW_SESSION_EXPIRED',
            'WIDGET_PERMISSION_DENIED', 'WIDGET_MANIFEST_SOURCE_INVALID', 'WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED',
            'WIDGET_MANIFEST_NOT_FOUND', 'WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED',
            'WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED',
            'WIDGET_BLOCKED',
        ];

        $this->assertCount(27, $frozen, 'Sanity: the frozen error-code list itself must total 27 (20 original + 3 Task 15a + 2 Task 15b + 1 Task 15c + 1 catalog Part 2).');

        $fixtureCodes = array_keys($fixture['errors']);
        $helperCodes = WidgetApiErrorResponse::knownCodes();
        $expected = $frozen;

        sort($expected);
        sort($fixtureCodes);
        sort($helperCodes);

        $this->assertSame($expected, $fixtureCodes, 'Fixture error codes must be set-equal to the frozen 27-code list (no extras, no omissions).');
        $this->assertSame($expected, $helperCodes, 'WidgetApiErrorResponse codes must be set-equal to the frozen 27-code list (no extras, no omissions).');

        foreach ($fixture['errors'] as $code => $definition) {
            $this->assertContains($definition['status'], $statusEnum, "{$code}: status must be in the closed enum.");
            $this->assertIsBool($definition['retryable'], "{$code}: retryable must be boolean.");
        }
    }

    public function test_error_response_helper_matches_fixture_status_for_every_code(): void
    {
        $fixture = $this->fixture();

        foreach ($fixture['errors'] as $code => $definition) {
            $this->assertSame($definition['status'], WidgetApiErrorResponse::statusFor($code), "WidgetApiErrorResponse status for {$code} must match the fixture.");
            $this->assertSame($definition['retryable'], WidgetApiErrorResponse::isRetryable($code), "WidgetApiErrorResponse retryable for {$code} must match the fixture.");
        }

        $fixtureCodes = array_keys($fixture['errors']);
        $helperCodes = WidgetApiErrorResponse::knownCodes();
        sort($fixtureCodes);
        sort($helperCodes);

        $this->assertSame($fixtureCodes, $helperCodes, 'WidgetApiErrorResponse must define exactly the fixture\'s error code set.');
    }

    public function test_error_response_envelope_shape_is_frozen(): void
    {
        $envelope = WidgetApiErrorResponse::envelope(
            WidgetApiErrorResponse::WIDGET_DRAFT_STALE,
            'The draft changed since it was loaded.',
            'corr-test-1',
        );

        $this->assertSame(['status', 'code', 'message', 'correlation_id'], array_keys($envelope));
        $this->assertSame(409, $envelope['status']);
        $this->assertSame('WIDGET_DRAFT_STALE', $envelope['code']);

        $withExtras = WidgetApiErrorResponse::envelope(
            WidgetApiErrorResponse::WIDGET_PROVIDER_INVALID,
            'Invalid provider arguments.',
            'corr-test-2',
            details: ['field' => 'provider_contract.arguments'],
            recovery: ['hint' => 'fix_arguments'],
        );

        $this->assertSame(['status', 'code', 'message', 'correlation_id', 'details', 'recovery'], array_keys($withExtras));
    }

    // -----------------------------------------------------------------
    // Project context resolver
    // -----------------------------------------------------------------

    public function test_project_context_resolver_derives_from_server_config_not_request_input(): void
    {
        config(['ispa-cms.folder_name' => 'PilotProject']);

        $resolver = new WidgetProjectContextResolver();
        $context = $resolver->resolve();

        $this->assertInstanceOf(AuthorizedProjectContext::class, $context);
        $this->assertSame('PilotProject', $context->projectName);
    }

    public function test_project_context_resolver_accepts_a_matching_project_name(): void
    {
        config(['ispa-cms.folder_name' => 'PilotProject']);

        $resolver = new WidgetProjectContextResolver();
        $context = $resolver->assertProject('PilotProject');

        $this->assertSame('PilotProject', $context->projectName);
    }

    public function test_project_context_resolver_rejects_a_mismatching_project_name_in_single_project_mode(): void
    {
        config(['ispa-cms.folder_name' => 'PilotProject']);

        $resolver = new WidgetProjectContextResolver();

        $this->expectException(AuthorizationException::class);
        $resolver->assertProject('SomeOtherProject');
    }

    public function test_project_context_resolver_accepts_a_null_project_name_as_no_selector(): void
    {
        config(['ispa-cms.folder_name' => 'PilotProject']);

        $resolver = new WidgetProjectContextResolver();
        $context = $resolver->assertProject(null);

        $this->assertSame('PilotProject', $context->projectName);
    }

    // -----------------------------------------------------------------
    // i18n
    // -----------------------------------------------------------------

    public function test_every_fixture_i18n_key_exists_in_the_exported_translation_file_with_both_locales(): void
    {
        $fixture = $this->fixture();
        $rows = $this->i18nRows();

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        // Bidirectional freeze: exactly 26 keys on both sides, and the two
        // name sets are set-equal (fixture keys <=> sidecar keys) — a sidecar
        // key absent from the fixture is as much a drift as the reverse.
        $this->assertCount(26, $fixture['i18n']['keys'], 'The i18n inventory is frozen at exactly 26 keys.');
        $this->assertCount(26, $rows, 'The exported sidecar is frozen at exactly 26 rows.');

        $fixtureNames = array_column($fixture['i18n']['keys'], 'name');
        $sidecarNames = array_keys($byName);
        sort($fixtureNames);
        sort($sidecarNames);
        $this->assertSame($fixtureNames, $sidecarNames, 'Fixture i18n keys and sidecar rows must be set-equal (no extras on either side).');

        foreach ($fixture['i18n']['keys'] as $keyDef) {
            $name = $keyDef['name'];
            $this->assertArrayHasKey($name, $byName, "i18n key '{$name}' must exist in " . self::I18N_PATH);

            $text = $byName[$name]['text'];
            $this->assertArrayHasKey('en', $text, "i18n key '{$name}' must have a non-empty English translation.");
            $this->assertArrayHasKey('vi', $text, "i18n key '{$name}' must have a non-empty Vietnamese translation.");
            $this->assertNotSame('', trim((string) $text['en']), "i18n key '{$name}': English text must not be empty.");
            $this->assertNotSame('', trim((string) $text['vi']), "i18n key '{$name}': Vietnamese text must not be empty.");
            $this->assertSame($fixture['i18n']['group_name'], $byName[$name]['group_name'], "i18n key '{$name}' must use group_name '{$fixture['i18n']['group_name']}'.");
        }
    }

    public function test_pilot_slot_and_template_context_keys_are_present_by_exact_architecture_name(): void
    {
        $fixture = $this->fixture();
        $names = array_column($fixture['i18n']['keys'], 'name');

        $requiredSlots = [
            'widget_site_layout.slot.site_header_before',
            'widget_site_layout.slot.site_header_after',
            'widget_site_layout.slot.page_before_content',
            'widget_site_layout.slot.page_after_content',
            'widget_site_layout.slot.site_footer_before',
            'widget_site_layout.slot.site_footer_after',
        ];

        $requiredTemplateContexts = [
            'widget_site_layout.template_context.site_shell',
            'widget_site_layout.template_context.page_static',
            'widget_site_layout.template_context.article_detail',
        ];

        foreach ([...$requiredSlots, ...$requiredTemplateContexts] as $required) {
            $this->assertContains($required, $names, "Required i18n key '{$required}' must be present.");
        }

        $countByCategory = static fn (string $category): int => count(array_filter(
            $fixture['i18n']['keys'],
            static fn (array $k) => $k['category'] === $category
        ));

        $this->assertSame(6, $countByCategory('slot'), 'Exactly six pilot Slot i18n keys (architecture.md "Pilot hook mapping").');
        $this->assertSame(3, $countByCategory('template_context'), 'Exactly three Template Context i18n keys.');
        $this->assertSame(1, $countByCategory('navigation'), 'Exactly one navigation i18n key.');
        $this->assertSame(11, $countByCategory('action'), 'Exactly eleven route-backed action i18n keys.');
        $this->assertSame(5, $countByCategory('error'), 'Exactly five error-status i18n keys.');
        $this->assertSame(26, 6 + 3 + 1 + 11 + 5, 'Sanity: category counts must sum to the frozen 26-key total.');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function findRoute(string $method, string $path): array
    {
        foreach ($this->fixture()['routes'] as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                return $route;
            }
        }

        $this->fail("Route {$method} {$path} not found in fixture.");
    }
}
