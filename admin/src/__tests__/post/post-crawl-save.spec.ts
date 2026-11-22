import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ajax from '@/ajax'
import PostCrawl from '../../../../cms/Content/admin/pages/crawl.vue'

vi.mock('@/ajax', () => ({
    default: {
        post: vi.fn(),
    },
}))

const stubs = {
    breadcrumbs: true,
    'i-icon': true,
    IContentsView: true,
    'a-form': {
        props: ['model'],
        emits: ['finish'],
        template: '<form @submit.prevent="$emit(\'finish\', model)"><slot /></form>',
    },
    'a-form-item': { template: '<div><slot /></div>' },
    'a-input': true,
    'a-textarea': true,
    'a-select': true,
    'a-checkbox': { template: '<label><slot /></label>' },
    'a-button': {
        props: ['htmlType', 'disabled', 'loading'],
        template: '<button :type="htmlType || \'button\'" data-button><slot /></button>',
    },
}

const crawlAnswer = (canSave: boolean) => ({
    data: {
        status: 'success',
        data: {
            thumbnail: '',
            title: 'Sample title',
            description: 'Sample summary',
            contents: [],
            can_save: canSave,
        },
    },
})

const crawl = async (canSave: boolean) => {
    vi.mocked(ajax.post).mockResolvedValueOnce(crawlAnswer(canSave))
    const wrapper = mount(PostCrawl, {
        global: { mocks: { $t: (key: string) => key }, stubs },
    })
    await wrapper.findAll('form')[0].trigger('submit')
    await flushPromises()

    return wrapper
}

const saveButton = (wrapper: Awaited<ReturnType<typeof crawl>>) =>
    wrapper.findAll('[data-button]').find((button) => button.text().includes('nav.b.save'))

describe('crawl post page', () => {
    beforeEach(() => {
        vi.mocked(ajax.post).mockReset()
    })

    it('shows the crawled article without Save where nobody saves crawled articles', async () => {
        const wrapper = await crawl(false)

        expect(wrapper.findAll('form')).toHaveLength(2)
        expect(wrapper.text()).toContain('Sample title')
        expect(saveButton(wrapper)).toBeUndefined()

        await wrapper.findAll('form')[1].trigger('submit')
        await flushPromises()
        expect(ajax.post).toHaveBeenCalledTimes(1)
    })

    it('shows Save and sends the article where the site saves crawled articles', async () => {
        const wrapper = await crawl(true)

        expect(saveButton(wrapper)).toBeDefined()

        vi.mocked(ajax.post).mockResolvedValueOnce({ data: { status: 'success' } })
        await wrapper.findAll('form')[1].trigger('submit')
        await flushPromises()

        expect(ajax.post).toHaveBeenLastCalledWith(
            'post/crawler/save',
            expect.objectContaining({ title: 'Sample title' })
        )
        expect(vi.mocked(ajax.post).mock.calls[1][1].original).not.toHaveProperty('can_save')
    })
})
