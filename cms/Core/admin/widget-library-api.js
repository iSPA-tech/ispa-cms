/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * API client of the widget library ("Thư viện widget"). Core endpoints live in `cms/Core/routes/api.php`
 * (`WidgetController`). Background reads are sent with `silent: true`, so a refused read never raises a toast: the page
 * shows its own state instead.
 */

import ajax from '@/ajax'
import { getWidgetPreview } from '@/libs'

const SILENT = { silent: true }

// Rows per list request. The list is paginated by the server; the library reads every page.
const LIST_PAGE_SIZE = 100
// Stops a runaway loop if the server ever reports a wrong page count (100 pages = 10,000 widgets).
const LIST_MAX_PAGES = 100

/**
 * Every widget of this site with origin, block state and usage. The endpoint is paginated, so the pages are read one
 * after another until the last: search, filter and blocking in the library must reach every widget.
 * `scope=library` lists every widget of the site database with every design, each marked `usable_on_site`; without it
 * the server leaves out designs (and widgets) of other projects, as the pickers need.
 * `with_usage=1` is required on each page: the list endpoint leaves `usage` out otherwise. Rejects when a page is
 * refused or fails (also when the server answers with an error envelope), so the screen shows a retry, not an empty
 * library.
 *
 * @returns {Promise<object[]>} Rows `{id, name, widget_key, origin, is_blocked, blocked_reason, version, usage: {pages, areas}, hub_latest_version, template: [{id, name, usable_on_site, preview_image_url, preview_stale}], inputs}`.
 */
export const listLibraryWidgets = async () => {
    const rows = []
    for (let page = 1; page <= LIST_MAX_PAGES; page++) {
        const res = await ajax.get('widget/admin-list', {
            params: { scope: 'library', with_usage: 1, pageSize: LIST_PAGE_SIZE, page },
            ...SILENT,
        })
        if (res.data?.status === 'error') {
            throw Object.assign(new Error('widget list refused'), { response: res })
        }
        const payload = res.data?.data
        const pageRows = Array.isArray(payload) ? payload : (payload?.data ?? [])
        rows.push(...pageRows)
        const lastPage = Number(payload?.last_page ?? 1)
        if (Array.isArray(payload) || page >= lastPage || pageRows.length === 0) break
    }
    return rows
}

/**
 * Designs of a library row this site can use: the ones the server marks `usable_on_site`. A row without the mark (an
 * older server, which lists only usable designs) keeps every design.
 *
 * @param {object} widget Library row.
 * @returns {Array<{id: number, name: string}>}
 */
export const usableDesigns = (widget) => (widget?.template ?? []).filter((design) => design?.usable_on_site !== false)

/**
 * Pages and shared areas that use a widget.
 *
 * @param {number} id Widget id.
 * @returns {Promise<{pages: Array<{post_id: number, title: string, type: string, edit_route: ?string}>, areas: number}>}
 */
export const getWidgetUsage = async (id) => {
    const res = await ajax.get(`widget/${id}/usage`, SILENT)
    return { pages: res.data?.data?.pages ?? [], areas: Number(res.data?.data?.areas ?? 0) }
}

/**
 * Whether this environment edits hub widgets in place, and which hub widgets have changes not exported yet.
 * A refused or failed read counts as "not authoring".
 *
 * @returns {Promise<{authoring: boolean, unexported_widget_keys: string[]}>}
 */
export const getCatalogStatus = async () => {
    try {
        const res = await ajax.get('widget/catalog-status', SILENT)
        return {
            authoring: res.data?.data?.authoring === true,
            unexported_widget_keys: res.data?.data?.unexported_widget_keys ?? [],
        }
    } catch {
        return { authoring: false, unexported_widget_keys: [] }
    }
}

/**
 * Number of shared areas of the active theme, read from the Widget module (`GET widget/slots`, `meta.area_count`).
 * Call it only while the Widget module is on; any failure counts as no shared area.
 *
 * @returns {Promise<number>}
 */
export const getSharedAreaCount = async () => {
    try {
        const res = await ajax.get('widget/slots', SILENT)
        return Number(res.data?.meta?.area_count ?? 0)
    } catch {
        return 0
    }
}

// One read of the designs shared areas accept, shared by every "Thêm vào…" dialog of this session.
let placeableDesignsRequest = null

/**
 * Designs a shared area of this site accepts, per widget: the same list the area add drawer reads
 * (`GET widget/admin-list?placeable=1`, every page). Cached for the session; a failed read is not cached and rejects,
 * so the caller decides what to show. {@link forgetPlaceableDesigns} drops the cache after a design save.
 *
 * @returns {Promise<Map<number, Set<number>>>} Widget id => ids of its designs usable in shared areas.
 */
