import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const api = vi.hoisted(() => ({ fetchEmailTemplateCatalog: vi.fn(), fetchNodeCatalog: vi.fn() }))
const access = vi.hoisted(() => ({ rules: new Set<string>(['email_template.viewAny']) }))
const graph = vi.hoisted(() => ({ nodes: null as any }))

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key} ${JSON.stringify(params)}` : key), te: () => false }),
}))
vi.mock('@/services/workflowApi', () => api)
vi.mock('@/mixins', () => ({ rule: (key: string) => access.rules.has(key), iAlert: vi.fn() }))
vi.mock('@/components/workflow/inputs/useStepData.js', () => ({ useStepData: () => ({ nodes: graph.nodes }), provideStepForm: vi.fn(), useStepForm: () => null }))
vi.mock('@/components/workflow/inputs/DataTokenInput.vue', () => ({
    default: {
        name: 'DataTokenInput',
        props: ['modelValue', 'disabled'],
        emits: ['update:modelValue'],
        template: '<input data-testid="data-token" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
}))
vi.mock('@/components/workflow/widgets/PermissionPicker.vue', () => ({
    default: {
        name: 'PermissionPicker',
        props: ['modelValue', 'readOnly'],
        emits: ['update:modelValue'],
        template: '<input data-testid="permission-picker" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
}))
vi.mock('@/components/workflow/node-editors/SchemaFormEditor.vue', () => ({
    default: { name: 'SchemaFormEditor', props: ['schema', 'modelValue', 'stepType'], template: '<div data-testid="text-form" :data-fields="Object.keys(schema.properties).join(\',\')" />' },
}))
vi.mock('@/components/workflow/node-editors/SchemaField.vue', () => ({ default: { name: 'SchemaField', props: ['name'], template: '<div :data-more="name" />' } }))

import EmailSendEditor from '@/components/workflow/node-editors/EmailSendEditor.vue'
import { resolveEditorComponent } from '@/components/workflow/node-editors/editorRegistry.js'
import { applyNodeCatalog } from '@/components/workflows/nodeCatalog'
import { isManaged, languageKind, startsOnSubjectEvent, switchMode, switchRecipient, switchTemplate, variableRows, writeVariable } from '@/components/workflow/widgets/emailSendRules.js'

const CONTACT = 'App\\Events\\ContactMessageReceived'
const str = { type: ['string', 'null'] }
const emailNode = {
    type: 'email_send',
    version: 2,
    safety: ['external_call'],
    approval_default: 'on',
    config_schema: { type: 'object', properties: { credential: { ...str, 'x-secret': 'smtp' }, mode: str, to: str, subject: str, body: str, reply_to: str, template_key: str, language: str, variables: { type: ['object', 'null'] }, recipient: str, recipient_permission: str, business_enabled: { type: 'boolean' }, storeAs: { ...str, 'x-store': true } } },
}
const templates = [
    {
        key_name: 'contact_auto_reply',
        mail_class: 'system',
        name: 'Đã nhận được tin nhắn của bạn',
        languages: [
            { id: 1, language_id: 1, code: 'vi', name: 'Tiếng Việt', mail_class: 'system' },
            { id: 2, language_id: 2, code: 'en', name: 'English', mail_class: 'system' },
        ],
        variables: [
            { key: 'display_name', description: 'Tên người gửi', sample: 'Lan Nguyễn', declared: true, builtin: false },
            { key: 'site_name', description: 'Tên trang', sample: 'iSPA', declared: true, builtin: true },
        ],
    },
    { key_name: 'spring_offer', mail_class: 'business', name: 'Ưu đãi mùa xuân', languages: [{ id: 3, language_id: 1, code: 'vi', name: 'Tiếng Việt', mail_class: 'business' }], variables: [] },
]
const eventNode = (event: string) => ({ id: 't1', data: { step_type: 'trigger_event', step_config: { event } } })

const stubs = {
    'a-radio-group': {
        props: ['value', 'disabled'],
        emits: ['update:value'],
        provide() {
            return { pick: (value: string) => (this as any).$emit('update:value', value) }
        },
        template: '<div role="radiogroup" :data-value="value"><slot /></div>',
    },
    'a-radio': { props: ['value'], inject: ['pick'], template: '<button type="button" :data-radio="value" @click="pick(value)"><slot /></button>' },
    'a-radio-button': { props: ['value'], inject: ['pick'], template: '<button type="button" :data-radio="value" @click="pick(value)"><slot /></button>' },
    'a-select': {
        props: ['value', 'options'],
        emits: ['update:value'],
        template: '<select :value="value" @change="$emit(\'update:value\', $event.target.value)"><option v-for="o in options" :key="o.value" :value="o.value" :data-class="o.mailClass" :data-languages="o.languages">{{ o.label }}</option></select>',
    },
    'a-switch': { props: ['checked', 'disabled'], emits: ['update:checked'], template: '<input type="checkbox" :checked="checked" :disabled="disabled" @change="$emit(\'update:checked\', $event.target.checked)" />' },
    'a-tag': { template: '<span><slot /></span>' },
    'a-alert': { props: ['message'], template: '<div role="alert">{{ message }}</div>' },
    'a-button': { template: '<button type="button"><slot /></button>' },
    'i-icon': true,
    'router-link': { props: ['to'], template: '<a :data-to="to.name"><slot /></a>' },
}

/** Mount the form and keep it in step with what it emits, like the step panel does. */
const open = async (modelValue: Record<string, unknown>, props: Record<string, unknown> = {}) => {
    const wrapper = mount(EmailSendEditor, {
        props: { stepType: 'email_send', modelValue, ...props, 'onUpdate:modelValue': (value: Record<string, unknown>) => wrapper.setProps({ modelValue: value }) },
        global: { stubs },
    })
    await flushPromises()
    return wrapper
}
const config = (wrapper: Awaited<ReturnType<typeof open>>) => wrapper.props('modelValue') as Record<string, any>

beforeEach(() => {
    api.fetchEmailTemplateCatalog.mockReset().mockResolvedValue(templates)
    access.rules = new Set(['email_template.viewAny'])
    graph.nodes = ref([eventNode(CONTACT)])
    applyNodeCatalog({ nodes: [emailNode], trigger_subject_events: [CONTACT] })
})

describe('email_send rules', () => {
    it('knows whether the workflow starts on an event that names its own person', () => {
        expect(startsOnSubjectEvent([eventNode(CONTACT)], [CONTACT])).toBe(true)
        expect(startsOnSubjectEvent([eventNode(`\\${CONTACT}`)], [CONTACT])).toBe(true)
        expect(startsOnSubjectEvent([eventNode('App\\Events\\OrderPaid')], [CONTACT])).toBe(false)
        expect(startsOnSubjectEvent([{ id: 'm', data: { step_type: 'trigger_manual', step_config: {} } }], [CONTACT])).toBe(false)
        // An older server does not say: every app-event workflow is offered the choice and the save decides.
        expect(startsOnSubjectEvent([eventNode('App\\Events\\OrderPaid')], null)).toBe(true)
        expect(startsOnSubjectEvent([], null)).toBe(false)
    })

    it('switches mode without leaving typed text in a template step', () => {
        const text = { mode: 'text', recipient: 'address', to: 'a@example.com', subject: 'Chào', body: 'Nội dung', reply_to: 'b@example.com', storeAs: 'vars.email' }
        expect(switchMode(text, 'template', { hasSubject: true })).toEqual({ mode: 'template', subject: undefined, body: undefined, reply_to: undefined, to: undefined, recipient: 'trigger_subject' })
        expect(switchMode(text, 'template', { hasSubject: false }).recipient).toBe('staff')
        expect(switchMode(text, 'text')).toEqual({})

        const template = { mode: 'template', template_key: 'spring_offer', recipient: 'staff', recipient_permission: 'contact.viewAny', business_enabled: true, variables: { a: 'b' } }
        expect(switchMode(template, 'text', { kept: { to: 'a@example.com', subject: 'Chào', body: 'Nội dung' } })).toEqual({
            mode: 'text', template_key: undefined, language: undefined, variables: undefined, recipient_permission: undefined, business_enabled: undefined, recipient: 'address', to: 'a@example.com', subject: 'Chào', body: 'Nội dung',
        })
    })

    it('keeps the recipient settings of one kind only, and resets the business switch with the template', () => {
        expect(switchRecipient({ recipient: 'address', to: 'a@example.com' }, 'staff')).toEqual({ recipient: 'staff', to: undefined, recipient_permission: '' })
        expect(switchRecipient({ recipient: 'staff', recipient_permission: 'contact.viewAny' }, 'trigger_subject')).toEqual({ recipient: 'trigger_subject', to: undefined, recipient_permission: undefined })
        expect(switchRecipient({ recipient: 'staff', recipient_permission: 'contact.viewAny' }, 'address')).toEqual({ recipient: 'address', recipient_permission: undefined, to: '' })
        expect(switchTemplate({ business_enabled: true, variables: { display_name: '{{ vars.a }}', gone: 'x' } }, templates[0])).toEqual({ template_key: 'contact_auto_reply', variables: { display_name: '{{ vars.a }}' }, business_enabled: undefined })
        expect(switchTemplate({ variables: { gone: 'x' } }, templates[1])).toEqual({ template_key: 'spring_offer', variables: undefined, business_enabled: undefined })
    })

    it('reads the language, the blanks to fill and policy B like the server', () => {
        expect(languageKind('', templates[0])).toBe('default')
        expect(languageKind('en', templates[0])).toBe('fixed')
        expect(languageKind('{{ vars.trigger.event.payload.locale }}', templates[0])).toBe('data')
        expect(variableRows(templates[0], { display_name: '{{ vars.n }}' })).toEqual([{ key: 'display_name', label: 'Tên người gửi', sample: 'Lan Nguyễn', value: '{{ vars.n }}' }])
        expect(writeVariable({ a: '1' }, 'b', '2')).toEqual({ a: '1', b: '2' })
        expect(writeVariable({ a: '1' }, 'a', '')).toBeUndefined()

        expect(isManaged({ mode: 'template', template_key: 'contact_auto_reply', recipient: 'trigger_subject' })).toBe(true)
        expect(isManaged({ mode: 'template', template_key: 'contact_auto_reply', recipient: 'staff', recipient_permission: 'contact.viewAny' })).toBe(true)
        expect(isManaged({ mode: 'template', template_key: 'contact_auto_reply', recipient: 'staff', recipient_permission: '' })).toBe(false)
        expect(isManaged({ mode: 'template', template_key: 'contact_auto_reply', recipient: 'address', to: 'a@example.com' })).toBe(false)
        expect(isManaged({ mode: 'template', template_key: 'contact_auto_reply', recipient: 'trigger_subject', subject: 'typed' })).toBe(false)
        expect(isManaged({ mode: 'text', to: 'a@example.com' })).toBe(false)
    })
})

describe('EmailSendEditor', () => {
    it('is the form of the email_send step', () => {
        expect(resolveEditorComponent('email_send', emailNode)).toBe(EmailSendEditor)
    })

    it('keeps the typed form in text mode, limited to its own settings', async () => {
        const wrapper = await open({ mode: 'text', recipient: 'address', to: 'a@example.com', subject: 'Chào', body: 'Nội dung', storeAs: 'vars.email' })

        expect(wrapper.get('[data-testid="email-send-mode"]').attributes('data-value')).toBe('text')
        expect(wrapper.get('[data-testid="text-form"]').attributes('data-fields')).toBe('credential,to,subject,body,reply_to,storeAs')
        expect(wrapper.find('[data-testid="email-send-template"]').exists()).toBe(false)
        expect(api.fetchEmailTemplateCatalog).not.toHaveBeenCalled()
    })

    it('switches to a template: drops the typed text, loads the templates and gives the text back on the way back', async () => {
        const wrapper = await open({ mode: 'text', recipient: 'address', to: 'a@example.com', subject: 'Chào', body: 'Nội dung', storeAs: 'vars.email', on_error: 'route' })

        await wrapper.get('[data-testid="email-send-mode-template"]').trigger('click')
        await flushPromises()

        // Unknown keys (`on_error`) and the result name stay; nothing typed is left behind.
        expect(config(wrapper)).toEqual({ mode: 'template', recipient: 'trigger_subject', storeAs: 'vars.email', on_error: 'route' })
        expect(api.fetchEmailTemplateCatalog).toHaveBeenCalledTimes(1)
        expect(wrapper.find('[data-testid="text-form"]').exists()).toBe(false)

        // The picker names each template with its class and its languages; no key is shown as the label.
        const options = wrapper.get('[data-testid="email-send-template-select"]').findAll('option')
        expect(options.map((option) => option.text())).toEqual(['Đã nhận được tin nhắn của bạn', 'Ưu đãi mùa xuân'])
        expect(options[0].attributes('data-class')).toBe('system')
        expect(options[0].attributes('data-languages')).toBe('Tiếng Việt, English')

        await wrapper.get('[data-testid="email-send-mode-text"]').trigger('click')
        expect(config(wrapper)).toEqual({ mode: 'text', recipient: 'address', to: 'a@example.com', subject: 'Chào', body: 'Nội dung', storeAs: 'vars.email', on_error: 'route' })
    })

    it('shows the business switch only for a business template, off until switched on', async () => {
        const wrapper = await open({ mode: 'template', recipient: 'trigger_subject', template_key: 'contact_auto_reply' })

        expect(wrapper.get('[data-testid="email-send-class"]').text()).toBe('text.workflow.value.system')
        expect(wrapper.find('[data-testid="email-send-business"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="email-send-managed"]').exists()).toBe(true)

        await wrapper.get('[data-testid="email-send-template-select"]').setValue('spring_offer')
        expect(config(wrapper)).toEqual({ mode: 'template', recipient: 'trigger_subject', template_key: 'spring_offer' })
        const toggle = wrapper.get('[data-testid="email-send-business-switch"]')
        expect((toggle.element as HTMLInputElement).checked).toBe(false)
        expect(wrapper.get('[data-testid="email-send-business"]').text()).toContain('text.workflow.email.business_off_hint')

        await toggle.setValue(true)
        expect(config(wrapper).business_enabled).toBe(true)
        expect(wrapper.get('[data-testid="email-send-business"]').text()).toContain('text.workflow.email.business_on_hint')

        // Picking another template puts the switch back to off.
        await wrapper.get('[data-testid="email-send-template-select"]').setValue('contact_auto_reply')
        expect(config(wrapper).business_enabled).toBeUndefined()
    })

    it('offers "the person who sent it" only when the workflow starts on an event that has one', async () => {
        const wrapper = await open({ mode: 'template', recipient: 'trigger_subject', template_key: 'contact_auto_reply' })
        expect(wrapper.find('[data-testid="email-send-recipient-subject"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="email-send-subject-why"]').exists()).toBe(false)

        // Staff: a permission picked from the list, never typed.
        await wrapper.get('[data-testid="email-send-recipient-staff"]').trigger('click')
        expect(config(wrapper)).toMatchObject({ recipient: 'staff', recipient_permission: '' })
        expect(wrapper.find('[data-testid="email-send-managed"]').exists()).toBe(false)
        await wrapper.get('[data-testid="permission-picker"]').setValue('contact.viewAny')
        expect(config(wrapper).recipient_permission).toBe('contact.viewAny')
        expect(wrapper.find('[data-testid="email-send-managed"]').exists()).toBe(true)

        // A typed address: allowed, with the approval explained, and no longer inside policy B.
        await wrapper.get('[data-testid="email-send-recipient-address"]').trigger('click')
        expect(config(wrapper)).toMatchObject({ recipient: 'address', to: '' })
        expect(config(wrapper).recipient_permission).toBeUndefined()
        expect(wrapper.get('[data-testid="email-send-address-approval"]').text()).toBe('text.workflow.email.address_needs_approval')
        expect(wrapper.find('[data-testid="email-send-managed"]').exists()).toBe(false)

        // A workflow started by hand has no such person: the choice is not offered and the form says why.
        graph.nodes = ref([{ id: 'm', data: { step_type: 'trigger_manual', step_config: {} } }])
        const manual = await open({ mode: 'template', recipient: 'staff', recipient_permission: 'contact.viewAny', template_key: 'contact_auto_reply' })
        expect(manual.find('[data-testid="email-send-recipient-subject"]').exists()).toBe(false)
        expect(manual.find('[data-testid="email-send-subject-why"]').exists()).toBe(true)

        // A step that already says so keeps the choice visible, with the problem in words.
        const stale = await open({ mode: 'template', recipient: 'trigger_subject', template_key: 'contact_auto_reply' })
        expect(stale.find('[data-testid="email-send-recipient-subject"]').exists()).toBe(true)
        expect(stale.get('[data-testid="email-send-subject-unavailable"]').text()).toBe('text.workflow.issue.email_recipient_unavailable')

        // Switching such a workflow to a template starts on staff.
        const fresh = await open({ mode: 'text', to: '', subject: '', body: '' })
        await fresh.get('[data-testid="email-send-mode-template"]').trigger('click')
        expect(config(fresh).recipient).toBe('staff')
    })

    it('maps the blanks of the template and the language through the data picker', async () => {
        const wrapper = await open({ mode: 'template', recipient: 'trigger_subject', template_key: 'contact_auto_reply' })

        // Only the blanks a person fills: the website name is filled by the server.
        const rows = wrapper.get('[data-testid="email-send-variables"]').findAll('[data-variable]')
        expect(rows.map((row) => row.attributes('data-variable'))).toEqual(['display_name'])
        expect(rows[0].text()).toContain('Tên người gửi')

        await rows[0].get('[data-testid="data-token"]').setValue('{{ vars.trigger.event.payload.display_name }}')
        expect(config(wrapper).variables).toEqual({ display_name: '{{ vars.trigger.event.payload.display_name }}' })

        const language = wrapper.get('[data-testid="email-send-language-select"]')
        expect(language.findAll('option').map((option) => option.text())).toEqual(['text.workflow.email.language_default', 'Tiếng Việt', 'English', 'text.workflow.email.language_from_data'])
        await language.setValue('fixed:en')
        expect(config(wrapper).language).toBe('en')
        await language.setValue('data')
        expect(config(wrapper).language).toBe('{{ vars.trigger.event.payload.locale }}')
        await language.setValue('default')
        expect(config(wrapper).language).toBeUndefined()
    })

    it('words a failed load, an empty list and a template that is gone', async () => {
        api.fetchEmailTemplateCatalog.mockRejectedValueOnce(new Error('down'))
        const failed = await open({ mode: 'template', recipient: 'staff', template_key: 'contact_auto_reply' })
        expect(failed.get('[data-testid="email-send-template-failed"]').text()).toContain('text.workflow.email.template_failed')
        expect(failed.find('[data-testid="email-send-template-missing"]').exists()).toBe(false)

        api.fetchEmailTemplateCatalog.mockResolvedValueOnce([])
        const empty = await open({ mode: 'template', recipient: 'staff' })
        expect(empty.get('[data-testid="email-send-template-empty"]').text()).toBe('text.workflow.email.template_none')
        expect(empty.get('[data-testid="email-send-manage"]').attributes('data-to')).toBe('setting-email-templates')

        const gone = await open({ mode: 'template', recipient: 'staff', template_key: 'old_offer' })
        expect(gone.get('[data-testid="email-send-template-missing"]').text()).toBe('text.workflow.email.template_missing')
        // The saved choice stays selected under a plain name, never as its raw key.
        expect(gone.get('[data-testid="email-send-template-select"]').findAll('option')[0].text()).toBe('text.workflow.email.template_saved')

        // Without the permission to open Email templates there is no link to it.
        access.rules = new Set()
        const noLink = await open({ mode: 'template', recipient: 'staff' })
        expect(noLink.find('[data-testid="email-send-manage"]').exists()).toBe(false)
    })

    it('changes nothing on a read-only step', async () => {
        const wrapper = await open({ mode: 'template', recipient: 'trigger_subject', template_key: 'spring_offer' }, { readOnly: true })
        await wrapper.get('[data-testid="email-send-mode-text"]').trigger('click')
        await wrapper.get('[data-testid="email-send-business-switch"]').setValue(true)
        expect(config(wrapper)).toEqual({ mode: 'template', recipient: 'trigger_subject', template_key: 'spring_offer' })
    })
})
