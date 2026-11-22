import { describe, expect, it } from 'vitest'
import fs from 'fs'
import path from 'path'
import viteConfig from '../../../vite.config.js'
import sharedPackages from '../../config/shared-packages.json'

/**
 * The shared admin package list (src/config/shared-packages.json) is the one source for the admin build aliases,
 * the module package builder (cms/Core PackageBuilder) and the installer. These checks keep the list, the installed
 * dependencies and the vite aliases in agreement, and pin the alias map so the admin bundle output stays the same.
 */
const root = path.resolve(__dirname, '../../..')
const nodeModules = path.join(root, 'node_modules')
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'))
const declared: Record<string, string> = { ...manifest.devDependencies, ...manifest.dependencies }

/** Path aliases that point into node_modules but are not shared packages (dead legacy alias, nothing installed). */
const legacyNodeModuleAliases: Record<string, string> = { icons: 'vue-material-design-icons' }

/**
 * The node_modules alias map as it was before the list existed (, 2026-11-12), plus `html-to-image` (widget hub
 * distribution Task 6, 2026-11-21: the Core design editor captures design pictures with it).
 */
const aliasMapBefore: Record<string, string> = {
    '@ant-design/icons-vue': '@ant-design/icons-vue',
    '@vueuse/core': '@vueuse/core',
    axios: 'axios',
    'lodash-es': 'lodash-es',
    vuedraggable: 'vuedraggable',
    '@tanstack/vue-query': '@tanstack/vue-query',
    '@vue-flow/core': '@vue-flow/core',
    'ant-design-vue': 'ant-design-vue',
    dayjs: 'dayjs',
    'html-to-image': 'html-to-image',
    numeral: 'numeral',
    clsx: 'clsx',
    icons: 'vue-material-design-icons',
    vue: 'vue',
    'vue-cal': 'vue-cal',
    'vue-i18n': 'vue-i18n',
    'vue-router': 'vue-router',
}

/** Aliases of the vite config that resolve into base-admin's node_modules, as name => package folder. */
function nodeModuleAliases(): Record<string, string> {
    const alias = (viteConfig as { resolve: { alias: Record<string, string> } }).resolve.alias
    const prefix = nodeModules + path.sep

    return Object.fromEntries(
        Object.entries(alias)
            .filter(([, target]) => target.startsWith(prefix))
            .map(([name, target]) => [name, target.slice(prefix.length).split(path.sep).join('/')])
    )
}

describe('shared admin packages', () => {
    const packages = sharedPackages.packages as Record<string, { major: number; used_by: string[] }>

    it('uses format 1 and npm package names', () => {
        expect(sharedPackages.format).toBe(1)
        expect(Object.keys(packages).length).toBeGreaterThan(0)
        for (const name of Object.keys(packages)) {
            expect(name).toMatch(/^(?:@[a-z0-9][a-z0-9._~-]*\/)?[a-z0-9][a-z0-9._~-]*$/)
        }
    })

    it('lists only installed dependencies with their stated major version', () => {
        for (const [name, entry] of Object.entries(packages)) {
            expect(declared[name], `${name} in package.json`).toBeTypeOf('string')
            expect(Number.isInteger(entry.major), `${name} major`).toBe(true)
            expect(Number(String(declared[name]).replace(/^[^\d]*/, '').split('.')[0]), `${name} range`).toBe(entry.major)
            const installed = JSON.parse(fs.readFileSync(path.join(nodeModules, name, 'package.json'), 'utf8'))
            expect(Number(String(installed.version).split('.')[0]), `${name} installed`).toBe(entry.major)
            expect(Array.isArray(entry.used_by)).toBe(true)
        }
    })

    it('builds every node_modules alias of the admin shell from the list', () => {
        const aliases = nodeModuleAliases()
        const shared = Object.fromEntries(Object.entries(aliases).filter(([name]) => !(name in legacyNodeModuleAliases)))

        expect(Object.keys(shared).sort()).toEqual(Object.keys(packages).sort())
        for (const [name, target] of Object.entries(shared)) {
            expect(target).toBe(name)
        }
    })

    it('keeps the alias map of the admin bundle unchanged', () => {
        const aliases = nodeModuleAliases()
        const sorted = (map: Record<string, string>) => Object.fromEntries(Object.entries(map).sort(([a], [b]) => a.localeCompare(b)))

        expect(sorted(aliases)).toEqual(sorted(aliasMapBefore))
    })
})
