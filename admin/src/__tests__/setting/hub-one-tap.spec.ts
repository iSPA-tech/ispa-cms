import { flushPromises, mount } from '@vue/test-utils'
import Antd from 'ant-design-vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, reactive } from 'vue'
import { createI18n } from 'vue-i18n'

// One-tap "Kết nối iSPA Hub": the drawer starts an attempt and sends the
// browser to ispa.tech; the callback screen finishes it with what ispa.tech sent back. The manual site address + secret
// form stays behind "Kết nối bằng mã thủ công".
const state = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(),
    replace: vi.fn(),
    push: vi.fn(),
    route: { query: {} as Record<string, string> },
}))

vi.mock('vue-i18n', async () => await vi.importActual('vue-i18n'))
vi.mock('@/ajax', () => ({ default: { post: state.post } }))
vi.mock('axios', () => ({ default: { get: state.get, post: vi.fn() } }))
vi.mock('vue-router', () => ({ useRoute: () => state.route, useRouter: () => ({ replace: state.replace, push: state.push }) }))
vi.mock('@/config', () => ({ apiUrl: 'https://site.example/', cmsTitle: 'iSPA CMS', logoAuthUrl: 'https://site.example/logo.png' }))
vi.mock('@/pages/auth/connect-hub.vue', async () => {
    const { defineComponent: dc, h: hh } = await import('vue')

    return { default: dc({ props: ['status', 'unavailable', 'adminSubmit'], setup: () => () => hh('div', { 'data-testid': 'connect-hub-stub' }) }) }
})

/** A copy of the rows the screens show (cms/Core translate-admin-authored.php). */
const wording: Record<string, string> = {
    'text.site_connect.title': 'Kết nối iSPA Hub',
    'text.site_connect.unavailable': 'Website chưa trả lời. Hãy thử lại.',
    'text.site_connect.env_wins': 'Đã lưu, nhưng cấu hình máy chủ (.env) vẫn được ưu tiên.',
    'text.site_connect.pending_title': 'Đang chờ iSPA Hub kiểm tra tên miền',
    'text.site_connect.pending_text': 'iSPA Hub đã nhận mã và đang kiểm tra website này.',
    'text.site_connect.reason.wrong_answer': 'Mã bí mật không khớp với website này. Kiểm tra lại mã.',
    'text.hub_connect.one_tap_intro': 'Bấm nút bên dưới, đăng nhập ispa.tech rồi bấm Cho phép. Bạn không phải chép mã nào.',
    'text.hub_connect.start': 'Kết nối iSPA Hub',
    'text.hub_connect.waiting': 'Đang chờ bạn cho phép trên ispa.tech…',
    'text.hub_connect.manual_link': 'Kết nối bằng mã thủ công',
    'text.hub_connect.one_tap_link': 'Kết nối bằng tài khoản ispa.tech',
    'text.hub_connect.finishing': 'Đang hoàn tất kết nối…',
    'text.hub_connect.connected': 'Đã kết nối với iSPA Hub.',
    'text.hub_connect.open_modules': 'Mở trang Module',
    'text.hub_connect.replace_text': 'Website này đang kết nối với iSPA Hub. Kết nối lại sẽ thay thông tin cũ.',
    'text.hub_connect.replace_confirm': 'Kết nối lại',
    'text.hub_connect.cancel': 'Huỷ',
    'text.hub_connect.forbidden': 'Tài khoản của bạn chưa có quyền kết nối website với iSPA Hub.',
    'text.hub_connect.err_state_invalid': 'Lần kết nối này đã hết hạn hoặc không còn dùng được. Hãy bấm Kết nối iSPA Hub để làm lại.',
    'text.hub_connect.err_denied': 'Bạn đã bấm Huỷ trên ispa.tech nên website chưa được kết nối.',
    'text.hub_connect.err_domain_taken': 'Website này đang thuộc một tài khoản iSPA khác.',
    'text.hub_connect.err_site_disabled': 'Website này đang bị tắt kết nối trong tài khoản iSPA. Hãy bật lại trên ispa.tech rồi thử lại.',
    'text.hub_connect.err_exchange_failed': 'iSPA Hub chưa xác nhận được kết nối. Bước này phải xong trong 60 giây sau khi bạn bấm Cho phép. Hãy bấm Kết nối iSPA Hub để làm lại, hoặc kết nối bằng mã thủ công.',
    'text.hub_connect.err_site_unknown': 'Chưa biết địa chỉ của website này. Hãy kết nối bằng mã thủ công.',
    'text.hub_connect.err_refused': 'iSPA Hub không nhận kết nối này. Hãy thử lại hoặc kết nối bằng mã thủ công.',
    'text.hub_connect.err_managed_by_env': 'Kết nối iSPA Hub của website này được đặt trong cấu hình máy chủ (.env) nên không đổi được từ trang quản trị. Hãy nhờ người phụ trách kỹ thuật thay đổi.',
    'text.hub_connect.err_unreachable': 'Chưa nối được tới iSPA Hub. Hãy thử lại sau ít phút hoặc kết nối bằng mã thủ công.',
}

