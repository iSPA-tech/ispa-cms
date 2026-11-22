import { describe, expect, it } from 'vitest'
import { normaliseViewState, sameState, viewToQuery } from '../../utils/saved-views.js'

const schema = { q: 'string', page: 'int', status: 'string', owner: 'int', tags: 'list' } as const

describe('sameState', () => {
    it('ignores key order, the page and empty values', () => {
        expect(sameState({ status: 'open', q: 'a' }, { q: 'a', status: 'open', page: 3, tags: [], owner: null })).toBe(true)
    })

    it('treats numbers and numeric strings alike and list order as irrelevant', () => {
        expect(sameState({ owner: 5, tags: ['b', 'a'] }, { owner: '5', tags: ['a', 'b'] })).toBe(true)
    })

    it('tells different filters apart', () => {
        expect(sameState({ status: 'open' }, { status: 'closed' })).toBe(false)
        expect(sameState({ status: 'open' }, {})).toBe(false)
        expect(sameState({ tags: ['a'] }, { tags: ['a', 'b'] })).toBe(false)
    })

    it('copes with missing input', () => {
        expect(sameState(undefined, null)).toBe(true)
        expect(normaliseViewState({ q: '  hi ' })).toEqual({ q: 'hi' })
    })
})

describe('viewToQuery', () => {
    it('writes the state as a query without a page and drops unknown keys', () => {
        const view = { state: { q: 'vip', status: 'open', owner: 7, tags: ['x', 'y'], page: 4, evil: '1' } }
        expect(viewToQuery(view, schema)).toEqual({ q: 'vip', status: 'open', owner: 7, tags: ['x', 'y'] })
    })

    it('gives an empty query for a view without usable state', () => {
        expect(viewToQuery({}, schema)).toEqual({})
        expect(viewToQuery({ state: 'nope' }, schema)).toEqual({})
    })
})
