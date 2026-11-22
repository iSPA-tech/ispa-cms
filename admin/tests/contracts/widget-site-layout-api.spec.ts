/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Chunk 3 Task 25: proves src/modules/site-layout/admin/api.js mirrors THE
// frozen contract fixture (cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json)
// byte-for-field: every one of its 36 routes (method, path, permission,
// statuses, paginated, idempotency_scope, response_resource, request_fields)
// and every one of its 27 error codes (status, retryable, recovery). Route 31
// (GET /layouts/{uuid}/draft) is a disclosed post-freeze contract evolution
// (routes 30->31) that closed a gap Task 25/27 flagged: a pure-read companion
// to PUT /layouts/{uuid}/draft returning the current Draft's placements[] so
// the admin canvas can reload a saved Draft on entry. The fixture is read
// directly from the sibling ispa-cms repo (the same cross-repo pattern
// vitest.config.ts already uses for the admin module aliases) so this test
// can never silently drift from the backend's own source of truth.

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// ---------------------------------------------------------------------------
// Hoisted mock axios client shared between the vi.mock('axios') factory and
// the test bodies below (vi.mock factories are hoisted above imports).
// ---------------------------------------------------------------------------
const mocks = vi.hoisted(() => {
    const client = {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        interceptors: { request: { use: vi.fn() }, response: { use: vi.fn() } },
    }
    return { client, create: vi.fn(() => client) }
})

vi.mock('axios', () => ({
    default: {
        create: mocks.create,
        isCancel: vi.fn(() => false),
    },
}))

// The project's global vitest setup (tests/setup/vitest.setup.ts) mocks
// `@/config` with a hand-picked subset of keys that does not include
// COOKIE_KEY_TOKEN. api.js reads COOKIE_KEY_TOKEN at module load time (same
// as every other src/modules/*/admin client), so this file layers a local
// override on top: `importOriginal` pulls in every REAL export (so nothing
// api.js needs is ever missing), then apiUrl/cookie are pinned to
// deterministic test values.
vi.mock('@/config', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()
    return {
        ...actual,
        apiUrl: 'https://example.com/',
        cookie: { get: vi.fn(), set: vi.fn(), remove: vi.fn(), getAll: vi.fn() },
    }
})

const CONTRACT_PATH = resolve(
    __dirname,
    '../../../cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json'
)

const contract = JSON.parse(readFileSync(CONTRACT_PATH, 'utf-8'))

const findContractRoute = (method: string, path: string) =>
    contract.routes.find((route: any) => route.method === method && route.path === path)

// ---------------------------------------------------------------------------
// System under test
// ---------------------------------------------------------------------------
import * as api from '../../../cms/Widget/admin/api'

const okResponse = (data: unknown, status = 200) => ({ status, data })

beforeEach(() => {
    vi.clearAllMocks()
})

