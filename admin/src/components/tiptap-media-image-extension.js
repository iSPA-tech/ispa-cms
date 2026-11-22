import { Node, mergeAttributes } from '@tiptap/core'

export const mediaImageAlignments = ['left', 'right', 'center']
export const mediaImageSizes = ['small', 'medium', 'large', 'full']
export const mediaImageWidthLimits = {
    minPx: 120,
    maxPx: 900,
    minPercent: 10,
    maxPercent: 100,
}

const normalizeOption = (value, allowedValues, fallback) => {
    return allowedValues.includes(value) ? value : fallback
}

const clampNumber = (value, min, max) => Math.min(Math.max(value, min), max)

const formatNumber = (value) => {
    const rounded = Math.round(value * 100) / 100

    return Number.isInteger(rounded) ? String(rounded) : String(rounded).replace(/\.?0+$/, '')
}

const convertLengthToPixels = (amount, unit) => {
    const value = Number(amount)

    if (!Number.isFinite(value) || value <= 0) {
        return null
    }

    switch (unit) {
        case 'px':
        case '':
            return value
        case 'pt':
            return value * (96 / 72)
        case 'in':
            return value * 96
        case 'cm':
            return value * (96 / 2.54)
        case 'mm':
            return value * (96 / 25.4)
        default:
            return null
    }
}

export const normalizeMediaImageWidth = (value = '') => {
    if (value === null || value === undefined) {
        return ''
    }

    const raw = String(value).trim().toLowerCase().replace(/\s+/g, '')

    if (!raw || raw === 'auto') {
        return ''
    }

    const percentMatch = raw.match(/^(\d+(?:\.\d+)?)%$/)
    if (percentMatch) {
        const percent = clampNumber(
            Number(percentMatch[1]),
            mediaImageWidthLimits.minPercent,
            mediaImageWidthLimits.maxPercent
        )

        return `${formatNumber(percent)}%`
    }

    const lengthMatch = raw.match(/^(\d+(?:\.\d+)?)(px|pt|in|cm|mm)?$/)
    if (lengthMatch) {
        const pixels = convertLengthToPixels(lengthMatch[1], lengthMatch[2] || '')

        if (!pixels) {
            return ''
        }

        const width = clampNumber(
            Math.round(pixels),
            mediaImageWidthLimits.minPx,
            mediaImageWidthLimits.maxPx
        )

        return `${width}px`
    }

    return ''
}

const normalizeUploadSource = (url = '') => {
    if (!url || typeof url !== 'string') {
        return ''
    }

    if (/^(https?:)?\/\//i.test(url) || url.startsWith('data:')) {
        return url
    }

    return `/${url.replace(/^\/+/, '')}`
}

export const normalizeMediaImageAttributes = (attributes = {}) => {
    return {
        src: normalizeUploadSource(attributes.src),
        uploadId: attributes.uploadId ? String(attributes.uploadId) : '',
        align: normalizeOption(attributes.align, mediaImageAlignments, 'center'),
        size: normalizeOption(attributes.size, mediaImageSizes, 'medium'),
        width: normalizeMediaImageWidth(attributes.width),
        alt: attributes.alt || '',
        caption: attributes.caption || '',
    }
}

export const createMediaImageAttributes = (upload = {}, options = {}) => {
    return normalizeMediaImageAttributes({
        src: upload.url || '',
        uploadId: upload.id || '',
        align: options.align,
        size: options.size,
        width: options.width,
        alt: options.alt || upload.alias_name || upload.name || '',
        caption: options.caption || '',
    })
}

const parseClassOption = (element, prefix, allowedValues, fallback) => {
    const className = element?.getAttribute('class') || ''
    const match = className
        .split(/\s+/)
        .map((item) => (item.startsWith(prefix) ? item.replace(prefix, '') : null))
        .find((item) => item && allowedValues.includes(item))

    return normalizeOption(match, allowedValues, fallback)
}

