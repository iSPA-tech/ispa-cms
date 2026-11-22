import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import ajax from '@/ajax'
import { renderWithApp } from '@/test-utils/renderWithApp'
import CommentPage from '../../../../cms/Content/admin/pages/comment.vue'

vi.mock('ant-design-vue', () => ({
    notification: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
    message: {
        error: vi.fn(),
        success: vi.fn(),
        warning: vi.fn(),
    },
}))

vi.mock('@/config', () => ({
    commentItemFilter: { page: 1, pageSize: 20, keywords: '', status: 'all', itemId: null },
    commentPlaceFilter: { page: 1, pageSize: 20, keywords: '', status: 'all', placeId: null },
    CACHE_KEY_ACCOUNT_DATA: 'CACHE_KEY_ACCOUNT_DATA',
    COOKIE_KEY_LOCALE: 'iSpa-locale',
    defaultLocale: 'en',
    basePath: '/iPanel/',
    apiUrl: 'https://example.com/',
    url: 'https://example.com/',
    cookie: { get: vi.fn(), set: vi.fn(), remove: vi.fn(), getAll: vi.fn() },
}))

const mockedAjax = vi.mocked(ajax)

const baseRow = {
    id: 11,
    name: 'Maria Nguyen',
    subject: null,
    content: 'Bài viết rất hay, cảm ơn tác giả.',
    target_type: 'Cms\\Content\\Models\\Post',
    target_id: 5,
    status: 'status_pending',
    contact: { id: 2, name: 'Maria Nguyen', email: 'maria@example.com' },
    created_at: '2026-11-19 08:05:00',
    deleted_at: null,
    image: null,
    target: { id: 5, name: 'Thư mục vụ mùa Vọng' },
    comment_status: { name: 'Chờ duyệt', name_alias: 'Pending' },
    isPublished: false,
    isDeleted: false,
}
const rejectedRow = {
    ...baseRow,
    id: 12,
    content: 'Spam link',
    status: 'rejected',
    contact: null,
    comment_status: null,
}
const publishedRow = {
    ...baseRow,
    id: 13,
    content: 'Đã đọc.',
    status: 'status_published',
    isPublished: true,
}
const bannedRow = {
    ...baseRow,
    id: 15,
    content: 'Trang này LỪA ĐẢO nhé, đừng tin.',
    banned_words: ['lua dao'],
}
const trashedRow = { ...baseRow, id: 14, content: 'Đã xóa', isDeleted: true, deleted_at: '2026-11-19 09:00:00' }

const renderComments = async (query = {}) => {
    const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    })

    return renderWithApp(CommentPage, {
        route: { name: 'post-comment', path: '/post/comment', query },
        permissions: ['comment.viewAny', 'comment.update'],
        routes: [{ path: '/post/view/:uuid', name: 'post-view', component: { template: '<div />' } }],
        globalMocks: { $isMobile: () => false },
        global: {
            plugins: [[VueQueryPlugin, { queryClient }]],
        },
        globalStubs: {
            breadcrumbs: { template: '<div><slot /></div>' },
            'i-button': { template: '<button type="button"><slot name="icon" /><slot /></button>' },
            'a-popconfirm': {
                props: ['title'],
                emits: ['confirm'],
                template: `<span class="popconfirm"><slot />
                    <button class="popconfirm-ok" type="button" @click="$emit('confirm')">{{ title }}</button>
                </span>`,
            },
            'a-textarea': {
                props: ['value'],
                emits: ['update:value'],
                template: '<textarea :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
            },
            'a-skeleton': { template: '<div class="skeleton" />' },
            'i-icon': { props: ['icon'], template: '<i :data-icon="icon" />' },
            'i-filter': { template: '<div />' },
            'i-bulk-action': { template: '<div />' },
            'no-permission-error': { template: '<div />' },
            'a-drawer': {
                props: ['open'],
                template: '<aside v-if="open"><slot /><footer><slot name="footer" /></footer></aside>',
            },
            'a-tabs': {
                props: ['activeKey'],
                template: '<div :data-active="activeKey"><slot /></div>',
            },
            'a-tab-pane': { props: ['tab'], template: '<span class="tab">{{ tab }}</span>' },
            'a-table': {
                props: ['columns', 'dataSource'],
                template: `<table>
                    <thead><tr><th v-for="column in columns" :key="column.key" :data-column="column.key">{{ column.title }}</th></tr></thead>
                    <tbody><tr v-for="row in dataSource" :key="row.id" :data-row="row.id">
                        <td v-for="column in columns" :key="column.key" :data-column="column.key">
                            <slot name="bodyCell" :column="column" :record="row" />
                        </td>
                    </tr></tbody>
                </table>`,
            },
        },
    })
}

