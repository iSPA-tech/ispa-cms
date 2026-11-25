import { describe, expect, it } from 'vitest'
import fs from 'fs'
import path from 'path'
import viteConfig from '../../../vite.config.js'
import sharedPackages from '../../config/shared-packages.json'

/**
 * Module admin code (cms/<Module>/admin) is built from outside base-admin, so a bare package import resolves only
 * through the shared package aliases (src/config/shared-packages.json). Anything else builds in a local checkout but
 * fails on a release build ("Rolldown failed to resolve import"), as `@vue-flow/background` did on 2026-11-24.
 */
const root = path.resolve(__dirname, '../../..')
const cmsRoot = [path.resolve(root, '../cms'), path.resolve(root, '../cms')].find((dir) => fs.existsSync(dir))

/**
 * Imports that are wrong today but sit in files nothing in the bundle imports (they would fail the release build the
 * moment a route loads them). Remove an entry when the file is fixed; never add one for new code.
 */
const knownOffenders = new Set([
    'GeoPlaces/admin/pages/place/report-item.vue: vuex',
    'GeoPlaces/admin/pages/place/report-place.vue: vuex',
    'GeoPlaces/admin/pages/report/place.vue: vuex',
])

/** Vite aliases that do not point into node_modules (for example `@shared-data-chart-css`). */
function shellAliases(): string[] {
    const alias = (viteConfig as { resolve: { alias: Record<string, string> } }).resolve.alias
    const nodeModules = path.join(root, 'node_modules') + path.sep

    return Object.entries(alias)
        .filter(([, target]) => typeof target === 'string' && !target.startsWith(nodeModules))
        .map(([name]) => name)
}

/** Package name of a bare import specifier, or null for relative paths and admin aliases. */
function packageOf(specifier: string): string | null {
    if (/^(\.|\/|@\/|@core-admin\/|~|virtual:)/.test(specifier)) {
        return null
    }
    const parts = specifier.split('/')

    return specifier.startsWith('@') ? parts.slice(0, 2).join('/') : parts[0]
}

function sourceFiles(dir: string): string[] {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name)
        if (entry.isDirectory()) {
            return entry.name === 'node_modules' || entry.name === '__tests__' ? [] : sourceFiles(full)
        }

        return /\.(vue|js|ts)$/.test(entry.name) ? [full] : []
    })
}

describe.runIf(cmsRoot)('module admin imports', () => {
    it('imports only shared packages', () => {
        const shared = new Set([...Object.keys(sharedPackages.packages), ...shellAliases()])
        const offenders: string[] = []
        for (const module of fs.readdirSync(cmsRoot as string)) {
            const adminDir = path.join(cmsRoot as string, module, 'admin')
            if (!fs.existsSync(adminDir)) {
                continue
            }
            for (const file of sourceFiles(adminDir)) {
                const source = fs.readFileSync(file, 'utf8')
                // Import statements (`import x from 'p'`, `import 'p'`, `export … from 'p'`) and dynamic `import('p')`.
                const statements = /^\s*(?:import|export)\s(?:[^'";]*?\sfrom\s*)?['"]([^'"]+)['"]|\bimport\(\s*['"]([^'"]+)['"]\s*\)/gm
                for (const match of source.matchAll(statements)) {
                    const name = packageOf(match[1] ?? match[2])
                    const entry = `${path.relative(cmsRoot as string, file)}: ${match[1] ?? match[2]}`
                    if (name !== null && !shared.has(name) && !shared.has(match[1] ?? match[2]) && !knownOffenders.has(entry)) {
                        offenders.push(entry)
                    }
                }
            }
        }

        expect(offenders).toEqual([])
    })
})