const getImageElement = (element) => {
    if (element?.tagName?.toLowerCase() === 'img') {
        return element
    }

    return element?.querySelector?.('img') || null
}

const getInlineStyleValue = (element, property) => {
    const styleValue = element?.style?.getPropertyValue?.(property)
    if (styleValue) {
        return styleValue
    }

    const styleAttr = element?.getAttribute?.('style') || ''
    const escapedProperty = property.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    const match = styleAttr.match(
        new RegExp(`(?:^|;)\\s*${escapedProperty}\\s*:\\s*([^;]+)`, 'i')
    )

    return match?.[1]?.trim() || ''
}

const getMediaImageWidth = (element) => {
    return normalizeMediaImageWidth(
        element?.getAttribute?.('data-media-width') ||
            getInlineStyleValue(element, '--content-media-width') ||
            getInlineStyleValue(element, 'width') ||
            element?.getAttribute?.('width') ||
            ''
    )
}

const getParsedMediaImageWidth = (element) => {
    const image = getImageElement(element)

    return getMediaImageWidth(element) || getMediaImageWidth(image)
}

const isBlockedImageSource = (src) => {
    const value = String(src || '').trim()

    return /^file:/i.test(value) || /^[a-z]:[\\/]/i.test(value) || /^\\\\/.test(value)
}

const isAllowedImageSource = (src) => {
    const value = String(src || '').trim()

    if (!value || isBlockedImageSource(value)) {
        return false
    }

    return (
        /^(https?:)?\/\//i.test(value) ||
        value.startsWith('/') ||
        value.startsWith('data:image/') ||
        /^[a-z0-9_.-]+\/[^<>]+/i.test(value)
    )
}

const buildMediaImageClassName = (attributes) => {
    return [
        'content-media',
        `content-media--${attributes.align}`,
        `content-media--${attributes.size}`,
        attributes.width ? 'content-media--custom-width' : '',
    ]
        .filter(Boolean)
        .join(' ')
}

const applyMediaImageDataset = (target, attributes) => {
    target.setAttribute('data-media-align', attributes.align)
    target.setAttribute('data-media-size', attributes.size)

    if (attributes.uploadId) {
        target.setAttribute('data-upload-id', attributes.uploadId)
    }

    if (attributes.width) {
        target.setAttribute('data-media-width', attributes.width)
    }
}

const unwrapMediaOnlyParents = (doc) => {
    doc.body.querySelectorAll('span, p').forEach((element) => {
        const media = Array.from(element.children).find((child) =>
            child.matches('figure.content-media')
        )
        if (!media) {
            return
        }

        const onlyMedia = Array.from(element.childNodes).every((node) => {
            if (node.nodeType === 3) {
                return !node.textContent.replace(/\u00a0/g, ' ').trim()
            }

            return node === media
        })

        if (onlyMedia) {
            element.replaceWith(media)
        }
    })
}

export const convertHtmlImagesToMediaFigures = (html = '') => {
    if (!html || typeof html !== 'string' || typeof DOMParser === 'undefined') {
        return html
    }

    try {
        const doc = new DOMParser().parseFromString(html, 'text/html')

        doc.body.querySelectorAll('img[src]').forEach((image) => {
            if (image.closest('figure.content-media')) {
                return
            }

            const rawSrc = image.getAttribute('src') || ''
            if (!isAllowedImageSource(rawSrc)) {
                image.remove()
                return
            }

            const attributes = normalizeMediaImageAttributes({
                src: rawSrc,
                align: image.getAttribute('data-media-align') || 'center',
                size: image.getAttribute('data-media-size') || 'medium',
                width: getMediaImageWidth(image),
                alt: image.getAttribute('alt') || image.getAttribute('title') || '',
                caption: image.getAttribute('data-media-caption') || '',
            })

            const figure = doc.createElement('figure')
            figure.setAttribute('class', buildMediaImageClassName(attributes))
            applyMediaImageDataset(figure, attributes)

            if (attributes.width) {
                figure.setAttribute('style', `--content-media-width: ${attributes.width}`)
            }

            const cleanImage = doc.createElement('img')
            cleanImage.setAttribute('src', attributes.src)
            cleanImage.setAttribute('alt', attributes.alt)
            cleanImage.setAttribute('loading', 'lazy')
            applyMediaImageDataset(cleanImage, attributes)

            figure.appendChild(cleanImage)

            if (attributes.caption) {
                const figcaption = doc.createElement('figcaption')
                figcaption.textContent = attributes.caption
                figure.appendChild(figcaption)
            }

            image.replaceWith(figure)
        })

        unwrapMediaOnlyParents(doc)

        return doc.body.innerHTML.trim()
    } catch {
        return html
    }
}

