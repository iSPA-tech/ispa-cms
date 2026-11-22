import { describe, it, expect, vi } from 'vitest'

import { numberToString, getVariantName, getDefaultLanguageId } from '@/mixins/utils'

describe('utils:getDefaultLanguageId', () => {
    it('returns matching language id by cookie locale', async () => {
        const cfg = await import('@/config')
        ;(cfg.cookie.get as unknown as any).mockReturnValue('vi')
        const languages = [
            { id: 1, path: 'en' },
            { id: 2, path: 'vi' },
        ]
        expect(getDefaultLanguageId(languages)).toBe(2)
    })

    it('returns null when no language matches', async () => {
        const cfg = await import('@/config')
        ;(cfg.cookie.get as unknown as any).mockReturnValue('fr')
        const languages = [
            { id: 1, path: 'en' },
            { id: 2, path: 'vi' },
        ]
        expect(getDefaultLanguageId(languages)).toBeNull()
    })
})

describe('utils:numberToString', () => {
    it('returns raw number under 1000', () => {
        expect(numberToString(999)).toBe(999)
    })

    it('formats thousands with k', () => {
        expect(numberToString(1000)).toBe('1k')
        expect(numberToString(1500)).toBe('1.5k')
    })

    it('formats millions with m', () => {
        expect(numberToString(1_000_000)).toBe('1m')
        expect(numberToString(1_234_567, 2)).toBe('1.23m')
    })

    it('formats billions with b', () => {
        expect(numberToString(1_000_000_000)).toBe('1b')
    })
})

describe('utils:getVariantName', () => {
    it('builds variant string from options', () => {
        expect(
            getVariantName({ sku: 'SKU123', first_option: 'Red', second_option: 'XL' }),
        ).toBe('SKU123 - Red - XL')
    })

    it('handles missing options', () => {
        expect(getVariantName({ sku: 'A1' })).toBe('A1')
        expect(getVariantName({ sku: 'A1', first_option: 'Blue' })).toBe('A1 - Blue')
    })
})
