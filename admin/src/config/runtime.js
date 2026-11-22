/**
 * Run-time site values for the admin.
 *
 * Order per field: `window.__ISPA_ADMIN__` (written by `cms:admin-config` into /iPanel/config.js), then the
 * `VITE_*` build value (keeps `vite dev` and older builds working), then `window.location`.
 * Everything is read at call time so tests can stub the window, the env and the location.
 */

const HUB_URL_DEFAULT = 'https://ispa.tech'
const DOCS_URL_DEFAULT = 'https://docs.ispa.tech'

const isSet = (value) => value !== undefined && value !== null

const asBoolean = (value) => {
    if (typeof value === 'boolean') {
        return value
    }

    if (typeof value === 'string') {
        const normalized = value.trim().toLowerCase()

        if (['true', '1', 'yes', 'on'].includes(normalized)) {
            return true
        }

        if (['false', '0', 'no', 'off'].includes(normalized)) {
            return false
        }
    }

    return undefined
}

// Exactly one trailing slash; a path prefix (`https://example.com/cms`) is kept.
const withTrailingSlash = (value) => String(value).replace(/\/+$/, '') + '/'

export function runtimeConfig() {
    const injected = globalThis.__ISPA_ADMIN__
    const win = injected && typeof injected === 'object' ? injected : {}
    const loc = globalThis.location || {}

    const viteProtocol = import.meta.env.VITE_PROTOCOL
    const viteBaseUrl = import.meta.env.VITE_BASE_URL
    const viteDomain = import.meta.env.VITE_DOMAIN
    const viteAuthKey = import.meta.env.VITE_APP_ADMIN_AUTH_KEY
    const viteHubUrl = import.meta.env.VITE_CMS_API_URL
    const viteVersion = import.meta.env.VITE_VERSION
    const viteDocsUrl = import.meta.env.VITE_DOCS_URL

    let url
    if (win.url) {
        url = win.url
    } else if (viteProtocol && viteBaseUrl) {
        url = `${viteProtocol}://${viteBaseUrl}`
    } else {
        url = loc.origin || ''
    }

    let secureCookie = asBoolean(win.secureCookie)
    if (secureCookie === undefined) {
        secureCookie = asBoolean(import.meta.env.VITE_SECURE_COOKIE)
    }
    if (secureCookie === undefined) {
        secureCookie = loc.protocol === 'https:'
    }

    return {
        url: withTrailingSlash(url),
        cookieDomain: win.cookieDomain || viteDomain || loc.hostname || '',
        secureCookie,
        // An empty string from config.js is a real value (key not set): do not fall back to the build value.
        adminAuthKey: isSet(win.adminAuthKey) ? String(win.adminAuthKey) : (viteAuthKey ?? ''),
        hubUrl: win.hubUrl || viteHubUrl || HUB_URL_DEFAULT,
        version: win.version || viteVersion || '',
        // Usage docs: <docsUrl>/<vi|en>/docs (config.js from ISPA_DOCS_URL).
        docsUrl: String(win.docsUrl || viteDocsUrl || DOCS_URL_DEFAULT).replace(/\/+$/, ''),
    }
}
