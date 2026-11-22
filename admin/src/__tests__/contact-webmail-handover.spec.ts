import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// The legacy Contact -> Email screens hand over to Webmail only while Webmail receives contact-form mail (connected to MailHub and a contact or
// default mailbox exists), not merely because the Webmail bundle is installed (2026-11-22).
const state = vi.hoisted(() => ({
    modules: [] as { key: string; contactInbox?: unknown }[],
    active: true,
    live: true,
    account: true,
    perms: ['webmail.viewAny'] as string[],
}))
const emailCounter = vi.hoisted(() => vi.fn().mockResolvedValue({}))
const load = vi.hoisted(() => vi.fn())

vi.mock('@/config/admin-modules', () => ({
    get adminModules() {
        return state.modules
    },
}))
vi.mock('@/hooks/useActiveModules', () => ({
    isModuleActive: () => state.active,
    isModuleLiveEnabled: () => state.active && state.live,
}))
vi.mock('@/hooks/useCan', () => ({
    hasAccountData: () => state.account,
    can: (permission: string) => state.perms.includes(permission),
}))
vi.mock('@/libs', () => ({ emailCounter }))

const nav = [
    { name: 'dashboard', group: 'dashboard' },
    {
        name: 'contact-dashboard',
        group: 'contact',
        children: ['email-unread', 'email-all', 'email-compose', 'contact-manage', 'contact-field'].map((name) => ({ name })),
    },
]
const webmailBundle = () => ({ key: 'webmail', contactInbox: { module: 'Webmail', rule: 'webmail.viewAny', load } })
const shown = (items: any[]) =>
    items[1].children.filter((child: any) => !(typeof child.hidden === 'function' ? child.hidden() : child.hidden)).map((child: any) => child.name)

// Fresh module instances per test: the handover helper remembers whether it already asked in this page load.
const setup = async () => {
    const bundles = await import('@/utils/moduleBundles')
    const { default: Contact } = await import('../../../cms/Content/admin/pages/contact/contact.vue')
    return { ...bundles, Contact }
}
const mountContact = (Contact: any) => mount(Contact, { global: { stubs: { 'router-view': true } } })

describe('Contact -> Email hands over to Webmail only while Webmail receives contact-form mail', () => {
    beforeEach(() => {
        vi.resetModules()
        localStorage.clear()
        Object.assign(state, { modules: [], active: true, live: true, account: true, perms: ['webmail.viewAny'] })
        load.mockReset()
        emailCounter.mockClear()
    })

    it('keeps the legacy menu entries, pages and counter on a site without the Webmail bundle', async () => {
        const { hideLegacyEmailNav, legacyEmailRedirect, refreshLegacyEmailHandover, Contact } = await setup()

        expect(await refreshLegacyEmailHandover()).toBe(false)
        expect(hideLegacyEmailNav(nav)).toBe(nav)
        expect(legacyEmailRedirect('email-all')).toBeUndefined()
        mountContact(Contact)
        expect(emailCounter).toHaveBeenCalledTimes(1)
    })

    it('connected and receiving: hides the legacy entries, redirects the legacy pages and skips the counter', async () => {
        state.modules = [webmailBundle()]
        load.mockResolvedValue(true)
        const { hideLegacyEmailNav, legacyEmailRedirect, refreshLegacyEmailHandover, Contact } = await setup()
        const menu = hideLegacyEmailNav(nav)

        // Before the answer the legacy entries stay (they always hold the submissions).
        expect(shown(menu)).toContain('email-all')
        expect(legacyEmailRedirect('email-all')).toBeUndefined()

        expect(await refreshLegacyEmailHandover()).toBe(true)
        expect(load).toHaveBeenCalledTimes(1)
        // The same menu objects follow the answer: `hidden` is re-read on render.
        expect(shown(menu)).toEqual(['contact-manage', 'contact-field'])
        expect(legacyEmailRedirect('email-all')).toBe('/iPanel/webmail/inbox')
        expect(legacyEmailRedirect('email-view')).toBe('/iPanel/webmail/inbox')
        expect(legacyEmailRedirect('email-compose')).toBe('/iPanel/webmail/compose')
        expect(legacyEmailRedirect('contact-manage')).toBeUndefined()
        mountContact(Contact)
        expect(emailCounter).not.toHaveBeenCalled()
    })

    it('Webmail installed but not connected (or no contact mailbox): keeps the legacy entries, pages and counter', async () => {
        state.modules = [webmailBundle()]
        load.mockResolvedValue(false)
        const { hideLegacyEmailNav, legacyEmailRedirect, refreshLegacyEmailHandover, Contact } = await setup()

        expect(await refreshLegacyEmailHandover()).toBe(false)
        expect(load).toHaveBeenCalledTimes(1)
        expect(shown(hideLegacyEmailNav(nav))).toEqual(['email-unread', 'email-all', 'email-compose', 'contact-manage', 'contact-field'])
        expect(legacyEmailRedirect('email-all')).toBeUndefined()
        expect(legacyEmailRedirect('email-compose')).toBeUndefined()
        mountContact(Contact)
        expect(emailCounter).toHaveBeenCalledTimes(1)
    })

    it('asks nothing before the module list of this page load or the account is known, and keeps the last answer', async () => {
        state.modules = [webmailBundle()]
        state.live = false
        let bundles = await setup()
        expect(await bundles.refreshLegacyEmailHandover(true)).toBe(false)

        vi.resetModules()
        state.live = true
        state.account = false
        bundles = await setup()
        expect(await bundles.refreshLegacyEmailHandover(true)).toBe(false)
        expect(load).not.toHaveBeenCalled()
    })

    it('gives no handover, without a request, to an operator without Webmail access or when the module is off', async () => {
        state.modules = [webmailBundle()]
        state.perms = []
        let bundles = await setup()
        expect(await bundles.refreshLegacyEmailHandover(true)).toBe(false)

        vi.resetModules()
        state.perms = ['webmail.viewAny']
        state.active = false
        bundles = await setup()
        expect(await bundles.refreshLegacyEmailHandover(true)).toBe(false)
        expect(load).not.toHaveBeenCalled()
    })

    it('asks once per page load unless forced, keeps the last answer when the request fails, and remembers it on the next visit', async () => {
        state.modules = [webmailBundle()]
        load.mockResolvedValue(true)
        let bundles = await setup()
        expect(await bundles.refreshLegacyEmailHandover()).toBe(true)
        expect(await bundles.refreshLegacyEmailHandover()).toBe(true)
        expect(load).toHaveBeenCalledTimes(1)

        load.mockRejectedValue(new Error('offline'))
        expect(await bundles.refreshLegacyEmailHandover(true)).toBe(true)
        expect(load).toHaveBeenCalledTimes(2)

        // Next page load: the last answer applies before any request, so the menu does not flash.
        vi.resetModules()
        bundles = await setup()
        expect(bundles.legacyEmailHandedOver()).toBe(true)
        expect(bundles.legacyEmailRedirect('email-all')).toBe('/iPanel/webmail/inbox')
    })
})
