import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ImportTab from '@core-admin/components/config-package/import-tab.vue'

const api = vi.hoisted(() => ({ uploadPackage: vi.fn(), fetchRun: vi.fn(), applyRun: vi.fn() }))
const tasks = vi.hoisted(() => ({ getBackgroundTasks: vi.fn() }))

vi.mock('@/libs/configPackageApi', () => api)
vi.mock('@/libs/background-tasks', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/libs/background-tasks')>()
    return { ...actual, getBackgroundTasks: tasks.getBackgroundTasks }
})
vi.mock('vue-router', () => ({ useRouter: () => ({ hasRoute: () => false }) }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params?: object) => (params ? `${key}${JSON.stringify(params)}` : key),
        te: (key: string) => !key.endsWith('section_unknown_thing'),
    }),
}))

const stubs = {
    'a-checkbox': { props: ['checked'], template: '<label><input type="checkbox" :checked="checked" /><slot /></label>' },
    'a-button': { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading"><slot /></button>' },
    'a-alert': { props: ['message', 'description'], template: '<div role="alert"><b>{{ message }}</b><i>{{ description }}</i><slot name="action" /></div>' },
    'a-empty': { props: ['description'], template: '<div>{{ description }}</div>' },
    'a-spin': true,
    'a-tag': { template: '<span><slot /></span>' },
    'a-modal': { props: ['open', 'title'], template: '<div v-if="open" role="dialog"><h5>{{ title }}</h5><slot /></div>' },
    'a-input': {
        props: ['value'],
        emits: ['update:value'],
        template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
    },
}

const counts = (o = {}) => ({ create: 0, update: 0, unchanged: 0, blocked: 0, skipped: 0, ...o })
const plan = (missing: string[] = ['MAIL_PASSWORD'], labels: Record<string, string> = {}) => ({
    data: {
        run: { uuid: 'run-1', kind: 'dry_run', status: 'succeeded' },
        plan: {
            sections: {
                settings: { status: 'changes', counts: counts({ update: 2, blocked: 1 }), total: 3, more: 0, warnings: [] },
                menus: { status: 'no_changes', counts: counts({ unchanged: 1 }), total: 1, more: 0, warnings: [] },
                tables: { status: 'no_changes', counts: counts({ blocked: 2 }), total: 2, more: 0, warnings: [] },
            },
            section: 'settings',
            items: [
                { key: 'site.name', label: 'Site name', action: 'update', reason: null, before: 'Old', after: 'New' },
                { key: 'site.slogan', label: null, action: 'update', reason: null, before: 'a', after: 'b' },
                { key: 'site.locked', label: null, action: 'blocked', reason: 'row does not exist on this site; enable the module or run seeders', before: null, after: 'v' },
            ],
            page: 1,
            per_page: 50,
            last_page: 1,
            item_total: 3,
            warnings: [],
            required_secrets: { already_set_here: [], missing_here: missing, labels },
            choices: [],
        },
    },
})
const applyRunBody = (status = 'succeeded', summary: object = {}) => ({
    data: { run: { uuid: 'apply-1', kind: 'apply', status, summary, backup_ref: 'b-1' } },
})
const okSummary = {
    backup_ref: 'b-1',
    error: null,
    sync_failed: [],
    sections: {
        settings: { status: 'succeeded', updated: 2, unchanged: 0, skipped: 0, blocked: 1 },
        menus: { status: 'succeeded', updated: 0, unchanged: 1, skipped: 0, blocked: 0 },
        tables: { status: 'skipped' },
    },
}

const find = (w: ReturnType<typeof mount>, id: string) => w.find(`[data-testid="${id}"]`)
const host = window.location.host

/** Mount the tab and run the upload and the dry run to the plan. */
const planned = async (missing: string[] = [], labels: Record<string, string> = {}) => {
    api.fetchRun.mockImplementation(async (uuid: string) => (uuid === 'apply-1' ? applyRunBody('succeeded', okSummary) : plan(missing, labels)))
    const w = mount(ImportTab, { global: { stubs } })
    const input = find(w, 'import-file')
    const file = new File(['{}'], 'p.json', { type: 'application/json' })
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await find(w, 'import-check').trigger('click')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(3100)
    await flushPromises()
    return w
}
const radio = (w: ReturnType<typeof mount>, section: string, option: string) => find(w, `import-section-choice-${section}-${option}`)
const isOn = (w: ReturnType<typeof mount>, section: string, option: string) => (radio(w, section, option).element as HTMLInputElement).checked
const openDialog = async (w: ReturnType<typeof mount>) => {
    await find(w, 'import-apply').trigger('click')
    await flushPromises()
}
const typeHost = async (w: ReturnType<typeof mount>, value: string) => {
    await find(w, 'import-apply-host').setValue(value)
}

beforeEach(() => {
    vi.useFakeTimers()
    api.uploadPackage.mockReset().mockResolvedValue({ data: { run: 'run-1', task: 'task-1', status: 'queued' } })
    api.applyRun.mockReset().mockResolvedValue({ data: { run: 'apply-1', task: 'task-2', status: 'queued' } })
    api.fetchRun.mockReset()
    tasks.getBackgroundTasks.mockReset().mockImplementation(async () => [
        { uuid: 'task-1', status: 'succeeded' },
        { uuid: 'task-2', status: 'succeeded' },
    ])
})
afterEach(() => vi.useRealTimers())

describe('config package import tab: apply step', () => {
    it('defaults to apply for sections with changes and skip where every item is blocked, and lets the operator change it', async () => {
        const w = await planned()
        expect(isOn(w, 'settings', 'apply')).toBe(true)
        expect(isOn(w, 'menus', 'apply')).toBe(true)
        expect(isOn(w, 'tables', 'skip')).toBe(true)
        await radio(w, 'settings', 'skip').setValue(true)
        expect(isOn(w, 'settings', 'skip')).toBe(true)
        expect(find(w, 'import-section-choice-settings').text()).toContain('apply_choice_summary{"changes":2,"blocked":1}')
    })

    it('shows the setting name and never the raw key when a label exists', async () => {
        const w = await planned()
        const table = find(w, 'diff-table').text()
        expect(table).toContain('Site name')
        expect(table).not.toContain('site.name')
        // No label: the key is the fallback.
        expect(table).toContain('site.slogan')
    })

    it('names missing secrets by label and falls back to the key when there is none', async () => {
        const w = await planned(['email.MAIL_PASSWORD', 'x.unknown'], { 'email.MAIL_PASSWORD': 'Mail password' })
        const text = find(w, 'import-secrets-missing').text()
        expect(text).toContain('Mail password')
        expect(text).not.toContain('email.MAIL_PASSWORD')
        expect(text).toContain('x.unknown')
    })

    it('disables Apply while secrets are missing and says why', async () => {
        const w = await planned(['MAIL_PASSWORD'])
        expect(find(w, 'import-apply').attributes('disabled')).toBeDefined()
        expect(find(w, 'import-apply-why').text()).toContain('apply_blocked_secrets')
        await find(w, 'import-apply').trigger('click')
        expect(find(w, 'import-apply-dialog').exists()).toBe(false)
    })

    it('disables Apply when nothing is chosen to apply', async () => {
        const w = await planned()
        await radio(w, 'settings', 'skip').setValue(true)
        expect(find(w, 'import-apply').attributes('disabled')).toBeDefined()
        expect(find(w, 'import-apply-why').text()).toContain('apply_blocked_nothing')
    })

    it('asks for the site host in a confirmation before anything is sent', async () => {
        const w = await planned()
        expect(find(w, 'import-apply').attributes('disabled')).toBeUndefined()
        await openDialog(w)
        const dialog = find(w, 'import-apply-dialog')
        expect(dialog.text()).toContain('apply_confirm_counts{"apply":2,"skip":0}')
        expect(dialog.text()).toContain('apply_confirm_backup')
        expect(dialog.text()).toContain(`"host":"${host}"`)
        expect(find(w, 'import-apply-confirm').attributes('disabled')).toBeDefined()
        await typeHost(w, 'not-this-site.example')
        expect(find(w, 'import-apply-confirm').attributes('disabled')).toBeDefined()
        await find(w, 'import-apply-confirm').trigger('click')
        expect(api.applyRun).not.toHaveBeenCalled()
        await typeHost(w, host)
        expect(find(w, 'import-apply-confirm').attributes('disabled')).toBeUndefined()
    })

    it('sends the dry run uuid with a skip marker for skipped sections only', async () => {
        const w = await planned()
        await radio(w, 'menus', 'skip').setValue(true)
        await openDialog(w)
        await typeHost(w, host)
        await find(w, 'import-apply-confirm').trigger('click')
        await flushPromises()
        expect(api.applyRun).toHaveBeenCalledTimes(1)
        expect(api.applyRun).toHaveBeenCalledWith('run-1', { menus: { '*': false }, tables: { '*': false } })
    })

    it('lists the item keys the operator has seen when a section is skipped', async () => {
        const w = await planned()
        await radio(w, 'menus', 'skip').setValue(true)
        await radio(w, 'tables', 'skip').setValue(true)
        // Skipping settings too would leave nothing to apply, so only the loaded section keys are checked on a skip.
        const loaded = (await import('@core-admin/config-package-diff.js')).buildApplyChoices({ settings: 'skip' }, { settings: ['site.name', 'site.slogan'] })
        expect(loaded).toEqual({ settings: { '*': false, 'site.name': false, 'site.slogan': false } })
    })

    it('follows the apply run and shows a result table per section', async () => {
        tasks.getBackgroundTasks.mockImplementation(async () => [
            { uuid: 'task-1', status: 'succeeded' },
            { uuid: 'task-2', status: 'running', message: 'text.background_tasks.step.config_apply_section', message_params: { done: 1, total: 3 } },
        ])
        const w = await planned()
        await openDialog(w)
        await typeHost(w, host)
        await find(w, 'import-apply-confirm').trigger('click')
        await flushPromises()
        await vi.advanceTimersByTimeAsync(3100)
        expect(find(w, 'import-apply-progress').text()).toContain('config_apply_section{"done":1,"total":3}')
        expect(find(w, 'import-apply-result').exists()).toBe(false)

        tasks.getBackgroundTasks.mockImplementation(async () => [{ uuid: 'task-2', status: 'succeeded' }])
        await vi.advanceTimersByTimeAsync(3100)
        await flushPromises()
        const result = find(w, 'import-apply-result')
        expect(result.exists()).toBe(true)
        expect(find(w, 'import-apply-progress').exists()).toBe(false)
        expect(find(w, 'import-apply-row-settings').text()).toContain('2')
        expect(find(w, 'import-apply-row-settings').text()).toContain('apply_section_succeeded')
        expect(find(w, 'import-apply-row-tables').text()).toContain('apply_section_skipped')
        expect(find(w, 'import-apply-blocked-hint').exists()).toBe(true)
        expect(result.text()).toContain('apply_backup_done')
        expect(find(w, 'import-apply-error').exists()).toBe(false)
        // Polling stopped: no more task reads after the run finished.
        const calls = tasks.getBackgroundTasks.mock.calls.length
        await vi.advanceTimersByTimeAsync(10000)
        expect(tasks.getBackgroundTasks.mock.calls.length).toBe(calls)
    })

    it('shows the result at once when the apply run is already finished in the response', async () => {
        api.applyRun.mockResolvedValue({ data: { run: 'apply-1', task: 'task-2', status: 'succeeded' } })
        const w = await planned()
        await openDialog(w)
        await typeHost(w, host)
        await find(w, 'import-apply-confirm').trigger('click')
        await flushPromises()
        expect(find(w, 'import-apply-result').exists()).toBe(true)
        expect(find(w, 'import-apply-progress').exists()).toBe(false)
    })

    it('shows a translated sentence when the apply run failed, never the server text', async () => {
        api.fetchRun.mockImplementation(async (uuid: string) =>
            uuid === 'apply-1'
                ? applyRunBody('failed', { error: 'The backup before import failed (disk full /var/x); nothing was changed.', sections: {} })
                : plan([]),
        )
        const w = mount(ImportTab, { global: { stubs } })
        const input = find(w, 'import-file')
        Object.defineProperty(input.element, 'files', { value: [new File(['{}'], 'p.json')], configurable: true })
        await input.trigger('change')
        await find(w, 'import-check').trigger('click')
        await flushPromises()
        await vi.advanceTimersByTimeAsync(3100)
        await flushPromises()
        await openDialog(w)
        await typeHost(w, host)
        await find(w, 'import-apply-confirm').trigger('click')
        await flushPromises()
        tasks.getBackgroundTasks.mockImplementation(async () => [{ uuid: 'task-2', status: 'failed' }])
        await vi.advanceTimersByTimeAsync(3100)
        await flushPromises()
        expect(find(w, 'import-apply-error').text()).toContain('apply_error_backup')
        expect(w.text()).not.toContain('disk full')
    })

    it('shows the refused sentence on a 409 and keeps the choices so the operator can retry', async () => {
        api.applyRun.mockRejectedValue({ response: { status: 409, data: { error_code: 'CONFIG_PACKAGE_APPLY_REFUSED', message: 'This site changed since the dry run' } } })
        const w = await planned()
        await openDialog(w)
        await typeHost(w, host)
        await find(w, 'import-apply-confirm').trigger('click')
        await flushPromises()
        expect(find(w, 'import-apply-error').text()).toContain('apply_error_refused')
        expect(w.text()).not.toContain('This site changed since')
        expect(find(w, 'import-apply-dialog').exists()).toBe(false)
        expect(find(w, 'import-apply').exists()).toBe(true)
    })

    it('shows a generic sentence for any other request error', async () => {
        api.applyRun.mockRejectedValue({ response: { status: 500, data: { message: 'SQLSTATE boom' } } })
        const w = await planned()
        await openDialog(w)
        await typeHost(w, host)
        await find(w, 'import-apply-confirm').trigger('click')
        await flushPromises()
        expect(find(w, 'import-apply-error').text()).toContain('apply_error_start')
        expect(w.text()).not.toContain('SQLSTATE')
    })

    it('stops polling the apply run when the tab is unmounted', async () => {
        tasks.getBackgroundTasks.mockImplementation(async () => [{ uuid: 'task-1', status: 'succeeded' }, { uuid: 'task-2', status: 'running' }])
        const w = await planned()
        await openDialog(w)
        await typeHost(w, host)
        await find(w, 'import-apply-confirm').trigger('click')
        await flushPromises()
        w.unmount()
        const calls = tasks.getBackgroundTasks.mock.calls.length
        await vi.advanceTimersByTimeAsync(10000)
        expect(tasks.getBackgroundTasks.mock.calls.length).toBe(calls)
    })

    it('translates validator issues from their keys and falls back to the message', async () => {
        api.uploadPackage.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    error_code: 'CONFIG_PACKAGE_INVALID',
                    data: {
                        errors: ['English one', 'English two'],
                        issues: [
                            { key: 'text.config_package.validation.field_required', params: { path: 'sections.settings.0.key' }, message: 'English one' },
                            { key: 'text.config_package.validation.unknown_section', params: { section: 'x' }, message: 'English two' },
                        ],
                    },
                },
            },
        })
        const w = mount(ImportTab, { global: { stubs } })
        const input = find(w, 'import-file')
        Object.defineProperty(input.element, 'files', { value: [new File(['{}'], 'p.json')], configurable: true })
        await input.trigger('change')
        await find(w, 'import-check').trigger('click')
        await flushPromises()
        const details = find(w, 'import-error-details').text()
        expect(details).toContain('validation.field_required{"path":"sections.settings.0.key"}')
        expect(details).not.toContain('English one')
    })
})
