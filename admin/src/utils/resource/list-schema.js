import { safeEntries } from './field-path'
import { filterSlots } from './custom-fields'

/**
 * Route-query state schema of a generic resource list. Pure helpers, i18n-free.
 */

/** Query keys the list never uses for a filter: its own search / page keys and the page's open-record key. */
export const RESERVED_LIST_KEYS = ['q', 'page', 'record']

/** Query key that addresses the open detail drawer of a resource page. */
export const RECORD_QUERY_KEY = 'record'

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/**
 * Whether a value is a canonical uuid.
 *
 * @param {unknown} value
 * @returns {boolean}
 */
export const isUuid = (value) => typeof value === 'string' && UUID_PATTERN.test(value)

/**
 * Build the `parseListState` schema of a resource list: `page`, `q` when the list has search, and one string key per
 * filter field (reserved keys are skipped so a filter can never shadow the page, search or record keys).
 *
 * @param {object} [schema] Resource schema from the schema endpoint (`list.search`, `list.filters`).
 * @returns {Record<string, 'string'|'int'>}
 */
export function listStateSchema(schema) {
    const list = schema?.list || {}
    const result = { page: 'int' }
    if (list.search) result.q = 'string'
    for (const filter of safeEntries(list.filters)) {
        if (RESERVED_LIST_KEYS.includes(filter.field)) continue
        for (const slot of filterSlots(filter)) result[slot.key] = slot.kind === 'list' ? 'list' : 'string'
    }

    return result
}
