/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Chunk 3 Task 25 data layer: typed wrappers for every route in THE frozen
// Widget Site Layout contract (cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json,
// contract_version 1.0.0, base_path /api/v2/admin/widget). This file mirrors
// that fixture verbatim -- method, path, permission, statuses, paginated,
// idempotency_scope, response_resource and request_fields for all 36 routes,
// plus status/retryable/recovery for all 27 error codes -- and is itself
// cross-checked against the fixture by tests/contracts/widget-site-layout-api.spec.ts.
// Route 31 (GET /layouts/{uuid}/draft) is a disclosed post-freeze contract
// evolution (routes 30->31) that closed a gap Task 25/27 flagged: a pure-read
// companion to PUT /layouts/{uuid}/draft returning the current Draft's
// version/lock_version/placements[] so use-widget-site-layout.js's bootstrap()
// can reload a previously-saved Draft on entry instead of always starting empty.
//
// HTTP layer follows the SAME per-module axios instance convention every
// other admin module under src/modules/*/admin uses (see
// src/modules/game/admin/client.js): its own axios.create() with
// baseURL/withCredentials/auth headers and a 401 interceptor. No new HTTP
// layer is invented here.

import axios from 'axios'
import { apiUrl, COOKIE_KEY_LOCALE, COOKIE_KEY_TOKEN, cookie, defaultLocale } from '@/config'
import { i18n } from '@/config/i18n'
import { isEmpty } from 'lodash-es'

const IDEMPOTENCY_KEY_HEADER = 'Idempotency-Key'

// ---------------------------------------------------------------------------
// Axios instance -- scoped to this module's own base path, matching every
// other src/modules/*/admin client's shape (baseURL/withCredentials/auth
// headers/401 interceptor). `cookie` is deliberately imported from the `@/config`
// barrel (not the deeper `@/config/cookies` game/client.js uses) so this
// module picks up the SAME mock the project's global vitest setup already
// provides for `@/config` -- no additional test wiring needed.
// ---------------------------------------------------------------------------
const axiosConfig = {
    baseURL: `${apiUrl}api/v2/admin/widget/`,
    timeout: 120000,
    withCredentials: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Credentials': 'true',
        'Accept-Language': cookie.get(COOKIE_KEY_LOCALE, defaultLocale),
        'ISPA-ADMIN-AUTH-KEY': import.meta.env.VITE_APP_ADMIN_AUTH_KEY,
    },
}

const authToken = cookie.get(COOKIE_KEY_TOKEN)
if (!isEmpty(authToken)) {
    axiosConfig.headers.Authorization = `Bearer ${authToken}`
}

const widgetSiteLayoutAjax = axios.create(axiosConfig)

// Same as the shared client (`@/ajax`): every request names the admin language in `X-Admin-Locale`, so widget and
// design names come back in that language. The API locale stays the site's (no `X-Locale` by default).
widgetSiteLayoutAjax.interceptors.request.use((config) => {
    if (!config.headers.has('X-Admin-Locale')) {
        config.headers.set('X-Admin-Locale', cookie.get(COOKIE_KEY_LOCALE, defaultLocale))
    }
    return config
})

widgetSiteLayoutAjax.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error?.response?.status === 401 && !isEmpty(authToken)) {
            localStorage.clear()
            cookie.remove(COOKIE_KEY_TOKEN)
            setTimeout(() => {
                window.location.reload()
            }, 1000)
        }
        return Promise.reject(error)
    }
)

// ---------------------------------------------------------------------------
// Route manifest -- the single source of truth this file's wrapper functions
// are generated from, and the same shape tests/contracts/widget-site-layout-api.spec.ts
// cross-checks field-for-field against the frozen fixture's `routes` array.
// ---------------------------------------------------------------------------

/**
 * @typedef {Object} WidgetSiteLayoutRoute
 * @property {string} key - matches the exported wrapper function name below
 * @property {'GET'|'POST'|'PUT'|'PATCH'|'DELETE'} method
 * @property {string} path - `{param}` tokens are substituted positionally by the wrapper
 * @property {string[]} permission
 * @property {number[]} statuses
 * @property {boolean} paginated
 * @property {string|null} idempotencyScope
 * @property {string|null} responseResource
 * @property {string[]} requestFields
 * @property {string[]} [allowlistedKeys]
 */

