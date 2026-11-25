import { reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/ajax', () => ({ default: http }))
// The query is run by hand: only the request each hook sends is under test.
vi.mock('@tanstack/vue-query', () => ({ useQuery: (options: unknown) => options, useMutation: (options: unknown) => options, useQueryClient: () => ({}) }))

import { useAgenticApprovalSlaSummary, useAgenticApprovals } from '@/hooks/useAgentic'

describe('approval requests for assignment and SLA', () => {
    beforeEach(() => http.get.mockReset().mockResolvedValue({ data: { data: { data: [], meta: {} } } }))

    it('sends assigned=me and overdue=1 only when asked', async () => {
        const filters = reactive({ status: 'pending', assigned: undefined as string | undefined, overdue: undefined as boolean | undefined, page: 1, perPage: 20 })
        const query = useAgenticApprovals(filters) as any
        await query.queryFn()
        expect(http.get).toHaveBeenLastCalledWith('agentic/approvals?status=pending&page=1&per_page=20')

        filters.assigned = 'me'
        filters.overdue = true
        await query.queryFn()
        expect(http.get).toHaveBeenLastCalledWith('agentic/approvals?status=pending&assigned=me&overdue=1&page=1&per_page=20')
        // The list is asked again when a filter changes.
        expect(query.queryKey()[1]).toContain('assigned=me&overdue=1')
    })

    it('asks the SLA summary for the chosen period', async () => {
        http.get.mockResolvedValue({ data: { data: { totals: { assigned: 3 }, reviewers: [] } } })
        const range = reactive({ from: '2026-11-01', to: '2026-11-24' })
        const query = useAgenticApprovalSlaSummary(range) as any
        expect(await query.queryFn()).toEqual({ totals: { assigned: 3 }, reviewers: [] })
        expect(http.get).toHaveBeenCalledWith('agentic/approvals/sla-summary', { params: { from: '2026-11-01', to: '2026-11-24' } })
        expect(query.queryKey()).toEqual(['agentic-approvals-sla-summary', { from: '2026-11-01', to: '2026-11-24' }])
    })
})
