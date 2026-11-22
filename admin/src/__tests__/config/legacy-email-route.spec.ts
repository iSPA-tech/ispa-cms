import { beforeEach, describe, expect, it, vi } from 'vitest'

// Router guard of the legacy Contact -> Email pages: redirect to Webmail only once Webmail is known to receive contact-form mail, decided
// before the page renders; other pages are never delayed by the question.
const beforeEachMock = vi.fn()
const refreshLegacyEmailHandover = vi.hoisted(() => vi.fn())

vi.mock('vue-router', () => ({
    createRouter: vi.fn((options) => ({ beforeEach: beforeEachMock, options })),
    createWebHistory: vi.fn(() => ({})),
}))
vi.mock('@/config', () => ({ basePath: '/iPanel/' }))
vi.mock('@/utils/moduleBundles', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/utils/moduleBundles')>()),
    refreshLegacyEmailHandover,
}))

const guard = async () => {
    await import('../../config/route')
    return beforeEachMock.mock.calls[0][0]
}

describe('legacy Contact -> Email route guard', () => {
    beforeEach(() => {
        vi.resetModules()
        beforeEachMock.mockClear()
        refreshLegacyEmailHandover.mockReset()
    })

    it('connected and receiving: sends the legacy pages to Webmail before they render', async () => {
        refreshLegacyEmailHandover.mockResolvedValue(true)
        const decide = await guard()

        await expect(decide({ name: 'email-all', meta: { rule: 'email.viewAny' } })).resolves.toEqual({ path: '/iPanel/webmail/inbox' })
        await expect(decide({ name: 'email-view', meta: {} })).resolves.toEqual({ path: '/iPanel/webmail/inbox' })
        await expect(decide({ name: 'email-compose', meta: {} })).resolves.toEqual({ path: '/iPanel/webmail/compose' })
        expect(refreshLegacyEmailHandover).toHaveBeenCalledWith(true)
    })

    it('not connected (or unknown): opens the legacy page through the normal checks', async () => {
        refreshLegacyEmailHandover.mockResolvedValue(false)
        const decide = await guard()

        await expect(decide({ name: 'email-all', meta: {} })).resolves.toBe(true)
        await expect(decide({ name: 'email-unread', meta: {} })).resolves.toBe(true)
    })

    it('answers other pages at once and only starts the question in the background', async () => {
        refreshLegacyEmailHandover.mockReturnValue(new Promise(() => {}))
        const decide = await guard()

        expect(decide({ name: 'contact-manage', meta: {} })).toBe(true)
        expect(refreshLegacyEmailHandover).toHaveBeenCalledWith()
    })
})
