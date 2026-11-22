import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp } from 'vue'
import isMobilePlugin, { isMobile, MOBILE_QUERY } from '@/utils/isMobile.js'

// `$isMobile()` opens drawers full width on a phone-sized screen. It replaced the vue-mobile-detection plugin (npm
// package without a declared licence, 2026-11-22) and now reads the screen width instead of the user agent.

function screen(matches: boolean) {
    const matchMedia = vi.fn((query: string) => ({ matches, media: query }))
    vi.stubGlobal('matchMedia', matchMedia)
    return matchMedia
}

afterEach(() => vi.unstubAllGlobals())

describe('isMobile', () => {
    it('is true below 768px and false from 768px up', () => {
        const matchMedia = screen(true)
        expect(isMobile()).toBe(true)
        expect(matchMedia).toHaveBeenCalledWith(MOBILE_QUERY)
        expect(MOBILE_QUERY).toBe('(max-width: 767px)')

        screen(false)
        expect(isMobile()).toBe(false)
    })

    it('is false where the browser cannot answer', () => {
        vi.stubGlobal('matchMedia', undefined)
        expect(isMobile()).toBe(false)
    })

    it('gives every component $isMobile()', () => {
        screen(true)
        const app = createApp({})
        app.use(isMobilePlugin)
        expect(app.config.globalProperties.$isMobile()).toBe(true)
    })
})
