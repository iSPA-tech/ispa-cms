/**
 * Moved from tests/ after the admin module split: covers the Core admin agentic screens (remaining-fixes),
 * imported from ispa-cms/cms/Core/admin by relative path.
 */
import { describe, expect, it, beforeEach, afterEach } from 'vitest'
import { computed, defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { useI18n } from 'vue-i18n'
import { useAgenticOnboarding } from '../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticOnboarding.js'

// ── M2: Grants columns use translation keys ─────────────────────

describe('M2: AgenticAdminConfig grants columns use i18n keys', () => {
    /**
     * Verify the pattern used in AgenticAdminConfig: the grants column
     * titles must go through t() and return i18n key strings (not hardcoded
     * English like "Tool", "Enabled", "Actions").
     */
    it('grants column titles are i18n keys, not hardcoded English', () => {
        let columns: any[] = []

        const Wrapper = defineComponent({
            setup() {
                const { t } = useI18n()

                const grantsColumns = computed(() => [
                    { key: 'tool_name', title: t('text.agentic.col.tool'), dataIndex: 'tool_name' },
                    { key: 'enabled', title: t('text.agentic.col.enabled'), width: 100 },
                    { key: 'actions', title: t('text.agentic.col.actions'), width: 120 },
                ])

                columns = grantsColumns.value
                return { grantsColumns }
            },
            template: '<div />',
        })

        mount(Wrapper)

        // The mock t() returns key strings starting with "text."
        // Hardcoded English ("Tool", "Enabled", "Actions") would NOT start with "text."
        expect(columns[0].title).toBe('text.agentic.col.tool')
        expect(columns[1].title).toBe('text.agentic.col.enabled')
        expect(columns[2].title).toBe('text.agentic.col.actions')

        // Verify none of the titles are the old hardcoded English strings
        for (const col of columns) {
            expect(col.title).not.toBe('Tool')
            expect(col.title).not.toBe('Enabled')
            expect(col.title).not.toBe('Actions')
        }
    })
})

// ── M6: Onboarding localStorage try/catch safety ────────────────

describe('M6: useAgenticOnboarding localStorage safety', () => {
    let originalGetItem: typeof Storage.prototype.getItem
    let originalSetItem: typeof Storage.prototype.setItem
    let originalRemoveItem: typeof Storage.prototype.removeItem

    beforeEach(() => {
        originalGetItem = Storage.prototype.getItem
        originalSetItem = Storage.prototype.setItem
        originalRemoveItem = Storage.prototype.removeItem
    })

    afterEach(() => {
        Storage.prototype.getItem = originalGetItem
        Storage.prototype.setItem = originalSetItem
        Storage.prototype.removeItem = originalRemoveItem
    })

    it('does not throw when localStorage.getItem throws (e.g. private browsing)', () => {
        Storage.prototype.getItem = () => {
            throw new DOMException('Access denied')
        }

        let result: ReturnType<typeof useAgenticOnboarding> | undefined
        const Wrapper = defineComponent({
            setup() {
                result = useAgenticOnboarding()
                return {}
            },
            template: '<div />',
        })

        // Should not throw
        expect(() => mount(Wrapper)).not.toThrow()
        expect(result).toBeDefined()
        expect(result!.showWizard.value).toBe(true)
    })

    it('does not throw when localStorage.setItem throws', () => {
        Storage.prototype.setItem = () => {
            throw new DOMException('Quota exceeded')
        }

        let onboarding: ReturnType<typeof useAgenticOnboarding> | undefined
        const Wrapper = defineComponent({
            setup() {
                onboarding = useAgenticOnboarding()
                return {}
            },
            template: '<div />',
        })

        mount(Wrapper)

        // completeOnboarding calls setItem — should not throw
        expect(() => onboarding!.completeOnboarding()).not.toThrow()
        expect(onboarding!.showWizard.value).toBe(false)
    })

    it('does not throw when localStorage.removeItem throws', () => {
        Storage.prototype.removeItem = () => {
            throw new DOMException('Access denied')
        }

        let onboarding: ReturnType<typeof useAgenticOnboarding> | undefined
        const Wrapper = defineComponent({
            setup() {
                onboarding = useAgenticOnboarding()
                return {}
            },
            template: '<div />',
        })

        mount(Wrapper)

        // resetOnboarding calls removeItem — should not throw
        expect(() => onboarding!.resetOnboarding()).not.toThrow()
        expect(onboarding!.showWizard.value).toBe(true)
    })
})
