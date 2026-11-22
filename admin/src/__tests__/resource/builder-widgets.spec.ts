import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/ajax', () => ({ default: { get: vi.fn() } }))

import ajax from '@/ajax'
import { displayWidgets, inputWidgets } from '@/components/resource/widgets'
import DisplayBoolean from '@/components/resource/widgets/display-boolean.vue'
import DisplayText from '@/components/resource/widgets/display-text.vue'
import InputRelation from '@/components/resource/widgets/input-relation.vue'
import InputSlug from '@/components/resource/widgets/input-slug.vue'

const get = ajax.get as unknown as ReturnType<typeof vi.fn>

const selectStub = {
    props: ['value', 'options', 'loading', 'mode', 'disabled'],
    emits: ['search', 'update:value', 'dropdownVisibleChange'],
    template:
        '<div class="sel" :data-mode="mode" :data-disabled="String(disabled)"><span v-for="o in options" :key="o.value" class="opt" :data-value="o.value">{{ o.label }}</span></div>',
}
const inputStub = {
    props: ['value', 'disabled'],
    emits: ['update:value'],
    template: '<input class="slug" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
}
const tagStub = { props: ['color'], template: '<span class="tag" :data-color="color"><slot /></span>' }
const global = {
    stubs: {
        'a-select': selectStub,
        'a-input': inputStub,
        'a-tag': tagStub,
        'a-spin': { template: '<i />' },
        'i-button': { template: '<button><slot /></button>' },
    },
}
const page = (rows: object[]) => ({ data: { data: rows } })
const base = { endpoint: '/api/v2/admin/x/tags', label_key: 'name', value_key: 'uuid', page_size: 20 }

beforeEach(() => {
    vi.useFakeTimers()
    get.mockReset()
})
afterEach(() => vi.useRealTimers())

describe('relation input: multiple', () => {
    const field = { field: 'tag_uuids', type: 'relation', relation: { ...base, multiple: true } }

    it('renders a multiple select with the record labels of the current values', () => {
        const record = { tag_uuids_labels: ['Alpha', 'Beta'] }
        const w = mount(InputRelation, { props: { field, modelValue: ['a', 'b'], record }, global })
        const sel = w.findComponent(selectStub)
        expect(sel.attributes('data-mode')).toBe('multiple')
        expect(w.findAll('.opt').map((o) => o.text())).toEqual(['Alpha', 'Beta'])
    })

    it('reads labels from a nested list of related objects', () => {
        const record = { tags: [{ uuid: 'a', name: 'Alpha' }] }
        const w = mount(InputRelation, { props: { field: { ...field, field: 'tag_uuids' }, modelValue: ['a'], record }, global })
        expect(w.find('.opt').text()).toBe('Alpha')
    })

    it('emits an array and keeps labels of picked values', async () => {
        get.mockResolvedValue(page([{ uuid: 'a', name: 'Alpha' }, { uuid: 'b', name: 'Beta' }]))
        const w = mount(InputRelation, { props: { field, modelValue: [] }, global })
        const sel = w.findComponent(selectStub)
        sel.vm.$emit('dropdownVisibleChange', true)
        await flushPromises()
        sel.vm.$emit('update:value', ['a', 'b'])
        expect(w.emitted('update:modelValue')!.at(-1)).toEqual([['a', 'b']])
        await w.setProps({ modelValue: ['a', 'b'] })
        get.mockResolvedValue(page([]))
        sel.vm.$emit('search', 'zzz')
        await vi.advanceTimersByTimeAsync(300)
        expect(w.findAll('.opt').map((o) => o.text())).toEqual(['Alpha', 'Beta'])
    })

    it('stays a single select without multiple', () => {
        const w = mount(InputRelation, { props: { field: { field: 'o', type: 'relation', relation: base } }, global })
        expect(w.findComponent(selectStub).attributes('data-mode')).toBeUndefined()
    })
})

