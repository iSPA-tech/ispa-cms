import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ExportTab from '@core-admin/components/config-package/export-tab.vue'

const api = vi.hoisted(() => ({
    fetchSections: vi.fn(),
    startExport: vi.fn(),
    fetchRun: vi.fn(),
    downloadUrl: (uuid: string) => `config-packages/${uuid}/download`,
}))
const tasks = vi.hoisted(() => ({ getBackgroundTasks: vi.fn() }))
const ajaxMock = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@/libs/configPackageApi', () => api)
vi.mock('@/libs/background-tasks', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/libs/background-tasks')>()
    return { ...actual, getBackgroundTasks: tasks.getBackgroundTasks }
})
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: object) => (params ? `${key}${JSON.stringify(params)}` : key), te: (key: string) => !key.endsWith('section_unknown_thing') }),
}))

const stubs = {
    'a-checkbox': {
        props: ['checked'],
        emits: ['update:checked'],
        template:
            '<label><input type="checkbox" :checked="checked" @change="$emit(\'update:checked\', $event.target.checked)" /><slot /></label>',
    },
    'a-select': { props: ['value'], template: '<select data-testid="scope"><slot /></select>' },
    'a-select-option': { template: '<option><slot /></option>' },
    'a-button': { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading"><slot /></button>' },
    'a-alert': { props: ['message', 'description'], template: '<div role="alert"><b>{{ message }}</b><i>{{ description }}</i><slot name="action" /></div>' },
    'a-empty': { props: ['description'], template: '<div>{{ description }}</div>' },
    'a-spin': true,
    'a-tag': { template: '<span><slot /></span>' },
}

const sectionsBody = {
    sections: [
        { key: 'settings', count: 12, patterns: ['site.*'] },
        { key: 'translations', count: 40, counts_by_scope: { web: 40 } },
        { key: 'unknown_thing', count: 2, tables: [{ table: 'secret_tbl', count: 2 }] },
    ],
    translation_scopes: ['web', 'adm', 'app', 'all'],
}

const mountTab = async () => {
    const wrapper = mount(ExportTab, { global: { stubs } })
    await flushPromises()
    return wrapper
}
const find = (w: ReturnType<typeof mount>, id: string) => w.find(`[data-testid="${id}"]`)
const check = async (w: ReturnType<typeof mount>, index: number) => {
    await w.findAll('input[type="checkbox"]')[index].setValue(true)
}

beforeEach(() => {
    vi.useFakeTimers()
    api.fetchSections.mockReset().mockResolvedValue({ success: true, data: sectionsBody })
    api.startExport.mockReset().mockResolvedValue({ success: true, data: { run: 'run-1', task: 'task-1', status: 'queued' } })
    api.fetchRun.mockReset().mockResolvedValue({ success: true, data: { run: { uuid: 'run-1', status: 'succeeded' } } })
    tasks.getBackgroundTasks.mockReset().mockResolvedValue([{ uuid: 'task-1', status: 'running' }])
    ajaxMock.get.mockReset()
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
})
afterEach(() => vi.useRealTimers())

describe('config package export tab', () => {
    it('lists sections from the API with counts and never shows table names or ids', async () => {
        const wrapper = await mountTab()
        const text = wrapper.text()
        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(3)
        expect(text).toContain('text.config_package.section_settings')
        expect(text).toContain('"count":12')
        expect(text).toContain('"count":40')
        expect(text).toContain('Unknown thing')
        expect(text).not.toContain('secret_tbl')
        expect(text).not.toContain('site.*')
    })

    it('shows a loading state, then an error with retry that reloads', async () => {
        api.fetchSections.mockRejectedValueOnce(new Error('x'))
        const wrapper = await mountTab()
        expect(find(wrapper, 'export-load-error').exists()).toBe(true)
        await find(wrapper, 'export-retry').trigger('click')
        await flushPromises()
        expect(api.fetchSections).toHaveBeenCalledTimes(2)
        expect(find(wrapper, 'export-load-error').exists()).toBe(false)
        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(3)
    })

    it('always shows the left-out notice, even while loading or failed', async () => {
        api.fetchSections.mockRejectedValueOnce(new Error('x'))
        const wrapper = await mountTab()
        expect(find(wrapper, 'export-left-out').text()).toContain('text.config_package.left_out_text')
        await find(wrapper, 'export-retry').trigger('click')
        await flushPromises()
        expect(find(wrapper, 'export-left-out').exists()).toBe(true)
    })

    it('disables Export and shows a hint while nothing is selected', async () => {
        const wrapper = await mountTab()
        expect(find(wrapper, 'export-start').attributes('disabled')).toBeDefined()
        expect(find(wrapper, 'export-empty').exists()).toBe(true)
        await check(wrapper, 0)
        expect(find(wrapper, 'export-start').attributes('disabled')).toBeUndefined()
        expect(find(wrapper, 'export-empty').exists()).toBe(false)
    })

    it('sends one request on a double click and the chosen sections and scope', async () => {
        let resolve: (v: unknown) => void = () => {}
        api.startExport.mockReturnValue(new Promise((r) => (resolve = r)))
        const wrapper = await mountTab()
        await check(wrapper, 0)
        await find(wrapper, 'export-start').trigger('click')
        await find(wrapper, 'export-start').trigger('click')
        expect(api.startExport).toHaveBeenCalledTimes(1)
        expect(api.startExport).toHaveBeenCalledWith({ sections: ['settings'], translation_scope: 'web' })
        expect(find(wrapper, 'export-start').attributes('disabled')).toBeDefined()
        resolve({ success: true, data: { run: 'run-1', task: 'task-1', status: 'queued' } })
        await flushPromises()
        expect(find(wrapper, 'export-run').exists()).toBe(true)
    })

    it('shows the run status, then a Download action once the task succeeded', async () => {
        const wrapper = await mountTab()
        await check(wrapper, 0)
        await find(wrapper, 'export-start').trigger('click')
        await flushPromises()
        expect(find(wrapper, 'export-run').text()).toContain('text.config_package.status_queued')
        expect(find(wrapper, 'export-download').exists()).toBe(false)
        await vi.advanceTimersByTimeAsync(3100)
        expect(find(wrapper, 'export-run').text()).toContain('text.config_package.status_running')
        tasks.getBackgroundTasks.mockResolvedValue([{ uuid: 'task-1', status: 'succeeded' }])
        await vi.advanceTimersByTimeAsync(3100)
        expect(find(wrapper, 'export-download').exists()).toBe(true)
        expect(find(wrapper, 'export-start').attributes('disabled')).toBeUndefined()
    })

    it('downloads a blob through ajax and saves it with the Content-Disposition filename', async () => {
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
        ajaxMock.get.mockResolvedValue({
            data: new Blob(['{}']),
            headers: { 'content-disposition': 'attachment; filename="config-package-x.json"' },
        })
        tasks.getBackgroundTasks.mockResolvedValue([{ uuid: 'task-1', status: 'succeeded' }])
        const wrapper = await mountTab()
        await check(wrapper, 0)
        await find(wrapper, 'export-start').trigger('click')
        await flushPromises()
        await vi.advanceTimersByTimeAsync(3100)
        const names: string[] = []
        const orig = document.createElement.bind(document)
        vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
            const el = orig(tag)
            if (tag === 'a') Object.defineProperty(el, 'download', { set: (v) => names.push(v), get: () => '' })
            return el
        })
        await find(wrapper, 'export-download').trigger('click')
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenCalledWith('config-packages/run-1/download', expect.objectContaining({ responseType: 'blob', silent: true }))
        expect(click).toHaveBeenCalled()
        expect(names).toEqual(['config-package-x.json'])
        vi.restoreAllMocks()
    })

    it('shows what went wrong and what to do when the export cannot start, and allows a retry', async () => {
        api.startExport.mockRejectedValueOnce(new Error('x'))
        const wrapper = await mountTab()
        await check(wrapper, 0)
        await find(wrapper, 'export-start').trigger('click')
        await flushPromises()
        expect(find(wrapper, 'export-error').text()).toContain('text.config_package.export_error_start')
        expect(find(wrapper, 'export-start').attributes('disabled')).toBeUndefined()
    })

    it('shows an error when the download fails', async () => {
        ajaxMock.get.mockRejectedValue(new Error('x'))
        tasks.getBackgroundTasks.mockResolvedValue([{ uuid: 'task-1', status: 'succeeded' }])
        const wrapper = await mountTab()
        await check(wrapper, 0)
        await find(wrapper, 'export-start').trigger('click')
        await flushPromises()
        await vi.advanceTimersByTimeAsync(3100)
        await find(wrapper, 'export-download').trigger('click')
        await flushPromises()
        expect(find(wrapper, 'export-error').text()).toContain('text.config_package.export_error_download')
    })

    it('shows the failed state with guidance when the task failed', async () => {
        tasks.getBackgroundTasks.mockResolvedValue([{ uuid: 'task-1', status: 'failed' }])
        const wrapper = await mountTab()
        await check(wrapper, 0)
        await find(wrapper, 'export-start').trigger('click')
        await flushPromises()
        await vi.advanceTimersByTimeAsync(3100)
        expect(find(wrapper, 'export-error').text()).toContain('text.config_package.export_error_failed')
        expect(find(wrapper, 'export-download').exists()).toBe(false)
    })

    it('stops polling after unmount', async () => {
        const wrapper = await mountTab()
        await check(wrapper, 0)
        await find(wrapper, 'export-start').trigger('click')
        await flushPromises()
        await vi.advanceTimersByTimeAsync(3100)
        const calls = tasks.getBackgroundTasks.mock.calls.length
        expect(calls).toBeGreaterThan(0)
        wrapper.unmount()
        await vi.advanceTimersByTimeAsync(30000)
        expect(tasks.getBackgroundTasks.mock.calls.length).toBe(calls)
        expect(vi.getTimerCount()).toBe(0)
    })
})
