import { readFileSync, readdirSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * `src/config/admin-modules.js` loads every `cms/<Module>/admin/module.js` eagerly, before the i18n plugin exists and
 * inside every spec that touches navigation. A bundle that imports `@/config/i18n` (or `@/config`, which re-exports it)
 * creates the i18n instance at import time and breaks those specs ("No createI18n export is defined on the vue-i18n
 * mock", 2026-11-09, AcmeContentPipeline). Bundles export translation keys; the shell translates them.
 */
const cmsRoot = resolve(__dirname, '../../../../cms')
const bundles = existsSync(cmsRoot)
    ? readdirSync(cmsRoot)
          .map((name) => resolve(cmsRoot, name, 'admin/module.js'))
          .filter((file) => existsSync(file))
    : []

describe('module bundles stay free of eager i18n imports', () => {
    it('finds the workspace module bundles', () => {
        expect(bundles.length).toBeGreaterThan(0)
    })

    it.each(bundles)('%s does not import @/config/i18n or @/config at load time', (file) => {
        const source = readFileSync(file, 'utf8')
        expect(source).not.toMatch(/from\s+['"]@\/config\/i18n['"]/)
        expect(source).not.toMatch(/from\s+['"]@\/config['"]/)
    })
})
