/**
 * Moved from tests/ after the admin module split: covers the Core admin agentic screens (agentic-p0-fixes),
 * imported from ispa-cms/cms/Core/admin by relative path.
 */
import { describe, expect, it } from 'vitest'
import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { useAgenticMeta } from '../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticMeta'
import AgenticSchedulesSection from '../../../../cms/Core/admin/pages/setting/agentic/components/AgenticSchedulesSection.vue'

// ── sl() composable tests ──────────────────────────────────────────

describe('useAgenticMeta:sl', () => {
    /**
     * Helper: mount a throwaway component that calls the composable
     * so that vue-i18n's useI18n() is invoked inside a setup context.
     */
    function getSl() {
        let slFn: ReturnType<typeof useAgenticMeta>['sl'] = () => ''

        const Wrapper = defineComponent({
            setup() {
                const { sl } = useAgenticMeta()
                slFn = sl
                return {}
            },
            template: '<div />',
        })

        mount(Wrapper)
        return slFn
    }

    it('translates a known status value via the i18n key', () => {
        const sl = getSl()
        // The mock t() returns the key string for keys starting with "text."
        expect(sl('running')).toBe('text.agentic.label.running')
    })

    it('returns the na key for null', () => {
        const sl = getSl()
        expect(sl(null)).toBe('text.agentic.na')
    })

    it('returns the na key for undefined', () => {
        const sl = getSl()
        expect(sl(undefined)).toBe('text.agentic.na')
    })

    it('returns the na key for empty string (falsy)', () => {
        const sl = getSl()
        expect(sl('')).toBe('text.agentic.na')
    })

    it('returns the label key for an unknown value (fallback is the raw value)', () => {
        const sl = getSl()
        // For any truthy string, sl() builds "text.agentic.label.<value>"
        // The mock returns the key itself since it starts with "text."
        expect(sl('some_unknown_status')).toBe('text.agentic.label.some_unknown_status')
    })
})

// ── AgenticSchedulesSection optional prop test ─────────────────────

const ATableStub = defineComponent({
    name: 'ATableStub',
    props: {
        columns: { type: Array, default: () => [] },
        dataSource: { type: Array, default: () => [] },
    },
    template: '<div />',
})

const AButtonStub = defineComponent({
    name: 'AButtonStub',
    props: { disabled: { type: Boolean, default: false } },
    emits: ['click'],
    template: '<button type="button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
})

describe('components:AgenticSchedulesSection optional onEditScheduleCadence', () => {
    it('mounts without crashing when onEditScheduleCadence is not provided', () => {
        const wrapper = mount(AgenticSchedulesSection, {
            props: {
                filters: { search: '', status: '', moduleScope: '', page: 1, perPage: 6 },
                draft: { name: '', cadenceMinutes: 60, showAdvanced: false },
                statusOptions: [],
                cadenceOptions: [],
                columns: [],
                schedules: [],
                meta: { total: 0 },
                runtimeStatusClass: () => '',
                formatMeta: () => '',
                onCreateSchedule: () => {},
                onToggleSchedule: () => {},
                onDispatchSchedule: () => {},
                onRunOnceSchedule: () => {},
                onResumeDispatchSchedule: () => {},
                onOpenRunDetail: () => {},
                // onEditScheduleCadence intentionally omitted
            },
            global: {
                stubs: {
                    'a-input': { template: '<input />' },
                    'a-select': { template: '<select />' },
                    'a-pagination': { template: '<div />' },
                    'a-table': ATableStub,
                    'a-button': AButtonStub,
                },
            },
        })

        expect(wrapper.exists()).toBe(true)
    })
})
