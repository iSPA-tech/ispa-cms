/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// "Thêm vào… → Trang" from the widget library: the page editor hands the picked widget to i-contents as
// `initialWidget`. The block list appends one widget block at the end, once, and only for a widget that exists and is
// not blocked. Without `initialWidget` (a reload after the page cleared its query) nothing is added or emitted.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const libs = vi.hoisted(() => ({ searchFilterWidgets: vi.fn(), getTemplates: vi.fn() }))

vi.mock('@/libs', () => ({ ...libs, getWidgetPreview: vi.fn() }))
vi.mock('@/hooks', () => ({ useInputs: vi.fn(), useTemplates: vi.fn(), useWidgets: vi.fn() }))
const access = vi.hoisted(() => ({ widget: true }))
vi.mock('@/mixins', () => ({ rule: () => access.widget, iAlert: vi.fn(), getInputVariableByTemplate: vi.fn() }))
vi.mock('@/hooks/useActiveModules', () => ({ useActiveModules: () => ({ isModuleExtensionAllowed: () => false }) }))
vi.mock('@/config/admin-modules', () => ({ adminContentBlockExtensions: [] }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params?.name ? `${key}|${params.name}` : key) }),
}))

import IContents from '@/components/i-contents.vue'

const stubs = {
    'a-button': { template: '<button type="button"><slot /></button>' },
    'a-form-item': { template: '<div><slot /></div>' },
    'a-switch': true,
    draggable: {
        props: ['modelValue'],
        template:
            '<div><slot v-for="(element, index) in modelValue" name="item" :element="element" :index="index" :isDragging="false" /></div>',
    },
    'tip-tap-editor': { template: '<div class="tiptap-stub" />' },
    'i-content-video': true,
    'i-content-widget-v2': { props: ['modelValue', 'pickOnLoad'], template: '<div class="widget-stub" />' },
    'i-icon': true,
    ModalMediaCenter: true,
}

const saved = () => [
    { id: 'content_9_0', type: 'text', value: '<p>Hello</p>' },
    { id: 'content_9_1', type: 'widget', widget_id: 1, template_id: 2, value: { a: 'x' }, inputs: [] },
]
const mountIt = (props: Record<string, unknown> = {}) =>
    mount(IContents, {
        props: { modelValue: saved(), targetId: 9, target: 'Post', ...props },
        global: { mocks: { $t: (key: string) => key }, stubs },
    })
const lastContent = (wrapper: ReturnType<typeof mountIt>) =>
    wrapper.emitted('update:modelValue')?.at(-1)?.[0] as Array<Record<string, any>> | undefined

describe('i-contents initial widget', () => {
    beforeEach(() => {
        access.widget = true
        libs.searchFilterWidgets.mockReset().mockResolvedValue({ data: [{ id: 5, name: 'Banner', blocked_at: null }] })
        libs.getTemplates.mockReset().mockResolvedValue([
            { id: 7, widget_id: 5, name: 'Wide' },
            { id: 2, widget_id: 1, name: 'T2' },
        ])
    })

    it('initial widget appended once', async () => {
        const wrapper = mountIt({ initialWidget: { widgetId: 5, templateId: 7 } })
        await flushPromises()

        const content = lastContent(wrapper)!
        expect(content).toHaveLength(3)
        expect(content.slice(0, 2)).toEqual(saved())
        expect(content[2]).toMatchObject({ type: 'widget', widget_id: 5, template_id: 7, value: {}, inputs: [] })
        expect(wrapper.emitted('initial-widget-handled')).toEqual([[{ status: 'added' }]])
        // The new block is the one that seeds its fields like a widget the operator just picked.
        const blocks = wrapper.findAllComponents('.widget-stub')
        expect(blocks.map((block) => (block.props() as any).pickOnLoad)).toEqual([false, true])
        expect(wrapper.find('[data-test="initial-widget-notice"]').text()).toContain('text.widget_library.page_added|Banner')

        // The parent echoes the content back and re-renders with the same hand-off: still one new block.
        await wrapper.setProps({ modelValue: content, initialWidget: { widgetId: 5, templateId: 7 } })
        await flushPromises()
        expect(lastContent(wrapper)).toHaveLength(3)
        expect(wrapper.emitted('initial-widget-handled')).toHaveLength(1)
        expect(libs.searchFilterWidgets).toHaveBeenCalledTimes(1)
    })

    it('reload with the query already cleared appends nothing', async () => {
        const wrapper = mountIt()
        await flushPromises()

        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
        expect(wrapper.emitted('initial-widget-handled')).toBeUndefined()
        expect(libs.searchFilterWidgets).not.toHaveBeenCalled()
        expect(wrapper.find('[data-test="initial-widget-notice"]').exists()).toBe(false)
    })

    it('a blocked widget is not appended and says why', async () => {
        libs.searchFilterWidgets.mockResolvedValue({
            data: [{ id: 5, name: 'Banner', blocked_at: '2026-11-21 10:00:00', blocked_reason: 'Sai nội dung' }],
        })
        const wrapper = mountIt({ initialWidget: { widgetId: 5, templateId: 7 } })
        await flushPromises()

        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
        expect(wrapper.emitted('initial-widget-handled')).toEqual([[{ status: 'blocked' }]])
        const notice = wrapper.find('[data-test="initial-widget-notice"]').text()
        expect(notice).toContain('text.widget_library.page_blocked|Banner')
        expect(notice).toContain('Sai nội dung')
    })

    it.each([
        ['the widget is gone', { data: [] }, 7],
        ['the design belongs to another widget', { data: [{ id: 5, name: 'Banner' }] }, 2],
    ])('nothing is appended when %s', async (_case, found, templateId) => {
        libs.searchFilterWidgets.mockResolvedValue(found)
        const wrapper = mountIt({ initialWidget: { widgetId: 5, templateId } })
        await flushPromises()

        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
        expect(wrapper.emitted('initial-widget-handled')).toEqual([[{ status: 'missing' }]])
        expect(wrapper.find('[data-test="initial-widget-notice"]').text()).toContain('text.widget_library.page_missing')
    })

    it('a failed lookup appends nothing', async () => {
        libs.searchFilterWidgets.mockRejectedValue(new Error('network'))
        const wrapper = mountIt({ initialWidget: { widgetId: 5, templateId: 7 } })
        await flushPromises()

        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
        expect(wrapper.emitted('initial-widget-handled')).toEqual([[{ status: 'missing' }]])
    })

    it('without the widget permission nothing is asked and one notice is shown', async () => {
        access.widget = false
        const wrapper = mountIt({ initialWidget: { widgetId: 5, templateId: 7 } })
        await flushPromises()

        // No refused request (it would add the server's own "no permission" message to ours).
        expect(libs.searchFilterWidgets).not.toHaveBeenCalled()
        expect(libs.getTemplates).not.toHaveBeenCalled()
        expect(wrapper.emitted('initial-widget-handled')).toEqual([[{ status: 'missing' }]])
        expect(wrapper.text()).toContain('text.widget_library.page_missing')
        expect(lastContent(wrapper)).toBeUndefined()
    })
})
