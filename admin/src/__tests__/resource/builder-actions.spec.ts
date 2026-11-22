import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
const nav = vi.hoisted(() => ({ route: { query: {} } as { query: Record<string, any> }, replaced: [] as any[] }))
vi.mock('vue-router', () => ({
    useRoute: () => nav.route,
    useRouter: () => ({
        replace: (to: { query: Record<string, any> }) => {
            nav.replaced.push(to)
            nav.route.query = to.query
        },
    }),
}))

const ajax = vi.hoisted(() => ({ get: vi.fn(), request: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajax }))
vi.mock('ant-design-vue', () => ({ message: { success: vi.fn(), error: vi.fn() } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const stubs = {
    'i-button': { emits: ['click'], template: '<button type="button" class="ibtn" @click="$emit(\'click\')"><slot /></button>' },
    'a-alert': { template: '<div />' },
    'a-skeleton': { template: '<div />' },
    'a-input-search': { template: '<input />' },
    'a-select': { template: '<select />' },
    'a-popconfirm': { template: '<div class="pop"><slot /></div>' },
    'a-tabs': { template: '<div><slot /></div>' },
    'a-tab-pane': { template: '<section><slot /></section>' },
    'a-table': {
        props: ['dataSource', 'columns', 'rowSelection'],
        template:
            '<div :data-selection="String(!!rowSelection)"><table><tbody><tr v-for="r in dataSource" :key="r.uuid"><td v-for="c in columns" :key="c.key"><slot name="bodyCell" :column="c" :record="r" /></td></tr></tbody></table></div>',
    },
}
const ep = (path: string) => ({ method: 'POST', path })
const actions = [
    { name: 'toggle', label: 'x.toggle', label_from: { field: 'active', on: 'x.deactivate', off: 'x.activate' }, endpoint: ep('/api/v2/admin/x/items/:uuid/toggle') },
    { name: 'purge', label: 'x.purge', bulk: true, endpoint: ep('/api/v2/admin/x/items/purge') },
]
const schema = (extra: Record<string, unknown> = {}) => ({
    list: {
        endpoint: { path: '/api/v2/admin/x/items', adapter: 'ispa-list-v1' },
        columns: [{ field: 'name', type: 'text', label: 'x.name' }],
        rowActions: ['toggle'],
        bulkActions: ['purge'],
        ...extra.list as object,
    },
    detail: { endpoint: { method: 'GET', path: '/api/v2/admin/x/items/:uuid' }, sections: [{ field: 'name', type: 'text', label: 'x.name' }] },
    form: { update: { endpoint: { method: 'PUT', path: '/api/v2/admin/x/items/:uuid' }, fields: [] } },
    actions: [...actions],
    ...(extra.abilities ? { abilities: extra.abilities } : {}),
})
const rows = [{ uuid: 'u1', name: 'One', active: true }, { uuid: 'u2', name: 'Two', active: 0 }]

beforeEach(() => {
    vi.resetAllMocks()
    ajax.get.mockResolvedValue({ data: { data: rows, meta: { current_page: 1, per_page: 20, total: 2, last_page: 1 } } })
})

describe('toggle row action labels', () => {
    it('picks the on or off label by the record value in the list', async () => {
        const { default: List } = await import('@/components/resource/resource-list.vue')
        const w = mount(List, { props: { schema: schema() }, global: { stubs } })
        await flushPromises()
        const buttons = w.findAll('[data-action="toggle"]')
        expect(buttons.map((b) => b.text())).toEqual(['x.deactivate', 'x.activate'])
    })

    it('falls back to the label without label_from', async () => {
        const { default: List } = await import('@/components/resource/resource-list.vue')
        const plain = schema()
        plain.actions[0] = { name: 'toggle', label: 'x.toggle', endpoint: ep('/x') } as never
        const w = mount(List, { props: { schema: plain }, global: { stubs } })
        await flushPromises()
        expect(w.findAll('[data-action="toggle"]').map((b) => b.text())).toEqual(['x.toggle', 'x.toggle'])
    })

    it('picks the label in the detail view', async () => {
        ajax.get.mockResolvedValue({ data: { data: rows[0] } })
        const { default: Detail } = await import('@/components/resource/resource-detail.vue')
        const w = mount(Detail, { props: { schema: schema(), uuid: 'u1' }, global: { stubs } })
        await flushPromises()
        expect(w.find('[data-action="toggle"]').text()).toBe('x.deactivate')
    })
})

describe('view-only schema', () => {
    const readOnly = { abilities: { view: true, manage: false } }

    it('list renders without row actions, bulk actions or selection', async () => {
        const { default: List } = await import('@/components/resource/resource-list.vue')
        const w = mount(List, { props: { schema: schema({ ...readOnly, list: { selectable: true } }) }, global: { stubs } })
        await flushPromises()
        expect(w.findAll('[data-action]')).toHaveLength(0)
        expect(w.find('[data-selection]').attributes('data-selection')).toBe('false')
    })

    it('detail shows the record without edit or actions', async () => {
        ajax.get.mockResolvedValue({ data: { data: rows[0] } })
        const { default: Detail } = await import('@/components/resource/resource-detail.vue')
        const w = mount(Detail, { props: { schema: schema(readOnly), uuid: 'u1' }, global: { stubs } })
        await flushPromises()
        expect(w.find('[data-section="name"]').exists()).toBe(true)
        expect(w.find('[data-test="edit"]').exists()).toBe(false)
        expect(w.findAll('[data-action]')).toHaveLength(0)
    })

    it('detail keeps edit and actions when manage is held', async () => {
        ajax.get.mockResolvedValue({ data: { data: rows[0] } })
        const { default: Detail } = await import('@/components/resource/resource-detail.vue')
        const w = mount(Detail, { props: { schema: schema({ abilities: { view: true, manage: true } }), uuid: 'u1' }, global: { stubs } })
        await flushPromises()
        expect(w.find('[data-test="edit"]').exists()).toBe(true)
        expect(w.find('[data-action="toggle"]').exists()).toBe(true)
    })
})
