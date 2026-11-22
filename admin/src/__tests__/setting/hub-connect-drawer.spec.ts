import { flushPromises, mount } from '@vue/test-utils'
import Antd from 'ant-design-vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'

// Signed-in "Kết nối iSPA Hub" from Modules: the drawer posts the
// site id and secret to the admin endpoint, without the install setup code the pre-login screen asks for.
const state = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), publicPost: vi.fn() }))

vi.mock('vue-i18n', async () => await vi.importActual('vue-i18n'))
vi.mock('@/ajax', () => ({ default: { post: state.post } }))
vi.mock('axios', () => ({ default: { get: state.get, post: state.publicPost } }))
vi.mock('@/config', () => ({
    apiUrl: 'https://site.example/',
    cmsTitle: 'iSPA CMS',
    logoAuthUrl: 'https://site.example/logo.png',
}))

/** A copy of the rows the screen shows (cms/Core translate-admin-authored.php and translates-admin-connect-hub.php). */
const wording: Record<string, string> = {
    'text.site_connect.title': 'Kết nối iSPA Hub',
    'text.site_connect.intro': 'Website này chưa được nối với iSPA Hub.',
    'text.site_connect.portal_link': 'Mở cổng khách hàng iSPA',
    'text.site_connect.site_id': 'Địa chỉ website',
    'text.site_connect.site_secret': 'Mã bí mật',
    'text.site_connect.setup_code': 'Mã cài đặt',
    'text.site_connect.required': 'Hãy điền {field}.',
    'text.site_connect.submit': 'Kết nối',
    'text.site_connect.submitting': 'Đang kết nối…',
    'text.site_connect.unavailable': 'Website chưa trả lời. Hãy thử lại.',
    'text.site_connect.credentials_refused': 'iSPA Hub không nhận địa chỉ website hoặc mã bí mật này.',
    'text.site_connect.hub_unreachable': 'Chưa nối được tới iSPA Hub. Hãy thử lại sau ít phút.',
    'text.hub_connect.site_id': 'Địa chỉ website',
    'text.hub_connect.secret': 'Mã bí mật',
    'text.hub_connect.hint': 'Lấy hai thông tin này trong portal ispa.tech: Websites & domains → Connect a website.',
    'text.hub_connect.connected': 'Đã kết nối với iSPA Hub.',
    'text.site_connect.env_wins': 'Đã lưu, nhưng cấu hình máy chủ (.env) vẫn được ưu tiên.',
    'text.site_connect.pending_title': 'Đang chờ iSPA Hub kiểm tra tên miền',
    'text.site_connect.pending_text': 'iSPA Hub đã nhận mã và đang kiểm tra website này.',
    'text.site_connect.recheck': 'Kiểm tra lại',
    'text.site_connect.submit_again': 'Thử kết nối lại',
    'text.site_connect.reason.wrong_answer': 'Mã bí mật không khớp với website này. Kiểm tra lại mã.',
    'text.hub_connect.replace_text': 'Website này đang kết nối với iSPA Hub. Kết nối lại sẽ thay thông tin cũ.',
    'text.hub_connect.replace_confirm': 'Kết nối lại',
    'text.hub_connect.cancel': 'Huỷ',
    'text.hub_connect.forbidden': 'Tài khoản của bạn chưa có quyền kết nối website với iSPA Hub.',
    'text.hub_connect.invalid': 'Hãy kiểm tra lại địa chỉ website và mã bí mật.',
    'text.hub_connect.one_tap_intro': 'Bấm nút bên dưới, đăng nhập ispa.tech rồi bấm Cho phép. Bạn không phải chép mã nào.',
    'text.hub_connect.start': 'Kết nối iSPA Hub',
    'text.hub_connect.manual_link': 'Kết nối bằng mã thủ công',
    'text.hub_connect.one_tap_link': 'Kết nối bằng tài khoản ispa.tech',
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

const Drawer = defineComponent({ props: ['open'], setup: (p, { slots }) => () => (p.open ? h('div', slots.default?.()) : null) })

const mountDrawer = async () => {
    const i18n = createI18n({ legacy: false, locale: 'vi', messages: { vi: nest(wording) }, missingWarn: false, fallbackWarn: false })
    const HubConnectDrawer = (await import('../../../../cms/Core/admin/pages/setting/modules/hub-connect-drawer.vue')).default
    const wrapper = mount(HubConnectDrawer, { props: { open: true }, global: { plugins: [Antd, i18n], stubs: { 'a-drawer': Drawer } } })
    await flushPromises()
    // The drawer opens on the one-tap button (hub-one-tap.spec.ts); the form sits behind "Kết nối bằng mã thủ công".
    await wrapper.get('[data-testid="hub-connect-manual"]').trigger('click')
    await flushPromises()

    return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountDrawer>>

const settle = async () => {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 20))
    await flushPromises()
}

