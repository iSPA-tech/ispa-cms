import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import WidgetRepeaterField from '@/components/widget-repeater-field.vue'
import WidgetInputFields from '@/components/widget-input-fields.vue'

vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (k: string, p?: Record<string, any>) => (p ? `${k}|${JSON.stringify(p)}` : k),
        te: () => false,
    }),
}))

const DraggableStub = defineComponent({
    name: 'draggable',
    props: ['modelValue', 'itemKey'],
    emits: ['update:modelValue'],
    setup(props, { slots }) {
        return () =>
            h('div', { 'data-test': 'draggable' }, (props.modelValue || []).map((element: any, index: number) => slots.item?.({ element, index })))
    },
})
const FieldsStub = defineComponent({
    name: 'WidgetInputFields',
    props: ['inputs', 'modelValue'],
    emits: ['update:modelValue'],
    setup(props) {
        return () => h('div', { class: 'fields-stub' }, props.inputs.map((i: any) => i.name).join(','))
    },
})
const AButton = defineComponent({
    props: ['disabled'],
    emits: ['click'],
    setup(p, { slots, emit, attrs }) {
        return () => h('button', { ...attrs, disabled: p.disabled, onClick: () => emit('click') }, slots.default?.())
    },
})

const input = {
    name: 'faq',
    type: 'repeater',
    options: {
        item_label: 'câu hỏi',
        max: 3,
        title_field: 'q',
        fields: [
            { name: 'q', type: 'text', label: 'Câu hỏi' },
            { name: 'a', type: 'textarea', label: 'Trả lời' },
        ],
    },
}
const items = [
    { _id: 'AAA', q: 'Một', a: '' },
    { _id: 'BBB', q: 'Hai', a: '' },
]

const mountField = (modelValue: any[] = items, inp = input) =>
    mount(WidgetRepeaterField, {
        props: { input: inp, modelValue },
        global: { stubs: { draggable: DraggableStub, WidgetInputFields: FieldsStub, 'a-button': AButton, 'i-icon': true } },
    })
const last = (w: any) => w.emitted('update:modelValue').at(-1)[0]

describe('widget-repeater-field', () => {
    beforeEach(() => vi.useFakeTimers())
    afterEach(() => vi.useRealTimers())

    it('renders one row per item with titles', () => {
        const w = mountField()
        expect(w.findAll('[data-test="repeater-row"]')).toHaveLength(2)
        expect(w.text()).toContain('câu hỏi 1 — Một')
        expect(w.text()).toContain('câu hỏi 2 — Hai')
    })

    it('adds an item and disables add at max', async () => {
        const w = mountField()
        await w.find('[data-test="repeater-add"]').trigger('click')
        expect(last(w)).toHaveLength(3)
        expect(last(w)[2]._id).toMatch(/^[A-Za-z0-9]{8}$/)
        const full = mountField([...items, { _id: 'CCC', q: '', a: '' }])
        expect(full.find('[data-test="repeater-add"]').attributes('disabled')).toBeDefined()
        expect(full.find('[data-test="repeater-count"]').text()).toContain('"count":3')
    })

    it('duplicates right after the source', async () => {
        const w = mountField()
        await w.findAll('[data-test="repeater-duplicate"]')[0].trigger('click')
        const next = last(w)
        expect(next.map((i: any) => i.q)).toEqual(['Một', 'Một', 'Hai'])
        expect(next[1]._id).not.toBe('AAA')
    })

    it('removes, shows undo, restores in place, and the bar expires after 8s', async () => {
        const w = mountField()
        await w.findAll('[data-test="repeater-remove"]')[0].trigger('click')
        expect(last(w).map((i: any) => i._id)).toEqual(['BBB'])
        await w.setProps({ modelValue: last(w) })
        expect(w.find('[data-test="repeater-undo"]').exists()).toBe(true)
        await w.find('[data-test="repeater-undo"]').trigger('click')
        expect(last(w).map((i: any) => i._id)).toEqual(['AAA', 'BBB'])
        await w.setProps({ modelValue: last(w) })
        expect(w.find('[data-test="repeater-undo"]').exists()).toBe(false)

        await w.findAll('[data-test="repeater-remove"]')[1].trigger('click')
        await w.setProps({ modelValue: last(w) })
        expect(w.find('[data-test="repeater-undo"]').exists()).toBe(true)
        vi.advanceTimersByTime(8001)
        await w.vm.$nextTick()
        expect(w.find('[data-test="repeater-undo"]').exists()).toBe(false)
    })

    it('keeps the live region mounted while empty and fills it on removal', async () => {
        const w = mountField()
        expect(w.find('[role="status"]').exists()).toBe(true)
        expect(w.find('[data-test="repeater-undo"]').exists()).toBe(false)
        await w.findAll('[data-test="repeater-remove"]')[0].trigger('click')
        await w.setProps({ modelValue: last(w) })
        expect(w.find('[role="status"] [data-test="repeater-undo"]').exists()).toBe(true)
    })

    it('emits the dragged order', () => {
        const w = mountField()
        w.findComponent(DraggableStub).vm.$emit('update:modelValue', [items[1], items[0]])
        expect(last(w).map((i: any) => i._id)).toEqual(['BBB', 'AAA'])
    })

    it('moves with the up/down buttons', async () => {
        const w = mountField()
        await w.findAll('[data-test="repeater-down"]')[0].trigger('click')
        expect(last(w).map((i: any) => i._id)).toEqual(['BBB', 'AAA'])
    })

    it('edits an item through the shared field renderer', () => {
        const w = mountField()
        const fields = w.findAllComponents(FieldsStub)
        expect(fields).toHaveLength(2)
        fields[1].vm.$emit('update:modelValue', { _id: 'BBB', q: 'Đổi', a: '' })
        expect(last(w)[1].q).toBe('Đổi')
        expect(last(w)[0]._id).toBe('AAA')
    })
})

describe('widget-repeater-field image sub-fields', () => {
    it('gives image and upload sub-fields the media picker options, keeping their own', () => {
        const inp = {
            name: 'logos', type: 'repeater',
            options: { fields: [{ name: 'logo', type: 'image' }, { name: 'doc', type: 'upload', options: { limit: 3 } }, { name: 'name', type: 'text' }] },
        }
        const w = mountField([{ _id: 'AAA', logo: [], doc: [], name: '' }], inp as any)
        const fields = w.findComponent(FieldsStub).props('inputs') as any[]
        expect(fields[0].options).toEqual({ class: null, alt: 'Image', src: 'unknown', value: null })
        expect(fields[1].options).toEqual({ class: null, alt: 'Image', src: 'unknown', value: null, limit: 3 })
        expect(fields[2].options).toBeUndefined()
    })
})

describe('widget-input-fields repeater branch', () => {
    it('renders the repeater field for type repeater', () => {
        const w = mount(WidgetInputFields, {
            props: { inputs: [input], modelValue: { faq: items } },
            global: { stubs: { WidgetRepeaterField: { template: '<div data-test="rep-stub" />' } } },
        })
        expect(w.find('[data-test="rep-stub"]').exists()).toBe(true)
    })
})
