/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// isModuleLiveEnabled: the check for code that calls a module's API. The copy of the module list kept in the browser
// can still say "on" after the module was turned off; only the server's answer in this page load counts.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CACHE_KEY_ACTIVE_MODULES } from '@/config/constants'

const getModules = vi.hoisted(() => vi.fn())
vi.mock('@/libs', () => ({ getModules }))

const loadHook = async () => {
    vi.resetModules()
    return await import('@/hooks/useActiveModules')
}

beforeEach(() => {
    getModules.mockReset()
    // A stored copy from an earlier visit, when the Widget module was on.
    localStorage.setItem(CACHE_KEY_ACTIVE_MODULES, JSON.stringify(['Core', 'Widget']))
    localStorage.setItem(`${CACHE_KEY_ACTIVE_MODULES}-status`, JSON.stringify({ core: true, widget: true }))
    localStorage.setItem(`${CACHE_KEY_ACTIVE_MODULES}-loaded`, 'true')
})

describe('isModuleLiveEnabled', () => {
    it('ignores the stored copy until the server has answered, then follows the server', async () => {
        const hook = await loadHook()
        // The stored copy alone satisfies the older checks: that is the stale answer.
        expect(hook.isModuleExtensionAllowed('Widget')).toBe(true)
        expect(hook.isModuleLiveEnabled('Widget')).toBe(false)

        getModules.mockResolvedValue([
            { name: 'Core', alias: 'core', enabled: true },
            { name: 'Widget', alias: 'widget', enabled: false },
        ])
        await hook.fetchAndCacheActiveModules()
        expect(hook.isModuleLiveEnabled('Widget')).toBe(false)
        expect(hook.isModuleLiveEnabled('Core')).toBe(true)
    })

    it('turns true once the server says the module is on, and stays false when the read fails', async () => {
        const failing = await loadHook()
        getModules.mockRejectedValue(new Error('down'))
        vi.spyOn(console, 'error').mockImplementation(() => {})
        await failing.fetchAndCacheActiveModules()
        expect(failing.isModuleLiveEnabled('Widget')).toBe(false)

        const hook = await loadHook()
        getModules.mockResolvedValue([{ name: 'Widget', alias: 'widget', enabled: true }])
        await hook.fetchAndCacheActiveModules()
        expect(hook.isModuleLiveEnabled('Widget')).toBe(true)
    })
})
