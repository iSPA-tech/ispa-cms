/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { basePath } from '@/config/base'

/** Milliseconds the two requests may take before the page reloads anyway. */
const TIMEOUT_MS = 4000

/**
 * Reload the admin so it runs the build a Core update just installed.
 *
 * The update writes a new admin index (new hashed script names), so the index is the only file a browser can still
 * hold in its old form. Before the reload it is requested again with `cache: 'reload'`, under both of its addresses:
 * that replaces the copy the browser stored, whatever cache headers the web server sends, so later visits get the
 * new build too. The reload itself asks the server about the page again. Nothing here depends on the web server.
 *
 * A failed or slow request never holds the reload back.
 *
 * @param {object} [options]
 * @param {(url: string, init: object) => Promise<unknown>} [options.fetcher] replaces `fetch` (specs)
 * @param {() => void} [options.reload] replaces `window.location.reload()` (specs)
 * @param {string} [options.base] the admin base path; the configured one by default
 * @returns {Promise<void>}
 */
export async function reloadAdminBuild({ fetcher, reload, base = basePath } = {}) {
    const ask = fetcher || ((url, init) => fetch(url, init))
    const root = base.endsWith('/') ? base : `${base}/`
    const controller = typeof AbortController === 'function' ? new AbortController() : null
    let timer = null
    try {
        const init = { cache: 'reload', credentials: 'same-origin', signal: controller?.signal }
        const requests = [root, `${root}index.html`].map((url) => Promise.resolve().then(() => ask(url, init)))
        await Promise.race([
            Promise.allSettled(requests),
            new Promise((resolve) => {
                timer = setTimeout(resolve, TIMEOUT_MS)
            }),
        ])
    } catch {
        // The reload below still asks the server for the page.
    } finally {
        clearTimeout(timer)
        controller?.abort()
    }
    if (reload) reload()
    else window.location.reload()
}
