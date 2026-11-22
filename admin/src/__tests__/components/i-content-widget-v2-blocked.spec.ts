/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// A page block whose widget is blocked on the site shows "Đang bị chặn" with the reason, asks for no preview (the
// server answers 409 WIDGET_BLOCKED with an English message) and leaves the stored block as it is.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const state = vi.hoisted(() => ({ widgets: [] as Array<Record<string, unknown>> }))
const libs = vi.hoisted(() => ({ getWidgetPreview: vi.fn(), searchFilterWidgets: vi.fn() }))
const alerts = vi.hoisted(() => ({ iAlert: vi.fn() }))

vi.mock('@/hooks', () => ({
    useInputs: () => ({ data: ref([{ widget_id: 1, name: 'a', type: 'text' }]) }),
    useTemplates: () => ({ data: ref([{ id: 2, widget_id: 1, name: 'T2', content: '' }]) }),
    useWidgets: () => ({ data: ref({ data: state.widgets }) }),
}))
vi.mock('@/libs', () => libs)
vi.mock('@/mixins', () => ({ getInputVariableByTemplate: (inputs: any[]) => inputs, iAlert: alerts.iAlert }))
vi.mock('@/mixins/types', () => ({
    contentWidgetObjectDefault: () => ({ widget_id: 0, template_id: 0, value: {}, inputs: [] }),
}))
vi.mock('ant-design-vue', () => ({ Modal: { confirm: vi.fn() } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import IContentWidgetV2 from '@/components/i-content-widget-v2.vue'

const pass = (name: string) => defineComponent({ name, setup: (_p, { slots }) => () => h('div', slots.default?.()) })
const saved = () => ({ type: 'widget', widget_id: 1, template_id: 2, value: { a: 'x' }, inputs: [] })
const mountIt = (props: Record<string, unknown> = {}) =>
    mount(IContentWidgetV2, {
        props: { modelValue: saved(), targetId: 1, ...props },
        global: {
            mocks: { $t: (key: string) => key },
            stubs: {
                WidgetPreviewEditor: defineComponent({
                    name: 'WidgetPreviewEditor',
                    emits: ['blocked'],
                    setup: () => () => h('div', { class: 'preview-editor-stub' }),
                }),
                WidgetInputFields: true,
                SelectInputWidget: true,
                'a-segmented': true,
                'a-form': pass('a-form'),
                'a-form-item': pass('a-form-item'),
                'a-select': true,
                'a-modal': true,
                'a-spin': true,
            },
        },
    })
const chip = (wrapper: ReturnType<typeof mountIt>) => wrapper.find('[data-test="widget-blocked"]')

describe('i-content-widget-v2 blocked widget', () => {
    beforeEach(() => {
        state.widgets = [{ id: 1, name: 'One', is_blocked: true, blocked_reason: 'Sai nội dung' }]
        libs.getWidgetPreview.mockReset().mockResolvedValue('')
        libs.searchFilterWidgets.mockReset().mockResolvedValue({ data: [] })
        alerts.iAlert.mockReset()
    })

    it('blocked chip on page block', async () => {
        const wrapper = mountIt()
        await flushPromises()

        expect(chip(wrapper).text()).toContain('text.widget_library.blocked')
        expect(chip(wrapper).text()).toContain('text.widget_library.block_reason: Sai nội dung')
        // Nothing asks the server for a preview of a blocked widget, and the stored block is not touched.
        expect(wrapper.find('.preview-editor-stub').exists()).toBe(false)
        expect(wrapper.find('[data-test="widget-preview-sizes"]').attributes('disabled')).toBeDefined()
        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    })

    it('a blocked widget without a reason shows the chip only', async () => {
        state.widgets = [{ id: 1, name: 'One', is_blocked: true, blocked_reason: null }]
        const wrapper = mountIt()
        await flushPromises()

        expect(chip(wrapper).text()).toContain('text.widget_library.blocked')
        expect(chip(wrapper).text()).not.toContain('text.widget_library.block_reason')
    })

    it('no chip for a widget that is not blocked', async () => {
        state.widgets = [{ id: 1, name: 'One', is_blocked: false, blocked_reason: null }]
        const wrapper = mountIt()
        await flushPromises()

        expect(chip(wrapper).exists()).toBe(false)
        expect(wrapper.find('.preview-editor-stub').exists()).toBe(true)
    })

    it('the preview reporting the block shows the chip when the list did not say so', async () => {
        state.widgets = [{ id: 1, name: 'One' }]
        const wrapper = mountIt()
        await flushPromises()
        expect(chip(wrapper).exists()).toBe(false)

        wrapper.findComponent({ name: 'WidgetPreviewEditor' }).vm.$emit('blocked')
        await flushPromises()

        expect(chip(wrapper).text()).toContain('text.widget_library.blocked')
        expect(wrapper.find('.preview-editor-stub').exists()).toBe(false)
    })

    it('a 409 WIDGET_BLOCKED on the size preview shows our copy, never the server message', async () => {
        state.widgets = [{ id: 1, name: 'One' }]
        libs.getWidgetPreview.mockRejectedValue({
            response: { status: 409, data: { error_code: 'WIDGET_BLOCKED', message: 'This widget is blocked on this site' } },
        })
        const wrapper = mountIt()
        await flushPromises()

        await wrapper.find('[data-test="widget-preview-sizes"]').trigger('click')
        await flushPromises()

        expect(libs.getWidgetPreview.mock.calls[0][2]).toMatchObject({ silent: true })
        expect(chip(wrapper).text()).toContain('text.widget_library.blocked')
        expect(wrapper.text()).not.toContain('This widget is blocked on this site')
        expect(alerts.iAlert).not.toHaveBeenCalled()
    })
})

describe('i-content-widget-v2 widget the paged picker list does not hold', () => {
    beforeEach(() => {
        // The picker list is one page of 20: the block's widget (id 1) is older than that page.
        state.widgets = [{ id: 40, name: 'Newer' }]
        libs.getWidgetPreview.mockReset().mockResolvedValue('')
    })

    it('reads the widget by id once and shows its name, the blocked chip and the reason', async () => {
        libs.searchFilterWidgets.mockReset().mockResolvedValue({
            data: [{ id: 1, name: 'Slider', blocked_at: '2026-11-21 10:00:00', blocked_reason: 'Ảnh lỗi' }],
        })
        const wrapper = mountIt()
        await flushPromises()

        expect(libs.searchFilterWidgets).toHaveBeenCalledTimes(1)
        expect(libs.searchFilterWidgets).toHaveBeenCalledWith({ id: 1, limit: 1 }, { silent: true })
        expect(wrapper.text()).toContain('Slider')
        expect(chip(wrapper).text()).toContain('text.widget_library.blocked')
        expect(chip(wrapper).text()).toContain('text.widget_library.block_reason: Ảnh lỗi')
        // Known to be blocked before any preview is asked for.
        expect(wrapper.find('.preview-editor-stub').exists()).toBe(false)
        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    })

    it('no preview is mounted while the read is on its way; an unblocked widget gets it afterwards', async () => {
        let answer: (value: unknown) => void = () => {}
        libs.searchFilterWidgets.mockReset().mockReturnValue(new Promise((resolve) => (answer = resolve)))
        const wrapper = mountIt()
        await flushPromises()
        expect(wrapper.find('.preview-editor-stub').exists()).toBe(false)

        answer({ data: [{ id: 1, name: 'Slider', blocked_at: null }] })
        await flushPromises()
        expect(chip(wrapper).exists()).toBe(false)
        expect(wrapper.find('.preview-editor-stub').exists()).toBe(true)
        expect(wrapper.text()).toContain('Slider')
    })

    it('a refused read keeps the block usable', async () => {
        libs.searchFilterWidgets.mockReset().mockRejectedValue(new Error('refused'))
        const wrapper = mountIt()
        await flushPromises()

        expect(chip(wrapper).exists()).toBe(false)
        expect(wrapper.find('.preview-editor-stub').exists()).toBe(true)
    })

    it('a widget the list holds is not read again', async () => {
        state.widgets = [{ id: 1, name: 'One' }]
        libs.searchFilterWidgets.mockReset()
        mountIt()
        await flushPromises()

        expect(libs.searchFilterWidgets).not.toHaveBeenCalled()
    })
})

describe('i-content-widget-v2 block placed from the widget library', () => {
    beforeEach(() => {
        state.widgets = [{ id: 1, name: 'One', is_blocked: false }]
    })

    it('fills its fields like a widget the operator just picked and sends them to the page', async () => {
        const wrapper = mountIt({
            modelValue: { type: 'widget', widget_id: 1, template_id: 2, value: {}, inputs: [] },
            pickOnLoad: true,
        })
        await flushPromises()

        const emitted = wrapper.emitted('update:modelValue')!
        expect(emitted).toHaveLength(1)
        expect(emitted[0][0]).toMatchObject({ widget_id: 1, template_id: 2 })
        expect((emitted[0][0] as any).inputs.map((input: any) => input.name)).toEqual(['a'])
    })

    it('a saved block opened as usual sends nothing', async () => {
        const wrapper = mountIt()
        await flushPromises()

        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    })
})
