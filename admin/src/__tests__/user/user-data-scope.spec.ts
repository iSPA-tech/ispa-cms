import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, shallowMount } from '@vue/test-utils'
import { ref } from 'vue'
import DetailPage from '../../../../cms/Core/admin/pages/user/detail.vue'
import UserDataScopeCard from '@/components/data-scope/user-data-scope-card.vue'

const api = vi.hoisted(() => ({
    getScopeDimensions: vi.fn(),
    getUserDataScope: vi.fn(),
    updateUserDataScope: vi.fn(),
}))
const iAlertMock = vi.hoisted(() => vi.fn())
const ruleMock = vi.hoisted(() => vi.fn())

vi.mock('@/libs/data-scope', async () => ({ ...api, scopeLabel: (await vi.importActual<typeof import('@/libs/data-scope')>('@/libs/data-scope')).scopeLabel }))
vi.mock('@/mixins', () => ({ iAlert: iAlertMock, rule: ruleMock }))
vi.mock('@/hooks', async () => {
    const { ref } = await import('vue')
    return {
        useUserDetail: () => ({
            data: ref({ data: { uuid: 'user-1', name: 'Lan', roles: [] } }),
            isLoading: ref(false),
            isFetching: ref(false),
            error: ref(null),
            refetch: vi.fn(),
        }),
    }
})
vi.mock('vue-router', () => ({
    useRoute: () => ({ params: { uuid: 'user-1' } }),
    useRouter: () => ({ push: vi.fn() }),
}))
const i18nState = vi.hoisted(() => ({
    locale: 'vi',
    messages: {
        vi: { 'text.scope.unit': 'Đơn vị Giáo hội', 'text.perm.agent': 'Xử lý phiếu hỗ trợ' } as Record<string, string>,
        en: { 'text.scope.unit': 'Church unit', 'text.perm.agent': 'Handle support tickets' } as Record<string, string>,
    },
}))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        locale: { get value() { return i18nState.locale } },
        t: (key: string) => i18nState.messages[i18nState.locale as 'vi' | 'en'][key] ?? key,
        te: (key: string) => key.startsWith('text.data_scope.not_allowed_'),
    }),
}))

const stubs = {
    'a-select': {
        name: 'ASelect',
        props: ['value', 'options', 'disabled'],
        emits: ['update:value'],
        template: '<div class="a-select" />',
    },
    'a-radio-group': {
        name: 'ARadioGroup',
        props: ['value', 'options', 'disabled'],
        emits: ['update:value'],
        template: '<div class="a-radio" />',
    },
    'a-button': {
        props: ['htmlType', 'disabled', 'loading'],
        template: '<button :type="htmlType || \'button\'" :disabled="disabled"><slot /></button>',
    },
    'scope-node-picker': {
        name: 'ScopeNodePicker',
        props: ['dimension'],
        emits: ['select'],
        template: '<div class="picker" />',
    },
    'scope-summary': { name: 'ScopeSummary', props: ['summary'], template: '<div class="summary" />' },
}

const dimensions = [{ name: 'organization', label: 'Tổ chức', module: 'core', hierarchy: 'tree' }]
const scope = () => ({
    assignments: [
        { uuid: 'a1', dimension: 'organization', node: { key: 'org-1', label: 'Hà Nội', trail: ['Việt Nam', 'Hà Nội'] }, expansion: 'self' },
        { uuid: 'a2', dimension: 'organization', node: { key: 'org-2', label: 'Huế', trail: ['Huế'] }, expansion: 'descendants' },
    ],
    effective: [
        { permission_key: 'post.view', title: 'View posts', summary: { level: 'nodes', dimensions: [] } },
        { permission_key: 'order.view', title: 'View orders', summary: { level: 'all' } },
    ],
})

const mountCard = async () => {
    const w = mount(UserDataScopeCard, { props: { userUuid: 'user-1' }, global: { stubs } })
    await flushPromises()
    return w
}
const httpError = (status: number, data: Record<string, unknown>) => ({ response: { status, data } })

