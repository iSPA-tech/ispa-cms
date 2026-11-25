import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AgenticApprovalDetail from '../../../../cms/Core/admin/pages/setting/agentic/AgenticApprovalDetail.vue'

const mocks = vi.hoisted(() => ({
    useAgenticApproval: vi.fn(),
    handleApproveApply: vi.fn(),
    handleApprove: vi.fn(),
    handleApply: vi.fn(),
    canApprove: true,
}))

vi.mock('vue-router', () => ({ useRoute: () => ({ params: {} }) }))
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
        handleApprove: mocks.handleApprove,
        handleReject: vi.fn(),
        handleApply: mocks.handleApply,
        handleApproveApply: mocks.handleApproveApply,
        approvalActionMode: ref(null),
    }),
}))

const mountDetail = (approval: Record<string, unknown>) => {
    mocks.useAgenticApproval.mockReturnValue({ data: ref({ id: 5, artifact: null, run: { module_scope: 'Core' }, scope: {}, ...approval }), isLoading: ref(false) })
    return mount(AgenticApprovalDetail, {
        props: { approvalId: 5 },
        global: {
            stubs: {
                AgenticArtifactDiff: true,
                ScopeSummary: true,
                'a-spin': true,
                'a-button': { props: ['type', 'disabled'], template: '<button :data-type="type" :disabled="disabled"><slot /></button>' },
                'a-tag': { template: '<span><slot /></span>' },
                'a-collapse': true,
                'a-collapse-panel': true,
                'a-textarea': true,
                'a-alert': { props: ['message', 'type'], template: '<div role="alert" :data-type="type">{{ message }}</div>' },
                'a-modal': {
                    props: ['open', 'title'],
                    emits: ['ok', 'update:open'],
                    template: '<div v-if="open" data-testid="modal" :data-title="title"><slot /><button data-testid="modal-ok" @click="$emit(\'ok\')" /></div>',
                },
            },
        },
    })
}

describe('Approve and apply on the approval detail', () => {
    beforeEach(() => {
        mocks.handleApproveApply.mockReset()
        mocks.handleApprove.mockReset()
        mocks.canApprove = true
    })

    it('offers one primary button and keeps plain approve as a secondary one', () => {
        const w = mountDetail({ status: 'pending' })
        const primary = w.get('[data-testid="approve-apply-button"]')
        expect(primary.text()).toBe('text.agentic.inbox.approve_apply')
        expect(primary.attributes('data-type')).toBe('primary')
        expect(w.get('[data-testid="approve-button"]').attributes('data-type')).toBeUndefined()
    })

    it('approves and applies after the confirmation, then closes the dialog', async () => {
        mocks.handleApproveApply.mockResolvedValue('applied')
        const w = mountDetail({ status: 'pending' })
        expect(w.find('[data-testid="modal"]').exists()).toBe(false)

        await w.get('[data-testid="approve-apply-button"]').trigger('click')
        expect(w.get('[data-testid="modal"]').attributes('data-title')).toBe('text.agentic.inbox.approve_apply_title')
        await w.get('[data-testid="modal-ok"]').trigger('click')
        await Promise.resolve()
        await w.vm.$nextTick()

        expect(mocks.handleApproveApply).toHaveBeenCalledWith(5, null)
        expect(mocks.handleApprove).not.toHaveBeenCalled()
        expect(w.find('[data-testid="modal"]').exists()).toBe(false)
    })

    it('keeps the dialog open when the request itself failed', async () => {
        mocks.handleApproveApply.mockResolvedValue(undefined)
        const w = mountDetail({ status: 'pending' })
        await w.get('[data-testid="approve-apply-button"]').trigger('click')
        await w.get('[data-testid="modal-ok"]').trigger('click')
        await Promise.resolve()
        await w.vm.$nextTick()
        expect(w.find('[data-testid="modal"]').exists()).toBe(true)
    })

    it('hides the buttons from a person who may not approve, and once it is no longer pending', () => {
        mocks.canApprove = false
        expect(mountDetail({ status: 'pending' }).find('[data-testid="approve-apply-button"]').exists()).toBe(false)
        mocks.canApprove = true
        expect(mountDetail({ status: 'approved', apply_outcome: 'applied' }).find('[data-testid="approve-apply-button"]').exists()).toBe(false)
    })

    it('says plainly that the content changed and nothing was published', () => {
        const w = mountDetail({ status: 'approved', apply_outcome: 'approval_stale' })
        const alert = w.get('[data-testid="approval-stale"]')
        expect(alert.text()).toBe('text.agentic.inbox.stale_warning')
        expect(alert.attributes('data-type')).toBe('warning')
        expect(w.find('[data-testid="approval-apply-failed"]').exists()).toBe(false)
        // The separate apply stays available for a retry.
        expect(w.text()).toContain('text.agentic.inbox.apply')
    })

    it('says plainly that the change could not be applied', () => {
        const w = mountDetail({ status: 'approved', apply_outcome: 'apply_failed' })
        const alert = w.get('[data-testid="approval-apply-failed"]')
        expect(alert.text()).toBe('text.agentic.inbox.apply_failed_warning')
        expect(alert.attributes('data-type')).toBe('error')
        expect(w.find('[data-testid="approval-stale"]').exists()).toBe(false)
    })
})
