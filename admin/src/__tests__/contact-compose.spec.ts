import { flushPromises, mount } from '@vue/test-utils'
import { nextTick, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ComposeMail from '../../../cms/Content/admin/pages/contact/compose.vue'
import SelectInputContact from '@/components/select-input-contact.vue'
import { storeEmail } from '@/libs'

vi.mock('@/libs', () => ({
    storeEmail: vi.fn(),
}))

vi.mock('@/hooks', () => ({
    useSearchContacts: () => ({
        data: ref([
            { id: 3, name: 'Dev Test', email: 'dev@example.com' },
            { id: 4, name: 'Dev Test', email: 'other@example.com' },
        ]),
        isFetching: ref(false),
    }),
}))

vi.mock('vue-router', () => ({
    useRouter: () => ({ go: vi.fn() }),
}))

vi.mock('@/components/tiptap-editor.vue', () => ({
    default: {
        name: 'TipTapEditor',
        props: { modelValue: String },
        template: '<div />',
    },
}))

const passthroughStub = { template: '<div><slot /></div>' }

const composeStubs = {
    breadcrumbs: passthroughStub,
    'a-form': {
        methods: {
            resetFields() {},
            clearValidate() {},
        },
        template: '<form><slot /></form>',
    },
    'a-form-item': passthroughStub,
    'a-divider': passthroughStub,
    'a-input': { props: ['value', 'disabled'], template: '<input />' },
    'a-button': { template: '<button><slot /></button>' },
    'select-input-contact': {
        name: 'SelectInputContact',
        props: ['modelValue', 'multiple', 'disabled', 'label', 'name'],
        emits: ['update:modelValue', 'contact-selected'],
        template: '<div />',
    },
    'i-icon': true,
}

describe('Contact compose email contract', () => {
    beforeEach(() => {
        vi.mocked(storeEmail).mockReset()
        vi.mocked(storeEmail).mockResolvedValue({ status: 'error' })
    })

    it('handles the successful unwrapped email response envelope', async () => {
        const getCounter = vi.fn()
        vi.mocked(storeEmail).mockResolvedValue({
            status: 'success',
            message: 'Email sent',
        })
        const wrapper = mount(ComposeMail, {
            global: {
                provide: { getCounter },
                stubs: composeStubs,
                directives: { loading: {} },
            },
        })

        wrapper.vm.form.subject = 'Test'
        wrapper.vm.form.content = '<p>Body</p>'
        wrapper.vm.form.to_email = 'manual@example.com'
        wrapper.vm.submitForm()
        await flushPromises()

        expect(getCounter).toHaveBeenCalledOnce()
        expect(wrapper.vm.form).toMatchObject({
            subject: null,
            content: null,
            to_email: null,
        })
        expect(wrapper.vm.btnState).toBe(false)
    })

    it('uses contact email and name when selecting a saved contact', async () => {
        const wrapper = mount(ComposeMail, {
            global: {
                provide: { getCounter: vi.fn() },
                stubs: composeStubs,
                directives: { loading: {} },
            },
        })
        const recipient = wrapper.findAllComponents({ name: 'SelectInputContact' })[0]

        recipient.vm.$emit('update:modelValue', 'dev@example.com')
        recipient.vm.$emit('contact-selected', {
            id: 3,
            name: 'Dev Test',
            email: 'dev@example.com',
        })
        wrapper.vm.form.subject = 'Test'
        wrapper.vm.form.content = '<p>Body</p>'
        await nextTick()

        wrapper.vm.submitForm()
        await nextTick()

        expect(storeEmail).toHaveBeenCalledWith(
            expect.objectContaining({
                email: 'dev@example.com',
                name: 'Dev Test',
                contact_id: 3,
                content: '<p>Body</p>',
            })
        )
        expect(storeEmail.mock.calls[0][0]).not.toHaveProperty('to_email')
        expect(storeEmail.mock.calls[0][0]).not.toHaveProperty('to_name')
    })

    it('does not let null validation fields erase the selected contact name', async () => {
        const wrapper = mount(ComposeMail, {
            global: {
                provide: { getCounter: vi.fn() },
                stubs: composeStubs,
                directives: { loading: {} },
            },
        })
        const recipient = wrapper.findAllComponents({ name: 'SelectInputContact' })[0]

        recipient.vm.$emit('update:modelValue', 'dev@example.com')
        recipient.vm.$emit('contact-selected', {
            id: 3,
            name: 'Dev Test',
            email: 'dev@example.com',
        })
        wrapper.vm.form.subject = 'Test'
        wrapper.vm.form.content = '<p>Body</p>'
        await nextTick()

        wrapper.vm.submitForm({
            email: 'dev@example.com',
            name: null,
            to_email: null,
            to_name: null,
        })
        await nextTick()

        expect(storeEmail).toHaveBeenCalledWith(
            expect.objectContaining({
                email: 'dev@example.com',
                name: 'Dev Test',
                contact_id: 3,
            })
        )
    })

    it('clears the contact id when the saved contact is cleared', async () => {
        const wrapper = mount(ComposeMail, {
            global: {
                provide: { getCounter: vi.fn() },
                stubs: composeStubs,
                directives: { loading: {} },
            },
        })
        const recipient = wrapper.findAllComponents({ name: 'SelectInputContact' })[0]

        recipient.vm.$emit('update:modelValue', 'dev@example.com')
        recipient.vm.$emit('contact-selected', {
            id: 3,
            name: 'Dev Test',
            email: 'dev@example.com',
        })
        recipient.vm.$emit('update:modelValue', null)
        recipient.vm.$emit('contact-selected', null)
        await nextTick()

        expect(wrapper.vm.form.contact_id).toBeNull()
    })

    it('hides manual recipient fields while a saved contact is selected', async () => {
        const wrapper = mount(ComposeMail, {
            global: {
                provide: { getCounter: vi.fn() },
                stubs: composeStubs,
                directives: { loading: {} },
            },
        })
        const recipient = wrapper.findAllComponents({ name: 'SelectInputContact' })[0]

        expect(wrapper.get('[data-test="manual-recipient-fields"]').exists()).toBe(true)

        recipient.vm.$emit('update:modelValue', 'dev@example.com')
        recipient.vm.$emit('contact-selected', {
            id: 3,
            name: 'Dev Test',
            email: 'dev@example.com',
        })
        await nextTick()

        expect(wrapper.find('[data-test="manual-recipient-fields"]').exists()).toBe(false)

        recipient.vm.$emit('update:modelValue', null)
        recipient.vm.$emit('contact-selected', null)
        await nextTick()

        expect(wrapper.get('[data-test="manual-recipient-fields"]').exists()).toBe(true)
    })

    it('maps manually entered recipient fields to the API contract', async () => {
        const wrapper = mount(ComposeMail, {
            global: {
                provide: { getCounter: vi.fn() },
                stubs: composeStubs,
                directives: { loading: {} },
            },
        })

        wrapper.vm.form.subject = 'Test'
        wrapper.vm.form.content = '<p>Body</p>'
        wrapper.vm.form.to_email = 'manual@example.com'
        wrapper.vm.form.to_name = 'Manual Recipient'
        wrapper.vm.submitForm()
        await nextTick()

        expect(storeEmail).toHaveBeenCalledWith(
            expect.objectContaining({
                email: 'manual@example.com',
                name: 'Manual Recipient',
            })
        )
    })
})

describe('SelectInputContact email values', () => {
    it('uses contact email as option value and emits selected contact', async () => {
        const wrapper = mount(SelectInputContact, {
            props: { modelValue: null },
            global: {
                stubs: {
                    'a-form-item': passthroughStub,
                    'a-select': {
                        name: 'ASelect',
                        props: ['modelValue', 'options'],
                        emits: ['update:value'],
                        template: '<div />',
                    },
                },
                directives: { loading: {} },
            },
        })
        const select = wrapper.findComponent({ name: 'ASelect' })

        expect(select.props('options')).toEqual([
            { label: 'Dev Test <dev@example.com>', value: 'dev@example.com' },
            { label: 'Dev Test <other@example.com>', value: 'other@example.com' },
        ])

        select.vm.$emit('update:value', 'dev@example.com')
        await nextTick()

        expect(wrapper.emitted('update:modelValue')).toEqual([['dev@example.com']])
        expect(wrapper.emitted('contact-selected')?.[0]).toEqual([
            { id: 3, name: 'Dev Test', email: 'dev@example.com' },
        ])
    })
})
