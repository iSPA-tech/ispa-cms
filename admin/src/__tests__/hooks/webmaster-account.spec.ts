import { describe, expect, it } from 'vitest'
import { isWebmasterAccount } from '@/hooks/useCan'

describe('isWebmasterAccount', () => {
    it('is true for the account with id 1', () => {
        expect(isWebmasterAccount({ id: 1 })).toBe(true)
    })

    it('is true for a role with custom level 0', () => {
        expect(isWebmasterAccount({ id: 7, roles: [{ custom: { level: 1 } }, { custom: { level: 0 } }] })).toBe(true)
    })

    it('is false for a role with level 1 only', () => {
        expect(isWebmasterAccount({ id: 7, roles: [{ custom: { level: 1 } }] })).toBe(false)
    })

    it('is false for a missing account', () => {
        expect(isWebmasterAccount(null)).toBe(false)
        expect(isWebmasterAccount(undefined)).toBe(false)
    })
})
