/**
 * Client hooks for resource actions. A schema action may name a hook (`after: 'hookName'`); a module registers the
 * implementation here and the generic screens run it once after the server accepted the action.
 */
import { message } from 'ant-design-vue'

/** @type {Map<string, (context: object) => (void|Promise<void>)>} */
const hooks = new Map()

/**
 * Register a hook under a name; a later registration with the same name replaces the earlier one.
 *
 * @param {string} name Hook name used in the schema `after` key.
 * @param {(context: object) => (void|Promise<void>)} fn Hook implementation.
 * @returns {void}
 */
export function registerActionHook(name, fn) {
    if (typeof name !== 'string' || name === '' || typeof fn !== 'function') {
        throw new TypeError('registerActionHook expects a non-empty name and a function.')
    }
    hooks.set(name, fn)
}

/**
 * Remove a registered hook.
 *
 * @param {string} name Hook name.
 * @returns {boolean} True when a hook was removed.
 */
export function unregisterActionHook(name) {
    return hooks.delete(name)
}

/**
 * Run a hook after a successful action. Never throws: an unknown name only warns in development, and a hook
 * error is shown as a message without undoing the server change.
 *
 * @param {string|undefined|null} name Hook name; empty means no hook.
 * @param {object} context Data passed to the hook (action, record or uuids, response).
 * @returns {Promise<boolean>} True when a hook ran without error.
 */
export async function runActionHook(name, context = {}) {
    if (!name) return false
    const fn = hooks.get(name)
    if (!fn) {
        if (import.meta.env.DEV) console.warn(`[resource] Unknown action hook "${name}".`)
        return false
    }
    try {
        await fn(context)
        return true
    } catch (error) {
        message.error(error?.message || String(name))
        return false
    }
}

/**
 * Message the server sent with a failed response (for example a 409 "has children" guard), or an empty string.
 *
 * @param {unknown} error Request error.
 * @returns {string}
 */
export function serverMessage(error) {
    const text = error?.response?.data?.message
    return typeof text === 'string' ? text.trim() : ''
}
