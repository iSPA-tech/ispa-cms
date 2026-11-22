import { beforeEach, describe, expect, it, vi } from 'vitest'
import ajax from '@/ajax'
import * as userApi from '@/libs/user'

vi.mock('@/ajax', () => ({
    default: {
        post: vi.fn(),
    },
}))

vi.mock('@/ajax-v4', () => ({
    default: {},
}))

describe('user email verification API', () => {
    beforeEach(() => {
        vi.mocked(ajax.post).mockReset()
        vi.mocked(ajax.post).mockResolvedValue({
            data: { status: 'success', message: 'Verification email queued.' },
        })
    })

    it('posts the target UUID to the email verification resend endpoint', async () => {
        expect(typeof userApi.resendUserEmailVerification).toBe('function')

        const response = await userApi.resendUserEmailVerification('user-uuid')

        expect(ajax.post).toHaveBeenCalledWith('user/user-uuid/email-verification/resend')
        expect(response).toEqual({ status: 'success', message: 'Verification email queued.' })
    })
})