/** @type {WidgetSiteLayoutRoute[]} */
export const WIDGET_SITE_LAYOUT_ROUTES = [
    {
        key: 'listSlots',
        method: 'GET',
        path: '/slots',
        permission: ['widget_layout.viewAny'],
        statuses: [200, 403],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetSlotResource',
        requestFields: [],
    },
    {
        key: 'listTargetContexts',
        method: 'GET',
        path: '/target-contexts',
        permission: ['widget_layout.viewAny'],
        statuses: [200, 403],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetTargetContextResource',
        requestFields: [],
    },
    {
        key: 'searchWidgetTargets',
        method: 'GET',
        path: '/target-contexts/{type}/targets',
        permission: ['widget_layout.viewAny'],
        statuses: [200, 403, 404, 422],
        paginated: true,
        idempotencyScope: null,
        responseResource: 'WidgetTargetResource',
        requestFields: ['search', 'page', 'per_page'],
    },
    {
        key: 'getWidgetTargetDetail',
        method: 'GET',
        path: '/target-contexts/{type}/targets/{target_id}',
        permission: ['widget_layout.viewAny'],
        statuses: [200, 403, 404],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetTargetResource',
        requestFields: [],
    },
    {
        key: 'getWidgetSiteLayoutHealth',
        method: 'GET',
        path: '/health',
        permission: ['widget_instance.viewAny'],
        statuses: [200, 403],
        paginated: false,
        idempotencyScope: null,
        responseResource: null,
        requestFields: [],
    },
    {
        key: 'listWidgetDefinitionCatalog',
        method: 'GET',
        path: '/catalog/definitions',
        permission: ['widget_instance.viewAny'],
        statuses: [200, 403],
        paginated: true,
        idempotencyScope: null,
        responseResource: 'WidgetDefinitionCatalogResource',
        requestFields: [],
    },
    {
        key: 'getWidgetDefinitionCatalogDetail',
        method: 'GET',
        path: '/catalog/definitions/{widget}',
        permission: ['widget_instance.viewAny'],
        statuses: [200, 403, 404],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetDefinitionCatalogResource',
        requestFields: [],
    },
    {
        key: 'listWidgetArtifactManifests',
        method: 'GET',
        path: '/catalog/manifests',
        permission: ['widget_template.approve'],
        statuses: [200, 403],
        paginated: true,
        idempotencyScope: null,
        responseResource: 'WidgetArtifactManifestResource',
        requestFields: [],
    },
    {
        key: 'getWidgetManifestSourceFingerprint',
        method: 'GET',
        path: '/catalog/manifests/source-fingerprint',
        permission: ['widget_template.approve'],
        statuses: [200, 403, 422],
        paginated: false,
        idempotencyScope: null,
        responseResource: null,
        requestFields: ['widget_id', 'widget_theme_id'],
    },
    {
        key: 'materializeWidgetManifest',
        method: 'POST',
        path: '/catalog/manifests/materialize',
        permission: ['widget_template.approve'],
        statuses: [200, 201, 403, 422, 503],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetArtifactManifestResource',
        requestFields: ['widget_id', 'widget_theme_id', 'expected_source_fingerprint'],
    },
    {
        key: 'approveWidgetManifest',
        method: 'POST',
        path: '/catalog/manifests/{manifest_hash}/approval',
        permission: ['widget_template.approve'],
        statuses: [200, 403, 404, 409, 422],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetArtifactManifestResource',
        requestFields: ['expected_attestation_version', 'reason'],
    },
    {
        key: 'revokeWidgetManifest',
        method: 'DELETE',
        path: '/catalog/manifests/{manifest_hash}/approval',
        permission: ['widget_template.approve'],
        statuses: [200, 403, 404, 409, 422],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetArtifactManifestResource',
        requestFields: ['expected_attestation_version', 'reason'],
    },
    {
        key: 'blockWidgetManifestRuntime',
        method: 'POST',
        path: '/catalog/manifests/{manifest_hash}/runtime-block',
        permission: ['widget_template.emergencyDisable'],
        statuses: [200, 403, 404, 409, 422, 503],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetArtifactManifestResource',
        requestFields: ['expected_control_version', 'reason'],
    },
    {
        key: 'clearWidgetManifestRuntimeBlock',
        method: 'DELETE',
        path: '/catalog/manifests/{manifest_hash}/runtime-block',
        permission: ['widget_template.emergencyDisable'],
        statuses: [200, 403, 404, 409, 422, 503],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetArtifactManifestResource',
        requestFields: ['expected_control_version', 'reason'],
    },
    {
        key: 'listWidgetInstances',
        method: 'GET',
        path: '/instances',
        permission: ['widget_instance.viewAny'],
        statuses: [200, 403],
        paginated: true,
        idempotencyScope: null,
        responseResource: 'WidgetInstanceResource',
        requestFields: [],
    },
    {
        key: 'createWidgetInstance',
        method: 'POST',
        path: '/instances',
        permission: ['widget_instance.create'],
        statuses: [201, 403, 404, 409, 422, 503],
        paginated: false,
        idempotencyScope: 'instance_create',
        responseResource: 'WidgetInstanceResource',
        requestFields: ['name', 'widget_id', 'widget_theme_id', 'values', 'provider_contract'],
    },
    {
        key: 'getWidgetInstance',
        method: 'GET',
        path: '/instances/{uuid}',
        permission: ['widget_instance.view'],
        statuses: [200, 403, 404],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetInstanceResource',
        requestFields: [],
    },
    {
        key: 'updateWidgetInstance',
        method: 'PATCH',
        path: '/instances/{uuid}',
        permission: ['widget_instance.update'],
        statuses: [200, 403, 404, 409, 422, 503],
        paginated: false,
        idempotencyScope: 'instance_update',
        responseResource: 'WidgetInstanceResource',
        requestFields: [
            'expected_current_version',
            'widget_id',
            'widget_theme_id',
            'values',
            'provider_contract',
        ],
    },
    {
        key: 'deleteWidgetInstance',
        method: 'DELETE',
        path: '/instances/{uuid}',
        permission: ['widget_instance.delete'],
        statuses: [200, 403, 404, 409],
        paginated: false,
        idempotencyScope: null,
        responseResource: null,
        requestFields: [],
    },
    {
        key: 'getWidgetInstanceUsage',
        method: 'GET',
        path: '/instances/{uuid}/usage',
        permission: ['widget_instance.view'],
        statuses: [200, 403, 404],
        paginated: false,
        idempotencyScope: null,
        responseResource: null,
        requestFields: [],
    },
    {
        key: 'cloneWidgetInstance',
        method: 'POST',
        path: '/instances/{uuid}/clone',
        permission: ['widget_instance.create'],
        statuses: [201, 403, 404, 409, 422, 503],
        paginated: false,
        idempotencyScope: 'instance_clone',
        responseResource: 'WidgetInstanceResource',
        requestFields: ['name'],
    },
    {
        key: 'archiveWidgetInstance',
        method: 'POST',
        path: '/instances/{uuid}/archive',
        permission: ['widget_instance.delete'],
        statuses: [200, 403, 404, 409],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetInstanceResource',
        requestFields: [],
    },
    {
        key: 'listWidgetLayouts',
        method: 'GET',
        path: '/layouts',
        permission: ['widget_layout.viewAny'],
        statuses: [200, 403],
        paginated: true,
        idempotencyScope: null,
        responseResource: 'WidgetLayoutResource',
        requestFields: [],
    },
    {
        key: 'bootstrapWidgetLayoutByKey',
        method: 'PUT',
        path: '/layouts/by-key/{layout_key}',
        permission: ['widget_layout.update'],
        statuses: [200, 201, 403, 422],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetLayoutResource',
        requestFields: [],
        allowlistedKeys: ['site'],
    },
    {
        key: 'getWidgetLayout',
        method: 'GET',
        path: '/layouts/{uuid}',
        permission: ['widget_layout.view'],
        statuses: [200, 403, 404],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetLayoutResource',
        requestFields: [],
    },
    {
        key: 'saveWidgetLayoutDraft',
        method: 'PUT',
        path: '/layouts/{uuid}/draft',
        permission: ['widget_layout.update'],
        statuses: [200, 403, 404, 409, 422],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetPlacementResource',
        requestFields: ['expected_current_draft_id', 'expected_lock_version', 'placements'],
    },
    {
        key: 'getWidgetLayoutDraft',
        method: 'GET',
        path: '/layouts/{uuid}/draft',
        permission: ['widget_layout.view'],
        statuses: [200, 403, 404],
        paginated: false,
        idempotencyScope: null,
        responseResource: 'WidgetLayoutDraftResource',
        requestFields: [],
    },
    {
        key: 'repinWidgetLayoutDraftInstance',
        method: 'POST',
        path: '/layouts/{uuid}/draft/instances/{instance_uuid}/versions-and-repin',
        permission: ['widget_layout.update', 'widget_instance.update'],
        statuses: [200, 403, 404, 409, 422, 503],
        paginated: false,
        idempotencyScope: 'layout_repin',
        responseResource: 'WidgetInstanceResource',
        requestFields: [
            'expected_current_instance_version',
            'expected_draft_id',
            'expected_draft_lock_version',
            'usage_fingerprint',
            'widget_id',
            'widget_theme_id',
            'values',
            'provider_contract',
        ],
    },
    {
        key: 'createWidgetLayoutDraftPlacement',
        method: 'POST',
        path: '/layouts/{uuid}/draft/placements',
        permission: ['widget_layout.update'],
        statuses: [201, 403, 404, 409, 422, 503],
        paginated: false,
        idempotencyScope: 'placement_create',
        responseResource: 'WidgetLayoutDraftResource',
        requestFields: [
            'slot_key',
            'scope_type',
            'scope_key',
            'target_type',
            'target_id',
            'widget_id',
            'widget_theme_id',
            'values',
            'expected_draft_revision',
        ],
    },
    {
        key: 'updateWidgetLayoutDraftPlacementContent',
        method: 'PATCH',
        path: '/layouts/{uuid}/draft/placements/{placement_uuid}/content',
        permission: ['widget_layout.update'],
        statuses: [200, 403, 404, 409, 422, 503],
        paginated: false,
        idempotencyScope: 'placement_content_update',
        responseResource: 'WidgetLayoutDraftResource',
        requestFields: ['values', 'widget_theme_id', 'expected_draft_revision'],
    },
    {
        key: 'duplicateWidgetLayoutDraftPlacement',
        method: 'POST',
        path: '/layouts/{uuid}/draft/placements/{placement_uuid}/duplicate',
        permission: ['widget_layout.update'],
        statuses: [201, 403, 404, 409, 422, 503],
        paginated: false,
        idempotencyScope: 'placement_duplicate',
        responseResource: 'WidgetLayoutDraftResource',
        requestFields: ['expected_draft_revision'],
    },
    {
        key: 'getWidgetLayoutDraftChanges',
        method: 'GET',
        path: '/layouts/{uuid}/draft/changes',
        permission: ['widget_layout.view'],
        statuses: [200, 403, 404],
        paginated: false,
        idempotencyScope: null,
        responseResource: null,
        requestFields: [],
    },
    {
        key: 'listWidgetLayoutReleases',
        method: 'GET',
        path: '/layouts/{uuid}/releases',
        permission: ['widget_layout.view'],
        statuses: [200, 403, 404],
        paginated: true,
        idempotencyScope: null,
        responseResource: 'WidgetLayoutReleaseResource',
        requestFields: [],
    },
    {
        key: 'createWidgetLayoutPreviewSession',
        method: 'POST',
        path: '/layouts/{uuid}/preview-sessions',
        permission: ['widget_layout.preview'],
        statuses: [201, 403, 404, 422],
        paginated: false,
        idempotencyScope: null,
        responseResource: null,
        requestFields: ['overlay', 'locale', 'template_context', 'target_type', 'target_id', 'viewport', 'editor_nonce'],
    },
    {
        key: 'publishWidgetLayout',
        method: 'POST',
        path: '/layouts/{uuid}/publish',
        permission: ['widget_layout.publish'],
        statuses: [200, 403, 404, 409, 422, 503],
        paginated: false,
        idempotencyScope: 'layout_publish',
        responseResource: 'WidgetLayoutReleaseResource',
        requestFields: ['expected_draft_id', 'expected_lock_version'],
    },
    {
        key: 'rollbackWidgetLayout',
        method: 'POST',
        path: '/layouts/{uuid}/rollback',
        permission: ['widget_layout.rollback'],
        statuses: [200, 403, 404, 409, 422, 503],
        paginated: false,
        idempotencyScope: 'layout_rollback',
        responseResource: 'WidgetLayoutReleaseResource',
        requestFields: ['source_release_version', 'expected_draft_id', 'expected_lock_version'],
    },
]