const fillAndSubmit = async (wrapper: Wrapper, id = 'shop.example.org', secret = 'ispa_site_secret') => {
    await wrapper.get('[data-testid="connect-hub-site-id"]').setValue(id)
    await wrapper.get('[data-testid="connect-hub-site-secret"]').setValue(secret)
    await wrapper.get('form').trigger('submit')
    await settle()
}

const rejection = (status: number, body: Record<string, unknown>) => ({ response: { status, data: body } })

describe('HubConnectDrawer (signed-in connect from Modules)', () => {
    beforeEach(() => {
        state.post.mockReset()
        state.publicPost.mockReset()
        state.get.mockReset()
        state.get.mockResolvedValue({ data: { data: { status: 'not_connected', message_key: 'text.site_status.not_configured', expires_at: null } } })
    })

    it('asks for the site id and the secret only, with the one-line portal hint', async () => {
        const wrapper = await mountDrawer()
        const text = wrapper.text()

        // Same labels as the ispa.tech portal and the pre-login screen.
        expect(text).toContain('Địa chỉ website')
        expect(text).toContain('Mã bí mật')
        expect(text).not.toContain('Khoá bí mật')
        expect(wrapper.get('[data-testid="connect-hub-site-secret"]').attributes('type')).toBe('password')
        expect(wrapper.get('[data-testid="connect-hub-hint"]').text()).toContain('Lấy hai thông tin này trong portal ispa.tech: Websites & domains → Connect a website.')
        expect(wrapper.find('[data-testid="connect-hub-setup-code"]').exists()).toBe(false)
        expect(text).not.toContain('Mã cài đặt')
        expect(text).not.toMatch(/text\.[a-z_]+\./)
    })

    it('posts the site id and secret to the admin endpoint without a setup code, then shows the success message', async () => {
        state.post.mockResolvedValue({ data: { data: { status: 'connected', source: 'stored', warning_key: null } } })
        const wrapper = await mountDrawer()

        await fillAndSubmit(wrapper)

        expect(state.post).toHaveBeenCalledTimes(1)
        expect(state.post.mock.calls[0][0]).toBe('site-connect')
        expect(state.post.mock.calls[0][1]).toEqual({ site_id: 'shop.example.org', secret: 'ispa_site_secret' })
        expect(state.post.mock.calls[0][2]).toMatchObject({ silent: true })
        expect(state.publicPost).not.toHaveBeenCalled()
        expect(wrapper.get('[data-testid="connect-hub-success"]').text()).toBe('Đã kết nối với iSPA Hub.')
        expect(wrapper.find('[data-testid="connect-hub-env-warning"]').exists()).toBe(false)
        expect(wrapper.find('form').exists()).toBe(false)
        // The site status is read again (once on open, once after connecting) and the page is told to reload.
        expect(state.get).toHaveBeenCalledTimes(2)
        expect(wrapper.emitted('connected')).toHaveLength(1)
    })

    it('warns that the .env details keep winning when the backend says the source is env', async () => {
        state.post.mockResolvedValue({ data: { data: { status: 'connected', source: 'env', warning_key: 'text.site_connect.env_wins' } } })
        const wrapper = await mountDrawer()

        await fillAndSubmit(wrapper)

        expect(wrapper.get('[data-testid="connect-hub-success"]').text()).toBe('Đã kết nối với iSPA Hub.')
        expect(wrapper.get('[data-testid="connect-hub-env-warning"]').text()).toContain('.env')
    })

    it('asks before replacing an existing connection and resends with replace=true', async () => {
        state.post.mockRejectedValueOnce(rejection(409, { error_code: 'HUB_ALREADY_CONNECTED' }))
        state.post.mockResolvedValueOnce({ data: { data: { status: 'connected', source: 'stored', warning_key: null } } })
        const wrapper = await mountDrawer()

        await fillAndSubmit(wrapper)

        const confirm = wrapper.get('[data-testid="connect-hub-replace"]')
        expect(confirm.text()).toContain('Website này đang kết nối với iSPA Hub. Kết nối lại sẽ thay thông tin cũ.')
        expect(wrapper.get('[data-testid="connect-hub-replace-confirm"]').text()).toBe('Kết nối lại')
        expect(wrapper.get('[data-testid="connect-hub-replace-cancel"]').text()).toBe('Huỷ')
        expect(wrapper.find('[data-testid="connect-hub-error"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="connect-hub-submit"]').exists()).toBe(false)

        await wrapper.get('[data-testid="connect-hub-replace-confirm"]').trigger('click')
        await settle()

        expect(state.post).toHaveBeenCalledTimes(2)
        expect(state.post.mock.calls[1][1]).toEqual({ site_id: 'shop.example.org', secret: 'ispa_site_secret', replace: true })
        expect(wrapper.get('[data-testid="connect-hub-success"]').text()).toBe('Đã kết nối với iSPA Hub.')
    })

    it('goes back to the form, sending nothing, when the replace question is cancelled', async () => {
        state.post.mockRejectedValueOnce(rejection(409, { error_code: 'HUB_ALREADY_CONNECTED' }))
        const wrapper = await mountDrawer()

        await fillAndSubmit(wrapper)
        await wrapper.get('[data-testid="connect-hub-replace-cancel"]').trigger('click')

        expect(state.post).toHaveBeenCalledTimes(1)
        expect(wrapper.find('[data-testid="connect-hub-replace"]').exists()).toBe(false)
        expect(wrapper.get('[data-testid="connect-hub-submit"]').attributes('disabled')).toBeUndefined()
        expect(wrapper.emitted('connected')).toBeUndefined()
    })

    it('says in plain words that the account may not connect the site (403)', async () => {
        state.post.mockRejectedValue(rejection(403, { error_code: 'HUB_CONNECT_FORBIDDEN', message: 'This action is unauthorized.' }))
        const wrapper = await mountDrawer()

        await fillAndSubmit(wrapper)

        expect(wrapper.get('[data-testid="connect-hub-error"]').text()).toBe('Tài khoản của bạn chưa có quyền kết nối website với iSPA Hub.')
        expect(wrapper.emitted('connected')).toBeUndefined()
    })

    it('shows what the backend says about each field (422)', async () => {
        state.post.mockRejectedValue(rejection(422, { error_code: 'HUB_CONNECT_INVALID', errors: { site_id: ['Địa chỉ website không đúng dạng.'], secret: ['Mã bí mật quá ngắn.'] } }))
        const wrapper = await mountDrawer()

        await fillAndSubmit(wrapper)

        expect(wrapper.text()).toContain('Địa chỉ website không đúng dạng.')
        expect(wrapper.text()).toContain('Mã bí mật quá ngắn.')
        expect(wrapper.get('[data-testid="connect-hub-error"]').text()).toBe('Hãy kiểm tra lại địa chỉ website và mã bí mật.')
    })

    // The backend sends the same message keys as the pre-login endpoint for what the hub answered.
    it.each([
        ['CREDENTIALS_REFUSED', 422, 'text.site_connect.credentials_refused', 'iSPA Hub không nhận địa chỉ website hoặc mã bí mật này.'],
        ['HUB_UNREACHABLE', 503, 'text.site_connect.hub_unreachable', 'Chưa nối được tới iSPA Hub. Hãy thử lại sau ít phút.'],
        ['HUB_UNREACHABLE', 503, '', 'Chưa nối được tới iSPA Hub. Hãy thử lại sau ít phút.'],
        ['CREDENTIALS_REFUSED', 422, '', 'iSPA Hub không nhận địa chỉ website hoặc mã bí mật này.'],
        ['HUB_CONNECT_FORBIDDEN', 403, 'mgs.no.permission', 'Tài khoản của bạn chưa có quyền kết nối website với iSPA Hub.'],
        ['', 401, '', 'Website chưa trả lời. Hãy thử lại.'],
        ['SOMETHING_NEW', 500, '', 'Website chưa trả lời. Hãy thử lại.'],
    ])('shows a plain message when the answer is %s (%i, key "%s")', async (code, status, key, message) => {
        state.post.mockRejectedValue(rejection(status as number, { error_code: code, message: 'raw server text', data: key ? { message_key: key } : null }))
        const wrapper = await mountDrawer()

        await fillAndSubmit(wrapper)

        expect(wrapper.get('[data-testid="connect-hub-error"]').text()).toBe(message)
    })

    it('never words a bad form with the setup code the pre-login screen asks for', async () => {
        state.post.mockRejectedValue(rejection(422, { error_code: 'HUB_CONNECT_INVALID', data: { message_key: 'text.site_connect.invalid_input' } }))
        const wrapper = await mountDrawer()

        await fillAndSubmit(wrapper)

        expect(wrapper.get('[data-testid="connect-hub-error"]').text()).toBe('Hãy kiểm tra lại địa chỉ website và mã bí mật.')
    })

    it('shows why the hub is still checking the domain when the answer is pending', async () => {
        state.post.mockResolvedValue({ data: { data: { status: 'pending', source: 'stored', warning_key: null, message_key: 'text.site_connect.reason.wrong_answer' } } })
        const wrapper = await mountDrawer()

        await fillAndSubmit(wrapper)

        expect(wrapper.get('[data-testid="connect-hub-pending"]').text()).toContain('Mã bí mật không khớp với website này')
        expect(wrapper.find('[data-testid="connect-hub-success"]').exists()).toBe(false)
        expect(wrapper.emitted('connected')).toBeUndefined()
    })

    it('does not send an incomplete form', async () => {
        const wrapper = await mountDrawer()

        await fillAndSubmit(wrapper, 'shop.example.org', '')

        expect(state.post).not.toHaveBeenCalled()
    })
})
