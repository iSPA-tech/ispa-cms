import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({ getWidgetPreview: vi.fn(), iAlert: vi.fn() }))
vi.mock('@/libs', () => ({ getWidgetPreview: mocks.getWidgetPreview }))
vi.mock('@/mixins', () => ({ iAlert: mocks.iAlert }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (k: string) => k, te: () => false }) }))

import WidgetPreviewEditor from '@/components/widget-preview-editor.vue'
import { __resetPreviewQueue } from '@/components/widget-preview-queue'
import { FRAME_STYLE } from '@/components/widget-preview-frame-script'

const HTML =
    '<body><h1>x</h1><script type="application/json" id="ispa-edit-fields">' +
    '{"on_preview":["a"],"off_preview":[]}</script></body>'
const inputs = [{ name: 'a', type: 'text', label: 'A' }]

const SKELETON = '[data-test="widget-preview-skeleton"]'
const REFRESHING = '[data-test="widget-preview-refreshing"]'
const FAILED = '[data-test="widget-preview-failed"]'

const passthrough = (name: string) => defineComponent({ name, setup: (_p, { slots }) => () => h('div', { class: name }, slots.default?.()) })
const SkeletonStub = defineComponent({
    name: 'ASkeleton',
    props: { active: Boolean },
    setup: (props) => () => h('div', { class: 'a-skeleton', 'data-active': String(props.active) }),
})

let observers: Array<(entries: any[]) => void> = []

function mountEditor() {
    return mount(WidgetPreviewEditor, {
        attachTo: document.body,
        props: { widgetId: 1, templateId: 2, inputs, modelValue: {} },
        global: {
            stubs: {
                WidgetInputFields: passthrough('fields'),
                WidgetPreviewItemSheet: passthrough('sheet'),
                'a-skeleton': SkeletonStub,
                'a-popover': passthrough('a-popover'),
                'a-drawer': passthrough('a-drawer'),
                'a-collapse': passthrough('a-collapse'),
                'a-collapse-panel': passthrough('a-collapse-panel'),
            },
        },
    })
}
const intersect = () => observers.forEach((cb) => cb([{ isIntersecting: true }]))
const post = (w: any, data: any) =>
    window.dispatchEvent(new MessageEvent('message', { data: { source: 'ispa-widget-edit', ...data }, source: w.find('iframe').element.contentWindow }))
const deferred = () => {
    let resolve!: (v: any) => void
    let reject!: (e: any) => void
    const promise = new Promise((res, rej) => { resolve = res; reject = rej })
    return { promise, resolve, reject }
}
const reducedMotion = (matches: boolean) => vi.stubGlobal('matchMedia', () => ({ matches }))

