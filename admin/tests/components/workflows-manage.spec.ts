import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const hookState = vi.hoisted(() => ({
    workflowsPayload: [],
    functionPayload: [],
    refetchMock: vi.fn(async () => undefined),
}))

// The page reads `?open=<slug>` (a workflow installed from a package) and clears it afterwards.
const routerState = vi.hoisted(() => ({ query: {} as Record<string, unknown>, replace: vi.fn(), push: vi.fn() }))
vi.mock('vue-router', () => ({
    useRoute: () => ({ query: routerState.query }),
    useRouter: () => ({ replace: routerState.replace, push: routerState.push }),
}))

vi.mock('@/hooks', () => ({
    useWorkflows: () => ({
        data: { value: hookState.workflowsPayload },
        isLoading: { value: false },
        isFetching: { value: false },
        refetch: hookState.refetchMock,
    }),
    useWorkflowFunctions: () => ({
        data: { value: hookState.functionPayload },
    }),
    widgetInputDefault: () => ({
        id: 0,
        widget_id: 0,
        name: null,
        type: 'text',
        default_value: null,
        options: [],
    }),
}))

vi.mock('@/mixins', () => ({
    iAlert: vi.fn(),
    rule: vi.fn(() => true),
}))

vi.mock('@/libs', () => ({
    deleteWorkflow: vi.fn(),
    updateWorkflow: vi.fn(),
}))

import WorkflowsManage from '../../../cms/Core/admin/pages/setting/workflows-manage.vue'

const ToolbarStub = defineComponent({
    name: 'WorkflowsToolbar',
    emits: ['create', 'update:show-infrastructure'],
    template: `
        <div>
            <button data-test="create" type="button" @click="$emit('create')">Create</button>
            <button
                data-test="show-infra"
                type="button"
                @click="$emit('update:show-infrastructure', true)"
            >
                Show infra
            </button>
        </div>
    `,
})

const WorkflowListStub = defineComponent({
    name: 'WorkflowList',
    props: {
        workflows: {
            type: Array,
            default: () => [],
        },
    },
    setup(props) {
        return () =>
            h(
                'div',
                { 'data-test': 'workflow-list' },
                (props.workflows || []).map((workflow) => workflow.name).join('|')
            )
    },
})

const WorkflowDetailCardStub = defineComponent({
    name: 'WorkflowDetailCard',
    template: '<div data-test="workflow-detail" />',
})

const WorkflowFormModalStub = defineComponent({
    name: 'WorkflowFormModal',
    setup(_, { emit, expose }) {
        const openCreate = () => {
            emit('saved', {
                mode: 'create',
                openBuilder: true,
                workflow: {
                    id: 99,
                    name: 'Approval Escalation',
                    slug: 'approval-escalation',
                    trigger_event: 'approval.created',
                    target_entity_type: 'App\\\\Models\\\\Approval',
                    description: '',
                    tags: [],
                    nodes: [],
                    edges: [],
                    is_active: true,
                },
            })
        }

        expose({
            openCreate,
            openEdit: vi.fn(),
            closeForm: vi.fn(),
        })

        return () => h('div', { 'data-test': 'workflow-form' })
    },
})

const WorkflowBuilderModalStub = defineComponent({
    name: 'WorkflowBuilderModal',
    props: {
        open: {
            type: Boolean,
            default: false,
        },
        workflow: {
            type: Object,
            default: null,
        },
    },
    setup(props) {
        return () =>
            h(
                'div',
                { 'data-test': 'builder-state' },
                `${props.open ? 'open' : 'closed'}:${props.workflow?.id ?? 'none'}`
            )
    },
})

const mountPage = () =>
    mount(WorkflowsManage, {
        global: {
            stubs: {
                WorkflowsToolbar: ToolbarStub,
                WorkflowList: WorkflowListStub,
                WorkflowDetailCard: WorkflowDetailCardStub,
                WorkflowFormModal: WorkflowFormModalStub,
                WorkflowBuilderModal: WorkflowBuilderModalStub,
            },
        },
    })

describe('pages:WorkflowsManage', () => {
    beforeEach(() => {
        hookState.workflowsPayload = [
            {
                id: 10,
                name: 'Customer Onboarding',
                slug: 'customer-onboarding',
                trigger_event: 'customer.created',
                target_entity_type: 'App\\\\Models\\\\Customer',
                description: 'Visible workflow',
                tags: ['customer'],
                is_active: true,
                nodes: [],
                edges: [],
            },
            {
                id: 11,
                name: 'Agentic Wrapper',
                slug: 'agentic-wrapper',
                trigger_event: 'schedule.dispatched',
                target_entity_type: 'App\\\\Models\\\\Run',
                description: 'Infrastructure workflow',
                tags: ['schedule-wrapper'],
                is_active: true,
                nodes: [],
                edges: [],
            },
        ]
        hookState.functionPayload = []
        hookState.refetchMock.mockClear()
        hookState.refetchMock.mockResolvedValue(undefined)
    })

    it('hides infrastructure workflows by default and reveals them when requested', async () => {
        const wrapper = mountPage()

        expect(wrapper.get('[data-test="workflow-list"]').text()).toContain('Customer Onboarding')
        expect(wrapper.get('[data-test="workflow-list"]').text()).not.toContain('Agentic Wrapper')

        await wrapper.get('[data-test="show-infra"]').trigger('click')

        expect(wrapper.get('[data-test="workflow-list"]').text()).toContain('Agentic Wrapper')
    })

    it('auto-opens the builder after create workflow completes', async () => {
        const wrapper = mountPage()

        expect(wrapper.get('[data-test="builder-state"]').text()).toBe('closed:none')

        await wrapper.get('[data-test="create"]').trigger('click')
        await flushPromises()

        expect(hookState.refetchMock).toHaveBeenCalled()
        expect(wrapper.get('[data-test="builder-state"]').text()).toBe('open:99')
    })
})
