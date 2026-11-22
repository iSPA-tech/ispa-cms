/**
 * List adapters translate between the generic resource list screen and a backend list contract.
 */

const DEFAULT_PER_PAGE = 20
const MAX_SEARCH_LENGTH = 100

/** Adapter for the `ispa-list-v1` contract (`page`, `per_page`, search param; `{ data, meta }` responses). */
export const ispaListV1 = {
    /**
     * Build query params for a list request.
     *
     * @param {{page?: number, perPage?: number, search?: string, searchParam?: string, filters?: object}} state
     * @returns {object}
     */
    toParams({ page = 1, perPage = DEFAULT_PER_PAGE, search = '', searchParam = 'q', filters = {} } = {}) {
        const params = {
            page: Math.max(1, Number(page) || 1),
            per_page: Math.max(1, Math.min(100, Number.isFinite(Number(perPage)) ? Number(perPage) : DEFAULT_PER_PAGE)),
        }
        const term = String(search ?? '').trim().slice(0, MAX_SEARCH_LENGTH)
        if (term !== '') {
            params[searchParam || 'q'] = term
        }
        for (const [key, value] of Object.entries(filters || {})) {
            if (value !== null && value !== undefined && value !== '') {
                params[key] = value
            }
        }
        return params
    },

    /**
     * Normalise a list response body.
     *
     * @param {{data?: Array, meta?: object}} body
     * @returns {{rows: Array, page: number, perPage: number, total: number, lastPage: number}}
     */
    fromResponse(body) {
        const rows = Array.isArray(body?.data) ? body.data : []
        const meta = body?.meta || {}
        return {
            rows,
            page: Number(meta.current_page) || 1,
            perPage: Number(meta.per_page) || DEFAULT_PER_PAGE,
            total: Number(meta.total) || (meta.total === 0 ? 0 : rows.length),
            lastPage: Number(meta.last_page) || 1,
        }
    },
}

/**
 * Adapter for the Laravel paginator contract: same request params as `ispa-list-v1`; the response is
 * `{ data: { data: [...], current_page, per_page, total, last_page } }` (the paginator nested under `data`).
 */
export const laravelPaginateV1 = {
    toParams: ispaListV1.toParams,

    /**
     * Normalise a nested paginator response body.
     *
     * @param {{data?: {data?: Array, current_page?: number, per_page?: number, total?: number, last_page?: number}}} body
     * @returns {{rows: Array, page: number, perPage: number, total: number, lastPage: number}}
     */
    fromResponse(body) {
        const paginator = body?.data || {}
        const rows = Array.isArray(paginator.data) ? paginator.data : []
        return {
            rows,
            page: Number(paginator.current_page) || 1,
            perPage: Number(paginator.per_page) || DEFAULT_PER_PAGE,
            total: Number(paginator.total) || (paginator.total === 0 ? 0 : rows.length),
            lastPage: Number(paginator.last_page) || 1,
        }
    },
}

/**
 * Adapter for `ContentCollection` (Content module): the page size travels as `pageSize`, a "no" on the confirmed
 * filter as `-1` (the server drops an empty-looking `0`), and the response is
 * `{ data: { data: [...], total, perPage, currentPage, totalPages } }` with camelCase paginator keys.
 */
export const contentCollectionV1 = {
    /**
     * Build query params for a Content list request.
     *
     * @param {{page?: number, perPage?: number, search?: string, searchParam?: string, filters?: object}} state
     * @returns {object}
     */
    toParams(state = {}) {
        const { per_page: perPage, ...params } = ispaListV1.toParams(state)
        params.pageSize = perPage
        if ('is_confirmed' in params) {
            params.confirmed = ['1', 1, true, 'true'].includes(params.is_confirmed) ? 1 : -1
            delete params.is_confirmed
        }
        return params
    },

    /**
     * Normalise a Content list response body.
     *
     * @param {{data?: {data?: Array, total?: number, perPage?: number, currentPage?: number, totalPages?: number}}} body
     * @returns {{rows: Array, page: number, perPage: number, total: number, lastPage: number}}
     */
    fromResponse(body) {
        const paginator = body?.data || {}
        const rows = Array.isArray(paginator.data) ? paginator.data : []
        return {
            rows,
            page: Number(paginator.currentPage) || 1,
            perPage: Number(paginator.perPage) || DEFAULT_PER_PAGE,
            total: Number(paginator.total) || (paginator.total === 0 ? 0 : rows.length),
            lastPage: Number(paginator.totalPages) || 1,
        }
    },
}

/** Registered list adapters keyed by name. */
export const listAdapters = { 'ispa-list-v1': ispaListV1, 'laravel-paginate-v1': laravelPaginateV1, 'content-collection-v1': contentCollectionV1 }

/**
 * Look up a list adapter by name.
 *
 * @param {string} name Adapter name.
 * @returns {typeof ispaListV1}
 * @throws {Error} When the adapter is unknown.
 */
export function getListAdapter(name) {
    const adapter = listAdapters[name]
    if (!adapter) {
        throw new Error(`Unknown list adapter "${name}".`)
    }
    return adapter
}