describe('widget-preview-editor loading state', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        __resetPreviewQueue()
        mocks.getWidgetPreview.mockReset().mockResolvedValue(HTML)
        mocks.iAlert.mockReset()
        observers = []
        vi.stubGlobal('IntersectionObserver', class { constructor(cb: any) { observers.push(cb) } observe() {} disconnect() {} unobserve() {} })
        reducedMotion(false)
    })
    afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals(); document.body.innerHTML = '' })

    it('shows no skeleton and is not busy before the block scrolls into view', async () => {
        const w = mountEditor()
        await flushPromises()
        expect(w.find(SKELETON).exists()).toBe(false)
        expect(w.attributes('aria-busy')).toBe('false')
    })

    it('shows the skeleton while the first preview is fetched', async () => {
        const d = deferred()
        mocks.getWidgetPreview.mockReturnValue(d.promise)
        const w = mountEditor()
        intersect()
        await flushPromises()
        expect(w.find(SKELETON).exists()).toBe(true)
        expect(w.find(SKELETON).text()).toContain('text.widget.edit_on_preview.loading_preview')
        expect(w.find('.a-skeleton').attributes('data-active')).toBe('true')
        expect(w.attributes('aria-busy')).toBe('true')
        expect(w.text()).not.toContain('loading_on_scroll')
    })

    it('keeps the skeleton over the frame until it reports its first height, then shows the frame', async () => {
        const w = mountEditor()
        intersect()
        await flushPromises()
        // The frame is mounted (it has to render to measure itself) but still covered.
        expect(w.find('iframe').exists()).toBe(true)
        expect(w.find(SKELETON).exists()).toBe(true)
        expect(w.attributes('aria-busy')).toBe('true')

        post(w, { type: 'size', height: 420 })
        await flushPromises()
        expect(w.find(SKELETON).exists()).toBe(false)
        expect(w.attributes('aria-busy')).toBe('false')
        expect(w.find('iframe').attributes('style')).toContain('height: 420px')
    })

    it('reserves the frame height while loading so the swap does not collapse the block', async () => {
        const d = deferred()
        mocks.getWidgetPreview.mockReturnValue(d.promise)
        const w = mountEditor()
        intersect()
        await flushPromises()
        const box = w.find('[data-test="widget-preview-box"]')
        expect(box.attributes('style')).toContain('min-height: 160px')
        d.resolve(HTML)
        await flushPromises()
        expect(w.find('iframe').attributes('style')).toContain('height: 160px')
    })

    it('a re-fetch after an edit keeps the frame and its height under a small spinner, never the skeleton', async () => {
        const w = mountEditor()
        intersect()
        await flushPromises()
        post(w, { type: 'size', height: 420 })
        await flushPromises()

        const d = deferred()
        mocks.getWidgetPreview.mockReturnValue(d.promise)
        await w.setProps({ modelValue: { a: 'changed outside' } })
        await vi.advanceTimersByTimeAsync(500)
        expect(mocks.getWidgetPreview).toHaveBeenCalledTimes(2)
        expect(w.find(SKELETON).exists()).toBe(false)
        expect(w.find(REFRESHING).exists()).toBe(true)
        expect(w.attributes('aria-busy')).toBe('true')
        expect(w.find('iframe').attributes('style')).toContain('height: 420px')

        d.resolve(HTML.replace('<h1>x</h1>', '<h1>y</h1>'))
        await flushPromises()
        expect(w.find(REFRESHING).exists()).toBe(false)
        expect(w.find(SKELETON).exists()).toBe(false)
        expect(w.attributes('aria-busy')).toBe('false')
        expect(w.find('iframe').attributes('style')).toContain('height: 420px')
    })

    it('a failed re-fetch drops the spinner and keeps the previous preview', async () => {
        const w = mountEditor()
        intersect()
        await flushPromises()
        post(w, { type: 'size', height: 420 })
        mocks.getWidgetPreview.mockRejectedValue({ response: { data: { message: 'boom' } } })
        await w.setProps({ modelValue: { a: 'changed outside' } })
        await vi.advanceTimersByTimeAsync(500)
        await flushPromises()
        expect(w.find(REFRESHING).exists()).toBe(false)
        expect(w.find('iframe').exists()).toBe(true)
        expect(w.find(FAILED).exists()).toBe(false)
    })

    it('stops the skeleton when the first fetch fails and offers a retry', async () => {
        mocks.getWidgetPreview.mockRejectedValue({ response: { data: { message: 'boom' } } })
        const w = mountEditor()
        intersect()
        await flushPromises()
        expect(w.find(SKELETON).exists()).toBe(false)
        expect(w.attributes('aria-busy')).toBe('false')
        expect(w.find(FAILED).text()).toContain('text.widget.edit_on_preview.preview_failed')
        expect(mocks.iAlert).toHaveBeenCalled()

        mocks.getWidgetPreview.mockResolvedValue(HTML)
        await w.find(`${FAILED} button`).trigger('click')
        await flushPromises()
        expect(w.find(FAILED).exists()).toBe(false)
        expect(w.find('iframe').exists()).toBe(true)
    })

    it('stops the skeleton and reports blocked when the widget is blocked on this site', async () => {
        mocks.getWidgetPreview.mockRejectedValue({ response: { status: 409, data: { error_code: 'WIDGET_BLOCKED' } } })
        const w = mountEditor()
        intersect()
        await flushPromises()
        expect(w.emitted('blocked')).toHaveLength(1)
        expect(mocks.iAlert).not.toHaveBeenCalled()
        expect(w.find(SKELETON).exists()).toBe(false)
        expect(w.attributes('aria-busy')).toBe('false')
    })

    it('stops the skeleton when the first request never answers', async () => {
        mocks.getWidgetPreview.mockReturnValue(new Promise(() => {}))
        const w = mountEditor()
        intersect()
        await flushPromises()
        expect(w.find(SKELETON).exists()).toBe(true)
        await vi.advanceTimersByTimeAsync(20000)
        expect(w.find(SKELETON).exists()).toBe(false)
        expect(w.find(FAILED).exists()).toBe(true)
        expect(w.attributes('aria-busy')).toBe('false')
    })

    it('shows the frame anyway when it never reports a height', async () => {
        const w = mountEditor()
        intersect()
        await flushPromises()
        expect(w.find(SKELETON).exists()).toBe(true)
        await vi.advanceTimersByTimeAsync(8000)
        expect(w.find(SKELETON).exists()).toBe(false)
        expect(w.find('iframe').exists()).toBe(true)
        expect(w.attributes('aria-busy')).toBe('false')
    })

    it('shows a loaded frame soon after load even without a height report (preview without edit markers)', async () => {
        mocks.getWidgetPreview.mockResolvedValue('<body><p>plain</p></body>')
        const w = mountEditor()
        intersect()
        await flushPromises()
        await w.find('iframe').trigger('load')
        await vi.advanceTimersByTimeAsync(1000)
        expect(w.find(SKELETON).exists()).toBe(false)
    })

    it('does not animate the skeleton when the user prefers reduced motion', async () => {
        reducedMotion(true)
        mocks.getWidgetPreview.mockReturnValue(new Promise(() => {}))
        const w = mountEditor()
        intersect()
        await flushPromises()
        expect(w.find('.a-skeleton').attributes('data-active')).toBe('false')
    })

    it('keeps the frame style that stops the resize loop', () => {
        expect(FRAME_STYLE).toContain('html,body{min-height:0!important}')
    })
})
