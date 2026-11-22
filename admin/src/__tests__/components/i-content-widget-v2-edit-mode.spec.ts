import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, onMounted, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const order = vi.hoisted(() => ({
    // Template order is b, a on purpose: sortWidgetInputs would reorder differently.
    inputs: [] as any[],
    mounts: 0,
}))
const inputsList = [
    { widget_id: 1, name: 'a', type: 'text', label: 'A' },
    { widget_id: 1, name: 'b', type: 'text', label: 'B' },
]
vi.mock('@/hooks', () => ({
    // Kept on `order` so a test can replay the late query load that runs changeWidget(…, false).
    useInputs: () => ((order as any).inputsRef = ref(inputsList), { data: (order as any).inputsRef }),
    useTemplates: () => ({ data: ref([{ id: 2, widget_id: 1, name: 'T', content: '' }, { id: 3, widget_id: 1, name: 'T3', content: '' }]) }),
    useWidgets: () => ({ data: ref({ data: [{ id: 1, name: 'Hero block' }] }) }),
}))
vi.mock('@/libs', () => ({ getWidgetPreview: vi.fn().mockResolvedValue('') }))
vi.mock('@/mixins', () => ({
    getInputVariableByTemplate: () => order.inputs,
    iAlert: vi.fn(),
}))
vi.mock('@/mixins/types', () => ({
    contentWidgetObjectDefault: () => ({ widget_id: 0, template_id: 0, value: {}, inputs: [] }),
}))
vi.mock('ant-design-vue', () => ({ Modal: { confirm: vi.fn(({ onOk }: any) => onOk()) } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (k: string) => k }) }))

import IContentWidgetV2 from '@/components/i-content-widget-v2.vue'

const EditorStub = defineComponent({
    name: 'WidgetPreviewEditor',
    props: ['widgetId', 'templateId', 'inputs', 'modelValue', 'blockName'],
    emits: ['update:modelValue', 'fields-ready'],
    setup(props) {
        onMounted(() => order.mounts++)
        return () => h('div', { class: 'editor-stub', 'data-names': props.inputs.map((i: any) => i.name).join(',') })
    },
})
const FieldsStub = defineComponent({
    name: 'WidgetInputFields',
    props: ['inputs', 'modelValue'],
    emits: ['update:modelValue'],
    setup: () => () => h('div', { class: 'fields-stub' }),
})
const SegmentedStub = defineComponent({
    name: 'a-segmented',
    props: ['value', 'options'],
    emits: ['update:value'],
    setup(props, { emit }) {
        return () =>
            h('div', { class: 'seg' }, props.options.map((o: any) =>
                h('button', { class: `seg-${o.value}`, onClick: () => emit('update:value', o.value) }, o.label)))
    },
})
const pass = (name: string) => defineComponent({ name, setup: (_p, { slots }) => () => h('div', slots.default?.()) })

const mountIt = () =>
    mount(IContentWidgetV2, {
        props: { modelValue: { widget_id: 1, template_id: 2, value: { a: 'x', b: 'y' }, inputs: [] }, targetId: 1 },
        global: {
            mocks: { $t: (k: string) => k },
            stubs: {
                WidgetPreviewEditor: EditorStub,
                WidgetInputFields: FieldsStub,
                SelectInputWidget: true,
                'a-segmented': SegmentedStub,
                'a-form': pass('a-form'),
                'a-form-item': pass('a-form-item'),
                'a-select': defineComponent({ name: 'ASelect', emits: ['change'], setup: () => () => h('div') }),
                'a-modal': true,
                'a-spin': true,
            },
        },
    })

