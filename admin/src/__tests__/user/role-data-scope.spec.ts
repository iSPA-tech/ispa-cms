import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import RolesPage from '../../../../cms/Core/admin/pages/user/roles.vue'
import RoleDataScopeDrawer from '@/components/data-scope/role-data-scope-drawer.vue'

const api = vi.hoisted(() => ({
    getScopeDimensions: vi.fn(),
    getRoleDataScope: vi.fn(),
    updateRoleDataScope: vi.fn(),
}))
const iAlertMock = vi.hoisted(() => vi.fn())
const ruleMock = vi.hoisted(() => vi.fn())

vi.mock('@/libs/data-scope', async () => ({ ...api, scopeLabel: (await vi.importActual<typeof import('@/libs/data-scope')>('@/libs/data-scope')).scopeLabel }))
vi.mock('@/mixins', () => ({
    iAlert: iAlertMock,
    rule: ruleMock,
    can: () => true,
    anyOf: (...a: unknown[]) => a,
    perm: (...a: unknown[]) => a.join('.'),
}))
vi.mock('@/hooks', async () => {
    const { ref } = await import('vue')
    return {
        useRoles: () => ({
            data: ref([{ id: 7, name: 'Editor', custom: {}, permissions: [] }]),
            isFetching: ref(false),
            isLoading: ref(false),
            refetch: vi.fn(),
        }),
    }
})
vi.mock('@/libs', () => ({ getRole: vi.fn() }))
vi.mock('@/ajax', () => ({ default: {} }))
vi.mock('@vueuse/core', () => ({ useStorage: () => ref({ level: 1 }) }))
vi.mock('@/components/permission-editor/PermissionEditor.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/permission-editor/PermissionOverview.vue', () => ({ default: { template: '<div />' } }))
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
        t: (key: string, params: Record<string, string> = {}) =>
            (i18nState.messages[i18nState.locale as 'vi' | 'en'][key] ?? key) + (params.dimension ? `:${params.dimension}` : ''),
        te: (key: string) => key.startsWith('text.data_scope.not_allowed_'),
    }),
}))

const stubs = {
    'a-drawer': { props: ['open', 'title'], template: '<div v-if="open"><slot /></div>' },
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
}

const dimensions = [{ name: 'organization', label: 'Tổ chức', module: 'core', hierarchy: 'tree' }]
const scope = () => ({
    grants: [
        { permission_key: 'post.view', title: 'View posts', allowed_levels: ['all', 'own', 'organization'], level: 'organization' },
        { permission_key: 'order.view', title: 'View orders', allowed_levels: ['all', 'own'], level: 'all' },
    ],
    assignments: [
        { uuid: 'u1', dimension: 'organization', node: { key: 'org-1', label: 'Hà Nội', trail: ['Việt Nam', 'Hà Nội'] }, expansion: 'self' },
    ],
})

const mountDrawer = async () => {
    const w = mount(RoleDataScopeDrawer, { props: { visible: true, roleId: 7, roleName: 'Editor' }, global: { stubs } })
    await flushPromises()
    return w
}

const httpError = (status: number, data: Record<string, unknown>) => ({ response: { status, data } })

