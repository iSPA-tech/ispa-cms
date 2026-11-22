import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import ajax from '@/ajax'
import PostPage from '../../../../cms/Content/admin/pages/post.vue'

vi.mock('@/hooks', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/hooks')>()
    return {
        ...actual,
        usePostDashboard: () => ({
            data: ref({}),
            isLoading: ref(false),
            isFetching: ref(false),
            error: ref(null),
            refetch: vi.fn(),
            dataUpdatedAt: ref(null),
        }),
    }
})

vi.mock('@/runtime', () => ({
    useAppRuntime: () => ({
        globalSettings: ref({}),
    }),
}))

describe('post submit lock', () => {
    beforeEach(() => {
        vi.useRealTimers()
        vi.mocked(ajax.post).mockReset()
        vi.mocked(ajax.put).mockReset()
    })

    it('blocks a second post save while the first request is pending', () => {
        let resolveSubmit: (value: unknown) => void = () => {}
        vi.mocked(ajax.put).mockReturnValue(
            new Promise((resolve) => {
                resolveSubmit = resolve
            }) as never
        )

        const wrapper = mount(PostPage, {
            global: {
                stubs: {
                    'router-view': { template: '<div />' },
                },
                mocks: {
                    $t: (key: string) => key,
                },
            },
        })

        wrapper.vm.form = {
            ...wrapper.vm.formDefault(),
            id: 143,
            uid: 'post-143',
            uuid: 'post-uuid-143',
            attachments: [],
            config: {},
            slugs: { slug: 'post-143' },
        }

        wrapper.vm.submitForm({ name: 'First save' })
        wrapper.vm.submitForm({ name: 'Second save' })

        expect(ajax.put).toHaveBeenCalledTimes(1)
        expect(wrapper.vm.btnState).toBe(true)

        resolveSubmit({ data: { status: 'success' } })
    })

    it('preserves all content blocks when create validation returns only video fields', async () => {
        vi.mocked(ajax.post).mockResolvedValue({ data: { status: 'error' } })

        const wrapper = mount(PostPage, {
            global: {
                stubs: {
                    'router-view': { template: '<div />' },
                },
                mocks: {
                    $t: (key: string) => key,
                },
            },
        })
        const content = [
            { id: 'content_0', type: 'text', value: '<p>Nội dung mới</p>' },
            {
                id: 'content_1',
                type: 'video',
                value: 'https://www.youtube.com/watch?v=abc123',
                options: { desktopHeight: 450, mobileHeight: 300 },
            },
        ]
        wrapper.vm.form = { ...wrapper.vm.formDefault(), content }

        await wrapper.vm.submitForm({
            content: [
                {
                    value: 'https://www.youtube.com/watch?v=abc123',
                    options: { desktopHeight: 450, mobileHeight: 300 },
                },
            ],
        })

        expect(ajax.post).toHaveBeenCalledWith(
            '/post',
            expect.objectContaining({ content })
        )
        expect(wrapper.vm.form.content).toEqual(content)
    })

    it('submits the full-page presentation choice inside post config', async () => {
        vi.mocked(ajax.post).mockResolvedValue({ data: { status: 'error' } })
        const wrapper = mount(PostPage, {
            global: {
                stubs: { 'router-view': { template: '<div />' } },
                mocks: { $t: (key: string) => key },
            },
        })

        expect(wrapper.vm.formDefault().config.is_full_page).toBe(false)
        wrapper.vm.form = {
            ...wrapper.vm.formDefault(),
            config: { ...wrapper.vm.formDefault().config, is_full_page: true },
        }

        await wrapper.vm.submitForm({ name: 'Bài chuyên sâu' })

        expect(ajax.post).toHaveBeenCalledWith('/post', expect.objectContaining({
            config: expect.objectContaining({ is_full_page: true }),
        }))
    })
})
