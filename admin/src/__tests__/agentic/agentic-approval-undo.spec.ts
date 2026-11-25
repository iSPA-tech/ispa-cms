import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AgenticApprovalDetail from '../../../../cms/Core/admin/pages/setting/agentic/AgenticApprovalDetail.vue'

const mocks = vi.hoisted(() => ({
    useAgenticApproval: vi.fn(),
    refetch: vi.fn(),
    post: vi.fn(),
    canApprove: true,
}))

vi.mock('vue-router', () => ({ useRoute: () => ({ params: {} }) }))
vi.mock('@/ajax', () => ({ default: { post: mocks.post } }))
vi.mock('ant-design-vue', () => ({ message: { success: vi.fn(), error: vi.fn(), warning: vi.fn() } }))
vi.mock('@/hooks', () => ({ useAgenticApproval: mocks.useAgenticApproval }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticPermissions', () => ({
    useAgenticPermissions: () => ({ canApproveAgentic: ref(mocks.canApprove) }),
}))
vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticMeta', () => ({
    useAgenticMeta: () => ({ artifactTypeLabel: () => 'Artifact', moduleLabel: () => 'Module', sl: (s: string) => s }),
}))
vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticMutations', () => ({
    useAgenticMutations: () => ({
        handleApprove: vi.fn(),
        handleReject: vi.fn(),
        handleApply: vi.fn(),
        handleApproveApply: vi.fn(),
        approvalActionMode: ref(null),
    }),
}))

const reversible = (node: Record<string, unknown> = {}) => ({
    status: 'approved',
    apply_outcome: 'applied',
    diff_data: { workflow_node: { kind: 'approval', mode: 'human', decided_by: 'reversible_fields', ...node } },
})

const mountDetail = (approval: Record<string, unknown>) => {
    mocks.useAgenticApproval.mockReturnValue({
        data: ref({ id: 7, artifact: null, run: { module_scope: 'Core' }, scope: {}, ...approval }),
        isLoading: ref(false),
        refetch: mocks.refetch,
    })
    return mount(AgenticApprovalDetail, {
        props: { approvalId: 7 },
        global: {
            stubs: {
                AgenticArtifactDiff: true,
                ScopeSummary: true,
                'a-spin': true,
                'a-button': { props: ['type', 'disabled', 'loading'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
                'a-tag': { template: '<span><slot /></span>' },
                'a-collapse': true,
                'a-collapse-panel': true,
                'a-textarea': true,
                'a-alert': true,
                'a-modal': true,
            },
        },
    })
}

describe('Undo a reversible change on the approval detail', () => {
    beforeEach(() => {
        mocks.post.mockReset()
        mocks.refetch.mockReset()
        mocks.canApprove = true
    })

    it('offers Undo for an applied reversible change and calls the undo route', async () => {
        mocks.post.mockResolvedValue({ data: { data: {} } })
        const w = mountDetail(reversible())
        const button = w.get('[data-testid="undo-button"]')
        expect(button.text()).toBe('text.agentic.workflow_node.undo')

        await button.trigger('click')
        await flushPromises()
        expect(mocks.post).toHaveBeenCalledWith('agentic/approvals/7/undo')
        expect(mocks.refetch).toHaveBeenCalled()
    })

    it('hides Undo once undone, for other deciders, and without the approve permission', () => {
        expect(mountDetail(reversible({ undone_at: '2026-11-24T10:00:00Z' })).find('[data-testid="undo-button"]').exists()).toBe(false)
        expect(mountDetail(reversible({ decided_by: 'auto' })).find('[data-testid="undo-button"]').exists()).toBe(false)
        mocks.canApprove = false
        expect(mountDetail(reversible()).find('[data-testid="undo-button"]').exists()).toBe(false)
    })
})