// =============================================================================
// 1. The route manifest mirrors the frozen contract exactly
// =============================================================================
describe('WIDGET_SITE_LAYOUT_ROUTES mirrors the frozen contract exactly', () => {
    it('declares exactly 36 routes, matching the fixture note_route_count', () => {
        expect(contract.routes).toHaveLength(36)
        expect(api.WIDGET_SITE_LAYOUT_ROUTES).toHaveLength(36)
    })

    it('has one manifest entry per contract route with identical method+path', () => {
        for (const contractRoute of contract.routes) {
            const manifestRoute = findContractRoute
                ? api.WIDGET_SITE_LAYOUT_ROUTES.find(
                      (r) => r.method === contractRoute.method && r.path === contractRoute.path
                  )
                : undefined
            expect(
                manifestRoute,
                `missing wrapped route for ${contractRoute.method} ${contractRoute.path}`
            ).toBeTruthy()
        }
    })

    it('has no extra manifest entries beyond the frozen 31 routes', () => {
        for (const manifestRoute of api.WIDGET_SITE_LAYOUT_ROUTES) {
            const contractRoute = findContractRoute(manifestRoute.method, manifestRoute.path)
            expect(
                contractRoute,
                `manifest declares an unfrozen route ${manifestRoute.method} ${manifestRoute.path}`
            ).toBeTruthy()
        }
    })

    it.each(
        // Parameterize on the manifest so every route gets its own assertion
        // (contract.routes is loaded at module scope; safe to reference here).
        (JSON.parse(readFileSync(CONTRACT_PATH, 'utf-8')).routes as any[]).map((r) => [
            `${r.method} ${r.path}`,
            r,
        ])
    )('%s matches permission/statuses/paginated/idempotency_scope/response_resource/request_fields', (_label, contractRoute) => {
        const manifestRoute = api.WIDGET_SITE_LAYOUT_ROUTES.find(
            (r) => r.method === contractRoute.method && r.path === contractRoute.path
        )
        expect(manifestRoute).toBeTruthy()
        expect(manifestRoute!.permission).toEqual(contractRoute.permission)
        expect(manifestRoute!.statuses).toEqual(contractRoute.statuses)
        expect(manifestRoute!.paginated).toBe(contractRoute.paginated)
        expect(manifestRoute!.idempotencyScope).toBe(contractRoute.idempotency_scope)
        expect(manifestRoute!.responseResource).toBe(contractRoute.response_resource)
        expect(manifestRoute!.requestFields).toEqual(contractRoute.request_fields)
        if (contractRoute.allowlisted_keys) {
            expect(manifestRoute!.allowlistedKeys).toEqual(contractRoute.allowlisted_keys)
        }
    })

    it('marks exactly the nine idempotency-key-header actions with a non-null scope', () => {
        const scoped = api.WIDGET_SITE_LAYOUT_ROUTES.filter((r) => r.idempotencyScope !== null).map(
            (r) => r.idempotencyScope
        )
        expect(scoped.sort()).toEqual(
            [
                'instance_create',
                'instance_update',
                'instance_clone',
                'layout_repin',
                'placement_create',
                'placement_content_update',
                'placement_duplicate',
                'layout_publish',
                'layout_rollback',
            ].sort()
        )
    })
})

// =============================================================================
// 2. The error catalog mirrors the frozen contract exactly
// =============================================================================
describe('WIDGET_ERROR_CODES mirrors the frozen contract exactly', () => {
    it('declares exactly the same 27 codes as the fixture, no more, no less', () => {
        const contractCodes = Object.keys(contract.errors).sort()
        const manifestCodes = Object.keys(api.WIDGET_ERROR_CODES).sort()
        expect(manifestCodes).toEqual(contractCodes)
        expect(manifestCodes).toHaveLength(27)
    })

    it.each(Object.entries(JSON.parse(readFileSync(CONTRACT_PATH, 'utf-8')).errors))(
        '%s matches status/retryable/recovery',
        (code, definition: any) => {
            expect(api.WIDGET_ERROR_CODES[code as string]).toEqual({
                status: definition.status,
                retryable: definition.retryable,
                recovery: definition.recovery,
            })
        }
    )
})

