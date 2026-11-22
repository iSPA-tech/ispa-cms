import { ref } from 'vue'

/**
 * Whether the signed-in account is the read-only demo account. The Demo project's guard marks every answer to
 * that account with `X-Demo-Read-Only`; the ajax client turns the flag on and the admin shell shows the banner. A site
 * without the Demo project never sends the header, so the flag stays off.
 */
export const DEMO_MARKER_HEADER = 'x-demo-read-only'

export const demoReadOnly = ref(false)

/**
 * Editable demo site: the Demo module marks answers to the
 * shared demo account with `X-Demo-Site: content; reset=HH:MM`; the shell shows a banner with that reset time. Empty on
 * every other site.
 */
export const DEMO_SITE_HEADER = 'x-demo-site'

export const demoSiteReset = ref('')

/**
 * Turn the flag on when a response carries the marker.
 *
 * @param {{ headers?: Record<string, unknown> } | undefined} response An axios response (or an error's response).
 */
export const noteDemoMarker = (response) => {
    const value = response?.headers?.[DEMO_MARKER_HEADER]
    if (value !== undefined && value !== null && String(value) !== '' && String(value) !== '0') {
        demoReadOnly.value = true
    }
    const site = response?.headers?.[DEMO_SITE_HEADER]
    if (site !== undefined && site !== null) {
        const reset = /(?:^|;)\s*reset=([0-9]{1,2}:[0-9]{2})/.exec(String(site))
        if (reset) {
            demoSiteReset.value = reset[1]
        }
    }
}

/** Switch the flag off (sign-out, tests). */
export const resetDemoReadOnly = () => {
    demoReadOnly.value = false
    demoSiteReset.value = ''
}
