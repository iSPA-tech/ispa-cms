import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, onMounted } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({ getWidgetPreview: vi.fn(), i18n: {} as Record<string, string> }))
vi.mock('@/libs', () => ({ getWidgetPreview: mocks.getWidgetPreview }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (k: string) => mocks.i18n[k] ?? k, te: (k: string) => k in mocks.i18n }) }))

import WidgetPreviewEditor from '@/components/widget-preview-editor.vue'
import { __resetPreviewQueue } from '@/components/widget-preview-queue'

const HTML =
    '<body><h1>x</h1><script type="application/json" id="ispa-edit-fields">' +
    '{"on_preview":["a","b"],"off_preview":["c","d"]}</script></body>'

const inputs = [
    { name: 'a', type: 'text', label: 'A' },
    { name: 'b', type: 'textarea', label: 'B' },
    { name: 'c', type: 'image', label: 'C' },
    { name: 'd', type: 'text', label: 'D' },
]

const FieldsStub = defineComponent({
    name: 'WidgetInputFields',
    props: ['inputs', 'modelValue'],
    emits: ['update:modelValue'],
    setup(props) {
        return () => h('div', { class: 'fields-stub', 'data-names': props.inputs.map((i: any) => i.name).join(','), 'data-labels': props.inputs.map((i: any) => i.label).join('|') })
    },
})
const TipTapStub = defineComponent({
    name: 'TipTapEditor',
    props: { modelValue: String, label: String, compact: Boolean },
    emits: ['update:modelValue'],
    setup(props) {
        return () => h('div', { class: 'tiptap-stub', 'data-label': props.label, 'data-compact': String(props.compact) }, props.modelValue)
    },
})
const PickerStub = defineComponent({
    name: 'WidgetPreviewImagePicker',
    props: { modelValue: Array, limit: Number, touch: Boolean },
    emits: ['update:modelValue', 'close'],
    setup(props) {
        return () => h('div', { class: 'picker-stub', 'data-limit': String(props.limit) })
    },
})
const slotStub = (name: string, slots: string[] = ['default']) =>
    defineComponent({
        name,
        props: ['open', 'title'],
        emits: ['afterOpenChange'],
        setup(_p, { slots: s, emit }) {
            onMounted(() => emit('afterOpenChange', true))
            return () => h('div', { class: name }, slots.map((n) => s[n]?.()))
        },
    })

let observers: Array<(entries: any[]) => void> = []
let observerOptions: any[] = []

function mountEditor(extra: Record<string, any> = {}) {
    return mount(WidgetPreviewEditor, {
        attachTo: document.body,
        props: { widgetId: 1, templateId: 2, inputs, modelValue: {}, ...extra },
        global: {
            stubs: {
                WidgetInputFields: FieldsStub,
                TipTapEditor: TipTapStub,
                WidgetPreviewImagePicker: PickerStub,
                'a-popover': slotStub('a-popover', ['default', 'content']),
                'a-drawer': slotStub('a-drawer'),
                'a-collapse': slotStub('a-collapse'),
                'a-collapse-panel': slotStub('a-collapse-panel'),
                'a-button': defineComponent({ emits: ['click'], setup(_p, { slots, emit }) { return () => h('button', { class: 'btn', onClick: () => emit('click') }, slots.default?.()) } }),
            },
        },
    })
}
const intersect = () => observers.forEach((cb) => cb([{ isIntersecting: true }]))
const post = (w: any, data: any, source?: any) =>
    window.dispatchEvent(new MessageEvent('message', { data: { source: 'ispa-widget-edit', ...data }, source: source ?? w.find('iframe').element.contentWindow }))