// =============================================================================
// 3. normalizeWidgetApiError classifies every frozen error code + edge cases
// =============================================================================
describe('normalizeWidgetApiError', () => {
    it.each(Object.entries(JSON.parse(readFileSync(CONTRACT_PATH, 'utf-8')).errors))(
        'classifies %s using the frozen retryable/recovery mapping',
        (code, definition: any) => {
            const axiosError = {
                response: {
                    status: definition.status,
                    data: {
                        status: definition.status,
                        code,
                        message: 'Something happened.',
                        correlation_id: 'corr-1',
                    },
                },
            }

            const normalized = api.normalizeWidgetApiError(axiosError)

            expect(normalized.status).toBe(definition.status)
            expect(normalized.code).toBe(code)
            expect(normalized.retryable).toBe(definition.retryable)
            expect(normalized.recovery).toBe(definition.recovery)
            expect(normalized.correlationId).toBe('corr-1')
            expect(normalized.aborted).toBe(false)
        }
    )

    it('treats an unrecognized code as non-retryable with no recovery (safe default)', () => {
        const normalized = api.normalizeWidgetApiError({
            response: { status: 400, data: { code: 'SOMETHING_NEW', message: 'Unknown.' } },
        })
        expect(normalized.retryable).toBe(false)
        expect(normalized.recovery).toBe('none')
    })

    it('treats a response-less network/timeout failure as non-retryable (never auto-retry)', () => {
        const normalized = api.normalizeWidgetApiError({ message: 'Network Error' })
        expect(normalized.status).toBeNull()
        expect(normalized.code).toBeNull()
        expect(normalized.retryable).toBe(false)
        expect(normalized.recovery).toBe('none')
        expect(normalized.aborted).toBe(false)
    })

    it('flags a canceled/aborted request distinctly from a real failure', () => {
        const normalized = api.normalizeWidgetApiError({ name: 'CanceledError', code: 'ERR_CANCELED' })
        expect(normalized.aborted).toBe(true)
        expect(normalized.retryable).toBe(false)
    })
})

