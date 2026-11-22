import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

import mainNavConfig from '@/config/navigation.js'

describe('Sidebar item identity', () => {
    it('every top-level item has its own name', () => {
        const names = (mainNavConfig as Array<{ name: string }>).map((nav) => nav.name)

        expect(names.every((name) => typeof name === 'string' && name !== '')).toBe(true)
        expect(new Set(names).size).toBe(names.length)
    })

    // The loop keys decide which component instance (and so which nav-badge timer) belongs to which item when the list changes
    // as the module catalog loads; an index key would hand a badge to a different item.
    it('the sidebar loop keys items by name, not by position', () => {
        const source = readFileSync(resolve(__dirname, '../../App.vue'), 'utf8')

        expect(source).toMatch(/<template v-for="nav in mainNav" :key="nav\.name">/)
        expect(source).not.toMatch(/v-for="\(nav, ind\) in mainNav"/)
    })
})
