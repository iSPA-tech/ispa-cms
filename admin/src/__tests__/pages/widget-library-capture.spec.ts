/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Widget hub distribution, Task 6: after a design save of a site widget, the design editor
// (cms/Core/admin/pages/widget-library/sources.vue) renders each saved design, captures it with html-to-image and
// uploads the picture. A failed capture never blocks or undoes the save.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string) => key, te: () => true }),
    createI18n: () => ({
        global: { t: (key: string) => key, setLocaleMessage: vi.fn(), locale: { value: 'vi' } },
        mode: 'composition',
        install: vi.fn(),
    }),
}))

const toCanvasMock = vi.hoisted(() => vi.fn())
vi.mock('html-to-image', () => ({ toCanvas: toCanvasMock }))

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))

const libsMocks = vi.hoisted(() => ({
    getWidget: vi.fn(),
    storeWidget: vi.fn(),
    updateWidget: vi.fn(),
    deleteWidget: vi.fn(),
    getWidgetPreview: vi.fn(),
}))
vi.mock('@/libs', () => ({
    ...libsMocks,
    buildWidgetSavePayload: (form: Record<string, unknown>) => ({ ...form }),
}))

vi.mock('@/hooks', async () => {
    const { ref } = await import('vue')
    return {
        useWidgets: () => ({ data: ref({ data: [] }), refetch: vi.fn() }),
        widgetInputDefault: () => ({ id: 0, name: '' }),
        widgetTemplateDefault: () => ({ id: 0, name: '' }),
    }
})

const iAlertMock = vi.hoisted(() => vi.fn())
vi.mock('@/mixins', () => ({
    can: () => true,
    rule: () => true,
    iAlert: iAlertMock,
    finishFailed: vi.fn(),
    getInputVariableByTemplate: (inputs: unknown[]) => inputs,
}))

const messageMock = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn(), info: vi.fn() }))
vi.mock('ant-design-vue', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    message: messageMock,
}))

const routeState = vi.hoisted(() => ({ query: {} as Record<string, unknown> }))
vi.mock('vue-router', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    useRouter: () => ({ push: vi.fn() }),
    useRoute: () => routeState,
}))

import WidgetLibrarySources from '../../../../cms/Core/admin/pages/widget-library/sources.vue'
import { captureDesignBlob } from '../../../../cms/Core/admin/widget-preview-capture'

const KB = 1024
/** Blob of `size` bytes with the given type. */
const blobOf = (size: number, type: string) => new Blob([new Uint8Array(size)], { type })
/**
 * Fake full-size capture. `encode(type, quality)` decides what `toBlob` hands back; a browser without WebP support
 * hands back PNG for an `image/webp` request.
 */
const fakeCanvas = (encode: (type: string, quality?: number) => Blob | null) => ({
    width: 1200,
    height: 750,
    toBlob: vi.fn((done: (blob: Blob | null) => void, type: string, quality?: number) => done(encode(type, quality))),
})
/** The scaled copy is a real (jsdom) canvas: its drawing and encoding are stubbed here. */
const scaledEncode = vi.hoisted(() => ({ fn: (_type: string, _quality?: number): Blob | null => null }))

const OverlayStub = defineComponent({
    props: { open: { type: Boolean, default: false } },
    setup(props, { slots }) {
        return () => (props.open ? h('section', { 'data-test': 'modal' }, [slots.default?.(), slots.footer?.()]) : null)
    },
})
const FormStub = defineComponent({
    emits: ['finish'],
    setup(_props, { slots, emit }) {
        return () =>
            h('form', { onSubmit: (event: Event) => (event.preventDefault(), emit('finish', {})) }, slots.default?.())
    },
})
const PassThrough = { template: '<div><slot /></div>' }
const stubs = {
    'a-modal': OverlayStub,
    'a-form': FormStub,
    'a-form-item': PassThrough,
    'a-alert': true,
    'a-table': true,
    'a-textarea': true,
    'a-input': true,
    'a-popconfirm': PassThrough,
    'a-button': { template: '<button><slot /></button>' },
    'i-button': { template: '<button><slot /></button>' },
    'i-icon': true,
    breadcrumbs: { template: '<div><slot /></div>' },
    'no-permission-error': true,
    'widget-template': true,
    'widget-input': true,
    'router-link': { template: '<a><slot /></a>' },
}

const siteWidget = {
    id: 20,
    name: 'Giới thiệu',
    description: 'Khối giới thiệu',
    js: '',
    widget_key: 'site.intro',
    origin: 'site',
    version: 4,
    usage: { pages: 1, areas: 0 },
    template: [{ id: 200, name: 'Hai cột' }],
    inputs: [{ id: 1, name: 'title' }],
}

// Let the frame load (jsdom fires `load` for srcdoc frames asynchronously) and the upload chain settle.
const settle = async () => {
    for (let i = 0; i < 5; i++) {
        await flushPromises()
        await new Promise((resolve) => setTimeout(resolve, 20))
    }
}

const openAndSave = async () => {
    routeState.query = { edit: '20' }
    const wrapper = mount(WidgetLibrarySources as never, { global: { stubs, mocks: { $t: (k: string) => k } } })
    await flushPromises()
    expect(wrapper.find('[data-test="modal"]').exists()).toBe(true)
    await wrapper.get('form').trigger('submit')
    return wrapper
}