// =============================================================================
// 4. Every wrapped endpoint issues the frozen method + path
// =============================================================================
describe('wrapped endpoints issue the frozen method and path', () => {
    it('GET /slots', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ data: [] }))
        await api.listSlots()
        expect(mocks.client.get).toHaveBeenCalledWith('/slots', expect.any(Object))
    })

    it('GET /target-contexts', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ data: [] }))
        await api.listTargetContexts()
        expect(mocks.client.get).toHaveBeenCalledWith('/target-contexts', expect.any(Object))
    })

    it('GET /target-contexts/{type}/targets forwards search/page/per_page as query params', async () => {
        mocks.client.get.mockResolvedValueOnce(
            okResponse({ data: [{ target_id: '1' }], meta: { total: 1 }, links: {} })
        )
        const result = await api.searchWidgetTargets('page', { search: 'home', page: 2, per_page: 10 })
        expect(mocks.client.get).toHaveBeenCalledWith(
            '/target-contexts/page/targets',
            expect.objectContaining({ params: { search: 'home', page: 2, per_page: 10 } })
        )
        expect(result.data).toEqual([{ target_id: '1' }])
        expect(result.meta).toEqual({ total: 1 })
    })

    it('GET /target-contexts/{type}/targets/{target_id}', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ data: { target_id: '42' } }))
        await api.getWidgetTargetDetail('page', '42')
        expect(mocks.client.get).toHaveBeenCalledWith(
            '/target-contexts/page/targets/42',
            expect.any(Object)
        )
    })

    it('GET /health', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ registry_drift: [] }))
        await api.getWidgetSiteLayoutHealth()
        expect(mocks.client.get).toHaveBeenCalledWith('/health', expect.any(Object))
    })

    it('GET /catalog/definitions', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ data: [] }))
        await api.listWidgetDefinitionCatalog()
        expect(mocks.client.get).toHaveBeenCalledWith('/catalog/definitions', expect.any(Object))
    })

    it('GET /catalog/definitions/{widget}', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ data: {} }))
        await api.getWidgetDefinitionCatalogDetail('hero-banner')
        expect(mocks.client.get).toHaveBeenCalledWith(
            '/catalog/definitions/hero-banner',
            expect.any(Object)
        )
    })

    it('GET /catalog/manifests', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ data: [] }))
        await api.listWidgetArtifactManifests()
        expect(mocks.client.get).toHaveBeenCalledWith('/catalog/manifests', expect.any(Object))
    })

    it('POST /catalog/manifests/materialize', async () => {
        mocks.client.post.mockResolvedValueOnce(okResponse({ manifest_hash: 'abc' }, 201))
        await api.materializeWidgetManifest({
            widget_id: 1,
            widget_theme_id: 2,
            expected_source_fingerprint: 'fp-1',
        })
        expect(mocks.client.post).toHaveBeenCalledWith(
            '/catalog/manifests/materialize',
            { widget_id: 1, widget_theme_id: 2, expected_source_fingerprint: 'fp-1' },
            expect.any(Object)
        )
    })

    it('POST /catalog/manifests/{manifest_hash}/approval', async () => {
        mocks.client.post.mockResolvedValueOnce(okResponse({ data: { manifest_hash: 'hash1' } }))
        await api.approveWidgetManifest('hash1', { expected_attestation_version: 1, reason: 'ok' })
        expect(mocks.client.post).toHaveBeenCalledWith(
            '/catalog/manifests/hash1/approval',
            { expected_attestation_version: 1, reason: 'ok' },
            expect.any(Object)
        )
    })

    it('DELETE /catalog/manifests/{manifest_hash}/approval', async () => {
        mocks.client.delete.mockResolvedValueOnce(okResponse({ data: { manifest_hash: 'hash1' } }))
        await api.revokeWidgetManifest('hash1', { expected_attestation_version: 2, reason: 'bad' })
        expect(mocks.client.delete).toHaveBeenCalledWith(
            '/catalog/manifests/hash1/approval',
            expect.objectContaining({ data: { expected_attestation_version: 2, reason: 'bad' } })
        )
    })

    it('POST /catalog/manifests/{manifest_hash}/runtime-block', async () => {
        mocks.client.post.mockResolvedValueOnce(okResponse({ data: { manifest_hash: 'hash1' } }))
        await api.blockWidgetManifestRuntime('hash1', { expected_control_version: 1, reason: 'incident' })
        expect(mocks.client.post).toHaveBeenCalledWith(
            '/catalog/manifests/hash1/runtime-block',
            { expected_control_version: 1, reason: 'incident' },
            expect.any(Object)
        )
    })

    it('DELETE /catalog/manifests/{manifest_hash}/runtime-block', async () => {
        mocks.client.delete.mockResolvedValueOnce(okResponse({ data: { manifest_hash: 'hash1' } }))
        await api.clearWidgetManifestRuntimeBlock('hash1', {
            expected_control_version: 2,
            reason: 'resolved',
        })
        expect(mocks.client.delete).toHaveBeenCalledWith(
            '/catalog/manifests/hash1/runtime-block',
            expect.objectContaining({ data: { expected_control_version: 2, reason: 'resolved' } })
        )
    })

    it('GET /instances', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ data: [] }))
        await api.listWidgetInstances()
        expect(mocks.client.get).toHaveBeenCalledWith('/instances', expect.any(Object))
    })

    it('POST /instances requires and forwards the Idempotency-Key header', async () => {
        mocks.client.post.mockResolvedValueOnce(okResponse({ data: { uuid: 'i-1' } }, 201))
        await api.createWidgetInstance(
            { name: 'Hero', widget_id: 1, widget_theme_id: 2, values: {}, provider_contract: {} },
            { idempotencyKey: 'key-create-1' }
        )
        expect(mocks.client.post).toHaveBeenCalledWith(
            '/instances',
            { name: 'Hero', widget_id: 1, widget_theme_id: 2, values: {}, provider_contract: {} },
            expect.objectContaining({ headers: { 'Idempotency-Key': 'key-create-1' } })
        )
    })

    it('POST /instances rejects without ever calling the client when idempotencyKey is missing', async () => {
        await expect(
            api.createWidgetInstance({
                name: 'Hero',
                widget_id: 1,
                widget_theme_id: 2,
                values: {},
                provider_contract: {},
            })
        ).rejects.toThrow()
        expect(mocks.client.post).not.toHaveBeenCalled()
    })

    it('GET /instances/{uuid}', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ data: { uuid: 'i-1' } }))
        await api.getWidgetInstance('i-1')
        expect(mocks.client.get).toHaveBeenCalledWith('/instances/i-1', expect.any(Object))
    })

    it('PATCH /instances/{uuid} requires and forwards the Idempotency-Key header', async () => {
        mocks.client.patch.mockResolvedValueOnce(okResponse({ data: { uuid: 'i-1' } }))
        await api.updateWidgetInstance(
            'i-1',
            { expected_current_version: 1, widget_id: 1, widget_theme_id: 2, values: {}, provider_contract: {} },
            { idempotencyKey: 'key-update-1' }
        )
        expect(mocks.client.patch).toHaveBeenCalledWith(
            '/instances/i-1',
            { expected_current_version: 1, widget_id: 1, widget_theme_id: 2, values: {}, provider_contract: {} },
            expect.objectContaining({ headers: { 'Idempotency-Key': 'key-update-1' } })
        )
    })

    it('DELETE /instances/{uuid}', async () => {
        mocks.client.delete.mockResolvedValueOnce(okResponse({ status: 'success' }))
        await api.deleteWidgetInstance('i-1')
        expect(mocks.client.delete).toHaveBeenCalledWith('/instances/i-1', expect.any(Object))
    })

    it('GET /instances/{uuid}/usage', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ data: { placements: [] } }))
        await api.getWidgetInstanceUsage('i-1')
        expect(mocks.client.get).toHaveBeenCalledWith('/instances/i-1/usage', expect.any(Object))
    })

    it('POST /instances/{uuid}/clone requires and forwards the Idempotency-Key header', async () => {
        mocks.client.post.mockResolvedValueOnce(okResponse({ data: { uuid: 'i-2' } }, 201))
        await api.cloneWidgetInstance('i-1', { name: 'Hero Copy' }, { idempotencyKey: 'key-clone-1' })
        expect(mocks.client.post).toHaveBeenCalledWith(
            '/instances/i-1/clone',
            { name: 'Hero Copy' },
            expect.objectContaining({ headers: { 'Idempotency-Key': 'key-clone-1' } })
        )
    })

    it('POST /instances/{uuid}/archive', async () => {
        mocks.client.post.mockResolvedValueOnce(okResponse({ data: { uuid: 'i-1', status: 'archived' } }))
        await api.archiveWidgetInstance('i-1')
        expect(mocks.client.post).toHaveBeenCalledWith('/instances/i-1/archive', undefined, expect.any(Object))
    })

    it('GET /layouts', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ data: [] }))
        await api.listWidgetLayouts()
        expect(mocks.client.get).toHaveBeenCalledWith('/layouts', expect.any(Object))
    })

    it('PUT /layouts/by-key/{layout_key} calls the explicit idempotent bootstrap route, never a GET', async () => {
        mocks.client.put.mockResolvedValueOnce(
            okResponse({ data: { uuid: 'l-1', current_draft: { version: 1, lock_version: 0 } } }, 201)
        )
        await api.bootstrapWidgetLayoutByKey('site')
        expect(mocks.client.put).toHaveBeenCalledWith('/layouts/by-key/site', undefined, expect.any(Object))
        expect(mocks.client.get).not.toHaveBeenCalled()
    })

    it('PUT /layouts/by-key/{layout_key} rejects a non-allowlisted key without calling the client', async () => {
        await expect(api.bootstrapWidgetLayoutByKey('checkout')).rejects.toThrow()
        expect(mocks.client.put).not.toHaveBeenCalled()
    })

    it('GET /layouts/{uuid}', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ data: { uuid: 'l-1' } }))
        await api.getWidgetLayout('l-1')
        expect(mocks.client.get).toHaveBeenCalledWith('/layouts/l-1', expect.any(Object))
    })

    it('PUT /layouts/{uuid}/draft', async () => {
        mocks.client.put.mockResolvedValueOnce(okResponse({ data: [] }))
        await api.saveWidgetLayoutDraft('l-1', {
            expected_current_draft_id: 1,
            expected_lock_version: 0,
            placements: [],
        })
        expect(mocks.client.put).toHaveBeenCalledWith(
            '/layouts/l-1/draft',
            { expected_current_draft_id: 1, expected_lock_version: 0, placements: [] },
            expect.any(Object)
        )
    })

    it('GET /layouts/{uuid}/draft', async () => {
        mocks.client.get.mockResolvedValueOnce(
            okResponse({ data: { version: 1, lock_version: 0, placements: [] } })
        )
        await api.getWidgetLayoutDraft('l-1')
        expect(mocks.client.get).toHaveBeenCalledWith('/layouts/l-1/draft', expect.any(Object))
    })

    it('POST /layouts/{uuid}/draft/instances/{instance_uuid}/versions-and-repin requires the Idempotency-Key header', async () => {
        mocks.client.post.mockResolvedValueOnce(
            okResponse({ data: { instance: {}, draft: { version: 1, lock_version: 1 }, sync_status: 'ready' } })
        )
        const body = {
            expected_current_instance_version: 1,
            expected_draft_id: 1,
            expected_draft_lock_version: 0,
            usage_fingerprint: 'fp',
            widget_id: 1,
            widget_theme_id: 2,
            values: {},
            provider_contract: {},
        }
        await api.repinWidgetLayoutDraftInstance('l-1', 'i-1', body, { idempotencyKey: 'key-repin-1' })
        expect(mocks.client.post).toHaveBeenCalledWith(
            '/layouts/l-1/draft/instances/i-1/versions-and-repin',
            body,
            expect.objectContaining({ headers: { 'Idempotency-Key': 'key-repin-1' } })
        )
    })

    it('GET /layouts/{uuid}/releases', async () => {
        mocks.client.get.mockResolvedValueOnce(okResponse({ data: [] }))
        await api.listWidgetLayoutReleases('l-1')
        expect(mocks.client.get).toHaveBeenCalledWith('/layouts/l-1/releases', expect.any(Object))
    })

    it('POST /layouts/{uuid}/preview-sessions', async () => {
        mocks.client.post.mockResolvedValueOnce(
            okResponse({ preview_url: 'https://x/y', expires_at: '2026-01-01T00:00:00Z', fingerprint: 'fp-1' }, 201)
        )
        await api.createWidgetLayoutPreviewSession('l-1', {
            overlay: [],
            locale: 'en',
            template_context: 'site_shell',
            target_type: 'page',
            target_id: '1',
            viewport: null,
        })
        expect(mocks.client.post).toHaveBeenCalledWith(
            '/layouts/l-1/preview-sessions',
            expect.objectContaining({ locale: 'en' }),
            expect.any(Object)
        )
    })

    it('POST /layouts/{uuid}/publish requires the Idempotency-Key header', async () => {
        mocks.client.post.mockResolvedValueOnce(
            okResponse({ release: { version: 2 }, draft: { version: 3, lock_version: 0 }, sync_status: 'ready' })
        )
        await api.publishWidgetLayout(
            'l-1',
            { expected_draft_id: 1, expected_lock_version: 3 },
            { idempotencyKey: 'key-publish-1' }
        )
        expect(mocks.client.post).toHaveBeenCalledWith(
            '/layouts/l-1/publish',
            { expected_draft_id: 1, expected_lock_version: 3 },
            expect.objectContaining({ headers: { 'Idempotency-Key': 'key-publish-1' } })
        )
    })

    it('POST /layouts/{uuid}/rollback requires the Idempotency-Key header', async () => {
        mocks.client.post.mockResolvedValueOnce(
            okResponse({
                release: { version: 4, rollback_of_version: 2 },
                draft: { version: 5, lock_version: 0 },
                sync_status: 'ready',
            })
        )
        await api.rollbackWidgetLayout(
            'l-1',
            { source_release_version: 2, expected_draft_id: 1, expected_lock_version: 4 },
            { idempotencyKey: 'key-rollback-1' }
        )
        expect(mocks.client.post).toHaveBeenCalledWith(
            '/layouts/l-1/rollback',
            { source_release_version: 2, expected_draft_id: 1, expected_lock_version: 4 },
            expect.objectContaining({ headers: { 'Idempotency-Key': 'key-rollback-1' } })
        )
    })
})

