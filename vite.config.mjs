/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { isAbsolute, join, relative } from 'node:path'
import { defineConfig, loadConfigFromFile, loadEnv, normalizePath } from 'vite'
import laravel from 'laravel-vite-plugin'
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'

const rootDir = process.cwd()

function flattenPlugins(plugins) {
    return plugins.flatMap((plugin) => (Array.isArray(plugin) ? flattenPlugins(plugin) : [plugin])).filter(Boolean)
}

function normalizeInputList(input) {
    if (!input) {
        return []
    }

    if (Array.isArray(input)) {
        return input
    }

    if (typeof input === 'object') {
        return Object.values(input)
    }

    return [input]
}

function normalizeRootInput(input) {
    if (typeof input !== 'string') {
        return input
    }

    const normalizedInput = normalizePath(input)
    const normalizedRoot = normalizePath(rootDir)

    if (isAbsolute(input) || normalizedInput.startsWith(`${normalizedRoot}/`)) {
        return normalizePath(relative(rootDir, input))
    }

    return normalizedInput
}

function isBuildableAssetInput(input) {
    if (typeof input !== 'string') {
        return false
    }

    if (input.includes('*')) {
        return false
    }

    if (/\.(scss|sass|less|styl)$/i.test(input)) {
        return cssPreprocessorInputsEnabled()
    }

    return /\.(css|js|jsx|ts|tsx|vue)$/i.test(input)
}

function cssPreprocessorInputsEnabled() {
    return ['1', 'true', 'yes', 'on'].includes(
        String(process.env.ISPA_VITE_ENABLE_CSS_PREPROCESSORS ?? process.env.ISPA_VITE_ENABLE_SASS ?? '')
            .trim()
            .toLowerCase(),
    )
}

function discoverCmsModuleViteConfigs() {
    const cmsDir = join(rootDir, 'cms')

    if (!existsSync(cmsDir)) {
        return []
    }

    return readdirSync(cmsDir, { withFileTypes: true })
        // Only Game* modules keep a vite.config.js (out of scope of the module.json frontend migration,);
        // every other module declares its entries in module.json "frontend.entries".
        .filter((entry) => entry.isDirectory() && entry.name.startsWith('Game'))
        .map((entry) => join(cmsDir, entry.name, 'vite.config.js'))
        .filter((configPath) => existsSync(configPath))
}

/**
 * Entries modules declare in module.json "frontend.entries" (paths under the module's resources/ folder),
 * returned as root-relative "cms/<Module>/<entry>" inputs.
 */
function discoverCmsModuleDeclaredInputs() {
    const cmsDir = join(rootDir, 'cms')

    if (!existsSync(cmsDir)) {
        return []
    }

    const inputs = []

    for (const entry of readdirSync(cmsDir, { withFileTypes: true })) {
        const manifestPath = join(cmsDir, entry.name, 'module.json')

        if (!entry.isDirectory() || !existsSync(manifestPath)) {
            continue
        }

        let declared = []

        try {
            declared = JSON.parse(readFileSync(manifestPath, 'utf8'))?.frontend?.entries ?? []
        } catch {
            continue
        }

        if (!Array.isArray(declared)) {
            continue
        }

        inputs.push(
            ...declared
                .filter((item) => typeof item === 'string' && item !== '')
                .map((item) => normalizePath(`cms/${entry.name}/${item}`))
                .filter(isBuildableAssetInput),
        )
    }

    return inputs
}

async function loadCmsModuleViteInputs(command, mode) {
    const configEnv = { command, mode }
    // Declared entries first; the vite.config.js scan below is the fallback and may repeat them (deduplicated later).
    const moduleInputs = [...discoverCmsModuleDeclaredInputs()]

    for (const configPath of discoverCmsModuleViteConfigs()) {
        const loadedConfig = await loadConfigFromFile(configEnv, configPath)
        const plugins = flattenPlugins(loadedConfig?.config?.plugins ?? [])
        const laravelPlugin = plugins.find((plugin) => plugin?.name === 'laravel')
        const laravelConfig = await laravelPlugin?.config?.({}, configEnv)

        moduleInputs.push(
            ...normalizeInputList(laravelConfig?.build?.rollupOptions?.input)
                .map(normalizeRootInput)
                .filter(isBuildableAssetInput),
        )
    }

    return moduleInputs
}