beforeEach(() => {
    vi.clearAllMocks()
    vi.restoreAllMocks()
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ drawImage: vi.fn() } as never)
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(function (
        this: HTMLCanvasElement,
        done: BlobCallback,
        type?: string,
        quality?: number
    ) {
        done(scaledEncode.fn(String(type), quality))
    })
    scaledEncode.fn = () => null
    // Catalog status for the page, the saved widget (read silently) for the capture.
    ajaxMock.get.mockImplementation(async (url: string) =>
        url === 'widget/20/edit'
            ? { data: { status: 'success', data: siteWidget } }
            : { data: { status: 'success', data: { authoring: false, unexported_widget_keys: [] } } }
    )
    ajaxMock.post.mockResolvedValue({ data: { status: 'success', data: { id: 200, preview_stale: false } } })
    libsMocks.getWidget.mockResolvedValue({ status: 'success', data: siteWidget })
    libsMocks.updateWidget.mockResolvedValue({ status: 'success', data: { id: 20, sync_status: 'ready' } })
    libsMocks.getWidgetPreview.mockResolvedValue('<div><h2>Xin chào</h2><script>alert(1)</script></div>')
})

describe('design editor preview capture', () => {
    it('save uploads a captured preview', async () => {
        const canvas = fakeCanvas((type) => (type === 'image/webp' ? blobOf(300 * KB, 'image/webp') : null))
        toCanvasMock.mockResolvedValue(canvas)
        const wrapper = await openAndSave()
        await settle()

        expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
        // The saved widget is read silently: a refused read never shows a message.
        expect(ajaxMock.get).toHaveBeenCalledWith('widget/20/edit', { silent: true })
        expect(libsMocks.getWidgetPreview).toHaveBeenCalledWith(20, { template_id: 200 }, { silent: true })
        expect(toCanvasMock).toHaveBeenCalledTimes(1)
        expect(toCanvasMock.mock.calls[0][1]).toMatchObject({ width: 1200, pixelRatio: 1 })
        expect(canvas.toBlob).toHaveBeenCalledWith(expect.any(Function), 'image/webp', 0.85)

        const upload = ajaxMock.post.mock.calls.find(([url]) => url === 'widget-template/200/preview-image')
        expect(upload).toBeDefined()
        const image = (upload![1] as FormData).get('image') as Blob
        expect(image.type).toBe('image/webp')
        expect(image.size).toBe(300 * KB)
        // The captured frame is gone again.
        expect(document.querySelectorAll('iframe').length).toBe(0)
    })

    it('falls back to a smaller PNG when the browser cannot write WebP', async () => {
        // No WebP support: the browser hands back a (large) PNG for the WebP request.
        toCanvasMock.mockResolvedValue(fakeCanvas(() => blobOf(1600 * KB, 'image/png')))
        let scaledWidth = 0
        scaledEncode.fn = (type) => (type === 'image/png' ? blobOf(500 * KB, 'image/png') : null)
        vi.mocked(HTMLCanvasElement.prototype.getContext).mockImplementation(function (this: HTMLCanvasElement) {
            scaledWidth = this.width
            return { drawImage: vi.fn() } as never
        })
        await openAndSave()
        await settle()

        expect(scaledWidth).toBe(800)
        const upload = ajaxMock.post.mock.calls.find(([url]) => url === 'widget-template/200/preview-image')
        const image = (upload![1] as FormData).get('image') as Blob
        expect(image.type).toBe('image/png')
        expect(image.size).toBe(500 * KB)
    })

    it('a WebP over 1 MB is sent smaller, and nothing is sent when no encoding fits', async () => {
        toCanvasMock.mockResolvedValue(fakeCanvas(() => blobOf(1300 * KB, 'image/webp')))
        scaledEncode.fn = (type) => (type === 'image/webp' ? blobOf(700 * KB, 'image/webp') : null)
        await openAndSave()
        await settle()
        const image = (ajaxMock.post.mock.calls.find(([url]) => url === 'widget-template/200/preview-image')![1] as FormData).get('image') as Blob
        expect(image.size).toBe(700 * KB)

        vi.clearAllMocks()
        toCanvasMock.mockResolvedValue(fakeCanvas(() => blobOf(1300 * KB, 'image/webp')))
        scaledEncode.fn = (type) => blobOf(1200 * KB, type)
        await openAndSave()
        await settle()
        expect(ajaxMock.post.mock.calls.some(([url]) => String(url).includes('preview-image'))).toBe(false)
        expect(messageMock.error).not.toHaveBeenCalled()
        expect(iAlertMock).not.toHaveBeenCalled()
    })

    it('a capture that never finishes gives up and removes its frame', async () => {
        toCanvasMock.mockReturnValue(new Promise(() => {}))
        await expect(captureDesignBlob('<div>Xin chào</div>', { timeoutMs: 100 })).rejects.toThrow('timeout')
        expect(document.querySelectorAll('iframe').length).toBe(0)
    })

    it('capture failure keeps save successful', async () => {
        // A picture from another site taints the canvas: the browser refuses the capture.
        toCanvasMock.mockRejectedValue(new DOMException('Tainted canvases may not be exported.', 'SecurityError'))
        const wrapper = await openAndSave()
        await settle()

        expect(libsMocks.updateWidget).toHaveBeenCalledTimes(1)
        expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
        expect(iAlertMock).not.toHaveBeenCalled()
        expect(messageMock.error).not.toHaveBeenCalled()
        expect(ajaxMock.post.mock.calls.some(([url]) => String(url).includes('preview-image'))).toBe(false)
        expect(document.querySelectorAll('iframe').length).toBe(0)
    })

    it('a hub widget save that made no site copy captures nothing', async () => {
        toCanvasMock.mockResolvedValue(fakeCanvas((type) => blobOf(10 * KB, type)))
        libsMocks.getWidget.mockResolvedValue({ status: 'success', data: { ...siteWidget, origin: 'hub' } })
        await openAndSave()
        await settle()

        expect(toCanvasMock).not.toHaveBeenCalled()
        expect(ajaxMock.post).not.toHaveBeenCalled()
    })
})