// =============================================================================
// 5. DoD-named scenarios: bootstrap dual status, dual publish/rollback
//    identities, sync_status, preview_url, and emergency-control states.
// =============================================================================
describe('Layout bootstrap installs either the 201 or 200 Resource as baseline', () => {
    it('a 201 (created) response resolves the WidgetLayoutResource, not a client-fabricated draft', async () => {
        mocks.client.put.mockResolvedValueOnce(
            okResponse(
                { data: { uuid: 'l-1', key: 'site', current_draft: { version: 1, lock_version: 0 }, current_published: null } },
                201
            )
        )
        const created = await api.bootstrapWidgetLayoutByKey('site')
        expect(created.uuid).toBe('l-1')
        expect(created.current_draft).toEqual({ version: 1, lock_version: 0 })
    })

    it('a 200 (already existing) response installs the SAME resource shape, never treated as a no-op', async () => {
        mocks.client.put.mockResolvedValueOnce(
            okResponse(
                { data: { uuid: 'l-1', key: 'site', current_draft: { version: 1, lock_version: 2 }, current_published: null } },
                200
            )
        )
        const existing = await api.bootstrapWidgetLayoutByKey('site')
        expect(existing.uuid).toBe('l-1')
        expect(existing.current_draft).toEqual({ version: 1, lock_version: 2 })
    })
})