describe('role data scope drawer', () => {
    beforeEach(() => {
        i18nState.locale = 'vi'
        Object.values(api).forEach((f) => f.mockReset())
        iAlertMock.mockReset()
        api.getScopeDimensions.mockResolvedValue(dimensions)
        api.getRoleDataScope.mockResolvedValue(scope())
        api.updateRoleDataScope.mockResolvedValue(scope())
    })

    it('lists only the returned scoped permissions and only their allowed levels', async () => {
        const w = await mountDrawer()
        const selects = w.findAllComponents('[data-testid="grant-level"]')
        expect(w.findAll('[data-testid="grant-row"]')).toHaveLength(2)
        expect(selects[0].props('options').map((o: { value: string }) => o.value)).toEqual(['all', 'own', 'organization'])
        expect(selects[0].props('options')[2].label).toBe('text.data_scope.level_dimension:Tổ chức')
        expect(selects[1].props('options').map((o: { value: string }) => o.value)).toEqual(['all', 'own'])
    })

    it.each([
        ['vi', 'Đơn vị Giáo hội', 'Xử lý phiếu hỗ trợ'],
        ['en', 'Church unit', 'Handle support tickets'],
    ])('translates dimension and permission label_key in %s, never the raw key or an "en | vi" name', async (locale, dim, perm) => {
        i18nState.locale = locale
        api.getScopeDimensions.mockResolvedValue([
            { name: 'organization', label: 'text.scope.unit', label_key: 'text.scope.unit', module: 'core', hierarchy: 'tree' },
        ])
        api.getRoleDataScope.mockResolvedValue({
            grants: [
                { permission_key: 'helpdesk.agent', title: 'Handle support tickets | Xử lý phiếu hỗ trợ', label_key: 'text.perm.agent', allowed_levels: ['all', 'organization'], level: 'organization' },
            ],
            assignments: [],
        })
        const w = await mountDrawer()
        i18nState.locale = 'vi'
        const text = w.text()
        expect(text).toContain(dim)
        expect(text).toContain(perm)
        expect(text).not.toContain('text.scope.unit')
        expect(text).not.toContain('text.perm.agent')
        expect(text).not.toContain(' | ')
        expect(w.findAllComponents('[data-testid="grant-level"]')[0].props('options')[1].label).toBe(`text.data_scope.level_dimension:${dim}`)
    })

    it('shows the role nodes by name and never the key', async () => {
        const w = await mountDrawer()
        const text = w.text()
        expect(text).toContain('Hà Nội')
        expect(text).toContain('Việt Nam › Hà Nội')
        expect(text).not.toContain('org-1')
        expect(w.find('[data-testid="dimension-section"]').exists()).toBe(true)
    })

    it('shows empty and load-error states with retry', async () => {
        api.getRoleDataScope.mockRejectedValueOnce(new Error('x'))
        const w = await mountDrawer()
        expect(w.find('[data-testid="scope-load-error"]').exists()).toBe(true)
        api.getRoleDataScope.mockResolvedValueOnce({ grants: [], assignments: [] })
        await w.find('[data-testid="scope-retry"]').trigger('click')
        await flushPromises()
        expect(w.find('[data-testid="scope-empty"]').exists()).toBe(true)
    })

    it('sends the expected PUT payload, including an added node and expansion change', async () => {
        const w = await mountDrawer()
        w.findComponent({ name: 'ScopeNodePicker' }).vm.$emit('select', { key: 'org-2', label: 'Huế', trail: ['Huế'] })
        await flushPromises()
        const radios = w.findAllComponents({ name: 'ARadioGroup' })
        radios[0].vm.$emit('update:value', 'branch')
        await flushPromises()
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(api.updateRoleDataScope).toHaveBeenCalledWith(7, {
            grants: [
                { permission_key: 'post.view', level: 'organization' },
                { permission_key: 'order.view', level: 'all' },
            ],
            assignments: [
                { dimension: 'organization', node_key: 'org-1', expansion: 'branch' },
                { dimension: 'organization', node_key: 'org-2', expansion: 'descendants' },
            ],
        })
        expect(w.emitted('saved')).toHaveLength(1)
        expect(iAlertMock).toHaveBeenCalledWith({ message: 'text.data_scope.saved' })
    })

    it('drops the node section and its assignments when no grant uses the dimension', async () => {
        const w = await mountDrawer()
        w.findAllComponents('[data-testid="grant-level"]')[0].vm.$emit('update:value', 'own')
        await flushPromises()
        expect(w.find('[data-testid="dimension-section"]').exists()).toBe(false)
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(api.updateRoleDataScope.mock.calls[0][1].assignments).toEqual([])
    })

    it('sends one PUT on a double submit', async () => {
        let resolve: (v: unknown) => void = () => {}
        api.updateRoleDataScope.mockReturnValue(new Promise((r) => (resolve = r)))
        const w = await mountDrawer()
        await w.find('form').trigger('submit')
        await w.find('form').trigger('submit')
        expect(api.updateRoleDataScope).toHaveBeenCalledTimes(1)
        resolve(scope())
        await flushPromises()
    })

    it('maps 422 errors to the grant and node rows', async () => {
        api.updateRoleDataScope.mockRejectedValue(
            httpError(422, { errors: { 'grants.1.level': ['Bad level'], 'assignments.0.node_key': ['Bad node'] } })
        )
        const w = await mountDrawer()
        await w.find('form').trigger('submit')
        await flushPromises()
        const rows = w.findAll('[data-testid="grant-row"]')
        expect(rows[0].text()).not.toContain('Bad level')
        expect(rows[1].text()).toContain('Bad level')
        expect(w.find('[data-testid="node-row"]').text()).toContain('Bad node')
        // loading is reset on error so the operator can submit again
        await w.find('form').trigger('submit')
        expect(api.updateRoleDataScope).toHaveBeenCalledTimes(2)
    })

    it.each([
        ['scope_escalation', 'text.data_scope.not_allowed_scope_escalation'],
        ['held_role_forbidden', 'text.data_scope.not_allowed_held_role_forbidden'],
        ['something_new', 'text.data_scope.not_allowed'],
    ])('shows the 403 reason %s in words', async (code, expected) => {
        api.updateRoleDataScope.mockRejectedValue(httpError(403, { error_code: code }))
        const w = await mountDrawer()
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(w.find('[data-testid="scope-general-error"]').text()).toBe(expected)
    })
})

describe('roles page data scope action', () => {
    const rolesStubs = {
        breadcrumbs: { template: '<div><slot /></div>' },
        'i-button': { template: '<button><slot /></button>' },
        'i-icon': { template: '<i />' },
        'i-badge': { template: '<span><slot /></span>' },
        'a-table': {
            props: ['dataSource'],
            template: '<div><div v-for="r in dataSource" :key="r.id"><slot name="bodyCell" :column="{ key: \'action\' }" :record="r" /></div></div>',
        },
        'a-modal': { template: '<div />' },
        'a-popconfirm': { template: '<div><slot /></div>' },
        RoleDataScopeDrawer: { name: 'RoleDataScopeDrawer', props: ['visible', 'roleId', 'roleName'], template: '<div />' },
        AppIconAction: {
            props: ['label'],
            emits: ['click'],
            template: '<button :data-title="label" @click="$emit(\'click\')" />',
        },
    }
    const mountRoles = () =>
        mount(RolesPage, { global: { stubs: rolesStubs, mocks: { $t: (k: string) => k } } })

    it('hides the action without data_scope.manage', () => {
        ruleMock.mockReturnValue(false)
        const w = mountRoles()
        expect(w.find('[data-title="text.data_scope.action"]').exists()).toBe(false)
    })

    it('shows the action with data_scope.manage and opens the drawer for the role', async () => {
        ruleMock.mockImplementation((key: string) => key === 'data_scope.manage')
        const w = mountRoles()
        await w.find('[data-title="text.data_scope.action"]').trigger('click')
        const drawer = w.findComponent({ name: 'RoleDataScopeDrawer' })
        expect(drawer.props()).toMatchObject({ visible: true, roleId: 7, roleName: 'Editor' })
    })
})
