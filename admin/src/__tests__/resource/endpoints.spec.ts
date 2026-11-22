import { describe, expect, it } from 'vitest'
import { resolvePath, toAjaxPath } from '@/utils/resource/endpoints'

describe('resolvePath', () => {
    it('replaces params with encoded record values', () => {
        expect(resolvePath('items/:uuid/lock', { uuid: 'a b/c' })).toBe('items/a%20b%2Fc/lock')
        expect(resolvePath('a/:x/b/:y', { x: 1, y: 'z' })).toBe('a/1/b/z')
    })

    it('leaves paths without params untouched', () => {
        expect(resolvePath('items', {})).toBe('items')
    })

    it('throws when a param is missing, null or empty', () => {
        expect(() => resolvePath('items/:uuid', {})).toThrow(Error)
        expect(() => resolvePath('items/:uuid', { uuid: null })).toThrow()
        expect(() => resolvePath('items/:uuid', { uuid: '' })).toThrow()
        expect(() => resolvePath('items/:uuid', undefined)).toThrow()
    })
})

describe('toAjaxPath', () => {
    it('strips the admin API prefix and leading slashes', () => {
        expect(toAjaxPath('/api/v2/admin/helpdesk/organizations')).toBe('helpdesk/organizations')
        expect(toAjaxPath('api/v2/admin/a/b')).toBe('a/b')
        expect(toAjaxPath('/other/x')).toBe('other/x')
        expect(toAjaxPath('helpdesk/x')).toBe('helpdesk/x')
    })
})
