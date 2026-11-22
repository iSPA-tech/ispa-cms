import { flushPromises, mount } from '@vue/test-utils'
import Antd from 'ant-design-vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import fallbackMessages from 'virtual:admin-i18n-fallback'
import { humanizeI18nKey } from '@/config/i18n-message-utils'

const state = vi.hoisted(() => ({ post: vi.fn() }))

vi.mock('vue-i18n', async () => await vi.importActual('vue-i18n'))
vi.mock('axios', () => ({ default: { post: state.post, get: vi.fn() } }))
vi.mock('@/config', () => ({
    apiUrl: 'https://site.example/',
    cmsTitle: 'iSPA CMS',
    logoAuthUrl: 'https://site.example/logo.png',
}))

/** The strings the screen asserts on, a copy of the rows in cms/Core translates-admin-connect-hub.php. */
const wording: Record<string, Record<string, string>> = {
    "vi": {
        "text.site_status.hub_unreachable": "Chưa nối được tới iSPA Hub. Hãy thử lại sau ít phút.",
        "text.site_connect.title": "Kết nối iSPA Hub",
        "text.site_connect.intro": "Website này chưa được nối với iSPA Hub. Bạn chỉ cần nối một lần: sau đó dùng được kho module, module trả phí và các dịch vụ iSPA.",
        "text.site_connect.portal_hint": "Chưa có địa chỉ website và khoá bí mật? Đăng ký website trên cổng khách hàng iSPA, cổng sẽ hiện chúng cho bạn.",
        "text.site_connect.portal_link": "Mở cổng khách hàng iSPA",
        "text.site_connect.site_id": "Địa chỉ website",
        "text.site_connect.site_id_help": "Tên miền đã đăng ký trên cổng, ví dụ example.com",
        "text.site_connect.site_secret": "Khoá bí mật",
        "text.site_connect.site_secret_help": "Cổng chỉ hiện khoá này một lần, khi đăng ký website.",
        "text.site_connect.setup_code": "Mã cài đặt",
        "text.site_connect.setup_code_help": "Mã gồm 8 nhóm ký tự, được in ra khi cài đặt website. Mất mã thì nhờ người cài đặt tạo mã mới.",
        "text.site_connect.required": "Hãy điền {field}.",
        "text.site_connect.submit": "Kết nối",
        "text.site_connect.submit_again": "Thử kết nối lại",
        "text.site_connect.submitting": "Đang kết nối…",
        "text.site_connect.pending_title": "Đang chờ iSPA Hub kiểm tra tên miền",
        "text.site_connect.pending_text": "iSPA Hub đã nhận khoá và đang kiểm tra website này có đúng là của bạn không. Việc này thường mất vài phút.",
        "text.site_connect.recheck": "Kiểm tra lại",
        "text.site_connect.unavailable_title": "Chưa kiểm tra được kết nối iSPA Hub",
        "text.site_connect.unavailable_text": "Website chưa trả lời. Hãy thử lại sau ít phút.",
        "text.site_connect.retry": "Thử lại",
        "text.site_connect.setup_code_invalid": "Mã cài đặt không đúng hoặc đã hết hạn. Kiểm tra lại mã, hoặc nhờ người cài đặt tạo mã mới.",
        "text.site_connect.unavailable": "Website chưa trả lời. Hãy thử lại.",
        "text.site_connect.reason.wrong_answer": "Khoá bí mật không khớp với website này. Kiểm tra lại khoá."
    },
    "en": {
        "text.site_status.hub_unreachable": "Could not reach iSPA Hub. Please try again in a few minutes.",
        "text.site_connect.title": "Connect to iSPA Hub",
        "text.site_connect.intro": "This website is not connected to iSPA Hub yet. You only need to do this once: after that you can use the module store, paid modules and the iSPA services.",
        "text.site_connect.portal_hint": "No website address and secret key yet? Register the website in the iSPA customer portal, which shows them to you.",
        "text.site_connect.portal_link": "Open the iSPA customer portal",
        "text.site_connect.site_id": "Website address",
        "text.site_connect.site_id_help": "The domain registered in the portal, for example example.com",
        "text.site_connect.site_secret": "Secret key",
        "text.site_connect.site_secret_help": "The portal shows this key only once, when you register the website.",
        "text.site_connect.setup_code": "Setup code",
        "text.site_connect.setup_code_help": "A code of 8 groups of characters, printed when the website was installed. If it is lost, ask whoever installed the website for a new one.",
        "text.site_connect.required": "Please fill in {field}.",
        "text.site_connect.submit": "Connect",
        "text.site_connect.submit_again": "Try to connect again",
        "text.site_connect.submitting": "Connecting…",
        "text.site_connect.pending_title": "Waiting for iSPA Hub to check the domain",
        "text.site_connect.pending_text": "iSPA Hub has received the key and is checking that this website really is yours. This usually takes a few minutes.",
        "text.site_connect.recheck": "Check again",
        "text.site_connect.unavailable_title": "Could not check the iSPA Hub connection",
        "text.site_connect.unavailable_text": "The website did not answer. Please try again in a few minutes.",
        "text.site_connect.retry": "Try again",
        "text.site_connect.setup_code_invalid": "The setup code is wrong or has expired. Check it, or ask whoever installed the website for a new one.",
        "text.site_connect.unavailable": "The website did not answer. Please try again.",
        "text.site_connect.reason.wrong_answer": "The secret key does not match this website. Check the key."
    }
}

