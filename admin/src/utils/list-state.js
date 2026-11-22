/**
 * Route-query list state helpers: keep a list's search, filters and page in the URL and build a safe
 * "back to the list" link. Pure functions, i18n-free.
 *
 * Schema: `{ q: 'string', page: 'int', <filter>: 'string' | 'int' | 'list' }`.
 */

export const LIST_STATE_MAX_STRING = 200
export const LIST_STATE_MAX_LIST_ITEMS = 50
export const RETURN_TO_MAX_LENGTH = 500
export const RETURN_TO_QUERY_KEY = 'from'

const first = (value) => (Array.isArray(value) ? value[0] : value)

const cleanString = (value) => {
    if (value === undefined || value === null || typeof value === 'object') {
        return ''
    }

    return String(value).trim().slice(0, LIST_STATE_MAX_STRING)
}

const cleanInt = (key, value) => {
    const raw = cleanString(first(value))
    if (!/^-?\d+$/.test(raw)) {
        return null
    }
    const number = parseInt(raw, 10)
    if (!Number.isSafeInteger(number)) {
        return null
    }

    return key === 'page' ? Math.max(1, number) : number
}

const cleanList = (value) => {
    const items = Array.isArray(value) ? value : typeof value === 'string' ? value.split(',') : []

    return items
        .map(cleanString)
        .filter((item) => item !== '')
        .slice(0, LIST_STATE_MAX_LIST_ITEMS)
}

const isEmpty = (value) =>
    value === undefined ||
    value === null ||
    value === '' ||
    (Array.isArray(value) && value.length === 0)

/**
 * Parse a route query into list state. Unknown keys are dropped; only keys present with a usable value are returned.
 *
 * @param {object} query Route query object.
 * @param {Record<string, 'string'|'int'|'list'>} schema Allowed keys and their types.
 * @returns {object}
 */
export function parseListState(query, schema) {
    const result = {}
    const source = query && typeof query === 'object' ? query : {}

    for (const [key, type] of Object.entries(schema || {})) {
        if (!Object.prototype.hasOwnProperty.call(source, key)) {
            continue
        }
        const value = type === 'int' ? cleanInt(key, source[key]) : type === 'list' ? cleanList(source[key]) : cleanString(first(source[key]))
        if (!isEmpty(value)) {
            result[key] = value
        }
    }

    return result
}

const sameValue = (a, b) => (Array.isArray(a) || Array.isArray(b) ? JSON.stringify(a ?? []) === JSON.stringify(b ?? []) : a === b)

/**
 * Serialise list state into a short query object: defaults and empty values are omitted.
 *
 * @param {object} state Current state.
 * @param {Record<string, 'string'|'int'|'list'>} schema Allowed keys and their types.
 * @param {object} [defaults] Default values (page defaults to 1).
 * @returns {object}
 */
export function serializeListState(state, schema, defaults = {}) {
    const clean = parseListState(
        Object.fromEntries(Object.entries(state || {}).map(([k, v]) => [k, typeof v === 'number' ? String(v) : v])),
        schema
    )
    const result = {}

    for (const key of Object.keys(schema || {})) {
        const value = clean[key]
        const fallback = key === 'page' && defaults.page === undefined ? 1 : defaults[key]
        if (isEmpty(value) || sameValue(value, fallback)) {
            continue
        }
        result[key] = value
    }

    return result
}

/**
 * Current route as an in-app "back" target, or '' when it cannot be used safely.
 *
 * @param {{ fullPath?: string }} route
 * @returns {string}
 */
export function buildReturnTo(route) {
    const path = route?.fullPath

    return typeof path === 'string' && path.startsWith('/') && path.length <= RETURN_TO_MAX_LENGTH ? path : ''
}

/**
 * Validate a raw return link. Accepts only in-app absolute paths (one leading slash, no scheme, no backslash,
 * no control characters, at most 500 characters).
 *
 * @param {unknown} raw
 * @returns {string|null}
 */
export function safeReturnTo(raw) {
    if (typeof raw !== 'string' || raw === '' || raw.length > RETURN_TO_MAX_LENGTH) {
        return null
    }
    if (!raw.startsWith('/') || raw.startsWith('//')) {
        return null
    }
    // eslint-disable-next-line no-control-regex
    if (raw.includes('\\') || /[\u0000-\u001f\u007f]/.test(raw)) {
        return null
    }

    return raw
}

/**
 * Validate a return link and keep it only when it points at the given list route (same path, any query or hash).
 * The admin router mounts every route under a base prefix (`/iPanel/helpdesk/customers`), so the list path matches as
 * the trailing part of the link's path.
 * A link to any other page gives null, so a list breadcrumb never lands on a page its label does not name.
 *
 * @param {unknown} raw Raw `from` value.
 * @param {string} listPath In-app path of the list route, for example `/helpdesk/customers`.
 * @returns {string|null}
 */
export function returnToWithin(raw, listPath) {
    const safe = safeReturnTo(raw)
    if (!safe || typeof listPath !== 'string') {
        return null
    }
    const path = safe.split(/[?#]/)[0].replace(/\/+$/, '') || '/'
    const expected = listPath.replace(/\/+$/, '') || '/'

    return path === expected || path.endsWith(expected) ? safe : null
}

/**
 * Add the current list location as `query.from` to a router location (kept off when it is not a safe path).
 *
 * @param {string|object} location Router location (path string or object).
 * @param {{ fullPath?: string }} route Current route.
 * @returns {object}
 */
export function withReturnTo(location, route) {
    const base = typeof location === 'string' ? { path: location } : { ...(location || {}) }
    const from = buildReturnTo(route)
    if (!from) {
        return base
    }

    return { ...base, query: { ...(base.query || {}), [RETURN_TO_QUERY_KEY]: from } }
}