const nest = (flat: Record<string, string>) => {
    const tree: Record<string, any> = {}
    for (const [key, value] of Object.entries(flat)) {
        const parts = key.split('.')
        let node = tree
        for (const part of parts.slice(0, -1)) node = node[part] ??= {}
        node[parts[parts.length - 1]] = value
    }

    return tree
}

const i18n = () => createI18n({ legacy: false, locale: 'vi', messages: { vi: nest(wording) }, missingWarn: false, fallbackWarn: false })
const Drawer = defineComponent({ props: ['open'], setup: (p, { slots }) => () => (p.open ? h('div', slots.default?.()) : null) })
const rejection = (status: number, body: Record<string, unknown>) => ({ response: { status, data: body } })
const refusal = (status: number, code: string) => rejection(status, { status: 'error', error_code: code, message: 'server wording', data: { message_key: null } })

/** Where the screens sent the browser (the shared `browser.go` is replaced, so nothing really navigates). */
const went: string[] = []

const mountDrawer = async (props: Record<string, unknown> = {}) => {
    const oneTap = await import('../../../../cms/Core/admin/pages/setting/modules/hub-one-tap.js')
    vi.spyOn(oneTap.browser, 'go').mockImplementation((url: string) => void went.push(url))
    const HubConnectDrawer = (await import('../../../../cms/Core/admin/pages/setting/modules/hub-connect-drawer.vue')).default
    const wrapper = mount(HubConnectDrawer, { props: { open: true, ...props }, global: { plugins: [Antd, i18n()], stubs: { 'a-drawer': Drawer } } })
    await flushPromises()

    return wrapper
}

const mountCallback = async (query: Record<string, string>) => {
    state.route = reactive({ query })
    const oneTap = await import('../../../../cms/Core/admin/pages/setting/modules/hub-one-tap.js')
    vi.spyOn(oneTap.browser, 'go').mockImplementation((url: string) => void went.push(url))
    const Callback = (await import('../../../../cms/Core/admin/pages/setting/hub-connect-callback.vue')).default
    const wrapper = mount(Callback, { global: { plugins: [Antd, i18n()] } })
    await flushPromises()

    return wrapper
}

beforeEach(() => {
    state.post.mockReset()
    state.get.mockReset()
    state.replace.mockReset()
    state.push.mockReset()
    state.get.mockResolvedValue({ data: { data: { status: 'not_connected', message_key: 'text.site_status.not_configured', expires_at: null } } })
    went.length = 0
})

