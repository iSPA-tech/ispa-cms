import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: object) => (params ? `${key}:${JSON.stringify(params)}` : key) }) }))
vi.mock('@/ajax', () => ({ default: { get: vi.fn() } }))

import ajax from '@/ajax'
import DisplayNumber from '@/components/resource/widgets/display-number.vue'
import DisplayRelation from '@/components/resource/widgets/display-relation.vue'
import InputRelation from '@/components/resource/widgets/input-relation.vue'

const get = ajax.get as unknown as ReturnType<typeof vi.fn>

const selectStub = {
    props: ['value', 'options', 'loading'],
    emits: ['search', 'update:value', 'dropdownVisibleChange'],
    template:
        '<div class="sel" :data-loading="loading" :data-value="value"><span v-for="o in options" :key="o.value" class="opt" :data-value="o.value">{{ o.label }}</span><slot name="notFoundContent" /></div>',
}
const global = {
    stubs: {
        'a-select': selectStub,
        'a-spin': { template: '<i class="spin" />' },
        'i-button': { emits: ['click'], template: '<button class="retry" @click="$emit(\'click\')"><slot /></button>' },
    },
}
const field = { field: 'owner_uuid', type: 'relation', relation: { endpoint: '/api/v2/admin/helpdesk/users', label_key: 'name', value_key: 'uuid', page_size: 20 } }
const page = (rows: object[]) => ({ data: { data: rows } })

function mountInput(props: Record<string, unknown> = {}) {
    return mount(InputRelation, { props: { field, ...props }, global })
}

beforeEach(() => {
    vi.useFakeTimers()
    get.mockReset()
})
afterEach(() => {
    vi.useRealTimers()
})

