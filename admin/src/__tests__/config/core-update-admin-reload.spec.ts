import { afterEach, describe, expect, it, vi } from 'vitest'
import { basePath } from '@/config/base'
import { reloadAdminBuild } from '@core-admin/pages/setting/modules/admin-reload'

describe('reloading the admin after a Core update', () => {
    afterEach(() => vi.useRealTimers())

    it('re-reads the admin index past the browser cache, then reloads', async () => {
        const order: string[] = []
        const fetcher = vi.fn((url: string) => {
            order.push(`fetch ${url}`)
            return Promise.resolve({ ok: true })
        })
        const reload = vi.fn(() => order.push('reload'))
        await reloadAdminBuild({ fetcher, reload })
        // The admin base comes from the config, and both spellings of the index are refreshed.
        expect(order).toEqual([`fetch ${basePath}`, `fetch ${basePath}index.html`, 'reload'])
        for (const [, options] of fetcher.mock.calls as unknown as [string, RequestInit][]) {
            expect(options.cache).toBe('reload')
        }
        expect(reload).toHaveBeenCalledTimes(1)
    })

    it('reloads all the same when the requests fail', async () => {
        const reload = vi.fn()
        await reloadAdminBuild({ fetcher: vi.fn(() => Promise.reject(new Error('offline'))), reload })
        expect(reload).toHaveBeenCalledTimes(1)

        const thrower = vi.fn(() => {
            throw new Error('no fetch here')
        })
        await reloadAdminBuild({ fetcher: thrower, reload })
        expect(reload).toHaveBeenCalledTimes(2)
    })

    it('does not wait for a server that never answers', async () => {
        vi.useFakeTimers()
        const reload = vi.fn()
        const done = reloadAdminBuild({ fetcher: vi.fn(() => new Promise(() => {})), reload })
        await vi.advanceTimersByTimeAsync(3999)
        expect(reload).not.toHaveBeenCalled()
        await vi.advanceTimersByTimeAsync(1)
        await done
        expect(reload).toHaveBeenCalledTimes(1)
    })

    it('adds the slash to a base given without one', async () => {
        const fetcher = vi.fn(() => Promise.resolve({ ok: true }))
        await reloadAdminBuild({ fetcher, reload: vi.fn(), base: '/admin' })
        expect(fetcher.mock.calls.map(([url]) => url)).toEqual(['/admin/', '/admin/index.html'])
    })
})
