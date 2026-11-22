/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Permission Toolkit - Reusable permission checking utilities
 *
 * Supports both new (module.resource.action) and legacy (resource.action) permission formats.
 *
 * @example
 * // Atomic checks:
 * can('education')                              // module-level
 * can('education', 'edu_semester')              // resource-level
 * can('education', 'edu_semester', 'viewAny')   // action-level (new format)
 * can('user', 'viewAny')                        // legacy format (auto-detected)
 *
 * // Composition:
 * canAny(act('education', 'edu_semester', 'view'), act('education', 'edu_subject', 'view'))
 * canAll(act('education', 'edu_semester', 'view'), act('education', 'edu_semester', 'edit'))
 *
 * // Presets:
 * can(crud('education', 'edu_semester'))
 * can(read('education', 'edu_semester'))
 * can(write('education', 'edu_semester'))
 *
 * // Batch check:
 * canIn('education', 'edu_semester', ['view', 'edit', 'delete'])
 * // => { view: true, edit: false, delete: true }
 *
 * // Debug:
 * explainDeny(crud('education', 'edu_semester'))
 * assertCan(act('education', 'edu_semester', 'view'), 'Cannot view semester')
 */

import { computed, unref } from 'vue'
import { useStorage } from '@vueuse/core'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config/constants'

// ========================================
// Constants
// ========================================

/** Standard CRUD actions */
export const CRUD_ACTIONS = Object.freeze(['viewAny', 'view', 'create', 'edit', 'update', 'delete'])

/** Read-only actions */
export const READ_ACTIONS = Object.freeze(['viewAny', 'view'])

/** Write actions */
export const WRITE_ACTIONS = Object.freeze(['create', 'edit', 'update', 'delete'])

/** All known actions (used for legacy detection) */
export const ACTIONS = new Set([
    'viewAny',
    'view',
    'create',
    'edit',
    'update',
    'delete',
    'forceDelete',
    'restore',
    'manage',
    'import',
    'export',
    'confirm',
    'password',
    'seo',
    'video',
    'audio',
    'document',
    'notification',
    'admission',
    'events',
    'signInAs',
])

const WEBMASTER_MANAGED_RESOURCES = new Set(['role', 'permission'])

// ========================================
// Spec Builders
// ========================================

/**
 * Create a module-level permission spec
 * @param {string} module
 * @returns {Object} spec
 */
export const mod = (module) => ({ t: 'module', m: module })

/**
 * Create a resource-level permission spec
 * @param {string} module
 * @param {string} resource
 * @returns {Object} spec
 */
export const res = (module, resource) => ({ t: 'resource', m: module, r: resource })

/**
 * Create an action-level permission spec (new format)
 * @param {string} module
 * @param {string} resource
 * @param {string} action
 * @returns {Object} spec
 */
export const act = (module, resource, action) => ({
    t: 'action',
    m: module,
    r: resource,
    a: action,
})

/**
 * Create a legacy permission spec (resource.action format)
 * @param {string} resource
 * @param {string} action
 * @returns {Object} spec
 */
export const legacy = (resource, action) => ({ t: 'legacy', r: resource, a: action })

/**
 * Create an anyOf (OR) composition spec
 * @param {...Object} specs
 * @returns {Object} spec
 */
export const anyOf = (...specs) => ({ t: 'anyOf', items: specs.flat() })

/**
 * Create an allOf (AND) composition spec
 * @param {...Object} specs
 * @returns {Object} spec
 */
export const allOf = (...specs) => ({ t: 'allOf', items: specs.flat() })

/**
 * Create a CRUD preset spec (all CRUD actions)
 * @param {string} module
 * @param {string} resource
 * @returns {Object} spec
 */
export const crud = (module, resource) => ({ t: 'preset', p: 'crud', m: module, r: resource })

/**
 * Create a read preset spec (viewAny, view)
 * @param {string} module
 * @param {string} resource
 * @returns {Object} spec
 */
