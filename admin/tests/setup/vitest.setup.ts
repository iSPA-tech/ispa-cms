import { config } from '@vue/test-utils'
import { vi } from 'vitest'

const useI18nMock = (key: string | string[], params?: Record<string, any>) => {
    const keyStr = Array.isArray(key) ? key.join('.') : key
    if (keyStr.startsWith('nav.')) return keyStr
    if (keyStr.startsWith('text.')) return keyStr
    if (keyStr.startsWith('validation.')) return keyStr
    if (keyStr.startsWith('n.')) return keyStr.replace('n.', 'nav.')
    if (keyStr.startsWith('t.')) return keyStr.replace('t.', 'text.')
    if (keyStr.startsWith('v.')) return keyStr.replace('v.', 'validation.')
    if (keyStr.startsWith('a.')) return keyStr.replace('a.', 'validation.attributes.')
    if (!params) return keyStr
    return keyStr.replace(/\{(\w+)\}/g, (match, paramKey) => {
        return Object.prototype.hasOwnProperty.call(params, paramKey) ? String(params[paramKey]) : match
    })
}

// Basic i18n-like mocks commonly used across components
config.global.mocks = {
    $t: useI18nMock,
    $tc: useI18nMock,
    $d: (v: any) => String(v),
    $n: (v: any) => String(v),
}

vi.mock('vue-i18n', () => {
    const t = (key: string | string[], params?: Record<string, any>) => {
        const keyStr = Array.isArray(key) ? key.join('.') : key
        if (keyStr.startsWith('nav.')) return keyStr
        if (keyStr.startsWith('text.')) return keyStr
        if (keyStr.startsWith('validation.')) return keyStr
        if (keyStr.startsWith('n.')) return keyStr.replace('n.', 'nav.')
        if (keyStr.startsWith('t.')) return keyStr.replace('t.', 'text.')
        if (keyStr.startsWith('v.')) return keyStr.replace('v.', 'validation.')
        if (keyStr.startsWith('a.')) return keyStr.replace('a.', 'validation.attributes.')
        if (!params) return keyStr
        return keyStr.replace(/\{(\w+)\}/g, (match, paramKey) => {
            return Object.prototype.hasOwnProperty.call(params, paramKey) ? String(params[paramKey]) : match
        })
    }
    return {
        useI18n: () => ({ t, te: () => true }),
        createI18n: () => ({
            global: {
                t,
                setLocaleMessage: vi.fn(),
                locale: { value: 'en' },
            },
            mode: 'composition',
            install: vi.fn(),
        }),
    }
})

// Shared module mocks to avoid app-wide side effects in unit tests
vi.mock('@/config', () => ({
    CACHE_KEY_ACCOUNT_DATA: 'CACHE_KEY_ACCOUNT_DATA',
    CACHE_KEY_MESSAGES_LOCALE: 'CACHE_KEY_MESSAGES_LOCALE',
    CONTENT_STATUSES: {},
    COOKIE_KEY_LOCALE: 'iSpa-locale',
    defaultLocale: 'en',
    basePath: '/iPanel/',
    apiUrl: 'https://example.com/',
    url: 'https://example.com/',
    cookie: { get: vi.fn(), set: vi.fn(), remove: vi.fn(), getAll: vi.fn() },
}))

vi.mock('@/config/base', () => ({
    apiUrl: 'https://example.com/',
    apiBase: 'https://example.com/api/v2/admin/',
    url: 'https://example.com/',
    appVersion: '0.0.0-test',
    buildVersion: 'test-build',
    domain: 'localhost',
    cookieDomain: 'localhost',
    isLocal: false,
    basePath: '/iPanel/',
    defaultLocale: 'en',
    supportLocales: ['en', 'vi'],
    cmsTitle: 'iSPA CMS',
    projectTitle: 'Content Management System',
    cookieKey: 'ispa-cms-test',
}))

vi.mock('@/ajax', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        interceptors: { response: { use: vi.fn() } },
    },
}))

vi.mock('@/ajax-v4', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        interceptors: { response: { use: vi.fn() } },
    },
}))

vi.mock('@/hooks', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/hooks')>()
    return {
        ...actual,
        widgetInputDefault: () => ({
            id: 0,
            widget_id: 0,
            name: null,
            type: 'text',
            default_value: null,
            options: [],
        }),
    }
})
