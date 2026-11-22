import { mount } from '@vue/test-utils'
import { nextTick, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AllEmail from '../../../cms/Content/admin/pages/contact/all-email.vue'
import EmailView from '../../../cms/Content/admin/pages/contact/email-view.vue'
import { iconMaps } from '@/config/icon-maps'
import { deleteEmail, getEmail, markEmailsAsSpam, replyEmail } from '@/libs'
import { iAlert } from '@/mixins'

const routerPush = vi.fn()
const routerGo = vi.fn()
const mockedHooks = vi.hoisted(() => ({
    useEmails: vi.fn(),
}))
const route = {
    name: 'email-all',
    params: { uuid: 'email-uuid-1' },
    query: {},
}
const refetch = vi.fn()
const emails = ref({
    data: {
        current_page: 1,
        data: [
            {
                contact: { email: 'dev@example.com', name: 'Dev Test' },
                content: '<p>Nội dung <strong>email</strong></p>',
                created_at: '2026-08-11 10:30',
                from_email: 'dev@example.com',
                from_name: 'Dev Test',
                id: 12,
                last_read: null,
                subject: 'Mail test',
                type: 'inbox',
                uuid: 'email-uuid-1',
            },
        ],
        from: 1,
        last_page: 1,
        to: 1,
        total: 1,
    },
})

vi.mock('@/hooks', () => ({
    useEmails: mockedHooks.useEmails,
}))

vi.mock('@/libs', () => ({
    deleteEmail: vi.fn(),
    getEmail: vi.fn(),
    markEmailsAsSpam: vi.fn(),
    replyEmail: vi.fn(),
}))

vi.mock('@/mixins', () => ({
    finishFailed: vi.fn(),
    iAlert: vi.fn(),
    initFilter: (defaults: Record<string, unknown>, query: Record<string, unknown>) => ({
        ...defaults,
        ...query,
    }),
    loadingSpinner: vi.fn(),
    objectAssignOnlyByKeyHasValue: (form: Record<string, unknown>, data: Record<string, unknown>) => ({
        ...form,
        ...data,
    }),
}))

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string) => key }),
}))

vi.mock('vue-router', () => ({
    useRoute: () => route,
    useRouter: () => ({ go: routerGo, push: routerPush }),
}))

vi.mock('@/components/tiptap-editor.vue', () => ({
    default: {
        name: 'TipTapEditor',
        emits: ['update:modelValue'],
        props: ['modelValue'],
        template: '<div data-test="editor" />',
    },
}))

vi.mock('@/components/common/SafeHtml.vue', () => ({
    default: {
        name: 'SafeHtml',
        props: ['html'],
        template: '<div data-test="safe-html">{{ html }}</div>',
    },
}))

const passthrough = { template: '<div><slot /></div>' }
const stubs = {
    'a-button': {
        emits: ['click'],
        template: '<button @click="$emit(\'click\', $event)"><slot /></button>',
    },
    'a-checkbox': {
        emits: ['change'],
        props: ['checked'],
        template: '<label><input :checked="checked" type="checkbox" @change="$emit(\'change\', $event)" /><slot /></label>',
    },
    'a-empty': { template: '<div data-test="empty" />' },
    'a-form': { emits: ['finish'], template: '<form @submit.prevent="$emit(\'finish\', {})"><slot /></form>' },
    'a-form-item': passthrough,
    'a-popconfirm': { template: '<div><slot name="title" /><slot /></div>' },
    'a-spin': passthrough,
    'a-tag': passthrough,
    breadcrumbs: {
        name: 'Breadcrumbs',
        props: ['items'],
        template: '<nav data-test="breadcrumbs" />',
    },
    'i-icon': true,
}

const mountOptions = {
    global: {
        config: { globalProperties: { $t: (key: string) => key } },
        provide: { getCounter: vi.fn() },
        stubs,
    },
}

const flush = async () => {
    await Promise.resolve()
    await nextTick()
}