export const listPlaceableDesigns = () => {
    if (!placeableDesignsRequest) {
        const request = (async () => {
            const designs = new Map()
            for (let page = 1; page <= LIST_MAX_PAGES; page++) {
                const res = await ajax.get('widget/admin-list', {
                    params: { placeable: 1, pageSize: LIST_PAGE_SIZE, page },
                    ...SILENT,
                })
                if (res.data?.status === 'error') {
                    throw Object.assign(new Error('widget list refused'), { response: res })
                }
                const payload = res.data?.data
                const pageRows = Array.isArray(payload) ? payload : (payload?.data ?? [])
                for (const row of pageRows) {
                    designs.set(Number(row.id), new Set((row.template ?? []).map((design) => Number(design.id))))
                }
                const lastPage = Number(payload?.last_page ?? 1)
                if (Array.isArray(payload) || page >= lastPage || pageRows.length === 0) break
            }
            return designs
        })()
        request.catch(() => {
            if (placeableDesignsRequest === request) placeableDesignsRequest = null
        })
        placeableDesignsRequest = request
    }
    return placeableDesignsRequest
}

/** Forget which designs shared areas accept (after a design save: designs may have been added or removed). */
export const forgetPlaceableDesigns = () => {
    placeableDesignsRequest = null
}

/**
 * Block a widget on the whole site. Rejects with the axios error; `error.response.data.error_code` is
 * `WIDGET_BLOCK_FORBIDDEN` (403) or `WIDGET_NOT_FOUND` (404).
 *
 * @param {number} id Widget id.
 * @param {string} reason Why it is blocked, shown to other operators.
 * @returns {Promise<{blocked: boolean, blocked_reason: ?string}>}
 */
export const blockWidget = async (id, reason) => {
    const res = await ajax.post(`widgets/${id}/block`, { reason }, SILENT)
    return res.data?.data ?? {}
}

/**
 * Lift the block of a widget. Same errors as {@link blockWidget}.
 *
 * @param {number} id Widget id.
 * @returns {Promise<{blocked: boolean}>}
 */
export const unblockWidget = async (id) => {
    const res = await ajax.delete(`widgets/${id}/block`, SILENT)
    return res.data?.data ?? {}
}

/**
 * Pages an operator can add a widget to (Content `GET post`, type `page`). Empty when the read is refused.
 *
 * @param {string} keywords Part of the page name.
 * @returns {Promise<Array<{uuid: string, name: string}>>}
 */
export const searchPages = async (keywords = '') => {
    try {
        const res = await ajax.get('post', { params: { type: 'page', keywords, pageSize: 20 }, ...SILENT })
        const payload = res.data?.data
        const rows = Array.isArray(payload) ? payload : (payload?.data ?? [])
        return rows.filter((row) => row?.uuid).map((row) => ({ uuid: row.uuid, name: row.name }))
    } catch {
        return []
    }
}

// One request per widget design, shared by the preview dialog and the picture capture of this session.
const thumbnailCache = new Map()

/**
 * Rendered HTML of one widget design with its default content. Cached per widget, design and widget version, so a
 * design saved anywhere in the admin (its version goes up) gets a new picture the next time the list is loaded. A
 * failed request is not cached. Rejects with the axios error (`error_code` `WIDGET_BLOCKED` for a blocked widget).
 *
 * @param {number} widgetId Widget id.
 * @param {number} themeId Design (widget theme) id.
 * @param {number} [version] Widget version from the list row.
 * @returns {Promise<string>}
 */
export const loadWidgetThumbnail = (widgetId, themeId, version = 0) => {
    const key = `${widgetId}-${themeId}-${version}`
    if (!thumbnailCache.has(key)) {
        const request = getWidgetPreview(widgetId, { template_id: themeId }, SILENT).then((html) => {
            if (typeof html !== 'string') throw Object.assign(new Error('preview'), { response: { data: html } })
            return html
        })
        request.catch(() => thumbnailCache.delete(key))
        thumbnailCache.set(key, request)
    }
    return thumbnailCache.get(key)
}

/**
 * HTML for a small, script-free picture (`sandbox=""` frame): scripts are taken out, since the frame would only refuse
 * them and log an error for each. A render with nothing to show (no text, image or other media) gives '' so the tile
 * shows its placeholder instead of a blank white box.
 *
 * @param {string} html Rendered widget HTML.
 * @returns {string}
 */
export const thumbnailHtml = (html) => {
    const clean = String(html ?? '').replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '')
    return isBlankRender(clean) ? '' : clean
}

/**
 * Whether rendered widget HTML shows nothing: no text and no image, video, frame, drawing or form control once the
 * head-only tags are left out.
 *
 * @param {string} html Rendered widget HTML.
 * @returns {boolean}
 */
