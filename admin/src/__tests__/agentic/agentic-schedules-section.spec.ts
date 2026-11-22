/**
 * Moved from tests/ after the admin module split: covers the Core admin agentic screens (agentic-schedules-section),
 * imported from ispa-cms/cms/Core/admin by relative path.
 */
import { describe, expect, it } from 'vitest'
import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import AgenticSchedulesSection from '../../../../cms/Core/admin/pages/setting/agentic/components/AgenticSchedulesSection.vue'

const ATableStub = defineComponent({
    name: 'ATableStub',
    props: {
        columns: {
            type: Array,
            default: () => [],
        },
        dataSource: {
            type: Array,
            default: () => [],
        },
    },
    template: `
        <div>
            <template v-for="record in dataSource" :key="record.id">
                <template v-for="column in columns" :key="column.key || column.dataIndex">
                    <slot name="bodyCell" :column="column" :record="record" />
                </template>
            </template>
        </div>
    `,
})

const AButtonStub = defineComponent({
    name: 'AButtonStub',
    props: {
        disabled: {
            type: Boolean,
            default: false,
        },
    },
    emits: ['click'],
    template: '<button type="button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
})

const ASelectStub = defineComponent({
    name: 'ASelectStub',
    props: {
        options: {
            type: Array,
            default: () => [],
        },
    },
    template: `
        <select>
            <option v-for="option in options" :key="option.value || option.label">
                {{ option.label || option.value }}
            </option>
        </select>
    `,
})

const buildWrapper = (overrides = {}) =>
    mount(AgenticSchedulesSection, {
        props: {
            filters: {
                search: '',
                status: 'active',
                moduleScope: '',
                page: 1,
                perPage: 6,
            },
            draft: {
                name: '',
                cadenceMinutes: 60,
                environment: '',
                moduleScope: '',
                goal: '',
                targetType: '',
                targetId: '',
            },
            statusOptions: [],
            cadenceOptions: [],
            columns: [{ key: 'actions' }],
            schedules: [
                {
                    id: 7,
                    status: 'active',
                    actions: {
                        can_dispatch: true,
                        can_pause: true,
                        can_resume: false,
                        can_run_once: false,
                        can_resume_and_dispatch: false,
                    },
                },
            ],
            meta: {
                total: 1,
            },
            selectedProfile: {
                id: 3,
                slug: 'ops-profile',
            },
            selectedDefinition: {
                id: 11,
                tool_name: 'core.inspect_workflow_context',
            },
            canOperate: true,
            canProvision: true,
            isCreatePending: false,
            isLoading: false,
            isFetching: false,
            isDispatchPending: false,
            isUpdatePending: false,
            activeScheduleId: null,
            scheduleActionMode: null,
            runtimeStatusClass: () => '',
            formatMeta: () => 'meta',
            onCreateSchedule: () => {},
            onToggleSchedule: () => {},
            onDispatchSchedule: () => {},
            onRunOnceSchedule: () => {},
            onResumeDispatchSchedule: () => {},
            onEditScheduleCadence: () => {},
            onOpenRunDetail: () => {},
            ...overrides,
        },
        global: {
            stubs: {
                'a-input': { template: '<input />' },
                'a-input-number': { template: '<input type="number" />' },
                'a-select': ASelectStub,
                'a-pagination': { template: '<div />' },
                'a-table': ATableStub,
                'a-button': AButtonStub,
            },
        },
    })

const findButton = (wrapper, label) =>
    wrapper.findAll('button').find((button) => button.text().trim() === label)

describe('components:AgenticSchedulesSection', () => {
    it('lets operators dispatch without provisioning while keeping provisioning-only controls disabled', () => {
        const wrapper = buildWrapper({
            canOperate: true,
            canProvision: false,
        })

        expect(findButton(wrapper, 'text.agentic.schedules.run_now')?.attributes('disabled')).toBeUndefined()
        expect(findButton(wrapper, 'text.agentic.schedules.create_button')?.attributes('disabled')).toBeDefined()
        expect(findButton(wrapper, 'text.agentic.schedules.pause')?.attributes('disabled')).toBeDefined()
    })

    it('keeps dispatch disabled when the operator capability is missing', () => {
        const wrapper = buildWrapper({
            canOperate: false,
            canProvision: true,
        })

        expect(findButton(wrapper, 'text.agentic.schedules.run_now')?.attributes('disabled')).toBeDefined()
    })

    it('shows paused-only run once and resume-dispatch controls instead of plain dispatch', () => {
        const wrapper = buildWrapper({
            canOperate: true,
            canProvision: true,
            schedules: [
                {
                    id: 8,
                    status: 'paused',
                    actions: {
                        can_dispatch: false,
                        can_pause: false,
                        can_resume: true,
                        can_run_once: true,
                        can_resume_and_dispatch: true,
                    },
                },
            ],
        })

        expect(findButton(wrapper, 'text.agentic.schedules.run_now')).toBeUndefined()
        expect(findButton(wrapper, 'text.agentic.schedules.run_once')?.attributes('disabled')).toBeUndefined()
        expect(findButton(wrapper, 'text.agentic.schedules.resume')?.attributes('disabled')).toBeUndefined()
        expect(findButton(wrapper, 'text.agentic.schedules.resume_and_run_now')?.attributes('disabled')).toBeUndefined()
        expect(wrapper.text()).toContain('text.agentic.schedules.run_once_tooltip')
    })

    it('lets operators run once on paused schedules without provisioning, but keeps resume-dispatch disabled', () => {
        const wrapper = buildWrapper({
            canOperate: true,
            canProvision: false,
            schedules: [
                {
                    id: 9,
                    status: 'paused',
                    actions: {
                        can_dispatch: false,
                        can_pause: false,
                        can_resume: true,
                        can_run_once: true,
                        can_resume_and_dispatch: true,
                    },
                },
            ],
        })

        expect(findButton(wrapper, 'text.agentic.schedules.run_once')?.attributes('disabled')).toBeUndefined()
        expect(findButton(wrapper, 'text.agentic.schedules.resume')?.attributes('disabled')).toBeDefined()
        expect(findButton(wrapper, 'text.agentic.schedules.resume_and_run_now')?.attributes('disabled')).toBeDefined()
    })

    it('renders the action guide so operators can tell run-once apart from resume-dispatch', () => {
        const wrapper = buildWrapper()

        expect(wrapper.text()).toContain('text.agentic.schedules.manual_actions')
        expect(wrapper.text()).toContain('text.agentic.schedules.run_now_desc')
        expect(wrapper.text()).toContain('text.agentic.schedules.run_once_desc')
    })
})
