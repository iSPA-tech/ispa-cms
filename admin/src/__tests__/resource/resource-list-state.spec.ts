import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import { isUuid, listStateSchema } from '@/utils/resource/list-schema'

const ajax = vi.hoisted(() => ({ get: vi.fn(), request: vi.fn() }))
const nav = vi.hoisted(() => ({ route: { query: {} } as { query: Record<string, any> }, replaced: [] as any[] }))
vi.mock('@/ajax', () => ({ default: ajax }))
vi.mock('ant-design-vue', () => ({ message: { success: vi.fn(), error: vi.fn() } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({
    useRoute: () => nav.route,
    useRouter: () => ({
        replace: (to: { query: Record<string, any> }) => {
            nav.replaced.push(to)
            nav.route.query = to.query
        },
    }),
}))

const inputStub = {
    props: ['value'],
    emits: ['update:value'],
    template: '<input class="search" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
}
const selectStub = {
    props: ['value', 'options'],
    emits: ['update:value', 'change'],
    template:
        '<select class="filter" :value="value" @change="$emit(\'update:value\', $event.target.value); $emit(\'change\', $event.target.value)"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
}
const stubs = {
    'i-button': { template: '<button type="button"><slot /></button>' },
    'a-alert': { template: '<div />' },
    'a-skeleton': { template: '<div class="skeleton" />' },
    'a-input-search': inputStub,
    'a-select': selectStub,
    'a-table': {
        props: ['dataSource', 'columns'],
        emits: ['change'],
        template: '<div><button class="goto3" @click="$emit(\'change\', { current: 3, pageSize: 20 })" /></div>',
    },
}
const schema = {
    list: {
        endpoint: { url: '/api/v2/admin/x/items', adapter: 'ispa-list-v1' },
        search: { param: 'q' },
        filters: [{ field: 'status', label: 'x.status', options: [{ value: 'active', label: 'x.active' }] }],
        columns: [{ field: 'name', type: 'text', label: 'x.name' }],
    },
}
const echoPage = (_url: string, config: { params: { page: number } }) =>
    Promise.resolve({ data: { data: [], meta: { current_page: config.params.page, per_page: 20, total: 90, last_page: 5 } } })
const mountList = async () => {
    const { default: List } = await import('@/components/resource/resource-list.vue')
    const w = mount(List, { props: { schema }, global: { stubs } })
    await flushPromises()
    return w
}
const params = () => ajax.get.mock.calls.at(-1)![1].params

describe('listStateSchema', () => {
    it('builds page, q and one key per filter, skipping reserved keys', () => {
        const built = listStateSchema({ list: { search: {}, filters: [{ field: 'status' }, { field: 'record' }, { field: 'page' }] } })
        expect(built).toEqual({ page: 'int', q: 'string', status: 'string' })
        expect(listStateSchema({ list: {} })).toEqual({ page: 'int' })
        expect(listStateSchema(undefined)).toEqual({ page: 'int' })
    })

    it('validates uuids', () => {
        expect(isUuid('0d6f4c1e-8a3b-4f6e-9d2a-1b7c5e9a3f40')).toBe(true)
        expect(isUuid('u1')).toBe(false)
        expect(isUuid(['0d6f4c1e-8a3b-4f6e-9d2a-1b7c5e9a3f40'])).toBe(false)
    })
})

describe('ResourceList route-query state', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        vi.resetAllMocks()
        nav.route = reactive({ query: {} })
        nav.replaced = []
        ajax.get.mockImplementation(echoPage)
    })
    afterEach(() => vi.useRealTimers())

    it('takes the first request params from the route query', async () => {
        nav.route.query = { q: 'acme', status: 'active', page: '3' }
        const w = await mountList()
        expect(ajax.get).toHaveBeenCalledTimes(1)
        expect(params()).toEqual({ page: 3, per_page: 20, q: 'acme', status: 'active' })
        expect((w.find('input.search').element as HTMLInputElement).value).toBe('acme')
    })

    it('ignores a filter value the filter does not offer', async () => {
        nav.route.query = { status: 'bogus' }
        await mountList()
        expect(params()).toEqual({ page: 1, per_page: 20 })
    })

    it('commits the search to the query only after the debounce', async () => {
        const w = await mountList()
        nav.replaced = []
        await w.find('input.search').setValue('acme')
        vi.advanceTimersByTime(299)
        expect(nav.replaced).toEqual([])
        vi.advanceTimersByTime(1)
        await flushPromises()
        expect(nav.replaced[0]).toEqual({ query: { q: 'acme' } })
    })

    it('commits filter and page changes with replace and keeps foreign keys', async () => {
        nav.route.query = { record: '0d6f4c1e-8a3b-4f6e-9d2a-1b7c5e9a3f40' }
        const w = await mountList()
        await w.find('select.filter').setValue('active')
        await flushPromises()
        expect(nav.replaced.at(-1)).toEqual({ query: { record: '0d6f4c1e-8a3b-4f6e-9d2a-1b7c5e9a3f40', status: 'active' } })
        await w.find('.goto3').trigger('click')
        await flushPromises()
        expect(nav.replaced.at(-1).query).toMatchObject({ status: 'active', page: 3 })
    })

    it('reloads once when the address changes elsewhere (Back)', async () => {
        await mountList()
        const calls = ajax.get.mock.calls.length
        nav.route.query = { q: 'back', page: '2' }
        await flushPromises()
        expect(ajax.get).toHaveBeenCalledTimes(calls + 1)
        expect(params()).toEqual({ page: 2, per_page: 20, q: 'back' })
    })

    it('clears the debounce timer on unmount: no request fires afterwards', async () => {
        const w = await mountList()
        const calls = ajax.get.mock.calls.length
        await w.find('input.search').setValue('late')
        w.unmount()
        expect(vi.getTimerCount()).toBe(0)
        vi.advanceTimersByTime(1000)
        await flushPromises()
        expect(ajax.get).toHaveBeenCalledTimes(calls)
    })
})