describe('relation input: dependsOn', () => {
    const field = { field: 'district_uuid', type: 'relation', relation: { ...base, depends_on: { field: 'province_uuid', param: 'province' } } }

    it('is disabled and sends no request while the parent is empty', async () => {
        const w = mount(InputRelation, { props: { field, values: { province_uuid: null } }, global })
        const sel = w.findComponent(selectStub)
        expect(sel.attributes('data-disabled')).toBe('true')
        sel.vm.$emit('dropdownVisibleChange', true)
        sel.vm.$emit('search', 'x')
        await vi.advanceTimersByTimeAsync(300)
        expect(get).not.toHaveBeenCalled()
    })

    it('sends the parent value under the declared param', async () => {
        get.mockResolvedValue(page([]))
        const w = mount(InputRelation, { props: { field, values: { province_uuid: 'p1' } }, global })
        expect(w.findComponent(selectStub).attributes('data-disabled')).toBe('false')
        w.findComponent(selectStub).vm.$emit('dropdownVisibleChange', true)
        await flushPromises()
        expect(get).toHaveBeenCalledWith('x/tags', expect.objectContaining({ params: { per_page: 20, province: 'p1' } }))
    })

    it('clears its value and reloads options when the parent changes', async () => {
        get.mockResolvedValue(page([]))
        const w = mount(InputRelation, { props: { field, modelValue: 'd1', values: { province_uuid: 'p1' } }, global })
        await w.setProps({ values: { province_uuid: 'p2' } })
        await flushPromises()
        expect(w.emitted('update:modelValue')!.at(-1)).toEqual([null])
        expect(get).toHaveBeenCalledTimes(1)
        expect(get.mock.calls[0][1].params.province).toBe('p2')
    })

    it('clears without a request when the parent becomes empty', async () => {
        const w = mount(InputRelation, { props: { field, modelValue: 'd1', values: { province_uuid: 'p1' } }, global })
        await w.setProps({ values: { province_uuid: '' } })
        await flushPromises()
        expect(w.emitted('update:modelValue')!.at(-1)).toEqual([null])
        expect(get).not.toHaveBeenCalled()
        expect(w.findComponent(selectStub).attributes('data-disabled')).toBe('true')
    })

    it('does not clear a stored value on mount', () => {
        const w = mount(InputRelation, { props: { field, modelValue: 'd1', values: { province_uuid: 'p1' } }, global })
        expect(w.emitted('update:modelValue')).toBeUndefined()
    })
})

describe('slug input', () => {
    const field = { field: 'slug', type: 'slug', from: 'name' }

    it('derives the slug from the source: diacritics, đ, case, separators', async () => {
        const w = mount(InputSlug, { props: { field, modelValue: '', values: { name: '' } }, global })
        await w.setProps({ values: { name: '  Đường Nguyễn Trãi — Quận 1!  ' } })
        expect(w.emitted('update:modelValue')!.at(-1)).toEqual(['duong-nguyen-trai-quan-1'])
    })

    it('stops following after a manual edit', async () => {
        const w = mount(InputSlug, { props: { field, modelValue: '', values: { name: 'a' } }, global })
        await w.find('input').setValue('custom')
        expect(w.emitted('update:modelValue')!.at(-1)).toEqual(['custom'])
        const count = w.emitted('update:modelValue')!.length
        await w.setProps({ modelValue: 'custom', values: { name: 'Another' } })
        expect(w.emitted('update:modelValue')!.length).toBe(count)
    })

    it('follows again when the slug is cleared', async () => {
        const w = mount(InputSlug, { props: { field, modelValue: '', values: { name: 'Hello World' } }, global })
        await w.find('input').setValue('custom')
        await w.find('input').setValue('')
        expect(w.emitted('update:modelValue')!.at(-1)).toEqual(['hello-world'])
    })

    it('never overwrites a stored slug', async () => {
        const w = mount(InputSlug, { props: { field, modelValue: 'stored', values: { name: 'Old' } }, global })
        await w.setProps({ values: { name: 'New name' } })
        expect(w.emitted('update:modelValue')).toBeUndefined()
    })
})

describe('boolean display widget', () => {
    it('shows Yes / No through the translation keys', () => {
        for (const yes of [true, 1, '1']) expect(mount(DisplayBoolean, { props: { value: yes }, global }).text()).toBe('text.yes')
        for (const no of [false, 0, '0']) expect(mount(DisplayBoolean, { props: { value: no }, global }).text()).toBe('text.no')
    })

    it('renders a dash for an empty value, never raw true/false', () => {
        for (const empty of [null, undefined, '']) expect(mount(DisplayBoolean, { props: { value: empty as never }, global }).text()).toBe('—')
        expect(mount(DisplayBoolean, { props: { value: true }, global }).text()).not.toMatch(/true/)
    })
})

describe('widget registry', () => {
    it('registers boolean display and slug widgets', () => {
        expect(displayWidgets.boolean).toBe(DisplayBoolean)
        expect(displayWidgets.slug).toBe(DisplayText)
        expect(inputWidgets.slug).toBe(InputSlug)
    })
})