const routesByKey = new Map(WIDGET_SITE_LAYOUT_ROUTES.map((route) => [route.key, route]))

// ---------------------------------------------------------------------------
// Error catalog -- mirrors WidgetApiErrorResponse::CODES / the fixture's
// `errors` object exactly (26 codes). `recovery` here is the CATEGORY the
// contract fixture freezes (e.g. "refresh_draft", "retry_after"), not the
// concrete per-response `recovery` payload the backend envelope may also
// carry (e.g. a retry-after second count) -- normalizeWidgetApiError()
// exposes both.
// ---------------------------------------------------------------------------

/** @type {Record<string, {status: number, retryable: boolean, recovery: string}>} */
export const WIDGET_ERROR_CODES = {
    WIDGET_DEFINITION_IN_USE: { status: 409, retryable: false, recovery: 'none' },
    WIDGET_TEMPLATE_IN_USE: { status: 409, retryable: false, recovery: 'none' },
    WIDGET_INSTANCE_IN_USE: { status: 409, retryable: false, recovery: 'none' },
    WIDGET_MODULE_IN_USE: { status: 409, retryable: false, recovery: 'none' },
    WIDGET_USAGE_CHANGED: { status: 409, retryable: true, recovery: 'refresh_draft' },
    WIDGET_IDEMPOTENCY_IN_PROGRESS: { status: 409, retryable: true, recovery: 'retry_after' },
    WIDGET_IDEMPOTENCY_MISMATCH: { status: 409, retryable: false, recovery: 'none' },
    WIDGET_PROVIDER_UNAVAILABLE: { status: 503, retryable: true, recovery: 'retry_after' },
    WIDGET_PROVIDER_INVALID: { status: 422, retryable: false, recovery: 'none' },
    WIDGET_MANIFEST_MATERIALIZATION_FAILED: { status: 503, retryable: true, recovery: 'retry_after' },
    WIDGET_MANIFEST_APPROVAL_REQUIRED: { status: 422, retryable: false, recovery: 'approval_inventory' },
    WIDGET_MANIFEST_REVOKED: { status: 409, retryable: false, recovery: 'approval_inventory' },
    WIDGET_ATTESTATION_VERSION_STALE: { status: 409, retryable: true, recovery: 'refresh_manifest' },
    WIDGET_RUNTIME_CONTROL_VERSION_STALE: { status: 409, retryable: true, recovery: 'refresh_manifest' },
    WIDGET_MANIFEST_RUNTIME_BLOCKED: { status: 409, retryable: false, recovery: 'none' },
    WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE: { status: 503, retryable: false, recovery: 'none' },
    WIDGET_REGISTRY_COMPATIBILITY_DRIFT: { status: 409, retryable: false, recovery: 'none' },
    WIDGET_ARTIFACT_INTEGRITY_FAILED: { status: 500, retryable: false, recovery: 'none' },
    WIDGET_DRAFT_STALE: { status: 409, retryable: true, recovery: 'refresh_draft' },
    WIDGET_BLOCKED: { status: 409, retryable: false, recovery: 'none' },
    WIDGET_PREVIEW_SESSION_EXPIRED: { status: 409, retryable: false, recovery: 'none' },
    WIDGET_PERMISSION_DENIED: { status: 403, retryable: false, recovery: 'none' },
    WIDGET_MANIFEST_SOURCE_INVALID: { status: 422, retryable: false, recovery: 'none' },
    WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED: { status: 422, retryable: false, recovery: 'none' },
    WIDGET_MANIFEST_NOT_FOUND: { status: 404, retryable: false, recovery: 'none' },
    WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED: { status: 422, retryable: false, recovery: 'none' },
    WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED: { status: 422, retryable: false, recovery: 'none' },
}