describe('i-content-widget-v2 edit on preview', () => {
    afterEach(() => vi.unstubAllEnvs())

    describe('flag off', () => {
        beforeEach(() => vi.stubEnv('VITE_WIDGET_EDIT_ON_PREVIEW', 'false'))
        it('renders the old grid', async () => {
            const w = mountIt()
            await flushPromises()
            expect(w.find('.fields-stub').exists()).toBe(true)
            expect(w.find('.editor-stub').exists()).toBe(false)
            expect(w.find('.seg').exists()).toBe(false)
        })
    })

    // edit-on-preview is on by default; only VITE_WIDGET_EDIT_ON_PREVIEW=false turns it off.
    describe('flag not set', () => {
        beforeEach(() => {
            vi.stubEnv('VITE_WIDGET_EDIT_ON_PREVIEW', undefined)
            order.inputs = [{ widget_id: 1, name: 'a', type: 'text', label: 'A' }]
        })
        it('edits on the preview by default', async () => {
            const w = mountIt()
            await flushPromises()
            expect(w.findComponent(EditorStub).exists()).toBe(true)
            expect(w.find('.fields-stub').exists()).toBe(false)
        })
    })

    describe('flag on', () => {
        beforeEach(() => {
            vi.stubEnv('VITE_WIDGET_EDIT_ON_PREVIEW', 'true')
            order.inputs = [
                { widget_id: 1, name: 'b', type: 'text', label: 'B' },
                { widget_id: 1, name: 'a', type: 'text', label: 'A' },
            ]
            order.mounts = 0
        })

        it('changing template in preview mode re-fetches with the new template_id', async () => {
            const w = mountIt()
            await flushPromises()
            const before = order.mounts
            w.findComponent({ name: 'ASelect' }).vm.$emit('change', 3)
            await flushPromises()
            // A fresh editor instance: its first fetch carries the new template_id.
            expect(order.mounts).toBe(before + 1)
            expect(w.findComponent(EditorStub).props('templateId')).toBe(3)
        })

        it('a template change clears the automatic fallback', async () => {
            const w = mountIt()
            await flushPromises()
            w.findComponent(EditorStub).vm.$emit('fields-ready', { onPreview: [], offPreview: ['a', 'b'] })
            await flushPromises()
            expect(w.find('.editor-stub').exists()).toBe(false)
            w.findComponent({ name: 'ASelect' }).vm.$emit('change', 3)
            await flushPromises()
            expect(w.find('.editor-stub').exists()).toBe(true)
            w.findComponent(EditorStub).vm.$emit('fields-ready', { onPreview: [], offPreview: ['a', 'b'] })
            await flushPromises()
            expect(w.find('.fields-stub').exists()).toBe(true)
        })

        it('shows the form without a toggle when the template has no inputs', async () => {
            order.inputs = []
            const w = mountIt()
            await flushPromises()
            expect(w.find('.seg').exists()).toBe(false)
            expect(w.find('.editor-stub').exists()).toBe(false)
            expect(w.find('.fields-stub').exists()).toBe(true)
        })

        it('defaults to the preview editor in template order with the widget name', async () => {
            const w = mountIt()
            await flushPromises()
            expect(w.find('.seg').exists()).toBe(true)
            expect(w.find('.fields-stub').exists()).toBe(false)
            const ed = w.findComponent(EditorStub)
            expect(ed.attributes('data-names')).toBe('b,a')
            expect(ed.props('blockName')).toBe('Hero block')
        })

        it('falls back to form when no field is on the preview, once', async () => {
            const w = mountIt()
            await flushPromises()
            w.findComponent(EditorStub).vm.$emit('fields-ready', { onPreview: [], offPreview: ['a', 'b'] })
            await flushPromises()
            expect(w.find('.fields-stub').exists()).toBe(true)
            expect(w.find('.seg').exists()).toBe(true)
            // The user goes back to preview: no second automatic fallback.
            await w.find('.seg-preview').trigger('click')
            await flushPromises()
            w.findComponent(EditorStub).vm.$emit('fields-ready', { onPreview: [], offPreview: ['a', 'b'] })
            await flushPromises()
            expect(w.find('.editor-stub').exists()).toBe(true)
        })

        it('shows the block and template names in the header and hides the selects until asked', async () => {
            const w = mountIt()
            await flushPromises()
            const title = w.find('button[aria-expanded]')
            expect(title.text()).toContain('Hero block')
            expect(title.text()).toContain('T')
            expect(title.attributes('aria-expanded')).toBe('false')
            await title.trigger('click')
            expect(title.attributes('aria-expanded')).toBe('true')
        })

        it('offers the grip and delete only when the list allows it', async () => {
            const w = mountIt()
            await flushPromises()
            expect(w.find('.drag-handle').exists()).toBe(false)
            await w.setProps({ removable: true })
            expect(w.find('.drag-handle').exists()).toBe(true)
            await w.find('button[aria-label="text.editor.contents.delete_block"]').trigger('click')
            expect(w.emitted('remove')).toHaveLength(1)
        })

        it('toggle switches modes without losing values', async () => {
            const w = mountIt()
            await flushPromises()
            w.findComponent(EditorStub).vm.$emit('update:modelValue', { a: 'changed', b: 'y' })
            await flushPromises()
            await w.find('.seg-form').trigger('click')
            await flushPromises()
            expect(w.findComponent(FieldsStub).props('modelValue')).toEqual({ a: 'changed', b: 'y' })
            await w.find('.seg-preview').trigger('click')
            await flushPromises()
            expect(w.findComponent(EditorStub).props('modelValue')).toEqual({ a: 'changed', b: 'y' })
        })
    
        it('opening a saved block without the repeater key does not seed it', async () => {
            order.inputs = [
                { widget_id: 1, name: 'a', type: 'text', label: 'A' },
                { widget_id: 1, name: 'fb', type: 'repeater', label: 'FB', options: { default_count: 3, fields: [{ name: 'quote', type: 'text' }] } },
            ]
            const w = mount(IContentWidgetV2, {
                props: { modelValue: { widget_id: 1, template_id: 2, value: { a: 'x' }, inputs: [] }, targetId: 1 },
                global: {
                    mocks: { $t: (k: string) => k },
                    stubs: {
                        WidgetPreviewEditor: EditorStub, WidgetInputFields: FieldsStub, SelectInputWidget: true,
                        'a-segmented': SegmentedStub, 'a-form': pass('a-form'), 'a-form-item': pass('a-form-item'),
                        'a-select': true, 'a-modal': true, 'a-spin': true,
                    },
                },
            })
            await flushPromises()
            ;(order as any).inputsRef.value = [...inputsList]
            await flushPromises()
            await new Promise((r) => setTimeout(r, 600))
            await flushPromises()
            expect(w.findComponent(EditorStub).props('modelValue').fb ?? []).toHaveLength(0)
            // The load path already emits once to fill `inputs` (pre-existing); it must carry no seeded items.
            for (const [v] of [...(w.emitted('update:modelValue') || []), ...(w.emitted('change') || [])] as any[]) {
                expect(Array.isArray(v.value.fb) && v.value.fb.length > 0).toBe(false)
            }
        })

        it('choosing a widget for a new block seeds default_count empty items', async () => {
            const fb = {
                widget_id: 1, name: 'fb', type: 'repeater', label: 'FB',
                options: { item_label: 'khách', max: 12, default_count: 3, fields: [{ name: 'quote', type: 'text' }] },
            }
            order.inputs = [{ widget_id: 1, name: 'a', type: 'text', label: 'A' }, fb]
            const w = mount(IContentWidgetV2, {
                props: { modelValue: { widget_id: 0, template_id: 0, value: {}, inputs: [] }, targetId: 0 },
                global: {
                    mocks: { $t: (k: string) => k },
                    stubs: {
                        WidgetPreviewEditor: EditorStub,
                        WidgetInputFields: FieldsStub,
                        SelectInputWidget: defineComponent({ name: 'SelectInputWidget', emits: ['change'], setup: () => () => h('div') }),
                        'a-segmented': SegmentedStub,
                        'a-form': pass('a-form'),
                        'a-form-item': pass('a-form-item'),
                        'a-select': true,
                        'a-modal': true,
                        'a-spin': true,
                    },
                },
            })
            await flushPromises()
            w.findAllComponents({ name: 'SelectInputWidget' })[0].vm.$emit('change', 1)
            await flushPromises()
            const value = w.findComponent(EditorStub).props('modelValue')
            expect(value.fb).toHaveLength(3)
            expect(value.fb[0]).toMatchObject({ quote: '' })
            expect(value.fb[0]._id).toMatch(/^[A-Za-z0-9]{8}$/)
            expect(w.find('[data-test="repeater-header-count"]').text()).toBe('text.widget.repeater.count')
        })
    })

    describe('saved repeater ids', () => {
        const mountSaved = (value: any) =>
            mount(IContentWidgetV2, {
                props: { modelValue: { widget_id: 1, template_id: 2, value, inputs: [] }, targetId: 1 },
                global: {
                    mocks: { $t: (k: string) => k },
                    stubs: {
                        WidgetPreviewEditor: EditorStub,
                        WidgetInputFields: FieldsStub,
                        SelectInputWidget: true,
                        'a-segmented': SegmentedStub,
                        'a-form': pass('a-form'),
                        'a-form-item': pass('a-form-item'),
                        'a-select': true,
                        'a-modal': true,
                        'a-spin': true,
                    },
                },
            })
        beforeEach(() => {
            vi.stubEnv('VITE_WIDGET_EDIT_ON_PREVIEW', 'true')
            order.inputs = [{ widget_id: 1, name: 'fb', type: 'repeater', label: 'FB', options: { fields: [{ name: 'q', type: 'text' }] } }]
        })
        it('assigns valid unique ids on load without showing the block as changed', async () => {
            const w = mount(IContentWidgetV2, {
                props: {
                    modelValue: { widget_id: 1, template_id: 2, value: { fb: [{ q: 'a' }, { _id: 'X', q: 'b' }, { _id: 'X', q: 'c' }] }, inputs: [] },
                    targetId: 1,
                },
                global: {
                    mocks: { $t: (k: string) => k },
                    stubs: {
                        WidgetPreviewEditor: EditorStub,
                        WidgetInputFields: FieldsStub,
                        SelectInputWidget: true,
                        'a-segmented': SegmentedStub,
                        'a-form': pass('a-form'),
                        'a-form-item': pass('a-form-item'),
                        'a-select': true,
                        'a-modal': true,
                        'a-spin': true,
                    },
                },
            })
            await flushPromises()
            // Inputs arrive after mount in the app: replay that late load (runs changeWidget(…, false)).
            ;(order as any).inputsRef.value = [...inputsList]
            await flushPromises()
            const ids = w.findComponent(EditorStub).props('modelValue').fb.map((i: any) => i._id)
            expect(new Set(ids).size).toBe(3)
            ids.forEach((id: string) => expect(id).toMatch(/^[A-Za-z0-9]{1,16}$/))
            expect(ids[1]).toBe('X')
            // A system rebuild of a clean block keeps it clean: the whole snapshot, inputs included.
            expect((w.vm as any).isFormDirty).toBe(false)
            // …and the page is not told about it, so it does not look edited either.
            expect(w.emitted('update:modelValue')).toBeUndefined()
        })
        it('normalises ids and stays clean when the queries are already cached at mount', async () => {
            const w = mountSaved({ fb: [{ q: 'a' }, { _id: 'X', q: 'b' }, { _id: 'X', q: 'c' }] })
            await flushPromises()
            const ids = w.findComponent(EditorStub).props('modelValue').fb.map((i: any) => i._id)
            expect(new Set(ids).size).toBe(3)
            expect(ids[1]).toBe('X')
            expect((w.vm as any).isFormDirty).toBe(false)
        })
        it('keeps an operator edit dirty when the queries arrive afterwards', async () => {
            const w = mountSaved({ fb: [{ _id: 'A', q: 'a' }] })
            await flushPromises()
            const vm = w.vm as any
            vm.innerForm.value = { ...vm.innerForm.value, fb: [{ _id: 'A', q: 'edited' }] }
            await flushPromises()
            expect(vm.isFormDirty).toBe(true)
            ;(order as any).inputsRef.value = [...inputsList]
            await flushPromises()
            expect(vm.isFormDirty).toBe(true)
        })
    })
})
