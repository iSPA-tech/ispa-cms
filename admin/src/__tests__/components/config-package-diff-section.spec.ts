import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import DiffSection from '@core-admin/components/config-package/diff-section.vue'
import ImportTab from '@core-admin/components/config-package/import-tab.vue'

const api = vi.hoisted(() => ({ uploadPackage: vi.fn(), fetchRun: vi.fn() }))
const tasks = vi.hoisted(() => ({ getBackgroundTasks: vi.fn() }))
const routes = vi.hoisted(() => ({ has: true }))

vi.mock('@/libs/configPackageApi', () => api)
vi.mock('@/libs/background-tasks', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/libs/background-tasks')>()
    return { ...actual, getBackgroundTasks: tasks.getBackgroundTasks }
})
vi.mock('vue-router', () => ({ useRouter: () => ({ hasRoute: () => routes.has }) }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: object) => (params ? `${key}${JSON.stringify(params)}` : key), te: () => true }),
}))

const stubs = {
    'a-checkbox': {
        props: ['checked'],
        emits: ['update:checked'],
        template: '<label><input type="checkbox" :checked="checked" @change="$emit(\'update:checked\', $event.target.checked)" /><slot /></label>',
    },
    'a-button': { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading"><slot /></button>' },
    'a-alert': { props: ['message', 'description'], template: '<div role="alert"><b>{{ message }}</b><i>{{ description }}</i><slot name="action" /></div>' },
    'a-empty': { props: ['description'], template: '<div>{{ description }}</div>' },
    'a-spin': true,
    'a-tag': { template: '<span><slot /></span>' },
    'router-link': { props: ['to'], template: '<a data-testid="env-link"><slot /></a>' },
}

const counts = (o = {}) => ({ create: 0, update: 0, unchanged: 0, blocked: 0, skipped: 0, ...o })
const planBody = {
    data: {
        run: { uuid: 'run-1', kind: 'dry_run', status: 'succeeded' },
        plan: {
            sections: {
                settings: { status: 'changes', counts: counts({ update: 2, blocked: 1 }), total: 3, more: 7, more_text: 'and 7 more', warnings: [] },
                menus: { status: 'no_changes', counts: counts({ unchanged: 1 }), total: 1, more: 0, warnings: [] },
            },
            section: 'settings',
            items: [
                { key: 'site.name', action: 'update', reason: null, before: 'Old', after: 'New', needs_choice: false },
                { key: 'mail.password', action: 'update', reason: null, before: 'x', after: 'S3cr3t-planted', needs_choice: false },
                { key: 'site.locked', action: 'blocked', reason: 'row does not exist on this site; enable the module or run seeders', before: null, after: 'v', needs_choice: false },
                { key: 'site.same', action: 'unchanged', reason: null, before: { custom: { badge: 'success' } }, after: { custom: { badge: 'success' } }, needs_choice: false },
                { key: 'site.odd', action: 'blocked', reason: 'some brand new reason', before: null, after: null, needs_choice: false },
            ],
            page: 1,
            per_page: 50,
            last_page: 1,
            item_total: 3,
            warnings: ['settings: 1 item(s) are blocked and will not be applied.'],
            required_secrets: { already_set_here: ['MAIL_HOST'], missing_here: ['MAIL_PASSWORD'] },
            choices: [],
        },
    },
}

const find = (w: ReturnType<typeof mount>, id: string) => w.find(`[data-testid="${id}"]`)
const json = (name: string, size = 10, type = 'application/json') => {
    const f = new File(['{}'], name, { type })
    Object.defineProperty(f, 'size', { value: size })
    return f
}
const pick = async (w: ReturnType<typeof mount>, files: File[]) => {
    const input = find(w, 'import-file')
    Object.defineProperty(input.element, 'files', { value: files, configurable: true })
    await input.trigger('change')
}
const mountTab = () => mount(ImportTab, { global: { stubs } })
const started = async (w: ReturnType<typeof mount>) => {
    await pick(w, [json('p.json')])
    await find(w, 'import-check').trigger('click')
    await flushPromises()
}

beforeEach(() => {
    vi.useFakeTimers()
    routes.has = true
    api.uploadPackage.mockReset().mockResolvedValue({ data: { run: 'run-1', task: 'task-1', status: 'queued' } })
    api.fetchRun.mockReset().mockResolvedValue(planBody)
    tasks.getBackgroundTasks.mockReset().mockResolvedValue([{ uuid: 'task-1', status: 'succeeded' }])
})
afterEach(() => vi.useRealTimers())

describe('config package diff section', () => {
    const items = [
        { key: 'a', action: 'blocked', reason: 'Not allowed', before: null, after: 'v' },
        { key: 'b', action: 'create', needs_choice: true, default_choice: true, before: null, after: 'w' },
    ]
    it('shows counts, reasons, the more line and emits choices', async () => {
        const w = mount(DiffSection, {
            props: { section: 'settings', overview: { counts: counts({ blocked: 1, create: 1 }) }, items, more: 4, choices: [] },
            global: { stubs },
        })
        expect(find(w, 'diff-count-blocked').text()).toContain('1')
        expect(find(w, 'diff-reason').text()).toBe('Not allowed')
        expect(find(w, 'diff-more').text()).toContain('"count":4')
        const box = find(w, 'diff-choice').find('input')
        expect((box.element as HTMLInputElement).checked).toBe(true)
        await box.setValue(false)
        expect(w.emitted('update:choices')![0][0]).toEqual({ b: false })
    })

    it('shows an empty state and no more line', () => {
        const w = mount(DiffSection, { props: { section: 'menus', items: [], more: 0 }, global: { stubs } })
        expect(find(w, 'diff-empty').exists()).toBe(true)
        expect(find(w, 'diff-more').exists()).toBe(false)
    })
})

describe('config package import tab', () => {
    it('accepts one .json file and refuses others before upload', async () => {
        const w = mountTab()
        await pick(w, [json('p.txt', 10, 'text/plain')])
        expect(find(w, 'import-error').text()).toContain('import_error_type')
        expect(find(w, 'import-check').attributes('disabled')).toBeDefined()
        await pick(w, [json('p.json', 21 * 1024 * 1024)])
        expect(find(w, 'import-error').text()).toContain('import_error_size')
        await pick(w, [json('a.json'), json('b.json')])
        expect(find(w, 'import-error').text()).toContain('import_error_too_many')
        await pick(w, [json('ok.json')])
        expect(find(w, 'import-error').exists()).toBe(false)
        expect(find(w, 'import-check').attributes('disabled')).toBeUndefined()
        expect(api.uploadPackage).not.toHaveBeenCalled()
    })

    it('shows a plain message plus validator details for a 422', async () => {
        api.uploadPackage.mockRejectedValue({
            response: { status: 422, data: { message: 'x', data: { errors: ['sections.settings.items.0: bad'] }, error_code: 'CONFIG_PACKAGE_INVALID', status: 'error' } },
        })
        const w = mountTab()
        await started(w)
        expect(find(w, 'import-error').text()).toContain('import_error_invalid')
        expect(find(w, 'import-error-details').text()).toContain('sections.settings.items.0: bad')
    })

    it('shows a not-JSON message and a generic one for form-request errors', async () => {
        api.uploadPackage.mockRejectedValueOnce({
            response: { status: 422, data: { message: 'x', error_code: 'CONFIG_PACKAGE_INVALID_JSON', status: 'error' } },
        })
        const w = mountTab()
        await started(w)
        expect(find(w, 'import-error').text()).toContain('import_error_json')
        api.uploadPackage.mockRejectedValueOnce({ response: { status: 422, data: { errors: { file: ['x'] } } } })
        await find(w, 'import-retry').trigger('click')
        await flushPromises()
        expect(find(w, 'import-error').text()).toContain('import_error_file')
    })

    it('shows progress while the dry run runs, then the summary per section', async () => {
        tasks.getBackgroundTasks.mockResolvedValue([{ uuid: 'task-1', status: 'running' }])
        const w = mountTab()
        await started(w)
        expect(find(w, 'import-progress').text()).toContain('import_status_queued')
        await vi.advanceTimersByTimeAsync(3100)
        expect(find(w, 'import-progress').text()).toContain('import_status_running')
        expect(find(w, 'import-summary').exists()).toBe(false)
        tasks.getBackgroundTasks.mockResolvedValue([{ uuid: 'task-1', status: 'succeeded' }])
        await vi.advanceTimersByTimeAsync(3100)
        await flushPromises()
        expect(find(w, 'import-progress').exists()).toBe(false)
        expect(find(w, 'import-summary-settings').text()).toContain('section_status_changes')
        expect(find(w, 'import-summary-menus').text()).toContain('section_status_no_changes')
        expect(find(w, 'import-warnings').text()).toContain('text.config_package.warning_blocked{"section":"text.config_package.section_settings","count":1}')
        expect(find(w, 'import-warnings').text()).not.toContain('item(s)')
    })

    describe('after the check', () => {
        const done = async () => {
            const w = mountTab()
            await started(w)
            await vi.advanceTimersByTimeAsync(3100)
            await flushPromises()
            return w
        }

        it('shows blocked reasons, the more line and never the planted secret', async () => {
            const w = await done()
            const reasons = w.findAll('[data-testid="diff-reason"]').map((r) => r.text())
            expect(reasons).toContain('text.config_package.reason_missing_row{}')
            expect(reasons).toContain('some brand new reason')
            expect(w.text()).not.toContain('enable the module')
            expect(find(w, 'diff-same').text()).toContain('import_same_as_now')
            expect(w.text()).not.toContain('badge')
            expect(find(w, 'diff-more').text()).toContain('"count":7')
            expect(w.text()).not.toContain('S3cr3t-planted')
            expect(w.html()).not.toContain('S3cr3t-planted')
        })

        it('loads another section through the API', async () => {
            const w = await done()
            await find(w, 'import-section-menus').trigger('click')
            await flushPromises()
            expect(api.fetchRun).toHaveBeenLastCalledWith('run-1', { page: 1, section: 'menus' })
        })

        it('lists missing secrets with a link to the settings screens when that route exists', async () => {
            const w = await done()
            expect(find(w, 'import-secrets-set').text()).toContain('MAIL_HOST')
            expect(find(w, 'import-secrets-missing').text()).toContain('MAIL_PASSWORD')
            expect(find(w, 'import-secrets-link').exists()).toBe(true)
        })

        it('uses plain text when the settings route is missing', async () => {
            routes.has = false
            const w = await done()
            expect(find(w, 'import-secrets-missing').text()).toContain('MAIL_PASSWORD')
            expect(find(w, 'import-secrets-link').exists()).toBe(false)
            expect(find(w, 'import-secrets-plain').exists()).toBe(true)
        })
    })

    it('stops polling after unmount', async () => {
        tasks.getBackgroundTasks.mockResolvedValue([{ uuid: 'task-1', status: 'running' }])
        const w = mountTab()
        await started(w)
        await vi.advanceTimersByTimeAsync(3100)
        const calls = tasks.getBackgroundTasks.mock.calls.length
        w.unmount()
        await vi.advanceTimersByTimeAsync(30000)
        expect(tasks.getBackgroundTasks.mock.calls.length).toBe(calls)
        expect(vi.getTimerCount()).toBe(0)
    })
})