/**
 * Normalizes any rejection from a wrapper below into one deterministic
 * envelope so callers (the composable / state machine) never touch a raw
 * axios error. Never marks an unrecognized code, or a response-less
 * network/timeout failure, as retryable -- silently retrying an
 * unclassified failure is exactly what DoD 1360 forbids ("never auto-retry").
 *
 * @param {unknown} error
 * @returns {{
 *   aborted: boolean,
 *   status: number|null,
 *   code: string|null,
 *   message: string,
 *   correlationId: string|null,
 *   details: Record<string, unknown>|null,
 *   recoveryPayload: Record<string, unknown>|null,
 *   retryable: boolean,
 *   recovery: string,
 *   raw: unknown,
 * }}
 */
export function normalizeWidgetApiError(error) {
    const isAborted =
        error?.code === 'ERR_CANCELED' || error?.name === 'CanceledError' || axios.isCancel?.(error) === true

    if (isAborted) {
        return {
            aborted: true,
            status: null,
            code: null,
            message: i18n.global.t('text.widget.request_aborted'),
            correlationId: null,
            details: null,
            recoveryPayload: null,
            retryable: false,
            recovery: 'none',
            raw: error,
        }
    }

    const response = error?.response
    if (!response) {
        return {
            aborted: false,
            status: null,
            code: null,
            message: error?.message || i18n.global.t('text.widget.network_error'),
            correlationId: null,
            details: null,
            recoveryPayload: null,
            retryable: false,
            recovery: 'none',
            raw: error,
        }
    }

    const body = response.data ?? {}
    const code = typeof body.code === 'string' ? body.code : null
    const known = code ? WIDGET_ERROR_CODES[code] : undefined

    return {
        aborted: false,
        status: response.status,
        code,
        message: body.message || error?.message || i18n.global.t('text.widget.api_error'),
        correlationId: body.correlation_id ?? null,
        details: body.details ?? null,
        recoveryPayload: body.recovery ?? null,
        retryable: known ? known.retryable : false,
        recovery: known ? known.recovery : 'none',
        raw: error,
    }
}

// ---------------------------------------------------------------------------
// Transport helpers
// ---------------------------------------------------------------------------

const substitutePath = (path, params) =>
    path.replace(/\{([^}]+)\}/g, (_match, token) => {
        const value = params ? params[token] : undefined
        if (value === undefined || value === null || value === '') {
            throw new Error(
                `[site-layout/api] Missing required path parameter "${token}" for route "${path}".`
            )
        }
        return encodeURIComponent(String(value))
    })

/** response?.data?.data ?? response?.data ?? fallback -- handles both a
 * Laravel `->response()` JsonResource wrap (`{data: ...}`) and a raw
 * `response()->json(...)` body (no wrap); confirmed against the real Widget
 * controllers, which use BOTH conventions across these 30 routes. */
