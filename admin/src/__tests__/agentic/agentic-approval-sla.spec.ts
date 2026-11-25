import { mount } from '@vue/test-utils'
import { reactive, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
    useAgenticApprovals: vi.fn(),
    useAgenticApproval: vi.fn(),
    useAgenticApprovalSlaSummary: vi.fn(),
    query: {} as Record<string, string>,
    filters: null as any,
}))

vi.mock('@/ajax', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('ant-design-vue', () => ({ message: { success: vi.fn(), error: vi.fn(), warning: vi.fn() } }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: mocks.query, params: {} }), useRouter: () => ({ push: vi.fn() }) }))
vi.mock('@/hooks', () => ({
    useAgenticApprovals: mocks.useAgenticApprovals,
    useAgenticApproval: mocks.useAgenticApproval,
    useAgenticApprovalSlaSummary: mocks.useAgenticApprovalSlaSummary,
}))
// Vietnamese wording of the keys under test; every other key echoes back.
const vi_VN: Record<string, string> = {
    'text.agentic.sla.under_minute': 'dưới 1 phút',
    'text.agentic.sla.days': '{n} ngày',
    'text.agentic.sla.hours': '{n} giờ',
    'text.agentic.sla.minutes': '{n} phút',
    'text.agentic.sla.time_left': 'Còn {time}',
    'text.agentic.sla.overdue_by': 'Quá hạn {time}',
    'text.agentic.sla.on_time': 'Đúng hạn',
    'text.agentic.sla.late': 'Trễ hạn',
    'text.agentic.sla.tab_assigned': 'Giao cho tôi',
    'text.agentic.sla.assigned_to': 'Giao cho',
    'text.agentic.sla.assigned_to_you': 'Giao cho bạn',
    'text.agentic.sla.fallback_note': 'Đã chuyển cho người có quyền duyệt',
    'text.agentic.sla.decided_by_others': 'Người khác xử lý: {n}',
}
const t = (key: string, params: Record<string, unknown> = {}) =>
    (vi_VN[key] ?? key).replace(/\{(\w+)\}/g, (_, name) => String(params[name] ?? ''))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t, te: () => true }) }))
vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticPermissions', () => ({
    useAgenticPermissions: () => ({ canViewAgentic: ref(true), canApproveAgentic: ref(true) }),
}))
vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticMeta', () => ({
    useAgenticMeta: () => ({ artifactTypeLabel: () => 'text.agentic.artifact.unknown', moduleLabel: (s: string) => s, sl: (s: string) => `status:${s}` }),
}))
vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticFilters', () => ({
    useAgenticFilters: () => ({ approvalFilters: mocks.filters }),
}))
vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticMutations', () => ({
    useAgenticMutations: () => ({
        handleApprove: vi.fn(), handleReject: vi.fn(), handleApply: vi.fn(), handleApproveApply: vi.fn(), handleRequestChanges: vi.fn(),
        activeApprovalActionId: ref(null), approvalActionMode: ref(null),
    }),
}))

import AgenticApprovalInbox from '../../../../cms/Core/admin/pages/setting/agentic/AgenticApprovalInbox.vue'
import AgenticApprovalDetail from '../../../../cms/Core/admin/pages/setting/agentic/AgenticApprovalDetail.vue'
import AgenticApprovalSla from '../../../../cms/Core/admin/pages/setting/agentic/AgenticApprovalSla.vue'
import { dueChip, formatDuration } from '../../../../cms/Core/admin/pages/setting/agentic/approval-sentence'
import { coreAdminModule } from '../../../../cms/Core/admin/module'
import { coreApprovalsRouteConfig } from '../../../../cms/Core/admin/route-config'