export const read = (module, resource) => ({ t: 'preset', p: 'read', m: module, r: resource })

/**
 * Create a write preset spec (create, edit, update, delete)
 * @param {string} module
 * @param {string} resource
 * @returns {Object} spec
 */
export const write = (module, resource) => ({ t: 'preset', p: 'write', m: module, r: resource })

// ========================================
// Permission Index (Cached)
// ========================================

/** Singleton index cache to avoid recomputation */
let _indexCache = null
let _accountRef = null

export const resetPermissionIndexCache = () => {
    _indexCache = null
    _accountRef = null
}

/**
 * True once the signed-in account (with its roles) is in the local cache. Before that every permission check fails,
 * so callers that run at page load (the router guard) should wait instead of treating it as "not allowed".
 * @returns {boolean}
 */
export const hasAccountData = () => {
    if (!_accountRef) usePermissionIndex()
    const account = unref(_accountRef)
    return Array.isArray(account?.roles)
}

/**
 * True when the signed-in account is a Webmaster (user id 1, or a role with level 0). Reactive when called inside a
 * computed, and false until the account is cached.
 * @returns {boolean}
 */
export const isWebmaster = () => {
    const idx = usePermissionIndex()
    return Boolean(idx?.value?.isWebmaster)
}

/**
 * True when a route marked `meta.webmasterOnly` must be refused: the account is known and it is not a Webmaster.
 * While the account is not cached yet this stays false (the page guards itself once the account arrives).
 * @param {object} meta Route meta.
 * @param {boolean} [ready] Whether the account is cached.
 * @param {boolean} [webmaster] Whether the account is a Webmaster.
 * @returns {boolean}
 */
export const isWebmasterRouteBlocked = (meta, ready = hasAccountData(), webmaster = isWebmaster()) =>
    Boolean(meta?.webmasterOnly) && ready && !webmaster

const normalizePermissionToken = (value) => String(value || '').trim()

/**
 * True when the account is a Webmaster: user id 1, or any role whose custom level is 0.
 * @param {object|null|undefined} account Account payload with optional `roles`.
 * @returns {boolean}
 */
export const isWebmasterAccount = (account) => {
    if (Number(account?.id ?? -1) === 1) return true

    const roles = Array.isArray(account?.roles) ? account.roles : []
    return roles.some((role) => Number(role?.custom?.level ?? 99) === 0)
}

const isWebmasterManagedResource = (resource) =>
    WEBMASTER_MANAGED_RESOURCES.has(normalizePermissionToken(resource))

const isWebmasterBypassSpec = (spec) => {
    if (!spec || typeof spec !== 'object') return false

    switch (spec.t) {
        case 'module':
            return isWebmasterManagedResource(spec.m)

        case 'resource':
        case 'action':
        case 'preset':
            return isWebmasterManagedResource(spec.r)

        case 'legacy':
            return isWebmasterManagedResource(spec.r)

        case 'anyOf':
            return (spec.items || []).some(isWebmasterBypassSpec)

        case 'allOf':
            return (spec.items || []).every(isWebmasterBypassSpec)

        default:
            return false
    }
}

/**
 * Build the permission index from account data.
 * Cached and only rebuilt when account changes.
 * @returns {ComputedRef<Object>} computed permission index
 */