const unwrapResource = (response) => response?.data?.data ?? response?.data ?? null

/** Same dual-shape tolerance as unwrapResource, but preserves paginator
 * meta/links for the six `paginated: true` routes instead of discarding them. */
const unwrapCollection = (response) => ({
    data: response?.data?.data ?? response?.data ?? [],
    meta: response?.data?.meta ?? null,
    links: response?.data?.links ?? null,
})

const requireIdempotencyKey = (idempotencyKey, routeKey) => {
    if (typeof idempotencyKey !== 'string' || idempotencyKey.trim() === '') {
        throw new Error(
            `[site-layout/api] "${routeKey}" requires an explicit opts.idempotencyKey; ` +
                'the backend rejects a missing/blank Idempotency-Key header (never send a blind mutation).'
        )
    }
}

/**
 * Dispatches one manifest-described route. Never called with a route that
 * isn't in WIDGET_SITE_LAYOUT_ROUTES (internal helper only).
 */
async function invoke(routeKey, { pathParams, query, body, idempotencyKey, signal } = {}) {
    const route = routesByKey.get(routeKey)
    if (!route) {
        throw new Error(`[site-layout/api] Unknown route key "${routeKey}".`)
    }

    if (route.allowlistedKeys && pathParams) {
        const suppliedKeys = Object.values(pathParams)
        for (const suppliedKey of suppliedKeys) {
            if (!route.allowlistedKeys.includes(suppliedKey)) {
                throw new Error(
                    `[site-layout/api] "${routeKey}" only allows ${JSON.stringify(route.allowlistedKeys)}; ` +
                        `refusing to call it with "${suppliedKey}".`
                )
            }
        }
    }

    if (route.idempotencyScope) {
        requireIdempotencyKey(idempotencyKey, routeKey)
    }

    const url = substitutePath(route.path, pathParams)
    const config = {}
    if (query !== undefined) config.params = query
    if (signal !== undefined) config.signal = signal
    if (idempotencyKey) config.headers = { [IDEMPOTENCY_KEY_HEADER]: idempotencyKey }

    try {
        switch (route.method) {
            case 'GET':
                return await widgetSiteLayoutAjax.get(url, config)
            case 'DELETE':
                return await widgetSiteLayoutAjax.delete(url, { ...config, data: body })
            case 'POST':
                return await widgetSiteLayoutAjax.post(url, body, config)
            case 'PUT':
                return await widgetSiteLayoutAjax.put(url, body, config)
            case 'PATCH':
                return await widgetSiteLayoutAjax.patch(url, body, config)
            default:
                throw new Error(`[site-layout/api] Unsupported method "${route.method}".`)
        }
    } catch (error) {
        throw normalizeWidgetApiError(error)
    }
}

// ---------------------------------------------------------------------------
// Slots / Target Contexts (read-only catalog + generic target discovery)
// ---------------------------------------------------------------------------

/** GET /slots -> WidgetSlotResource[] */
export const listSlots = (opts = {}) =>
    invoke('listSlots', { signal: opts.signal }).then(unwrapResource)

/** GET /target-contexts -> WidgetTargetContextResource[] */
export const listTargetContexts = (opts = {}) =>
    invoke('listTargetContexts', { signal: opts.signal }).then(unwrapResource)

/** GET /target-contexts/{type}/targets -> paginated WidgetTargetResource[] */
export const searchWidgetTargets = (type, query = {}, opts = {}) =>
    invoke('searchWidgetTargets', { pathParams: { type }, query, signal: opts.signal }).then(
        unwrapCollection
    )

/** GET /target-contexts/{type}/targets/{target_id} -> WidgetTargetResource */
export const getWidgetTargetDetail = (type, targetId, opts = {}) =>
    invoke('getWidgetTargetDetail', {
        pathParams: { type, target_id: targetId },
        signal: opts.signal,
    }).then(unwrapResource)

// ---------------------------------------------------------------------------
// Health / registry preflight (the fixture defines no separate registry-
// preflight route; GET /health's own `registry_drift` section IS the admin-
// facing registry-compatibility surface -- see WidgetRuntimeHealthController).
// ---------------------------------------------------------------------------

/** GET /health -> { registry_drift, provider_usage, materialization, blob_manifest_integrity, emergency_block } */
export const getWidgetSiteLayoutHealth = (opts = {}) =>
    invoke('getWidgetSiteLayoutHealth', { signal: opts.signal }).then(unwrapResource)

// ---------------------------------------------------------------------------
// Catalog: Widget/Theme definitions (eligible-only projection) + Manifest
// approval inventory + materialize/approve/revoke/emergency-control
// ---------------------------------------------------------------------------

/** GET /catalog/definitions -> paginated WidgetDefinitionCatalogResource[] (eligible only) */
export const listWidgetDefinitionCatalog = (query = {}, opts = {}) =>
    invoke('listWidgetDefinitionCatalog', { query, signal: opts.signal }).then(unwrapCollection)

/** GET /catalog/definitions/{widget} -> WidgetDefinitionCatalogResource */
export const getWidgetDefinitionCatalogDetail = (widgetId, opts = {}) =>
    invoke('getWidgetDefinitionCatalogDetail', {
        pathParams: { widget: widgetId },
        signal: opts.signal,
    }).then(unwrapResource)

/** GET /catalog/manifests -> paginated WidgetArtifactManifestResource[] (approval inventory) */
export const listWidgetArtifactManifests = (query = {}, opts = {}) =>
    invoke('listWidgetArtifactManifests', { query, signal: opts.signal }).then(unwrapCollection)

/**
 * GET /catalog/manifests/source-fingerprint -> {widget_id, widget_theme_id,
 * source_fingerprint} (route 32, pure read). The client echoes
 * `source_fingerprint` back verbatim as materialize's
 * expected_source_fingerprint so the optimistic check stays meaningful.
 */
