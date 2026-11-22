import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/ajax', () => ({
    default: {
        post: vi.fn(),
    },
}))

import ajax from '@/ajax'
import { createAuthenticatorQrUrl, revokeAuthenticatorQrUrl } from '@/libs/authenticator'

describe('authenticator QR adapter', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('posts the OTP URI through authenticated AJAX and returns an object URL', async () => {
        const blob = new Blob(['qr'], { type: 'image/png' })
        const createObjectURL = vi.spyOn(window.URL, 'createObjectURL').mockReturnValue('blob:authenticator-qr')
        ajax.post.mockResolvedValue({ data: blob })

        await expect(createAuthenticatorQrUrl('https://example.test/private-qr')).resolves.toBe('blob:authenticator-qr')
        expect(ajax.post).toHaveBeenCalledWith(
            'qr',
            { string: 'https://example.test/private-qr', size: 280 },
            { responseType: 'blob' }
        )
        expect(createObjectURL).toHaveBeenCalledWith(blob)

        createObjectURL.mockRestore()
    })

    it('revokes a previously rendered QR object URL', () => {
        const revokeObjectURL = vi.spyOn(window.URL, 'revokeObjectURL').mockImplementation(() => {})

        revokeAuthenticatorQrUrl('blob:authenticator-qr')

        expect(revokeObjectURL).toHaveBeenCalledWith('blob:authenticator-qr')
        revokeObjectURL.mockRestore()
    })
})
