import { describe, expect, it } from 'vitest'
import { sanitizeHtml } from '../../src/utils/safeHtml'

describe('utils:sanitizeHtml', () => {
    it('removes executable tags and inline event handlers', () => {
        const html = sanitizeHtml(
            '<p onclick="alert(1)" style="color:red">Safe</p><script>alert(1)</script>'
        )

        expect(html).toBe('<p>Safe</p>')
    })

    it('removes unsafe urls while keeping allowed links and image data', () => {
        const html = sanitizeHtml(
            '<a href="javascript:alert(1)">Bad</a><a href="https://example.com">Good</a><img src="data:image/png;base64,abcd" />'
        )

        expect(html).toContain('<a>Bad</a>')
        expect(html).toContain('<a href="https://example.com">Good</a>')
        expect(html).toContain('<img src="data:image/png;base64,abcd">')
    })
})
