import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ replace: vi.fn() }) }))
const ajax = vi.hoisted(() => ({ get: vi.fn(), request: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajax }))
vi.mock('ant-design-vue', () => ({ message: { success: vi.fn(), error: vi.fn() } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const stubs = {
    'i-button': { emits: ['click'], template: '<button type="button" class="ibtn" @click="$emit(\'click\', $event)"><slot /></button>' },
    'a-alert': { template: '<div role="alert" />' },
    'a-skeleton': { template: '<div />' },
    'a-input-search': { template: '<input />' },
    'a-input': { template: '<input />' },
    'a-select': { template: '<select />' },
    'a-table': { template: '<table class="tbl" data-testid="resource-table" />' },
    'a-pagination': { props: ['current', 'total', 'pageSize'], emits: ['change'], template: '<nav :data-total="total" />' },
    'a-popconfirm': { emits: ['confirm'], template: '<span class="pop"><slot /><button type="button" class="pop-ok" @click="$emit(\'confirm\')" /></span>' },
}
const schema = {
    list: {
        endpoint: { url: '/api/v2/admin/x/items', adapter: 'ispa-list-v1' },
        columns: [
            { field: 'name', type: 'text', label: 'text.x.name', link: 'detail' },
            { field: 'status', type: 'text', label: 'text.x.status' },
        ],
        rowActions: ['confirm', 'delete'],
    },
    actions: [
        { name: 'confirm', label: 'text.x.confirm', endpoint: { method: 'PUT', path: '/api/v2/admin/x/items/:uuid/quick' } },
        { name: 'delete', label: 'action.delete', danger: true, refresh: 'list', endpoint: { method: 'DELETE', path: '/api/v2/admin/x/items/:uuid' } },
    ],
}
const media = (matches: boolean) => vi.stubGlobal('matchMedia', () => ({ matches, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
const mountList = async () => {
    const component = (await import('@/components/resource/resource-list.vue')).default
    const wrapper = mount(component, { props: { schema }, global: { stubs } })
    await flushPromises()
    return wrapper
}
const tid = (w: any, id: string) => w.find(`[data-testid="${id}"]`)

describe('resource list on narrow screens', () => {
    beforeEach(() => {
        ajax.get.mockResolvedValue({ data: { data: [{ uuid: 'u-1', name: 'First', status: 'draft' }], meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 } } })
        ajax.request.mockResolvedValue({ data: {} })
    })
    afterEach(() => vi.unstubAllGlobals())

    it('shows stacked cards with every column and every row action, no table', async () => {
        media(true)
        ajax.get.mockResolvedValue({ data: { data: [{ uuid: 'u-1', name: 'First', status: 'draft' }], meta: { current_page: 1, per_page: 20, total: 45, last_page: 3 } } })
        const w = await mountList()
        expect(tid(w, 'resource-table').exists()).toBe(false)
        const card = tid(w, 'resource-card-u-1')
        expect(card.text()).toContain('First')
        expect(card.text()).toContain('text.x.status')
        expect(card.find('[data-action="confirm"]').exists()).toBe(true)
        expect(card.find('[data-action="delete"]').exists()).toBe(true)
        expect(tid(w, 'resource-cards-pagination').exists()).toBe(true)
    })

    it('runs a row action from a card', async () => {
        media(true)
        const w = await mountList()
        await tid(w, 'resource-card-u-1').find('[data-action="confirm"]').trigger('click')
        await flushPromises()
        expect(ajax.request.mock.calls[0][0]).toMatchObject({ url: 'x/items/u-1/quick', method: 'put' })
    })

    it('keeps the table on wide screens', async () => {
        media(false)
        const w = await mountList()
        expect(tid(w, 'resource-table').exists()).toBe(true)
        expect(tid(w, 'resource-card-u-1').exists()).toBe(false)
    })
})
