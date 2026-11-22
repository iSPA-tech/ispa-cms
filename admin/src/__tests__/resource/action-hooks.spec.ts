import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const msg = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('ant-design-vue', () => ({ message: msg }))

import { registerActionHook, runActionHook, serverMessage, unregisterActionHook } from '@/utils/resource/action-hooks'

describe('action hooks', () => {
    beforeEach(() => vi.resetAllMocks())
    afterEach(() => {
        unregisterActionHook('known')
        vi.unstubAllEnvs()
    })

    it('runs a registered hook once with the context', async () => {
        const fn = vi.fn()
        registerActionHook('known', fn)
        expect(await runActionHook('known', { uuid: 'u1' })).toBe(true)
        expect(fn).toHaveBeenCalledTimes(1)
        expect(fn).toHaveBeenCalledWith({ uuid: 'u1' })
    })

    it('does nothing for an empty name', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
        expect(await runActionHook(undefined)).toBe(false)
        expect(warn).not.toHaveBeenCalled()
    })

    it('warns about an unknown hook in dev only', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
        vi.stubEnv('DEV', true)
        expect(await runActionHook('missing')).toBe(false)
        expect(warn).toHaveBeenCalledTimes(1)
        warn.mockClear()
        vi.stubEnv('DEV', false)
        expect(await runActionHook('missing')).toBe(false)
        expect(warn).not.toHaveBeenCalled()
        expect(msg.error).not.toHaveBeenCalled()
    })

    it('shows a message when the hook fails and does not throw', async () => {
        registerActionHook('known', async () => {
            throw new Error('Hook broke')
        })
        await expect(runActionHook('known', {})).resolves.toBe(false)
        expect(msg.error).toHaveBeenCalledWith('Hook broke')
    })

    it('rejects invalid registrations', () => {
        expect(() => registerActionHook('', () => {})).toThrow(TypeError)
        expect(() => registerActionHook('x', 'nope' as any)).toThrow(TypeError)
    })

    it('reads the server message from a failed response', () => {
        expect(serverMessage({ response: { data: { message: ' Has children ' } } })).toBe('Has children')
        expect(serverMessage(new Error('x'))).toBe('')
        expect(serverMessage({ response: { data: { message: 5 } } })).toBe('')
    })
})