export const isBlankRender = (html) => {
    const body = String(html ?? '').replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1\s*>/gi, '')
    if (/<(img|picture|video|audio|iframe|svg|canvas|input|button|select|textarea)\b/i.test(body)) return false
    return body.replace(/<[^>]*>/g, '').replace(/&nbsp;/gi, ' ').trim() === ''
}

// Screens that want to know when a design got a new picture (the library shows it on the card at once).
const previewListeners = new Set()

/**
 * Hear about every design picture stored by {@link uploadDesignPreview} in this session, wherever it was captured.
 *
 * @param {(saved: {id: number, preview_image_url: string, preview_stale: boolean}) => void} listener
 * @returns {() => void} Call it to stop listening.
 */
export const onDesignPreviewUploaded = (listener) => {
    previewListeners.add(listener)
    return () => previewListeners.delete(listener)
}

/**
 * Upload a captured picture (WebP or PNG) of one saved design; the server keeps it as the design's own image and
 * clears "Chưa có ảnh mới". The file is named by its real type. Rejects when the server refuses it.
 *
 * @param {number} themeId Design (widget theme) id.
 * @param {Blob} image WebP or PNG image.
 * @returns {Promise<{id: number, preview_image_url: string, preview_stale: boolean}>}
 */
export const uploadDesignPreview = async (themeId, image) => {
    const formData = new FormData()
    formData.append('image', image, image?.type === 'image/webp' ? 'preview.webp' : 'preview.png')
    const res = await ajax.post(`widget-template/${themeId}/preview-image`, formData, {
        ...SILENT,
        headers: { 'Content-Type': 'multipart/form-data' },
    })
    if (res.data?.status === 'error') {
        throw Object.assign(new Error('preview upload refused'), { response: res })
    }
    const saved = res.data?.data
    if (saved?.id) {
        for (const listener of [...previewListeners]) {
            try {
                listener(saved)
            } catch {
                // A listener that fails must not turn a stored picture into a failed upload.
            }
        }
    }
    return saved
}

/** Data of a hub response; an error envelope sent with a 2xx status rejects like a refused request. */
const hubData = (res) => {
    if (res.data?.status === 'error') {
        throw Object.assign(new Error('widget hub request refused'), { response: res })
    }
    return res.data?.data
}

/**
 * Widgets on the hub this site may see, with what the site already has. The server keeps the list for ten minutes;
 * `refresh` asks the hub now ("Tải mới"). Rejects when the hub cannot be reached (503 `WIDGET_HUB_UNREACHABLE`) or
 * the account lacks `widget.hub` (403 `WIDGET_HUB_FORBIDDEN`).
 *
 * @param {{refresh?: boolean}} [options]
 * @returns {Promise<Array<{widget_key: string, name: string, description: ?string, publisher: ?string, latest_version: number, installed_widget_id: ?number, installed_version: ?number, missing_resolvers: string[], preview_theme_keys: string[], preview_urls: Record<string, string>, changes?: Array<{version: number, items: Array<{type: 'added'|'fixed'|'changed', text: string}>}>}>>}
 */
export const getHubCatalog = async ({ refresh = false } = {}) => {
    const res = await ajax.get('widget/hub-catalog', { params: refresh ? { refresh: 1 } : {}, ...SILENT })
    const entries = hubData(res)
    return Array.isArray(entries) ? entries : []
}

/**
 * "Tải về": install the hub's latest version of a widget. Rejects with the axios error; see {@link hubErrorCopy}.
 *
 * @param {string} widgetKey Hub key of the widget (from the hub list).
 * @returns {Promise<{id: number, sync_status?: string}>} `sync_status: 'pending'` when the design files are not written yet.
 */
export const downloadHubWidget = async (widgetKey) =>
    hubData(await ajax.post(`widget/hub/${encodeURIComponent(widgetKey)}/download`, {}, SILENT)) ?? {}

/**
 * What "Cập nhật" would change. The server reads the new version from the hub, so this can reject like the hub list.
 *
 * @param {number} id Widget id.
 * @returns {Promise<{pages: number, areas: number, removed_designs_in_use: string[], new_input_labels: string[]}>}
 */
export const getHubUpdateImpact = async (id) => {
    const data = hubData(await ajax.get(`widget/${id}/hub-update/impact`, SILENT)) ?? {}
    return {
        pages: Number(data.pages ?? 0),
        areas: Number(data.areas ?? 0),
        removed_designs_in_use: data.removed_designs_in_use ?? [],
        new_input_labels: data.new_input_labels ?? [],
    }
}

/**
 * "Cập nhật": move a downloaded hub widget to the hub's latest version. Same result and errors as
 * {@link downloadHubWidget}.
 *
 * @param {number} id Widget id.
 * @returns {Promise<{id: number, sync_status?: string}>}
 */
