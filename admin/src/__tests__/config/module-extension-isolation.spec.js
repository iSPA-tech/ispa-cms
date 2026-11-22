import { beforeEach, describe, expect, it } from 'vitest'

import { CACHE_KEY_ACTIVE_MODULES } from '@/config/constants'

/**
 * This CMS core is shared across many sites, so
 * UI a module OWNS — the Acme post-editor sections, its comparison block —
 * must never render on a deployment without that module. Build-time
 * `import.meta.glob` already keeps the code out of foreign bundles; these
 * tests cover the runtime gate that has to hold when the module IS bundled.
 */

const loadHook = async ({ modules = null, loaded = false } = {}) => {
    localStorage.clear()
    if (modules) {
        localStorage.setItem(`${CACHE_KEY_ACTIVE_MODULES}-status`, JSON.stringify(modules))
    }
    localStorage.setItem(`${CACHE_KEY_ACTIVE_MODULES}-loaded`, JSON.stringify(loaded))

    return import(`@/hooks/useActiveModules?bust=${Math.random()}`)
}

describe('module extension isolation', () => {
    beforeEach(() => {
        localStorage.clear()
    })

    it('hides module-owned UI until the module catalog has loaded', async () => {
        const { isModuleExtensionAllowed, isModuleActive } = await loadHook({ loaded: false })

        // isModuleActive stays fail-open for optional UI…
        expect(isModuleActive('AcmeContentPipeline')).toBe(true)
        // …but a module's own panels must not appear on an unknown catalog.
        expect(isModuleExtensionAllowed('AcmeContentPipeline')).toBe(false)
    })

    it('hides module-owned UI on deployments without that module', async () => {
        const { isModuleExtensionAllowed } = await loadHook({
            modules: { content: true, core: true },
            loaded: true,
        })

        expect(isModuleExtensionAllowed('AcmeContentPipeline')).toBe(false)
    })

    it('hides module-owned UI when the module is installed but disabled', async () => {
        const { isModuleExtensionAllowed } = await loadHook({
            modules: { acmecontentpipeline: false },
            loaded: true,
        })

        expect(isModuleExtensionAllowed('AcmeContentPipeline')).toBe(false)
    })

    it('shows module-owned UI only where the module is active', async () => {
        const { isModuleExtensionAllowed } = await loadHook({
            modules: { acmecontentpipeline: true },
            loaded: true,
        })

        expect(isModuleExtensionAllowed('AcmeContentPipeline')).toBe(true)
        expect(isModuleExtensionAllowed('Education')).toBe(false)
    })

    it('keeps the post editor and content block surfaces on the strict check', async () => {
        const fs = await import('node:fs')
        const path = await import('node:path')
        const root = path.resolve(__dirname, '../..')

        for (const file of [
            '../../cms/Content/admin/pages/create.vue',
            '../../cms/Content/admin/pages/update.vue',
            'components/i-contents.vue',
        ]) {
            const source = fs.readFileSync(path.join(root, file), 'utf8')
            expect(source).toContain('isModuleExtensionAllowed(extension.module)')
            expect(source).not.toContain('isModuleActive(extension.module)')
        }
    })
})
