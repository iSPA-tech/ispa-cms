import '@testing-library/jest-dom'
import { vi } from 'vitest'

const createStorageMock = () => {
    let store = new Map<string, string>()

    return {
        get length() {
            return store.size
        },
        clear: vi.fn(() => {
            store = new Map<string, string>()
        }),
        getItem: vi.fn((key: string) => store.get(key) ?? null),
        key: vi.fn((index: number) => Array.from(store.keys())[index] ?? null),
        removeItem: vi.fn((key: string) => {
            store.delete(key)
        }),
        setItem: vi.fn((key: string, value: string) => {
            store.set(key, String(value))
        }),
    }
}

const ensureLocalStorage = () => {
    try {
        const key = '__vitest_local_storage_probe__'
        window.localStorage.setItem(key, '1')
        window.localStorage.removeItem(key)
        return
    } catch (_error) {
        const storage = createStorageMock()

        Object.defineProperty(window, 'localStorage', {
            value: storage,
            configurable: true,
        })
        Object.defineProperty(globalThis, 'localStorage', {
            value: storage,
            configurable: true,
        })
    }
}

ensureLocalStorage()

// matchMedia polyfill for jsdom
if (!window.matchMedia) {
    window.matchMedia = (query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: vi.fn(), // deprecated
        removeListener: vi.fn(), // deprecated
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })
}

// ResizeObserver stub
if (!('ResizeObserver' in window)) {
    // @ts-ignore
    window.ResizeObserver = class ResizeObserver {
        observe() {}
        unobserve() {}
        disconnect() {}
    }
}

// IntersectionObserver stub
if (!('IntersectionObserver' in window)) {
    // @ts-ignore
    window.IntersectionObserver = class IntersectionObserver {
        constructor() {}
        observe() {}
        unobserve() {}
        disconnect() {}
        takeRecords() {
            return []
        }
    }
}

// scrollTo stub
if (!window.scrollTo) {
    // @ts-ignore
    window.scrollTo = vi.fn()
}