export const getWidgetManifestSourceFingerprint = (query, opts = {}) =>
    invoke('getWidgetManifestSourceFingerprint', { query, signal: opts.signal }).then(unwrapResource)

/**
 * POST /catalog/manifests/materialize -> WidgetArtifactManifestResource.
 * 201 = newly created pending Manifest, 200 = exact reuse; both resolve the
 * same shape here, matching the frozen contract's own "no project selector,
 * server-derived" design (never call twice believing 200 means "unchanged,
 * skip install").
 */
export const materializeWidgetManifest = (body, opts = {}) =>
    invoke('materializeWidgetManifest', { body, signal: opts.signal }).then(unwrapResource)

/** POST /catalog/manifests/{manifest_hash}/approval -> WidgetArtifactManifestResource (pending/revoked -> approved) */
export const approveWidgetManifest = (manifestHash, body, opts = {}) =>
    invoke('approveWidgetManifest', {
        pathParams: { manifest_hash: manifestHash },
        body,
        signal: opts.signal,
    }).then(unwrapResource)

/** DELETE /catalog/manifests/{manifest_hash}/approval -> WidgetArtifactManifestResource (approved -> revoked only) */
export const revokeWidgetManifest = (manifestHash, body, opts = {}) =>
    invoke('revokeWidgetManifest', {
        pathParams: { manifest_hash: manifestHash },
        body,
        signal: opts.signal,
    }).then(unwrapResource)

/**
 * POST /catalog/manifests/{manifest_hash}/runtime-block -> WidgetArtifactManifestResource.
 * Emergency control axis, independent from eligibility approval. Models ONLY
 * 200 completion or a thrown normalized error (403/404/409/422/503) -- never
 * a 202/pending outcome (D-008: no acknowledged-partial state exists here).
 * A 503 WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE happens BEFORE any row
 * lookup on the server, so `expected_control_version` the caller already
 * holds is unaffected by the failed attempt; site-layout-state.js is the
 * layer responsible for not discarding it and never auto-retrying.
 */
export const blockWidgetManifestRuntime = (manifestHash, body, opts = {}) =>
    invoke('blockWidgetManifestRuntime', {
        pathParams: { manifest_hash: manifestHash },
        body,
        signal: opts.signal,
    }).then(unwrapResource)

/** DELETE /catalog/manifests/{manifest_hash}/runtime-block -> WidgetArtifactManifestResource (blocked -> unblocked) */
export const clearWidgetManifestRuntimeBlock = (manifestHash, body, opts = {}) =>
    invoke('clearWidgetManifestRuntimeBlock', {
        pathParams: { manifest_hash: manifestHash },
        body,
        signal: opts.signal,
    }).then(unwrapResource)

// ---------------------------------------------------------------------------
// Instances
// ---------------------------------------------------------------------------

/** GET /instances -> paginated WidgetInstanceResource[] */
export const listWidgetInstances = (query = {}, opts = {}) =>
    invoke('listWidgetInstances', { query, signal: opts.signal }).then(unwrapCollection)

/**
 * POST /instances -> WidgetInstanceResource (201).
 * `opts.idempotencyKey` is REQUIRED -- the backend's StoreWidgetInstanceRequest
 * rejects a missing/blank Idempotency-Key header outright.
 */
export const createWidgetInstance = (body, opts = {}) =>
    invoke('createWidgetInstance', {
        body,
        idempotencyKey: opts.idempotencyKey,
        signal: opts.signal,
    }).then(unwrapResource)

/** GET /instances/{uuid} -> WidgetInstanceResource */
export const getWidgetInstance = (uuid, opts = {}) =>
    invoke('getWidgetInstance', { pathParams: { uuid }, signal: opts.signal }).then(unwrapResource)

/**
 * PATCH /instances/{uuid} -> WidgetInstanceResource. Standalone update: persists
 * a ready Version WITHOUT repinning any Layout Draft. `opts.idempotencyKey` REQUIRED.
 */
export const updateWidgetInstance = (uuid, body, opts = {}) =>
    invoke('updateWidgetInstance', {
        pathParams: { uuid },
        body,
        idempotencyKey: opts.idempotencyKey,
        signal: opts.signal,
    }).then(unwrapResource)

/** DELETE /instances/{uuid} -> { status: 'success' } */
export const deleteWidgetInstance = (uuid, opts = {}) =>
    invoke('deleteWidgetInstance', { pathParams: { uuid }, signal: opts.signal }).then(unwrapResource)

/** GET /instances/{uuid}/usage -> raw usage payload (no formal Resource per the fixture) */
export const getWidgetInstanceUsage = (uuid, opts = {}) =>
    invoke('getWidgetInstanceUsage', { pathParams: { uuid }, signal: opts.signal }).then(unwrapResource)

/** POST /instances/{uuid}/clone -> WidgetInstanceResource (201). `opts.idempotencyKey` REQUIRED. */
export const cloneWidgetInstance = (uuid, body, opts = {}) =>
    invoke('cloneWidgetInstance', {
        pathParams: { uuid },
        body,
        idempotencyKey: opts.idempotencyKey,
        signal: opts.signal,
    }).then(unwrapResource)

/** POST /instances/{uuid}/archive -> WidgetInstanceResource (no request body) */
export const archiveWidgetInstance = (uuid, opts = {}) =>
    invoke('archiveWidgetInstance', { pathParams: { uuid }, signal: opts.signal }).then(unwrapResource)

// ---------------------------------------------------------------------------
// Layouts: identity, bootstrap, draft, repin, releases, preview, publish, rollback
// ---------------------------------------------------------------------------

/** GET /layouts -> paginated WidgetLayoutResource[] */
export const listWidgetLayouts = (query = {}, opts = {}) =>
    invoke('listWidgetLayouts', { query, signal: opts.signal }).then(unwrapCollection)