const NOW = Date.parse('2026-11-24T10:00:00Z')
const people = [{ uuid: 'u-1', name: 'Nguyễn An' }, { uuid: 'u-2', name: 'Trần Bình' }]
const assignment = (over: Record<string, unknown> = {}) => ({
    assignees: people, assigned_to_me: true, due_at: '2026-11-24T12:00:00Z', sla_seconds: 14400, decided_at: null,
    response_seconds: null, sla_met: null, overdue: false, breached_at: null, decided_by: null, fallback: false,
    ...over,
})
const approval = (over: Record<string, unknown> = {}) => ({
    id: 9401, status: 'pending', created_at: '2026-11-24T08:00:00Z', run: {}, scope: {},
    artifact: { artifact_type: 'x', payload: {} },
    diff_data: { workflow_node: { kind: 'approval', mode: 'human', title: 'Duyệt bài viết' } },
    ...over,
})

const tagStub = { props: ['color'], template: '<span class="tag" :data-color="color"><slot /></span>' }

describe('durations and the due chip, in plain words', () => {
    it('writes a length of time with at most two units', () => {
        expect(formatDuration(8100, t)).toBe('2 giờ 15 phút')
        expect(formatDuration(45 * 60, t)).toBe('45 phút')
        expect(formatDuration(3 * 86400 + 4 * 3600 + 120, t)).toBe('3 ngày 4 giờ')
        expect(formatDuration(20, t)).toBe('dưới 1 phút')
        expect(formatDuration(null, t)).toBe('—')
        expect(formatDuration(8100, t, { short: true })).toBe('2 giờ')
    })

    it('says how long is left on a waiting approval', () => {
        expect(dueChip(approval({ assignment: assignment() }), t, NOW)).toEqual({ text: 'Còn 2 giờ', tone: 'neutral', color: 'default' })
    })

    it('says how late an overdue approval is, in red and in words', () => {
        const chip = dueChip(approval({ assignment: assignment({ due_at: '2026-11-24T07:00:00Z', overdue: true }) }), t, NOW)
        expect(chip).toEqual({ text: 'Quá hạn 3 giờ', tone: 'late', color: 'red' })
    })

    it('says on time or late once decided, and nothing when no deadline was set', () => {
        expect(dueChip(approval({ status: 'approved', assignment: assignment({ sla_met: true }) }), t, NOW)?.text).toBe('Đúng hạn')
        expect(dueChip(approval({ status: 'rejected', assignment: assignment({ sla_met: false }) }), t, NOW)).toMatchObject({ text: 'Trễ hạn', color: 'red' })
        expect(dueChip(approval({ assignment: assignment({ due_at: null, sla_seconds: null }) }), t, NOW)).toBeNull()
        expect(dueChip(approval({ status: 'approved', assignment: assignment({ due_at: null, sla_met: null }) }), t, NOW)).toBeNull()
        expect(dueChip(approval(), t, NOW)).toBeNull()
    })
})