describe('widget-preview-editor', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        __resetPreviewQueue()
        mocks.getWidgetPreview.mockReset().mockResolvedValue(HTML)
        observers = []
        observerOptions = []
        vi.stubGlobal('IntersectionObserver', class { constructor(cb: any, o: any) { observers.push(cb); observerOptions.push(o) } observe() {} disconnect() {} unobserve() {} })
        // Default: the editor box is laid out as soon as it is observed.
        vi.stubGlobal('ResizeObserver', class { cb: any; constructor(cb: any) { this.cb = cb } observe() { this.cb([{ contentRect: { width: 1 } }]) } disconnect() {} })
    })
    afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals(); document.body.innerHTML = '' })

    it('does not fetch before intersecting', async () => {
        const w = mountEditor()
        await flushPromises()
        expect(mocks.getWidgetPreview).not.toHaveBeenCalled()
        expect(w.text()).toContain('text.widget.edit_on_preview.loading_on_scroll')
    })

    it('fetches with edit_markers and all fields seeded', async () => {
        mountEditor({ modelValue: { a: 'x' } })
        intersect()
        await flushPromises()
        const [id, params] = mocks.getWidgetPreview.mock.calls[0]
        expect(id).toBe(1)
        expect(params).toEqual({ template_id: 2, edit_markers: 1, a: 'x', b: '', c: [], d: '' })
    })

    it('queue runs at most two previews at once', async () => {
        mocks.getWidgetPreview.mockReturnValue(new Promise(() => {}))
        mountEditor(); mountEditor(); mountEditor()
        intersect()
        await flushPromises()
        expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(2)
    })

    it('field-input emits merged values without refetch', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-input', name: 'a', value: 'Mới' })
        await flushPromises(); vi.advanceTimersByTime(1000); await flushPromises()
        const emitted = w.emitted('update:modelValue')!
        expect(emitted[emitted.length - 1][0]).toMatchObject({ a: 'Mới', b: '' })
        expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(1)
    })

    it('typed text survives opening another field', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-input', name: 'a', value: 'Mới' })
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        await w.find('.btn').trigger('click')
        vi.advanceTimersByTime(600); await flushPromises()
        expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(2)
        expect(mocks.getWidgetPreview.mock.calls[1][1].a).toBe('Mới')
    })

    it('rich text round-trips unchanged', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        const html = '<p>Chúng tôi &amp; bạn</p>'
        w.findComponent(TipTapStub).vm.$emit('update:modelValue', html)
        await flushPromises()
        const emitted = w.emitted('update:modelValue')!
        expect(emitted[emitted.length - 1][0].b).toBe(html)
    })

    it('off-preview fields render in Cài đặt khác in template order', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        const panel = w.find('.a-collapse-panel')
        expect(panel.find('.fields-stub').attributes('data-names')).toBe('c,d')
        expect(w.emitted('fields-ready')![0][0]).toEqual({ onPreview: ['a', 'b'], offPreview: ['c', 'd'] })
    })

    it('ignores messages from other windows', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-input', name: 'a', value: 'evil' }, window)
        await flushPromises()
        expect(w.emitted('update:modelValue')).toBeUndefined()
    })

    it('typing after Xong pushes the re-fetch back and keeps the newest value', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        await w.find('.btn').trigger('click')
        vi.advanceTimersByTime(400)
        post(w, { type: 'field-input', name: 'a', value: 'Mới 2' })
        vi.advanceTimersByTime(400); await flushPromises()
        expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(1)
        vi.advanceTimersByTime(600); await flushPromises()
        expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(2)
        expect(mocks.getWidgetPreview.mock.calls[1][1].a).toBe('Mới 2')
    })

    it('does not apply a response that predates typing', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        let release: any
        mocks.getWidgetPreview.mockReturnValueOnce(new Promise((res) => { release = res }))
        await w.find('iframe').exists()
        const before = w.find('iframe').attributes('srcdoc')
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        await w.find('.btn').trigger('click')
        vi.advanceTimersByTime(600); await flushPromises()
        post(w, { type: 'field-input', name: 'a', value: 'typed' })
        release('<body>NEW</body>'); await flushPromises()
        expect(w.find('iframe').attributes('srcdoc')).toBe(before)
    })

    it('focuses the editor after field-open and Esc closes it', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        const active = document.activeElement as HTMLElement
        expect(active.tagName).toBe('BUTTON')
        await w.find('.btn').trigger('keydown', { key: 'Escape' })
        expect(w.find('.a-popover').exists()).toBe(false)
    })

    it('observes with a 200px root margin', async () => {
        mountEditor()
        expect(observerOptions[0]).toEqual({ rootMargin: '200px' })
    })

    it('focuses the editor as soon as its content exists', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        expect((document.activeElement as HTMLElement).tagName).toBe('BUTTON')
    })

    it('waits until the hidden popover has a size before focusing', async () => {
        const sized: Array<(entries: any[]) => void> = []
        vi.stubGlobal('ResizeObserver', class { constructor(cb: any) { sized.push(cb) } observe() {} disconnect() {} })
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        expect(document.activeElement).toBe(document.body)
        sized[0]([{ contentRect: { width: 0 } }])
        expect(document.activeElement).toBe(document.body)
        sized[0]([{ contentRect: { width: 320 } }])
        expect((document.activeElement as HTMLElement).tagName).toBe('BUTTON')
    })

    it('closes the editor on an escape message from the frame', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        expect(w.find('.a-popover').exists()).toBe(true)
        post(w, { type: 'escape' })
        await flushPromises()
        expect(w.find('.a-popover').exists()).toBe(false)
    })

    it('seeds values from the model when inputs arrive after mount', async () => {
        const w = mountEditor({ inputs: [], modelValue: { a: 'real', d: 'dd' } })
        intersect(); await flushPromises()
        expect(mocks.getWidgetPreview).not.toHaveBeenCalled()
        await w.setProps({ inputs })
        await flushPromises()
        expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(1)
        expect(mocks.getWidgetPreview.mock.calls[0][1]).toEqual({ template_id: 2, edit_markers: 1, a: 'real', b: '', c: [], d: 'dd' })
    })

    it('passes token-free labels to the editor fields', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        for (const f of w.findAllComponents(FieldsStub)) {
            expect(f.attributes('data-labels')).not.toMatch(/\{[A-Za-z0-9_]+\}/)
        }
        expect(w.findComponent(TipTapStub).attributes('data-label')).toBe('B')
    })

    it('edits rich text in place with the compact editor, not the field grid', async () => {
        const w = mountEditor({ modelValue: { b: '<p>Cũ</p>' } })
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        const editor = w.findComponent(TipTapStub)
        expect(editor.exists()).toBe(true)
        expect(editor.attributes('data-compact')).toBe('true')
        expect(editor.text()).toBe('<p>Cũ</p>')
        expect(w.findAllComponents(FieldsStub).some((f) => f.attributes('data-names') === 'b')).toBe(false)
    })

    it('keeps the field grid for image fields', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'c', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        expect(w.findComponent(TipTapStub).exists()).toBe(false)
        expect(w.findAllComponents(FieldsStub).some((f) => f.attributes('data-names') === 'c')).toBe(true)
    })

    it('picks a library image in place and applies it only when chosen', async () => {
        const media = [...inputs.slice(0, 2), { name: 'c', type: 'image', label: 'C', options: { src: 'unknown' } }, inputs[3]]
        const w = mountEditor({ inputs: media })
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'c', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        const picker = w.findComponent(PickerStub)
        expect(picker.attributes('data-limit')).toBe('1')
        expect(w.findAllComponents(FieldsStub).some((f) => f.attributes('data-names') === 'c')).toBe(false)
        expect(w.emitted('update:modelValue')).toBeUndefined()
        const file = { id: 7, name: 'a.jpg', url: '/a.jpg' }
        picker.vm.$emit('update:modelValue', [file])
        picker.vm.$emit('close')
        await flushPromises()
        expect(w.emitted('update:modelValue')!.at(-1)![0].c).toEqual([file])
        expect(w.find('.a-popover').exists()).toBe(false)
        vi.advanceTimersByTime(600); await flushPromises()
        expect(mocks.getWidgetPreview.mock.calls.at(-1)![1].c).toEqual([file])
    })

    it('cancelling the image picker changes nothing', async () => {
        const media = [{ name: 'c', type: 'image', label: 'C', options: { src: 'unknown' } }]
        const w = mountEditor({ inputs: media })
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'c', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        w.findComponent(PickerStub).vm.$emit('close')
        await flushPromises()
        expect(w.emitted('update:modelValue')).toBeUndefined()
        expect(w.find('.a-popover').exists()).toBe(false)
    })

    it('re-fetches the preview while rich text is typed in the popover', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        w.findComponent(TipTapStub).vm.$emit('update:modelValue', '<p>Mới</p>')
        vi.advanceTimersByTime(600); await flushPromises()
        expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(2)
        expect(mocks.getWidgetPreview.mock.calls[1][1].b).toBe('<p>Mới</p>')
        // Still open: the reload must not close the popover.
        expect(w.find('.a-popover').exists()).toBe(true)
    })

    it('outlines the open field in the frame, again after a reload, and clears it on close', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        const sent = vi.spyOn(w.find('iframe').element.contentWindow as Window, 'postMessage')
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        expect(sent).toHaveBeenCalledWith({ source: 'ispa-widget-edit', type: 'mark-field', name: 'b' }, '*')
        sent.mockClear()
        await w.find('iframe').trigger('load')
        expect(sent).toHaveBeenCalledWith({ source: 'ispa-widget-edit', type: 'mark-field', name: 'b' }, '*')
        await w.find('.btn').trigger('click')
        expect(sent).toHaveBeenCalledWith({ source: 'ispa-widget-edit', type: 'mark-field', name: null }, '*')
    })

    it('labels the field editor with a close button and the live-preview hint', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        const box = w.find('.a-popover')
        expect(box.text()).toContain('text.widget.edit_on_preview.rich_text_hint')
        expect(box.text()).toContain('text.widget.edit_on_preview.live_hint_above')
        await box.find('[data-action=close]').trigger('click')
        expect(w.find('.a-popover').exists()).toBe(false)
    })

    it('passes the typing hint into the frame', async () => {
        mocks.i18n = { 'text.widget.edit_on_preview.typing': 'đang gõ' }
        const w = mountEditor()
        intersect(); await flushPromises()
        mocks.i18n = {}
        expect(w.find('iframe').attributes('srcdoc')).toContain('__ISPA_STRINGS__={"typing":"đang gõ",')
    })

    it('schedules nothing when a late response lands after unmount', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        let release: any
        mocks.getWidgetPreview.mockReturnValueOnce(new Promise((res) => { release = res }))
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        await w.find('.btn').trigger('click')
        vi.advanceTimersByTime(600); await flushPromises()
        post(w, { type: 'field-input', name: 'a', value: 'typed' })
        w.unmount()
        release(HTML); await flushPromises()
        vi.advanceTimersByTime(2000); await flushPromises()
        expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(2)
    })
    it('ignores field-input for unknown or off-preview names and coerces values', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'field-input', name: 'zzz', value: 'x' })
        post(w, { type: 'field-input', name: 'c', value: 'x' })
        post(w, { type: 'field-input', name: 'b', value: '<script>' })
        post(w, { type: 'field-open', name: 'a', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        expect(w.emitted('update:modelValue')).toBeUndefined()
        expect(w.find('.a-popover').exists()).toBe(false)
        post(w, { type: 'field-input', name: 'a', value: 5 })
        await flushPromises()
        expect(w.emitted('update:modelValue')![0][0].a).toBe('5')
    })

    it('re-fetches on an outside modelValue change but not on its own echo', async () => {
        const w = mountEditor({ modelValue: { a: 'orig' } })
        intersect(); await flushPromises()
        post(w, { type: 'field-input', name: 'a', value: 'typed' })
        await flushPromises()
        await w.setProps({ modelValue: w.emitted('update:modelValue')![0][0] })
        vi.advanceTimersByTime(1000); await flushPromises()
        expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(1)
        await w.setProps({ modelValue: { a: 'orig' } })
        vi.advanceTimersByTime(1000); await flushPromises()
        expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(2)
        expect(mocks.getWidgetPreview.mock.calls[1][1].a).toBe('orig')
    })

    it('reports no fields when the first fetch fails', async () => {
        mocks.getWidgetPreview.mockReset().mockRejectedValue(new Error('500'))
        const w = mountEditor()
        intersect(); await flushPromises()
        expect(w.emitted('fields-ready')![0][0]).toEqual({ onPreview: [], offPreview: [] })
    })

    it('passes translated token-free labels into the frame', async () => {
        mocks.i18n = { 'validation.attributes.title': 'Tiêu đề' }
        const w = mountEditor({ inputs: [{ name: 'title', type: 'text' }] })
        intersect(); await flushPromises()
        mocks.i18n = {}
        const srcdoc = w.find('iframe').attributes('srcdoc')!
        expect(srcdoc).toContain('__ISPA_LABELS__={"title":"Tiêu đề"}')
    })

    it('returns focus to the edited field after the re-fetch reloads the frame', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        const win = w.find('iframe').element.contentWindow as Window
        const sent = vi.spyOn(win, 'postMessage')
        post(w, { type: 'field-open', name: 'b', rect: { x: 1, y: 2, width: 3, height: 4 } })
        await flushPromises()
        await w.find('.btn').trigger('click')
        expect(document.activeElement).toBe(w.find('iframe').element)
        await w.find('iframe').trigger('load')
        expect(sent).toHaveBeenCalledWith({ source: 'ispa-widget-edit', type: 'focus-field', name: 'b' }, '*')
        sent.mockClear()
        await w.find('iframe').trigger('load')
        expect(sent.mock.calls.some((c) => c[0].type === 'focus-field')).toBe(false)
    })

    it('moves on-preview fields missing from the frame to Cài đặt khác', async () => {
        const w = mountEditor()
        intersect(); await flushPromises()
        post(w, { type: 'fields-present', names: ['a'] })
        await flushPromises()
        expect(w.find('.a-collapse-panel .fields-stub').attributes('data-names')).toBe('b,c,d')
        const ready = w.emitted('fields-ready')!
        expect(ready[ready.length - 1][0]).toEqual({ onPreview: ['a'], offPreview: ['c', 'd', 'b'] })
    })

    it('uses blockName for the off-screen placeholder and falls back to the widget id', async () => {
        const named = mountEditor({ blockName: 'Hero block' })
        expect(named.find('span.font-medium').text()).toBe('Hero block')
        const bare = mountEditor()
        expect(bare.find('span.font-medium').text()).toBe('#1')
    })

    describe('repeater items', () => {
        const fbInput = {
            name: 'fb', type: 'repeater', label: 'Feedback',
            options: { item_label: 'khách', max: 3, fields: [{ name: 'quote', type: 'text', label: 'Lời' }, { name: 'photo', type: 'image', label: 'Ảnh' }] },
        }
        const RHTML =
            '<body><h1>x</h1><script type="application/json" id="ispa-edit-fields">' +
            '{"on_preview":["a","fb"],"off_preview":[],"repeaters":{"fb":{"count":2,"max":3,"item_label":"khách","fields":[]}}}</script></body>'
        const items = () => [{ _id: 'id1', quote: 'Q1', photo: [] }, { _id: 'id2', quote: 'Q2', photo: [] }]
        const mountR = () => mountEditor({ inputs: [inputs[0], fbInput], modelValue: { a: 'x', fb: items() } })
        const last = (w: any) => { const e = w.emitted('update:modelValue')!; return e[e.length - 1][0] }
        beforeEach(() => { mocks.getWidgetPreview.mockReset().mockResolvedValue(RHTML) })
        afterEach(() => { (window as any).innerWidth = 1024 })

        it('item-add appends an empty item and re-fetches', async () => {
            const w = mountR()
            intersect(); await flushPromises()
            post(w, { type: 'item-add', repeater: 'fb' })
            await flushPromises()
            const fb = last(w).fb
            expect(fb).toHaveLength(3)
            expect(fb[2]).toMatchObject({ quote: '', photo: [] })
            expect(fb[2]._id).toMatch(/^[A-Za-z0-9]{8}$/)
            vi.advanceTimersByTime(600); await flushPromises()
            expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(2)
            expect(mocks.getWidgetPreview.mock.calls[1][1].fb).toHaveLength(3)
        })

        it('item-remove shows undo and restores at index', async () => {
            const w = mountR()
            intersect(); await flushPromises()
            post(w, { type: 'item-remove', repeater: 'fb', id: 'id1' })
            await flushPromises()
            expect(last(w).fb.map((i: any) => i._id)).toEqual(['id2'])
            const undo = w.find('[data-test="preview-undo"]')
            expect(undo.exists()).toBe(true)
            await undo.trigger('click')
            expect(last(w).fb.map((i: any) => i._id)).toEqual(['id1', 'id2'])
            expect(w.find('[data-test="preview-undo"]').exists()).toBe(false)
            post(w, { type: 'item-remove', repeater: 'fb', id: 'id2' })
            await flushPromises()
            vi.advanceTimersByTime(8100); await flushPromises()
            expect(w.find('[data-test="preview-undo"]').exists()).toBe(false)
        })

        it('field-input NAME.ID.FIELD updates that item only', async () => {
            const w = mountR()
            intersect(); await flushPromises()
            post(w, { type: 'field-input', name: 'fb.id2.quote', value: 'Mới' })
            await flushPromises(); vi.advanceTimersByTime(1000); await flushPromises()
            expect(last(w).fb).toEqual([{ _id: 'id1', quote: 'Q1', photo: [] }, { _id: 'id2', quote: 'Mới', photo: [] }])
            expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(1)
        })

        it('an outside reset drops the pending undo so it cannot duplicate an item', async () => {
            const w = mountR()
            intersect(); await flushPromises()
            post(w, { type: 'item-remove', repeater: 'fb', id: 'id1' })
            await flushPromises()
            expect(w.find('[data-test="preview-undo"]').exists()).toBe(true)
            await w.setProps({ modelValue: { a: 'x', fb: items() } })
            await flushPromises()
            expect(w.find('[data-test="preview-undo"]').exists()).toBe(false)
        })

        it('rejects unknown repeater/id', async () => {
            const w = mountR()
            intersect(); await flushPromises()
            post(w, { type: 'item-remove', repeater: 'zz', id: 'id1' })
            post(w, { type: 'item-remove', repeater: 'a', id: 'id1' })
            post(w, { type: 'item-duplicate', repeater: 'fb', id: 'nope' })
            post(w, { type: 'item-move', repeater: 'fb', id: 'nope', toIndex: 0 })
            post(w, { type: 'field-input', name: 'fb.nope.quote', value: 'x' })
            post(w, { type: 'field-input', name: 'fb.id1.unknown', value: 'x' })
            post(w, { type: 'field-input', name: 'fb.id1.photo', value: 'x' })
            post(w, { type: 'field-open', name: 'fb.id1.quote', rect: { x: 1, y: 2, width: 3, height: 4 } })
            await flushPromises()
            expect(w.emitted('update:modelValue')).toBeUndefined()
            expect(w.find('.a-popover').exists()).toBe(false)
        })

        it('opens an item image field in the popover titled with the item', async () => {
            const w = mountR()
            intersect(); await flushPromises()
            post(w, { type: 'field-open', name: 'fb.id2.photo', rect: { x: 1, y: 2, width: 3, height: 4 } })
            await flushPromises()
            expect(w.find('.a-popover').text()).toContain('Ảnh · khách 2')
        })

        it('phone item-open shows Lên/Xuống', async () => {
            ;(window as any).innerWidth = 375
            const w = mountR()
            intersect(); await flushPromises()
            post(w, { type: 'item-open', repeater: 'fb', id: 'id2' })
            await flushPromises()
            const sheet = w.find('[data-test="preview-item-sheet"]')
            expect(sheet.exists()).toBe(true)
            expect(sheet.find('.fields-stub').attributes('data-names')).toBe('quote,photo')
            const up = sheet.find('[data-action="up"]')
            expect(up.text()).toBe('text.widget.repeater.up')
            expect(sheet.find('[data-action="down"]').attributes('disabled')).toBeDefined()
            // 48px comes from the drawer root rule (a utility on the button loses to it): 12 = 48px, never 11 = 44px.
            const rootClass = (sheet.element.closest('.a-drawer') as HTMLElement).getAttribute('root-class-name')!.split(' ')
            expect(rootClass).toContain('[&_button]:min-h-12')
            expect(rootClass).not.toContain('[&_button]:min-h-11')
            await up.trigger('click')
            expect(last(w).fb.map((i: any) => i._id)).toEqual(['id2', 'id1'])
        })
    })
})
