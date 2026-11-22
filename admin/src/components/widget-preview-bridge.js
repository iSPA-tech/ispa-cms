// Parent-side helpers for the editable widget preview iframe (srcdoc, sandbox="allow-scripts").
import { FRAME_SCRIPT, FRAME_STYLE } from './widget-preview-frame-script'

export const BRIDGE_SOURCE = 'ispa-widget-edit'

const MANIFEST_RE = /<script\b[^>]*\bid=["']ispa-edit-fields["'][^>]*>([\s\S]*?)<\/script>/gi

// Escape so the injected JSON/script cannot close its own tag.
function safeInline(text) {
    return text.replace(/<\/(script|style)/gi, '<\\/$1').replace(/<!--/g, '<\\!--')
}

export function buildEditableSrcdoc(html, labels, strings) {
    // The server appends its manifest last; an earlier lookalike may come from widget content.
    const match = [...html.matchAll(MANIFEST_RE)].pop()
    if (!match) return { srcdoc: html, onPreview: [], offPreview: [], repeaters: {} }

    let onPreview = []
    let offPreview = []
    let repeaters = {}
    try {
        const data = JSON.parse(match[1])
        onPreview = Array.isArray(data.on_preview) ? data.on_preview : []
        offPreview = Array.isArray(data.off_preview) ? data.off_preview : []
        const r = data.repeaters
        repeaters = r && typeof r === 'object' && !Array.isArray(r) ? r : {}
    } catch {
        // Malformed manifest: treat as no editable fields.
    }

    const stripped = html.slice(0, match.index) + html.slice(match.index + match[0].length)
    const labelsJson = JSON.stringify(labels || {}).replace(/</g, '\\u003c')
    const stringsJson = JSON.stringify(strings || {}).replace(/</g, '\\u003c')
    const repeatersJson = JSON.stringify(repeaters).replace(/</g, '\\u003c')
    const injection =
        `<style>${safeInline(FRAME_STYLE)}</style>` +
        `<script>window.__ISPA_LABELS__=${labelsJson};window.__ISPA_STRINGS__=${stringsJson};window.__ISPA_REPEATERS__=${repeatersJson};${safeInline(FRAME_SCRIPT)}</script>`

    const idx = stripped.toLowerCase().lastIndexOf('</body>')
    const srcdoc =
        idx === -1 ? stripped + injection : stripped.slice(0, idx) + injection + stripped.slice(idx)
    return { srcdoc, onPreview, offPreview, repeaters }
}

export function isBridgeMessage(event, frameWindow) {
    return !!frameWindow && event.source === frameWindow && !!event.data && event.data.source === BRIDGE_SOURCE
}
