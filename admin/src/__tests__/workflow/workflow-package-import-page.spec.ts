import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ImportPage from '@core-admin/pages/setting/workflow-package-import.vue'
import BindingField from '@core-admin/components/workflow-package/binding-field.vue'

const api = vi.hoisted(() => ({
    previewWorkflowPackage: vi.fn(),
    installWorkflowPackage: vi.fn(),
    fetchCredentials: vi.fn(),
    fetchAgentProfiles: vi.fn(),
    fetchWorkflows: vi.fn(),
}))
const access = vi.hoisted(() => ({ rules: new Set<string>() }))
const router = vi.hoisted(() => ({ push: vi.fn(), hasRoute: () => true }))

vi.mock('@core-admin/workflow-package-api', () => api)
vi.mock('@/mixins', () => ({ rule: (key: string) => access.rules.has(key) }))
vi.mock('vue-router', () => ({ useRouter: () => router }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params?: Record<string, unknown>) => (params && Object.values(params).some((v) => v !== undefined) ? `${key}${JSON.stringify(params)}` : key),
        te: () => true,
    }),
}))
vi.mock('@/components/workflow/inputs/ReviewerPicker.vue', () => ({
    default: {
        name: 'ReviewerPicker',
        props: ['modelValue', 'max', 'readOnly', 'label'],
        emits: ['update:modelValue'],
        template: '<div data-testid="reviewer-picker" :data-max="max">{{ (modelValue || []).join(",") }}</div>',
    },
}))