export const usePermissionIndex = () => {
    // Return cached if account ref is the same
    if (_accountRef) {
        return _indexCache
    }

    _accountRef = useStorage(CACHE_KEY_ACCOUNT_DATA, {})

    _indexCache = computed(() => {
        const account = unref(_accountRef)

        // Sets for O(1) lookups
        const moduleSet = new Set()
        const resourceSet = new Set()
        const moduleResourceSet = new Set()
        const newActionSet = new Set()
        const legacyActionSet = new Set()
        const idSet = new Set()

        // Maps for preset validation (which actions exist for a resource)
        const actionByResource = new Map()
        const legacyActionByResource = new Map()

        const roles = Array.isArray(account?.roles) ? account.roles : []
        const isWebmaster = isWebmasterAccount(account)

        for (const role of roles) {
            const permissions = role.permissions || []
            for (const perm of permissions) {
                if (perm.id) idSet.add(perm.id)

                const group = normalizePermissionToken(perm.group)
                const action = normalizePermissionToken(perm.name)

                if (!group || !action) continue

                // Parse group into module and resource
                const parts = group.split('.')
                let module = ''
                let resource = ''

                if (parts.length >= 2) {
                    // New format: module.resource (e.g., "education.edu_semester")
                    module = parts[0]
                    resource = parts.slice(1).join('.')
                } else {
                    // Legacy format: just resource (e.g., "user")
                    resource = parts[0]
                }

                // Build index entries
                if (module) {
                    moduleSet.add(module)
                    moduleResourceSet.add(`${module}.${resource}`)

                    // New action key: module.resource.action
                    const newKey = `${module}.${resource}.${action}`
                    newActionSet.add(newKey)

                    // Track actions per module.resource
                    const mrKey = `${module}.${resource}`
                    if (!actionByResource.has(mrKey)) {
                        actionByResource.set(mrKey, new Set())
                    }
                    actionByResource.get(mrKey).add(action)
                }

                if (resource) {
                    resourceSet.add(resource)

                    // Legacy action key: resource.action
                    const legacyKey = `${resource}.${action}`
                    legacyActionSet.add(legacyKey)

                    // Track actions per resource (legacy)
                    if (!legacyActionByResource.has(resource)) {
                        legacyActionByResource.set(resource, new Set())
                    }
                    legacyActionByResource.get(resource).add(action)
                }
            }
        }

        return {
            isWebmaster,
            moduleSet,
            resourceSet,
            moduleResourceSet,
            newActionSet,
            legacyActionSet,
            idSet,
            actionByResource,
            legacyActionByResource,
        }
    })

    return _indexCache
}

/**
 * Get the current permission index value (unwrapped)
 * @returns {Object} index
 */
const getIndex = () => {
    const idx = usePermissionIndex()
    return unref(idx)
}

// ========================================
// Spec Normalization
// ========================================

/**
 * Normalize arguments into a spec object
 * @param {string|Object} a - module, resource (legacy), or spec object
 * @param {string} [b] - resource or action (legacy)
 * @param {string} [c] - action
 * @returns {Object} spec
 */
const normalizeArgs = (a, b, c) => {
    // Already a spec object
    if (a && typeof a === 'object' && a.t) {
        return a
    }

    // Array of specs => anyOf
    if (Array.isArray(a)) {
        return anyOf(...a)
    }

    const argA = normalizePermissionToken(a)
    const argB = b !== undefined ? normalizePermissionToken(b) : undefined
    const argC = c !== undefined ? normalizePermissionToken(c) : undefined

    // 3 args: can(module, resource, action)
    if (argC !== undefined) {
        return act(argA, argB, argC)
    }

    // 2 args: can(module, resource) OR can(resource, action) legacy
    if (argB !== undefined) {
        // If second arg is a known action, treat as legacy
        if (ACTIONS.has(argB)) {
            return legacy(argA, argB)
        }
        return res(argA, argB)
    }

    // 1 arg: can(module), can('resource.action') legacy string,
    // or can('module.resource[.subresource].action') new-format string
    if (argA.includes('.')) {
        const parts = argA.split('.')

        if (parts.length === 2 && ACTIONS.has(parts[1])) {
            // Legacy: resource.action
            return legacy(parts[0], parts[1])
        }

        if (parts.length === 2) {
            // New format resource check: module.resource
            return res(parts[0], parts[1])
        }

        if (parts.length >= 3) {
            // New format action check: module.resource.action
            return act(parts[0], parts.slice(1, -1).join('.'), parts.at(-1))
        }
    }

    return mod(argA)
}

// ========================================
// Core Evaluation
// ========================================

