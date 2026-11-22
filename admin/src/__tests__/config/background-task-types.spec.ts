/**
 * Background task type labels spec.
 *
 * Covers the `adminBackgroundTaskTypes` registry that the background task bar uses to turn a raw task type into a
 * readable label. Config package job types (`config.package.*`) must each map to a translation key owned by the core
 * module, and the sidecar translation source must define those keys.
 */
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

import { adminBackgroundTaskTypes } from '@/config/admin-modules.js'

const SIDECAR_PATH = resolve(__dirname, '../../../../cms/Core/database/seeders/sql/translate-admin-authored.php')

const CONFIG_PACKAGE_TYPES: Array<{ type: string; text: string }> = [
    { type: 'config.package.export', text: 'text.background_tasks.type.config_package_export' },
    { type: 'config.package.dry_run', text: 'text.background_tasks.type.config_package_dry_run' },
    { type: 'config.package.apply', text: 'text.background_tasks.type.config_package_apply' },
]

type TaskType = { type: string; text: string; moduleKey: string }
const entries = adminBackgroundTaskTypes as TaskType[]

describe('adminBackgroundTaskTypes', () => {
    it.each(CONFIG_PACKAGE_TYPES)('maps $type exactly once to $text owned by core', ({ type, text }) => {
        const matches = entries.filter((entry) => entry.type === type)

        expect(matches).toHaveLength(1)
        expect(matches[0].text).toBe(text)
        expect(matches[0].moduleKey).toBe('core')
    })

    it('keeps the core backup and module install labels', () => {
        const backup = entries.find((entry) => entry.type === 'core.backup')
        const install = entries.find((entry) => entry.type === 'core.module_install')

        expect(backup?.text).toBe('text.background_tasks.type.core_backup')
        expect(install?.text).toBe('text.background_tasks.type.core_module_install')
    })

    it('every entry has a translation key as text, never the raw type', () => {
        for (const entry of entries) {
            expect(entry.text.startsWith('text.')).toBe(true)
            expect(entry.text).not.toBe(entry.type)
        }
    })

    it('the sidecar translation source defines the config package label keys', () => {
        if (!existsSync(SIDECAR_PATH)) {
            return
        }

        const source = readFileSync(SIDECAR_PATH, 'utf8')

        for (const { text } of CONFIG_PACKAGE_TYPES) {
            expect(source).toContain(`'${text}'`)
        }
    })
})
