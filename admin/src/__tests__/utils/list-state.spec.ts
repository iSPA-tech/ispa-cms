import { describe, expect, it } from 'vitest'
import {
    buildReturnTo,
    parseListState,
    returnToWithin,
    safeReturnTo,
    serializeListState,
    withReturnTo,
} from '../../utils/list-state.js'

const schema = { q: 'string', page: 'int', status: 'string', owner: 'int', tags: 'list' } as const

describe('list-state', () => {
    it('round-trips every type', () => {
        const state = { q: 'abc', page: 3, status: 'open', owner: 7, tags: ['a', 'b'] }
        const query = serializeListState(state, schema)
        expect(query).toEqual({ q: 'abc', page: 3, status: 'open', owner: 7, tags: ['a', 'b'] })
        const asUrl = { q: 'abc', page: '3', status: 'open', owner: '7', tags: ['a', 'b'] }
        expect(parseListState(asUrl, schema)).toEqual(state)
    })

    it('drops unknown keys and over-long values', () => {
        expect(parseListState({ evil: '1', q: 'x'.repeat(500) }, schema)).toEqual({ q: 'x'.repeat(200) })
        expect(parseListState({ q: '  hi  ', page: '0' }, schema)).toEqual({ q: 'hi', page: 1 })
        expect(parseListState({ page: 'abc', owner: '1.5' }, schema)).toEqual({})
        expect(parseListState({ tags: 'a, b,,c' }, schema)).toEqual({ tags: ['a', 'b', 'c'] })
    })

    it('omits defaults and empty values', () => {
        expect(serializeListState({ q: '', page: 1, status: 'all', tags: [] }, schema, { status: 'all' })).toEqual({})
        expect(serializeListState({ page: 2, status: 'all' }, schema, { status: 'all' })).toEqual({ page: 2 })
    })

    it('rejects unsafe return links', () => {
        for (const bad of ['//evil.com', 'https://x', 'javascript:alert(1)', '/\\x', '/a'.padEnd(501, 'a'), '', ['/a'], null, undefined, '/a\nb']) {
            expect(safeReturnTo(bad as any)).toBeNull()
        }
        expect(safeReturnTo('/iPanel/helpdesk/tickets?view=mine&page=2')).toBe('/iPanel/helpdesk/tickets?view=mine&page=2')
        expect(safeReturnTo('/a'.padEnd(500, 'a'))).not.toBeNull()
    })

    it('builds a return link from the route', () => {
        expect(buildReturnTo({ fullPath: '/list?page=2' })).toBe('/list?page=2')
        expect(buildReturnTo({ fullPath: 'https://x' })).toBe('')
        expect(buildReturnTo({ fullPath: '/' + 'a'.repeat(500) })).toBe('')
        expect(buildReturnTo(undefined as any)).toBe('')
    })

    it('adds query.from', () => {
        expect(withReturnTo({ path: '/d/1', query: { tab: 'x' } }, { fullPath: '/list?page=2' })).toEqual({
            path: '/d/1',
            query: { tab: 'x', from: '/list?page=2' },
        })
        expect(withReturnTo('/d/1', { fullPath: 'bad' })).toEqual({ path: '/d/1' })
    })

    it('keeps a return link only when it points at its own list', () => {
        expect(returnToWithin('/helpdesk/customers?status=active&page=2', '/helpdesk/customers')).toBe('/helpdesk/customers?status=active&page=2')
        expect(returnToWithin('/helpdesk/customers', '/helpdesk/customers')).toBe('/helpdesk/customers')
        expect(returnToWithin('/iPanel/helpdesk/customers?q=a', '/helpdesk/customers')).toBe('/iPanel/helpdesk/customers?q=a')
        expect(returnToWithin('/iPanel/helpdesk/customers/c-1', '/helpdesk/customers')).toBeNull()
        expect(returnToWithin('/iPanel/helpdesk/organizations?q=a', '/helpdesk/customers')).toBeNull()
        expect(returnToWithin('/helpdesk/customers/?q=a', '/helpdesk/customers')).toBe('/helpdesk/customers/?q=a')
        expect(returnToWithin('/helpdesk/customers/c-1', '/helpdesk/organizations')).toBeNull()
        expect(returnToWithin('/helpdesk/customers/c-1?x=1', '/helpdesk/customers')).toBeNull()
        expect(returnToWithin('/helpdesk/customers-old', '/helpdesk/customers')).toBeNull()
        expect(returnToWithin('https://evil.example/helpdesk/customers', '/helpdesk/customers')).toBeNull()
        expect(returnToWithin(undefined, '/helpdesk/customers')).toBeNull()
    })
})
