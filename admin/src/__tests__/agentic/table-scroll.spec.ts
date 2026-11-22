import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, reactive } from 'vue'
import AgenticRunsSection from '../../../../cms/Core/admin/pages/setting/agentic/components/AgenticRunsSection.vue'
import AgenticSchedulesSection from '../../../../cms/Core/admin/pages/setting/agentic/components/AgenticSchedulesSection.vue'

// Stub all Ant Design Vue components used in templates
const stubComponent = (name: string, extraProps: string[] = []) => defineComponent({
    name,
    props: [...extraProps],
    inheritAttrs: true,
    setup(_, { slots, attrs }) {
        return () => h('div', { 'data-stub': name, ...attrs }, slots.default?.())
    },
})

const ATableStub = defineComponent({
    name: 'a-table',
    props: {
        columns: Array,
        dataSource: Array,
        loading: Boolean,
        pagination: [Object, Boolean],
        scroll: Object,
        rowKey: String,
        size: String,
    },
    inheritAttrs: true,
    setup(_, { slots }) {
        return () => h('div', { 'data-stub': 'a-table' }, slots.default?.())
    },
})

const globalStubs = {
    'a-table': ATableStub,
    'a-input': stubComponent('a-input', ['value']),
    'a-select': stubComponent('a-select', ['value']),
    'a-button': stubComponent('a-button'),
    'a-pagination': stubComponent('a-pagination'),
    'a-switch': stubComponent('a-switch'),
    'a-popconfirm': stubComponent('a-popconfirm'),
    'a-tabs': stubComponent('a-tabs'),
    'a-tab-pane': stubComponent('a-tab-pane'),
}

// Mock the useAgenticMeta composable
vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticMeta', () => ({
    useAgenticMeta: () => ({
        sl: (v: string) => v || 'n/a',
        moduleLabel: (v: string) => v || 'unknown',
    }),
}))

const noop = () => {}

describe('H1: a-table horizontal scroll props', () => {
    it('AgenticRunsSection renders a-table with :scroll="{ x: 980 }"', () => {
        const wrapper = mount(AgenticRunsSection, {
            props: {
                filters: reactive({ search: '', status: undefined, moduleScope: undefined, page: 1, perPage: 6 }),
                statusOptions: [],
                columns: [
                    { key: 'goal', title: 'Run', width: 240 },
                    { key: 'status', title: 'Status', width: 120 },
                    { key: 'workflow', title: 'Workflow', width: 160 },
                    { key: 'latest_step', title: 'Latest step' },
                    { key: 'inspect', title: 'Actions', width: 210 },
                ],
                runs: [],
                meta: {},
                canOperate: false,
                isLoading: false,
                isFetching: false,
                isStopPending: false,
                isCancelPending: false,
                isRerunPending: false,
                activeRunId: null,
                runActionMode: null,
                runtimeStatusClass: noop,
                formatMeta: () => '',
                onOpenRunDetail: noop,
                onStopRun: noop,
                onCancelRun: noop,
                onRerunRun: noop,
            },
            global: { stubs: globalStubs },
        })

        const tableVm = wrapper.findComponent({ name: 'a-table' })
        expect(tableVm.exists()).toBe(true)
        expect((tableVm.props() as any).scroll).toEqual({ x: 980 })
    })

    it('AgenticSchedulesSection renders a-table with :scroll="{ x: 880 }"', () => {
        const wrapper = mount(AgenticSchedulesSection, {
            props: {
                filters: reactive({ search: '', status: undefined, moduleScope: undefined, page: 1, perPage: 6 }),
                draft: reactive({ name: '', cadenceMinutes: 1440, showAdvanced: false, environment: '', moduleScope: '', goal: '', targetType: '', targetId: '' }),
                statusOptions: [],
                columns: [
                    { key: 'summary', title: 'Schedule', width: 250 },
                    { key: 'cadence', title: 'Cadence', width: 140 },
                    { key: 'last_run', title: 'Last run' },
                    { key: 'actions', title: 'Actions', width: 240 },
                ],
                schedules: [],
                meta: {},
                selectedProfile: null,
                selectedDefinition: null,
                selectedProfileCapabilityMeta: null,
                canCreateSchedule: false,
                scheduleEligibilityMessage: '',
                scheduleEligibilityTone: 'warn',
                defaultScheduleName: '',
                cadenceOptions: [],
                canOperate: false,
                canProvision: false,
                isCreatePending: false,
                isLoading: false,
                isFetching: false,
                isDispatchPending: false,
                isUpdatePending: false,
                activeScheduleId: null,
                scheduleActionMode: null,
                runtimeStatusClass: noop,
                formatMeta: () => '',
                onCreateSchedule: noop,
                onToggleSchedule: noop,
                onDispatchSchedule: noop,
                onRunOnceSchedule: noop,
                onResumeDispatchSchedule: noop,
                onEditScheduleCadence: noop,
                onOpenRunDetail: noop,
            },
            global: { stubs: globalStubs },
        })

        const tableVm = wrapper.findComponent({ name: 'a-table' })
        expect(tableVm.exists()).toBe(true)
        expect((tableVm.props() as any).scroll).toEqual({ x: 880 })
    })
})