/**
 * Evaluate a single spec against the permission index
 * @param {Object} spec
 * @param {Object} idx - permission index
 * @returns {boolean}
 */
const evaluateSpec = (spec, idx) => {
    if (!spec || typeof spec !== 'object') return false

    if (idx?.isWebmaster && isWebmasterBypassSpec(spec)) return true

    switch (spec.t) {
        case 'module':
            return idx.moduleSet.has(spec.m)

        case 'resource':
            return idx.moduleResourceSet.has(`${spec.m}.${spec.r}`) || idx.legacyActionSet.has(`${spec.m}.${spec.r}`)

        case 'action': {
            // Try new format first: module.resource.action
            const newKey = `${spec.m}.${spec.r}.${spec.a}`
            if (idx.newActionSet.has(newKey)) return true

            // Fallback to legacy: resource.action
            const legacyKey = `${spec.r}.${spec.a}`
            return idx.legacyActionSet.has(legacyKey)
        }

        case 'legacy': {
            const key = `${spec.r}.${spec.a}`
            return idx.legacyActionSet.has(key)
        }

        case 'anyOf':
            return (spec.items || []).some((item) => evaluateSpec(item, idx))

        case 'allOf':
            return (spec.items || []).every((item) => evaluateSpec(item, idx))

        case 'preset': {
            const presetActions = getPresetActions(spec.p)
            const mrKey = `${spec.m}.${spec.r}`

            // Get actions that actually exist for this resource
            const existingActions = idx.actionByResource.get(mrKey) || new Set()
            const legacyExisting = idx.legacyActionByResource.get(spec.r) || new Set()

            // Check each preset action that exists for this resource
            for (const action of presetActions) {
                // Only check actions that exist in the resource's catalog
                const actionExists = existingActions.has(action) || legacyExisting.has(action)

                if (!actionExists) continue // Skip actions not in catalog

                // Check if user has this action
                const newKey = `${spec.m}.${spec.r}.${action}`
                const legacyKey = `${spec.r}.${action}`
                const hasAction = idx.newActionSet.has(newKey) || idx.legacyActionSet.has(legacyKey)

                if (!hasAction) return false
            }

            return true
        }

        default:
            return false
    }
}

/**
 * Get actions for a preset type
 * @param {string} preset
 * @returns {string[]}
 */
const getPresetActions = (preset) => {
    switch (preset) {
        case 'crud':
            return CRUD_ACTIONS
        case 'read':
            return READ_ACTIONS
        case 'write':
            return WRITE_ACTIONS
        default:
            return []
    }
}

// ========================================
// Public API
// ========================================

/**
 * Check if user has permission
 *
 * @param {string|Object|Array} a - module, resource (legacy), spec object, or array of specs
 * @param {string} [b] - resource or action (legacy)
 * @param {string} [c] - action
 * @returns {boolean}
 *
 * @example
 * can('education')                              // module-level
 * can('education', 'edu_semester')              // resource-level
 * can('education', 'edu_semester', 'viewAny')   // action-level
 * can('user', 'viewAny')                        // legacy (auto-detected)
 * can('user.viewAny')                           // legacy string
 * can(crud('education', 'edu_semester'))        // preset spec
 * can([spec1, spec2])                           // anyOf array
 */
export const can = (a, b, c) => {
    const spec = normalizeArgs(a, b, c)
    const idx = getIndex()
    return evaluateSpec(spec, idx)
}

/**
 * Check if user has ANY of the specified permissions (OR)
 * @param {...Object} specs
 * @returns {boolean}
 */
export const canAny = (...specs) => {
    const spec = anyOf(...specs)
    const idx = getIndex()
    return evaluateSpec(spec, idx)
}

/**
 * Check if user has ALL of the specified permissions (AND)
 * @param {...Object} specs
 * @returns {boolean}
 */
export const canAll = (...specs) => {
    const spec = allOf(...specs)
    const idx = getIndex()
    return evaluateSpec(spec, idx)
}