describe('Contact email Gmail-like layouts', () => {
    beforeEach(() => {
        route.name = 'email-all'
        route.params.uuid = 'email-uuid-1'
        route.query = {}
        routerGo.mockReset()
        routerPush.mockReset()
        refetch.mockReset()
        mockedHooks.useEmails.mockReset()
        mockedHooks.useEmails.mockReturnValue({
            data: emails,
            isFetching: ref(false),
            refetch,
        })
        vi.mocked(deleteEmail).mockReset()
        vi.mocked(getEmail).mockReset()
        vi.mocked(markEmailsAsSpam).mockReset()
        vi.mocked(replyEmail).mockReset()
        vi.mocked(iAlert).mockReset()
        emails.value = {
            data: {
                current_page: 1,
                data: [
                    {
                        contact: { email: 'dev@example.com', name: 'Dev Test' },
                        content: '<p>Nội dung <strong>email</strong></p>',
                        created_at: '2026-08-11 10:30',
                        from_email: 'dev@example.com',
                        from_name: 'Dev Test',
                        id: 12,
                        last_read: null,
                        subject: 'Mail test',
                        type: 'inbox',
                        uuid: 'email-uuid-1',
                    },
                ],
                from: 1,
                last_page: 1,
                to: 1,
                total: 1,
            },
        }
    })

    it('renders a Gmail-like inbox row with a clean excerpt and opens the email UUID', async () => {
        const wrapper = mount(AllEmail, mountOptions)
        await flush()

        expect(wrapper.text()).toContain('Nội dung email')
        expect(wrapper.text()).not.toContain('<p>')

        await wrapper.get('[data-test="mail-row"]').trigger('click')

        expect(routerPush).toHaveBeenCalledWith({
            name: 'email-view',
            params: { uuid: 'email-uuid-1' },
        })
    })

    it('switches mailbox filters through the current route query', async () => {
        const wrapper = mount(AllEmail, mountOptions)
        await flush()

        const sentTab = wrapper.findAll('button').find((button) => button.text() === 'nav.a.email-sent')
        await sentTab?.trigger('click')

        expect(routerPush).toHaveBeenCalledWith({
            name: 'email-all',
            query: { page: 1, type: 'sent' },
        })
    })

    it('uses 20 rows per page and persists a keyword filter in the mailbox query', async () => {
        const wrapper = mount(AllEmail, mountOptions)
        await flush()

        expect(mockedHooks.useEmails).toHaveBeenCalledWith(
            expect.objectContaining({ limit: 20, type: 'inbox' }),
            expect.any(Object)
        )

        await wrapper.get('[data-test="mail-search"]').setValue('spam@example.test')
        await wrapper.get('[data-test="mail-search-form"]').trigger('submit')

        expect(routerPush).toHaveBeenCalledWith({
            name: 'email-all',
            query: { page: 1, query: 'spam@example.test' },
        })
    })

    it('provides first, previous, next, and last mailbox pagination controls', async () => {
        emails.value.data.current_page = 2
        emails.value.data.from = 21
        emails.value.data.last_page = 4
        emails.value.data.to = 40
        emails.value.data.total = 79
        const wrapper = mount(AllEmail, mountOptions)
        await flush()

        expect(wrapper.get('[data-test="mail-page-summary"]').text()).toBe('21–40 / 79')

        await wrapper.findAll('button').find((button) => button.text() === 'pagination.first')?.trigger('click')
        await wrapper.findAll('button').find((button) => button.text() === 'pagination.last')?.trigger('click')

        expect(routerPush).toHaveBeenCalledWith({ name: 'email-all', query: { page: 1 } })
        expect(routerPush).toHaveBeenCalledWith({ name: 'email-all', query: { page: 4 } })
    })

    it('maps the previous-page icon instead of falling back to the reload alert icon', () => {
        expect(iconMaps['chevron-left']).toBeTruthy()
    })

    it('deletes selected email rows by UUID', async () => {
        vi.mocked(deleteEmail).mockResolvedValue({ status: 'success' })
        const wrapper = mount(AllEmail, mountOptions)
        await flush()

        await wrapper.find('input[type="checkbox"]').setValue(true)
        await (wrapper.vm as unknown as { deleteSelected: () => Promise<void> }).deleteSelected()

        expect(deleteEmail).toHaveBeenCalledWith('email-uuid-1')
    })

    it('marks selected inbox senders as spam by UUID without blocking sent recipients', async () => {
        vi.mocked(markEmailsAsSpam).mockResolvedValue({ status: 'success' })
        emails.value.data.data.push({
            contact: { email: 'recipient@example.com', name: 'Recipient' },
            content: '<p>Mail đã gửi</p>',
            created_at: '2026-08-11 11:00',
            from_email: 'admin@example.com',
            subject: 'Mail đã gửi',
            type: 'sent',
            uuid: 'email-uuid-2',
        })
        const wrapper = mount(AllEmail, mountOptions)
        await flush()

        await (wrapper.vm as unknown as { toggleAll: (checked: boolean) => void }).toggleAll(true)
        await (wrapper.vm as unknown as { markSelectedAsSpam: () => Promise<void> }).markSelectedAsSpam()

        expect(markEmailsAsSpam).toHaveBeenCalledWith(['email-uuid-1'], false)
    })

    it('passes the custom-domain block choice with selected inbox UUIDs', async () => {
        vi.mocked(markEmailsAsSpam).mockResolvedValue({ status: 'success' })
        const wrapper = mount(AllEmail, mountOptions)
        await flush()

        const vm = wrapper.vm as unknown as {
            blockCustomDomains: boolean
            markSelectedAsSpam: () => Promise<void>
            toggleAll: (checked: boolean) => void
        }
        await vm.toggleAll(true)
        vm.blockCustomDomains = true
        await vm.markSelectedAsSpam()

        expect(markEmailsAsSpam).toHaveBeenCalledWith(['email-uuid-1'], true)
    })

    it('uses translated admin keys for spam confirmation and failure messages', async () => {
        vi.mocked(markEmailsAsSpam).mockResolvedValue({ status: 'error' })
        const wrapper = mount(AllEmail, mountOptions)
        await flush()

        await (wrapper.vm as unknown as { toggleAll: (checked: boolean) => void }).toggleAll(true)

        expect(wrapper.text()).toContain('text.contact.email.spam.confirm')
        expect(wrapper.text()).toContain('text.contact.email.spam.block_custom_domain')
        expect(wrapper.get('[aria-label="text.contact.email.spam.action"]').exists()).toBe(true)

        await (wrapper.vm as unknown as { markSelectedAsSpam: () => Promise<void> }).markSelectedAsSpam()

        expect(iAlert).toHaveBeenCalledWith('text.contact.email.spam.failed')
    })

    it('keeps reply collapsed until requested and labels the contact panel clearly', async () => {
        vi.mocked(getEmail).mockResolvedValue({
            status: 'success',
            data: {
                contact: { email: 'dev@example.com', name: 'Dev Test' },
                contact_id: 3,
                content: '<p>Nội dung email</p>',
                created_at: '2026-08-11 10:30',
                id: 12,
                subject: 'Mail test',
                type: 'inbox',
            },
        })
        const wrapper = mount(EmailView, mountOptions)
        await flush()

        expect(wrapper.find('[data-test="reply-composer"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="contact-panel"]').exists()).toBe(false)

        await wrapper.get('[data-test="contact-toggle"]').trigger('click')
        expect(wrapper.find('[data-test="contact-panel"]').text()).toContain('Dev Test')

        await wrapper.get('[data-test="reply-toggle"]').trigger('click')
        expect(wrapper.find('[data-test="reply-composer"]').text()).toContain('dev@example.com')
    })

    it('keeps reply recipient fields when the editor emits new content', async () => {
        vi.mocked(getEmail).mockResolvedValue({
            status: 'success',
            data: {
                contact: { email: 'dev@example.com', name: 'Dev Test' },
                contact_id: 3,
                content: '<p>Nội dung email</p>',
                created_at: '2026-08-11 10:30',
                id: 12,
                subject: 'Mail test',
                type: 'inbox',
            },
        })
        vi.mocked(replyEmail).mockResolvedValue({ status: 'success' })
        const wrapper = mount(EmailView, mountOptions)
        await flush()

        await wrapper.get('[data-test="reply-toggle"]').trigger('click')
        wrapper.findComponent({ name: 'TipTapEditor' }).vm.$emit('update:modelValue', '<p>Đã soạn</p>')
        await nextTick()

        expect(wrapper.find('[data-test="reply-composer"]').text()).toContain('Dev Test <dev@example.com>')

        await wrapper.find('form').trigger('submit')

        expect(replyEmail).toHaveBeenCalledWith(
            12,
            expect.objectContaining({
                content: '<p>Đã soạn</p>',
                email: 'dev@example.com',
                name: 'Dev Test',
            })
        )
    })

    it('shows breadcrumbs for the contact mailbox and current email', async () => {
        vi.mocked(getEmail).mockResolvedValue({
            status: 'success',
            data: {
                content: '<p>Nội dung email</p>',
                subject: 'Mail test',
                type: 'inbox',
            },
        })
        const wrapper = mount(EmailView, mountOptions)
        await flush()

        const items = wrapper.findComponent({ name: 'Breadcrumbs' }).props('items')

        expect(items).toEqual(
            expect.arrayContaining([
                expect.objectContaining({ name: 'contact-dashboard' }),
                expect.objectContaining({ name: 'email-all' }),
                expect.objectContaining({ text: 'Mail test' }),
            ])
        )
    })
})
