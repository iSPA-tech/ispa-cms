import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ fetchEmailTemplates: vi.fn(), fetchEmailTemplate: vi.fn(), saveEmailTemplate: vi.fn(), previewEmailTemplate: vi.fn() }))
const access = vi.hoisted(() => ({ rules: new Set<string>(), webmaster: false }))

vi.mock('@core-admin/email-template-api', async (original) => ({ ...(await original<Record<string, unknown>>()), ...api }))
vi.mock('@/ajax', () => ({ default: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }))
vi.mock('@/mixins', () => ({ rule: (key: string) => access.rules.has(key) }))
vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => access.webmaster }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params?: Record<string, unknown>) => (params ? `${key}${JSON.stringify(params)}` : key),
        te: (key: string) => !key.endsWith('.group.newsletter'),
    }),
}))
// The shared form is covered by its own spec; here it is a stand-in that shows what the page passes and edits a copy.
vi.mock('@/components/email-template/EmailTemplateForm.vue', () => ({
    default: {
        name: 'EmailTemplateForm',
        props: ['modelValue', 'formId', 'mailClassOptions', 'mailClassHelp', 'layoutOptions', 'variableList', 'showTechnicalFields', 'showPreview'],
        emits: ['update:modelValue', 'submit'],
        template: `<form :id="formId" data-testid="shared-form" :data-technical="String(showTechnicalFields)" :data-preview="String(showPreview)" @submit.prevent="$emit('submit', modelValue)">
            <input data-testid="f-name" :value="modelValue.name" @input="$emit('update:modelValue', { ...modelValue, name: $event.target.value })" />
            <textarea data-testid="f-content" :value="modelValue.content" @input="$emit('update:modelValue', { ...modelValue, content: $event.target.value })" />
            <button v-for="o in mailClassOptions" :key="o.value" type="button" :data-class="o.value" :data-on="String(modelValue.mail_class === o.value)" :data-disabled="String(!!o.disabled)" @click="$emit('update:modelValue', { ...modelValue, mail_class: o.value })">{{ o.label }} {{ o.hint }}</button>
            <span data-testid="f-class-help">{{ mailClassHelp }}</span>
            <select v-if="layoutOptions" data-testid="f-layout" :value="modelValue.blade_layout" @change="$emit('update:modelValue', { ...modelValue, blade_layout: $event.target.value })"><option v-for="o in layoutOptions" :key="o.value" :value="o.value">{{ o.label }}</option></select>
            <span v-for="v in variableList" :key="v.key" :data-variable="v.key" />
        </form>`,
    },
}))

import Page from '@core-admin/pages/setting/email-templates.vue'
import { changedFields, groupTemplates, humanizeGroup, refusal, sampleValues } from '@core-admin/email-template-api'