describe('publish/rollback expose dual Release/fresh-Draft identities and sync_status', () => {
    it('publish response carries both the new Release and the fresh Draft lock', async () => {
        mocks.client.post.mockResolvedValueOnce(
            okResponse({
                release: { version: 7, based_on_version: null, rollback_of_version: null, placements_count: 3 },
                draft: { version: 8, lock_version: 0 },
                sync_status: 'ready',
            })
        )
        const result = await api.publishWidgetLayout(
            'l-1',
            { expected_draft_id: 6, expected_lock_version: 5 },
            { idempotencyKey: 'k-1' }
        )
        expect(result.release.version).toBe(7)
        expect(result.draft).toEqual({ version: 8, lock_version: 0 })
        expect(result.sync_status).toBe('ready')
    })

    it('rollback response never reactivates a historical release identity in place', async () => {
        mocks.client.post.mockResolvedValueOnce(
            okResponse({
                release: { version: 10, rollback_of_version: 4 },
                draft: { version: 11, lock_version: 0 },
                sync_status: 'ready',
            })
        )
        const result = await api.rollbackWidgetLayout(
            'l-1',
            { source_release_version: 4, expected_draft_id: 9, expected_lock_version: 2 },
            { idempotencyKey: 'k-2' }
        )
        expect(result.release.version).toBe(10)
        expect(result.release.rollback_of_version).toBe(4)
        expect(result.draft.version).toBe(11)
    })
})