export const MediaImage = Node.create({
    name: 'mediaImage',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            src: {
                default: '',
                parseHTML: (element) => getImageElement(element)?.getAttribute('src') || '',
            },
            uploadId: {
                default: '',
                parseHTML: (element) =>
                    getImageElement(element)?.getAttribute('data-upload-id') || '',
            },
            align: {
                default: 'center',
                parseHTML: (element) =>
                    normalizeOption(
                        element.getAttribute('data-media-align') ||
                            getImageElement(element)?.getAttribute('data-media-align') ||
                            parseClassOption(
                                element,
                                'content-media--',
                                mediaImageAlignments,
                                'center'
                            ),
                        mediaImageAlignments,
                        'center'
                    ),
            },
            size: {
                default: 'medium',
                parseHTML: (element) =>
                    normalizeOption(
                        element.getAttribute('data-media-size') ||
                            getImageElement(element)?.getAttribute('data-media-size') ||
                            parseClassOption(element, 'content-media--', mediaImageSizes, 'medium'),
                        mediaImageSizes,
                        'medium'
                    ),
            },
            width: {
                default: '',
                parseHTML: (element) => getParsedMediaImageWidth(element),
            },
            alt: {
                default: '',
                parseHTML: (element) => getImageElement(element)?.getAttribute('alt') || '',
            },
            caption: {
                default: '',
                parseHTML: (element) =>
                    element.querySelector?.('figcaption')?.textContent?.trim() || '',
            },
        }
    },

    parseHTML() {
        return [
            { tag: 'figure.content-media' },
            { tag: 'img[data-upload-id]' },
            { tag: 'img[src]' },
        ]
    },

    renderHTML({ HTMLAttributes }) {
        const attributes = normalizeMediaImageAttributes(HTMLAttributes)
        const figureAttributes = {
            class: buildMediaImageClassName(attributes),
            'data-media-align': attributes.align,
            'data-media-size': attributes.size,
        }

        if (attributes.uploadId) {
            figureAttributes['data-upload-id'] = attributes.uploadId
        }

        if (attributes.width) {
            figureAttributes['data-media-width'] = attributes.width
            figureAttributes.style = `--content-media-width: ${attributes.width}`
        }

        const imageAttributes = {
            src: attributes.src,
            alt: attributes.alt,
            loading: 'lazy',
            'data-media-align': attributes.align,
            'data-media-size': attributes.size,
        }

        if (attributes.uploadId) {
            imageAttributes['data-upload-id'] = attributes.uploadId
        }

        if (attributes.width) {
            imageAttributes['data-media-width'] = attributes.width
        }

        const children = [
            'figure',
            mergeAttributes(figureAttributes),
            ['img', mergeAttributes(imageAttributes)],
        ]

        if (attributes.caption) {
            children.push(['figcaption', {}, attributes.caption])
        }

        return children
    },

    addCommands() {
        return {
            setMediaImage:
                (attributes) =>
                ({ commands }) => {
                    return commands.insertContent({
                        type: this.name,
                        attrs: normalizeMediaImageAttributes(attributes),
                    })
                },
            updateMediaImage:
                (attributes) =>
                ({ commands }) => {
                    return commands.updateAttributes(
                        this.name,
                        normalizeMediaImageAttributes(attributes)
                    )
                },
        }
    },
})

export default MediaImage
