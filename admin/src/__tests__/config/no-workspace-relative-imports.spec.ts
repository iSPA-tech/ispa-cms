import { describe, expect, it } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'

/**
 * base-admin is built in two layouts: the workspace checkout (`base-admin/` beside `ispa-cms/`) and a release
 * (`admin/` beside `cms/`). A static import that climbs into `ispa-cms/` only resolves in the first one and broke the
 * cms.ispa.io release 105 build (2026-11-08). Core admin files are imported through the `@core-admin` alias
 * (vite.config.js / vitest.config.ts pick the layout); other modules are discovered by the globs in admin-modules.js.
 */
const SRC = resolve(__dirname, '../..')
const WORKSPACE_IMPORT = /(?:from\s*|import\s*\()\s*['"](?:\.\.\/)+ispa-cms\//
const EXTENSIONS = ['.js', '.ts', '.vue', '.mjs']

const walk = (dir: string, out: string[] = []): string[] => {
    for (const name of readdirSync(dir)) {
        const file = join(dir, name)
        if (statSync(file).isDirectory()) {
            if (name !== '__tests__') walk(file, out)
        } else if (EXTENSIONS.some((ext) => name.endsWith(ext))) {
            out.push(file)
        }
    }
    return out
}

describe('workspace-relative imports', () => {
    it('no src file imports from ../ispa-cms (use @core-admin or the module globs)', () => {
        const offenders = walk(SRC)
            .filter((file) => WORKSPACE_IMPORT.test(readFileSync(file, 'utf8')))
            .map((file) => relative(SRC, file))
        expect(offenders).toEqual([])
    })
})
