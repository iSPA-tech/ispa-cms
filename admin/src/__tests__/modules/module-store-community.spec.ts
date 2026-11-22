import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import StoreCommunity from '../../../../cms/Core/admin/pages/setting/modules/store-community.vue'
import StoreTab from '../../../../cms/Core/admin/pages/setting/modules/store-tab.vue'

/*
 * ratings, questions and private feedback in the module store drawer, and average stars on the cards.
 */

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))
const messageMock = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('ant-design-vue', () => ({ message: messageMock }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (k: string, p?: Record<string, unknown>, n?: number) => (p ? `${k}|${JSON.stringify(p)}${n === undefined ? '' : '#' + n}` : k),
        locale: { value: 'vi' },
    }),
}))

const $t = (k: string, p?: Record<string, unknown>, n?: number) => (p ? `${k}|${JSON.stringify(p)}${n === undefined ? '' : '#' + n}` : k)
const passthrough = (tag = 'div') => defineComponent({ setup: (_p, { slots, attrs }) => () => h(tag, attrs, slots.default?.()) })
const field = (tag: string) =>
    defineComponent({
        props: ['value', 'maxlength', 'rows', 'placeholder', 'options'],
        emits: ['update:value'],
        setup: (p, { emit, attrs }) => () =>
            h(tag, { value: p.value ?? '', 'data-testid': attrs['data-testid'], onInput: (e: Event) => emit('update:value', (e.target as HTMLInputElement).value) }),
    })
