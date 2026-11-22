/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Build-time fallback messages for the pre-login "Kết nối iSPA Hub" gate screen.
 *
 * The screen is the first thing a new site shows and it can appear before the admin translation pack has loaded (or
 * when the pack request fails). The wording is read from the Core translation sidecar when the admin is built and is
 * served to the app as the virtual module `virtual:admin-i18n-fallback`, so the sidecar stays the only source of the
 * text. The loaded pack overrides these messages (see `withFallbackMessages` in i18n-message-utils.js).
 *
 * This file runs in Node (vite.config.js, vitest.config.ts). It lives under src/ so the deploy sync ships it.
 */

import fs from 'node:fs'
import path from 'node:path'

/** Name of the virtual module the app imports. */
export const ADMIN_I18N_FALLBACK_MODULE = 'virtual:admin-i18n-fallback'

const RESOLVED_ID = `\0${ADMIN_I18N_FALLBACK_MODULE}`

/** Sidecar file of the gate screen, relative to cms/Core. */
export const FALLBACK_SIDECAR = 'database/seeders/exported/translates-admin-connect-hub.php'

/** Only these dotted prefixes are bundled: the gate screen must stay small. */
export const FALLBACK_PREFIXES = ['text.site_connect.', 'text.site_status.']

/** `$entry('name', 'en', 'vi')`, optionally with a fourth argument (the row's `updated_at`, ignored here). */
const ENTRY_PATTERN = /\$entry\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*(?:,\s*'(?:[^'\\]|\\.)*'\s*)?\)/g

/** Undo PHP single-quote escapes (`\'` and `\\`). */
const unescapePhp = (value) => value.replace(/\\(['\\])/g, '$1')

/**
 * Turn the sidecar source into nested vue-i18n message trees per locale.
 *
 * @param {string} phpSource Contents of the sidecar file; rows are `$entry('name', 'en text', 'vi text'[, 'updated at'])` calls.
 * @returns {{en: object, vi: object}} For example `{ en: { text: { site_connect: { title: '...' } } }, vi: ... }`.
 */
export const buildFallbackMessages = (phpSource) => {
    const messages = { en: {}, vi: {} }

    for (const [, name, en, vi] of String(phpSource).matchAll(ENTRY_PATTERN)) {
        const key = unescapePhp(name)
        if (!FALLBACK_PREFIXES.some((prefix) => key.startsWith(prefix))) {
            continue
        }
        for (const [locale, text] of [['en', en], ['vi', vi]]) {
            const parts = key.split('.')
            let node = messages[locale]
            for (const part of parts.slice(0, -1)) {
                node = node[part] ??= {}
            }
            node[parts[parts.length - 1]] = unescapePhp(text)
        }
    }

    return messages
}

/**
 * Vite plugin serving `virtual:admin-i18n-fallback`.
 *
 * @param {string} coreAdminPath Path of cms/Core/admin in the current layout (workspace or release), as the configs
 *     already resolve it for `@core-admin`; the sidecar sits beside it under cms/Core/database.
 * @returns {import('vite').Plugin}
 */
export const adminI18nFallbackPlugin = (coreAdminPath) => {
    const sidecar = path.resolve(coreAdminPath, '..', FALLBACK_SIDECAR)

    return {
        name: 'admin-i18n-fallback',
        resolveId(id) {
            return id === ADMIN_I18N_FALLBACK_MODULE ? RESOLVED_ID : null
        },
        load(id) {
            if (id !== RESOLVED_ID) {
                return null
            }
            // Rebuild the module when the sidecar changes (dev server and watch mode).
            this.addWatchFile?.(sidecar)
            let messages = { en: {}, vi: {} }
            if (fs.existsSync(sidecar)) {
                messages = buildFallbackMessages(fs.readFileSync(sidecar, 'utf8'))
            } else {
                this.warn?.(`admin i18n fallback: sidecar not found at ${sidecar}; the gate screen has no bundled wording.`)
            }

            return `export default ${JSON.stringify(messages)}`
        },
    }
}
