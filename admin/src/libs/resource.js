import ajax from '@/ajax'

const MAX_ENTRIES = 20
/** @type {Map<string, {etag: string|undefined, schema: object}>} Insertion order doubles as LRU order. */
const cache = new Map()

const touch = (key, entry) => {
    cache.delete(key)
    cache.set(key, entry)
    while (cache.size > MAX_ENTRIES) {
        cache.delete(cache.keys().next().value)
    }
}

/**
 * Fetch a resource schema, revalidating a cached copy with its ETag (304 reuses the cache).
 * The request is silent: callers handle rejections themselves.
 *
 * @param {string} module Module name.
 * @param {string} resource Resource name.
 * @returns {Promise<object>}
 */
export const getResourceSchema = async (module, resource) => {
    const key = `${module}/${resource}`
    const cached = cache.get(key)
    const headers = cached?.etag ? { 'If-None-Match': cached.etag } : {}
    const res = await ajax.get(`resources/${module}/${resource}/schema`, {
        silent: true,
        headers,
        validateStatus: (s) => (s >= 200 && s < 300) || s === 304,
    })
    if (res.status === 304 && cached) {
        touch(key, cached)
        return cached.schema
    }
    const schema = res.data.data
    touch(key, { etag: res.headers?.etag ?? res.headers?.['etag'], schema })
    return schema
}

/**
 * Empty the in-memory schema cache (tests).
 *
 * @returns {void}
 */
export const clearResourceSchemaCache = () => cache.clear()
