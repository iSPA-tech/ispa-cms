/**
 * Moved from tests/ after the admin module split: covers the Core admin agentic screens (agentic-schedules-i18n),
 * imported from ispa-cms/cms/Core/admin by relative path.
 */
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import AgenticSchedulesSection from '../../../../cms/Core/admin/pages/setting/agentic/components/AgenticSchedulesSection.vue'

/**
 * Verify that AgenticSchedulesSection.vue contains no hardcoded
 * Vietnamese (or stray English) user-facing strings — every visible
 * label must go through `t()`.
 */
describe('AgenticSchedulesSection i18n', () => {
    const filePath = resolve(
        __dirname,
        '../../../../cms/Core/admin/pages/setting/agentic/components/AgenticSchedulesSection.vue',
    )
    const source = readFileSync(filePath, 'utf-8')

    // Extract only the <template> block for string scanning
    const templateMatch = source.match(/<template>([\s\S]*?)<\/template>/)
    const templateBlock = templateMatch ? templateMatch[1] : ''

    it('contains no hardcoded Vietnamese strings in the template', () => {
        // Common Vietnamese diacritics that would appear in hardcoded text
        const vietnamesePattern = /[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/i

        // Split template into lines, skip lines that are purely t() calls or HTML-only
        const lines = templateBlock.split('\n')
        const violatingLines: string[] = []

        for (const line of lines) {
            const trimmed = line.trim()
            // Skip empty, comment, and pure-HTML/directive lines
            if (!trimmed || trimmed.startsWith('<!--') || trimmed.startsWith('<') && !trimmed.includes('{{')) {
                continue
            }
            // Check for Vietnamese characters outside of t() calls
            if (vietnamesePattern.test(trimmed)) {
                // Make sure the Vietnamese text is not inside a t('...') call
                // Remove all t('...') content to see what remains
                const withoutTCalls = trimmed.replace(/t\(['"][^'"]*['"]\)/g, '')
                if (vietnamesePattern.test(withoutTCalls)) {
                    violatingLines.push(trimmed)
                }
            }
        }

        expect(violatingLines).toEqual([])
    })

    it('contains no hardcoded English label strings outside t() calls', () => {
        const knownHardcodedPatterns = [
            /['"]Execution flow:/,
            /['"]Goal override:/,
            /['"]Run #/,
            /['"]Status:/,
            /['"]At:/,
            /['"]Workflow:/,
            /\|\|\s*['"]global['"]/,
            /\|\|\s*['"]default['"]/,
            /\|\|\s*['"]never['"]/,
            /\|\|\s*['"]pending sync['"]/,
            /\|\|\s*['"]n\/a['"]/,
        ]

        for (const pattern of knownHardcodedPatterns) {
            expect(
                pattern.test(templateBlock),
                `Template should not contain hardcoded pattern: ${pattern}`,
            ).toBe(false)
        }
    })

    it('renders without crashing when given minimal props', () => {
        const wrapper = mount(AgenticSchedulesSection, {
            props: {
                filters: { search: '', status: null, moduleScope: '', page: 1, perPage: 15 },
                draft: {
                    name: '',
                    cadenceMinutes: null,
                    environment: '',
                    moduleScope: '',
                    targetType: '',
                    targetId: '',
                    goal: '',
                    showAdvanced: false,
                },
                statusOptions: [],
                columns: [],
                schedules: [],
                meta: { total: 0 },
                cadenceOptions: [],
                runtimeStatusClass: () => '',
                formatMeta: () => '',
                onCreateSchedule: () => {},
                onToggleSchedule: () => {},
                onDispatchSchedule: () => {},
                onRunOnceSchedule: () => {},
                onResumeDispatchSchedule: () => {},
                onOpenRunDetail: () => {},
            },
            shallow: true,
        })

        expect(wrapper.exists()).toBe(true)
        // The component should render key t() keys as text (mock returns the key string)
        expect(wrapper.text()).toContain('text.agentic.schedules.title')
    })
})
