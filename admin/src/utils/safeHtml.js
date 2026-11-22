const BLOCKED_TAGS = new Set([
    'base',
    'embed',
    'form',
    'iframe',
    'link',
    'meta',
    'object',
    'script',
    'style',
])

const URI_ATTRIBUTES = new Set(['action', 'formaction', 'href', 'poster', 'src', 'xlink:href'])
const SAFE_URI_PATTERN = /^(https?:|mailto:|tel:|\/(?!\/)|#|\?|\.\/|\.\.\/)/i
const SAFE_IMAGE_DATA_URI_PATTERN = /^data:image\/(?:gif|jpeg|jpg|png|webp);base64,[a-z0-9+/]+=*$/i

const isSafeUri = (name, value) => {
    const trimmed = String(value || '').trim()

    if (!trimmed) {
        return true
    }

    if (name === 'src' && SAFE_IMAGE_DATA_URI_PATTERN.test(trimmed)) {
        return true
    }

    return SAFE_URI_PATTERN.test(trimmed)
}

export const sanitizeHtml = (html = '') => {
    if (html === null || html === undefined) {
        return ''
    }

    if (typeof document === 'undefined') {
        return String(html)
            .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
            .replace(/\son[a-z]+\s*=\s*(['"]).*?\1/gi, '')
            .replace(/\son[a-z]+\s*=\s*[^\s>]+/gi, '')
    }

    const template = document.createElement('template')
    template.innerHTML = String(html)

    const nodes = template.content.querySelectorAll('*')

    nodes.forEach((node) => {
        const tagName = node.tagName.toLowerCase()

        if (BLOCKED_TAGS.has(tagName)) {
            node.remove()
            return
        }

        Array.from(node.attributes).forEach((attribute) => {
            const name = attribute.name.toLowerCase()
            const value = attribute.value

            if (name.startsWith('on') || name === 'style' || name === 'srcdoc') {
                node.removeAttribute(attribute.name)
                return
            }

            if (URI_ATTRIBUTES.has(name) && !isSafeUri(name, value)) {
                node.removeAttribute(attribute.name)
            }
        })
    })

    return template.innerHTML
}