describe('preview-sessions returns preview_url/expiry/fingerprint', () => {
    it('surfaces preview_url, expires_at and fingerprint from the ad hoc response', async () => {
        mocks.client.post.mockResolvedValueOnce(
            okResponse(
                { preview_url: 'https://preview.example/x', expires_at: '2026-01-01T00:05:00Z', fingerprint: 'fp-abc' },
                201
            )
        )
        const result = await api.createWidgetLayoutPreviewSession('l-1', {
            overlay: [],
            locale: 'en',
            template_context: 'site_shell',
            target_type: 'page',
            target_id: '1',
            viewport: null,
        })
        expect(result.preview_url).toBe('https://preview.example/x')
        expect(result.expires_at).toBe('2026-01-01T00:05:00Z')
        expect(result.fingerprint).toBe('fp-abc')
    })
})

describe('emergency runtime-control models 200 completion and 503 topology-unavailable', () => {
    it('200 completion returns the updated manifest control state', async () => {
        mocks.client.post.mockResolvedValueOnce(
            okResponse({ data: { manifest_hash: 'hash1', control_version: 2, blocked: true } })
        )
        const result = await api.blockWidgetManifestRuntime('hash1', {
            expected_control_version: 1,
            reason: 'incident',
        })
        expect(result.blocked).toBe(true)
        expect(result.control_version).toBe(2)
    })

    it('503 WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE is never retryable and carries no recovery', async () => {
        mocks.client.post.mockRejectedValueOnce({
            response: {
                status: 503,
                data: {
                    status: 503,
                    code: 'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE',
                    message: 'Emergency runtime control is unavailable.',
                    correlation_id: 'corr-9',
                },
            },
        })
        await expect(
            api.blockWidgetManifestRuntime('hash1', { expected_control_version: 1, reason: 'incident' })
        ).rejects.toMatchObject({
            status: 503,
            code: 'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE',
            retryable: false,
            recovery: 'none',
        })
    })

    it('409 WIDGET_RUNTIME_CONTROL_VERSION_STALE is retryable only via refresh_manifest', async () => {
        mocks.client.post.mockRejectedValueOnce({
            response: {
                status: 409,
                data: {
                    status: 409,
                    code: 'WIDGET_RUNTIME_CONTROL_VERSION_STALE',
                    message: 'stale',
                    correlation_id: 'corr-10',
                },
            },
        })
        await expect(
            api.blockWidgetManifestRuntime('hash1', { expected_control_version: 1, reason: 'incident' })
        ).rejects.toMatchObject({
            status: 409,
            code: 'WIDGET_RUNTIME_CONTROL_VERSION_STALE',
            retryable: true,
            recovery: 'refresh_manifest',
        })
    })

    it('never emits a 202/pending status for the emergency-control routes (no acknowledged-partial state)', () => {
        const block = api.WIDGET_SITE_LAYOUT_ROUTES.find(
            (r) => r.method === 'POST' && r.path === '/catalog/manifests/{manifest_hash}/runtime-block'
        )
        const clear = api.WIDGET_SITE_LAYOUT_ROUTES.find(
            (r) => r.method === 'DELETE' && r.path === '/catalog/manifests/{manifest_hash}/runtime-block'
        )
        expect(block!.statuses).not.toContain(202)
        expect(clear!.statuses).not.toContain(202)
    })
})