/**
 * PUT /layouts/by-key/{layout_key} -> WidgetLayoutResource (201 create, 200
 * existing). THE explicit idempotent Site Layout entry point (DoD 1357): call
 * this directly on entry, never a GET-then-create dance, never a locally
 * fabricated Draft. Both 201 and 200 install the SAME way -- the caller must
 * not special-case either as a no-op. `layoutKey` defaults to the only
 * currently allowlisted key ('site'); a non-allowlisted key throws before any
 * network call (client-side mirror of the fixture's own `allowlisted_keys`).
 */
export const bootstrapWidgetLayoutByKey = (layoutKey = 'site', opts = {}) =>
    invoke('bootstrapWidgetLayoutByKey', {
        pathParams: { layout_key: layoutKey },
        signal: opts.signal,
    }).then(unwrapResource)

/** GET /layouts/{uuid} -> WidgetLayoutResource */
export const getWidgetLayout = (uuid, opts = {}) =>
    invoke('getWidgetLayout', { pathParams: { uuid }, signal: opts.signal }).then(unwrapResource)

/**
 * PUT /layouts/{uuid}/draft -> WidgetPlacementResource[] (the saved Placements,
 * normalized order). Concurrency-safe via `expected_current_draft_id` +
 * `expected_lock_version` recheck under a row lock, NOT the Idempotency-Key
 * header protocol (idempotencyScope is null for this route by design). The
 * response body intentionally carries ONLY the placements array -- the
 * Draft's own identity (`version`/`lock_version`) is never re-exposed here
 * (WidgetLayoutDraftService: "no raw row id is EVER exposed to a client...
 * the frozen WidgetLayoutResource.current_draft nested field list is exactly
 * [version, lock_version]"). site-layout-state.js derives the accepted
 * `lock_version` deterministically as `expected_lock_version + 1` on success,
 * since the service increments it by exactly one on every successful save
 * and never changes the Draft's own `version` number in place.
 */
export const saveWidgetLayoutDraft = (uuid, body, opts = {}) =>
    invoke('saveWidgetLayoutDraft', { pathParams: { uuid }, body, signal: opts.signal }).then(
        unwrapResource
    )

/**
 * GET /layouts/{uuid}/draft -> WidgetLayoutDraftResource ({version, lock_version,
 * placements[], instance_fingerprints}). Pure-read companion to
 * saveWidgetLayoutDraft, added by a disclosed post-freeze contract evolution
 * (routes 30->31, see the fixture's own `note` on this route): returns the
 * Layout's CURRENT Draft so the admin canvas can reload and safely edit a
 * previously-saved Draft on entry instead of always starting empty.
 * `placements[]` items carry the exact same frozen field set as
 * saveWidgetLayoutDraft's own response (uuid, slot_key, scope_type, scope_key,
 * target_type, target_id, widget_instance_uuid, widget_instance_version,
 * position, enabled, rules) -- `target_id` stays an opaque string end-to-end,
 * never coerced. `instance_fingerprints` is a
 * map<widget_instance_uuid, usage_fingerprint> -- one server-issued repin token
 * per Instance any Placement references, exactly the value
 * repinWidgetLayoutDraftInstance validates its `usage_fingerprint` against, so
 * the Inspector's Apply-to-Draft echoes it back verbatim (never recomputed).
 * use-widget-site-layout.js's bootstrap() calls this immediately after a
 * successful bootstrapWidgetLayoutByKey() to seed baselineDraft/localDraft's
 * placements and install instanceFingerprints into shared state.
 */
export const getWidgetLayoutDraft = (uuid, opts = {}) =>
    invoke('getWidgetLayoutDraft', { pathParams: { uuid }, signal: opts.signal }).then(unwrapResource)

/**
 * POST /layouts/{uuid}/draft/instances/{instance_uuid}/versions-and-repin ->
 * { instance: WidgetInstanceResource, draft: {version, lock_version, placements}, sync_status }.
 * Atomic: a failure never returns a partially-updated Instance/Draft pair.
 * `opts.idempotencyKey` REQUIRED.
 */
export const repinWidgetLayoutDraftInstance = (uuid, instanceUuid, body, opts = {}) =>
    invoke('repinWidgetLayoutDraftInstance', {
        pathParams: { uuid, instance_uuid: instanceUuid },
        body,
        idempotencyKey: opts.idempotencyKey,
        signal: opts.signal,
    }).then(unwrapResource)

// Placement content (Unified Widget Catalog Part 2, Task 3): each placement owns its content,
// so the area editor never manages instances. Each call resolves to the current draft
// ({version, lock_version, placements, instance_fingerprints}) plus `placementUuid`, the
// placement it created or edited (placement uuids change on every full draft save).
// `body.expected_draft_revision` is the draft's lock_version; `opts.idempotencyKey` REQUIRED.
const unwrapDraftWithPlacement = (response) => ({
    ...unwrapResource(response),
    placementUuid: response?.data?.meta?.placement_uuid ?? null,
})

/** POST /layouts/{uuid}/draft/placements -> widget + content + placement in one call. */
export const createWidgetLayoutDraftPlacement = (uuid, body, opts = {}) =>
    invoke('createWidgetLayoutDraftPlacement', {
        pathParams: { uuid },
        body,
        idempotencyKey: opts.idempotencyKey,
        signal: opts.signal,
    }).then(unwrapDraftWithPlacement)

/** PATCH /layouts/{uuid}/draft/placements/{placement_uuid}/content -> new content for one placement. */
export const updateWidgetLayoutDraftPlacementContent = (uuid, placementUuid, body, opts = {}) =>
    invoke('updateWidgetLayoutDraftPlacementContent', {
        pathParams: { uuid, placement_uuid: placementUuid },
        body,
        idempotencyKey: opts.idempotencyKey,
        signal: opts.signal,
    }).then(unwrapDraftWithPlacement)

/** POST /layouts/{uuid}/draft/placements/{placement_uuid}/duplicate -> copy with its own content. */
export const duplicateWidgetLayoutDraftPlacement = (uuid, placementUuid, body, opts = {}) =>
    invoke('duplicateWidgetLayoutDraftPlacement', {
        pathParams: { uuid, placement_uuid: placementUuid },
        body,
        idempotencyKey: opts.idempotencyKey,
        signal: opts.signal,
    }).then(unwrapDraftWithPlacement)

