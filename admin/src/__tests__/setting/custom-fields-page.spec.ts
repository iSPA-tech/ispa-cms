import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import CustomFieldsPage from '../../../../cms/Core/admin/pages/setting/custom-fields.vue'
import CustomFieldForm from '@/components/custom-fields/custom-field-form.vue'
import { fieldLabel, movedOrder } from '@/libs/custom-fields'

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const state = vi.hoisted(() => ({ locale: 'vi' }))

vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params?: Record<string, unknown>) => (params ? `${key}|${JSON.stringify(params)}` : key),
        locale: { get value() { return state.locale } },
    }),
}))

const A = '11111111-1111-4111-8111-111111111111'
const B = '22222222-2222-4222-8222-222222222222'
const C = '33333333-3333-4333-8333-333333333333'
const field = (uuid: string, over: Record<string, unknown> = {}) => ({
    uuid,
    key: 'k' + uuid.slice(0, 2),
    type: 'text',
    labels: { vi: 'Nhãn ' + uuid.slice(0, 2), en: 'Label ' + uuid.slice(0, 2) },
    is_required: false,
    show_in_list: true,
    is_filterable: false,
    is_searchable: true,
    sort_order: 0,
    archived: false,
    ...over,
})
const resourcesBody = (items: unknown[], canManage = true) => ({ data: { data: items, meta: { can_manage: canManage } } })
const fieldsBody = (items: unknown[], canManage = true) => ({ data: { data: items, meta: { limit: 30, count: items.length, can_manage: canManage } } })
const resource = { key: 'content/posts', module: 'content', resource: 'posts', label: 'Bài viết' }

const slotOnly = (tag = 'div') => defineComponent({ setup: (_, { slots }) => () => h(tag, slots.default?.()) })
const stubs = {
    'a-skeleton': defineComponent({ setup: (_, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }) }),
    'a-alert': defineComponent({
        props: ['message'],
        setup: (props, { slots, attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }, [props.message, slots.action?.()]),
    }),
    'a-tag': slotOnly('span'),
    'a-select': defineComponent({
        props: ['value', 'options', 'disabled'],
        emits: ['update:value', 'change'],
        setup: (props, { emit, attrs }) => () =>
            h('div', { 'data-testid': attrs['data-testid'] }, (props.options || []).map((o: { value: string; label: string }) =>
                h('button', { 'data-testid': `opt-${o.value}`, onClick: () => { emit('update:value', o.value); emit('change', o.value) } }, o.label))),
    }),
    'a-popconfirm': defineComponent({
        props: ['title'],
        emits: ['confirm'],
        setup: (props, { slots, emit }) => () =>
            h('span', [slots.default?.(), h('i', { 'data-testid': 'confirm-text' }, props.title), h('button', { 'data-testid': 'confirm-yes', onClick: () => emit('confirm') })]),
    }),
    'a-table': defineComponent({
        props: ['columns', 'dataSource', 'locale'],
        setup: (props, { slots }) => () =>
            h('div', [
                h('div', { 'data-testid': 'col-keys' }, props.columns.map((c: { key: string }) => c.key).join(',')),
                props.dataSource.length === 0 ? h('p', { 'data-testid': 'table-empty' }, props.locale?.emptyText) : null,
                ...props.dataSource.map((record: { uuid: string }, index: number) =>
                    h('div', { 'data-testid': `row-${record.uuid}` }, props.columns.map((column: { key: string }) =>
                        h('div', { 'data-col': column.key }, slots.bodyCell?.({ column, record, index }))))),
            ]),
    }),
    'i-button': defineComponent({
        props: ['disabled'],
        emits: ['click'],
        setup: (props, { slots, attrs, emit }) => () =>
            h('button', { disabled: props.disabled, 'data-testid': attrs['data-testid'], 'aria-label': attrs['aria-label'], onClick: () => emit('click') }, slots.default?.()),
    }),
}

const answer = (resources: unknown[], fields: unknown[], canManage = true) => {
    ajaxMock.get.mockImplementation((url: string) =>
        Promise.resolve(url === 'custom-field-resources' ? resourcesBody(resources, canManage) : fieldsBody(fields, canManage)))
}
const mountPage = async () => {
    const wrapper = mount(CustomFieldsPage, { global: { stubs } })
    await flushPromises()
    return wrapper
}
const pick = async (wrapper: Awaited<ReturnType<typeof mountPage>>) => {
    await wrapper.get('[data-testid="opt-content/posts"]').trigger('click')
    await flushPromises()
}