const stubs = {
    'no-permission-error': { template: '<div data-testid="no-permission" />' },
    'router-link': { props: ['to'], template: '<a :data-to="to.name"><slot /></a>' },
    'a-button': { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading"><slot /></button>' },
    'a-alert': { props: ['message', 'description'], template: '<div role="alert"><b>{{ message }}</b><i>{{ description }}</i><slot name="description" /><slot name="action" /></div>' },
    'a-spin': true,
    'a-tag': { template: '<span><slot /></span>' },
    'a-input': { props: ['value'], emits: ['update:value'], template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
    'a-select': {
        props: ['value', 'options'],
        emits: ['update:value'],
        template: '<select :value="value" @change="$emit(\'update:value\', $event.target.value)"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
    },
}

const USER = '1dc65f12-55d2-4d71-a8f3-616f3a1b72d6'
const pkg = {
    workflow: {
        slug: 'sync-crm',
        name: 'Sync CRM',
        description: 'Fetch, approve, log.',
        steps: [
            { step_code: 'fetch', name: 'Call the CRM', step_type: 'http_request' },
            { step_code: 'ok', name: 'Wait for approval', step_type: 'approval' },
        ],
    },
}
const report = (over: Record<string, unknown> = {}) => ({
    valid: true,
    installable: true,
    source_slug: 'sync-crm',
    target_slug: 'sync-crm',
    name: 'Sync CRM',
    manifest: { safety_classes: ['external_call', 'read'], steps: [{ step_type: 'http_request' }, { step_type: 'approval' }], outbound_hosts: ['crm.example.com'], modules: ['Core'] },
    problems: [],
    binding_problems: [],
    slug_problems: [],
    warnings: [],
    bindings: [],
    dry_run: { completed: true, steps: [{ step_code: 'fetch', step_type: 'http_request', safety: ['external_call'], mode: 'stubbed', outcome: 'would_run' }] },
    ...over,
})
const taken = { installable: false, slug_problems: [{ code: 'slug_exists', message: 'A workflow with slug sync-crm already exists here' }] }
const bindings = (userResolved = false) => [
    { name: 'crm_api', kind: 'credential', credential_type: 'http_auth', steps: ['fetch'], resolved: true },
    { name: 'user_1', kind: 'user', steps: ['ok'], resolved: userResolved },
]

const mountPage = () => mount(ImportPage, { global: { stubs } })
const find = (wrapper: ReturnType<typeof mountPage>, id: string) => wrapper.find(`[data-testid="${id}"]`)

/** Choose a file in the hidden input, as the browser would. */
async function choose(wrapper: ReturnType<typeof mountPage>, content: string = JSON.stringify(pkg), name = 'sync-crm.workflow.json') {
    const input = find(wrapper, 'package-file')
    const file = new File([content], name, { type: 'application/json' })
    if (typeof file.text !== 'function') Object.defineProperty(file, 'text', { value: async () => content })
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await flushPromises()
    return file
}

beforeEach(() => {
    vi.clearAllMocks()
    access.rules = new Set(['workflow_project.create', 'workflow_credential.manage'])
    api.fetchCredentials.mockResolvedValue([{ name: 'crm_api', type: 'http_header' }, { name: 'crm_backup', type: 'http_header' }])
    api.fetchAgentProfiles.mockResolvedValue([])
    api.fetchWorkflows.mockResolvedValue([])
})

describe('workflow package import page', () => {
    it('shows the no-permission view without the right to create workflows', () => {
        access.rules = new Set(['workflow_project.viewAny'])
        const wrapper = mountPage()
        expect(find(wrapper, 'workflow-package-not-allowed').exists()).toBe(true)
        expect(find(wrapper, 'package-pick').exists()).toBe(false)
    })

    it('checks the chosen file and enables install for a clean package', async () => {
        api.previewWorkflowPackage.mockResolvedValue(report())
        const wrapper = mountPage()
        expect(find(wrapper, 'package-install-box').exists()).toBe(false)
        const file = await choose(wrapper)

        // The file carries no sample record, so the test run is asked for with a blank one.
        expect(api.previewWorkflowPackage).toHaveBeenCalledWith(file, { bindings: {}, asSlug: '', blankSample: true })
        expect(find(wrapper, 'dry-run-blank').exists()).toBe(true)
        expect(find(wrapper, 'package-summary').text()).toContain('Sync CRM')
        expect(find(wrapper, 'package-summary').text()).toContain('Fetch, approve, log.')
        expect(find(wrapper, 'package-does').text()).toContain('crm.example.com')
        expect(find(wrapper, 'package-dry-run').text()).toContain('text.workflow.package.dry_run_safe')
        expect(find(wrapper, 'package-dry-run').text()).toContain('Call the CRM')
        expect(find(wrapper, 'package-install').attributes('disabled')).toBeUndefined()
        expect(find(wrapper, 'package-blockers').exists()).toBe(false)
    })

    it('explains a file that is not a workflow package and offers nothing to install', async () => {
        api.previewWorkflowPackage.mockRejectedValue({ response: { status: 422, data: { message: 'The file is not a JSON workflow package.' } } })
        const wrapper = mountPage()
        await choose(wrapper, 'hello', 'notes.txt')
        expect(find(wrapper, 'package-error').text()).toContain('text.workflow.package.error.not_a_package_title')
        expect(find(wrapper, 'package-error').text()).not.toContain('JSON workflow package')
        expect(find(wrapper, 'package-install').exists()).toBe(false)
    })

    it('lists package problems in plain sentences with step names and keeps install off', async () => {
        api.previewWorkflowPackage.mockResolvedValue(
            report({
                valid: false,
                installable: false,
                problems: [
                    { code: 'checksum_mismatch', message: 'The checksum does not match' },
                    { code: 'taint_needs_approval', message: 'fetch: …', step_code: 'fetch' },
                ],
            })
        )
        const wrapper = mountPage()
        await choose(wrapper)
        const text = find(wrapper, 'package-problems').text()
        expect(text).toContain('text.workflow.package.problem.checksum_mismatch')
        expect(text).toContain('Call the CRM')
        expect(text).not.toContain('The checksum does not match')
        expect(find(wrapper, 'package-bindings').exists()).toBe(false)
        expect(find(wrapper, 'package-dry-run').exists()).toBe(false)
        expect(find(wrapper, 'package-install').attributes('disabled')).toBeDefined()
        expect(find(wrapper, 'package-blockers').text()).toContain('text.workflow.package.blocker.problems')
    })

    it('installs as a copy without asking for a key, then installs under that key', async () => {
        api.previewWorkflowPackage.mockImplementation(async (_file: File, options: { asSlug?: string }) =>
            options.asSlug === 'sync-crm-copy-2' ? report({ target_slug: options.asSlug }) : report({ ...taken, target_slug: options.asSlug || 'sync-crm' })
        )
        api.installWorkflowPackage.mockResolvedValue({ slug: 'sync-crm-copy-2', name: 'Sync CRM', is_active: false })
        const wrapper = mountPage()
        const file = await choose(wrapper)

        expect(find(wrapper, 'package-slug').exists()).toBe(true)
        expect(find(wrapper, 'package-install').attributes('disabled')).toBeDefined()
        expect(find(wrapper, 'package-blockers').text()).toContain('text.workflow.package.blocker.slug')
        expect(wrapper.findAll('input').every((input) => input.attributes('type') === 'file')).toBe(true)

        await find(wrapper, 'package-copy').trigger('click')
        await flushPromises()
        expect(api.previewWorkflowPackage.mock.calls.map((call) => call[1].asSlug)).toEqual(['', 'sync-crm-copy', 'sync-crm-copy-2'])
        expect(find(wrapper, 'package-slug').exists()).toBe(false)
        expect(find(wrapper, 'package-copy-chosen').text()).toContain('Sync CRM')
        expect(find(wrapper, 'package-install').attributes('disabled')).toBeUndefined()

        await find(wrapper, 'package-install').trigger('click')
        await flushPromises()
        expect(api.installWorkflowPackage).toHaveBeenCalledWith(file, {
            bindings: {},
            asSlug: 'sync-crm-copy-2',
            asName: 'text.workflow.package.copy_name_n{"name":"Sync CRM","n":2}',
            blankSample: false,
        })
        expect(find(wrapper, 'package-installed').text()).toContain('text.workflow.package.installed_off')

        await find(wrapper, 'package-open').trigger('click')
        expect(router.push).toHaveBeenCalledWith({ name: 'workflows', query: { open: 'sync-crm-copy-2' } })
    })

    it('uses the sample record of the package when it has one', async () => {
        api.previewWorkflowPackage.mockResolvedValue(report())
        const wrapper = mountPage()
        const file = await choose(wrapper, JSON.stringify({ ...pkg, sample: { root: { name: 'Ann' } } }))
        expect(api.previewWorkflowPackage).toHaveBeenCalledWith(file, { bindings: {}, asSlug: '', blankSample: false })
        expect(find(wrapper, 'dry-run-blank').exists()).toBe(false)
    })

    it('says so when no copy key is free', async () => {
        api.previewWorkflowPackage.mockResolvedValue(report(taken))
        const wrapper = mountPage()
        await choose(wrapper)
        await find(wrapper, 'package-copy').trigger('click')
        await flushPromises()
        expect(find(wrapper, 'package-copy-failed').exists()).toBe(true)
        expect(find(wrapper, 'package-install').attributes('disabled')).toBeDefined()
    })

    it('pre-selects the credential of the same name and keeps install off until every binding is chosen', async () => {
        vi.useFakeTimers()
        api.previewWorkflowPackage.mockImplementation(async (_file: File, options: { bindings: Record<string, string> }) =>
            options.bindings.user_1 === USER
                ? report({ bindings: bindings(true) })
                : report({ installable: false, bindings: bindings(), binding_problems: [{ code: 'binding_missing', binding: 'user_1', message: 'Binding user_1 (user) needs a value on this site.' }] })
        )
        const wrapper = mountPage()
        await choose(wrapper)

        expect(api.fetchCredentials).toHaveBeenCalledTimes(1)
        expect(api.fetchWorkflows).not.toHaveBeenCalled()
        const fields = wrapper.findAllComponents(BindingField)
        expect(fields.map((field) => [field.props('row').name, field.props('row').state, field.props('row').value])).toEqual([
            ['crm_api', 'ok', 'crm_api'],
            ['user_1', 'empty', ''],
        ])
        expect(find(wrapper, 'binding-crm_api').text()).toContain('Call the CRM')
        expect(find(wrapper, 'binding-user_1').text()).toContain('Wait for approval')
        expect(find(wrapper, 'reviewer-picker').attributes('data-max')).toBe('1')
        expect(find(wrapper, 'package-install').attributes('disabled')).toBeDefined()
        expect(find(wrapper, 'package-blockers').text()).toContain('text.workflow.package.blocker.bindings{"count":1}')

        fields[1].vm.$emit('update:value', USER)
        await vi.advanceTimersByTimeAsync(400)
        await flushPromises()
        expect(api.previewWorkflowPackage).toHaveBeenLastCalledWith(expect.anything(), { bindings: { crm_api: 'crm_api', user_1: USER }, asSlug: '', blankSample: true })
        expect(find(wrapper, 'package-install').attributes('disabled')).toBeUndefined()
        vi.useRealTimers()
    })

    it('shows the new report when the site changed between the check and the install', async () => {
        api.previewWorkflowPackage.mockResolvedValue(report())
        api.installWorkflowPackage.mockRejectedValue({ response: { status: 422, data: { message: 'The workflow package cannot be installed.', data: report({ ...taken, dry_run: null }) } } })
        const wrapper = mountPage()
        await choose(wrapper)
        await find(wrapper, 'package-install').trigger('click')
        await flushPromises()
        expect(find(wrapper, 'package-install-error').text()).toContain('text.workflow.package.install_failed_changed')
        expect(find(wrapper, 'package-slug').exists()).toBe(true)
        expect(find(wrapper, 'package-installed').exists()).toBe(false)
        expect(find(wrapper, 'package-dry-run').text()).toContain('Call the CRM')
    })

    it('shows warnings in plain words without blocking the install', async () => {
        api.previewWorkflowPackage.mockResolvedValue(
            report({ warnings: [{ code: 'host_not_allowlisted', message: "Outbound host crm.example.com is not on this site's allowlist; its steps fail until a Webmaster adds it." }] })
        )
        const wrapper = mountPage()
        await choose(wrapper)
        expect(find(wrapper, 'package-warnings').text()).toContain('text.workflow.package.problem.host_not_allowlisted{"host":"crm.example.com"}')
        expect(find(wrapper, 'package-install').attributes('disabled')).toBeUndefined()
    })
})

describe('workflow package binding field', () => {
    const lists = (over = {}) => ({
        credentials: { items: [{ name: 'crm_api', type: 'http_header' }, { name: 'bot', type: 'telegram_bot' }], loading: false, failed: false },
        profiles: { items: [{ slug: 'helper', name: 'Content helper' }], loading: false, failed: false },
        workflows: { items: [{ slug: 'greeting', name: 'Greeting' }], loading: false, failed: false },
        ...over,
    })
    const row = (over = {}) => ({ name: 'crm_api', kind: 'credential', credentialType: 'http_auth', steps: ['Call the CRM'], value: '', state: 'empty', ...over })
    const mountField = (props: Record<string, unknown>) => mount(BindingField, { props: { lists: lists(), ...props } as never, global: { stubs } })

    it('lists only fitting credentials by name and emits the chosen one', async () => {
        const wrapper = mountField({ row: row() })
        expect(wrapper.findAll('option').map((o) => o.text())).toEqual(['crm_api (text.workflow_credential.type_http_header)'])
        await wrapper.find('select').setValue('crm_api')
        expect(wrapper.emitted('update:value')?.[0]).toEqual(['crm_api'])
    })

    it('says when no credential fits and links to the credentials page', async () => {
        const wrapper = mountField({ row: row({ credentialType: 'smtp' }), canManageCredentials: true })
        expect(wrapper.find('[data-testid="binding-credential-none"]').text()).toContain('text.workflow.package.binding.credential_none')
        expect(wrapper.find('[data-testid="binding-credential-link"]').attributes('data-to')).toBe('workflow-credentials')
        await wrapper.find('[data-testid="binding-reload"]').trigger('click')
        expect(wrapper.emitted('reload')?.[0]).toEqual(['credentials'])
        expect(mountField({ row: row({ credentialType: 'smtp' }) }).find('[data-testid="binding-credential-link"]').exists()).toBe(false)
    })

    it('picks an agent profile or a workflow by name, storing its key', async () => {
        const profile = mountField({ row: row({ name: 'agent_profile_1', kind: 'agent_profile' }) })
        expect(profile.findAll('option').map((o) => o.text())).toEqual(['Content helper'])
        await profile.find('select').setValue('helper')
        expect(profile.emitted('update:value')?.[0]).toEqual(['helper'])

        const workflow = mountField({ row: row({ name: 'workflow_1', kind: 'workflow' }) })
        expect(workflow.findAll('option').map((o) => o.text())).toEqual(['Greeting'])
    })

    it('picks a person through the reviewer picker, one at most', () => {
        const wrapper = mountField({ row: row({ name: 'user_1', kind: 'user', value: USER, state: 'ok' }) })
        expect(wrapper.find('[data-testid="reviewer-picker"]').text()).toBe(USER)
        wrapper.findComponent({ name: 'ReviewerPicker' }).vm.$emit('update:modelValue', [])
        expect(wrapper.emitted('update:value')?.[0]).toEqual([''])
    })

    it('asks for the record code only for a fixed record, and says when it is not a code', async () => {
        const wrapper = mountField({ row: row({ name: 'record_1', kind: 'record', value: 'abc', state: 'invalid' }) })
        expect(wrapper.text()).toContain('text.workflow.package.binding.record_hint')
        expect(wrapper.text()).toContain('text.workflow.package.binding.record_invalid')
        await wrapper.find('input').setValue(' 3f0c2a0e-1b7d-4c55-9a43-0d6a1f2b3c4d ')
        expect(wrapper.emitted('update:value')?.[0]).toEqual(['3f0c2a0e-1b7d-4c55-9a43-0d6a1f2b3c4d'])
    })
})
