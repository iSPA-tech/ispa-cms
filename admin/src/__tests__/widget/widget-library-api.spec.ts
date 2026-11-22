import { beforeEach, describe, expect, it, vi } from 'vitest'
import ajax from '@/ajax'
import {
    listLibraryWidgets,
    loadHubPreview,
    loadWidgetThumbnail,
    onDesignPreviewUploaded,
    uploadDesignPreview,
} from '../../../../cms/Core/admin/widget-library-api'

// Paginator page as `GET widget/admin-list` returns it.
const page = (current: number, last: number, ids: number[]) => ({
    data: { status: 'success', data: { current_page: current, last_page: last, total: 5, data: ids.map((id) => ({ id })) } },
})

describe('widget library api', () => {
    beforeEach(() => vi.restoreAllMocks())

    it('reads every page of the list, so no widget is left out of the library', async () => {
        const get = vi
            .spyOn(ajax, 'get')
            .mockResolvedValueOnce(page(1, 3, [1, 2]) as any)
            .mockResolvedValueOnce(page(2, 3, [3, 4]) as any)
            .mockResolvedValueOnce(page(3, 3, [5]) as any)

        const rows = await listLibraryWidgets()

        expect(rows.map((row: { id: number }) => row.id)).toEqual([1, 2, 3, 4, 5])
        expect(get).toHaveBeenCalledTimes(3)
        expect(get.mock.calls.map(([, config]) => (config as any).params.page)).toEqual([1, 2, 3])
        // Usage is asked for on every page, quietly.
        for (const [url, config] of get.mock.calls) {
            expect(url).toBe('widget/admin-list')
            expect((config as any).params.with_usage).toBe(1)
            expect((config as any).silent).toBe(true)
        }
    })

    it('library requests scope library', async () => {
        // The library lists every widget of the site; only its own request opts out of the pickers' site filter.
        const get = vi.spyOn(ajax, 'get').mockResolvedValueOnce(page(1, 1, [1]) as any)

        await listLibraryWidgets()

        expect((get.mock.calls[0][1] as any).params.scope).toBe('library')
    })

    it('fails instead of showing an empty library when the list read is refused', async () => {
        vi.spyOn(ajax, 'get').mockResolvedValue({ data: { status: 'error', message: 'no permission' } } as any)

        await expect(listLibraryWidgets()).rejects.toBeTruthy()
    })

    it('keeps one picture per widget design and version', async () => {
        const post = vi.spyOn(ajax, 'post').mockResolvedValue({ data: '<p>v</p>' } as any)

        await loadWidgetThumbnail(901, 7, 1)
        await loadWidgetThumbnail(901, 7, 1)
        expect(post).toHaveBeenCalledTimes(1)

        // A saved design has a new version: the old picture is not reused.
        await loadWidgetThumbnail(901, 7, 2)
        expect(post).toHaveBeenCalledTimes(2)
    })

    it('asks for at most four hub pictures at a time, quietly, as images', async () => {
        const waiting: Array<(value: unknown) => void> = []
        const get = vi.spyOn(ajax, 'get').mockImplementation(() => new Promise((resolve) => waiting.push(resolve)) as any)
        get.mockClear()
        const settle = () => new Promise((resolve) => setTimeout(resolve))
        const image = { data: new Blob(['png'], { type: 'image/png' }) }

        const requests = [1, 2, 3, 4, 5, 6].map((n) => loadHubPreview(`http://site.test/p/${n}`))
        await settle()
        expect(get).toHaveBeenCalledTimes(4)
        expect(get.mock.calls[0]).toEqual(['http://site.test/p/1', { responseType: 'blob', silent: true }])

        waiting[0](image)
        await requests[0]
        await settle()
        expect(get).toHaveBeenCalledTimes(5)

        // An answer that is not a picture (an error envelope) is refused, and the next one still starts.
        waiting[1]({ data: new Blob(['{}'], { type: 'application/json' }) })
        await expect(requests[1]).rejects.toBeTruthy()
        await settle()
        expect(get).toHaveBeenCalledTimes(6)
        waiting.slice(2).forEach((resolve) => resolve(image))
        await Promise.all(requests.slice(2))
    })

    it('names the uploaded picture by its real type and tells listeners about the new one', async () => {
        const saved = { id: 7, preview_image_url: '/storage/widget-previews/a/b-new.webp', preview_stale: false }
        const post = vi.spyOn(ajax, 'post').mockResolvedValue({ data: { status: 'success', data: saved } } as any)
        post.mockClear()
        const heard = vi.fn()
        const stop = onDesignPreviewUploaded(heard)

        await uploadDesignPreview(7, new Blob(['w'], { type: 'image/webp' }))
        expect((post.mock.calls[0][1] as FormData).get('image')).toMatchObject({ name: 'preview.webp' })
        expect(heard).toHaveBeenCalledWith(saved)

        stop()
        await uploadDesignPreview(7, new Blob(['p'], { type: 'image/png' }))
        expect((post.mock.calls[1][1] as FormData).get('image')).toMatchObject({ name: 'preview.png' })
        expect(heard).toHaveBeenCalledTimes(1)
    })
})