describe('relation input widget', () => {
    it('debounces search into one request', async () => {
        get.mockResolvedValue(page([]))
        const w = mountInput()
        const sel = w.findComponent(selectStub)
        for (const term of ['a', 'ab', 'abc']) {
            sel.vm.$emit('search', term)
            await vi.advanceTimersByTimeAsync(100)
        }
        expect(get).not.toHaveBeenCalled()
        await vi.advanceTimersByTimeAsync(300)
        expect(get).toHaveBeenCalledTimes(1)
        expect(get).toHaveBeenCalledWith('helpdesk/users', expect.objectContaining({ params: { search: 'abc', per_page: 20 } }))
    })

    it('sends the term under a custom search_param', async () => {
        get.mockResolvedValue(page([]))
        const w = mount(InputRelation, { props: { field: { ...field, relation: { ...field.relation, search_param: 'q' } } }, global })
        w.findComponent(selectStub).vm.$emit('search', '2000')
        await vi.advanceTimersByTimeAsync(300)
        expect(get).toHaveBeenCalledWith('helpdesk/users', expect.objectContaining({ params: { q: '2000', per_page: 20 } }))
    })

    it('omits the search param when the term is empty', async () => {
        get.mockResolvedValue(page([]))
        const w = mountInput()
        w.findComponent(selectStub).vm.$emit('dropdownVisibleChange', true)
        await flushPromises()
        expect(get).toHaveBeenCalledWith('helpdesk/users', expect.objectContaining({ params: { per_page: 20 } }))
    })

    it('keeps the current label from a flat <relation>_<label_key> record key', () => {
        const w = mountInput({ modelValue: 'u9', record: { owner_name: 'Cy' } })
        expect(w.find('.opt').text()).toBe('Cy')
    })

    it('prefills the value and keeps the label from the record keys named by from_record', () => {
        const batchField = { ...field, field: 'product_variant_uuid', from_record: { value: 'variant_uuid', label: 'variant_name' } }
        const w = mount(InputRelation, { props: { field: batchField, modelValue: null, record: { variant_uuid: 'v1', variant_name: 'Rice 5kg' } }, global })
        expect(w.emitted('update:modelValue')?.[0]).toEqual(['v1'])
        const filled = mount(InputRelation, { props: { field: batchField, modelValue: 'v1', record: { variant_uuid: 'v1', variant_name: 'Rice 5kg' } }, global })
        expect(filled.emitted('update:modelValue')).toBeUndefined()
        expect(filled.find('.opt').text()).toBe('Rice 5kg')
    })

    it('leaves an empty value alone when the from_record key is missing from the record', () => {
        const batchField = { ...field, from_record: { value: 'variant_uuid', label: 'variant_name' } }
        const w = mount(InputRelation, { props: { field: batchField, modelValue: null, record: {} }, global })
        expect(w.emitted('update:modelValue')).toBeUndefined()
    })

    it('shows loading then the options', async () => {
        let resolve: (v: unknown) => void = () => {}
        get.mockReturnValue(new Promise((r) => (resolve = r)))
        const w = mountInput()
        w.findComponent(selectStub).vm.$emit('dropdownVisibleChange', true)
        await flushPromises()
        expect(w.find('.sel').attributes('data-loading')).toBe('true')
        expect(w.find('.spin').exists()).toBe(true)
        resolve(page([{ uuid: 'u1', name: 'Ann' }]))
        await flushPromises()
        expect(w.find('.opt').text()).toBe('Ann')
        expect(w.find('.sel').attributes('data-loading')).toBe('false')
    })

    it('reads rows from a Laravel paginator nested under data', async () => {
        get.mockResolvedValue({ data: { data: { data: [{ uuid: 'y1', name: '2031-2032' }], current_page: 1 } } })
        const w = mountInput()
        w.findComponent(selectStub).vm.$emit('dropdownVisibleChange', true)
        await flushPromises()
        expect(w.find('.opt').text()).toBe('2031-2032')
    })

    it('shows the empty state', async () => {
        get.mockResolvedValue(page([]))
        const w = mountInput()
        w.findComponent(selectStub).vm.$emit('dropdownVisibleChange', true)
        await flushPromises()
        expect(w.find('.r-relation-empty').text()).toBe('text.resource.empty')
    })

    it('shows an error with a retry that refetches', async () => {
        get.mockRejectedValueOnce(new Error('boom')).mockResolvedValueOnce(page([{ uuid: 'u1', name: 'Ann' }]))
        const w = mountInput()
        w.findComponent(selectStub).vm.$emit('dropdownVisibleChange', true)
        await flushPromises()
        expect(w.find('.r-relation-error').text()).toContain('text.resource.load_failed')
        await w.find('.retry').trigger('click')
        await flushPromises()
        expect(get).toHaveBeenCalledTimes(2)
        expect(w.find('.opt').text()).toBe('Ann')
    })

    it('keeps the label of the current value', () => {
        const w = mountInput({ modelValue: 'u9', record: { owner: { uuid: 'u9', name: 'Bob' } } })
        expect(w.find('.opt').text()).toBe('Bob')
        expect(get).not.toHaveBeenCalled()
    })

    it('emits the uuid on select', async () => {
        const w = mountInput()
        w.findComponent(selectStub).vm.$emit('update:value', 'u1')
        expect(w.emitted('update:modelValue')?.[0]).toEqual(['u1'])
    })

    it('ignores a stale response', async () => {
        const resolvers: Array<(v: unknown) => void> = []
        get.mockImplementation(() => new Promise((r) => resolvers.push(r)))
        const w = mountInput()
        const sel = w.findComponent(selectStub)
        sel.vm.$emit('search', 'a')
        await vi.advanceTimersByTimeAsync(300)
        sel.vm.$emit('search', 'b')
        await vi.advanceTimersByTimeAsync(300)
        resolvers[1](page([{ uuid: 'b', name: 'Bee' }]))
        resolvers[0](page([{ uuid: 'a', name: 'Ay' }]))
        await flushPromises()
        expect(w.findAll('.opt').map((o) => o.text())).toEqual(['Bee'])
    })

    it('clears timers on unmount, also over 50 cycles', async () => {
        get.mockResolvedValue(page([]))
        const w = mountInput()
        w.findComponent(selectStub).vm.$emit('search', 'x')
        expect(vi.getTimerCount()).toBe(1)
        w.unmount()
        expect(vi.getTimerCount()).toBe(0)
        for (let i = 0; i < 50; i++) {
            const m = mountInput()
            m.findComponent(selectStub).vm.$emit('search', `t${i}`)
            m.unmount()
        }
        expect(vi.getTimerCount()).toBe(0)
        await vi.advanceTimersByTimeAsync(1000)
        expect(get).not.toHaveBeenCalled()
    })
})

