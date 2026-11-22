import { describe, expect, it } from 'vitest'
import { existsSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'

/**
 * Exit criterion of the module split of the admin: `src/pages` holds only shell pages. Module screens live in
 * `cms/<Module>/admin/pages` and are composed by the globs in `src/config/admin-modules.js`.
 * The only other exception is the Game area (`game-config/`, decision D-13), documented separately.
 * Dotfiles (.DS_Store) and empty directories are ignored.
 */
const PAGES = resolve(__dirname, '../../pages')

/** Whole directories that are shell-owned. */
const ALLOWED_DIRS = [
    'auth/', // sign-in, forgot password
    'account/', // the signed-in user's own account screens
    'my/', // personal shell area
    'game-config/', // Game exception (D-13), documented separately
]

/** Single files kept on purpose. */
const ALLOWED_FILES = [
    'dashboard.vue', // shell dashboard
    'my-tasks.vue', // shell route `my-tasks` with the header badge
    'notifications.vue', // shell route `notifications` ("View all" from the header bell)
    'category/manage.vue', // shared category manager, registered globally as <category-manage>
    'category/tag.vue', // shared tag manager, registered globally as <tag-manage>
    'category/category-tree.js', // helper of the shared category manager
    'report/report.vue', // loaded by both Content and GeoPlaces page-loaders
    'setting/setting.vue', // setting group layout loaded by config/router/setting.js
    'setting/setting-hub.vue', // setting hub
    'setting/setting-category-layout.vue', // setting category layout
    'setting/setting-items.vue', // setting items list
    'setting/setting-item.vue', // setting item editor
    'setting/menu-editor-state.js', // imported by shell menu-editor.vue and menu-item-details.vue
]

const walk = (dir: string, out: string[] = []): string[] => {
    for (const name of readdirSync(dir)) {
        if (name.startsWith('.')) continue
        const file = join(dir, name)
        if (statSync(file).isDirectory()) walk(file, out)
        else out.push(relative(PAGES, file).split('\\').join('/'))
    }
    return out
}

describe('shell pages only', () => {
    it('src/pages contains only allow-listed shell files', () => {
        const offenders = walk(PAGES).filter(
            (file) => !ALLOWED_FILES.includes(file) && !ALLOWED_DIRS.some((dir) => file.startsWith(dir)),
        )
        expect(
            offenders,
            `Not shell pages: ${offenders.join(', ')}. Move module screens into cms/<Module>/admin/pages.`,
        ).toEqual([])
    })

    it('every allow-listed file still exists', () => {
        const missing = ALLOWED_FILES.filter((file) => !existsSync(join(PAGES, file)))
        expect(missing, `Remove stale allow-list entries: ${missing.join(', ')}`).toEqual([])
    })
})