const mountInbox = (data: unknown[]) => {
    mocks.filters = reactive({ status: 'pending', artifactType: undefined, page: 1, perPage: 6 })
    mocks.useAgenticApprovals.mockReturnValue({ data: ref({ data, meta: { total: data.length, per_page: 20 } }), isLoading: ref(false) })
    return mount(AgenticApprovalInbox, {
        global: {
            stubs: {
                breadcrumbs: { template: '<nav><slot name="badge" /><slot name="description" /><slot /></nav>' },
                'router-link': { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
                'a-segmented': {
                    props: ['value', 'options'],
                    emits: ['update:value'],
                    template: '<div><button v-for="o in options" :key="o.value" :data-tab="o.value" @click="$emit(\'update:value\', o.value)">{{ o.label }}</button></div>',
                },
                'a-checkbox': {
                    props: ['checked'],
                    emits: ['update:checked'],
                    template: '<label><input type="checkbox" :checked="checked" @change="$emit(\'update:checked\', $event.target.checked)" /><slot /></label>',
                },
                'a-select': true, 'a-spin': true, 'a-pagination': true,
                'a-tag': tagStub,
                'a-button': { template: '<button><slot /></button>' },
                'a-popconfirm': { template: '<span><slot /></span>' },
                'no-permission-error': true,
            },
        },
    })
}

describe('approval list: assigned to me and overdue', () => {
    beforeEach(() => {
        mocks.query = {}
        vi.useFakeTimers()
        vi.setSystemTime(NOW)
    })

    it('"Giao cho tôi" asks for the waiting approvals assigned to me', async () => {
        const w = mountInbox([])
        expect(mocks.filters.assigned).toBeUndefined()
        const tab = w.get('[data-tab="assigned"]')
        expect(tab.text()).toBe('Giao cho tôi')
        await tab.trigger('click')
        expect(mocks.filters).toMatchObject({ status: 'pending', assigned: 'me', page: 1 })
        expect(w.get('[data-testid="inbox-empty"]').text()).toBe('text.agentic.sla.empty_assigned')
        await w.get('[data-tab="approved"]').trigger('click')
        expect(mocks.filters).toMatchObject({ status: 'approved', assigned: undefined })
    })

    it('"Quá hạn" keeps only the overdue ones, and is dropped on a tab where nothing waits', async () => {
        const w = mountInbox([])
        await w.get('[data-testid="inbox-overdue-only"] input').setValue(true)
        expect(mocks.filters.overdue).toBe(true)
        expect(w.get('[data-testid="inbox-empty"]').text()).toBe('text.agentic.sla.empty_overdue')
        await w.get('[data-tab="approved"]').trigger('click')
        expect(mocks.filters.overdue).toBeUndefined()
        expect(w.find('[data-testid="inbox-overdue-only"]').exists()).toBe(false)
    })

    it('shows the time left, who it was given to, and overdue in red with words', () => {
        const w = mountInbox([
            approval({ id: 1, assignment: assignment() }),
            approval({ id: 2, assignment: assignment({ due_at: '2026-11-24T07:00:00Z', overdue: true, assigned_to_me: false }) }),
            approval({ id: 3 }),
        ])
        const rows = w.findAll('[data-testid="inbox-row"]')
        expect(rows[0].get('[data-testid="inbox-row-due"]').text()).toBe('Còn 2 giờ')
        expect(rows[0].get('[data-testid="inbox-row-mine"]').text()).toBe('Giao cho bạn')
        expect(rows[0].get('[data-testid="inbox-row-assignees"]').text()).toBe('Giao cho: Nguyễn An, Trần Bình')
        const late = rows[1].get('[data-testid="inbox-row-due"]')
        expect(late.text()).toBe('Quá hạn 3 giờ')
        expect(late.attributes('data-color')).toBe('red')
        expect(rows[1].find('[data-testid="inbox-row-mine"]').exists()).toBe(false)
        // An approval from before the feature has no assignment line at all.
        expect(rows[2].find('[data-testid="inbox-row-assignment"]').exists()).toBe(false)
        expect(w.text()).not.toMatch(/u-1|u-2/)
    })

    it('links to the review times page', () => {
        const w = mountInbox([])
        expect(JSON.parse(w.get('[data-testid="inbox-open-sla"]').attributes('data-to') as string)).toEqual({ name: 'approvals-sla' })
        const page = coreApprovalsRouteConfig.children.find((child: any) => child.name === 'approvals-sla') as any
        expect(page.path).toBe('sla')
        expect(page.meta.rule).toEqual(['agentic.approve', 'agentic.viewAny'])
    })
})

const mountDetail = (data: Record<string, unknown>) => {
    mocks.useAgenticApproval.mockReturnValue({ data: ref(data), isLoading: ref(false), refetch: vi.fn() })
    return mount(AgenticApprovalDetail, {
        props: { approvalId: 9401 },
        global: {
            stubs: {
                AgenticArtifactDiff: true, ScopeSummary: true, 'a-spin': true, 'a-alert': true, 'a-modal': true, 'a-textarea': true,
                'a-radio-group': true, 'a-radio': true, 'a-select': true, 'a-popconfirm': { template: '<span><slot /></span>' },
                'a-tag': tagStub,
                'a-button': { template: '<button><slot /></button>' },
                'router-link': { template: '<a><slot /></a>' },
            },
        },
    })
}

describe('approval detail: reviewer and deadline box', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        vi.setSystemTime(NOW)
    })

    it('shows who it was given to and the time left while it waits', () => {
        const w = mountDetail(approval({ assignment: assignment() }))
        const box = w.get('[data-testid="approval-assignment"]')
        expect(box.get('[data-testid="assignment-assignees"]').text()).toContain('Nguyễn An, Trần Bình')
        expect(box.get('[data-testid="assignment-mine"]').text()).toBe('Giao cho bạn')
        expect(box.get('[data-testid="assignment-due"]').text()).toContain('24/11/2026 19:00')
        expect(box.get('[data-testid="assignment-due-chip"]').text()).toBe('Còn 2 giờ')
        expect(box.find('[data-testid="assignment-decided-by"]').exists()).toBe(false)
        expect(box.find('[data-testid="assignment-fallback"]').exists()).toBe(false)
        expect(box.text()).not.toMatch(/u-1|u-2/)
    })

    it('shows the decider, the time taken and on time after the decision', () => {
        const w = mountDetail(approval({
            status: 'approved',
            assignment: assignment({ decided_at: '2026-11-24T10:15:00Z', response_seconds: 8100, sla_met: true, decided_by: { uuid: 'u-2', name: 'Trần Bình' } }),
        }))
        expect(w.get('[data-testid="assignment-decided-by"]').text()).toBe('Trần Bình')
        expect(w.get('[data-testid="assignment-response"]').text()).toContain('2 giờ 15 phút')
        expect(w.get('[data-testid="assignment-sla-result"]').text()).toBe('Đúng hạn')
        expect(w.find('[data-testid="assignment-due-chip"]').exists()).toBe(false)
    })

    it('says late in red, and that it was passed on when the assignee could not approve', () => {
        const w = mountDetail(approval({
            status: 'rejected',
            assignment: assignment({ decided_at: '2026-11-24T14:00:00Z', response_seconds: 21600, sla_met: false, fallback: true, decided_by: { uuid: 'u-9', name: 'Lê Chi' } }),
        }))
        const result = w.get('[data-testid="assignment-sla-result"]')
        expect(result.text()).toBe('Trễ hạn')
        expect(result.attributes('data-color')).toBe('red')
        expect(w.get('[data-testid="assignment-fallback"]').text()).toBe('Đã chuyển cho người có quyền duyệt')
    })

    it('renders an approval without assignment as before', () => {
        const w = mountDetail(approval())
        expect(w.find('[data-testid="approval-assignment"]').exists()).toBe(false)
        expect(w.get('[data-testid="approval-sentence"]').text()).toBe('Duyệt bài viết')
        const nulled = mountDetail(approval({ assignment: null }))
        expect(nulled.find('[data-testid="approval-assignment"]').exists()).toBe(false)
    })
})