const cell = (wrapper, id, column) => wrapper.find(`tr[data-row="${id}"] td[data-column="${column}"]`)

let detail15 = { ...bannedRow }

describe('Comment moderation list', () => {
    beforeEach(() => {
        detail15 = { ...bannedRow }
        mockedAjax.get.mockReset()
        mockedAjax.put.mockReset()

        mockedAjax.get.mockImplementation((url) => {
            if (url === '/comment') {
                return Promise.resolve({
                    data: {
                        data: {
                            data: [baseRow, rejectedRow, publishedRow, trashedRow, bannedRow],
                            currentPage: 1,
                            perPage: 20,
                            total: 5,
                        },
                    },
                })
            }
            if (url === '/comment/11') {
                return Promise.resolve({
                    data: {
                        data: { ...baseRow, target: { ...baseRow.target, uuid: 'post-uuid-5' } },
                    },
                })
            }

            if (url === '/comment/15') {
                return Promise.resolve({ data: { data: detail15 } })
            }
            if (url === '/comment-settings') {
                return Promise.resolve({
                    data: { status: 'success', data: { banned_words: ['lua dao', 'spam'] } },
                })
            }

            return Promise.reject(new Error(`Unexpected GET ${url}`))
        })
        mockedAjax.put.mockResolvedValue({ data: { status: 'success' } })
    })

    it('shows the comment text, sender, date and post on each row, without an image column', async () => {
        const { wrapper } = await renderComments()
        await flushPromises()

        expect(cell(wrapper, 11, 'comment').text()).toContain('Bài viết rất hay, cảm ơn tác giả.')
        expect(cell(wrapper, 11, 'author').text()).toContain('Maria Nguyen')
        expect(cell(wrapper, 11, 'author').text()).toContain('maria@example.com')
        expect(cell(wrapper, 11, 'author').text()).toContain('19/11/2026 08:05')
        expect(cell(wrapper, 11, 'target').text()).toBe('Thư mục vụ mùa Vọng')
        expect(wrapper.find('img').exists()).toBe(false)
        expect(wrapper.find('th[data-column="confirm"]').exists()).toBe(false)
    })

    it('labels every status with a translated tag, including rejected', async () => {
        const { wrapper } = await renderComments()
        await flushPromises()

        expect(cell(wrapper, 11, 'status').text()).toBe('text.comment.status_pending')
        expect(cell(wrapper, 12, 'status').text()).toBe('text.comment.status_rejected')
        expect(cell(wrapper, 12, 'status').text()).not.toContain('rejected ')
        expect(cell(wrapper, 13, 'status').text()).toBe('text.comment.status_published')
        expect(cell(wrapper, 14, 'status').text()).toBe('text.product.filter_deleted')
    })

    it('offers only the actions that fit the status and has no dead edit button', async () => {
        const { wrapper } = await renderComments()
        await flushPromises()

        const actions = (id) =>
            cell(wrapper, id, 'action')
                .findAll('[data-testid]')
                .map((node) => node.attributes('data-testid'))

        expect(actions(11)).toEqual(['comment-approve', 'comment-reject', 'comment-view', 'comment-delete'])
        expect(actions(12)).toEqual(['comment-approve', 'comment-view', 'comment-delete'])
        expect(actions(13)).toEqual(['comment-reject', 'comment-view', 'comment-delete'])
        expect(actions(14)).toEqual(['comment-restore'])
        expect(wrapper.find('[data-icon="edit"]').exists()).toBe(false)
        expect(wrapper.find('[data-icon="pencil"]').exists()).toBe(false)
    })

    it('approves through the confirm endpoint and rejects through the status endpoint', async () => {
        const { wrapper } = await renderComments()
        await flushPromises()

        await cell(wrapper, 11, 'action').find('[data-testid="comment-approve"]').trigger('click')
        await flushPromises()
        expect(mockedAjax.put).toHaveBeenLastCalledWith('/comment/11/confirm', {})

        await cell(wrapper, 11, 'action').find('[data-testid="comment-reject"]').trigger('click')
        await flushPromises()
        expect(mockedAjax.put).toHaveBeenLastCalledWith('/comment/11/status', { type: 'rejected' })

        await cell(wrapper, 14, 'action').find('[data-testid="comment-restore"]').trigger('click')
        await flushPromises()
        expect(mockedAjax.put).toHaveBeenLastCalledWith('/comment/14/restore', {})
    })

    it('opens the detail with the comment first and a link to the post', async () => {
        const { wrapper } = await renderComments()
        await flushPromises()

        await cell(wrapper, 11, 'comment').find('[data-testid="comment-text"]').trigger('click')
        await flushPromises()

        const detail = wrapper.find('[data-testid="comment-detail"]')
        expect(detail.text()).toContain('Bài viết rất hay, cảm ơn tác giả.')
        expect(detail.text()).toContain('maria@example.com')
        expect(detail.find('[data-testid="comment-target-link"]').text()).toBe('Thư mục vụ mùa Vọng')
        expect(wrapper.find('aside footer [data-testid="comment-approve"]').exists()).toBe(true)
        expect(wrapper.find('aside footer [data-testid="comment-reject"]').exists()).toBe(true)
    })

    it('requests the status of the open tab and switches tabs through the URL', async () => {
        const { wrapper, router } = await renderComments({ status: 'status_pending' })
        await flushPromises()

        expect(mockedAjax.get).toHaveBeenCalledWith(
            '/comment',
            expect.objectContaining({
                params: expect.objectContaining({ status: 'status_pending', type: 'Post' }),
            })
        )
        expect(wrapper.find('[data-testid="comment-status-tabs"]').attributes('data-active')).toBe(
            'status_pending'
        )

        wrapper.vm.changeStatus('rejected')
        await flushPromises()
        expect(router.currentRoute.value.query.status).toBe('rejected')
        expect(mockedAjax.get).toHaveBeenLastCalledWith(
            '/comment',
            expect.objectContaining({ params: expect.objectContaining({ status: 'rejected' }) })
        )
    })

    it('flags a row whose sender or text matches a banned word', async () => {
        const { wrapper } = await renderComments()
        await flushPromises()

        expect(cell(wrapper, 15, 'status').find('[data-testid="comment-banned-flag"]').text()).toBe(
            'text.comment.banned_flag'
        )
        expect(cell(wrapper, 15, 'comment').find('[data-testid="comment-banned-flag"]').exists()).toBe(true)
        expect(cell(wrapper, 11, 'status').find('[data-testid="comment-banned-flag"]').exists()).toBe(false)
    })

    it('asks before approving a comment with banned words and approves a clean one at once', async () => {
        const { wrapper } = await renderComments()
        await flushPromises()

        // Clean comment: no confirm around the button, one click approves.
        expect(cell(wrapper, 11, 'action').find('.popconfirm [data-testid="comment-approve"]').exists()).toBe(false)
        await cell(wrapper, 11, 'action').find('[data-testid="comment-approve"]').trigger('click')
        await flushPromises()
        expect(mockedAjax.put).toHaveBeenCalledTimes(1)
        expect(mockedAjax.put).toHaveBeenLastCalledWith('/comment/11/confirm', {})

        // Flagged comment: the click alone sends nothing, the confirm does.
        mockedAjax.put.mockClear()
        const approve = cell(wrapper, 15, 'action').find('.popconfirm [data-testid="comment-approve"]')
        expect(approve.exists()).toBe(true)
        await approve.trigger('click')
        await flushPromises()
        expect(mockedAjax.put).not.toHaveBeenCalled()

        const confirm = approve.element.closest('.popconfirm')?.querySelector('.popconfirm-ok') as HTMLElement
        expect(confirm.textContent).toContain('text.comment.banned_confirm')
        confirm.click()
        await flushPromises()
        expect(mockedAjax.put).toHaveBeenCalledTimes(1)
        expect(mockedAjax.put).toHaveBeenLastCalledWith('/comment/15/confirm', {})
    })

    it('lists the matched words in the detail and marks them as text segments', async () => {
        const { wrapper } = await renderComments()
        await flushPromises()

        await cell(wrapper, 15, 'comment').find('[data-testid="comment-text"]').trigger('click')
        await flushPromises()

        expect(wrapper.find('[data-testid="comment-banned-found"]').text()).toContain('text.comment.banned_found')
        const box = wrapper.find('[data-testid="comment-detail-text"]')
        const hits = box.findAll('[data-testid="comment-banned-hit"]')
        expect(hits.map((hit) => hit.text())).toEqual(['LỪA ĐẢO'])
        expect(box.text()).toBe('Trang này LỪA ĐẢO nhé, đừng tin.')
    })

    it('renders comment markup as plain text, never as HTML', async () => {
        detail15 = { ...bannedRow, content: '<img src=x onerror=alert(1)> lừa đảo <b>x</b>' }
        const { wrapper } = await renderComments()
        await flushPromises()

        await cell(wrapper, 15, 'comment').find('[data-testid="comment-text"]').trigger('click')
        await flushPromises()

        const box = wrapper.find('[data-testid="comment-detail-text"]')
        expect(box.find('img').exists()).toBe(false)
        expect(box.find('b').exists()).toBe(false)
        expect(box.text()).toBe('<img src=x onerror=alert(1)> lừa đảo <b>x</b>')
        expect(box.findAll('[data-testid="comment-banned-hit"]').map((hit) => hit.text())).toEqual(['lừa đảo'])
    })

    it('saves an edited comment with the unchanged name, then reloads the detail', async () => {
        const { wrapper } = await renderComments()
        await flushPromises()

        await cell(wrapper, 15, 'comment').find('[data-testid="comment-text"]').trigger('click')
        await flushPromises()
        await wrapper.find('[data-testid="comment-edit"]').trigger('click')

        const input = wrapper.find('[data-testid="comment-edit-input"]')
        expect((input.element as HTMLTextAreaElement).value).toBe('Trang này LỪA ĐẢO nhé, đừng tin.')
        await input.setValue('Trang này chưa tốt.')

        detail15 = { ...bannedRow, content: 'Trang này chưa tốt.', banned_words: [] }
        mockedAjax.get.mockClear()
        await wrapper.find('[data-testid="comment-edit-save"]').trigger('click')
        await flushPromises()

        expect(mockedAjax.put).toHaveBeenCalledWith('/comment/15', {
            name: 'Maria Nguyen',
            content: 'Trang này chưa tốt.',
        })
        expect(mockedAjax.get.mock.calls.map((call) => call[0])).toEqual(
            expect.arrayContaining(['/comment/15', '/comment'])
        )
        expect(wrapper.find('[data-testid="comment-edit-input"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="comment-detail-text"]').text()).toBe('Trang này chưa tốt.')
        expect(wrapper.find('[data-testid="comment-banned-found"]').exists()).toBe(false)
    })

    it('loads the banned word list in the settings drawer and saves the lines as an array', async () => {
        mockedAjax.put.mockResolvedValue({
            data: { status: 'success', message: 'Saved', data: { banned_words: ['lua dao', 'cờ bạc'] } },
        })
        const { wrapper } = await renderComments()
        await flushPromises()

        await wrapper.find('[data-testid="comment-settings-open"]').trigger('click')
        await flushPromises()

        expect(mockedAjax.get).toHaveBeenCalledWith('/comment-settings')
        const input = wrapper.find('[data-testid="comment-settings-input"]')
        expect((input.element as HTMLTextAreaElement).value).toBe('lua dao\nspam')

        await input.setValue('  lua dao \n\n cờ bạc\n')
        mockedAjax.get.mockClear()
        await wrapper.find('[data-testid="comment-settings-save"]').trigger('click')
        await flushPromises()

        expect(mockedAjax.put).toHaveBeenCalledWith('/comment-settings', {
            banned_words: ['lua dao', 'cờ bạc'],
        })
        expect(mockedAjax.get).toHaveBeenCalledWith('/comment', expect.anything())
        expect(wrapper.find('[data-testid="comment-settings"]').exists()).toBe(false)
    })

    it('shows an error with a retry when the settings cannot be loaded', async () => {
        const { wrapper } = await renderComments()
        await flushPromises()

        const listAndDetail = mockedAjax.get.getMockImplementation()
        mockedAjax.get.mockImplementation((url, ...rest) =>
            url === '/comment-settings' ? Promise.reject(new Error('down')) : listAndDetail(url, ...rest)
        )
        await wrapper.find('[data-testid="comment-settings-open"]').trigger('click')
        await flushPromises()

        expect(wrapper.find('[data-testid="comment-settings-error"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="comment-settings-input"]').exists()).toBe(false)
    })
})
