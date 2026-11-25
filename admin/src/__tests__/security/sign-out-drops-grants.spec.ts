import { beforeEach, describe, expect, it, vi } from 'vitest'
import { heldGrantValue, holdGrant } from '@/libs/securityTokens'
import { signInAsUser, signOutAccount } from '@/libs/user'

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn(), put: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))

// Review fix 36 (2026-11-25): step-up grants live in memory; a sign-out or a sign-in as another user in the same tab
// must not leave them to the next person.
describe('step-up grants on account switches', () => {
    beforeEach(() => {
        ajaxMock.post.mockReset()
        holdGrant('g-publish', 300, 'workflow_publish')
        holdGrant('g-revoke', 300)
    })

    it('drops every held grant on sign-out, even when the request fails', async () => {
        ajaxMock.post.mockRejectedValue(new Error('offline'))
        await expect(signOutAccount()).rejects.toThrow('offline')
        expect(heldGrantValue('workflow_publish')).toBeNull()
        expect(heldGrantValue()).toBeNull()
    })

    it('drops every held grant when signing in as another user', async () => {
        ajaxMock.post.mockResolvedValue({ data: { token: 't' } })
        await signInAsUser('u-1')
        expect(heldGrantValue('workflow_publish')).toBeNull()
        expect(heldGrantValue()).toBeNull()
    })
})
