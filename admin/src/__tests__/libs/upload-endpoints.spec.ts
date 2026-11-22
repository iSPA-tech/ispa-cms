import { beforeEach, describe, expect, it, vi } from 'vitest'

const getMock = vi.fn()
const useQueryMock = vi.fn()

vi.mock('@/ajax', () => ({
    default: {
        get: (...args: unknown[]) => getMock(...args),
    },
}))

vi.mock('@tanstack/vue-query', () => ({
    useQuery: (options: unknown) => {
        useQueryMock(options)

        return {}
    },
}))

describe('upload list endpoint', () => {
    beforeEach(() => {
        getMock.mockReset()
        useQueryMock.mockReset()
        getMock.mockResolvedValue({ data: { data: [] } })
    })

    it('getUploads lists the general `upload` route unless an endpoint is given', async () => {
        const { getUploads } = await import('../../libs/upload')

        await getUploads({ page: 2 })
        await getUploads({ page: 3 }, 'digital-library/documents')

        expect(getMock).toHaveBeenNthCalledWith(1, 'upload', { params: { page: 2 } })
        expect(getMock).toHaveBeenNthCalledWith(2, 'digital-library/documents', {
            params: { page: 3 },
        })
    })

    it('useUploads keeps its query key and call shape for the default route', async () => {
        const { useUploads } = await import('../../hooks/useUpload')
        const params = { page: 1, type: 'image' }

        useUploads(params, { enabled: true })

        const options = useQueryMock.mock.calls[0][0] as {
            queryKey: unknown[]
            queryFn: () => Promise<unknown>
            enabled: boolean
        }
        expect(options.queryKey).toEqual(['uploads', params])
        expect(options.enabled).toBe(true)

        await options.queryFn()

        expect(getMock).toHaveBeenCalledWith('upload', { params })
    })

    it('useUploads scopes the cache and the request to a module endpoint', async () => {
        const { useUploads } = await import('../../hooks/useUpload')
        const params = { page: 1 }

        useUploads(params, {}, 'digital-library/documents')

        const options = useQueryMock.mock.calls[0][0] as {
            queryKey: unknown[]
            queryFn: () => Promise<unknown>
        }
        expect(options.queryKey).toEqual(['uploads', 'digital-library/documents', params])

        await options.queryFn()

        expect(getMock).toHaveBeenCalledWith('digital-library/documents', { params })
    })
})
