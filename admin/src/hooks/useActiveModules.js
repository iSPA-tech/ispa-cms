/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { computed, ref, unref } from 'vue'
import { useStorage } from '@vueuse/core'
import { CACHE_KEY_ACTIVE_MODULES } from '@/config/constants'
import { getModules } from '@/libs'

/**
 * Reactive ref backed by localStorage.
 * Stores an array of active module names, e.g. ['Core', 'Content', 'Education'].
 */
const activeModulesRef = useStorage(CACHE_KEY_ACTIVE_MODULES, [])
const activeModuleStatusRef = useStorage(`${CACHE_KEY_ACTIVE_MODULES}-status`, {})
const activeModuleCatalogLoadedRef = useStorage(`${CACHE_KEY_ACTIVE_MODULES}-loaded`, false)

// True once the module list was read from the server in THIS page load. Not kept in the browser: the stored copy above
// can be stale (a module turned off since the last visit) until the boot fetch answers.
const activeModuleCatalogLiveRef = ref(false)

const normalizeModuleName = (moduleName) => String(moduleName || '').toLowerCase()

/**
 * Computed Set for O(1) lookups.
 * Module names are stored lowercase for case-insensitive matching.
 */
const activeModuleSet = computed(() => {
    const list = unref(activeModulesRef)
    return new Set((Array.isArray(list) ? list : []).map((m) => normalizeModuleName(m)))
})

const activeModuleStatusMap = computed(() => {
    const map = unref(activeModuleStatusRef)
    return map && typeof map === 'object' && !Array.isArray(map) ? map : {}
})

/**
 * Fetch active modules from backend and cache in localStorage.
 * Call this once during app boot.
 */
export const fetchAndCacheActiveModules = async () => {
    activeModuleCatalogLoadedRef.value = false

    try {
        const modules = await getModules()
        activeModulesRef.value = modules
            .filter((m) => m.enabled)
            .map((m) => m.name)
        activeModuleStatusRef.value = modules.reduce((status, module) => {
            const enabled = Boolean(module.enabled)

            for (const name of [module.name, module.alias]) {
                const key = normalizeModuleName(name)
                if (key) {
                    status[key] = enabled
                }
            }

            return status
        }, {})
        activeModuleCatalogLoadedRef.value = true
        activeModuleCatalogLiveRef.value = true
    } catch (error) {
        console.error('Failed to fetch active modules:', error)
    }
}

/**
 * Check if a CMS module is active.
 * Case-insensitive comparison.
 *
 * @param {string} moduleName - CMS module name (e.g. 'Education', 'Content')
 * @returns {boolean}
 */
export const isModuleActive = (moduleName) => {
    if (!moduleName) return true
    const key = normalizeModuleName(moduleName)
    const statusMap = activeModuleStatusMap.value

    if (Object.prototype.hasOwnProperty.call(statusMap, key)) {
        return statusMap[key] === true
    }

    return activeModuleCatalogLoadedRef.value !== true
}

/**
 * Fail-CLOSED check for UI a module OWNS — post-editor extensions, content
 * blocks, anything a foreign deployment must never render.
 *
 * isModuleActive() is deliberately fail-OPEN: while the catalog is unknown
 * (first paint, or a failed /modules call) it answers "true" so optional UI
 * degrades gracefully. That is the wrong default for a module's own panels:
 * this CMS core is shared across sites, and a single-site editor section
 * appearing in another site's admin — even briefly, or permanently while the
 * modules endpoint is down — is a leak. Requires the catalog to have loaded.
 *
 * @param {string} moduleName - CMS module that owns the UI
 * @returns {boolean}
 */
export const isModuleExtensionAllowed = (moduleName) =>
    activeModuleCatalogLoadedRef.value === true && isModuleActive(moduleName)

/**
 * Strictest check, for code that CALLS a module's API: true only when the server said in this page load that the
 * module is on. The stored copy is ignored, so a module turned off since the last visit is never called (its routes
 * answer 404). Reactive: turns true when the boot fetch answers.
 *
 * @param {string} moduleName - CMS module whose API is about to be called
 * @returns {boolean}
 */
export const isModuleLiveEnabled = (moduleName) =>
    activeModuleCatalogLiveRef.value === true && activeModuleStatusMap.value[normalizeModuleName(moduleName)] === true

/**
 * Composable to access active modules state.
 */
export const useActiveModules = () => ({
    activeModules: activeModulesRef,
    activeModuleSet,
    activeModuleStatusMap,
    activeModuleCatalogLoaded: activeModuleCatalogLoadedRef,
    isModuleActive,
    isModuleExtensionAllowed,
    isModuleLiveEnabled,
    fetchAndCacheActiveModules,
})