describe('HubConnectDrawer (one tap)', () => {
    it('offers one button first and keeps the manual form behind a link', async () => {
        const wrapper = await mountDrawer()

        expect(wrapper.get('[data-testid="hub-one-tap-start"]').text()).toBe('Kết nối iSPA Hub')
        expect(wrapper.get('[data-testid="hub-connect-manual"]').text()).toBe('Kết nối bằng mã thủ công')
        expect(wrapper.find('[data-testid="connect-hub-stub"]').exists()).toBe(false)
        expect(wrapper.text()).not.toMatch(/text\.[a-z_]+\./)

        await wrapper.get('[data-testid="hub-connect-manual"]').trigger('click')
        expect(wrapper.find('[data-testid="connect-hub-stub"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="hub-one-tap-start"]').exists()).toBe(false)

        // And back to the one button.
        await wrapper.get('[data-testid="hub-connect-one-tap"]').trigger('click')
        expect(wrapper.find('[data-testid="hub-one-tap-start"]').exists()).toBe(true)
        expect(state.post).not.toHaveBeenCalled()
    })

    it('starts an attempt, says it waits for the approval on ispa.tech and sends the browser there', async () => {
        state.post.mockResolvedValue({ data: { data: { redirect_url: 'https://ispa.tech/portal/connect-site?domain=site.example&state=s' } } })
        const wrapper = await mountDrawer()

        await wrapper.get('[data-testid="hub-one-tap-start"]').trigger('click')
        await flushPromises()

        expect(state.post).toHaveBeenCalledTimes(1)
        expect(state.post.mock.calls[0][0]).toBe('hub-connect/start')
        expect(state.post.mock.calls[0][1]).toEqual({})
        expect(state.post.mock.calls[0][2]).toMatchObject({ silent: true })
        expect(went).toEqual(['https://ispa.tech/portal/connect-site?domain=site.example&state=s'])
        expect(wrapper.get('[data-testid="hub-one-tap-waiting"]').text()).toBe('Đang chờ bạn cho phép trên ispa.tech…')

        // A second press while the browser is leaving starts nothing.
        await wrapper.get('[data-testid="hub-one-tap-start"]').trigger('click')
        await flushPromises()
        expect(state.post).toHaveBeenCalledTimes(1)
    })

    it('never follows an address that is not a web address', async () => {
        state.post.mockResolvedValue({ data: { data: { redirect_url: 'javascript:alert(1)' } } })
        const wrapper = await mountDrawer()

        await wrapper.get('[data-testid="hub-one-tap-start"]').trigger('click')
        await flushPromises()

        expect(went).toEqual([])
        expect(wrapper.get('[data-testid="hub-one-tap-error"]').text()).toBe('Website chưa trả lời. Hãy thử lại.')
    })

    it.each([
        [422, 'HUB_CONNECT_SITE_UNKNOWN', 'Chưa biết địa chỉ của website này. Hãy kết nối bằng mã thủ công.'],
        [500, '', 'Website chưa trả lời. Hãy thử lại.'],
    ])('explains a refused start (%s %s) in plain words and keeps the manual link', async (status, code, message) => {
        state.post.mockRejectedValue(refusal(status as number, code as string))
        const wrapper = await mountDrawer()

        await wrapper.get('[data-testid="hub-one-tap-start"]').trigger('click')
        await flushPromises()

        expect(went).toEqual([])
        expect(wrapper.get('[data-testid="hub-one-tap-error"]').text()).toBe(message)
        expect(wrapper.get('[data-testid="hub-one-tap-error"]').attributes('role')).toBe('alert')
        expect(wrapper.find('[data-testid="hub-connect-manual"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="hub-one-tap-waiting"]').exists()).toBe(false)
        expect(wrapper.get('[data-testid="hub-one-tap-start"]').attributes('disabled')).toBeUndefined()
    })

    it('asks before replacing an existing connection, then starts again with replace and sends the browser on', async () => {
        state.post.mockRejectedValueOnce(refusal(409, 'HUB_ALREADY_CONNECTED'))
        const wrapper = await mountDrawer()

        await wrapper.get('[data-testid="hub-one-tap-start"]').trigger('click')
        await flushPromises()

        expect(went).toEqual([])
        expect(wrapper.emitted('attention')).toHaveLength(1)
        expect(wrapper.get('[data-testid="hub-one-tap-replace"]').text()).toContain('Website này đang kết nối với iSPA Hub. Kết nối lại sẽ thay thông tin cũ.')
        expect(wrapper.find('[data-testid="hub-one-tap-error"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="hub-one-tap-start"]').exists()).toBe(false)

        state.post.mockResolvedValueOnce({ data: { data: { redirect_url: 'https://ispa.tech/portal/connect-site?state=r' } } })
        expect(wrapper.get('[data-testid="hub-one-tap-replace-confirm"]').text()).toBe('Kết nối lại')
        await wrapper.get('[data-testid="hub-one-tap-replace-confirm"]').trigger('click')
        await flushPromises()

        expect(state.post.mock.calls[1][0]).toBe('hub-connect/start')
        expect(state.post.mock.calls[1][1]).toEqual({ replace: true })
        expect(went).toEqual(['https://ispa.tech/portal/connect-site?state=r'])
        expect(wrapper.get('[data-testid="hub-one-tap-waiting"]').text()).toBe('Đang chờ bạn cho phép trên ispa.tech…')
    })

    it('closes without sending anything when the replace question is cancelled', async () => {
        state.post.mockRejectedValueOnce(refusal(409, 'HUB_ALREADY_CONNECTED'))
        const wrapper = await mountDrawer()
        await wrapper.get('[data-testid="hub-one-tap-start"]').trigger('click')
        await flushPromises()

        expect(wrapper.get('[data-testid="hub-one-tap-replace-cancel"]').text()).toBe('Huỷ')
        await wrapper.get('[data-testid="hub-one-tap-replace-cancel"]').trigger('click')

        expect(state.post).toHaveBeenCalledTimes(1)
        expect(wrapper.emitted('close')).toHaveLength(1)
        expect(wrapper.find('[data-testid="hub-one-tap-replace"]').exists()).toBe(false)
    })

    it.each([
        ['HUB_CONNECT_MANAGED_BY_ENV', 409, 'Kết nối iSPA Hub của website này được đặt trong cấu hình máy chủ (.env) nên không đổi được từ trang quản trị. Hãy nhờ người phụ trách kỹ thuật thay đổi.'],
        ['HUB_CONNECT_FORBIDDEN', 403, 'Tài khoản của bạn chưa có quyền kết nối website với iSPA Hub.'],
    ])('offers nothing to press after %s', async (code, status, message) => {
        state.post.mockRejectedValue(refusal(status as number, code as string))
        const wrapper = await mountDrawer()

        await wrapper.get('[data-testid="hub-one-tap-start"]').trigger('click')
        await flushPromises()

        expect(wrapper.get('[data-testid="hub-one-tap-error"]').text()).toBe(message)
        expect(wrapper.find('[data-testid="hub-one-tap-start"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="hub-connect-manual"]').exists()).toBe(false)
    })

    it('opens straight on the manual form when asked to', async () => {
        const wrapper = await mountDrawer({ manual: true })

        expect(wrapper.find('[data-testid="connect-hub-stub"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="hub-one-tap-start"]').exists()).toBe(false)
    })
})

