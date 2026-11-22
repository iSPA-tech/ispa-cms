import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref, watch } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const getInputByWidget = vi.hoisted(() => vi.fn())

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (k: string) => k, te: () => false }),
}))
vi.mock('@/libs', () => ({ getInputByWidget }))
vi.mock('@/mixins', () => ({ can: () => false, iAlert: vi.fn() }))
vi.mock('../../../../cms/Widget/admin/api', () => ({
    listWidgetDefinitionCatalog: vi.fn().mockResolvedValue({
        data: [
            { widget_id: 5, widget_theme_id: 9, label: 'Banner', canonical_schema: { title_1: { type: 'text' } } },
            { widget_id: 6, widget_theme_id: 11, label: 'Hero', canonical_schema: { heading: { type: 'text' } } },
        ],
    }),
    getWidgetInstanceUsage: vi.fn().mockResolvedValue({ placements: [] }),
    bootstrapWidgetLayoutByKey: vi.fn().mockResolvedValue({ uuid: 'layout-1' }),
    getWidgetLayoutDraft: vi.fn().mockResolvedValue({ instance_fingerprints: {} }),
}))
vi.mock('../../../../cms/Widget/admin/widget-error-toast', () => ({ iWidgetError: vi.fn() }))

import WidgetPlacementInspector from '../../../../cms/Widget/admin/components/widget-placement-inspector.vue'

const FieldsStub = defineComponent({
    name: 'WidgetInputFields',
    props: ['inputs', 'modelValue'],
    emits: ['update:modelValue'],
    setup(props) {
        return () =>
            h(
                'ul',
                { 'data-test': 'fields-stub' },
                props.inputs.map((i: any) =>
                    h('li', { 'data-name': i.name, 'data-type': i.type }, i.options?.label || i.label || '')
                )
            )
    },
})

const instance = {
    uuid: 'inst-1',
    name: 'Banner đầu trang',
    lock_version: 1,
    current_version: {
        widget_id: 5,
        widget_theme_id: 9,
        schema: { title_1: { type: 'text' }, cover: { type: 'text' }, body: { type: 'textarea' }, extra_note: { type: 'text' } },
        values: { title_1: 'Xin chào', cover: [], body: '', extra_note: '' },
    },
}

const catalogRows = [
    { id: 1, widget_id: 5, name: 'title_1', type: 'text', options: { label: 'Tiêu đề chính' } },
    { id: 2, widget_id: 5, name: 'cover', type: 'image', options: { label: 'Ảnh bìa' } },
    { id: 3, widget_id: 5, name: 'body', type: 'textarea', options: { label: 'Nội dung' } },
    { id: 4, widget_id: 5, name: 'retired_field', type: 'text', options: { label: 'Không còn dùng' } },
]

// The inspector takes what a placement shows (`content`): the pinned version's widget, design, field set and values.
const contentOf = (source: any) => ({ ...source.current_version })

const mountInspector = (props: Record<string, any> = { instance }, stubs: Record<string, any> = { WidgetInputFields: FieldsStub }) =>
    mount(WidgetPlacementInspector, {
        props: { content: contentOf(props.instance) },
        global: {
            mocks: { $t: (k: string) => k },
            stubs: { ...stubs, 'widget-target-picker': true, 'i-icon': true, 'i-button': true },
        },
    })

const withWidget = (widgetId: number, values: Record<string, any> = instance.current_version.values) => ({
    ...instance,
    current_version: { ...instance.current_version, widget_id: widgetId, values },
})

const rendered = (wrapper: any) =>
    wrapper.findAll('[data-test="fields-stub"] li').map((li: any) => ({
        name: li.attributes('data-name'),
        type: li.attributes('data-type'),
        label: li.text(),
    }))