const summary = {
    totals: { assigned: 12, decided: 9, within_sla: 7, late: 2, overdue_now: 1, median_response_seconds: 8100 },
    reviewers: [
        { uuid: 'u-1', name: 'Nguyễn An', assigned: 8, decided_by_them: 5, decided_by_others: 1, within_sla: 5, late: 1, overdue_now: 1, median_response_seconds: 5400, avg_response_seconds: 6000 },
        { uuid: 'u-2', name: 'Trần Bình', assigned: 4, decided_by_them: 3, decided_by_others: 0, within_sla: 2, late: 1, overdue_now: 0, median_response_seconds: null, avg_response_seconds: null },
    ],
}

const mountSla = (data: Record<string, unknown> | undefined, state: Record<string, boolean> = {}) => {
    mocks.useAgenticApprovalSlaSummary.mockReset()
    mocks.useAgenticApprovalSlaSummary.mockReturnValue({ data: ref(data), isLoading: ref(Boolean(state.loading)), isError: ref(Boolean(state.error)) })
    return mount(AgenticApprovalSla, {
        global: {
            stubs: {
                breadcrumbs: { props: ['items'], template: '<nav data-testid="crumbs">{{ items.map((i) => i.text).join(">") }}<slot name="description" /><slot /></nav>' },
                'router-link': { template: '<a><slot /></a>' },
                'a-button': { template: '<button><slot /></button>' },
                'a-date-picker': { props: ['value'], emits: ['update:value'], template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
                'a-alert': { props: ['message'], template: '<div role="alert">{{ message }}</div>' },
                'a-spin': true,
                'i-stat-tile': { props: ['label', 'value', 'tone', 'badge'], template: '<div :data-tone="tone">{{ label }}={{ value }} {{ badge }}</div>' },
                'no-permission-error': true,
            },
        },
    })
}

describe('review times (SLA summary)', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        vi.setSystemTime(NOW)
    })

    it('asks for the last 30 days first, and again when a date changes', async () => {
        const w = mountSla(summary)
        const range = mocks.useAgenticApprovalSlaSummary.mock.calls[0][0]
        expect(range).toEqual({ from: '2026-10-26', to: '2026-11-24' })
        await w.get('[data-testid="sla-from"]').setValue('2026-11-01')
        expect(range).toEqual({ from: '2026-11-01', to: '2026-11-24' })
    })

    it('shows the totals and one row per reviewer, by name and in plain durations', () => {
        const w = mountSla(summary)
        expect(w.get('[data-testid="crumbs"]').text()).toContain('nav.a.approvals>text.agentic.sla.summary_title')
        expect(w.get('[data-testid="sla-total-assigned"]').text()).toContain('=12')
        expect(w.get('[data-testid="sla-total-decided"]').text()).toContain('=9')
        expect(w.get('[data-testid="sla-total-within_sla"]').text()).toContain('=7')
        expect(w.get('[data-testid="sla-total-late"]').text()).toContain('=2')
        expect(w.get('[data-testid="sla-total-late"]').attributes('data-tone')).toBe('danger')
        expect(w.get('[data-testid="sla-total-overdue_now"]').text()).toContain('=1')
        expect(w.get('[data-testid="sla-total-median"]').text()).toContain('2 giờ 15 phút')
        const rows = w.findAll('[data-testid="sla-reviewer-row"]')
        expect(rows).toHaveLength(2)
        expect(rows[0].findAll('th,td').map((cell) => cell.text())).toEqual(['Nguyễn An', '8', '5 Người khác xử lý: 1', '5', '1', '1', '1 giờ 30 phút'])
        expect(rows[1].findAll('th,td').map((cell) => cell.text())).toEqual(['Trần Bình', '4', '3', '2', '1', '0', '—'])
        expect(w.text()).not.toMatch(/u-1|u-2|@/)
    })

    it('says so when nobody was assigned, when the figures are partial and when loading failed', () => {
        const empty = mountSla({ totals: {}, reviewers: [] })
        expect(empty.get('[data-testid="sla-empty"]').text()).toBe('text.agentic.sla.reviewers_empty')
        expect(empty.get('[data-testid="sla-total-assigned"]').text()).toContain('=0')
        const partial = mountSla({ ...summary, truncated: true })
        expect(partial.get('[data-testid="sla-truncated"]').text()).toBe('text.agentic.sla.truncated')
        const failed = mountSla(undefined, { error: true })
        expect(failed.get('[data-testid="sla-error"]').text()).toBe('text.agentic.sla.load_failed')
        expect(failed.find('[data-testid="sla-totals"]').exists()).toBe(false)
    })
})

describe('overdue notification', () => {
    it('has a title and opens the approval it names', () => {
        const entry = (coreAdminModule as any).notifications.find((row: any) => row.type === 'workflow.approval.overdue')
        expect(entry.text).toBe('text.workflow.notifications.approval_overdue')
        expect(entry.open({ approval_id: 9401 })).toEqual({ name: 'approval-detail', params: { id: '9401' } })
    })
})