export default defineConfig(async ({ command, mode }) => {
    process.env = { ...process.env, ...loadEnv(mode, process.cwd()) }
    const cmsModuleInputs = await loadCmsModuleViteInputs(command, mode)
    const selectedProjectFolder = String(process.env.VITE_TEMPLATE_FOLDER ?? '').trim()
    const selectedProjectAppPath = selectedProjectFolder
        ? join(rootDir, 'projects', selectedProjectFolder, 'dev', 'app.js')
        : null
    const selectedProjectAppInput = selectedProjectAppPath && existsSync(selectedProjectAppPath)
        ? normalizePath(relative(rootDir, selectedProjectAppPath))
        : null
    const selectedProjectViewPaths = selectedProjectFolder
        ? [
            `projects/${selectedProjectFolder}/views/**`,
            `projects/${selectedProjectFolder}/views/components/**`,
        ]
        : []

    // --- Define the array of paths to watch for triggering full page reloads ---
    const refreshPaths = [
        // Standard Laravel paths (previously covered by refresh: true)
        'resources/views/**',
        'routes/**',
        'app/View/Components/**', // Keep if using Blade components in app/
        // Add other standard paths if needed (e.g., app/Forms/**, app/Tables/** if using Filament/similar)

        // --- Add your custom path here ---
        // IMPORTANT: Replace 'Name' with your actual theme/project directory name.
        'projects/' + process.env.VITE_TEMPLATE_FOLDER + '/dev/template/**',
        process.env.VITE_TEMPLATE_TAILWIND_CSS, // Ensure this is set in your .env file
        ...selectedProjectViewPaths,
        'cms/**/resources/**', // Watch all module resource files for changes
        // --- End custom path ---
    ]
    // --- End refresh paths definition ---

    // Deduplicate the input array just in case VITE_TEMPLATE_TAILWIND_CSS is the same as another entry
    const uniqueInput = [
        process.env.VITE_TEMPLATE_TAILWIND_CSS, // Ensure this is set in your .env file
        selectedProjectAppInput,
        'resources/css/app.css',
        'resources/js/app.js',
        'resources/plugins/calendar.min.js',
        'resources/plugins/calendar.min.css',
        'resources/plugins/fancybox.min.js',
        // 'resources/plugins/fancybox.umd.js', // Consider if you need both min.js and umd.js
        'resources/plugins/fancybox.min.css',
        'resources/plugins/swiper-bundle.min.js',
        'resources/plugins/swiper-bundle.min.css',
        'resources/plugins/lightslider.min.js',
        'resources/plugins/lightslider.min.css',
        'resources/plugins/quill.snow.css',
        'resources/js/iSpa.js',
        'resources/js/home.search.js',
        'resources/js/comment.js',
        'resources/js/language.js',
        ...cmsModuleInputs,
    ]
        .filter(Boolean) // Remove null/undefined entries if VITE_TEMPLATE_TAILWIND_CSS is unset
        .filter((value, index, self) => self.indexOf(value) === index) // Keep only unique paths

    return {
        base: process.env.APP_URL,
        server: {
            https: false,
            // host: '0.0.0.0', // for docker
            hmr: {
                host: 'localhost',
                lazy: true,
            },
            liveReload: true,
            //origin: 'http://cms-dev.test', // ← thêm dòng này
        },
        build: {
            sourcemap: false,
            outDir: 'public/build',
            emptyOutDir: true,
        },
        plugins: [
            tailwindcss(),
            vue(),
            laravel({
                // Use the deduplicated input array
                input: uniqueInput,

                // --- Use the defined refreshPaths array ---
                refresh: refreshPaths,
            }),
        ],
        resolve: {
            alias: {
                '@': 'resources',
            },
        },
    }
})