const stubs = {
    'i-button': { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading" v-bind="$attrs"><slot /></button>' },
    'a-alert': { props: ['message'], template: '<div role="alert"><b>{{ message }}</b><slot name="action" /></div>' },
    'a-skeleton': { template: '<div data-testid="skeleton" />' },
    'a-tag': { template: '<span><slot /></span>' },
    'a-tooltip': { template: '<span><slot /></span>' },
    'a-pagination': true,
    'a-input': { props: ['value'], emits: ['update:value'], template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
    'a-select': {
        props: ['value', 'options'],
        emits: ['update:value', 'change'],
        template: '<select :value="value" @change="$emit(\'update:value\', $event.target.value); $emit(\'change\', $event.target.value)"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
    },
    'a-drawer': { props: ['open', 'title'], template: '<aside v-if="open" data-testid="drawer"><h2>{{ title }}</h2><slot /><footer><slot name="footer" /></footer></aside>' },
}

const vi_ = { id: 1, name: 'Tiếng Việt', code: 'vi' }
const en = { id: 2, name: 'English', code: 'en' }
const rows = [
    { id: 11, group: 'workflow', key_name: 'contact_auto_reply', name: '{{ site_name }} đã nhận được tin nhắn của bạn', mail_class: 'system', language_id: 1, language: vi_ },
    { id: 12, group: 'workflow', key_name: 'contact_auto_reply', name: '{{ site_name }} has received your message', mail_class: 'system', language_id: 2, language: en },
    { id: 13, group: 'workflow', key_name: 'spring_offer', name: 'Ưu đãi mùa xuân', mail_class: 'business', language_id: 1, language: vi_ },
    { id: 21, group: 'order', key_name: 'order_created', name: 'Đơn hàng mới', mail_class: 'business', language_id: 1, language: vi_ },
]
const detail = (over: Record<string, unknown> = {}) => ({
    ...rows[0],
    content: '<p>Chào {{ display_name }}</p>',
    blade_layout: null,
    variables: { display_name: 'Tên người gửi' },
    variable_list: [
        { key: 'display_name', description: 'Tên người gửi', sample: 'Lan Nguyễn', declared: true, builtin: false },
        { key: 'site_name', description: 'Tên trang', sample: 'iSPA', declared: true, builtin: true },
    ],
    ...over,
})

const open = async () => {
    const wrapper = mount(Page, { global: { stubs } })
    await flushPromises()
    return wrapper
}
const openRow = async (wrapper: Awaited<ReturnType<typeof open>>, id: number) => {
    await wrapper.get(`[data-testid="et-open-${id}"]`).trigger('click')
    await flushPromises()
}

beforeEach(() => {
    Object.values(api).forEach((mock) => mock.mockReset())
    access.rules = new Set(['email_template.viewAny', 'email_template.update'])
    access.webmaster = false
    api.fetchEmailTemplates.mockResolvedValue({ rows, total: rows.length, page: 1 })
    api.fetchEmailTemplate.mockResolvedValue(detail())
    api.saveEmailTemplate.mockResolvedValue({ status: 'success' })
    api.previewEmailTemplate.mockResolvedValue({ subject: 'iSPA đã nhận được tin nhắn của bạn', body: '<p>Chào Lan</p>', html: '<html><body><p>Chào Lan</p></body></html>', variables: [] })
})

describe('email template rules', () => {
    it('groups the list by group then language, workflow mails first', () => {
        const sections = groupTemplates([...rows, { id: 31, group: 'workflow', key_name: 'x', name: 'A', mail_class: 'system', language_id: null, language: null }])
        expect(sections.map((section) => [section.group, section.count])).toEqual([['workflow', 4], ['order', 1]])
        expect(sections[0].languages.map((language) => [language.name, language.rows.map((row) => row.id)])).toEqual([['Tiếng Việt', [11, 13]], ['English', [12]], [null, [31]]])
        expect(humanizeGroup('order_status')).toBe('Order status')
    })

    it('sends only what changed, and reads a refusal', () => {
        const original = { name: 'A', content: 'B', mail_class: 'system', blade_layout: '' }
        expect(changedFields(original, { ...original })).toEqual({})
        expect(changedFields(original, { ...original, mail_class: 'business', blade_layout: 'mail.branded' })).toEqual({ mail_class: 'business' })
        expect(changedFields(original, { ...original, blade_layout: 'mail.branded' }, { withLayout: true })).toEqual({ blade_layout: 'mail.branded' })
        expect(sampleValues(detail().variable_list as any)).toEqual({ display_name: 'Lan Nguyễn' })
        expect(refusal({ response: { status: 422, data: { errors: { blade_layout: ['bad'] } } } }).code).toBe('blade_layout')
        expect(refusal({ response: { status: 422, data: { errors: { mail_class: ['bad'] } } } }).code).toBe('mail_class')
        expect(refusal({ response: { status: 403, data: {} } }).code).toBe('forbidden')
        expect(refusal({ response: { status: 400, data: { message: 'Không có quyền' } } })).toMatchObject({ code: '', message: 'Không có quyền' })
    })
})

describe('Email templates page', () => {
    it('lists the templates by group and language with subject, class, language and code', async () => {
        const wrapper = await open()

        expect(api.fetchEmailTemplates).toHaveBeenCalledWith({ group: '', mailClass: '', page: 1 })
        const sections = wrapper.findAll('[data-group]')
        expect(sections.map((section) => section.attributes('data-group'))).toEqual(['workflow', 'order'])
        expect(sections[0].text()).toContain('text.email_template.group.workflow')
        expect(sections[0].text()).toContain('Tiếng Việt')
        expect(sections[0].text()).toContain('English')
        const first = sections[0].findAll('[data-testid="et-row"]')
        expect(first).toHaveLength(3)
        expect(first[0].get('[data-testid="et-row-class"]').text()).toBe('text.email_template.class.system')
        expect(first[1].text()).toContain('Ưu đãi mùa xuân')
        expect(first[1].get('[data-testid="et-row-class"]').text()).toBe('text.email_template.class.business')
        expect(first[1].text()).toContain('spring_offer')
        // Each class is explained once above the list.
        expect(wrapper.get('[data-testid="et-class-legend"]').text()).toContain('text.email_template.class.system_hint')
        expect(wrapper.get('[data-testid="et-class-legend"]').text()).toContain('text.email_template.class.business_hint')
    })

    it('filters by group and by class on the server', async () => {
        const wrapper = await open()
        // The groups offered are the ones the site has, named in words (an unknown one by its humanized name).
        expect(wrapper.get('[data-testid="et-filter-group"]').findAll('option').map((option) => option.text())).toEqual(['text.email_template.filter.group_all', 'text.email_template.group.order', 'text.email_template.group.workflow'])

        api.fetchEmailTemplates.mockResolvedValueOnce({ rows: [rows[2]], total: 1, page: 1 })
        await wrapper.get('[data-testid="et-filter-class"]').setValue('business')
        await flushPromises()
        expect(api.fetchEmailTemplates).toHaveBeenLastCalledWith({ group: '', mailClass: 'business', page: 1 })
        expect(wrapper.findAll('[data-testid="et-row"]')).toHaveLength(1)

        api.fetchEmailTemplates.mockResolvedValueOnce({ rows: [], total: 0, page: 1 })
        await wrapper.get('[data-testid="et-filter-group"]').setValue('order')
        await flushPromises()
        expect(api.fetchEmailTemplates).toHaveBeenLastCalledWith({ group: 'order', mailClass: 'business', page: 1 })
        expect(wrapper.get('[data-testid="et-empty"]').text()).toBe('text.email_template.empty_filtered')
        // A filtered list can still be widened: the groups seen before stay in the filter.
        expect(wrapper.get('[data-testid="et-filter-group"]').findAll('option')).toHaveLength(3)
    })

    it('shows loading, empty and a failed load with a retry', async () => {
        api.fetchEmailTemplates.mockResolvedValueOnce({ rows: [], total: 0, page: 1 })
        const empty = await open()
        expect(empty.get('[data-testid="et-empty"]').text()).toBe('text.email_template.empty')

        api.fetchEmailTemplates.mockRejectedValueOnce(new Error('down'))
        const failed = await open()
        expect(failed.get('[data-testid="et-load-error"]').text()).toContain('text.email_template.load_failed')
        await failed.get('[data-testid="et-load-error"] button').trigger('click')
        await flushPromises()
        expect(failed.findAll('[data-testid="et-row"]')).toHaveLength(4)

        let release: (value: unknown) => void = () => {}
        api.fetchEmailTemplates.mockReturnValueOnce(new Promise((resolve) => (release = resolve)))
        const loading = mount(Page, { global: { stubs } })
        await loading.vm.$nextTick()
        expect(loading.find('[data-testid="skeleton"]').exists()).toBe(true)
        release({ rows, total: 4, page: 1 })
        await flushPromises()
        expect(loading.find('[data-testid="skeleton"]').exists()).toBe(false)
    })

    it('changes the class of a template and saves only that', async () => {
        const wrapper = await open()
        await openRow(wrapper, 11)

        expect(api.fetchEmailTemplate).toHaveBeenCalledWith(11)
        const form = wrapper.get('[data-testid="shared-form"]')
        // Operator form: no key, no free layout, no raw variable editor; the page has its own server preview.
        expect(form.attributes('data-technical')).toBe('false')
        expect(form.attributes('data-preview')).toBe('false')
        expect(form.find('[data-testid="f-layout"]').exists()).toBe(false)
        expect(wrapper.get('[data-testid="et-layout-note"]').text()).toBe('text.email_template.layout.workflow_note')
        expect(form.findAll('[data-variable]').map((node) => node.attributes('data-variable'))).toEqual(['display_name', 'site_name'])
        expect(form.get('[data-class="system"]').attributes('data-on')).toBe('true')
        expect(form.get('[data-class="business"]').text()).toContain('text.email_template.class.business_hint')
        // Nothing changed yet: nothing to save.
        expect(wrapper.get('[data-testid="et-save"]').attributes('disabled')).toBeDefined()

        await form.get('[data-class="business"]').trigger('click')
        expect(wrapper.get('[data-testid="f-class-help"]').text()).toBe('text.email_template.class.change_warning')
        expect(wrapper.get('[data-testid="et-save"]').attributes('disabled')).toBeUndefined()

        api.fetchEmailTemplates.mockClear()
        await form.trigger('submit')
        await flushPromises()

        expect(api.saveEmailTemplate).toHaveBeenCalledTimes(1)
        expect(api.saveEmailTemplate).toHaveBeenCalledWith(11, { mail_class: 'business' })
        expect(wrapper.find('[data-testid="drawer"]').exists()).toBe(false)
        expect(wrapper.get('[data-testid="et-notice"]').text()).toContain('text.email_template.saved')
        expect(api.fetchEmailTemplates).toHaveBeenCalledTimes(1)
    })

    it('offers only the allowed mail frames outside workflow mails, and words a refusal', async () => {
        api.fetchEmailTemplate.mockResolvedValueOnce(detail({ ...rows[3], blade_layout: 'emails.legacy', variable_list: [] }))
        const wrapper = await open()
        await openRow(wrapper, 21)

        const layout = wrapper.get('[data-testid="f-layout"]')
        expect(layout.findAll('option').map((option) => (option.element as HTMLOptionElement).value)).toEqual(['', 'mail.branded', 'mail.order.layout', 'journal::mail.layout', 'emails.legacy'])
        expect(layout.findAll('option')[4].text()).toBe('text.email_template.layout.other')
        expect(wrapper.find('[data-testid="et-layout-note"]').exists()).toBe(false)

        await wrapper.get('[data-testid="f-name"]').setValue('Đơn hàng mới của bạn')
        api.saveEmailTemplate.mockRejectedValueOnce({ response: { status: 422, data: { errors: { blade_layout: ['The selected blade layout is invalid.'] } } } })
        await wrapper.get('[data-testid="shared-form"]').trigger('submit')
        await flushPromises()

        expect(api.saveEmailTemplate).toHaveBeenCalledWith(21, { name: 'Đơn hàng mới của bạn' })
        // The refusal is shown in the screen's words, the drawer stays open and the button works again.
        expect(wrapper.get('[data-testid="et-save-error"]').text()).toBe('text.email_template.refused.layout')
        expect(wrapper.find('[data-testid="drawer"]').exists()).toBe(true)
        expect(wrapper.get('[data-testid="et-save"]').attributes('disabled')).toBeUndefined()
    })

    it('previews the unsaved text with sample data inside a sandboxed frame', async () => {
        const wrapper = await open()
        await openRow(wrapper, 11)

        await wrapper.get('[data-testid="f-name"]').setValue('{{ site_name }} cảm ơn bạn')
        // One sample input per blank a person fills (the website name is filled by the server).
        const samples = wrapper.get('[data-testid="et-preview"]').findAll('input')
        expect(samples).toHaveLength(1)
        expect((samples[0].element as HTMLInputElement).value).toBe('Lan Nguyễn')
        await samples[0].setValue('Minh')

        await wrapper.get('[data-testid="et-preview-run"]').trigger('click')
        await flushPromises()

        expect(api.previewEmailTemplate).toHaveBeenCalledWith(11, { name: '{{ site_name }} cảm ơn bạn', content: '<p>Chào {{ display_name }}</p>', sample_data: { display_name: 'Minh' } })
        expect(api.saveEmailTemplate).not.toHaveBeenCalled()
        expect(wrapper.get('[data-testid="et-preview-subject"]').text()).toBe('iSPA đã nhận được tin nhắn của bạn')
        const frame = wrapper.get('[data-testid="et-preview-frame"]')
        // Server HTML never reaches the page itself: an empty sandbox (no scripts, no forms, no same-origin).
        expect(frame.attributes('sandbox')).toBe('')
        expect(frame.attributes('srcdoc')).toBe('<html><body><p>Chào Lan</p></body></html>')
        expect(wrapper.findAll('p').some((node) => node.text() === 'Chào Lan')).toBe(false)

        api.previewEmailTemplate.mockRejectedValueOnce({ response: { status: 500, data: {} } })
        await wrapper.get('[data-testid="et-preview-run"]').trigger('click')
        await flushPromises()
        expect(wrapper.get('[data-testid="et-preview-error"]').text()).toBe('text.email_template.preview.failed')
        expect(wrapper.find('[data-testid="et-preview-frame"]').exists()).toBe(false)
    })

    it('lets a viewer read and preview but not save, and words a template that cannot be opened', async () => {
        access.rules = new Set(['email_template.viewAny'])
        const wrapper = await open()
        expect(wrapper.get('[data-testid="et-open-11"]').text()).toBe('text.email_template.view')
        await openRow(wrapper, 11)
        expect(wrapper.find('[data-testid="et-save"]').exists()).toBe(false)
        expect(wrapper.get('[data-testid="drawer"]').text()).toContain('text.email_template.view_only')
        await wrapper.get('[data-testid="shared-form"]').trigger('submit')
        expect(api.saveEmailTemplate).not.toHaveBeenCalled()

        access.rules = new Set(['email_template.viewAny', 'email_template.update'])
        api.fetchEmailTemplate.mockRejectedValueOnce(new Error('gone'))
        const failed = await open()
        await openRow(failed, 12)
        expect(failed.get('[data-testid="et-detail-error"]').text()).toContain('text.email_template.detail_failed')
    })
})

describe('system mail class (plan §7 item 21b)', () => {
    it('offers system mail only to a Webmaster when the template is not system yet', async () => {
        api.fetchEmailTemplate.mockResolvedValue(detail({ ...rows[2] }))
        const wrapper = await open()
        await openRow(wrapper, 13)
        const system = wrapper.get('[data-testid="shared-form"]').get('[data-class="system"]')
        expect(system.attributes('data-disabled')).toBe('true')
        expect(system.text()).toContain('text.email_template.class.system_webmaster_only')

        access.webmaster = true
        const again = await open()
        await openRow(again, 13)
        expect(again.get('[data-testid="shared-form"]').get('[data-class="system"]').attributes('data-disabled')).toBe('false')
    })

    it('keeps a system template editable for an editor who is not a Webmaster', async () => {
        const wrapper = await open()
        await openRow(wrapper, 11)
        expect(wrapper.get('[data-testid="shared-form"]').get('[data-class="system"]').attributes('data-disabled')).toBe('false')
    })

    it('words the Webmaster-only refusal', () => {
        const error = { response: { status: 403, data: { error_code: 'mail_class_system_webmaster_only', message: 'Only a Webmaster can make a template system mail' } } }
        expect(refusal(error).code).toBe('system_webmaster_only')
        expect(refusal({ response: { status: 403, data: {} } }).code).toBe('forbidden')
    })
})
