import { flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { Modal } from 'ant-design-vue'
import ajax from '@/ajax'
import SlugV3 from '@/components/slug-v3.vue'
import { renderWithApp } from '@/test-utils/renderWithApp'

vi.mock('@/ajax', () => ({
    default: {
        post: vi.fn(),
    },
}))

vi.mock('ant-design-vue', async () => {
    const actual = await vi.importActual<typeof import('ant-design-vue')>('ant-design-vue')

    return {
        ...actual,
        Modal: {
            ...actual.Modal,
            confirm: vi.fn(),
        },
    }
})

const form = (overrides = {}) => ({
    id: 10,
    name: 'Bài viết cũ',
    status: 'status_published',
    isPublished: true,
    published_at: '2026-08-12 10:00:00',
    slugs: {
        id: 20,
        target_id: 10,
        slug: 'bai-viet-cu',
    },
    ...overrides,
})

const renderSlug = async (modelValue = form(), extraProps: Record<string, unknown> = {}) =>
    renderWithApp(SlugV3, {
        permissions: ['slug.viewAny'],
        props: {
            modelValue,
            open: true,
            module: 'post',
            ...extraProps,
        },
        globalStubs: {
            'i-icon': { template: '<span />' },
        },
    })

describe('SlugV3 wording', () => {
    it('uses one parameterised title key when a label is given', async () => {
        const { wrapper } = await renderSlug(form(), { label: 'Posts' })

        expect(wrapper.find('input[placeholder="text.slug.title_with_label"]').exists()).toBe(true)
    })

    it('falls back to the plain title key without a label', async () => {
        const { wrapper } = await renderSlug()

        expect(wrapper.find('input[placeholder="validation.attributes.title"]').exists()).toBe(true)
    })
})

describe('SlugV3 required message', () => {
    it('uses one sentence key for the required rule, with or without a label', async () => {
        for (const extraProps of [{}, { label: 'Posts' }]) {
            const { wrapper } = await renderSlug(form(), extraProps)
            const rules = wrapper.findComponent('.more_tools').vm.$attrs.rules as Array<{ message: string }>

            expect(rules[0].message).toBe('text.slug.name_required')
        }
    })
})

describe('SlugV3 published records', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        vi.useFakeTimers()
        vi.mocked(ajax.post).mockResolvedValue({
            data: { status: 'success', data: 'bai-viet-moi' },
        } as never)
    })

    it('keeps slug unchanged when the name changes on a published record', async () => {
        const { wrapper } = await renderSlug()

        wrapper.vm.checkNameSlugDebounced({ target: { value: 'Tên mới' } })
        await vi.advanceTimersByTimeAsync(600)

        expect(ajax.post).not.toHaveBeenCalled()
        expect(wrapper.vm.innerForm.slugs.slug).toBe('bai-viet-cu')
    })

    it('requires the UI confirmation before unlocking a published slug', async () => {
        const { wrapper } = await renderSlug()

        expect(wrapper.vm.innerForm.isPublished).toBe(true)
        expect(wrapper.vm.publishedRecord).toBe(true)
        expect(wrapper.find('input[disabled]').exists()).toBe(false)

        wrapper.vm.toggleSlugEdit()
        expect(Modal.confirm).toHaveBeenCalledWith(expect.objectContaining({
            onOk: expect.any(Function),
        }))
        expect(wrapper.find('input[disabled]').exists()).toBe(false)

        const confirmConfig = vi.mocked(Modal.confirm).mock.calls[0]?.[0]
        confirmConfig?.onOk?.()
        await nextTick()

        expect(wrapper.find('input[disabled]').exists()).toBe(false)
    })

    it('resumes name-to-slug sync only after the confirmation', async () => {
        const { wrapper } = await renderSlug()

        wrapper.vm.toggleSlugEdit()
        const confirmConfig = vi.mocked(Modal.confirm).mock.calls[0]?.[0]
        confirmConfig?.onOk?.()
        await nextTick()

        wrapper.vm.checkNameSlugDebounced({ target: { value: 'Tên mới' } })
        await vi.advanceTimersByTimeAsync(600)
        await flushPromises()

        expect(ajax.post).toHaveBeenCalledWith('/slug/check-slug', expect.objectContaining({
            slug: 'Tên mới',
            target_id: 10,
        }))
    })

    it('keeps the existing draft auto-sync behavior', async () => {
        const { wrapper } = await renderSlug(form({
            isPublished: false,
            status: 'status_draft',
            published_at: null,
        }))

        wrapper.vm.checkNameSlugDebounced({ target: { value: 'Bản nháp mới' } })
        await vi.advanceTimersByTimeAsync(600)
        await flushPromises()

        expect(ajax.post).toHaveBeenCalledTimes(1)
    })

    it('keeps the slug locked after an earlier publication is unpublished', async () => {
        const { wrapper } = await renderSlug(form({
            isPublished: false,
            status: 'status_draft',
        }))

        expect(wrapper.vm.publishedRecord).toBe(true)

        wrapper.vm.checkNameSlugDebounced({ target: { value: 'Tên mới' } })
        await vi.advanceTimersByTimeAsync(600)

        expect(ajax.post).not.toHaveBeenCalled()
    })
})