/**
 * GET /slots with its `meta.area_count`: the shared areas of the active theme and how many there are. The area editor
 * and its menu entry show only when the count is above 0.
 *
 * @returns {Promise<{slots: object[], areaCount: number}>}
 */
export const listSlotsWithAreaCount = (opts = {}) =>
    invoke('listSlots', { signal: opts.signal }).then((response) => {
        const slots = unwrapResource(response) ?? []
        return { slots, areaCount: Number(response?.data?.meta?.area_count ?? slots.length) }
    })

/** Number of shared areas of the active theme; 0 when the read is refused or fails. */
export const getAreaCount = () =>
    listSlotsWithAreaCount()
        .then((result) => result.areaCount)
        .catch(() => 0)

// Rows per request of the widget list below; the list is paginated by the server and every page is read.
const WIDGET_LIST_PAGE_SIZE = 100
const WIDGET_LIST_MAX_PAGES = 100

/**
 * Every widget of this site, for step 1 of the add drawer. Core endpoint `GET widget/admin-list`, read through this
 * module's own client (the Widget admin never imports another module's admin code). The client raises no toast, so a
 * refused read stays silent and the drawer shows its own state.
 *
 * @returns {Promise<Array<{id: number, name: string, is_blocked: boolean, version: number, template: Array<{id: number, name: string}>, inputs: object[]}>>}
 */
export const listPlaceableWidgets = async (opts = {}) => {
    const rows = []
    try {
        for (let page = 1; page <= WIDGET_LIST_MAX_PAGES; page++) {
            const response = await widgetSiteLayoutAjax.get('admin-list', {
                // `placeable=1`: only designs a placement on this site accepts (others answer 422 on add).
                params: { pageSize: WIDGET_LIST_PAGE_SIZE, page, placeable: 1 },
                signal: opts.signal,
            })
            if (response?.data?.status === 'error') {
                throw Object.assign(new Error('widget list refused'), { response })
            }
            const payload = response?.data?.data
            const pageRows = Array.isArray(payload) ? payload : (payload?.data ?? [])
            rows.push(...pageRows)
            const lastPage = Number(payload?.last_page ?? 1)
            if (Array.isArray(payload) || page >= lastPage || pageRows.length === 0) break
        }
    } catch (error) {
        throw normalizeWidgetApiError(error)
    }
    return rows
}

/**
 * Rendered HTML of one widget design with its default content (Core `POST widget/{id}/preview`), for the small
 * pictures in the add drawer. Empty string when the server returns no HTML.
 *
 * @returns {Promise<string>}
 */
export const getWidgetThumbnail = (widgetId, themeId, opts = {}) =>
    widgetSiteLayoutAjax
        .post(`${widgetId}/preview`, { template_id: themeId }, { signal: opts.signal })
        .then((response) => (typeof response?.data === 'string' ? response.data : ''))
        .catch((error) => {
            throw normalizeWidgetApiError(error)
        })

/**
 * GET /layouts/{uuid}/draft/changes -> [{kind: added|removed|changed, slot_key, slot_label,
 * slot_label_key, widget_name, scope_type, scope_label}] -- what publishing would change on the site.
 * A null label means "not translated server-side": translate slot_label_key / scope_type instead.
 */
export const getWidgetLayoutDraftChanges = (uuid, opts = {}) =>
    invoke('getWidgetLayoutDraftChanges', { pathParams: { uuid }, signal: opts.signal }).then(
        (response) => response?.data?.data ?? []
    )

/** GET /layouts/{uuid}/releases -> paginated WidgetLayoutReleaseResource[] (+ published_by_name, is_live) */
export const listWidgetLayoutReleases = (uuid, query = {}, opts = {}) =>
    invoke('listWidgetLayoutReleases', { pathParams: { uuid }, query, signal: opts.signal }).then(
        unwrapCollection
    )

/**
 * POST /layouts/{uuid}/preview-sessions -> { preview_url, expires_at, fingerprint, diagnostics }
 * (ad hoc payload; the fixture declares no formal Resource for this route).
 * `overlay` MUST reference only persisted Instance Version ids -- never
 * ephemeral/unsaved Instance values (architecture.md constraint mirrored by
 * the fixture's own `overlay_constraints`). Not idempotency-scoped (mints a
 * fresh session every call by design).
 */
export const createWidgetLayoutPreviewSession = (uuid, body, opts = {}) =>
    invoke('createWidgetLayoutPreviewSession', {
        pathParams: { uuid },
        body,
        signal: opts.signal,
    }).then(unwrapResource)

/**
 * POST /layouts/{uuid}/publish -> { release: WidgetLayoutReleaseResource, draft: {version, lock_version}, sync_status }.
 * Always carries BOTH the new Release identity and the fresh Draft's
 * identity/lock -- never only one. `opts.idempotencyKey` REQUIRED.
 */
export const publishWidgetLayout = (uuid, body, opts = {}) =>
    invoke('publishWidgetLayout', {
        pathParams: { uuid },
        body,
        idempotencyKey: opts.idempotencyKey,
        signal: opts.signal,
    }).then(unwrapResource)

/**
 * POST /layouts/{uuid}/rollback -> { release: WidgetLayoutReleaseResource, draft: {version, lock_version}, sync_status }.
 * Reserves a brand-new Release (`rollback_of_version` pointing at the source)
 * plus a fresh Draft; never reactivates the historical Release row in place.
 * `opts.idempotencyKey` REQUIRED.
 */
export const rollbackWidgetLayout = (uuid, body, opts = {}) =>
    invoke('rollbackWidgetLayout', {
        pathParams: { uuid },
        body,
        idempotencyKey: opts.idempotencyKey,
        signal: opts.signal,
    }).then(unwrapResource)
