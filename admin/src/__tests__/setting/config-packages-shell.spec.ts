import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ConfigPackages from '@core-admin/pages/setting/config-packages.vue'
import SettingHub from '@/pages/setting/setting-hub.vue'
import SettingCategoryLayout from '@/pages/setting/setting-category-layout.vue'
import {
    applyRun,
    downloadUrl,
    fetchRun,
    fetchRuns,
    fetchSections,
    startExport,
    uploadPackage,
} from '@/libs/configPackageApi'

const state = vi.hoisted(() => ({ webmaster: true }))
const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('@/mixins', () => ({ rule: () => true, iAlert: vi.fn() }))
vi.mock('@/hooks/useCan', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/hooks/useCan')>()
    return { ...actual, isWebmaster: () => state.webmaster, hasAccountData: () => true }
})
vi.mock('@/hooks/useActiveModules', () => ({ isModuleActive: () => true }))
vi.mock('@/config/admin-modules', () => ({ adminModuleSettings: [] }))
vi.mock('vue-router', () => ({
    useRoute: () => ({ name: 'setting-config-packages', path: '/setting/system/config-packages', meta: {} }),
    useRouter: () => ({ replace: vi.fn() }),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: { value: 'vi' } }) }))

const stubs = {
    'a-tabs': { template: '<div data-testid="tabs"><slot /></div>' },
    'a-tab-pane': { props: ['tab'], template: '<section data-testid="tab-pane"><h4>{{ tab }}</h4><slot /></section>' },
    'i-icon': true,
    'router-link': { props: ['to'], template: '<a :data-to="to && to.name"><slot /></a>' },
}

beforeEach(() => {
    state.webmaster = true
    ajaxMock.get.mockReset().mockResolvedValue({ data: {} })
    ajaxMock.post.mockReset().mockResolvedValue({ data: {} })
})

describe('configPackageApi', () => {
    it('calls the config-packages endpoints', async () => {
        await fetchSections()
        expect(ajaxMock.get.mock.calls[0][0]).toBe('config-packages/sections')
        await startExport({ sections: ['a'], translation_scope: 'web' })
        expect(ajaxMock.post.mock.calls[0][0]).toBe('config-packages/export')
        expect(ajaxMock.post.mock.calls[0][1]).toEqual({ sections: ['a'], translation_scope: 'web' })
        await fetchRuns({ page: 2 })
        expect(ajaxMock.get.mock.calls[1][0]).toBe('config-packages/runs')
        expect(ajaxMock.get.mock.calls[1][1].params).toEqual({ page: 2 })
        await fetchRun('u1', { page: 1 })
        expect(ajaxMock.get.mock.calls[2][0]).toBe('config-packages/runs/u1')
        await applyRun('u1', { x: 'skip' })
        expect(ajaxMock.post.mock.calls[1][0]).toBe('config-packages/u1/apply')
        expect(ajaxMock.post.mock.calls[1][1]).toEqual({ choices: { x: 'skip' } })
    })

    it('uploads the file as multipart form data under the field "file"', async () => {
        const file = new File(['{}'], 'p.json', { type: 'application/json' })
        await uploadPackage(file)
        const [url, body, config] = ajaxMock.post.mock.calls[0]
        expect(url).toBe('config-packages/upload')
        expect(body).toBeInstanceOf(FormData)
        expect((body as FormData).get('file')).toBeInstanceOf(File)
        expect(config.headers['Content-Type']).toBe('multipart/form-data')
    })

    it('builds the download URL without calling the API', () => {
        expect(downloadUrl('u1')).toBe('config-packages/u1/download')
        expect(ajaxMock.get).not.toHaveBeenCalled()
    })
})

describe('config packages page shell', () => {
    it('shows the three tabs to a Webmaster', async () => {
        const wrapper = mount(ConfigPackages, { global: { stubs } })
        await flushPromises()
        expect(wrapper.find('[data-testid="config-packages-page"]').exists()).toBe(true)
        expect(wrapper.findAll('[data-testid="tab-pane"]')).toHaveLength(3)
        const text = wrapper.text()
        for (const key of ['tab_export', 'tab_import', 'tab_history']) {
            expect(text).toContain(`text.config_package.${key}`)
        }
    })

    it('shows only the not-allowed message to a non-Webmaster', async () => {
        state.webmaster = false
        const wrapper = mount(ConfigPackages, { global: { stubs } })
        await flushPromises()
        expect(wrapper.text()).toContain('text.config_package.not_allowed')
        expect(wrapper.find('[data-testid="tabs"]').exists()).toBe(false)
        expect(wrapper.findAll('[data-testid="tab-pane"]')).toHaveLength(0)
    })
})

describe('config packages navigation', () => {
    const hub = () => mount(SettingHub, { global: { mocks: { $t: (k: string) => k }, stubs } })

    const systemCount = () => {
        const match = hub().find('[data-testid="setting-hub-system"]').text().match(/(\d+) setting\.hub\.items/)
        return Number(match?.[1])
    }

    it('counts the hub entry only for a Webmaster (Backups and Configuration packages are Webmaster-only)', () => {
        state.webmaster = true
        const withWebmaster = systemCount()
        state.webmaster = false
        expect(withWebmaster - systemCount()).toBe(2)
    })

    it('lists the sidebar link only for a Webmaster', () => {
        const layout = () =>
            mount(SettingCategoryLayout, {
                global: { mocks: { $t: (k: string) => k }, stubs: { ...stubs, 'router-view': true } },
            })
        state.webmaster = true
        expect(layout().find('a[data-to="setting-config-packages"]').exists()).toBe(true)
        state.webmaster = false
        expect(layout().find('a[data-to="setting-config-packages"]').exists()).toBe(false)
    })
})