/**
 * Batch check multiple actions for a resource
 * @param {string} module
 * @param {string} resource
 * @param {string[]} actions
 * @returns {Object<string, boolean>}
 *
 * @example
 * canIn('education', 'edu_semester', ['view', 'edit', 'delete'])
 * // => { view: true, edit: false, delete: true }
 */
export const canIn = (module, resource, actions) => {
    const idx = getIndex()
    const result = {}

    if (idx?.isWebmaster && isWebmasterManagedResource(resource)) {
        for (const action of actions) {
            result[action] = true
        }

        return result
    }

    for (const action of actions) {
        const newKey = `${module}.${resource}.${action}`
        const legacyKey = `${resource}.${action}`
        result[action] = idx.newActionSet.has(newKey) || idx.legacyActionSet.has(legacyKey)
    }

    return result
}

// ========================================
// Debug Helpers
// ========================================

/**
 * Explain why a permission was denied
 * @param {string|Object} a - spec or args
 * @param {string} [b]
 * @param {string} [c]
 * @returns {Object} explanation
 *
 * @example
 * explainDeny(crud('education', 'edu_semester'))
 * // => {
 * //   allowed: false,
 * //   type: 'preset',
 * //   missing: ['action:education.edu_semester.delete'],
 * //   checked: ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
 * //   hint: 'Missing 1 of 6 CRUD actions'
 * // }
 */
export const explainDeny = (a, b, c) => {
    const spec = normalizeArgs(a, b, c)
    const idx = getIndex()

    if (idx?.isWebmaster && isWebmasterBypassSpec(spec)) {
        return {
            allowed: true,
            type: 'webmaster-managed',
            missing: [],
            checked: [],
            hint: null,
        }
    }

    const explain = (s) => {
        if (!s || typeof s !== 'object') {
            return {
                allowed: false,
                type: 'invalid',
                missing: [],
                checked: [],
                hint: 'Invalid spec',
            }
        }

        switch (s.t) {
            case 'module': {
                const allowed = idx.moduleSet.has(s.m)
                return {
                    allowed,
                    type: 'module',
                    missing: allowed ? [] : [`module:${s.m}`],
                    checked: [s.m],
                    hint: allowed ? null : `No permissions in module "${s.m}"`,
                }
            }

            case 'resource': {
                const key = `${s.m}.${s.r}`
                const allowed = idx.moduleResourceSet.has(key)
                return {
                    allowed,
                    type: 'resource',
                    missing: allowed ? [] : [`resource:${key}`],
                    checked: [key],
                    hint: allowed ? null : `No permissions for resource "${key}"`,
                }
            }

            case 'action': {
                const newKey = `${s.m}.${s.r}.${s.a}`
                const legacyKey = `${s.r}.${s.a}`
                const allowed = idx.newActionSet.has(newKey) || idx.legacyActionSet.has(legacyKey)
                return {
                    allowed,
                    type: 'action',
                    missing: allowed ? [] : [`action:${newKey}`],
                    checked: [newKey, legacyKey],
                    hint: allowed ? null : `Missing action "${s.a}" on "${s.m}.${s.r}"`,
                }
            }

            case 'legacy': {
                const key = `${s.r}.${s.a}`
                const allowed = idx.legacyActionSet.has(key)
                return {
                    allowed,
                    type: 'legacy',
                    missing: allowed ? [] : [`legacy:${key}`],
                    checked: [key],
                    hint: allowed ? null : `Missing legacy permission "${key}"`,
                }
            }

            case 'anyOf': {
                const results = (s.items || []).map(explain)
                const allowed = results.some((r) => r.allowed)
                return {
                    allowed,
                    type: 'anyOf',
                    missing: allowed ? [] : results.flatMap((r) => r.missing),
                    checked: results.flatMap((r) => r.checked),
                    hint: allowed ? null : `None of ${results.length} alternatives allowed`,
                    branches: results,
                }
            }

            case 'allOf': {
                const results = (s.items || []).map(explain)
                const allowed = results.every((r) => r.allowed)
                const failedResults = results.filter((r) => !r.allowed)
                return {
                    allowed,
                    type: 'allOf',
                    missing: failedResults.flatMap((r) => r.missing),
                    checked: results.flatMap((r) => r.checked),
                    hint: allowed
                        ? null
                        : `Failed ${failedResults.length} of ${results.length} requirements`,
                    branches: results,
                }
            }

            case 'preset': {
                const presetActions = getPresetActions(s.p)
                const mrKey = `${s.m}.${s.r}`

                const existingActions = idx.actionByResource.get(mrKey) || new Set()
                const legacyExisting = idx.legacyActionByResource.get(s.r) || new Set()

                const checked = []
                const missing = []
                let hasAll = true

                for (const action of presetActions) {
                    const actionExists = existingActions.has(action) || legacyExisting.has(action)

                    if (!actionExists) continue

                    checked.push(action)

                    const newKey = `${s.m}.${s.r}.${action}`
                    const legacyKey = `${s.r}.${action}`
                    const hasAction =
                        idx.newActionSet.has(newKey) || idx.legacyActionSet.has(legacyKey)

                    if (!hasAction) {
                        hasAll = false
                        missing.push(`action:${newKey}`)
                    }
                }

                const presetName = s.p.toUpperCase()
                return {
                    allowed: hasAll,
                    type: 'preset',
                    preset: s.p,
                    missing,
                    checked,
                    hint: hasAll
                        ? null
                        : `Missing ${missing.length} of ${checked.length} ${presetName} actions`,
                }
            }

            default:
                return {
                    allowed: false,
                    type: 'unknown',
                    missing: [],
                    checked: [],
                    hint: 'Unknown spec type',
                }
        }
    }

    return explain(spec)
}