describe('user data scope card', () => {
    beforeEach(() => {
        i18nState.locale = 'vi'
        Object.values(api).forEach((f) => f.mockReset())
        iAlertMock.mockReset()
        api.getScopeDimensions.mockResolvedValue(dimensions)
        api.getUserDataScope.mockResolvedValue(scope())
        api.updateUserDataScope.mockResolvedValue(scope())
    })

    it('lists assigned nodes by name, never by key, and renders the effective scope per permission', async () => {
        const w = await mountCard()
        const text = w.text()
        expect(text).toContain('Hà Nội')
        expect(text).toContain('Việt Nam › Hà Nội')
        expect(text).toContain('View posts')
        expect(text).not.toContain('org-1')
        expect(w.findAllComponents({ name: 'ScopeSummary' })).toHaveLength(2)
        expect(w.findAllComponents({ name: 'ScopeSummary' })[1].props('summary')).toEqual({ level: 'all' })
    })

    it.each([
        ['vi', 'Đơn vị Giáo hội', 'Xử lý phiếu hỗ trợ'],
        ['en', 'Church unit', 'Handle support tickets'],
    ])('translates dimension and permission label_key in %s, never the raw key or an "en | vi" name', async (locale, dim, perm) => {
        i18nState.locale = locale
        api.getScopeDimensions.mockResolvedValue([
            { name: 'organization', label: 'text.scope.unit', label_key: 'text.scope.unit', module: 'core', hierarchy: 'tree' },
        ])
        api.getUserDataScope.mockResolvedValue({
            assignments: scope().assignments,
            effective: [
                { permission_key: 'helpdesk.agent', title: 'Handle support tickets | Xử lý phiếu hỗ trợ', label_key: 'text.perm.agent', summary: { level: 'all' } },
            ],
        })
        const w = await mountCard()
        const text = w.text()
        expect(text).toContain(dim)
        expect(text).toContain(perm)
        expect(text).not.toContain('text.scope.unit')
        expect(text).not.toContain('text.perm.agent')
        expect(text).not.toContain(' | ')
        expect(w.findComponent({ name: 'ASelect' }).props('options')[0].label).toBe(dim)
    })

    it('removes a node and sends the replacement body', async () => {
        const w = await mountCard()
        await w.findAll('[data-testid="node-remove"]')[0].trigger('click')
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(api.updateUserDataScope).toHaveBeenCalledWith('user-1', {
            assignments: [{ dimension: 'organization', node_key: 'org-2', expansion: 'descendants' }],
        })
        expect(iAlertMock).toHaveBeenCalledWith({ message: 'text.data_scope.saved' })
        // data is reloaded after success
        expect(api.getUserDataScope).toHaveBeenCalledTimes(2)
    })

    it('adds a picked node with its expansion', async () => {
        const w = await mountCard()
        w.findComponent({ name: 'ScopeNodePicker' }).vm.$emit('select', { key: 'org-3', label: 'Đà Nẵng', trail: ['Đà Nẵng'] })
        await flushPromises()
        w.findAllComponents({ name: 'ARadioGroup' })[0].vm.$emit('update:value', 'branch')
        await flushPromises()
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(api.updateUserDataScope).toHaveBeenCalledWith('user-1', {
            assignments: [
                { dimension: 'organization', node_key: 'org-1', expansion: 'branch' },
                { dimension: 'organization', node_key: 'org-2', expansion: 'descendants' },
                { dimension: 'organization', node_key: 'org-3', expansion: 'descendants' },
            ],
        })
    })

    it('sends one PUT on a double submit', async () => {
        let resolve: (v: unknown) => void = () => {}
        api.updateUserDataScope.mockReturnValue(new Promise((r) => (resolve = r)))
        const w = await mountCard()
        await w.find('form').trigger('submit')
        await w.find('form').trigger('submit')
        expect(api.updateUserDataScope).toHaveBeenCalledTimes(1)
        resolve(scope())
        await flushPromises()
    })

    it('shows a load error with retry that reloads', async () => {
        api.getUserDataScope.mockRejectedValueOnce(new Error('x'))
        const w = await mountCard()
        expect(w.find('[data-testid="scope-load-error"]').exists()).toBe(true)
        await w.find('[data-testid="scope-retry"]').trigger('click')
        await flushPromises()
        expect(w.find('[data-testid="scope-load-error"]').exists()).toBe(false)
        expect(w.text()).toContain('Hà Nội')
    })

    it('shows the empty state when nothing is assigned', async () => {
        api.getUserDataScope.mockResolvedValue({ assignments: [], effective: [] })
        const w = await mountCard()
        expect(w.find('[data-testid="scope-no-nodes"]').exists()).toBe(true)
        expect(w.find('[data-testid="scope-no-effective"]').exists()).toBe(true)
    })

    it('shows a 422 error under its node row and lets the operator submit again', async () => {
        api.updateUserDataScope.mockRejectedValue(httpError(422, { errors: { 'assignments.1.node_key': ['Bad node'] } }))
        const w = await mountCard()
        await w.find('form').trigger('submit')
        await flushPromises()
        const rows = w.findAll('[data-testid="node-row"]')
        expect(rows[0].text()).not.toContain('Bad node')
        expect(rows[1].text()).toContain('Bad node')
        await w.find('form').trigger('submit')
        expect(api.updateUserDataScope).toHaveBeenCalledTimes(2)
    })

    it.each([
        ['self_edit_forbidden', 'text.data_scope.not_allowed_self_edit_forbidden'],
        ['something_new', 'text.data_scope.not_allowed'],
    ])('shows the 403 reason %s in words', async (code, expected) => {
        api.updateUserDataScope.mockRejectedValue(httpError(403, { error_code: code }))
        const w = await mountCard()
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(w.find('[data-testid="scope-general-error"]').text()).toBe(expected)
    })
})

describe('user detail page data scope card', () => {
    const mountPage = () =>
        shallowMount(DetailPage, { global: { mocks: { $t: (k: string) => k }, stubs: { UserDataScopeCard: true } } })

    it('hides the card without data_scope.manage', () => {
        ruleMock.mockReturnValue(false)
        expect(mountPage().findComponent({ name: 'UserDataScopeCard' }).exists()).toBe(false)
    })

    it('shows the card with data_scope.manage and passes the user uuid', () => {
        ruleMock.mockImplementation((key: string) => key === 'data_scope.manage')
        const card = mountPage().findComponent({ name: 'UserDataScopeCard' })
        expect(card.exists()).toBe(true)
        expect(card.props('userUuid')).toBe('user-1')
    })
})
