/**
 * Dotted field paths for the generic resource renderer (`custom_fields.<key>`). Pure helpers, i18n-free.
 *
 * A path is unsafe when any segment is empty or names an object prototype member; such paths are never read or written,
 * so a schema entry cannot pollute `Object.prototype`.
 */

/** Path segments that are never allowed. */
export const FORBIDDEN_SEGMENTS = ['__proto__', 'constructor', 'prototype']

/**
 * Split a dotted path into segments.
 *
 * @param {unknown} path Dotted path such as `custom_fields.tier`.
 * @returns {string[]|null} Segments, or null when the path is empty, has an empty segment or a forbidden segment.
 */
export function pathSegments(path) {
    if (typeof path !== 'string' || path === '') return null
    const segments = path.split('.')
    return segments.some((segment) => segment === '' || FORBIDDEN_SEGMENTS.includes(segment)) ? null : segments
}

/**
 * Whether a path is safe to read and write.
 *
 * @param {unknown} path
 * @returns {boolean}
 */
export const isSafePath = (path) => pathSegments(path) !== null

/**
 * Read a dotted path from an object. A flat key that equals the whole path wins (records that carry
 * `'custom_fields.tier'` literally), otherwise the segments are walked. Unsafe or missing paths give `undefined`.
 *
 * @param {object|null|undefined} source Object to read.
 * @param {string} path Dotted path.
 * @returns {*}
 */
export function getPath(source, path) {
    const segments = pathSegments(path)
    if (!segments || source === null || typeof source !== 'object') return undefined
    if (Object.prototype.hasOwnProperty.call(source, path)) return source[path]
    let current = source
    for (const segment of segments) {
        if (current === null || typeof current !== 'object' || !Object.prototype.hasOwnProperty.call(current, segment)) return undefined
        current = current[segment]
    }
    return current
}

/**
 * Write a value at a dotted path, creating plain objects on the way. Unsafe paths are ignored.
 *
 * @param {object} target Object to mutate.
 * @param {string} path Dotted path.
 * @param {*} value Value to store.
 * @returns {object} The same target.
 */
export function setPath(target, path, value) {
    const segments = pathSegments(path)
    if (!segments) return target
    let current = target
    for (const segment of segments.slice(0, -1)) {
        if (current[segment] === null || typeof current[segment] !== 'object' || Array.isArray(current[segment])) current[segment] = {}
        current = current[segment]
    }
    current[segments[segments.length - 1]] = value
    return target
}

/**
 * Keep only the schema entries whose `field` is a safe path.
 *
 * @param {Array<{field?: string}>} entries Schema fields, columns, sections or filters.
 * @returns {Array<object>}
 */
export const safeEntries = (entries) => (entries || []).filter((entry) => isSafePath(entry?.field))

/**
 * Nest flat `{ 'a.b': v }` values into `{ a: { b: v } }`.
 *
 * @param {Record<string, *>} flat Values keyed by field path.
 * @returns {object}
 */
export function nestPaths(flat) {
    const result = {}
    for (const [path, value] of Object.entries(flat || {})) setPath(result, path, value)
    return result
}

/**
 * Map server validation errors onto form field names. An error key matches a field exactly (`custom_fields.tags`) or as
 * a child of it (`custom_fields.tags.0`); child messages land on the parent field. Unknown keys are kept as they are.
 *
 * @param {Record<string, string[]|string>} serverErrors Laravel `errors` object.
 * @param {string[]} fieldNames Names of the rendered fields.
 * @returns {Record<string, string[]>}
 */
export function mapErrorKeys(serverErrors, fieldNames) {
    const mapped = {}
    for (const [key, raw] of Object.entries(serverErrors || {})) {
        const messages = Array.isArray(raw) ? raw.map(String) : [String(raw)]
        const owner = fieldNames.includes(key) ? key : (fieldNames.find((name) => key.startsWith(`${name}.`)) ?? key)
        mapped[owner] = [...(mapped[owner] || []), ...messages]
    }
    return mapped
}