/**
 * Assert that user has permission (throws in dev, returns false in prod)
 * @param {string|Object} a - spec or args
 * @param {string} [b]
 * @param {string} [c]
 * @param {string} [message='Permission denied']
 * @returns {boolean}
 */
export const assertCan = (a, b, c, message = 'Permission denied') => {
    // Handle message in different arg positions
    let spec
    let msg = message

    if (typeof a === 'object' && a.t) {
        spec = a
        msg = typeof b === 'string' ? b : message
    } else if (c !== undefined && typeof c !== 'string') {
        // c might be message if only 2 real args
        spec = normalizeArgs(a, b)
        msg = typeof c === 'string' ? c : message
    } else {
        spec = normalizeArgs(a, b, c)
    }

    const allowed = evaluateSpec(spec, getIndex())

    if (allowed) return true

    // In development, throw with detailed explanation
    const isDev = import.meta.env?.DEV || process.env.NODE_ENV === 'development'

    if (isDev) {
        const explanation = explainDeny(spec)
        const detail = explanation.hint || explanation.missing.join(', ')
        throw new Error(`${msg}: ${detail}`)
    }

    return false
}

// ========================================
// Backward Compatibility
// ========================================

/**
 * @deprecated Use can() instead
 * Legacy rule() function for backward compatibility
 */
export const rule = (...args) => {
    // Warn in development (only once per session)
    if (!rule._warned) {
        const isDev = import.meta.env?.DEV || process.env.NODE_ENV === 'development'
        if (isDev) {
            console.warn(
                '[DEPRECATED] rule() is deprecated. Use can()/crud()/anyOf()/allOf() instead.'
            )
            rule._warned = true
        }
    }

    return can(...args)
}

// ========================================
// Default Export
// ========================================

export default {
    // Core
    can,
    canAny,
    canAll,
    canIn,

    // Spec builders
    mod,
    res,
    act,
    legacy,
    anyOf,
    allOf,
    crud,
    read,
    write,

    // Debug
    explainDeny,
    assertCan,

    // Constants
    ACTIONS,
    CRUD_ACTIONS,
    READ_ACTIONS,
    WRITE_ACTIONS,

    // Index
    usePermissionIndex,

    // Legacy
    rule,
}