export const updateHubWidget = async (id) => hubData(await ajax.post(`widget/${id}/hub-update`, {}, SILENT)) ?? {}

// Hub pictures are fetched a few at a time: a long "Trên hub" list must not open dozens of requests at once.
const HUB_PREVIEW_CONCURRENCY = 4
let hubPreviewsRunning = 0
const hubPreviewQueue = []

const runNextHubPreview = () => {
    while (hubPreviewsRunning < HUB_PREVIEW_CONCURRENCY && hubPreviewQueue.length > 0) {
        const { url, resolve, reject } = hubPreviewQueue.shift()
        hubPreviewsRunning++
        ajax.get(url, { responseType: 'blob', ...SILENT })
            .then((res) => {
                const blob = res?.data
                // An error envelope sent with a 2xx status is JSON, not a picture.
                if (!(blob instanceof Blob) || !blob.type.startsWith('image/')) {
                    throw Object.assign(new Error('hub preview refused'), { response: res })
                }
                resolve(blob)
            })
            .catch(reject)
            .finally(() => {
                hubPreviewsRunning--
                runNextHubPreview()
            })
    }
}

/**
 * Picture of a hub widget design before it is downloaded (`preview_urls` of a hub list entry). The admin signs API
 * requests with a header, which an `<img>` cannot send, so the picture is read through the API client as a blob; the
 * caller shows it with an object URL and revokes that URL when done. At most four are read at a time. Rejects when
 * the hub has no such picture or cannot be reached.
 *
 * @param {string} url Preview address from the hub list.
 * @returns {Promise<Blob>}
 */
export const loadHubPreview = (url) =>
    new Promise((resolve, reject) => {
        hubPreviewQueue.push({ url, resolve, reject })
        runNextHubPreview()
    })

const HUB_ERROR_COPY = {
    WIDGET_HUB_UNREACHABLE: 'text.widget_library.hub_unreachable',
    WIDGET_RESOLVER_MISSING: 'text.widget_library.hub_resolver_missing',
    WIDGET_KEY_TAKEN: 'text.widget_library.hub_key_taken',
    WIDGET_BUNDLE_UNTRUSTED: 'text.widget_library.hub_untrusted',
    WIDGET_UPDATE_DESIGN_IN_USE: 'text.widget_library.hub_design_in_use',
    WIDGET_UP_TO_DATE: 'text.widget_library.hub_up_to_date',
    WIDGET_SITE_COPY: 'text.widget_library.hub_site_copy',
    WIDGET_AUTHORING_COPY: 'text.widget_library.hub_authoring_copy',
    WIDGET_HUB_FORBIDDEN: 'text.widget_library.hub_forbidden',
    WIDGET_HUB_NOT_FOUND: 'text.widget_library.hub_not_found',
    WIDGET_NOT_FOUND: 'text.widget_library.not_found',
}

/**
 * Translation key of the plain sentence for a refused hub request. A request with no answer at all (network down,
 * timeout) reads as "hub not reachable"; an unknown refusal as the general "that did not work".
 *
 * @param {unknown} error Axios error.
 * @returns {string}
 */
export const hubErrorCopy = (error) => {
    if (!error?.response) return HUB_ERROR_COPY.WIDGET_HUB_UNREACHABLE
    return HUB_ERROR_COPY[widgetErrorCode(error)] ?? 'text.widget_library.action_failed'
}

// Refusals that pressing again cannot change; only a new read of the hub list ("Tải mới") can.
const FINAL_HUB_REFUSALS = new Set([
    'WIDGET_RESOLVER_MISSING',
    'WIDGET_KEY_TAKEN',
    'WIDGET_BUNDLE_UNTRUSTED',
    'WIDGET_HUB_NOT_FOUND',
    'WIDGET_HUB_FORBIDDEN',
])

/**
 * Whether a refused hub download would be refused again as it is (same widget, same site), unlike a hub that did not
 * answer.
 *
 * @param {unknown} error Axios error.
 * @returns {boolean}
 */
export const isFinalHubRefusal = (error) => FINAL_HUB_REFUSALS.has(widgetErrorCode(error))

/** Forget cached previews of a widget (after a block change or a design save). */
export const forgetWidgetThumbnails = (widgetId) => {
    for (const key of [...thumbnailCache.keys()]) {
        if (key.startsWith(`${widgetId}-`)) thumbnailCache.delete(key)
    }
}

/**
 * Stable error code of a failed widget request.
 *
 * @param {unknown} error Axios error.
 * @returns {string} For example `WIDGET_BLOCKED`, or '' when the server sent none.
 */
export const widgetErrorCode = (error) => String(error?.response?.data?.error_code ?? '')
