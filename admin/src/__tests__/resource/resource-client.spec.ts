import { beforeEach, describe, expect, it, vi } from 'vitest'

const getMock = vi.fn()
vi.mock('@/ajax', () => ({ default: { get: (...a: any[]) => getMock(...a) } }))

import { clearResourceSchemaCache, getResourceSchema } from '@/libs/resource'

const ok = (schema: any, etag?: string) => ({ status: 200, headers: etag ? { etag } : {}, data: { data: schema } })

describe('getResourceSchema', () => {
    beforeEach(() => {
        getMock.mockReset()
        clearResourceSchemaCache()
    })

    it('fetches silently without If-None-Match first, then revalidates and reuses on 304', async () => {
        getMock.mockResolvedValueOnce(ok({ v: 1 }, '"e1"'))
        expect(await getResourceSchema('m', 'r')).toEqual({ v: 1 })
        const [url, cfg] = getMock.mock.calls[0]
        expect(url).toBe('resources/m/r/schema')
        expect(cfg.silent).toBe(true)
        expect(cfg.headers?.['If-None-Match']).toBeUndefined()
        expect(cfg.validateStatus(304)).toBe(true)
        expect(cfg.validateStatus(200)).toBe(true)
        expect(cfg.validateStatus(500)).toBe(false)

        getMock.mockResolvedValueOnce({ status: 304, headers: {}, data: '' })
        expect(await getResourceSchema('m', 'r')).toEqual({ v: 1 })
        expect(getMock.mock.calls[1][1].headers['If-None-Match']).toBe('"e1"')
    })

    it('replaces the cached etag on 200', async () => {
        getMock.mockResolvedValueOnce(ok({ v: 1 }, '"e1"'))
        await getResourceSchema('m', 'r')
        getMock.mockResolvedValueOnce(ok({ v: 2 }, '"e2"'))
        expect(await getResourceSchema('m', 'r')).toEqual({ v: 2 })
        getMock.mockResolvedValueOnce({ status: 304, headers: {}, data: '' })
        expect(await getResourceSchema('m', 'r')).toEqual({ v: 2 })
        expect(getMock.mock.calls[2][1].headers['If-None-Match']).toBe('"e2"')
    })

    it('evicts the least recently used entry beyond 20', async () => {
        for (let i = 0; i < 21; i++) {
            getMock.mockResolvedValueOnce(ok({ i }, `"e${i}"`))
            await getResourceSchema('m', `r${i}`)
        }
        getMock.mockResolvedValueOnce(ok({ i: 0 }, '"e0"'))
        await getResourceSchema('m', 'r0')
        expect(getMock.mock.calls[21][1].headers?.['If-None-Match']).toBeUndefined()
    })

    it('a 304 refreshes the LRU position', async () => {
        for (let i = 0; i < 20; i++) {
            getMock.mockResolvedValueOnce(ok({ i }, `"e${i}"`))
            await getResourceSchema('m', `r${i}`)
        }
        getMock.mockResolvedValueOnce({ status: 304, headers: {}, data: '' })
        await getResourceSchema('m', 'r0')
        getMock.mockResolvedValueOnce(ok({ i: 20 }, '"e20"'))
        await getResourceSchema('m', 'r20') // evicts r1, not r0
        getMock.mockResolvedValueOnce({ status: 304, headers: {}, data: '' })
        await getResourceSchema('m', 'r0')
        expect(getMock.mock.calls[22][1].headers['If-None-Match']).toBe('"e0"')
    })

    it('propagates errors', async () => {
        getMock.mockRejectedValueOnce(new Error('boom'))
        await expect(getResourceSchema('m', 'r')).rejects.toThrow('boom')
    })
})
