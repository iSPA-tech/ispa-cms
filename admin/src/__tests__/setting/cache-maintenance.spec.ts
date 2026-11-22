import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const page = readFileSync(resolve(__dirname, '../../../../cms/Core/admin/pages/setting/cache.vue'), 'utf8')
const api = readFileSync(resolve(root, 'libs/cache.js'), 'utf8')

describe('cache maintenance controls', () => {
    it('uses fixed backend actions behind confirmations', () => {
        expect(api).toContain("ajax.post('/runtime-cache/clear')")
        expect(api).toContain("ajax.post('/runtime-cache/optimize')")
        expect(page).toContain('Modal.confirm')
        expect(page).toContain('clearApplicationCache')
        expect(page).toContain('optimizeApplication')
        expect(page).toContain('text.cache.runtime.clear')
        expect(page).toContain('text.cache.runtime.optimize')
    })

    it('keeps independent loading states and explains sitemap preservation', () => {
        expect(page).toContain(":loading=\"runtimeAction === 'clear'\"")
        expect(page).toContain(":loading=\"runtimeAction === 'optimize'\"")
        expect(page).toContain('text.cache.runtime.intro')
    })
})
