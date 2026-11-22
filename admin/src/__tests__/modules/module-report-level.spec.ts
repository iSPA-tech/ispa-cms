import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import ReportLevel from '../../../../cms/Core/admin/pages/setting/modules/report-level.vue'

/*
 * "Gửi cho iSPA" on the modules page. The Webmaster picks how much the daily site report sends (off, basic,
 * full), previews the exact JSON of the chosen level before saving, is warned that off stops ratings and install proof,
 * and cannot change a level set on the server.
 */

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))
const messageMock = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('ant-design-vue', () => ({ message: messageMock }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k) }),
}))

const $t = (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k)
const stubs = {
    'a-skeleton': defineComponent({ setup: (_p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }) }),
    'a-alert': defineComponent({ props: ['message'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'], role: 'alert' }, p.message) }),
    'a-tag': defineComponent({ setup: (_p, { slots, attrs }) => () => h('span', { 'data-testid': attrs['data-testid'] }, slots.default?.()) }),
    'a-modal': defineComponent({
        props: ['open', 'title'],
        setup: (p, { slots, attrs }) => () => (p.open ? h('div', { 'data-testid': attrs['data-testid'] }, [h('h2', p.title), slots.default?.()]) : null),
    }),
    'i-button': defineComponent({
        props: ['disabled', 'loading', 'variant', 'size'],
        emits: ['click'],
        setup: (p, { slots, emit, attrs }) => () =>
            h('button', { type: 'button', disabled: p.disabled || p.loading, 'data-testid': attrs['data-testid'], onClick: () => emit('click') }, slots.default?.()),
    }),
}

const state = (over: Record<string, unknown> = {}) => ({
    data: { data: { level: 'basic', source: 'default', default: 'basic', stored: null, env_locked: false, connected: true, levels: ['off', 'basic', 'full'], ...over } },
})
const basicJson = '{\n    "schema": 1,\n    "domain": "self.example.test"\n}'

const mountView = async () => {
    const wrapper = mount(ReportLevel, { global: { mocks: { $t }, stubs } })
    await flushPromises()
    return wrapper
}
const tid = (wrapper, id: string) => wrapper.find(`[data-testid="${id}"]`)

describe('Site report level', () => {
    beforeEach(() => {
        vi.resetAllMocks()
        ajaxMock.get.mockImplementation((url: string, config?: { params?: { level?: string } }) =>
            url.endsWith('/preview')
                ? Promise.resolve({ data: { data: { level: config?.params?.level, json: config?.params?.level === 'off' ? null : basicJson } } })
                : Promise.resolve(state()),
        )
    })

    it('draws the radios in the app colour, not the browser default', async () => {
        const wrapper = await mountView()
        for (const level of ['off', 'basic', 'full']) expect(tid(wrapper, `report-level-radio-${level}`).classes()).toContain('accent-primary')
    })

    it('shows the three levels with the one in use checked and marks the default', async () => {
        const wrapper = await mountView()

        expect(ajaxMock.get).toHaveBeenCalledWith('utilities/modules/report-level', expect.objectContaining({ silent: true }))
        for (const level of ['off', 'basic', 'full']) {
            expect(tid(wrapper, `report-level-option-${level}`).text()).toContain(`text.modules.report.level.${level}.label`)
            expect(tid(wrapper, `report-level-option-${level}`).text()).toContain(`text.modules.report.level.${level}.hint`)
        }
        expect((tid(wrapper, 'report-level-radio-basic').element as HTMLInputElement).checked).toBe(true)
        expect(tid(wrapper, 'report-level-option-basic').find('[data-testid="report-level-default"]').exists()).toBe(true)
        expect(tid(wrapper, 'report-level-option-basic').find('[data-testid="report-level-current"]').exists()).toBe(true)
        expect(tid(wrapper, 'report-level-save').attributes('disabled')).toBeDefined()
        expect(tid(wrapper, 'report-level-off-warning').exists()).toBe(false)
    })

    it('warns that off stops ratings and install proof, and saves the choice once', async () => {
        ajaxMock.put.mockResolvedValue(state({ level: 'off', source: 'setting', stored: 'off' }))
        const wrapper = await mountView()

        await tid(wrapper, 'report-level-radio-off').setValue(true)
        expect(tid(wrapper, 'report-level-off-warning').text()).toBe('text.modules.report.off_warning')

        await tid(wrapper, 'report-level-save').trigger('click')
        await tid(wrapper, 'report-level-save').trigger('click')
        await flushPromises()

        expect(ajaxMock.put).toHaveBeenCalledTimes(1)
        expect(ajaxMock.put).toHaveBeenCalledWith('utilities/modules/report-level', { level: 'off' }, expect.objectContaining({ silent: true }))
        expect(messageMock.success).toHaveBeenCalledWith('text.modules.report.saved')
        expect(tid(wrapper, 'report-level-option-off').find('[data-testid="report-level-current"]').exists()).toBe(true)
    })

    it('previews the exact JSON of the chosen level before saving, and says nothing is sent for off', async () => {
        const wrapper = await mountView()

        await tid(wrapper, 'report-level-preview').trigger('click')
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenLastCalledWith('utilities/modules/report-level/preview', expect.objectContaining({ params: { level: 'basic' } }))
        expect(tid(wrapper, 'report-level-preview-json').text()).toBe(basicJson)
        expect(tid(wrapper, 'report-level-preview-dialog').text()).toContain('text.modules.report.level.basic.label')

        await tid(wrapper, 'report-level-radio-off').setValue(true)
        await tid(wrapper, 'report-level-preview').trigger('click')
        await flushPromises()
        expect(tid(wrapper, 'report-level-preview-json').exists()).toBe(false)
        expect(tid(wrapper, 'report-level-preview-none').text()).toBe('text.modules.report.preview_none')
        expect(ajaxMock.put).not.toHaveBeenCalled()
    })

    it('locks the choice when the server sets the level and says why', async () => {
        ajaxMock.get.mockResolvedValue(state({ level: 'full', source: 'env', default: 'full', env_locked: true }))
        const wrapper = await mountView()

        expect(tid(wrapper, 'report-level-env-locked').text()).toBe('text.modules.report.env_locked')
        expect(tid(wrapper, 'report-level-radio-off').attributes('disabled')).toBeDefined()
        expect(tid(wrapper, 'report-level-save').exists()).toBe(false)
    })

    it('says an unconnected site sends nothing', async () => {
        ajaxMock.get.mockResolvedValue(state({ level: 'off', source: 'unconnected', connected: false }))
        const wrapper = await mountView()

        expect(tid(wrapper, 'report-level-not-connected').text()).toBe('text.modules.report.not_connected')
        expect(wrapper.find('[data-testid="report-level-current"]').exists()).toBe(false)
    })

    it('shows a load error with a retry, and a plain save error', async () => {
        ajaxMock.get.mockRejectedValueOnce({ response: { status: 500 } })
        const wrapper = await mountView()
        expect(tid(wrapper, 'report-level-error').text()).toContain('text.modules.report.error.load')

        await tid(wrapper, 'report-level-retry').trigger('click')
        await flushPromises()
        expect(tid(wrapper, 'report-level-radio-basic').exists()).toBe(true)

        ajaxMock.put.mockRejectedValue({ response: { status: 409, data: { error_code: 'REPORT_LEVEL_MANAGED_BY_ENV' } } })
        await tid(wrapper, 'report-level-radio-full').setValue(true)
        await tid(wrapper, 'report-level-save').trigger('click')
        await flushPromises()
        expect(tid(wrapper, 'report-level-save-error').text()).toBe('text.modules.report.env_locked')
    })
})