describe('custom fields page', () => {
    beforeEach(() => {
        ajaxMock.get.mockReset()
        ajaxMock.post.mockReset()
        state.locale = 'vi'
    })

    it('shows a loading state, then the picker with human labels only', async () => {
        let release: (v: unknown) => void = () => {}
        ajaxMock.get.mockReturnValue(new Promise((r) => { release = r }))
        const wrapper = mount(CustomFieldsPage, { global: { stubs } })
        await nextTick()
        expect(wrapper.find('[data-testid="cf-resources-loading"]').exists()).toBe(true)
        release(resourcesBody([resource, { ...resource, key: 'content/pages', resource: 'pages', label: 'Trang' }]))
        await flushPromises()
        expect(wrapper.find('[data-testid="cf-resources-loading"]').exists()).toBe(false)
        const text = wrapper.get('[data-testid="cf-resource-select"]').text()
        expect(text).toContain('Bài viết')
        expect(text).not.toContain('content/posts')
        expect(wrapper.find('[data-testid="cf-fields-box"]').exists()).toBe(false)
    })

    it('says so when no resource takes fields', async () => {
        answer([], [])
        const wrapper = await mountPage()
        expect(wrapper.find('[data-testid="cf-no-resources"]').exists()).toBe(true)
    })

    it('shows a retry when the resource list fails and reloads on click', async () => {
        ajaxMock.get.mockRejectedValueOnce({ response: { status: 500 } })
        const wrapper = await mountPage()
        expect(wrapper.find('[data-testid="cf-resources-error"]').exists()).toBe(true)
        answer([resource], [])
        await wrapper.get('[data-testid="cf-resources-retry"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid="cf-resources-error"]').exists()).toBe(false)
    })

    it('opens the only resource at once', async () => {
        answer([resource], [field(A)])
        const wrapper = await mountPage()
        expect(ajaxMock.get).toHaveBeenCalledWith('resources/content/posts/custom-fields', expect.anything())
        expect(wrapper.find(`[data-testid="row-${A}"]`).exists()).toBe(true)
    })

    it('lists fields in the current language with plain type names and marks archived rows', async () => {
        answer([resource, { ...resource, key: 'content/pages', resource: 'pages', label: 'Trang' }], [field(A, { type: 'select' }), field(B, { archived: true, labels: { vi: '', en: 'Only English' } })])
        const wrapper = await mountPage()
        await pick(wrapper)
        const rowA = wrapper.get(`[data-testid="row-${A}"]`).text()
        expect(rowA).toContain('Nhãn 11')
        expect(rowA).toContain('text.custom_fields.type.select')
        expect(rowA).not.toContain(A)
        expect(wrapper.get(`[data-testid="row-${B}"]`).text()).toContain('Only English')
        expect(wrapper.find(`[data-testid="cf-archived-${B}"]`).exists()).toBe(true)
        expect(wrapper.find(`[data-testid="cf-archived-${A}"]`).exists()).toBe(false)
    })

    it('shows a field as filterable when its type is filtered without the flag', async () => {
        const typeFiltered = ['select', 'multiselect', 'boolean', 'integer', 'date']
        const ids = ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0001', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0002', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0003', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0004', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0005']
        answer([resource], [...typeFiltered.map((type, i) => field(ids[i], { type })), field(B, { type: 'text' }), field(C, { type: 'text', is_filterable: true })])
        const wrapper = await mountPage()
        const cell = (uuid: string) => wrapper.get(`[data-testid="row-${uuid}"] [data-col="is_filterable"]`).text()
        for (const id of ids) expect(cell(id)).toBe('text.custom_fields.yes')
        expect(cell(B)).toBe('text.custom_fields.no')
        expect(cell(C)).toBe('text.custom_fields.yes')
    })

    it('shows the label in English when the language is English', async () => {
        state.locale = 'en'
        answer([resource], [field(A)])
        const wrapper = await mountPage()
        expect(wrapper.get(`[data-testid="row-${A}"]`).text()).toContain('Label 11')
    })

    it('shows the empty text for a resource without fields', async () => {
        answer([resource], [])
        const wrapper = await mountPage()
        expect(wrapper.get('[data-testid="table-empty"]').text()).toBe('text.custom_fields.empty')
    })

    it('shows a field-list error with retry', async () => {
        ajaxMock.get.mockImplementation((url: string) =>
            url === 'custom-field-resources' ? Promise.resolve(resourcesBody([resource])) : Promise.reject({ response: { status: 500 } }))
        const wrapper = await mountPage()
        expect(wrapper.find('[data-testid="cf-fields-error"]').exists()).toBe(true)
        answer([resource], [field(A)])
        await wrapper.get('[data-testid="cf-fields-retry"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid="cf-fields-error"]').exists()).toBe(false)
        expect(wrapper.find(`[data-testid="row-${A}"]`).exists()).toBe(true)
    })

    it('renders no manage controls without custom_field.manage', async () => {
        answer([resource], [field(A), field(B)], false)
        const wrapper = await mountPage()
        expect(wrapper.find(`[data-testid="cf-up-${B}"]`).exists()).toBe(false)
        expect(wrapper.find(`[data-testid="cf-archive-${A}"]`).exists()).toBe(false)
        expect(wrapper.find('[data-testid="confirm-yes"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="cf-add"]').exists()).toBe(false)
        expect(wrapper.find(`[data-testid="cf-edit-${A}"]`).exists()).toBe(false)
        expect(wrapper.find('[data-testid="cf-purge"]').exists()).toBe(false)
        expect(wrapper.find(`[data-testid="row-${A}"]`).exists()).toBe(true)
    })

    it('sends the new order once as a uuid list and shows the refreshed list', async () => {
        answer([resource], [field(A), field(B), field(C)])
        let finish: (v: unknown) => void = () => {}
        ajaxMock.post.mockReturnValue(new Promise((r) => { finish = r }))
        const wrapper = await mountPage()
        await wrapper.get(`[data-testid="cf-down-${A}"]`).trigger('click')
        await wrapper.get(`[data-testid="cf-down-${A}"]`).trigger('click')
        finish(fieldsBody([field(B), field(A), field(C)]))
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
        expect(ajaxMock.post).toHaveBeenCalledWith('resources/content/posts/custom-fields/reorder', { uuids: [B, A, C] }, expect.anything())
        expect(wrapper.get('[data-testid="cf-notice"]').text()).toContain('text.custom_fields.reordered')
        const rows = wrapper.findAll('[data-testid^="row-"]').map((r) => r.attributes('data-testid'))
        expect(rows).toEqual([`row-${B}`, `row-${A}`, `row-${C}`])
    })

    it('disables moving the first row up and the last row down', async () => {
        answer([resource], [field(A), field(B)])
        const wrapper = await mountPage()
        expect(wrapper.get(`[data-testid="cf-up-${A}"]`).attributes('disabled')).toBeDefined()
        expect(wrapper.get(`[data-testid="cf-down-${B}"]`).attributes('disabled')).toBeDefined()
        expect(wrapper.get(`[data-testid="cf-down-${A}"]`).attributes('disabled')).toBeUndefined()
    })

    it('archives only after the confirmation and sends one request on a double click', async () => {
        answer([resource], [field(A)])
        ajaxMock.post.mockResolvedValue({ data: { data: field(A, { archived: true }) } })
        const wrapper = await mountPage()
        expect(wrapper.get('[data-testid="confirm-text"]').text()).toBe('text.custom_fields.archive_confirm')
        expect(ajaxMock.post).not.toHaveBeenCalled()
        const yes = wrapper.get('[data-testid="confirm-yes"]')
        await yes.trigger('click')
        await yes.trigger('click')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
        expect(ajaxMock.post).toHaveBeenCalledWith(`resources/content/posts/custom-fields/${A}/archive`, {}, expect.anything())
    })

    it('restores an archived field after the confirmation', async () => {
        answer([resource], [field(A, { archived: true })])
        ajaxMock.post.mockResolvedValue({ data: { data: field(A) } })
        const wrapper = await mountPage()
        expect(wrapper.find(`[data-testid="cf-archive-${A}"]`).exists()).toBe(false)
        await wrapper.get('[data-testid="confirm-yes"]').trigger('click')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledWith(`resources/content/posts/custom-fields/${A}/restore`, {}, expect.anything())
    })

    it('shows a plain error and re-enables the controls when a write fails', async () => {
        answer([resource], [field(A), field(B)])
        ajaxMock.post.mockRejectedValue({ response: { status: 422 } })
        const wrapper = await mountPage()
        await wrapper.get(`[data-testid="cf-down-${A}"]`).trigger('click')
        await flushPromises()
        expect(wrapper.get('[data-testid="cf-action-error"]').text()).toContain('text.custom_fields.action_failed')
        expect(wrapper.get(`[data-testid="cf-down-${A}"]`).attributes('disabled')).toBeUndefined()
    })

    it('leaves no timer behind after unmount', async () => {
        const timers = vi.spyOn(globalThis, 'setInterval')
        answer([resource], [field(A)])
        const wrapper = await mountPage()
        wrapper.unmount()
        expect(timers).not.toHaveBeenCalled()
        timers.mockRestore()
    })
})