describe('relation and number display', () => {
    it('relation renders the nested label, the label field, the value or a dash', () => {
        expect(mount(DisplayRelation, { props: { value: 'u1', field, record: { owner: { name: 'Ann' } } } }).text()).toBe('Ann')
        expect(mount(DisplayRelation, { props: { value: 'u1', field, record: { owner_uuid_label: 'Cy' } } }).text()).toBe('Cy')
        expect(mount(DisplayRelation, { props: { value: 'u1', field } }).text()).toBe('u1')
        expect(mount(DisplayRelation, { props: { value: null, field } }).text()).toBe('—')
    })

    it('number renders the unit when set', () => {
        expect(mount(DisplayNumber, { props: { value: 12, field: { unit: 'text.unit.kg' } } }).text()).toBe('12 text.unit.kg')
        expect(mount(DisplayNumber, { props: { value: 0, field: {} } }).text()).toBe('0')
        expect(mount(DisplayNumber, { props: { value: null, field: { unit: 'u' } } }).text()).toBe('—')
    })
})

describe('relation input widget with endpoints_by', () => {
    const stub = {
        props: ['value', 'options', 'disabled', 'placeholder'],
        emits: ['search', 'update:value', 'dropdownVisibleChange'],
        template: '<div class="sel" :data-disabled="disabled" :data-placeholder="placeholder"><span v-for="o in options" :key="o.value" class="opt">{{ o.label }}</span></div>',
    }
    const stubs = { ...global.stubs, 'a-select': stub }
    const by = {
        field: 'target_uuid',
        type: 'relation',
        relation: { endpoint: 'x/default', label_key: 'name', value_key: 'uuid', page_size: 20, endpoints_by: { field: 'scope_type', map: { book: 'x/books', classification: 'x/classes' } } },
    }
    const mountBy = (values: Record<string, unknown>, modelValue: unknown = null) => mount(InputRelation, { props: { field: by, values, modelValue }, global: { stubs } })

    it('calls the endpoint mapped from the parent value', async () => {
        get.mockResolvedValue(page([]))
        const w = mountBy({ scope_type: 'book' })
        expect(w.findComponent(stub).attributes('data-disabled')).toBe('false')
        w.findComponent(stub).vm.$emit('dropdownVisibleChange', true)
        await flushPromises()
        expect(get).toHaveBeenCalledWith('x/books', expect.anything())
    })

    it.each([[{}], [{ scope_type: null }], [{ scope_type: 'other' }]])('is disabled with a hint and sends no request while the parent is empty or unmapped (%j)', async (values) => {
        const w = mountBy(values)
        const sel = w.findComponent(stub)
        expect(sel.attributes('data-disabled')).toBe('true')
        expect(sel.attributes('data-placeholder')).toBe('text.resource.empty')
        sel.vm.$emit('dropdownVisibleChange', true)
        await vi.advanceTimersByTimeAsync(300)
        expect(get).not.toHaveBeenCalled()
    })

    it('names the parent field in the hint when the form passes its label', () => {
        const w = mount(InputRelation, { props: { field: { ...by, parent_label: 'Scope type' }, values: {}, modelValue: null }, global: { stubs } })
        expect(w.findComponent(stub).attributes('data-placeholder')).toBe('text.resource.pick_parent_first:{"field":"Scope type"}')
    })

    it('clears the value and reloads from the new endpoint when the parent changes', async () => {
        get.mockResolvedValue(page([]))
        const w = mountBy({ scope_type: 'book' }, 'u1')
        await w.setProps({ values: { scope_type: 'classification' } })
        await flushPromises()
        expect(w.emitted('update:modelValue')).toEqual([[null]])
        expect(get).toHaveBeenCalledWith('x/classes', expect.anything())
    })

    it('clears without a request when the parent becomes unmapped', async () => {
        const w = mountBy({ scope_type: 'book' }, 'u1')
        await w.setProps({ values: { scope_type: null } })
        await flushPromises()
        expect(w.emitted('update:modelValue')).toEqual([[null]])
        expect(get).not.toHaveBeenCalled()
        expect(w.findComponent(stub).attributes('data-disabled')).toBe('true')
    })
})
