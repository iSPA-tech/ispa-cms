/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Admin gate helpers (, spec 12.2 / 12.4): the admin asks its own backend for the hub connection status before
 * the sign-in screen. The browser never calls the hub and never holds the domain token.
 *
 * signing in never depends on the hub. A site that is not connected (or still pending) signs in as
 * usual and connects later from Modules; only a site backend that does not answer shows the retry screen.
 */

/** Views the shell can show before sign-in. */
export const GATE_LOADING = 'loading'
export const GATE_READY = 'ready'
export const GATE_UNAVAILABLE = 'unavailable'

/**
 * Which pre-login view a site-status answer leads to.
 *
 * @param {{status?: string}|null} answer Parsed `data` of `GET /api/v2/public/site-status`, or null while loading.
 * @param {boolean} failed True when the status request itself failed (backend down, network).
 * @returns {string} One of the GATE_* constants.
 */
export const siteGateView = (answer, failed = false) => {
    if (failed) {
        return GATE_UNAVAILABLE
    }
    if (!answer || typeof answer.status !== 'string') {
        return GATE_LOADING
    }

    // Any answer from the site backend (connected, not_connected, pending or a newer status) leads to sign-in.
    return GATE_READY
}

/**
 * Read the site status from the site's own backend.
 *
 * @param {{get: Function}} http Axios-like client.
 * @param {string} baseUrl Site base URL ending with a slash.
 * @returns {Promise<{status: string, message_key: string, expires_at: string|null}|null>} The answer, or null when the
 *     request failed or the shape is wrong.
 */
export const loadSiteStatus = async (http, baseUrl) => {
    try {
        const res = await http.get(`${baseUrl}api/v2/public/site-status`)
        const data = res?.data?.data

        return data && typeof data.status === 'string' ? data : null
    } catch {
        return null
    }
}

/**
 * Send the connect form to the site's own backend. Never throws: a failure comes back as `{ok: false, ...}`.
 *
 * @param {{post: Function}} http Axios-like client.
 * @param {string} baseUrl Site base URL ending with a slash.
 * @param {{siteId: string, siteSecret: string, setupCode: string}} form Form values.
 * @returns {Promise<{ok: boolean, status: string, messageKey: string}>} `status` is connected, pending or not_connected.
 */
export const submitSiteConnect = async (http, baseUrl, form) => {
    try {
        const res = await http.post(`${baseUrl}api/v2/public/site-connect`, {
            site_id: form.siteId,
            site_secret: form.siteSecret,
            setup_code: form.setupCode,
        })
        const data = res?.data?.data ?? {}

        return { ok: true, status: String(data.status || 'pending'), messageKey: String(data.message_key || '') }
    } catch (err) {
        const code = err?.response?.status
        const data = err?.response?.data?.data ?? {}
        const fallback = code === 429 ? 'text.site_connect.too_many_attempts_here' : 'text.site_connect.unavailable'

        return { ok: false, status: 'not_connected', messageKey: String(data.message_key || fallback) }
    }
}

/** Admin connect: the site already holds a hub connection; the same request with `replace: true` replaces it. */
export const HUB_ALREADY_CONNECTED = 'HUB_ALREADY_CONNECTED'

/**
 * Which message explains a failed admin connect.
 *
 * @param {number|undefined} httpStatus HTTP status of the answer.
 * @param {string} code `error_code` of the answer, or ''.
 * @param {string} messageKey `message_key` of the answer, or ''.
 * @returns {string} A `text.*` key.
 */
const adminConnectMessageKey = (httpStatus, code, messageKey) => {
    if (code === 'HUB_CONNECT_FORBIDDEN') return 'text.hub_connect.forbidden'
    // Not the backend's `invalid_input` wording: that one also asks for the setup code.
    if (code === 'HUB_CONNECT_INVALID') return 'text.hub_connect.invalid'
    // Hub answers (CREDENTIALS_REFUSED, HUB_UNREACHABLE) carry the message key the pre-login screen already words.
    if (messageKey !== '') return messageKey
    if (/REFUS/.test(code)) return 'text.site_connect.credentials_refused'
    if (/UNREACHABLE/.test(code)) return 'text.site_connect.hub_unreachable'
    if (httpStatus === 403) return 'text.hub_connect.forbidden'
    if (httpStatus === 422) return 'text.hub_connect.invalid'
    if (httpStatus === 429) return 'text.site_connect.too_many_attempts_here'

    return 'text.site_connect.unavailable'
}

/**
 * Signed-in connect: send the site id and secret to
 * `POST api/v2/admin/site-connect`. No setup code: the signed-in account with `hub.connect` is the proof of authority.
 * Never throws: a failure comes back as `{ok: false, ...}`.
 *
 * @param {{post: Function}} http The signed-in admin client (`@/ajax`, base URL `api/v2/admin/`).
 * @param {{siteId: string, secret: string, replace?: boolean}} form Form values; `replace` once the person confirmed.
 * @returns {Promise<{ok: boolean, status: string, source?: string, warningKey?: string, code?: string, messageKey?: string,
 *     fieldErrors?: {siteId: string, siteSecret: string}}>} `code` is the backend `error_code` (for example
 *     HUB_ALREADY_CONNECTED); `status` is connected or pending (the hub still checks the domain); `source` is
 *     `stored` or `env`.
 */
export const submitAdminSiteConnect = async (http, form) => {
    const body = { site_id: form.siteId, secret: form.secret }
    if (form.replace) {
        body.replace = true
    }
    try {
        // `silent`: the screen shows its own plain message, so the shared ajax alert stays off.
        const res = await http.post('site-connect', body, { silent: true })
        const data = res?.data?.data ?? {}

        return {
            ok: true,
            status: String(data.status || 'connected'),
            messageKey: String(data.message_key || ''),
            source: String(data.source || 'stored'),
            warningKey: String(data.warning_key || ''),
        }
    } catch (err) {
        const httpStatus = err?.response?.status
        const payload = err?.response?.data ?? {}
        const code = String(payload.error_code || payload.errorCode || '')
        const errors = payload.errors ?? payload.data?.errors ?? {}
        const first = (value) => String((Array.isArray(value) ? value[0] : value) || '')

        return {
            ok: false,
            status: 'not_connected',
            code,
            messageKey: adminConnectMessageKey(httpStatus, code, String(payload.data?.message_key || payload.message_key || '')),
            fieldErrors: { siteId: first(errors.site_id), siteSecret: first(errors.secret) },
        }
    }
}