describe('custom fields page purge switch', () => {
    it('turns the purge box on now that the server takes a backup first (Q-P8-2)', async () => {
        ajaxMock.get.mockReset()
        answer([resource], [field(A)])
        const wrapper = await mountPage()
        await pick(wrapper)
        const form = wrapper.findComponent(CustomFieldForm)
        expect(form.exists()).toBe(true)
        expect(form.props('backupReady')).toBe(true)
    })
})

describe('custom field helpers', () => {
    it('picks the label of the language, then the other one, then the key', () => {
        expect(fieldLabel({ labels: { vi: 'A', en: 'B' }, key: 'k' }, 'en')).toBe('B')
        expect(fieldLabel({ labels: { vi: 'A', en: '' }, key: 'k' }, 'en')).toBe('A')
        expect(fieldLabel({ labels: {}, key: 'k' }, 'vi')).toBe('k')
    })

    it('moves one entry without changing the input and refuses to leave the list', () => {
        const list = [{ uuid: 'a' }, { uuid: 'b' }, { uuid: 'c' }]
        expect(movedOrder(list, 0, 1)).toEqual(['b', 'a', 'c'])
        expect(movedOrder(list, 0, -1)).toBeNull()
        expect(movedOrder(list, 2, 1)).toBeNull()
        expect(list.map((f) => f.uuid)).toEqual(['a', 'b', 'c'])
    })
})
