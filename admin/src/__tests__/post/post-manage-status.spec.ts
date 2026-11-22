import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import ajax from '@/ajax'
import { message } from 'ant-design-vue'
import { renderWithApp } from '@/test-utils/renderWithApp'
import PostManage from '../../../../cms/Content/admin/pages/manage.vue'

vi.mock('ant-design-vue', () => ({
    message: {
        error: vi.fn(),
        success: vi.fn(),
        warning: vi.fn(),
    },
}))

vi.mock('@/config', () => ({
    CATEGORY_TYPE: { article: 0 },
    CATEGORY_TYPE_STR_POST: 'article',
    CACHE_KEY_ACCOUNT_DATA: 'CACHE_KEY_ACCOUNT_DATA',
    COOKIE_KEY_LOCALE: 'iSpa-locale',
    defaultLocale: 'en',
    basePath: '/iPanel/',
    apiUrl: 'https://example.com/',
    url: 'https://example.com/',
    cookie: { get: vi.fn(), set: vi.fn(), remove: vi.fn(), getAll: vi.fn() },
}))

const mockedAjax = vi.mocked(ajax)

const postRow = {
    id: 7,
    uuid: 'post-uuid-1',
    name: 'Tổng thống Hàn Quốc cảm ơn Tòa Thánh',
    slug: 'vatican-news-tong-thong-han-quoc-cam-on-toa-thanh',
    status: 'status_draft',
    isPublished: false,
    isDeleted: false,
    category: { name: 'Thế giới' },
    image: null,
    created_at: '2026-09-19 09:15:00',
    published_at: '2026-09-18 14:30:00',
    author: { id: 3, username: 'editor', name: 'Nguyễn Văn A' },
}

const renderPostManage = async () => {
    const queryClient = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    })

    return renderWithApp(PostManage, {
        route: { name: 'post-post', path: '/post/post' },
        permissions: ['post.viewAny', 'post.update', 'post.delete'],
        global: {
            plugins: [[VueQueryPlugin, { queryClient }]],
        },
        globalStubs: {
            breadcrumbs: { template: '<div><slot /></div>' },
            'i-button': { template: '<button type="button"><slot /></button>' },
            'i-icon': { template: '<span />' },
            'i-filter': {
                template: '<div />',
                methods: { getValues: () => ({}), applyRoute: () => {} },
            },
            'i-bulk-action': { template: '<div />' },
            'image-v3': { template: '<img />' },
            'no-permission-error': { template: '<div />' },
            'a-skeleton': { template: '<div />' },
            'a-table': {
                props: ['columns', 'dataSource'],
                template: `<table>
                    <thead><tr><th v-for="column in columns" :key="column.key || 'actions'" :data-column="column.key">
                        <slot name="headerCell" :column="column">{{ column.title }}</slot>
                    </th></tr></thead>
                    <tbody><tr v-for="row in dataSource" :key="row.uuid">
                        <td v-for="column in columns" :key="column.key || 'actions'" :data-column="column.key">
                            <slot name="bodyCell" :column="column" :record="row" />
                        </td>
                    </tr></tbody>
                </table>`,
            },
        },
    })
}

describe('PostManage status switch', () => {
    beforeEach(() => {
        mockedAjax.get.mockReset()
        mockedAjax.put.mockReset()
        vi.mocked(message.error).mockReset()

        mockedAjax.get.mockImplementation((url) => {
            if (url === 'post') {
                return Promise.resolve({
                    data: {
                        data: {
                            data: [postRow, {
                                ...postRow,
                                id: 8,
                                uuid: 'post-uuid-2',
                                name: 'Bản nháp',
                                published_at: null,
                                author: null,
                            }],
                            currentPage: 1,
                            perPage: 20,
                            total: 2,
                        },
                    },
                })
            }
            if (url === 'category') {
                return Promise.resolve({ data: { data: [] } })
            }

            return Promise.reject(new Error(`Unexpected GET ${url}`))
        })
    })

    it('rolls back the row status and shows the backend error when publish fails', async () => {
        const { wrapper } = await renderPostManage()
        await flushPromises()

        const row = { ...postRow }
        mockedAjax.put.mockRejectedValueOnce({
            response: {
                data: {
                    message: 'Cannot publish: 1 image(s) have not been downloaded yet.',
                },
            },
        })

        wrapper.vm.onChangeStatus('status_published', row)
        await flushPromises()

        expect(mockedAjax.put).toHaveBeenCalledWith('post/post-uuid-1/quick', {
            status: 'status_published',
        })
        expect(row.status).toBe('status_draft')
        expect(message.error).toHaveBeenCalledWith(
            'Cannot publish: 1 image(s) have not been downloaded yet.'
        )
    })

    it('shows created time, published time and author on each post row', async () => {
        const { wrapper } = await renderPostManage()
        await flushPromises()

        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(2)
        expect(wrapper.find('th[data-column="created_at"]').exists()).toBe(true)
        expect(wrapper.find('th[data-column="published_at"]').exists()).toBe(true)
        expect(wrapper.find('th[data-column="author"]').exists()).toBe(true)
        expect(rows[0].find('td[data-column="created_at"]').text()).toBe('19/09/2026 09:15')
        expect(rows[0].find('td[data-column="published_at"]').text()).toBe('18/09/2026 14:30')
        expect(rows[0].find('td[data-column="author"]').text()).toBe('Nguyễn Văn A')
        expect(rows[1].find('td[data-column="published_at"]').text()).toBe('—')
        expect(rows[1].find('td[data-column="author"]').text()).toBe('—')
    })
})
