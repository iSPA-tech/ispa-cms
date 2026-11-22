import { describe, expect, it } from 'vitest'
import { resolveOpenNavGroup } from '@/utils/navOpenGroup'

const navs = [
    { name: 'user', group: 'user', children: [{ name: 'user-list' }] },
    {
        name: 'education',
        group: 'education',
        children: [{ name: 'student-manage' }, { name: 'sub', children: [{ name: 'deep-page' }] }],
    },
]

describe('resolveOpenNavGroup', () => {
    it('opens the group whose children hold the route, not meta.group', () => {
        expect(resolveOpenNavGroup(navs, { name: 'student-manage', meta: { group: 'user' } })).toBe('education')
    })

    it('falls back to meta.group when no nav contains the route', () => {
        expect(resolveOpenNavGroup(navs, { name: 'unknown', meta: { group: 'user' } })).toBe('user')
        expect(resolveOpenNavGroup(navs, { name: 'unknown', meta: {} })).toBeNull()
    })

    it('matches meta.activeNav', () => {
        expect(resolveOpenNavGroup(navs, { name: 'student-detail', meta: { activeNav: 'student-manage', group: 'user' } })).toBe(
            'education'
        )
    })

    it('keeps the route own group when that group also lists the route', () => {
        const shared = [
            { name: 'education', group: 'education', children: [{ name: 'shared-page' }] },
            { name: 'user', group: 'user', children: [{ name: 'shared-page' }] },
        ]
        expect(resolveOpenNavGroup(shared, { name: 'shared-page', meta: { group: 'user' } })).toBe('user')
    })

    it('searches nested children', () => {
        expect(resolveOpenNavGroup(navs, { name: 'deep-page', meta: {} })).toBe('education')
    })
})
