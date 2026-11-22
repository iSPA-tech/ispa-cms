import dayjs from 'dayjs'
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import DetailPage from '../../../../cms/Core/admin/pages/user/detail.vue'

const logs = vi.hoisted(() => ({ value: [] as unknown[], status: 'user_active' as string, created: '2026-11-07 10:00:00' }))

vi.mock('@/mixins', () => ({ iAlert: vi.fn(), rule: () => true }))
vi.mock('@/hooks', async () => {
    const { ref } = await import('vue')
    return {
        useUserDetail: () => ({
            data: ref({ data: { uuid: 'user-1', name: 'Lan', status: logs.status, status_label: 'USER_ACTIVE', created_at: logs.created, roles: [], audit_logs: logs.value } }),
            isLoading: ref(false),
            isFetching: ref(false),
            error: ref(null),
            refetch: vi.fn(),
        }),
    }
})
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { uuid: 'user-1' } }), useRouter: () => ({ push: vi.fn() }) }))
const dict: Record<string, string> = {
    'text.data_scope.history_event': 'Đã đổi phạm vi dữ liệu',
    'text.data_scope.history_added': 'Thêm {name}',
    'text.data_scope.history_added_reach': 'Thêm {name}, {reach}',
    'text.data_scope.history_removed': 'Bỏ {name}',
    'text.data_scope.history_changed': 'Đổi {name}: {reach}',
    'text.data_scope.history_reach_self': 'chỉ mục này',
    'text.data_scope.history_reach_descendants': 'kèm cấp dưới',
    'text.data_scope.history_reach_branch': 'cả nhánh của cấp trên',
    'text.data_scope.history_unknown_node': 'một mục không còn trong phạm vi hiện tại',
    'text.user.active': 'Đang hoạt động',
    'text.data_scope.history_no_change': 'Đã lưu, không có thay đổi',
}
const tr = (key: string, p: Record<string, string> = {}) =>
    (dict[key] ?? key).replace(/\{(\w+)\}/g, (_m, k) => p[k] ?? '')
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: tr, locale: { value: 'vi' } }) }))

const stubs = {
    UserDataScopeCard: { name: 'UserDataScopeCard', emits: ['loaded'], template: '<div />' },
    'a-timeline': { template: '<div><slot /></div>' },
    'a-timeline-item': { template: '<div><slot /></div>' },
}
const asg = (key: string, expansion: string) => ({ dimension: 'organization', node_key: key, expansion })
const entry = (before: unknown[], after: unknown[]) => ({
    id: 1,
    action: 'data_scope.user_updated',
    created_at: '2026-11-07 10:00:00',
    changed: { before: { assignments: before }, after: { assignments: after } },
})
const mountPage = () => mount(DetailPage, { global: { mocks: { $t: tr }, stubs } })

describe('user detail data-scope history', () => {
    it('renders plain sentences with names, never keys, JSON or event codes', async () => {
        const A = '11111111-aaaa-4bbb-8ccc-000000000001'
        const B = '22222222-aaaa-4bbb-8ccc-000000000002'
        const C = '33333333-aaaa-4bbb-8ccc-000000000003'
        logs.value = [entry([asg(A, 'self'), asg(C, 'self')], [asg(B, 'descendants'), asg(C, 'branch')])]
        const w = mountPage()
        w.getComponent({ name: 'UserDataScopeCard' }).vm.$emit('loaded', [
            { dimension: 'organization', node: { key: B, label: 'Tổ chức B' } },
            { dimension: 'organization', node: { key: C, label: 'Tổ chức C' } },
        ])
        await w.vm.$nextTick()
        const text = w.get('[data-testid="scope-history"]').text()
        expect(text).toContain('Thêm Tổ chức B, kèm cấp dưới')
        expect(text).toContain('Đổi Tổ chức C: cả nhánh của cấp trên')
        expect(text).toContain('Bỏ một mục không còn trong phạm vi hiện tại')
        const all = w.text()
        for (const bad of [A, B, C, '{', 'node_key', 'dimension', 'data_scope.user_updated', 'Data Scope']) {
            expect(all).not.toContain(bad)
        }
        expect(all).toContain('Đã đổi phạm vi dữ liệu')
    })

    it('uses the generic phrase for an unresolved added node and a no-change note for a no-op save', () => {
        logs.value = [entry([], [asg('x-1', 'descendants')]), { ...entry([asg('x-1', 'self')], [asg('x-1', 'self')]), id: 2 }]
        const text = mountPage().text()
        expect(text).toContain('Thêm một mục không còn trong phạm vi hiện tại, kèm cấp dưới')
        expect(text).toContain('Đã lưu, không có thay đổi')
        expect(text).not.toContain('x-1')
    })

    it('names a removed node from the stored label, never from its key', () => {
        logs.value = [
            entry([{ ...asg('gone-key-1', 'self'), label: 'Phòng Cũ' }, asg('gone-key-2', 'self')], []),
        ]
        const text = mountPage().text()
        expect(text).toContain('Bỏ Phòng Cũ')
        expect(text).toContain('Bỏ một mục không còn trong phạm vi hiện tại')
        expect(text).not.toContain('gone-key')
    })

    it('shows relative time in Vietnamese and the status as a label', () => {
        logs.value = []
        logs.created = dayjs().subtract(3, 'day').format('YYYY-MM-DD HH:mm:ss')
        const text = mountPage().text()
        expect(text).toContain('trước')
        expect(text).not.toContain('ago')
        expect(text).toContain('Đang hoạt động')
        expect(text).not.toContain('user_active')
        expect(text).not.toContain('USER_ACTIVE')
    })
})
