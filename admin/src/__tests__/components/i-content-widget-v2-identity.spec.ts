import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const confirmState = vi.hoisted(() => ({ pending: null as null | { onOk: () => void; onCancel: () => void } }))

vi.mock('@/hooks', () => ({
    useInputs: () => ({ data: ref([{ widget_id: 1, name: 'a', type: 'text' }, { widget_id: 5, name: 'q', type: 'text' }]) }),
    useTemplates: () =>
        ({ data: ref([
            { id: 2, widget_id: 1, name: 'T2', content: '' },
            { id: 3, widget_id: 1, name: 'T3', content: '' },
            { id: 7, widget_id: 5, name: 'T7', content: '' },
        ]) }),
    useWidgets: () => ({ data: ref({ data: [{ id: 1, name: 'One' }, { id: 5, name: 'Five' }] }) }),
}))
vi.mock('@/libs', () => ({ getWidgetPreview: vi.fn().mockResolvedValue('') }))
vi.mock('@/mixins', () => ({
    getInputVariableByTemplate: (inputs: any[]) => inputs,
    iAlert: vi.fn(),
}))
vi.mock('@/mixins/types', () => ({
    contentWidgetObjectDefault: () => ({ widget_id: 0, template_id: 0, value: {}, inputs: [] }),
}))
vi.mock('ant-design-vue', () => ({
    Modal: { confirm: vi.fn((opts: any) => { confirmState.pending = opts }) },
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (k: string) => k }) }))

import IContentWidgetV2 from '@/components/i-content-widget-v2.vue'

const pass = (name: string) => defineComponent({ name, setup: (_p, { slots }) => () => h('div', slots.default?.()) })
const saved = () => ({
    type: 'widget', widget_id: 1, template_id: 2, widget_key: 'old-widget', theme_key: 'old-theme',
    value: { a: 'x' }, inputs: [],
})
const mountIt = () =>
    mount(IContentWidgetV2, {
        props: { modelValue: saved(), targetId: 1 },
        global: {
            mocks: { $t: (k: string) => k },
            stubs: {
                WidgetPreviewEditor: true,
                WidgetInputFields: true,
                SelectInputWidget: true,
                'a-segmented': true,
                'a-form': pass('a-form'),
                'a-form-item': pass('a-form-item'),
                'a-select': defineComponent({ name: 'ASelect', emits: ['change'], setup: () => () => h('div') }),
                'a-modal': true,
                'a-spin': true,
            },
        },
    })
const lastEmit = (w: any) => w.emitted('update:modelValue').at(-1)[0]

describe('i-content-widget-v2 saved identity', () => {
    afterEach(() => { vi.unstubAllEnvs(); confirmState.pending = null })

    it('keeps the loaded keys while nothing changed', async () => {
        const w = mountIt()
        await flushPromises()
        expect(w.emitted('update:modelValue')).toBeUndefined()
    })

    it('changing the widget drops the old widget_key and theme_key', async () => {
        const w = mountIt()
        await flushPromises()
        // Template 7 has no value overlap, so the discard confirm is only for values: answer yes.
        w.findComponent({ name: 'SelectInputWidget' }).vm.$emit('change', 5)
        await flushPromises()
        confirmState.pending!.onOk()
        await flushPromises()
        const out = lastEmit(w)
        expect(out.widget_id).toBe(5)
        expect(out.template_id).toBe(7)
        expect(out).not.toHaveProperty('widget_key')
        expect(out).not.toHaveProperty('theme_key')
    })

    it('changing only the template drops the stale theme_key', async () => {
        const w = mountIt()
        await flushPromises()
        w.findComponent({ name: 'ASelect' }).vm.$emit('change', 3)
        await flushPromises()
        confirmState.pending!.onOk()
        await flushPromises()
        const out = lastEmit(w)
        expect(out.template_id).toBe(3)
        expect(out).not.toHaveProperty('widget_key')
        expect(out).not.toHaveProperty('theme_key')
    })

    it('hides the editors while the discard confirm is open', async () => {
        vi.stubEnv('VITE_WIDGET_EDIT_ON_PREVIEW', 'true')
        const w = mountIt()
        await flushPromises()
        expect(w.findComponent({ name: 'WidgetPreviewEditor' }).exists()).toBe(true)
        w.findComponent({ name: 'SelectInputWidget' }).vm.$emit('change', 5)
        await flushPromises()
        expect(w.findComponent({ name: 'WidgetPreviewEditor' }).exists()).toBe(false)
        confirmState.pending!.onCancel()
        await flushPromises()
        expect(w.findComponent({ name: 'WidgetPreviewEditor' }).exists()).toBe(true)
    })
})
