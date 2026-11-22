import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import SupportRequests from '../../../../cms/Core/admin/pages/setting/modules/support-requests.vue'

/*
 * the "Yêu cầu hỗ trợ" sub-view of the modules page. The Webmaster sees this site's support requests (module,
 * subject, status, last reply), opens a thread and replies; a reply cannot be sent twice and an unreachable hub shows a
 * plain message with a retry.
 */

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const messageMock = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('ant-design-vue', () => ({ message: messageMock }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k),
        te: () => true,
        locale: { value: 'vi' },
    }),
}))

const $t = (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k)
const stubs = {
    'a-skeleton': defineComponent({ setup: () => () => h('div') }),
    'a-empty': defineComponent({ props: ['description'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }, p.description) }),
    'a-alert': defineComponent({ props: ['message'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'], role: 'alert' }, p.message) }),
    'a-tag': defineComponent({ setup: (_p, { slots, attrs }) => () => h('span', { 'data-testid': attrs['data-testid'] }, slots.default?.()) }),
    'a-textarea': defineComponent({
        props: ['value', 'disabled'],
        emits: ['update:value'],
        setup: (p, { emit, attrs }) => () =>
            h('textarea', { value: p.value ?? '', disabled: p.disabled, 'data-testid': attrs['data-testid'], onInput: (e: Event) => emit('update:value', (e.target as HTMLTextAreaElement).value) }),
    }),
    'i-button': defineComponent({
        props: ['disabled', 'loading', 'nativeType'],
        emits: ['click'],
        setup: (p, { slots, emit, attrs }) => () =>
            h('button', { type: p.nativeType || 'button', disabled: p.disabled || p.loading, 'data-testid': attrs['data-testid'], onClick: () => emit('click') }, slots.default?.()),
    }),
}

const UUID_A = '11111111-1111-4111-8111-111111111111'
const UUID_B = '22222222-2222-4222-8222-222222222222'
const ticket = (over: Record<string, unknown> = {}) => ({
    uuid: UUID_A,
    number: 'T-100',
    module: 'Helpdesk',
    subject: 'Lỗi đồng bộ',
    status: 'open',
    created_at: '2026-11-14T08:00:00+00:00',
    last_activity_at: '2026-11-15T09:00:00+00:00',
    last_reply_at: '2026-11-15T09:00:00+00:00',
    ...over,
})
const list = () => ({ data: { data: { items: [ticket(), ticket({ uuid: UUID_B, number: 'T-101', module: null, subject: 'Hỏi giá', status: 'closed', last_reply_at: null })] } } })
const thread = (status = 'open') => ({
    data: {
        data: {
            ticket: ticket({ status }),
            messages: [
                { uuid: 'm1', author: 'site', body: 'Không đồng bộ được vé <img src=x onerror=alert(1)>', created_at: '2026-11-14T08:00:00+00:00' },
                { uuid: 'm2', author: 'support', body: 'Chúng tôi đang kiểm tra.', created_at: '2026-11-15T09:00:00+00:00' },
            ],
        },
    },
})
const errorWith = (status: number, errorCode?: string) => ({ response: { status, data: { errorCode } } })

const mountView = async () => {
    const wrapper = mount(SupportRequests, {
        global: { mocks: { $t }, stubs },
        props: { locale: 'vi', labelFor: (name: string) => (name === 'Helpdesk' ? 'Hỗ trợ khách hàng' : name) },
    })
    await flushPromises()
    return wrapper
}

beforeEach(() => {
    ajaxMock.get.mockReset()
    ajaxMock.post.mockReset()
    messageMock.success.mockReset()
    ajaxMock.get.mockImplementation((url: string) => Promise.resolve(url.endsWith('/support') ? list() : thread()))
})

describe('SupportRequests', () => {
    it('lists the requests with module label, subject, status and last reply', async () => {
        const wrapper = await mountView()
        expect(ajaxMock.get).toHaveBeenCalledWith('utilities/modules/support', expect.anything())
        const rows = wrapper.findAll('[data-testid=support-request-row]')
        expect(rows).toHaveLength(2)
        expect(rows[0].text()).toContain('Hỗ trợ khách hàng')
        expect(rows[0].text()).toContain('Lỗi đồng bộ')
        expect(rows[0].text()).toContain('text.modules.support.requests.status.open')
        expect(rows[1].text()).toContain('text.modules.support.requests.status.closed')
        // No reply yet: a dash, never a raw null; a ticket id never shows.
        expect(rows[1].text()).toContain('-')
        expect(wrapper.html()).not.toContain(UUID_A)
        expect(wrapper.html()).not.toContain('null')
    })

    it('shows an empty state when there are no requests', async () => {
        ajaxMock.get.mockResolvedValue({ data: { data: { items: [] } } })
        const wrapper = await mountView()
        expect(wrapper.find('[data-testid=support-requests-empty]').exists()).toBe(true)
    })

    it('shows a plain message with retry when the hub cannot be reached', async () => {
        ajaxMock.get.mockRejectedValue(errorWith(502, 'MODULE_SUPPORT_UNAVAILABLE'))
        const wrapper = await mountView()
        const alert = wrapper.find('[data-testid=support-requests-error]')
        expect(alert.text()).toContain('text.modules.support.requests.error.unavailable')
        ajaxMock.get.mockResolvedValue(list())
        await wrapper.find('[data-testid=support-requests-retry]').trigger('click')
        await flushPromises()
        expect(wrapper.findAll('[data-testid=support-request-row]')).toHaveLength(2)
    })

    it('opens a thread, shows messages as plain text and goes back to the list', async () => {
        const wrapper = await mountView()
        await wrapper.findAll('[data-testid=support-request-open]')[0].trigger('click')
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenCalledWith(`utilities/modules/support/${UUID_A}`, expect.anything())
        const messages = wrapper.findAll('[data-testid=support-message]')
        expect(messages).toHaveLength(2)
        expect(messages[0].find('img').exists()).toBe(false)
        expect(messages[0].text()).toContain('<img')
        expect(messages[0].text()).toContain('text.modules.support.requests.author.site')
        expect(messages[1].text()).toContain('text.modules.support.requests.author.support')
        await wrapper.find('[data-testid=support-thread-back]').trigger('click')
        expect(wrapper.findAll('[data-testid=support-request-row]')).toHaveLength(2)
    })

    it('sends a reply once, even when the button is pressed twice, then shows the refreshed thread', async () => {
        let release: (value: unknown) => void = () => {}
        ajaxMock.post.mockReturnValue(new Promise((resolve) => (release = resolve)))
        const wrapper = await mountView()
        await wrapper.findAll('[data-testid=support-request-open]')[0].trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=support-reply-send]').attributes('disabled')).toBeDefined()
        await wrapper.find('[data-testid=support-reply-body]').setValue('  Đã thử lại, vẫn lỗi  ')
        await wrapper.find('[data-testid=support-reply-send]').trigger('click')
        await wrapper.find('[data-testid=support-reply-send]').trigger('click')
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
        expect(ajaxMock.post).toHaveBeenCalledWith(`utilities/modules/support/${UUID_A}/reply`, { body: 'Đã thử lại, vẫn lỗi' }, expect.anything())
        release({ data: { data: { message: null, status: 'open' } } })
        await flushPromises()
        expect(messageMock.success).toHaveBeenCalled()
        expect((wrapper.find('[data-testid=support-reply-body]').element as HTMLTextAreaElement).value).toBe('')
        // The thread is read again after the reply.
        expect(ajaxMock.get.mock.calls.filter(([url]) => url === `utilities/modules/support/${UUID_A}`)).toHaveLength(2)
    })

    it('keeps the typed reply and says what went wrong when the reply is refused', async () => {
        ajaxMock.post.mockRejectedValue(errorWith(429, 'MODULE_SUPPORT_RATE_LIMITED'))
        const wrapper = await mountView()
        await wrapper.findAll('[data-testid=support-request-open]')[0].trigger('click')
        await flushPromises()
        await wrapper.find('[data-testid=support-reply-body]').setValue('Thử lại')
        await wrapper.find('[data-testid=support-reply-send]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=support-reply-error]').text()).toContain('text.modules.support.requests.error.rate_limited')
        expect((wrapper.find('[data-testid=support-reply-body]').element as HTMLTextAreaElement).value).toBe('Thử lại')
        expect(wrapper.find('[data-testid=support-reply-send]').attributes('disabled')).toBeUndefined()
    })

    it('offers no reply box for a closed request and points to a new request instead', async () => {
        ajaxMock.get.mockImplementation((url: string) => Promise.resolve(url.endsWith('/support') ? list() : thread('closed')))
        const wrapper = await mountView()
        await wrapper.findAll('[data-testid=support-request-open]')[1].trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=support-reply-body]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=support-thread-closed]').exists()).toBe(true)
    })

    it('shows a plain message with a way back when the request is not found or the thread cannot load', async () => {
        const wrapper = await mountView()
        ajaxMock.get.mockRejectedValue(errorWith(404, 'MODULE_SUPPORT_TICKET_NOT_FOUND'))
        await wrapper.findAll('[data-testid=support-request-open]')[0].trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=support-thread-error]').text()).toContain('text.modules.support.requests.error.ticket_not_found')
        expect(wrapper.find('[data-testid=support-thread-back]').exists()).toBe(true)
    })
})
