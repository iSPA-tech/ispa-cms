/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Vite config for the prebuilt front-end assets of ONE module package.
 *
 * Run by Cms\Core\Services\Modules\Packages\FrontendBuilder (`pnpm exec vite build --config vite.module.config.mjs`
 * in the ispa-cms root) with a cleaned environment:
 * - ISPA_MODULE_NAME     module folder name (required);
 * - ISPA_MODULE_OUT_DIR  absolute, fresh output folder (required);
 * - ISPA_MODULE_DIR      absolute module folder (default cms/<name> under the current folder);
 * - ISPA_MODULE_ENTRIES  JSON list of entries relative to the module folder (default module.json frontend.entries);
 * - ISPA_MODULE_CACHE_DIR Vite cache folder (default a sibling of the output folder), never inside the module.
 *
 * The module folder is the Vite root, so manifest keys are the declared entries (`resources/...`). `base: './'` keeps
 * every URL relative to the folder the site publishes the build in; no `.env` file is read and no source map is
 * written, so the output holds no APP_URL, no absolute path and no secret. Same plugins as the root build.
 */

import { existsSync, readFileSync, realpathSync } from 'node:fs'
import { dirname, isAbsolute, join, resolve } from 'node:path'
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'

const MODULE_NAME_PATTERN = /^[A-Z][A-Za-z0-9]{1,63}$/
const ENTRY_PATTERN = /\.(css|js|jsx|ts|tsx|vue|scss|sass|less|styl)$/i

/**
 * Read a required environment value or stop the build with a clear message.
 */
function requiredEnv(name) {
    const value = String(process.env[name] ?? '').trim()

    if (value === '') {
        throw new Error(`vite.module.config.mjs: ${name} is required.`)
    }

    return value
}

/**
 * Whether an entry is a relative, buildable path under resources/ without empty, "." or ".." segments.
 */
function isValidEntry(entry) {
    if (typeof entry !== 'string' || !entry.startsWith('resources/') || entry.includes('\\') || entry.includes('*')) {
        return false
    }

    return entry.split('/').every((segment) => segment !== '' && segment !== '.' && segment !== '..') && ENTRY_PATTERN.test(entry)
}

/**
 * The module's declared entries: ISPA_MODULE_ENTRIES when set, else module.json frontend.entries.
 */
function moduleEntries(moduleDir) {
    const raw = String(process.env.ISPA_MODULE_ENTRIES ?? '').trim()
    const entries = raw !== ''
        ? JSON.parse(raw)
        : JSON.parse(readFileSync(join(moduleDir, 'module.json'), 'utf8'))?.frontend?.entries

    if (!Array.isArray(entries) || entries.length === 0) {
        throw new Error('vite.module.config.mjs: the module declares no frontend.entries.')
    }

    for (const entry of entries) {
        if (!isValidEntry(entry)) {
            throw new Error(`vite.module.config.mjs: invalid entry ${JSON.stringify(entry)}.`)
        }
        if (!existsSync(join(moduleDir, entry))) {
            throw new Error(`vite.module.config.mjs: entry ${entry} does not exist.`)
        }
    }

    return entries
}

export default defineConfig(() => {
    const name = requiredEnv('ISPA_MODULE_NAME')

    if (!MODULE_NAME_PATTERN.test(name)) {
        throw new Error('vite.module.config.mjs: ISPA_MODULE_NAME is not a module name.')
    }

    const outDir = requiredEnv('ISPA_MODULE_OUT_DIR')

    if (!isAbsolute(outDir)) {
        throw new Error('vite.module.config.mjs: ISPA_MODULE_OUT_DIR must be absolute.')
    }

    // The real path: a symlinked folder (macOS /var -> /private/var) would turn manifest keys into ../ paths.
    const moduleDir = realpathSync(resolve(String(process.env.ISPA_MODULE_DIR ?? '').trim() || join(process.cwd(), 'cms', name)))
    const cacheDir = String(process.env.ISPA_MODULE_CACHE_DIR ?? '').trim() || join(dirname(outDir), 'vite-cache')
    const input = moduleEntries(moduleDir).map((entry) => join(moduleDir, entry))

    return {
        root: moduleDir,
        base: './',
        publicDir: false,
        envDir: false,
        cacheDir,
        logLevel: 'warn',
        clearScreen: false,
        build: {
            outDir,
            emptyOutDir: true,
            manifest: 'manifest.json',
            sourcemap: false,
            copyPublicDir: false,
            assetsInlineLimit: 0,
            rollupOptions: { input },
        },
        plugins: [
            tailwindcss(),
            vue(),
            laravel({ input, refresh: false }),
        ],
    }
})
