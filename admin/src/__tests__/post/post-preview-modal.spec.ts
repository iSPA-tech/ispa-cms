import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PostPreviewModal from '../../../../cms/Content/admin/pages/post-preview-modal.vue'
import { getWidgetPreview } from '@/libs'

const previewExtension = vi.hoisted(() => ({
    component: {
        props: ['modelValue'],
        template:
            '<div data-test="post-preview-data-chart">{{ modelValue.options?.title }}: {{ modelValue.value?.series?.[0]?.values?.join(", ") }}</div>',
    },
}))

vi.mock('@/config/admin-modules', () => ({
    adminContentBlockExtensions: [
        {
            type: 'data_chart',
            module: 'AcmeContentPipeline',
            previewComponent: previewExtension.component,
        },
    ],
}))

vi.mock('@/hooks/useActiveModules', () => ({
    useActiveModules: () => ({ isModuleExtensionAllowed: () => true }),
}))

vi.mock('@/libs', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/libs')>()
    return {
        ...actual,
        getWidgetPreview: vi.fn(),
    }
})

const AModalStub = {
    props: ['open', 'title'],
    emits: ['cancel'],
    template: '<section v-if="open" class="modal-stub"><h2>{{ title }}</h2><slot /></section>',
}

const mountPreview = (post: Record<string, unknown>) =>
    mount(PostPreviewModal, {
        props: { open: true, post },
        global: {
            stubs: {
                'a-modal': AModalStub,
                'a-tag': { template: '<span><slot /></span>' },
                'a-spin': { template: '<span data-test="spinner" />' },
                'a-button': { template: '<button type="button"><slot /></button>' },
                'a-empty': { props: ['description'], template: '<div>{{ description }}</div>' },
            },
        },
    })

describe('post preview modal', () => {
    beforeEach(() => {
        vi.mocked(getWidgetPreview).mockReset()
        vi.mocked(getWidgetPreview).mockResolvedValue('<section>Rendered widget</section>')
    })

    it('renders the current draft safely and previews supported media and widgets', async () => {
        const wrapper = mountPreview({
            name: 'Bài viết đang soạn',
            description: 'Tóm tắt bài viết',
            type: 'post',
            status: 'status_draft',
            images: [{ original_url: 'https://cdn.example.com/cover.jpg' }],
            content: [
                { type: 'text', value: '<p>Nội dung mới</p><script>window.bad = true</script>' },
                {
                    type: 'image',
                    value: [{ url: 'https://cdn.example.com/body.jpg' }],
                    options: { alt: 'Ảnh bài viết', caption: 'Chú thích ảnh' },
                },
                { type: 'video', value: 'https://www.youtube.com/watch?v=abc123XYZ' },
                {
                    id: 'widget-one',
                    type: 'widget',
                    widget_id: 12,
                    template_id: 34,
                    value: { title: 'Tiêu đề widget', ignored: 'Không gửi' },
                    inputs: [{ name: 'title' }],
                },
            ],
        })

        await flushPromises()

        expect(wrapper.text()).toContain('Bài viết đang soạn')
        expect(wrapper.text()).toContain('Nội dung mới')
        expect(wrapper.find('script').exists()).toBe(false)
        expect(wrapper.get('[data-test="post-preview-cover"]').attributes('src')).toBe(
            'https://cdn.example.com/cover.jpg'
        )
        expect(wrapper.get('[data-test="post-preview-images"] img').attributes('src')).toBe(
            'https://cdn.example.com/body.jpg'
        )
        const videoIframe = wrapper.get('[data-test="post-preview-video"] iframe')
        expect(videoIframe.attributes('src')).toBe(
            'https://www.youtube-nocookie.com/embed/abc123XYZ'
        )
        expect(videoIframe.attributes('referrerpolicy')).toBe('strict-origin-when-cross-origin')
        expect(getWidgetPreview).toHaveBeenCalledWith(
            12,
            { template_id: 34, title: 'Tiêu đề widget' },
            expect.objectContaining({ signal: expect.any(AbortSignal) })
        )
        expect(
            wrapper.get('[data-test="post-preview-widget"] iframe').attributes('srcdoc')
        ).toContain('Rendered widget')
    })

    it('changes responsive frame width and emits close without saving', async () => {
        const wrapper = mountPreview({
            name: 'Draft',
            content: [{ type: 'text', value: null }],
        })

        await wrapper.get('[data-test="post-preview-viewport-mobile"]').trigger('click')

        expect(wrapper.text()).toContain('text.post.no_content')
        expect(wrapper.get('[data-test="post-preview-frame"]').attributes('style')).toContain(
            'width: 390px'
        )
        ;(wrapper.vm as { closePreview: () => void }).closePreview()

        expect(wrapper.emitted('update:open')).toEqual([[false]])
        expect(getWidgetPreview).not.toHaveBeenCalled()
    })

    it('shows a widget error and retries only that preview block', async () => {
        vi.mocked(getWidgetPreview)
            .mockRejectedValueOnce(new Error('Không thể tải widget'))
            .mockResolvedValueOnce('<section>Widget tải lại</section>')

        const wrapper = mountPreview({
            content: [
                {
                    type: 'widget',
                    widget_id: 12,
                    template_id: 34,
                    value: {},
                    inputs: [],
                },
            ],
        })

        await flushPromises()
        expect(wrapper.text()).toContain('Không thể tải widget')

        await wrapper.get('[data-test="post-preview-widget"] button').trigger('click')
        await flushPromises()

        expect(getWidgetPreview).toHaveBeenCalledTimes(2)
        expect(
            wrapper.get('[data-test="post-preview-widget"] iframe').attributes('srcdoc')
        ).toContain('Widget tải lại')
    })

    it('renders an active module-owned content preview with the unsaved block', () => {
        const wrapper = mountPreview({
            content: [
                {
                    type: 'data_chart',
                    options: { title: 'Doanh số' },
                    value: { series: [{ values: [12, 18] }] },
                },
            ],
        })

        expect(wrapper.get('[data-test="post-preview-data-chart"]').text()).toContain(
            'Doanh số: 12, 18'
        )
        expect(wrapper.find('[data-test="post-preview-unsupported-block"]').exists()).toBe(false)
    })

    it('connects the shared modal to both post editor pages', () => {
        const createSource = readFileSync(
            resolve(process.cwd(), '../cms/Content/admin/pages/create.vue'),
            'utf8'
        )
        const updateSource = readFileSync(
            resolve(process.cwd(), '../cms/Content/admin/pages/update.vue'),
            'utf8'
        )

        for (const source of [createSource, updateSource]) {
            expect(source).toContain(
                "import PostPreviewModal from './post-preview-modal.vue'"
            )
            expect(source).toContain(
                '<post-preview-modal v-model:open="previewOpen" :post="form" />'
            )
            expect(source).toContain("{{ $t('nav.b.preview') }}")
        }
    })
})
