import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import StoreImage from '../../../../cms/Core/admin/pages/setting/modules/store-image.vue'

const ajaxMock = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))

const HASH = 'c'.repeat(64)

beforeEach(() => {
    ajaxMock.get.mockReset()
    URL.createObjectURL = vi.fn(() => 'blob:store-image') as never
    URL.revokeObjectURL = vi.fn() as never
})
afterEach(() => vi.restoreAllMocks())

describe('StoreImage', () => {
    it('loads the cached image as a blob and shows it', async () => {
        ajaxMock.get.mockResolvedValue({ data: new Blob(['x'], { type: 'image/png' }) })
        const wrapper = mount(StoreImage, { props: { hash: HASH, alt: 'Shot' } })
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenCalledWith(`utilities/modules/catalog/media/${HASH}`, expect.objectContaining({ responseType: 'blob' }))
        expect(wrapper.find('img').attributes('src')).toBe('blob:store-image')
        expect(wrapper.find('img').attributes('alt')).toBe('Shot')
    })

    it('shows the placeholder slot when there is no hash or the image fails', async () => {
        const none = mount(StoreImage, { props: { hash: null }, slots: { default: '<i data-testid="ph" />' } })
        await flushPromises()
        expect(none.find('[data-testid=ph]').exists()).toBe(true)
        expect(ajaxMock.get).not.toHaveBeenCalled()

        ajaxMock.get.mockRejectedValue(new Error('404'))
        const failed = mount(StoreImage, { props: { hash: HASH }, slots: { default: '<i data-testid="ph" />' } })
        await flushPromises()
        expect(failed.find('img').exists()).toBe(false)
        expect(failed.find('[data-testid=ph]').exists()).toBe(true)
    })

    it('releases the object url when it goes away', async () => {
        ajaxMock.get.mockResolvedValue({ data: new Blob(['x']) })
        const wrapper = mount(StoreImage, { props: { hash: HASH } })
        await flushPromises()
        wrapper.unmount()
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:store-image')
    })
})
