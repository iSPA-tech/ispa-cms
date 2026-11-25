import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAgenticMutations } from '../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticMutations'

const mocks = vi.hoisted(() => ({ mutateAsync: vi.fn(), requestChanges: vi.fn(), reject: vi.fn() }))
const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }))

vi.mock('ant-design-vue', () => ({ message: toast }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/hooks', () => {
    const idle = () => ({ mutateAsync: vi.fn(), isPending: { value: false } })
    return new Proxy(
        {
            useApproveApplyAgenticApproval: () => ({ mutateAsync: mocks.mutateAsync, isPending: { value: false } }),
            useRequestChangesAgenticApproval: () => ({ mutateAsync: mocks.requestChanges, isPending: { value: false } }),
            useRejectAgenticApproval: () => ({ mutateAsync: mocks.reject, isPending: { value: false } }),
        } as Record<string, unknown>,
        { get: (target, key: string) => target[key] ?? (key.startsWith('use') ? idle : undefined), has: () => true }
    )
})

describe('Approve and apply handler', () => {
    beforeEach(() => {
        mocks.mutateAsync.mockReset()
        Object.values(toast).forEach((mock) => mock.mockReset())
    })

    it('sends the note and reports success', async () => {
        mocks.mutateAsync.mockResolvedValue({ status: 'success', data: { id: 5, outcome: 'applied' } })
        const { handleApproveApply, approvalActionMode } = useAgenticMutations()

        const pending = handleApproveApply(5, 'looks right')
        expect(approvalActionMode.value).toBe('approve_apply')
        expect(await pending).toBe('applied')

        expect(mocks.mutateAsync).toHaveBeenCalledWith({ approvalId: 5, reviewerNote: 'looks right' })
        expect(toast.success).toHaveBeenCalledWith('text.agentic.msg.approve_apply_done')
        expect(approvalActionMode.value).toBeNull()
    })

    it('warns that the content changed and nothing was published', async () => {
        mocks.mutateAsync.mockResolvedValue({ data: { outcome: 'approval_stale' } })
        expect(await useAgenticMutations().handleApproveApply(5, null)).toBe('approval_stale')
        expect(toast.warning).toHaveBeenCalledWith('text.agentic.inbox.stale_warning', 8)
        expect(toast.success).not.toHaveBeenCalled()
    })

    it('says the change could not be applied', async () => {
        mocks.mutateAsync.mockResolvedValue({ data: { outcome: 'apply_failed' } })
        expect(await useAgenticMutations().handleApproveApply(5, null)).toBe('apply_failed')
        expect(toast.error).toHaveBeenCalledWith('text.agentic.inbox.apply_failed_warning', 8)
    })

    it('returns nothing when the request failed, with one message only', async () => {
        mocks.mutateAsync.mockRejectedValue({ response: { data: { message: 'Not pending' } } })
        expect(await useAgenticMutations().handleApproveApply(5, null)).toBeUndefined()
        expect(toast.error).toHaveBeenCalledWith('Not pending')

        toast.error.mockReset()
        // The API client already showed this failure.
        mocks.mutateAsync.mockRejectedValue({ reported: true, response: { data: { message: 'Not pending' } } })
        expect(await useAgenticMutations().handleApproveApply(5, null)).toBeUndefined()
        expect(toast.error).not.toHaveBeenCalled()
    })
})

describe('Ask for a rewrite handler', () => {
    beforeEach(() => {
        mocks.requestChanges.mockReset()
        Object.values(toast).forEach((mock) => mock.mockReset())
    })

    it('sends the feedback, the extra information and the options, and says where the new draft will be', async () => {
        mocks.requestChanges.mockResolvedValue({ data: { id: 5, status: 'changes_requested' } })
        const { handleRequestChanges, approvalActionMode } = useAgenticMutations()

        const pending = handleRequestChanges(5, { feedback: 'Shorter', addedInfo: 'Built in 1880', options: { length: 'shorter' } })
        expect(approvalActionMode.value).toBe('request_changes')
        expect(await pending).toEqual({ data: { id: 5, status: 'changes_requested' } })

        expect(mocks.requestChanges).toHaveBeenCalledWith({ approvalId: 5, feedback: 'Shorter', addedInfo: 'Built in 1880', options: { length: 'shorter' } })
        expect(toast.success).toHaveBeenCalledWith('text.agentic.msg.rewrite_requested')
        expect(approvalActionMode.value).toBeNull()
    })

    it('returns nothing when the request was refused, with one message only', async () => {
        mocks.requestChanges.mockRejectedValue({ response: { data: { message: 'No round left' } } })
        expect(await useAgenticMutations().handleRequestChanges(5, { feedback: 'Shorter' })).toBeUndefined()
        expect(toast.error).toHaveBeenCalledWith('No round left')

        toast.error.mockReset()
        mocks.requestChanges.mockRejectedValue({ reported: true })
        expect(await useAgenticMutations().handleRequestChanges(5, { feedback: 'Shorter' })).toBeUndefined()
        expect(toast.error).not.toHaveBeenCalled()
    })
})

describe('Reject handler', () => {
    beforeEach(() => {
        mocks.reject.mockReset()
        Object.values(toast).forEach((mock) => mock.mockReset())
    })

    it('sends the picked reason with the note, and no reason when none was picked', async () => {
        mocks.reject.mockResolvedValue({ message: '' })
        const { handleReject } = useAgenticMutations()

        await handleReject(5, null, 'wrong_info')
        expect(mocks.reject).toHaveBeenLastCalledWith({ approvalId: 5, reviewerNote: null, reason: 'wrong_info' })
        expect(toast.success).toHaveBeenCalledWith('text.agentic.msg.proposal_rejected')

        // Approvals without declared reasons keep the old request: the server refuses a `reason` there.
        await handleReject(5, 'Not this one')
        expect(mocks.reject).toHaveBeenLastCalledWith({ approvalId: 5, reviewerNote: 'Not this one' })
    })
})
