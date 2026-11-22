/**
 * a route group redirect must name its target (`redirect: { name }`). A bare string is a PATH to Vue Router:
 * `redirect: 'education-dashboard'` went to `/education-dashboard`, outside /iPanel, and showed a blank page.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/mixins', () => ({
    perm: (moduleName: string) => moduleName,
}))

import { router } from '../../config/route'

describe('route redirects', () => {
    const records = router.getRoutes() as any[]

    it('has no redirect given as a bare string that is not a path', () => {
        const bare = records
            .filter((record) => typeof record.redirect === 'string' && !record.redirect.startsWith('/'))
            .map((record) => `${String(record.name)} -> ${record.redirect}`)
        expect(bare).toEqual([])
    })

    it('sends every named redirect to a route that exists', () => {
        const names = new Set(records.map((record) => String(record.name)))
        const missing = records
            .filter((record) => record.redirect && typeof record.redirect === 'object' && record.redirect.name)
            .filter((record) => !names.has(String(record.redirect.name)))
            .map((record) => `${String(record.name)} -> ${record.redirect.name}`)
        expect(missing).toEqual([])
    })

    it('resolves the group path of a module with a redirect to a screen under /iPanel', () => {
        for (const name of ['education', 'language', 'user', 'setting', 'place', 'cms-management']) {
            const record = records.find((r) => r.name === name)
            if (!record) continue
            const target = router.resolve(typeof record.redirect === 'object' ? record.redirect : { name: String(record.redirect) })
            expect(target.fullPath.startsWith('/iPanel') || target.fullPath.startsWith('/')).toBe(true)
            expect(target.matched.length).toBeGreaterThan(0)
        }
    })
})
