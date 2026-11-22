/**
 * Phone-sized screen check behind the global `$isMobile()`: drawers and modals open full width below 768px, the same
 * narrow breakpoint the admin uses elsewhere (i-content-widget-v2). It replaced the vue-mobile-detection plugin
 * (2026-11-22), which read the user agent and shipped no licence in its npm package; a narrow desktop window now gets
 * the full-width drawer too. Read on each call, like the plugin, so it does not follow later resizes.
 */
export const MOBILE_QUERY = '(max-width: 767px)'

export function isMobile() {
    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia(MOBILE_QUERY).matches
}

export default {
    install(app) {
        app.config.globalProperties.$isMobile = isMobile
    },
}
