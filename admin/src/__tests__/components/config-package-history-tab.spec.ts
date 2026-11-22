import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import HistoryTab from '@core-admin/components/config-package/history-tab.vue'

const api = vi.hoisted(() => ({ fetchRuns: vi.fn(), downloadUrl: (uuid: string) => `config-packages/${uuid}/download` }))
const ajaxMock = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@/libs/configPackageApi', () => api)
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params?: object) => (params ? `${key}${JSON.stringify(params)}` : key),
        te: (key: string) => !key.endsWith('section_unknown_thing'),
        locale: { value: 'en' },
    }),
}))

const stubs = {
    'a-button': { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading"><slot /></button>' },
    'a-alert': { props: ['message', 'description'], template: '<div role="alert"><b>{{ message }}</b><i>{{ description }}</i><slot name="action" /></div>' },
    'a-empty': { props: ['description'], template: '<div>{{ description }}</div>' },
    'a-skeleton': true,
    'a-tag': { template: '<span><slot /></span>' },
}

const counts = (o = {}) => ({ create: 0, update: 0, unchanged: 0, blocked: 0, skipped: 0, ...o })
const runs = [
    {
        uuid: 'apply-1',
        kind: 'apply',
        status: 'succeeded',
        sections: ['settings', 'unknown_thing'],
        created_at: '2026-11-10T03:00:00+00:00',
        summary: { sections: { settings: { status: 'succeeded', updated: 2, unchanged: 1, skipped: 0, blocked: 1 }, unknown_thing: { status: 'skipped' } } },
        has_package: false,
    },
    {
        uuid: 'check-1',
        kind: 'dry_run',
        status: 'succeeded',
        sections: ['settings'],
        created_at: '2026-11-10T02:00:00+00:00',
        summary: { sections: { settings: { status: 'changes', counts: counts({ update: 3, blocked: 2 }), total: 5 } } },
    },
    { uuid: 'export-1', kind: 'export', status: 'succeeded', sections: ['settings'], created_at: '2026-11-10T01:00:00+00:00', summary: { sections: { settings: 12 } }, has_package: true },
    { uuid: 'export-2', kind: 'export', status: 'failed', sections: ['menus'], created_at: '2026-11-09T01:00:00+00:00', summary: null, has_package: false },
]

const find = (w: ReturnType<typeof mount>, id: string) => w.find(`[data-testid="${id}"]`)
const mountTab = async () => {
    const w = mount(HistoryTab, { global: { stubs } })
    await flushPromises()
    return w
}

beforeEach(() => {
    api.fetchRuns.mockReset().mockResolvedValue({ success: true, data: { data: runs, meta: { current_page: 1, per_page: 50, total: runs.length, last_page: 1 } } })
    ajaxMock.get.mockReset().mockResolvedValue({ data: new Blob(['{}']), headers: { 'content-disposition': 'attachment; filename="pack.json"' } })
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
})

describe('config package history tab', () => {
    it('asks for the last 50 runs and lists kind, time, status, parts and counts', async () => {
        const w = await mountTab()
        expect(api.fetchRuns).toHaveBeenCalledWith({ per_page: 50 })
        expect(find(w, 'history-table').findAll('tbody tr')).toHaveLength(4)
        const apply = find(w, 'history-row-apply-1').text()
        expect(apply).toContain('kind_apply')
        expect(apply).toContain('history_status_succeeded')
        expect(apply).toContain('text.config_package.section_settings')
        expect(apply).toContain('Unknown thing')
        expect(apply).toContain('history_result_apply{"applied":2,"blocked":1}')
        expect(find(w, 'history-row-check-1').text()).toContain('history_result_check{"changes":3,"blocked":2}')
        expect(find(w, 'history-row-export-1').text()).toContain('history_result_export{"count":12}')
        expect(find(w, 'history-row-export-2').text()).toContain('history_status_failed')
        expect(w.text()).not.toContain('apply-1')
    })

    it('shows a loading state, then the empty state when there are no runs', async () => {
        let resolve: (v: unknown) => void = () => {}
        api.fetchRuns.mockReturnValue(new Promise((r) => (resolve = r)))
        const w = mount(HistoryTab, { global: { stubs } })
        await flushPromises()
        expect(find(w, 'history-loading').exists()).toBe(true)
        resolve({ success: true, data: { data: [], meta: { total: 0 } } })
        await flushPromises()
        expect(find(w, 'history-loading').exists()).toBe(false)
        expect(find(w, 'history-empty').text()).toContain('history_empty')
        expect(find(w, 'history-table').exists()).toBe(false)
    })

    it('shows an error with retry that reloads, and the reload button reloads too', async () => {
        api.fetchRuns.mockRejectedValueOnce(new Error('x'))
        const w = await mountTab()
        expect(find(w, 'history-error').exists()).toBe(true)
        await find(w, 'history-retry').trigger('click')
        await flushPromises()
        expect(api.fetchRuns).toHaveBeenCalledTimes(2)
        expect(find(w, 'history-error').exists()).toBe(false)
        expect(find(w, 'history-table').exists()).toBe(true)
        await find(w, 'history-reload').trigger('click')
        await flushPromises()
        expect(api.fetchRuns).toHaveBeenCalledTimes(3)
    })

    it('offers a download only for exports that finished and still hold their package', async () => {
        const w = await mountTab()
        expect(find(w, 'history-download-export-1').exists()).toBe(true)
        expect(find(w, 'history-download-export-2').exists()).toBe(false)
        expect(find(w, 'history-download-apply-1').exists()).toBe(false)
        await find(w, 'history-download-export-1').trigger('click')
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenCalledWith('config-packages/export-1/download', { responseType: 'blob', silent: true })
        expect(URL.createObjectURL).toHaveBeenCalled()
        expect(find(w, 'history-download-error').exists()).toBe(false)
    })

    it('shows a translated sentence when the download fails', async () => {
        ajaxMock.get.mockRejectedValue({ response: { status: 500, data: { message: 'SQLSTATE boom' } } })
        const w = await mountTab()
        await find(w, 'history-download-export-1').trigger('click')
        await flushPromises()
        expect(find(w, 'history-download-error').text()).toContain('export_error_download')
        expect(w.text()).not.toContain('SQLSTATE')
    })

    it('opens the result table of a check and of an import, and none for an export', async () => {
        const w = await mountTab()
        expect(find(w, 'history-view-export-1').exists()).toBe(false)
        await find(w, 'history-view-check-1').trigger('click')
        expect(find(w, 'history-detail-row-settings').text()).toContain('3')
        await find(w, 'history-view-apply-1').trigger('click')
        expect(find(w, 'history-detail-row-settings').text()).toContain('apply_section_succeeded')
        expect(find(w, 'history-detail-row-unknown_thing').text()).toContain('apply_section_skipped')
        await find(w, 'history-detail-close').trigger('click')
        expect(find(w, 'history-detail').exists()).toBe(false)
    })
})
