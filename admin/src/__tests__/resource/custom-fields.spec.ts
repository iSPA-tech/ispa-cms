import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { getPath, isSafePath, mapErrorKeys, nestPaths, safeEntries, setPath } from '@/utils/resource/field-path'
import { customHelp, customLabel, filterParams, filterSlots } from '@/utils/resource/custom-fields'

const nav = vi.hoisted(() => ({ route: { query: {} } as { query: Record<string, any> } }))
vi.mock('vue-router', () => ({
    useRoute: () => nav.route,
    useRouter: () => ({
        replace: (to: { query: Record<string, any> }) => {
            nav.route.query = to.query
        },
    }),
}))
const ajax = vi.hoisted(() => ({ get: vi.fn(), request: vi.fn() }))
const msg = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
const i18n = vi.hoisted(() => ({ locale: { value: 'vi' } as { value: string } }))
vi.mock('@/ajax', () => ({ default: ajax }))
vi.mock('ant-design-vue', () => ({ message: msg }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: i18n.locale }) }))

import ResourceForm from '@/components/resource/resource-form.vue'
import ResourceDetail from '@/components/resource/resource-detail.vue'
import ResourceList from '@/components/resource/resource-list.vue'

const tier = { field: 'custom_fields.tier', type: 'text', label: 'text.custom.tier', custom: true, rules: {}, label_text: { vi: 'Hạng', en: 'Tier' }, help_text: 'Chọn hạng khách' }
const grade = {
    field: 'custom_fields.grade',
    type: 'enum',
    label: 'text.custom.grade',
    custom: true,
    rules: {},
    label_text: { vi: 'Xếp loại' },
    options: [
        { value: 'a', label: 'k.a', label_text: { vi: 'Loại A', en: 'Grade A' } },
        { value: 'b', label: 'k.b', label_text: { vi: 'Loại B', en: 'Grade B' } },
    ],
}
const tags = { ...grade, field: 'custom_fields.tags', multiple: true, label_text: { vi: 'Nhãn', en: 'Tags' } }
const signed = { field: 'custom_fields.signed', type: 'date', label: 'text.custom.signed', custom: true, rules: {}, label_text: { en: 'Signed on' } }
const customFields = [tier, grade, tags, signed]

