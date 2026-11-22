import { useStorage } from '@vueuse/core'
import { adminModules } from '@/config/admin-modules'
import { buildVersion } from '@/config/base'
import { CACHE_KEY_PREFIX } from '@/config/constants'
import { can, hasAccountData } from '@/hooks/useCan'
import { isModuleActive, isModuleLiveEnabled } from '@/hooks/useActiveModules'

/**
 * True when the admin bundle with this key is registered for the current site.
 * The registry (`adminModules`) only contains bundles whose module.js exists in
 * the site's build, so a site without the module never reports it as active.
 */
export const isModuleBundleActive = (key) =>
    adminModules.some((module) => module?.key === key)

export const isWebmailActive = () => isModuleBundleActive('webmail')

const LEGACY_EMAIL_NAV = ['email-unread', 'email-all', 'email-sent', 'email-compose']

/** Legacy Contact -> Email route names mapped to their Webmail destination. */
const LEGACY_EMAIL_REDIRECTS = {
    'email-all': '/iPanel/webmail/inbox',
    'email-sent': '/iPanel/webmail/inbox',
    'email-unread': '/iPanel/webmail/inbox',
    'email-view': '/iPanel/webmail/inbox',
    'email-compose': '/iPanel/webmail/compose',
}

/**
 * Whether contact-form mail is read in Webmail on this site (connected to MailHub and a contact or default mailbox receives it): only then
 * do the legacy Contact -> Email screens hand over to Webmail. true / false = the last answer, kept in the browser so the sidebar does not
 * change on the next visit; null = not known yet. Unknown keeps the legacy screens: they always hold the submissions, so showing them
 * wrongly costs little, while hiding them wrongly leaves the operator nowhere to read them.
 */
// JSON serializer: with a null default the plain one would read the stored answer back as the string 'true'.
const handoverRef = useStorage(`${CACHE_KEY_PREFIX}legacy-email-handover-${buildVersion}`, null, undefined, {
    serializer: { read: (raw) => JSON.parse(raw), write: (value) => JSON.stringify(value) },
})
let answeredThisLoad = false
let pending = null

/** The Webmail bundle's `contactInbox` hook ({ module, rule, load }); undefined when the site has no such bundle. */
const contactInboxHook = () => adminModules.find((module) => module?.key === 'webmail')?.contactInbox

/** True once Webmail is known to receive contact-form mail (reactive: the sidebar re-reads it when the answer arrives). */
export const legacyEmailHandedOver = () => handoverRef.value === true

/** True for the legacy Contact -> Email routes that move to Webmail. */
export const isLegacyEmailRoute = (routeName) =>
    Object.prototype.hasOwnProperty.call(LEGACY_EMAIL_REDIRECTS, routeName)

/**
 * Asks the Webmail bundle whether contact-form mail lands in its inbox and remembers the answer; resolves to the handover state.
 * Asked once per page load unless `force` (a legacy page about to open asks again; Webmail caches its status for 30 s).
 * No hook, the Webmail module off, or an operator without the hook's permission: no handover, and no request. While the module list of
 * this page load or the account is not known yet, or when the request fails, the last answer stands.
 */
export const refreshLegacyEmailHandover = (force = false) => {
    const hook = contactInboxHook()
    if (typeof hook?.load !== 'function' || !isModuleActive(hook.module) || (hasAccountData() && hook.rule && !can(hook.rule))) {
        handoverRef.value = false
        return Promise.resolve(false)
    }
    if (!isModuleLiveEnabled(hook.module) || !hasAccountData() || (answeredThisLoad && !force)) {
        return Promise.resolve(legacyEmailHandedOver())
    }
    pending ??= Promise.resolve()
        .then(() => hook.load())
        .then((answer) => {
            handoverRef.value = answer === true
            answeredThisLoad = true
        })
        .catch(() => {})
        .then(() => {
            pending = null
            return legacyEmailHandedOver()
        })
    return pending
}

/** Webmail destination for a legacy email route, or undefined when it must stay. */
export const legacyEmailRedirect = (routeName, handedOver = legacyEmailHandedOver()) =>
    handedOver ? LEGACY_EMAIL_REDIRECTS[routeName] : undefined

/**
 * Marks the legacy email entries of the Contact menu group `hidden` while Webmail receives contact-form mail. The entries stay in the list
 * (the menu export is static); App.vue re-reads `hidden` on every render, so they appear or go when the answer arrives. Sites without the
 * Webmail bundle are left untouched.
 */
export const hideLegacyEmailNav = (items, active = isWebmailActive()) =>
    active
        ? items.map((item) =>
              item.group === 'contact' && Array.isArray(item.children)
                  ? {
                        ...item,
                        children: item.children.map((child) =>
                            LEGACY_EMAIL_NAV.includes(child?.name)
                                ? { ...child, hidden: () => legacyEmailHandedOver() || (typeof child.hidden === 'function' ? !!child.hidden() : !!child.hidden) }
                                : child
                        ),
                    }
                  : item
          )
        : items
