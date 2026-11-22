import { parseListState, serializeListState } from './list-state.js'

/**
 * Pure helpers of the saved views bar. i18n-free.
 */

const scalar = (value) => String(value).trim()

/**
 * Normalise a list state for comparison and storage: empty values and the page are dropped, numbers become strings,
 * lists keep only non-empty items in a stable (sorted) order. Keys come back sorted so equal states stringify equally.
 *
 * @param {object} state Raw list state (route state, or the state of a saved view).
 * @returns {Record<string, string|string[]>}
 */
export function normaliseViewState(state) {
    const source = state && typeof state === 'object' ? state : {}
    const result = {}

    for (const key of Object.keys(source).sort()) {
        if (key === 'page') continue
        const value = source[key]
        if (value === undefined || value === null || typeof value === 'object' && !Array.isArray(value)) continue
        if (Array.isArray(value)) {
            const items = value.filter((v) => v !== undefined && v !== null && v !== '').map(scalar).filter(Boolean).sort()
            if (items.length) result[key] = items
            continue
        }
        const text = scalar(value)
        if (text !== '') result[key] = text
    }

    return result
}

/**
 * Whether two list states mean the same filters. Order independent (keys and list items); the page is ignored.
 *
 * @param {object} a First state.
 * @param {object} b Second state.
 * @returns {boolean}
 */
export function sameState(a, b) {
    return JSON.stringify(normaliseViewState(a)) === JSON.stringify(normaliseViewState(b))
}

/**
 * Route query of a saved view: its state cleaned through the list schema (unknown keys dropped), without a page so the
 * list restarts on page 1.
 *
 * @param {{state?: object}} view Saved view.
 * @param {Record<string, 'string'|'int'|'list'>} schema List state schema.
 * @returns {object}
 */
export function viewToQuery(view, schema) {
    const { page, ...rest } = parseListState(view?.state && typeof view.state === 'object' ? view.state : {}, schema)
    void page

    return serializeListState(rest, schema)
}