const stubs = {
    'i-button': { props: ['nativeType'], emits: ['click'], template: '<button :type="nativeType || \'button\'" @click="$emit(\'click\', $event)"><slot /></button>' },
    'a-input': { props: ['value'], emits: ['update:value'], template: '<input class="inp" :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
    'a-select': {
        props: ['value', 'options', 'mode'],
        emits: ['update:value', 'change'],
        template:
            '<select class="sel" :data-mode="mode" @change="$emit(\'update:value\', mode ? [$event.target.value] : $event.target.value); $emit(\'change\')"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
    },
    'a-date-picker': { props: ['value'], emits: ['update:value'], template: '<input class="date" :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
    'a-input-number': { props: ['value'], emits: ['update:value'], template: '<input class="num" :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
    'a-switch': { template: '<span />' },
    'a-skeleton': { template: '<div />' },
    'a-alert': { template: '<div role="alert" />' },
    'a-tag': { template: '<span><slot /></span>' },
    'a-input-search': { template: '<input />' },
    'a-table': {
        props: ['dataSource', 'columns'],
        template:
            '<table><thead><tr><th v-for="c in columns" :key="c.key">{{ c.title }}</th></tr></thead><tbody><tr v-for="r in dataSource" :key="r.uuid"><td v-for="c in columns" :key="c.key"><slot name="bodyCell" :column="c" :record="r" /></td></tr></tbody></table>',
    },
}

describe('field paths', () => {
    it('reads and writes dotted paths and nests flat values', () => {
        expect(getPath({ custom_fields: { tier: 'gold' } }, 'custom_fields.tier')).toBe('gold')
        expect(getPath({ 'custom_fields.tier': 'flat' }, 'custom_fields.tier')).toBe('flat')
        expect(getPath({}, 'custom_fields.tier')).toBeUndefined()
        expect(nestPaths({ name: 'x', 'custom_fields.tier': 'gold', 'custom_fields.tags': ['a'] })).toEqual({ name: 'x', custom_fields: { tier: 'gold', tags: ['a'] } })
    })

    it('rejects prototype keys everywhere', () => {
        for (const path of ['__proto__.x', 'a.constructor', 'prototype', 'a..b', '']) expect(isSafePath(path)).toBe(false)
        const target = {}
        setPath(target, '__proto__.polluted', 'yes')
        setPath(target, 'custom_fields.constructor.polluted', 'yes')
        nestPaths({ '__proto__.polluted': 'yes' })
        expect(({} as any).polluted).toBeUndefined()
        expect(target).toEqual({})
        expect(getPath({}, '__proto__.toString')).toBeUndefined()
        expect(safeEntries([{ field: 'custom_fields.ok' }, { field: '__proto__.x' }, { field: 'custom_fields.constructor' }])).toEqual([{ field: 'custom_fields.ok' }])
    })

    it('maps dotted and child errors onto the field', () => {
        expect(mapErrorKeys({ 'custom_fields.tags.0': ['bad'], 'custom_fields.tier': 'req', other: ['x'] }, ['custom_fields.tags', 'custom_fields.tier'])).toEqual({
            'custom_fields.tags': ['bad'],
            'custom_fields.tier': ['req'],
            other: ['x'],
        })
    })

    it('resolves labels, help and filter params', () => {
        expect(customLabel(tier, 'en')).toBe('Tier')
        expect(customLabel(grade, 'en')).toBe('Xếp loại')
        expect(customLabel({ custom: true, field: 'custom_fields.k' }, 'vi')).toBe('k')
        expect(customLabel({ field: 'name' }, 'vi')).toBeNull()
        expect(customHelp(tier, 'vi')).toBe('Chọn hạng khách')
        const range = { field: 'custom_fields.n', param: 'cf[n]', range: true }
        expect(filterSlots(range).map((s) => s.key)).toEqual(['custom_fields.n:from', 'custom_fields.n:to'])
        expect(filterParams(range, { 'custom_fields.n:from': '1', 'custom_fields.n:to': '' })).toEqual({ 'cf[n][from]': '1' })
        expect(filterParams({ field: 'custom_fields.t', param: 'cf[t]', multiple: true }, { 'custom_fields.t': ['a', 'b'] })).toEqual({ 'cf[t]': ['a', 'b'] })
        expect(filterParams({ field: 'status' }, { status: 'x' })).toEqual({ status: 'x' })
    })
})

describe('ResourceForm with custom fields', () => {
    beforeEach(() => {
        vi.resetAllMocks()
        i18n.locale.value = 'vi'
    })
    const mountForm = (props = {}) => mount<any>(ResourceForm, { props: { fields: customFields, endpoint: { path: 'helpdesk/organizations', method: 'POST' }, ...props }, global: { stubs } })

    it('shows literal labels, option labels and help in vi and en', async () => {
        const w = mountForm()
        expect(w.find('[data-field="custom_fields.tier"]').text()).toContain('Hạng')
        expect(w.find('[data-field="custom_fields.tier"] .field-help').text()).toBe('Chọn hạng khách')
        expect(w.find('[data-field="custom_fields.grade"]').text()).toContain('Xếp loại')
        expect(w.find('[data-field="custom_fields.grade"]').text()).toContain('Loại A')
        expect(w.find('[data-field="custom_fields.signed"]').text()).toContain('Signed on')
        expect(w.find('[data-field="custom_fields.tags"] select').attributes('data-mode')).toBe('multiple')
        w.unmount()

        i18n.locale.value = 'en'
        const en = mountForm()
        expect(en.find('[data-field="custom_fields.tier"]').text()).toContain('Tier')
        expect(en.find('[data-field="custom_fields.grade"]').text()).toContain('Grade A')
        expect(en.find('[data-field="custom_fields.tags"]').text()).toContain('Tags')
    })

    it('writes values under custom_fields in the payload, on create and edit', async () => {
        ajax.request.mockResolvedValue({ data: {} })
        const create = mountForm()
        await create.find('[data-field="custom_fields.tier"] input').setValue('gold')
        await create.find('[data-field="custom_fields.tags"] select').setValue('b')
        await create.find('form').trigger('submit')
        await flushPromises()
        const data = ajax.request.mock.calls[0][0].data
        expect(data.custom_fields).toMatchObject({ tier: 'gold', tags: ['b'] })
        expect(Object.keys(data)).not.toContain('custom_fields.tier')

        const edit = mountForm({ record: { uuid: 'u1', custom_fields: { tier: 'silver', grade: 'a', tags: ['a'], signed: '2026-11-05' } }, endpoint: { path: 'helpdesk/organizations/:uuid', method: 'PUT' } })
        expect((edit.find('[data-field="custom_fields.tier"] input').element as HTMLInputElement).value).toBe('silver')
        await edit.find('form').trigger('submit')
        await flushPromises()
        expect(ajax.request.mock.calls[1][0].data.custom_fields).toEqual({ tier: 'silver', grade: 'a', tags: ['a'], signed: '2026-11-05' })
    })

    it('shows a 422 message under the dotted field, including child keys', async () => {
        ajax.request.mockRejectedValue({ response: { status: 422, data: { errors: { 'custom_fields.tier': ['Hạng không hợp lệ'], 'custom_fields.tags.0': ['Nhãn sai'] } } } })
        const w = mountForm()
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(w.find('[data-field="custom_fields.tier"] .field-error').text()).toBe('Hạng không hợp lệ')
        expect(w.find('[data-field="custom_fields.tags"] .field-error').text()).toBe('Nhãn sai')
    })

    it('ignores a schema entry with a prototype key', async () => {
        ajax.request.mockResolvedValue({ data: {} })
        const w = mountForm({ fields: [tier, { field: '__proto__.polluted', type: 'text', label: 'x', rules: {} }, { field: 'custom_fields.constructor', type: 'text', label: 'x', rules: {} }] })
        expect(w.findAll('[data-field]').length).toBe(1)
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(ajax.request.mock.calls[0][0].data).toEqual({ custom_fields: { tier: null } })
        expect(({} as any).polluted).toBeUndefined()
    })

    it('leaves no document or window listeners behind after unmount', () => {
        const add = vi.spyOn(window, 'addEventListener')
        const remove = vi.spyOn(window, 'removeEventListener')
        const addDoc = vi.spyOn(document, 'addEventListener')
        const removeDoc = vi.spyOn(document, 'removeEventListener')
        const w = mountForm()
        w.unmount()
        expect(add.mock.calls.length).toBeLessThanOrEqual(remove.mock.calls.length)
        expect(addDoc.mock.calls.length).toBeLessThanOrEqual(removeDoc.mock.calls.length)
    })
})

describe('ResourceList with custom fields', () => {
    beforeEach(() => {
        vi.resetAllMocks()
        i18n.locale.value = 'vi'
        nav.route.query = {}
    })
    const listSchema = {
        list: {
            endpoint: { url: '/api/v2/admin/helpdesk/organizations', adapter: 'ispa-list-v1' },
            search: { param: 'q' },
            columns: [
                { field: 'name', type: 'text', label: 'text.x.name', link: 'detail' },
                { field: 'custom_fields.tags', type: 'enum', label: 'text.custom.tags', custom: true, label_text: { vi: 'Nhãn', en: 'Tags' }, options: tags.options },
                { field: 'custom_fields.vip', type: 'boolean', label: 'text.custom.vip', custom: true, label_text: { vi: 'VIP' } },
                { field: '__proto__.bad', type: 'text', label: 'x' },
            ],
            filters: [
                { field: 'custom_fields.grade', type: 'enum', label: 'text.custom.grade', custom: true, label_text: { vi: 'Xếp loại' }, options: grade.options, param: 'cf[grade]' },
                { field: 'custom_fields.tags', type: 'enum', label: 'text.custom.tags', custom: true, label_text: { vi: 'Nhãn' }, options: tags.options, param: 'cf[tags]', multiple: true },
                { field: 'custom_fields.size', type: 'number', label: 'text.custom.size', custom: true, label_text: { vi: 'Quy mô' }, param: 'cf[size]', range: true },
            ],
        },
    }
    const mountList = async () => {
        ajax.get.mockResolvedValue({ data: { data: [{ uuid: 'u1', name: 'Acme', custom_fields: { tags: ['a', 'b'], vip: true } }], meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 } } })
        const w = mount<any>(ResourceList, { props: { schema: listSchema }, global: { stubs } })
        await flushPromises()
        return w
    }

    it('shows custom columns with literal labels, joined options and yes/no', async () => {
        const w = await mountList()
        const heads = w.findAll('th').map((h: any) => h.text())
        expect(heads).toEqual(['text.x.name', 'Nhãn', 'VIP'])
        const cells = w.findAll('td').map((c: any) => c.text())
        expect(cells[1]).toBe('Loại A, Loại B')
        expect(cells[2]).toBe('text.yes')
    })

    it('sends custom filters as cf[...] params, range as [from]/[to], multiple as an array', async () => {
        const w = await mountList()
        const selects = w.findAll('select.sel')
        await selects[0].setValue('a')
        await flushPromises()
        expect(ajax.get.mock.calls.at(-1)![1].params['cf[grade]']).toBe('a')
        await selects[1].setValue('b')
        await flushPromises()
        expect(ajax.get.mock.calls.at(-1)![1].params['cf[tags]']).toEqual(['b'])
        await w.find('input.num').setValue('5')
        await flushPromises()
        const params = ajax.get.mock.calls.at(-1)![1].params
        expect(params['cf[size][from]']).toBe('5')
        expect('cf[size][to]' in params).toBe(false)
        expect(Object.keys(params).some((k) => k.includes('__proto__'))).toBe(false)
    })
})

describe('ResourceDetail with custom fields', () => {
    beforeEach(() => {
        vi.resetAllMocks()
        i18n.locale.value = 'en'
    })

    it('reads nested values with literal labels and shows a bare date unshifted', async () => {
        ajax.get.mockResolvedValue({ data: { data: { uuid: 'u1', name: 'Acme', custom_fields: { tags: ['a'], signed: '2026-11-05' } } } })
        const schema = { detail: { endpoint: { path: '/api/v2/admin/helpdesk/organizations/:uuid' }, sections: [tags, signed, { field: '__proto__.x', type: 'text', label: 'x' }] } }
        const w = mount<any>(ResourceDetail, { props: { schema, uuid: 'u1' }, global: { stubs } })
        await flushPromises()
        expect(w.find('[data-section="custom_fields.tags"]').text()).toContain('Tags')
        expect(w.find('[data-section="custom_fields.tags"]').text()).toContain('Grade A')
        expect(w.find('[data-section="custom_fields.signed"]').text()).toContain('Signed on')
        expect(w.find('[data-section="custom_fields.signed"]').text()).toContain('05/11/2026')
        expect(w.findAll('[data-section]').length).toBe(2)
    })
})