describe('widget placement inspector inputs', () => {
    beforeEach(() => {
        getInputByWidget.mockReset()
        getInputByWidget.mockResolvedValue({ status: 'success', data: catalogRows })
    })

    it('inspector shows catalog labels not field names', async () => {
        const wrapper = mountInspector()
        await flushPromises()

        expect(getInputByWidget).toHaveBeenCalledWith(5, { silent: true })
        const fields = rendered(wrapper)
        expect(fields.find((f: any) => f.name === 'title_1')?.label).toBe('Tiêu đề chính')
        expect(fields.find((f: any) => f.name === 'cover')).toEqual({ name: 'cover', type: 'image', label: 'Ảnh bìa' })
        // Same order as the page-block form: by name, image/textarea last.
        expect(fields.map((f: any) => f.name)).toEqual(['extra_note', 'title_1', 'body', 'cover'])
    })

    it('only fields of the pinned design show; one without a catalog row keeps its own type', async () => {
        const wrapper = mountInspector()
        await flushPromises()

        const fields = rendered(wrapper)
        expect(fields.some((f: any) => f.name === 'retired_field')).toBe(false)
        expect(fields.find((f: any) => f.name === 'extra_note')).toEqual({ name: 'extra_note', type: 'text', label: '' })
    })

    it('keeps the typed values when the catalog inputs arrive', async () => {
        const wrapper = mountInspector()
        await flushPromises()

        const values = wrapper.findComponent(FieldsStub).props('modelValue')
        expect(values.title_1).toBe('Xin chào')
        expect(values.cover).toEqual([])
    })

    it('still renders the fields when the catalog inputs cannot be loaded', async () => {
        getInputByWidget.mockRejectedValue(new Error('offline'))
        const wrapper = mountInspector({ instance: withWidget(76) })
        await flushPromises()

        expect(rendered(wrapper).map((f: any) => f.name)).toEqual(['cover', 'extra_note', 'title_1', 'body'])
    })

    it('a slower answer for another widget never replaces the current fields', async () => {
        let releaseFirst: (value: any) => void = () => {}
        getInputByWidget.mockImplementation((id: number) =>
            id === 5
                ? new Promise((resolve) => (releaseFirst = resolve))
                : Promise.resolve({ status: 'success', data: [{ name: 'heading', type: 'text', options: { label: 'Dòng tiêu đề' } }] })
        )
        const wrapper = mountInspector()
        await flushPromises()
        await wrapper.setProps({
            content: { widget_id: 6, widget_theme_id: 11, schema: { heading: { type: 'text' } }, values: {} },
        })
        await flushPromises()
        releaseFirst({ status: 'success', data: catalogRows })
        await flushPromises()

        expect(rendered(wrapper)).toEqual([{ name: 'heading', type: 'text', label: 'Dòng tiêu đề' }])
    })

    it('area form load keeps a string image value unchanged', async () => {
        // The real field renderer with a picker that echoes like media-selection.vue.
        const EchoMediaSelection = defineComponent({
            name: 'MediaSelection',
            props: ['modelValue', 'limit', 'type'],
            emits: ['update:modelValue'],
            setup(props, { emit }) {
                const inner = ref(props.modelValue)
                watch(
                    () => props.modelValue,
                    (val) => (inner.value = val)
                )
                watch(
                    () => inner.value,
                    (val) => emit('update:modelValue', val)
                )
                return () => h('div', { 'data-test': 'media-picker' })
            },
        })
        const stored = { title_1: 'Xin chào', cover: '/storage/banner.jpg', body: '', extra_note: '' }
        const wrapper = mountInspector(
            { instance: withWidget(5, stored) },
            { 'media-selection': EchoMediaSelection, 'a-form-item': true, 'a-input': true, 'i-content-textarea-editor': true }
        )
        await flushPromises()
        await flushPromises()

        const fields = wrapper.findComponent({ name: 'WidgetInputFields' })
        expect(fields.props('inputs').find((i: any) => i.name === 'cover').type).toBe('image')
        expect(fields.props('modelValue').cover).toBe('/storage/banner.jpg')
        expect(wrapper.findComponent(EchoMediaSelection).props('modelValue')).toEqual([
            { url: '/storage/banner.jpg', name: 'banner.jpg' },
        ])
    })

    it('a refused read is asked once per widget, and the fields keep their fallback', async () => {
        getInputByWidget.mockRejectedValue(new Error('no permission'))
        const first = mountInspector({ instance: withWidget(77) })
        await flushPromises()
        expect(rendered(first).map((f: any) => f.name)).toEqual(['cover', 'extra_note', 'title_1', 'body'])
        first.unmount()

        const second = mountInspector({ instance: withWidget(77) })
        await flushPromises()

        expect(getInputByWidget).toHaveBeenCalledTimes(1)
        expect(rendered(second).map((f: any) => f.name)).toEqual(['cover', 'extra_note', 'title_1', 'body'])
    })
})
