import { describe, expect, it } from 'vitest'
import { contentCollectionV1, getListAdapter, ispaListV1, laravelPaginateV1, listAdapters } from '@/utils/resource/list-adapters'

describe('ispa-list-v1 adapter', () => {
    it('is registered by name', () => {
        expect(listAdapters['ispa-list-v1']).toBe(ispaListV1)
        expect(getListAdapter('ispa-list-v1')).toBe(ispaListV1)
    })

    it('builds params with defaults', () => {
        expect(ispaListV1.toParams({})).toEqual({ page: 1, per_page: 20 })
    })

    it('clamps per page and trims search', () => {
        expect(ispaListV1.toParams({ page: 3, perPage: 500, search: '  abc ' })).toEqual({ page: 3, per_page: 100, q: 'abc' })
        expect(ispaListV1.toParams({ perPage: 0 }).per_page).toBe(1)
    })

    it('uses searchParam, caps length, omits empty search', () => {
        expect(ispaListV1.toParams({ search: 'x'.repeat(150), searchParam: 'search' }).search).toHaveLength(100)
        expect(ispaListV1.toParams({ search: '   ' })).not.toHaveProperty('q')
    })

    it('merges filters omitting empty values', () => {
        const p = ispaListV1.toParams({ filters: { status: 'a', none: null, undef: undefined, blank: '', zero: 0, no: false } })
        expect(p).toMatchObject({ status: 'a', zero: 0, no: false })
        expect(p).not.toHaveProperty('none')
        expect(p).not.toHaveProperty('undef')
        expect(p).not.toHaveProperty('blank')
    })

    it('parses a response', () => {
        expect(
            ispaListV1.fromResponse({ data: [{ id: 1 }], meta: { current_page: 2, last_page: 5, per_page: 10, total: 42 } })
        ).toEqual({ rows: [{ id: 1 }], page: 2, perPage: 10, total: 42, lastPage: 5 })
    })

    it('has safe defaults', () => {
        expect(ispaListV1.fromResponse(undefined)).toEqual({ rows: [], page: 1, perPage: 20, total: 0, lastPage: 1 })
        expect(ispaListV1.fromResponse({ data: [1, 2] })).toEqual({ rows: [1, 2], page: 1, perPage: 20, total: 2, lastPage: 1 })
    })

    it('throws on unknown adapter', () => {
        expect(() => getListAdapter('nope')).toThrow()
    })
})

describe('laravel-paginate-v1 adapter', () => {
    it('is registered by name and shares the request params', () => {
        expect(listAdapters['laravel-paginate-v1']).toBe(laravelPaginateV1)
        expect(laravelPaginateV1.toParams({ page: 2, perPage: 10, search: 'a' })).toEqual({ page: 2, per_page: 10, q: 'a' })
    })

    it('maps the nested paginator to rows, page, per page, total and last page', () => {
        const body = { data: { data: [{ uuid: 'a' }, { uuid: 'b' }], current_page: 2, per_page: 15, total: 31, last_page: 3 } }
        expect(laravelPaginateV1.fromResponse(body)).toEqual({ rows: [{ uuid: 'a' }, { uuid: 'b' }], page: 2, perPage: 15, total: 31, lastPage: 3 })
    })

    it('falls back to safe defaults for an empty or malformed body', () => {
        expect(laravelPaginateV1.fromResponse({})).toEqual({ rows: [], page: 1, perPage: 20, total: 0, lastPage: 1 })
        expect(laravelPaginateV1.fromResponse({ data: { data: [], total: 0 } }).total).toBe(0)
    })
})

describe('content-collection-v1 adapter', () => {
    it('is registered by name', () => {
        expect(listAdapters['content-collection-v1']).toBe(contentCollectionV1)
    })

    it('sends the page size as pageSize and keeps page and search', () => {
        expect(contentCollectionV1.toParams({ page: 2, perPage: 50, search: 'abc', searchParam: 'keywords' })).toEqual({ page: 2, pageSize: 50, keywords: 'abc' })
    })

    it('maps the confirmed filter to the values the server understands', () => {
        expect(contentCollectionV1.toParams({ filters: { is_confirmed: '1', status: 'content_published' } })).toMatchObject({ confirmed: 1, status: 'content_published' })
        expect(contentCollectionV1.toParams({ filters: { is_confirmed: '0' } })).toMatchObject({ confirmed: -1 })
        expect(contentCollectionV1.toParams({ filters: { is_confirmed: '0' } })).not.toHaveProperty('is_confirmed')
        expect(contentCollectionV1.toParams({})).not.toHaveProperty('confirmed')
    })

    it('maps the camelCase paginator to rows, page, per page, total and last page', () => {
        const body = { data: { data: [{ uuid: 'a' }], total: 31, count: 1, perPage: 10, currentPage: 2, totalPages: 4 } }
        expect(contentCollectionV1.fromResponse(body)).toEqual({ rows: [{ uuid: 'a' }], page: 2, perPage: 10, total: 31, lastPage: 4 })
    })

    it('falls back to safe defaults for an empty body', () => {
        expect(contentCollectionV1.fromResponse(undefined)).toEqual({ rows: [], page: 1, perPage: 20, total: 0, lastPage: 1 })
    })
})