describe('materialize models both 201 (new pending) and 200 (exact reuse) plus 503 failure', () => {
    it('201 for a newly materialized manifest', async () => {
        mocks.client.post.mockResolvedValueOnce(
            okResponse({ data: { manifest_hash: 'hash-new', eligibility_state: 'pending' } }, 201)
        )
        const result = await api.materializeWidgetManifest({
            widget_id: 1,
            widget_theme_id: 2,
            expected_source_fingerprint: 'fp',
        })
        expect(result.manifest_hash).toBe('hash-new')
    })

    it('200 for an exact reuse', async () => {
        mocks.client.post.mockResolvedValueOnce(
            okResponse({ data: { manifest_hash: 'hash-existing', eligibility_state: 'approved' } }, 200)
        )
        const result = await api.materializeWidgetManifest({
            widget_id: 1,
            widget_theme_id: 2,
            expected_source_fingerprint: 'fp',
        })
        expect(result.manifest_hash).toBe('hash-existing')
    })

    it('503 WIDGET_MANIFEST_MATERIALIZATION_FAILED is retryable via retry_after', async () => {
        mocks.client.post.mockRejectedValueOnce({
            response: {
                status: 503,
                data: {
                    status: 503,
                    code: 'WIDGET_MANIFEST_MATERIALIZATION_FAILED',
                    message: 'failed',
                    correlation_id: 'corr-11',
                },
            },
        })
        await expect(
            api.materializeWidgetManifest({
                widget_id: 1,
                widget_theme_id: 2,
                expected_source_fingerprint: 'fp',
            })
        ).rejects.toMatchObject({ retryable: true, recovery: 'retry_after' })
    })
})