const stubs = {
    'a-tag': passthrough('span'),
    'a-skeleton': passthrough(),
    'a-alert': defineComponent({ props: ['message', 'description'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'], role: 'alert' }, [p.message, ' ', p.description]) }),
    'a-rate': defineComponent({
        props: ['value', 'disabled', 'allowHalf'],
        emits: ['update:value'],
        setup: (p, { emit, attrs }) => () =>
            h('input', { type: 'number', value: p.value, disabled: p.disabled, 'data-testid': attrs['data-testid'], onInput: (e: Event) => emit('update:value', Number((e.target as HTMLInputElement).value)) }),
    }),
    'a-textarea': field('textarea'),
    'a-input': field('input'),
    'a-select': field('input'),
    'i-icon': true,
    'i-button': defineComponent({
        props: ['disabled', 'loading', 'nativeType'],
        emits: ['click'],
        setup: (p, { slots, emit, attrs }) => () =>
            h('button', { type: p.nativeType || 'button', disabled: p.disabled || p.loading, 'data-testid': attrs['data-testid'], onClick: () => emit('click') }, slots.default?.()),
    }),
}
const mountOpts = { global: { mocks: { $t }, stubs } }

const state = vi.hoisted(() => ({
    mine: null as unknown,
    ratings: null as unknown,
    questions: null as unknown,
}))

const mineAnswer = (over: Record<string, unknown> = {}) => ({ available: true, installed: true, rating: null, can_rate: true, reason: null, default_name: 'Vương', ...over })
const ratingsAnswer = (over: Record<string, unknown> = {}) => ({
    available: true,
    average: 4.5,
    count: 2,
    items: [{ stars: 5, body: '<img src=x onerror=alert(1)>Tốt', version: '1.2.0', author_name: 'An', updated_at: '2026-11-14T00:00:00Z' }],
    page: 1,
    per_page: 10,
    total: 1,
    ...over,
})
const questionsAnswer = (over: Record<string, unknown> = {}) => ({
    available: true,
    items: [{ body: 'Có chạy offline không?', author_kind: 'site', author_name: 'Bình', created_at: '2026-11-14T00:00:00Z', answers: [{ body: 'Có.', author_kind: 'ispa', author_name: 'iSPA', created_at: '2026-11-14T01:00:00Z' }] }],
    page: 1,
    per_page: 10,
    total: 1,
    ...over,
})

const entry = (over: Record<string, unknown> = {}) => ({ name: 'Helpdesk', label: 'Helpdesk', installed: 'release', installed_version: '1.2.0', ...over })
const mountCommunity = async (over: Record<string, unknown> = {}) => {
    const wrapper = mount(StoreCommunity, { ...mountOpts, props: { entry: entry(over), locale: 'vi' } })
    await flushPromises()
    return wrapper
}

beforeEach(() => {
    for (const fn of Object.values(ajaxMock)) fn.mockReset()
    messageMock.success.mockReset()
    state.mine = mineAnswer()
    state.ratings = ratingsAnswer()
    state.questions = questionsAnswer()
    ajaxMock.get.mockImplementation((url: string) => {
        if (url.endsWith('/ratings/mine')) return Promise.resolve({ data: { data: state.mine } })
        if (url.includes('/ratings')) return Promise.resolve({ data: { data: state.ratings } })
        if (url.includes('/questions')) return Promise.resolve({ data: { data: state.questions } })
        return Promise.reject(new Error('unexpected ' + url))
    })
})

describe('StoreCommunity', () => {
    it('reads the average, the ratings and the questions with their answers, as plain text', async () => {
        const wrapper = await mountCommunity()
        expect(ajaxMock.get).toHaveBeenCalledWith('utilities/modules/catalog/Helpdesk/ratings/mine', expect.anything())
        expect(wrapper.find('[data-testid=community-average]').text()).toContain('text.modules.store.community.summary')
        const rating = wrapper.find('[data-testid=community-rating]')
        expect(rating.text()).toContain('<img src=x onerror=alert(1)>Tốt')
        expect(rating.find('img').exists()).toBe(false)
        const question = wrapper.find('[data-testid=community-question]')
        expect(question.text()).toContain('Có chạy offline không?')
        expect(question.find('[data-testid=community-answer]').text()).toContain('text.modules.store.community.badge.ispa')
    })

    it('hides the rate button when the module is not installed on this website', async () => {
        state.mine = mineAnswer({ installed: false, can_rate: false, reason: 'MODULE_COMMUNITY_NOT_INSTALLED' })
        const wrapper = await mountCommunity({ installed: 'none', installed_version: null })
        expect(wrapper.find('[data-testid=community-rate-open]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=community-rate-reason]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=community-not-installed]').text()).toContain('text.modules.store.community.not_installed')
        // Asking and private feedback stay open to every Webmaster who sees the module.
        expect(wrapper.find('[data-testid=community-ask-open]').exists()).toBe(true)
        expect(wrapper.find('[data-testid=community-feedback-open]').exists()).toBe(true)
    })

    it('shows the hub refusal reason when the latest status report does not show the module', async () => {
        state.mine = mineAnswer({ can_rate: false, reason: 'MODULE_COMMUNITY_INSTALL_REQUIRED' })
        const wrapper = await mountCommunity()
        expect(wrapper.find('[data-testid=community-rate-open]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=community-rate-reason]').text()).toContain('text.modules.store.community.reason.install_required')
    })

    it('rates with stars, text and the default display name, then shows the saved rating', async () => {
        ajaxMock.put.mockResolvedValue({ data: { data: { rating: { stars: 4, body: 'Ổn', version: '1.2.0', author_name: 'Vương', updated_at: '2026-11-15T00:00:00Z', hidden: false } } } })
        const wrapper = await mountCommunity()
        await wrapper.find('[data-testid=community-rate-open]').trigger('click')
        expect((wrapper.find('[data-testid=community-name]').element as HTMLInputElement).value).toBe('Vương')
        await wrapper.find('[data-testid=community-rate-stars]').setValue('4')
        await wrapper.find('[data-testid=community-rate-text]').setValue('Ổn')
        await wrapper.find('[data-testid=community-rate-save]').trigger('click')
        await flushPromises()
        expect(ajaxMock.put).toHaveBeenCalledWith('utilities/modules/catalog/Helpdesk/rating', { stars: 4, body: 'Ổn', author_name: 'Vương' }, expect.anything())
        expect(messageMock.success).toHaveBeenCalled()
        expect(wrapper.find('[data-testid=community-mine]').text()).toContain('Ổn')
        expect(wrapper.find('[data-testid=community-rate-open]').text()).toContain('text.modules.store.community.edit_rating')
        // The list and the average are read again, so the new rating shows at once.
        expect(ajaxMock.get.mock.calls.filter(([url]) => String(url).endsWith('/Helpdesk/ratings'))).toHaveLength(2)
    })

    it('tells the store card the new average and count right after rating', async () => {
        ajaxMock.put.mockResolvedValue({ data: { data: { rating: { stars: 4, body: '', version: '1.2.0', author_name: 'Vương', updated_at: '2026-11-15T00:00:00Z', hidden: false } } } })
        const wrapper = await mountCommunity()
        state.ratings = ratingsAnswer({ average: 4.2, count: 3 })
        await wrapper.find('[data-testid=community-rate-open]').trigger('click')
        await wrapper.find('[data-testid=community-rate-stars]').setValue('4')
        await wrapper.find('[data-testid=community-rate-save]').trigger('click')
        await flushPromises()
        expect(wrapper.emitted('rated')?.[0]).toEqual([{ name: 'Helpdesk', average: 4.2, count: 3 }])
    })

    it('says in plain words why a display name was refused and keeps what was typed', async () => {
        const wrapper = await mountCommunity()
        await wrapper.find('[data-testid=community-ask-open]').trigger('click')
        await wrapper.find('[data-testid=community-ask-text]').setValue('Hi')
        for (const [code, key] of [['MODULE_COMMUNITY_NAME_ISPA', 'name_ispa'], ['MODULE_COMMUNITY_NAME_CONTACT', 'name_contact']]) {
            ajaxMock.post.mockRejectedValue({ response: { status: 422, data: { error_code: code } } })
            await wrapper.find('[data-testid=community-ask-send]').trigger('click')
            await flushPromises()
            expect(wrapper.find('[data-testid=community-form-error]').text()).toContain('text.modules.store.community.error.' + key)
            expect((wrapper.find('[data-testid=community-ask-text]').element as HTMLTextAreaElement).value).toBe('Hi')
        }
        // The site's own request limit answers 429 without a code: still the plain "many posts" words.
        ajaxMock.post.mockRejectedValue({ response: { status: 429, data: { message: 'Too Many Attempts.' } } })
        await wrapper.find('[data-testid=community-ask-send]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=community-form-error]').text()).toContain('text.modules.store.community.error.rate_limited')
    })

    it('keeps the save button disabled until stars are picked', async () => {
        const wrapper = await mountCommunity()
        await wrapper.find('[data-testid=community-rate-open]').trigger('click')
        expect(wrapper.find('[data-testid=community-rate-save]').attributes('disabled')).toBeDefined()
    })

    it('shows the refusal in the form when the hub refuses the rating', async () => {
        ajaxMock.put.mockRejectedValue({ response: { data: { error_code: 'MODULE_COMMUNITY_INSTALL_REQUIRED' } } })
        const wrapper = await mountCommunity()
        await wrapper.find('[data-testid=community-rate-open]').trigger('click')
        await wrapper.find('[data-testid=community-rate-stars]').setValue('5')
        await wrapper.find('[data-testid=community-rate-save]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=community-form-error]').text()).toContain('text.modules.store.community.reason.install_required')
    })

    it('asks a question and shows it first', async () => {
        ajaxMock.post.mockResolvedValue({ data: { data: { question: { body: 'Có bản tiếng Anh không?', author_kind: 'site', author_name: 'Vương', created_at: '2026-11-15T00:00:00Z', answers: [] } } } })
        const wrapper = await mountCommunity()
        await wrapper.find('[data-testid=community-ask-open]').trigger('click')
        await wrapper.find('[data-testid=community-ask-text]').setValue('Có bản tiếng Anh không?')
        await wrapper.find('[data-testid=community-ask-send]').trigger('click')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledWith('utilities/modules/catalog/Helpdesk/questions', { body: 'Có bản tiếng Anh không?', author_name: 'Vương' }, expect.anything())
        expect(wrapper.findAll('[data-testid=community-question]')[0].text()).toContain('Có bản tiếng Anh không?')
    })

    it('sends private feedback with its kind', async () => {
        ajaxMock.post.mockResolvedValue({ data: { data: { sent: true } } })
        const wrapper = await mountCommunity()
        await wrapper.find('[data-testid=community-feedback-open]').trigger('click')
        await wrapper.find('[data-testid=community-feedback-kind]').setValue('praise')
        await wrapper.find('[data-testid=community-feedback-text]').setValue('Rất tốt')
        await wrapper.find('[data-testid=community-feedback-send]').trigger('click')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledWith('utilities/modules/catalog/Helpdesk/feedback', { kind: 'praise', body: 'Rất tốt', author_name: 'Vương' }, expect.anything())
        expect(messageMock.success).toHaveBeenCalledWith('text.modules.store.community.feedback_sent')
        expect(wrapper.find('[data-testid=community-feedback-form]').exists()).toBe(false)
    })

    it('renders nothing when the hub has no ratings yet (an older hub)', async () => {
        state.mine = mineAnswer({ available: false })
        state.ratings = ratingsAnswer({ available: false, items: [], average: null, count: 0 })
        state.questions = questionsAnswer({ available: false, items: [] })
        const wrapper = await mountCommunity()
        expect(wrapper.find('[data-testid=store-community]').exists()).toBe(false)
    })

    it('shows plain empty states and a retry after a failure', async () => {
        state.ratings = ratingsAnswer({ items: [], average: null, count: 0, total: 0 })
        state.questions = questionsAnswer({ items: [], total: 0 })
        const wrapper = await mountCommunity()
        expect(wrapper.find('[data-testid=community-ratings-empty]').text()).toContain('text.modules.store.community.no_ratings')
        expect(wrapper.find('[data-testid=community-questions-empty]').text()).toContain('text.modules.store.community.no_questions')

        ajaxMock.get.mockRejectedValue({ response: { data: { error_code: 'MODULE_CATALOG_UNAVAILABLE' } } })
        const failed = await mountCommunity()
        expect(failed.find('[data-testid=community-error]').exists()).toBe(true)
        expect(failed.find('[data-testid=community-retry]').exists()).toBe(true)
    })
})

describe('StoreTab average stars', () => {
    const card = (rating: unknown) => ({
        name: 'Helpdesk',
        label: 'Helpdesk',
        latest: { version: '1.2.0' },
        installed: 'none',
        installed_version: null,
        canInstall: true,
        canUpgrade: false,
        requirements: [],
        listing: { content: { vi: { display_name: 'Trợ giúp', summary: '' }, en: {} }, rating },
    })

    it('shows the average and the count when the module has ratings', () => {
        const wrapper = mount(StoreTab, { ...mountOpts, props: { rows: [card({ average: 4.5, count: 3 })], locale: 'vi' } })
        expect(wrapper.find('[data-testid=store-card-rating]').text()).toBe('text.modules.store.community.summary|{"average":"4,5","count":3}#3')
    })

    it('shows nothing without ratings or from an older hub', () => {
        for (const rating of [{ average: null, count: 0 }, undefined]) {
            const wrapper = mount(StoreTab, { ...mountOpts, props: { rows: [card(rating)], locale: 'vi' } })
            expect(wrapper.find('[data-testid=store-card-rating]').exists()).toBe(false)
        }
    })
})