describe('L14: search input debounce', () => {
    it('AgenticRunsSection does not update filters.search immediately on input', async () => {
        vi.useFakeTimers()

        const filters = reactive({ search: '', status: undefined, moduleScope: undefined, page: 1, perPage: 6 })

        const wrapper = mount(AgenticRunsSection, {
            props: {
                filters,
                statusOptions: [],
                columns: [],
                runs: [],
                meta: {},
                canOperate: false,
                isLoading: false,
                isFetching: false,
                isStopPending: false,
                isCancelPending: false,
                isRerunPending: false,
                activeRunId: null,
                runActionMode: null,
                runtimeStatusClass: noop,
                formatMeta: () => '',
                onOpenRunDetail: noop,
                onStopRun: noop,
                onCancelRun: noop,
                onRerunRun: noop,
            },
            global: { stubs: globalStubs },
        })

        // Find the search input (first a-input)
        const searchInput = wrapper.findComponent({ name: 'a-input' })
        expect(searchInput.exists()).toBe(true)

        // Simulate typing by updating the v-model value
        await searchInput.vm.$emit('update:value', 'test query')
        await wrapper.vm.$nextTick()

        // filters.search should NOT be updated yet (debounce pending)
        expect(filters.search).toBe('')

        // Advance past the 400ms debounce
        vi.advanceTimersByTime(400)
        await wrapper.vm.$nextTick()

        // Now it should be synced
        expect(filters.search).toBe('test query')

        vi.useRealTimers()
    })

    it('AgenticSchedulesSection does not update filters.search immediately on input', async () => {
        vi.useFakeTimers()

        const filters = reactive({ search: '', status: undefined, moduleScope: undefined, page: 1, perPage: 6 })

        const wrapper = mount(AgenticSchedulesSection, {
            props: {
                filters,
                draft: reactive({ name: '', cadenceMinutes: 1440, showAdvanced: false, environment: '', moduleScope: '', goal: '', targetType: '', targetId: '' }),
                statusOptions: [],
                columns: [],
                schedules: [],
                meta: {},
                selectedProfile: null,
                selectedDefinition: null,
                selectedProfileCapabilityMeta: null,
                canCreateSchedule: false,
                scheduleEligibilityMessage: '',
                scheduleEligibilityTone: 'warn',
                defaultScheduleName: '',
                cadenceOptions: [],
                canOperate: false,
                canProvision: false,
                isCreatePending: false,
                isLoading: false,
                isFetching: false,
                isDispatchPending: false,
                isUpdatePending: false,
                activeScheduleId: null,
                scheduleActionMode: null,
                runtimeStatusClass: noop,
                formatMeta: () => '',
                onCreateSchedule: noop,
                onToggleSchedule: noop,
                onDispatchSchedule: noop,
                onRunOnceSchedule: noop,
                onResumeDispatchSchedule: noop,
                onEditScheduleCadence: noop,
                onOpenRunDetail: noop,
            },
            global: { stubs: globalStubs },
        })

        const searchInput = wrapper.findComponent({ name: 'a-input' })
        expect(searchInput.exists()).toBe(true)

        await searchInput.vm.$emit('update:value', 'schedule search')
        await wrapper.vm.$nextTick()

        // Should not be updated yet
        expect(filters.search).toBe('')

        vi.advanceTimersByTime(400)
        await wrapper.vm.$nextTick()

        expect(filters.search).toBe('schedule search')

        vi.useRealTimers()
    })
})