/** Turn `{ 'text.site_connect.title': 'x' }` into the nested tree vue-i18n reads. */
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

const mountScreen = async (props: Record<string, unknown> = {}, locale = 'vi') => {
    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { vi: nest(wording.vi), en: nest(wording.en) } })
    const Screen = (await import('@/pages/auth/connect-hub.vue')).default

    return mount(Screen, { props, global: { plugins: [Antd, i18n] } })
}

const fill = async (wrapper: Awaited<ReturnType<typeof mountScreen>>, values: { id?: string; secret?: string; code?: string }) => {
    if (values.id !== undefined) await wrapper.get('[data-testid="connect-hub-site-id"]').setValue(values.id)
    if (values.secret !== undefined) await wrapper.get('[data-testid="connect-hub-site-secret"]').setValue(values.secret)
    if (values.code !== undefined) await wrapper.get('[data-testid="connect-hub-setup-code"]').setValue(values.code)
}

const submit = async (wrapper: Awaited<ReturnType<typeof mountScreen>>) => {
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 20))
    await flushPromises()
}

describe('ConnectHub (pre-login connect screen)', () => {
    beforeEach(() => {
        state.post.mockReset()
    })

    // the admin translation pack could not be loaded, only the wording bundled at build time exists. The app's
    // missing-key handler humanizes keys ("Site Id Help"), which is what the operator saw before the fallback.
    describe.each([
        ['vi', 'Kết nối iSPA Hub', 'Địa chỉ website', 'Chưa nối được tới iSPA Hub'],
        ['en', 'Connect to iSPA Hub', 'Website address', 'Could not reach iSPA Hub'],
    ])('without the translation pack (%s)', (locale, title, siteId, unreachable) => {
        const mountWithoutPack = async (props: Record<string, unknown>) => {
            const i18n = createI18n({
                legacy: false,
                locale,
                fallbackLocale: 'en',
                messages: fallbackMessages as any,
                missing: (_locale: string, key: string) => humanizeI18nKey(key),
                missingWarn: false,
                fallbackWarn: false,
            })
            const Screen = (await import('@/pages/auth/connect-hub.vue')).default

            return mount(Screen, { props, global: { plugins: [Antd, i18n] } })
        }

        it('shows real wording, not raw or humanized keys', async () => {
            const wrapper = await mountWithoutPack({ status: { status: 'not_connected', message_key: 'text.site_status.not_connected', expires_at: null } })
            const text = wrapper.text()

            expect(text).toContain(title)
            expect(text).toContain(siteId)
            const silent = await mountWithoutPack({ status: { status: 'not_connected', message_key: 'text.site_status.hub_unreachable', expires_at: null } })
            expect(silent.text()).toContain(unreachable)
            expect(silent.find('form').exists()).toBe(false)
            expect(text).not.toMatch(/text\.[a-z_]+\./)
            for (const humanized of ['Site Id Help', 'Setup Code Help', 'Portal Hint', 'Intro']) {
                expect(text).not.toContain(humanized)
            }
        })

        it('shows real wording in the pending and unavailable states', async () => {
            const pending = await mountWithoutPack({ status: { status: 'pending', message_key: 'text.site_status.pending', expires_at: null } })
            expect(pending.get('[data-testid="connect-hub-pending"]').text()).not.toContain('Pending Title')
            expect(pending.get('[data-testid="connect-hub-pending"]').text()).toMatch(/iSPA Hub/)

            const unavailable = await mountWithoutPack({ unavailable: true })
            expect(unavailable.get('[data-testid="connect-hub-unavailable"]').text()).toMatch(/iSPA Hub/)
            expect(unavailable.get('[data-testid="connect-hub-retry"]').text()).not.toBe('Retry')
        })
    })

    it('shows the plain explanation, three fields and a link to the portal, in Vietnamese', async () => {
        const wrapper = await mountScreen({ status: { status: 'not_connected', message_key: 'text.site_status.not_configured', expires_at: null } })
        const text = wrapper.text()

        expect(text).toContain('Kết nối iSPA Hub')
        expect(text).toContain('Website này chưa được nối với iSPA Hub')
        expect(text).toContain('Địa chỉ website')
        expect(text).toContain('Khoá bí mật')
        expect(text).toContain('Mã cài đặt')
        expect(wrapper.get('[data-testid="connect-hub-portal-link"]').attributes('href')).toMatch(/\/portal$/)
        expect(text).not.toMatch(/text\.[a-z_]+\./)
        expect(wrapper.find('[data-testid="connect-hub-pending"]').exists()).toBe(false)
    })

    it('has English wording when the locale is English', async () => {
        const wrapper = await mountScreen({}, 'en')

        expect(wrapper.text()).toContain('Connect to iSPA Hub')
        expect(wrapper.text()).toContain('Setup code')
        expect(wrapper.text()).not.toContain('Kết nối')
    })

    it('shows the waiting-for-domain-check state while the status is pending', async () => {
        const wrapper = await mountScreen({ status: { status: 'pending', message_key: 'text.site_status.pending', expires_at: null } })

        expect(wrapper.get('[data-testid="connect-hub-pending"]').text()).toContain('Đang chờ iSPA Hub kiểm tra tên miền')
        expect(wrapper.get('[data-testid="connect-hub-submit"]').text()).toBe('Thử kết nối lại')
        await wrapper.get('[data-testid="connect-hub-recheck"]').trigger('click')
        expect(wrapper.emitted('refresh')).toHaveLength(1)
    })

    it('shows a notice with the form only when the hub refused the site', async () => {
        const notConfigured = await mountScreen({ status: { status: 'not_connected', message_key: 'text.site_status.not_configured', expires_at: null } })
        expect(notConfigured.find('[data-testid="connect-hub-notice"]').exists()).toBe(false)
        expect(notConfigured.find('form').exists()).toBe(true)

        const refused = await mountScreen({ status: { status: 'not_connected', message_key: 'text.site_status.not_connected', expires_at: null } })
        expect(refused.find('form').exists()).toBe(true)
    })

    // the hub cannot be reached after the grace period. The key form cannot help (the site may already hold keys),
    // so the screen shows only the notice and a retry.
    it.each([
        ['vi', 'Chưa nối được tới iSPA Hub. Hãy thử lại sau ít phút.', 'Thử lại'],
        ['en', 'Could not reach iSPA Hub. Please try again in a few minutes.', 'Try again'],
    ])('shows only the notice and a retry when the hub cannot be reached (%s)', async (locale, notice, retry) => {
        const wrapper = await mountScreen({ status: { status: 'not_connected', message_key: 'text.site_status.hub_unreachable', expires_at: null } }, locale)

        expect(wrapper.get('[data-testid="connect-hub-unavailable"]').text()).toContain(notice)
        expect(wrapper.get('[data-testid="connect-hub-retry"]').text()).toBe(retry)
        expect(wrapper.find('form').exists()).toBe(false)
        expect(wrapper.find('[data-testid="connect-hub-site-secret"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="connect-hub-portal-link"]').exists()).toBe(false)
        await wrapper.get('[data-testid="connect-hub-retry"]').trigger('click')
        expect(wrapper.emitted('refresh')).toHaveLength(1)
    })

    it('shows a retry instead of the form when the status could not be read', async () => {
        const wrapper = await mountScreen({ unavailable: true })

        expect(wrapper.find('[data-testid="connect-hub-unavailable"]').exists()).toBe(true)
        expect(wrapper.find('form').exists()).toBe(false)
        await wrapper.get('[data-testid="connect-hub-retry"]').trigger('click')
        expect(wrapper.emitted('refresh')).toHaveLength(1)
    })

    it('sends the three fields to the site and hands over when connected', async () => {
        state.post.mockResolvedValue({ data: { data: { status: 'connected', message_key: 'text.site_connect.connected' } } })
        const wrapper = await mountScreen({ status: { status: 'not_connected', message_key: 'text.site_status.not_configured', expires_at: null } })

        await fill(wrapper, { id: 'shop.example.org', secret: 'ispa_site_secret', code: 'AAAA-BBBB' })
        await submit(wrapper)

        expect(state.post).toHaveBeenCalledTimes(1)
        expect(state.post.mock.calls[0][0]).toMatch(/api\/v2\/public\/site-connect$/)
        expect(state.post.mock.calls[0][1]).toEqual({ site_id: 'shop.example.org', site_secret: 'ispa_site_secret', setup_code: 'AAAA-BBBB' })
        expect(wrapper.emitted('connected')).toHaveLength(1)
    })

    it('says in plain words that the setup code is wrong and stays on the form', async () => {
        state.post.mockRejectedValue({ response: { status: 422, data: { data: { status: 'not_connected', message_key: 'text.site_connect.setup_code_invalid' } } } })
        const wrapper = await mountScreen()

        await fill(wrapper, { id: 'shop.example.org', secret: 'ispa_site_secret', code: 'WRONG' })
        await submit(wrapper)

        expect(wrapper.get('[data-testid="connect-hub-error"]').text()).toContain('Mã cài đặt không đúng hoặc đã hết hạn')
        expect(wrapper.emitted('connected')).toBeUndefined()
        expect(wrapper.get('[data-testid="connect-hub-submit"]').attributes('disabled')).toBeUndefined()
    })

    it('shows the reason a domain check failed and offers a new try', async () => {
        state.post.mockResolvedValue({ data: { data: { status: 'pending', message_key: 'text.site_connect.reason.wrong_answer' } } })
        const wrapper = await mountScreen()

        await fill(wrapper, { id: 'shop.example.org', secret: 'ispa_site_secret', code: 'AAAA' })
        await submit(wrapper)

        expect(wrapper.get('[data-testid="connect-hub-pending"]').text()).toContain('Khoá bí mật không khớp')
        expect(wrapper.emitted('connected')).toBeUndefined()
    })

    it('does not send an incomplete form', async () => {
        const wrapper = await mountScreen()

        await fill(wrapper, { id: 'shop.example.org', secret: '', code: '' })
        await submit(wrapper)

        expect(state.post).not.toHaveBeenCalled()
    })

    it('ignores a second submit while one is running', async () => {
        let release: (value: unknown) => void = () => undefined
        state.post.mockImplementation(() => new Promise((resolve) => { release = resolve }))
        const wrapper = await mountScreen()

        await fill(wrapper, { id: 'shop.example.org', secret: 'ispa_site_secret', code: 'AAAA' })
        await submit(wrapper)
        await submit(wrapper)

        expect(state.post).toHaveBeenCalledTimes(1)
        expect(wrapper.get('[data-testid="connect-hub-submit"]').attributes('disabled')).toBeDefined()
        release({ data: { data: { status: 'pending', message_key: '' } } })
    })
})
