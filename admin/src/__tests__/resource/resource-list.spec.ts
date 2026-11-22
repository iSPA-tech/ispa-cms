import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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
import { registerActionHook, unregisterActionHook } from '@/utils/resource/action-hooks'

const ajax = vi.hoisted(() => ({ get: vi.fn(), request: vi.fn() }))
const msg = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajax }))
vi.mock('ant-design-vue', () => ({ message: msg }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const loadComponent = () => import('@/components/resource/resource-list.vue')

const inputStub = {
    props: ['value', 'placeholder'],
    emits: ['update:value'],
    template: '<input class="search" :value="value" :placeholder="placeholder" @input="$emit(\'update:value\', $event.target.value)" />',
}
const selectStub = {
    props: ['value', 'options'],
    emits: ['update:value', 'change'],
    template:
        '<select class="filter" :value="value" @change="$emit(\'update:value\', $event.target.value); $emit(\'change\', $event.target.value)"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
}
const stubs = {
    'i-button': { emits: ['click'], template: '<button type="button" class="ibtn" @click="$emit(\'click\', $event)"><slot /></button>' },
    'a-alert': { props: ['message'], template: '<div role="alert">{{ message }}<slot name="action" /></div>' },
    'a-skeleton': { template: '<div class="skeleton" />' },
    'a-input-search': inputStub,
    'a-input': inputStub,
    'a-select': selectStub,
    'a-table': {
        props: ['dataSource', 'columns', 'locale', 'pagination'],
        emits: ['change'],
        template:
            '<div><button class="goto2" @click="$emit(\'change\', { current: 2, pageSize: 20 })">p2</button><table><tbody><tr v-for="r in dataSource" :key="r.uuid"><td v-for="c in columns" :key="c.key"><slot name="bodyCell" :column="c" :record="r" /></td></tr></tbody></table><p v-if="!dataSource.length" class="empty">{{ locale.emptyText }}</p></div>',
    },
}

const schema = (list: Record<string, unknown> = {}) => ({
    list: {
        endpoint: { url: '/api/v2/admin/helpdesk/organizations', adapter: 'ispa-list-v1' },
        search: { param: 'q', placeholder: 'text.x.search' },
        filters: [{ field: 'status', type: 'enum', label: 'text.x.status', options: [{ value: 'active', label: 'text.x.active' }] }],
        columns: [
            { field: 'name', type: 'text', label: 'text.x.name', link: 'detail' },
            { field: 'status', type: 'enum', label: 'text.x.status', options: [{ value: 'active', label: 'text.x.active' }] },
        ],
        ...list,
    },
})
const body = (rows: unknown[], meta = {}) => ({ data: { data: rows, meta: { current_page: 1, per_page: 20, total: rows.length, last_page: 1, ...meta } } })
const rows = (...names: string[]) => names.map((name, i) => ({ uuid: `u-${name}-${i}`, name, status: 'active' }))
const mountList = async (s = schema()) => {
    const wrapper = mount((await loadComponent()).default, { props: { schema: s }, global: { stubs } })
    await flushPromises()
    return wrapper
}
const params = (call = -1) => ajax.get.mock.calls.at(call)![1].params

describe('ResourceList', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        vi.resetAllMocks()
        nav.route = reactive({ query: {} })
        nav.replaced = []
        ajax.get.mockResolvedValue(body(rows('Acme')))
    })
    afterEach(() => vi.useRealTimers())

    it('requests page 1 with the default page size and no search', async () => {
        await mountList()
        expect(ajax.get).toHaveBeenCalledTimes(1)
        expect(ajax.get.mock.calls[0][0]).toBe('helpdesk/organizations')
        expect(params()).toEqual({ page: 1, per_page: 20 })
    })

    it('debounces search by 300 ms and resets the page', async () => {
        const wrapper = await mountList()
        await wrapper.find('input.search').setValue('ac')
        await wrapper.find('input.search').setValue('acme')
        vi.advanceTimersByTime(299)
        expect(ajax.get).toHaveBeenCalledTimes(1)
        vi.advanceTimersByTime(1)
        await flushPromises()
        expect(ajax.get).toHaveBeenCalledTimes(2)
        expect(params()).toEqual({ page: 1, per_page: 20, q: 'acme' })
    })

    it('applies a filter, resets the page, and "all" clears it', async () => {
        const wrapper = await mountList()
        await wrapper.find('.goto2').trigger('click')
        await flushPromises()
        expect(params().page).toBe(2)
        await wrapper.find('select.filter').setValue('active')
        await flushPromises()
        expect(params()).toEqual({ page: 1, per_page: 20, status: 'active' })
        await wrapper.find('select.filter').setValue('')
        await flushPromises()
        expect(params()).toEqual({ page: 1, per_page: 20 })
    })

    it('requests the page chosen in the table', async () => {
        const wrapper = await mountList()
        await wrapper.find('.goto2').trigger('click')
        await flushPromises()
        expect(params()).toEqual({ page: 2, per_page: 20 })
    })

    it('ignores a stale response that resolves after a newer one', async () => {
        let resolveFirst: (v: unknown) => void = () => {}
        ajax.get.mockReset()
        ajax.get.mockImplementationOnce(() => new Promise((r) => (resolveFirst = r)))
        ajax.get.mockResolvedValueOnce(body(rows('Second')))
        const wrapper = mount((await loadComponent()).default, { props: { schema: schema() }, global: { stubs } })
        await wrapper.find('select.filter').setValue('active')
        await flushPromises()
        resolveFirst(body(rows('First')))
        await flushPromises()
        expect(wrapper.text()).toContain('Second')
        expect(wrapper.text()).not.toContain('First')
    })

    it('shows a skeleton on first load only', async () => {
        let resolve: (v: unknown) => void = () => {}
        ajax.get.mockReset()
        ajax.get.mockImplementationOnce(() => new Promise((r) => (resolve = r)))
        const wrapper = mount((await loadComponent()).default, { props: { schema: schema() }, global: { stubs } })
        await flushPromises()
        expect(wrapper.find('.skeleton').exists()).toBe(true)
        resolve(body(rows('Acme')))
        await flushPromises()
        expect(wrapper.find('.skeleton').exists()).toBe(false)
        ajax.get.mockReturnValueOnce(new Promise(() => {}))
        await wrapper.find('select.filter').setValue('active')
        await flushPromises()
        expect(wrapper.find('.skeleton').exists()).toBe(false)
    })

    it('shows an error alert and retries', async () => {
        ajax.get.mockReset()
        ajax.get.mockRejectedValueOnce(new Error('boom'))
        ajax.get.mockResolvedValueOnce(body(rows('Acme')))
        const wrapper = mount((await loadComponent()).default, { props: { schema: schema() }, global: { stubs } })
        await flushPromises()
        expect(wrapper.find('[role=alert]').text()).toContain('text.resource.load_failed')
        const retry = wrapper.findAll('button').find((b) => b.text() === 'text.resource.retry')!
        await retry.trigger('click')
        await flushPromises()
        expect(ajax.get).toHaveBeenCalledTimes(2)
        expect(wrapper.find('[role=alert]').exists()).toBe(false)
        expect(wrapper.text()).toContain('Acme')
    })

    it('shows the empty text and a translated search placeholder', async () => {
        ajax.get.mockResolvedValue(body([]))
        const wrapper = await mountList()
        expect(wrapper.find('.empty').text()).toBe('text.resource.empty')
        expect(wrapper.find('input.search').attributes('placeholder')).toBe('text.x.search')
    })

    it('hides search when the schema has none', async () => {
        const wrapper = await mountList(schema({ search: undefined }))
        expect(wrapper.find('input.search').exists()).toBe(false)
    })

    it('emits open with the record when the link column is clicked', async () => {
        const wrapper = await mountList()
        await wrapper.find('button.resource-link').trigger('click')
        expect(wrapper.emitted('open')![0][0]).toMatchObject({ name: 'Acme' })
    })

    it('translates enum option labels in cells', async () => {
        const wrapper = await mountList()
        expect(wrapper.text()).toContain('text.x.active')
    })

    it('drops a pending debounce on unmount', async () => {
        const wrapper = await mountList()
        await wrapper.find('input.search').setValue('acme')
        wrapper.unmount()
        vi.advanceTimersByTime(1000)
        await flushPromises()
        expect(ajax.get).toHaveBeenCalledTimes(1)
    })

    it('limits the search input to 100 characters', async () => {
        const wrapper = await mountList()
        expect(wrapper.find('input.search').attributes('maxlength')).toBe('100')
    })

    it('takes column widths from the schema only', async () => {
        const wide = schema({
            columns: [
                { field: 'name', type: 'text', label: 'a', link: 'detail' },
                { field: 'status', type: 'status', label: 'b', width: 130 },
                { field: 'created_at', type: 'datetime', label: 'c', width: 160 },
                { field: 'other', type: 'enum', label: 'd' },
            ],
        })
        const wrapper = await mountList(wide)
        const columns = wrapper.findComponent(stubs['a-table']).props('columns') as { key: string; width?: number }[]
        expect(columns.map((c) => c.width)).toEqual([undefined, 130, 160, undefined])
    })

    it('labels both range inputs From and To with the field label, not the bare label', async () => {
        const rangeStubs = {
            ...stubs,
            'a-input-number': { props: ['value', 'placeholder'], template: '<input class="num" :placeholder="placeholder" />' },
            'a-date-picker': { props: ['value', 'placeholder'], template: '<input class="date" :placeholder="placeholder" />' },
        }
        for (const [type, selector] of [['number', 'input.num'], ['date', 'input.date']] as const) {
            const s = schema({ filters: [{ field: 'custom_fields.size', type, label: 'Employees', custom: true, range: true, label_text: { vi: 'Số nhân viên', en: 'Employees' } }] })
            const w = mount((await loadComponent()).default, { props: { schema: s }, global: { stubs: rangeStubs } })
            await flushPromises()
            const placeholders = w.findAll(selector).map((i) => i.attributes('placeholder'))
            expect(placeholders).toEqual(['text.resource.range_from', 'text.resource.range_to'])
        }
    })

    it('leads a filter with its schema allLabel entry, which sends no param', async () => {
        const withAll = schema({
            filters: [{ field: 'status', type: 'enum', label: 'text.x.status', allLabel: 'text.x.all_statuses', options: [{ value: 'active', label: 'text.x.active' }] }],
        })
        const wrapper = await mountList(withAll)
        expect(wrapper.findAll('select.filter option').map((o) => o.text())).toEqual(['text.x.all_statuses', 'text.x.active'])
        expect((wrapper.find('select.filter').element as HTMLSelectElement).value).toBe('')
        await wrapper.find('select.filter').setValue('active')
        await flushPromises()
        expect(params()).toEqual({ page: 1, per_page: 20, status: 'active' })
        await wrapper.find('select.filter').setValue('')
        await flushPromises()
        expect(params()).toEqual({ page: 1, per_page: 20 })
    })

    it('falls back to the generic all entry without allLabel', async () => {
        const wrapper = await mountList()
        expect(wrapper.findAll('select.filter option').map((o) => o.text())[0]).toBe('text.resource.all')
    })

    it('uses the empty and load_failed labels of the schema', async () => {
        ajax.get.mockResolvedValue(body([]))
        const labelled = schema({ labels: { empty: 'text.y.empty', load_failed: 'text.y.failed' } })
        const wrapper = await mountList(labelled)
        expect(wrapper.find('.empty').text()).toBe('text.y.empty')
        ajax.get.mockRejectedValueOnce(new Error('x'))
        const failing = await mountList(labelled)
        expect(failing.find('[role=alert]').text()).toContain('text.y.failed')
    })

    it('shows status and enum labels from the filter options when the column has none', async () => {
        const s = schema({
            filters: [{ field: 'status', type: 'status', label: 'l', options: [{ value: 'active', label: 'text.x.active' }] }],
            columns: [
                { field: 'name', type: 'text', label: 'a', link: 'detail' },
                { field: 'status', type: 'status', label: 'b' },
            ],
        })
        const wrapper = await mountList(s)
        const columns = wrapper.findComponent(stubs['a-table']).props('columns') as { field: { options: { label: string }[] } }[]
        expect(columns[1].field.options.map((o) => o.label)).toEqual(['text.x.active'])
    })

    it('requests the endpoint `path` the schema builder emits (not only the legacy `url`)', async () => {
        ajax.get.mockResolvedValue(body(rows('A')))
        await mountList(schema({ endpoint: { route: 'r', path: 'helpdesk/organizations', method: 'GET', adapter: 'ispa-list-v1' } }))
        expect(ajax.get.mock.calls[0][0]).toBe('helpdesk/organizations')
    })

    describe('row actions, selection and bulk actions', () => {
        const selTable = {
            props: ['dataSource', 'columns', 'locale', 'pagination', 'rowSelection'],
            emits: ['change'],
            template:
                '<div class="tbl" :data-total="pagination.total" :data-current="pagination.current" :data-size="pagination.pageSize"><table><tbody><tr v-for="r in dataSource" :key="r.uuid" :data-row="r.uuid"><td v-if="rowSelection"><input type="checkbox" class="sel" :checked="rowSelection.selectedRowKeys.includes(r.uuid)" @change="rowSelection.onChange(rowSelection.selectedRowKeys.includes(r.uuid) ? rowSelection.selectedRowKeys.filter((k) => k !== r.uuid) : [...rowSelection.selectedRowKeys, r.uuid])" /></td><td v-for="c in columns" :key="c.key"><slot name="bodyCell" :column="c" :record="r" /></td></tr></tbody></table></div>',
        }
        const popStub = {
            props: ['title'],
            emits: ['confirm'],
            template: '<span class="pop" :data-title="title"><slot /><button type="button" class="pop-ok" @click="$emit(\'confirm\')" /></span>',
        }
        const allStubs = { ...stubs, 'a-table': selTable, 'a-popconfirm': popStub }
        const actions = [
            { name: 'make_current', label: 'text.x.current', endpoint: { method: 'POST', path: '/api/v2/admin/x/items/:uuid/current' }, visible: { field: 'status', op: 'eq', value: 'active' } },
            { name: 'delete', label: 'action.delete', danger: true, refresh: 'list', endpoint: { method: 'DELETE', path: '/api/v2/admin/x/items/:uuid' } },
            { name: 'bulk_delete', label: 'text.x.bulk_delete', danger: true, bulk: true, refresh: 'list', endpoint: { method: 'POST', path: '/api/v2/admin/x/items/bulk-delete' } },
        ]
        const full = (over: Record<string, unknown> = {}) => ({ ...schema({ selectable: true, rowActions: ['make_current', 'delete'], bulkActions: ['bulk_delete'] }), actions, ...over })
        const mountFull = async (s: any = full()) => {
            const w = mount((await loadComponent()).default, { props: { schema: s }, global: { stubs: allStubs } })
            await flushPromises()
            return w
        }
        const mixed = () =>
            ajax.get.mockResolvedValue(body([{ uuid: 'a', name: 'A', status: 'active' }, { uuid: 'b', name: 'B', status: 'inactive' }]))
        afterEach(() => unregisterActionHook('afterIt'))

        it('shows a row action only for rows where its visible condition holds', async () => {
            mixed()
            const w = await mountFull()
            expect(w.find('[data-row="a"] [data-action="make_current"]').exists()).toBe(true)
            expect(w.find('[data-row="b"] [data-action="make_current"]').exists()).toBe(false)
            expect(w.find('[data-row="b"] [data-action="delete"]').exists()).toBe(true)
        })

        it('shows no selection, row buttons or bulk bar for a schema without the new keys', async () => {
            const w = await mountFull(schema())
            expect(w.find('input.sel').exists()).toBe(false)
            expect(w.find('[data-action]').exists()).toBe(false)
            expect(w.find('[data-test="bulk-bar"]').exists()).toBe(false)
        })

        it('runs a plain row action with the record params and reloads the list', async () => {
            mixed()
            ajax.request.mockResolvedValue({ data: {} })
            const w = await mountFull()
            await w.find('[data-row="a"] [data-action="make_current"]').trigger('click')
            await flushPromises()
            expect(ajax.request.mock.calls[0][0]).toMatchObject({ url: 'x/items/a/current', method: 'post' })
            expect(msg.success).toHaveBeenCalledWith('text.resource.saved')
            expect(ajax.get).toHaveBeenCalledTimes(2)
        })

        it('delete: confirms first, then calls the endpoint and reloads the list', async () => {
            mixed()
            ajax.request.mockResolvedValue({ data: {} })
            const w = await mountFull()
            expect(w.find('[data-row="a"] .pop').attributes('data-title')).toBe('action.delete')
            expect(ajax.request).not.toHaveBeenCalled()
            expect(w.find('[data-row="a"] [data-action="delete"]').attributes('variant')).toBe('danger')
            await w.find('[data-row="a"] .pop-ok').trigger('click')
            await flushPromises()
            expect(ajax.request.mock.calls[0][0]).toMatchObject({ url: 'x/items/a', method: 'delete' })
            expect(ajax.get).toHaveBeenCalledTimes(2)
        })

        it('bulk delete sends the selected uuids, reloads and clears the selection', async () => {
            mixed()
            ajax.request.mockResolvedValue({ data: {} })
            const w = await mountFull()
            expect(w.find('[data-test="bulk-bar"]').exists()).toBe(false)
            const boxes = w.findAll('input.sel')
            await boxes[0].trigger('change')
            await boxes[1].trigger('change')
            expect(w.find('[data-test="bulk-bar"]').text()).toContain('text.selected')
            expect(w.find('[data-test="bulk-bar"]').text()).toContain('2')
            await w.find('[data-test="bulk-bar"] .pop-ok').trigger('click')
            await flushPromises()
            expect(ajax.request.mock.calls[0][0]).toMatchObject({ url: 'x/items/bulk-delete', method: 'post', data: { uuids: ['a', 'b'] } })
            expect(ajax.get).toHaveBeenCalledTimes(2)
            expect(w.find('[data-test="bulk-bar"]').exists()).toBe(false)
            expect(w.findAll('input.sel').every((box) => !(box.element as HTMLInputElement).checked)).toBe(true)
        })

        it('keeps the selection when a bulk action fails and shows the server message (409 guard)', async () => {
            mixed()
            ajax.request.mockRejectedValue({ response: { status: 409, data: { message: 'Has children' } } })
            const w = await mountFull()
            await w.findAll('input.sel')[0].trigger('change')
            await w.find('[data-test="bulk-bar"] .pop-ok').trigger('click')
            await flushPromises()
            expect(msg.error).toHaveBeenCalledWith('Has children')
            expect(ajax.get).toHaveBeenCalledTimes(1)
            expect(w.find('[data-test="bulk-bar"]').exists()).toBe(true)
        })

        it('shows the generic failure text when the server gives no message', async () => {
            mixed()
            ajax.request.mockRejectedValue(new Error('x'))
            const w = await mountFull()
            await w.find('[data-row="a"] [data-action="make_current"]').trigger('click')
            await flushPromises()
            expect(msg.error).toHaveBeenCalledWith('text.resource.save_failed')
        })

        it('runs the after hook once after success and never after failure', async () => {
            const hook = vi.fn()
            registerActionHook('afterIt', hook)
            mixed()
            ajax.request.mockRejectedValueOnce(new Error('x')).mockResolvedValueOnce({ data: {} })
            const withHook = full({ actions: actions.map((a) => (a.name === 'make_current' ? { ...a, after: 'afterIt' } : a)) })
            const w = await mountFull(withHook)
            await w.find('[data-row="a"] [data-action="make_current"]').trigger('click')
            await flushPromises()
            expect(hook).not.toHaveBeenCalled()
            await w.find('[data-row="a"] [data-action="make_current"]').trigger('click')
            await flushPromises()
            expect(hook).toHaveBeenCalledTimes(1)
            expect(hook.mock.calls[0][0]).toMatchObject({ action: { name: 'make_current' }, record: { uuid: 'a' } })
        })

        it('a failing hook shows a message, but the refresh is still applied', async () => {
            registerActionHook('afterIt', () => {
                throw new Error('Hook broke')
            })
            mixed()
            ajax.request.mockResolvedValue({ data: {} })
            const withHook = full({ actions: actions.map((a) => (a.name === 'make_current' ? { ...a, after: 'afterIt' } : a)) })
            const w = await mountFull(withHook)
            await w.find('[data-row="a"] [data-action="make_current"]').trigger('click')
            await flushPromises()
            expect(msg.error).toHaveBeenCalledWith('Hook broke')
            expect(ajax.get).toHaveBeenCalledTimes(2)
        })

        it('maps the nested laravel paginator to rows, page, per page and total', async () => {
            ajax.get.mockResolvedValue({ data: { data: { data: [{ uuid: 'a', name: 'A', status: 'active' }], current_page: 2, per_page: 10, total: 11, last_page: 2 } } })
            const w = await mountFull(schema({ endpoint: { path: '/api/v2/admin/x/items', adapter: 'laravel-paginate-v1' } }))
            expect(w.find('[data-row="a"]').exists()).toBe(true)
            const table = w.find('.tbl')
            expect(table.attributes('data-total')).toBe('11')
            expect(table.attributes('data-current')).toBe('2')
            expect(table.attributes('data-size')).toBe('10')
        })

        it('steps back to the last page when a delete emptied the current one', async () => {
            ajax.get
                .mockResolvedValueOnce(body([{ uuid: 'a', name: 'A', status: 'active' }], { current_page: 2, total: 21, last_page: 2 }))
                .mockResolvedValueOnce(body([], { current_page: 2, total: 20, last_page: 1 }))
                .mockResolvedValueOnce(body([{ uuid: 'z', name: 'Z', status: 'active' }], { current_page: 1, total: 20, last_page: 1 }))
            ajax.request.mockResolvedValue({ data: {} })
            const w = await mountFull()
            await w.find('[data-row="a"] .pop-ok').trigger('click')
            await flushPromises()
            expect(params().page).toBe(1)
            expect(w.find('[data-row="z"]').exists()).toBe(true)
        })
    })
})
