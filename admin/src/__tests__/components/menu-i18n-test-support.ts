import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const source = (name: string) =>
    resolve(
        dirname(fileURLToPath(import.meta.url)),
        '../../../../cms/Core/database/seeders/exported',
        name
    )
const rows = JSON.parse(
    execFileSync(
        'php',
        [
            '-r',
            'echo json_encode(array_merge(require $argv[1], require $argv[2]), JSON_THROW_ON_ERROR);',
            source('translates.php'),
            source('translates-admin-menu-editor.php'),
        ],
        { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 }
    )
)
export const menuMessages = { vi: {}, en: {} }
for (const row of rows) {
    if (!row.for_adm) continue
    const name = row.name.startsWith(`${row.group_name}.`)
        ? row.name
        : `${row.group_name}.${row.name}`
    for (const locale of ['vi', 'en']) {
        if (row.text?.[locale])
            menuMessages[locale][name] = row.text[locale].replace(
                /:([A-Za-z_][A-Za-z0-9_]*)/g,
                '{$1}'
            )
    }
}
export const createMenuTestI18n = (createI18n: any) =>
    createI18n({
        legacy: false,
        locale: 'vi',
        fallbackLocale: 'en',
        flatJson: true,
        messages: structuredClone(menuMessages),
        missing: (_locale: string, key: string) => key,
        missingWarn: false,
        fallbackWarn: false,
    }).global
