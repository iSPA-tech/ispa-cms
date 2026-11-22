/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * One-tap hub connect helpers. The admin asks its own backend to start
 * an attempt, sends the browser to the ispa.tech consent screen, and finishes the attempt with what ispa.tech sent back.
 * The browser never holds a secret: only the single-use code passes through the address bar.
 */

/** The site already holds a hub connection; the same finish request with `replace: true` replaces it. */
export const HUB_ALREADY_CONNECTED = 'HUB_ALREADY_CONNECTED'
/** The signed-in account lacks `hub.connect`: nothing on the screen can help, so it offers no action. */
export const HUB_CONNECT_FORBIDDEN = 'HUB_CONNECT_FORBIDDEN'

/** The hub connection is set in the server's `.env`: the admin cannot change it, so nothing is offered either. */
export const HUB_CONNECT_MANAGED_BY_ENV = 'HUB_CONNECT_MANAGED_BY_ENV'

/**
 * Whether another attempt or the manual form can help after this refusal.
 *
 * @param {string} code Backend `error_code`, or ''.
 * @returns {boolean} False for a missing permission and for a connection the server settings own.
 */
export const canRetryHubConnect = (code) => code !== HUB_CONNECT_FORBIDDEN && code !== HUB_CONNECT_MANAGED_BY_ENV

/** Message when the address carries no usable code or state, or the attempt is unknown, used or expired. */
export const STATE_INVALID_KEY = 'text.hub_connect.err_state_invalid'

/** Plain wording per backend `error_code` (rows in cms/Core translate-admin-authored.php). */
const MESSAGE_KEYS = {
    HUB_CONNECT_FORBIDDEN: 'text.hub_connect.forbidden',
    HUB_CONNECT_SITE_UNKNOWN: 'text.hub_connect.err_site_unknown',
    HUB_CONNECT_STATE_INVALID: STATE_INVALID_KEY,
    HUB_CONNECT_DENIED: 'text.hub_connect.err_denied',
    HUB_CONNECT_DOMAIN_TAKEN: 'text.hub_connect.err_domain_taken',
    HUB_CONNECT_SITE_DISABLED: 'text.hub_connect.err_site_disabled',
    HUB_CONNECT_EXCHANGE_FAILED: 'text.hub_connect.err_exchange_failed',
    CREDENTIALS_REFUSED: 'text.hub_connect.err_refused',
    HUB_UNREACHABLE: 'text.hub_connect.err_unreachable',
    HUB_CONNECT_MANAGED_BY_ENV: 'text.hub_connect.err_managed_by_env',
}

/** Message when the site backend itself did not answer in a known way. */
const UNAVAILABLE_KEY = 'text.site_connect.unavailable'

/** Leaving the admin goes through here so a screen can be tested without navigating. */
export const browser = {
    /**
     * Send the browser to another address.
     *
     * @param {string} url Absolute address.
     */
    go(url) {
        window.location.assign(url)
    },
}

/**
 * Describe a failed request.
 *
 * @param {any} err What the admin client threw.
 * @returns {{ok: false, code: string, messageKey: string}} `code` is the backend `error_code`, or ''.
 */
const failure = (err) => {
    const payload = err?.response?.data ?? {}
    const code = String(payload.error_code || payload.errorCode || '')
    const fallback = err?.response?.status === 403 ? MESSAGE_KEYS.HUB_CONNECT_FORBIDDEN : UNAVAILABLE_KEY

    return { ok: false, code, messageKey: MESSAGE_KEYS[code] || fallback }
}

/**
 * Start an attempt: `POST api/v2/admin/hub-connect/start`. Never throws. A site that already holds a connection answers
 * HUB_ALREADY_CONNECTED until the person confirmed and the request carries `replace`.
 *
 * @param {{post: Function}} http The signed-in admin client (`@/ajax`, base URL `api/v2/admin/`).
 * @param {boolean} replace True once the person confirmed replacing the existing connection.
 * @returns {Promise<{ok: true, redirectUrl: string}|{ok: false, code: string, messageKey: string}>} The ispa.tech
 *     consent address to open, or why the attempt did not start.
 */
export const startHubConnect = async (http, replace = false) => {
    try {
        // `silent`: the screen shows its own plain message, so the shared ajax alert stays off.
        const res = await http.post('hub-connect/start', replace ? { replace: true } : {}, { silent: true })
        const redirectUrl = String(res?.data?.data?.redirect_url || '')

        // Only a web address is ever followed.
        return /^https?:\/\//i.test(redirectUrl) ? { ok: true, redirectUrl } : { ok: false, code: '', messageKey: UNAVAILABLE_KEY }
    } catch (err) {
        return failure(err)
    }
}

/**
 * Finish an attempt: `POST api/v2/admin/hub-connect/finish` with what ispa.tech put in the callback address. Never throws.
 *
 * @param {{post: Function}} http The signed-in admin client.
 * @param {{code?: string, state: string, error?: string, replace?: boolean}} answer `code` after "Cho phép", `error`
 *     after "Huỷ"; `replace` once the person confirmed replacing an existing connection (same code and state).
 * @returns {Promise<{ok: true, status: string, source: string, warningKey: string, messageKey: string}|{ok: false,
 *     code: string, messageKey: string}>} `status` is connected or pending (the hub still checks the domain).
 */
export const finishHubConnect = async (http, answer) => {
    const body = answer.error ? { error: answer.error, state: answer.state } : { code: answer.code, state: answer.state }
    if (answer.replace) {
        body.replace = true
    }
    try {
        const res = await http.post('hub-connect/finish', body, { silent: true })
        const data = res?.data?.data ?? {}

        return {
            ok: true,
            status: String(data.status || 'connected'),
            source: String(data.source || 'stored'),
            warningKey: String(data.warning_key || ''),
            messageKey: String(data.message_key || ''),
        }
    } catch (err) {
        return failure(err)
    }
}
