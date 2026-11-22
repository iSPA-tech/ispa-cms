import { beforeEach, describe, expect, it, vi } from 'vitest'

const notification = vi.hoisted(() => ({
    error: vi.fn(),
    info: vi.fn(),
    success: vi.fn(),
    warning: vi.fn(),
}))
const can = vi.hoisted(() => vi.fn(() => false))
const useStorage = vi.hoisted(() => vi.fn((_key, value) => ({ value })))

vi.mock('ant-design-vue', () => ({ notification }))
vi.mock('@vueuse/core', () => ({ useStorage }))
vi.mock('@/ajax', () => ({ default: {} }))
vi.mock('@/assets/spinner.png', () => ({ default: '' }))
vi.mock('@/config/base', () => ({ basePath: '/', defaultLocale: 'vi' }))
vi.mock('@/config', () => ({
    CACHE_KEY_ACCOUNT_DATA: 'account',
    CONTENT_STATUSES: {},
    cookie: {},
    COOKIE_KEY_LOCALE: 'locale',
}))
vi.mock('@/hooks', () => ({ widgetInputDefault: () => ({}) }))
vi.mock('@/hooks/useCan', () => ({ can }))

import { iAlert, rule, setAlertTitleTranslator } from '@/mixins/utils'

describe('iAlert', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('renders an explicit warning without a success title', () => {
        iAlert({
            type: 'warning',
            title: 'Đã hủy',
            message: 'Đã hủy xác thực passkey.',
        })

        expect(notification.warning).toHaveBeenCalledWith(
            expect.objectContaining({ message: 'Đã hủy' }),
        )
        expect(notification.success).not.toHaveBeenCalled()
    })

    it('uses the translated default title when a translator is registered, and the English default otherwise', () => {
        setAlertTitleTranslator(null)
        iAlert({ message: 'ok' })
        expect(notification.success).toHaveBeenLastCalledWith(expect.objectContaining({ message: 'Success' }))

        setAlertTitleTranslator((key) => (key === 'text.title.success' ? 'Thành công!' : null))
        iAlert({ message: 'ok' })
        expect(notification.success).toHaveBeenLastCalledWith(expect.objectContaining({ message: 'Thành công!' }))

        iAlert('lỗi', true)
        expect(notification.error).toHaveBeenLastCalledWith(expect.objectContaining({ message: 'Error' }))
        setAlertTitleTranslator(null)
    })
})

describe('rule', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('evaluates a structured permission spec without creating reactive storage', () => {
        can.mockReturnValueOnce(true)

        expect(rule({ t: 'allOf', items: [] })).toBe(true)
        expect(useStorage).not.toHaveBeenCalled()
    })
})