describe('Hub connect callback screen', () => {
    it('finishes the attempt with the code and state, clears the address and says it is connected', async () => {
        let release: (value: unknown) => void = () => {}
        state.post.mockReturnValue(new Promise((resolve) => (release = resolve)))
        const wrapper = await mountCallback({ code: 'one-time-code', state: 'the-state' })

        expect(wrapper.get('[data-testid="hub-callback-working"]').text()).toContain('Đang hoàn tất kết nối…')
        expect(state.post).toHaveBeenCalledTimes(1)
        expect(state.post.mock.calls[0][0]).toBe('hub-connect/finish')
        expect(state.post.mock.calls[0][1]).toEqual({ code: 'one-time-code', state: 'the-state' })
        expect(state.post.mock.calls[0][2]).toMatchObject({ silent: true })
        // The single-use code leaves the address bar at once.
        expect(state.replace).toHaveBeenCalledWith({ query: {} })

        release({ data: { data: { status: 'connected', source: 'stored', warning_key: null, message_key: null } } })
        await flushPromises()

        expect(wrapper.get('[data-testid="hub-callback-done"]').text()).toBe('Đã kết nối với iSPA Hub.')
        expect(wrapper.find('[data-testid="hub-callback-env-warning"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="hub-callback-working"]').exists()).toBe(false)

        await wrapper.get('[data-testid="hub-callback-modules"]').trigger('click')
        expect(state.push).toHaveBeenCalledWith({ name: 'setting-modules' })
    })

    it('adds the .env notice when the server settings keep winning', async () => {
        state.post.mockResolvedValue({ data: { data: { status: 'connected', source: 'env', warning_key: 'text.site_connect.env_wins', message_key: null } } })
        const wrapper = await mountCallback({ code: 'c', state: 's' })

        expect(wrapper.get('[data-testid="hub-callback-done"]').text()).toBe('Đã kết nối với iSPA Hub.')
        expect(wrapper.get('[data-testid="hub-callback-env-warning"]').text()).toBe('Đã lưu, nhưng cấu hình máy chủ (.env) vẫn được ưu tiên.')
    })

    it('shows the pending panel while the hub still checks the domain', async () => {
        state.post.mockResolvedValue({ data: { data: { status: 'pending', source: 'stored', warning_key: null, message_key: 'text.site_connect.reason.wrong_answer' } } })
        const wrapper = await mountCallback({ code: 'c', state: 's' })

        const pending = wrapper.get('[data-testid="hub-callback-pending"]')
        expect(pending.text()).toContain('Đang chờ iSPA Hub kiểm tra tên miền')
        expect(pending.text()).toContain('Mã bí mật không khớp với website này. Kiểm tra lại mã.')
        expect(wrapper.find('[data-testid="hub-callback-done"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="hub-callback-modules"]').exists()).toBe(true)
    })

    it('passes on that the person pressed Huỷ on ispa.tech', async () => {
        state.post.mockRejectedValue(refusal(400, 'HUB_CONNECT_DENIED'))
        const wrapper = await mountCallback({ error: 'access_denied', state: 'the-state' })

        expect(state.post.mock.calls[0][1]).toEqual({ error: 'access_denied', state: 'the-state' })
        expect(wrapper.get('[data-testid="hub-callback-error"]').text()).toBe('Bạn đã bấm Huỷ trên ispa.tech nên website chưa được kết nối.')
    })

    it.each([
        [400, 'HUB_CONNECT_STATE_INVALID', 'Lần kết nối này đã hết hạn hoặc không còn dùng được. Hãy bấm Kết nối iSPA Hub để làm lại.'],
        [409, 'HUB_CONNECT_DOMAIN_TAKEN', 'Website này đang thuộc một tài khoản iSPA khác.'],
        [409, 'HUB_CONNECT_SITE_DISABLED', 'Website này đang bị tắt kết nối trong tài khoản iSPA. Hãy bật lại trên ispa.tech rồi thử lại.'],
        [502, 'HUB_CONNECT_EXCHANGE_FAILED', 'iSPA Hub chưa xác nhận được kết nối. Bước này phải xong trong 60 giây sau khi bạn bấm Cho phép. Hãy bấm Kết nối iSPA Hub để làm lại, hoặc kết nối bằng mã thủ công.'],
        [422, 'CREDENTIALS_REFUSED', 'iSPA Hub không nhận kết nối này. Hãy thử lại hoặc kết nối bằng mã thủ công.'],
        [503, 'HUB_UNREACHABLE', 'Chưa nối được tới iSPA Hub. Hãy thử lại sau ít phút hoặc kết nối bằng mã thủ công.'],
        [500, '', 'Website chưa trả lời. Hãy thử lại.'],
    ])('explains %s %s in plain words, with a way to start again and the manual link', async (status, code, message) => {
        state.post.mockRejectedValueOnce(refusal(status as number, code as string))
        const wrapper = await mountCallback({ code: 'c', state: 's' })

        const error = wrapper.get('[data-testid="hub-callback-error"]')
        expect(error.text()).toBe(message)
        expect(error.attributes('role')).toBe('alert')
        expect(wrapper.text()).not.toContain('server wording')
        expect(wrapper.find('[data-testid="hub-callback-done"]').exists()).toBe(false)

        // The manual form opens on the Modules screen.
        expect(wrapper.get('[data-testid="hub-callback-manual"]').text()).toBe('Kết nối bằng mã thủ công')
        await wrapper.get('[data-testid="hub-callback-manual"]').trigger('click')
        expect(state.push).toHaveBeenCalledWith({ name: 'setting-modules', query: { connect: 'manual' } })

        // "Kết nối iSPA Hub" starts a new attempt from here.
        state.post.mockResolvedValueOnce({ data: { data: { redirect_url: 'https://ispa.tech/portal/connect-site?state=new' } } })
        expect(wrapper.get('[data-testid="hub-callback-restart"]').text()).toBe('Kết nối iSPA Hub')
        await wrapper.get('[data-testid="hub-callback-restart"]').trigger('click')
        await flushPromises()
        expect(state.post.mock.calls[1][0]).toBe('hub-connect/start')
        expect(went).toEqual(['https://ispa.tech/portal/connect-site?state=new'])
        expect(wrapper.get('[data-testid="hub-callback-waiting"]').text()).toBe('Đang chờ bạn cho phép trên ispa.tech…')
    })

    it('says the server settings decide and offers nothing to press (HUB_CONNECT_MANAGED_BY_ENV)', async () => {
        state.post.mockRejectedValue(refusal(409, 'HUB_CONNECT_MANAGED_BY_ENV'))
        const wrapper = await mountCallback({ code: 'c', state: 's' })

        expect(wrapper.get('[data-testid="hub-callback-error"]').text()).toBe('Kết nối iSPA Hub của website này được đặt trong cấu hình máy chủ (.env) nên không đổi được từ trang quản trị. Hãy nhờ người phụ trách kỹ thuật thay đổi.')
        expect(wrapper.find('[data-testid="hub-callback-restart"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="hub-callback-manual"]').exists()).toBe(false)
    })

    it('starting again on a site that already holds a connection asks first, then starts with replace', async () => {
        state.post.mockRejectedValueOnce(refusal(400, 'HUB_CONNECT_STATE_INVALID'))
        const wrapper = await mountCallback({ code: 'c', state: 's' })

        state.post.mockRejectedValueOnce(refusal(409, 'HUB_ALREADY_CONNECTED'))
        await wrapper.get('[data-testid="hub-callback-restart"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid="hub-callback-replace"]').exists()).toBe(true)
        expect(went).toEqual([])

        state.post.mockResolvedValueOnce({ data: { data: { redirect_url: 'https://ispa.tech/portal/connect-site?state=r' } } })
        await wrapper.get('[data-testid="hub-callback-replace-confirm"]').trigger('click')
        await flushPromises()

        expect(state.post.mock.calls[2][0]).toBe('hub-connect/start')
        expect(state.post.mock.calls[2][1]).toEqual({ replace: true })
        expect(went).toEqual(['https://ispa.tech/portal/connect-site?state=r'])
        expect(wrapper.get('[data-testid="hub-callback-waiting"]').text()).toBe('Đang chờ bạn cho phép trên ispa.tech…')
    })

    it('offers nothing to press when the account may not connect the site', async () => {
        state.post.mockRejectedValue(refusal(403, 'HUB_CONNECT_FORBIDDEN'))
        const wrapper = await mountCallback({ code: 'c', state: 's' })

        expect(wrapper.get('[data-testid="hub-callback-error"]').text()).toBe('Tài khoản của bạn chưa có quyền kết nối website với iSPA Hub.')
        expect(wrapper.find('[data-testid="hub-callback-restart"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="hub-callback-manual"]').exists()).toBe(false)
    })

    it('asks before replacing an existing connection, then resends the same code and state with replace', async () => {
        state.post.mockRejectedValueOnce(refusal(409, 'HUB_ALREADY_CONNECTED'))
        const wrapper = await mountCallback({ code: 'one-time-code', state: 'the-state' })

        expect(wrapper.get('[data-testid="hub-callback-replace"]').text()).toContain('Website này đang kết nối với iSPA Hub. Kết nối lại sẽ thay thông tin cũ.')
        expect(wrapper.find('[data-testid="hub-callback-error"]').exists()).toBe(false)
        expect(state.post).toHaveBeenCalledTimes(1)

        state.post.mockResolvedValueOnce({ data: { data: { status: 'connected', source: 'stored', warning_key: null, message_key: null } } })
        await wrapper.get('[data-testid="hub-callback-replace-confirm"]').trigger('click')
        await flushPromises()

        expect(state.post).toHaveBeenCalledTimes(2)
        expect(state.post.mock.calls[1][0]).toBe('hub-connect/finish')
        expect(state.post.mock.calls[1][1]).toEqual({ code: 'one-time-code', state: 'the-state', replace: true })
        expect(wrapper.get('[data-testid="hub-callback-done"]').text()).toBe('Đã kết nối với iSPA Hub.')
        expect(wrapper.find('[data-testid="hub-callback-replace"]').exists()).toBe(false)
    })

    it('keeps the old connection and goes to Modules when the replace question is cancelled', async () => {
        state.post.mockRejectedValueOnce(refusal(409, 'HUB_ALREADY_CONNECTED'))
        const wrapper = await mountCallback({ code: 'c', state: 's' })

        await wrapper.get('[data-testid="hub-callback-replace-cancel"]').trigger('click')
        await flushPromises()

        expect(state.post).toHaveBeenCalledTimes(1)
        expect(state.push).toHaveBeenCalledWith({ name: 'setting-modules' })
    })

    it('sends nothing when the address carries no code or state, and says how to start again', async () => {
        const wrapper = await mountCallback({})

        expect(state.post).not.toHaveBeenCalled()
        expect(wrapper.get('[data-testid="hub-callback-error"]').text()).toBe('Lần kết nối này đã hết hạn hoặc không còn dùng được. Hãy bấm Kết nối iSPA Hub để làm lại.')
        expect(wrapper.find('[data-testid="hub-callback-restart"]').exists()).toBe(true)
    })
})
