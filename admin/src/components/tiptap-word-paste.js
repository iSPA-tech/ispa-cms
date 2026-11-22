import {
    convertHtmlImagesToMediaFigures,
    normalizeMediaImageWidth,
} from '@/components/tiptap-media-image-extension'

export const emptyParagraphRegex = /<p(?:\s[^>]*)?>\s*(?:&nbsp;|&#160;)?\s*(<br\s*\/?>)?\s*<\/p>/gi
const wordHtmlRegex = /class=["']?Mso|style=["'][^"']*mso-|w:WordDocument|<o:p>/i
const wordListHtmlRegex = /MsoListParagraph|mso-list\s*:|mso-list\s*:\s*Ignore/i
const standaloneListMarkerRegex = /^\s*(?:\d+[.)]|[a-zA-Z][.)]|[ivxlcdmIVXLCDM]+[.)]|[•▪◦·o-])\s*$/
const listMarkerPrefixRegex =
    /^\s*(?:\d+[.)]|[a-zA-Z][.)]|[ivxlcdmIVXLCDM]+[.)]|[•▪◦·o-])(?:\s+|\t+)/
const listLineRegex = /^\s*((?:\d+|[a-zA-Z]|[ivxlcdmIVXLCDM]+)[.)]|[•▪◦·o-])(?:\s+|\t+)(.*?)\s*$/
const numericMarkerRegex = /^(\d+)[.)]$/
const alphaMarkerRegex = /^([a-zA-Z])[.)]$/
const romanMarkerRegex = /^([ivxlcdmIVXLCDM]{2,})[.)]$/
const bulletMarkerRegex = /^[•▪◦·o-]$/
const continuationPunctuationRegex = /[,;:]$/
const sentenceEndRegex = /[.!?…]$/
const allowedStyleProps = new Set([
    'text-align',
    'font-weight',
    'font-style',
    'text-decoration',
    'font-size',
    'color',
    'background-color',
    'background',
    'font-family',
    'margin',
    'margin-left',
    'padding-left',
    'text-indent',
    'line-height',
    'list-style-type',
    'list-style-position',
])

const trimPastedListParagraphWhitespace = (paragraph) => {
    const textNodes = []

    const collectTextNodes = (node) => {
        node.childNodes.forEach((child) => {
            if (child.nodeType === 3) {
                textNodes.push(child)
                return
            }

            if (child.nodeType === 1 && !['UL', 'OL'].includes(child.tagName)) {
                collectTextNodes(child)
            }
        })
    }

    collectTextNodes(paragraph)

    let changed = false
    let leading = true

    textNodes.forEach((node) => {
        if (!leading) {
            return
        }

        const value = node.nodeValue || ''
        const trimmed = value.replace(/^\s+/, '')

        if (trimmed !== value) {
            node.nodeValue = trimmed
            changed = true
        }

        if (!node.nodeValue) {
            node.remove()
            changed = true
            return
        }

        leading = false
    })

    const remainingTextNodes = textNodes.filter((node) => node.parentNode)
    let trailing = true

    for (let index = remainingTextNodes.length - 1; index >= 0 && trailing; index -= 1) {
        const node = remainingTextNodes[index]
        const value = node.nodeValue || ''
        const trimmed = value.replace(/\s+$/, '')

        if (trimmed !== value) {
            node.nodeValue = trimmed
            changed = true
        }

        if (!node.nodeValue) {
            node.remove()
            changed = true
            continue
        }

        trailing = false
    }

    return changed
}

// Pasted HTML can carry spaces/newlines at list item paragraph edges. With
// `preserveWhitespace: 'full'`, those spaces can make ProseMirror render its internal
// `ProseMirror-trailingBreak` as a visible extra line in the editor.
export const normalizePastedListWhitespace = (html = '') => {
    if (!html || typeof html !== 'string' || typeof DOMParser === 'undefined') {
        return html
    }

    const parser = new DOMParser()
    const doc = parser.parseFromString(html, 'text/html')
    let changed = false

    doc.body.querySelectorAll('li').forEach((listItem) => {
        const paragraph = Array.from(listItem.children).find((child) => child.tagName === 'P')
        const target = paragraph || listItem

        if (trimPastedListParagraphWhitespace(target)) {
            changed = true
        }
    })

    return changed ? doc.body.innerHTML : html
}

const isLowerCaseStart = (value = '') => {
    const first = value.trim().match(/^\p{L}/u)?.[0]

    return Boolean(first && first === first.toLocaleLowerCase())
}

const shouldJoinWrappedLine = (current = '', next = '') => {
    const left = current.trim()
    const right = next.trim()

    if (
        !left ||
        !right ||
        standaloneListMarkerRegex.test(right) ||
        listMarkerPrefixRegex.test(right)
    ) {
        return false
    }

    if (standaloneListMarkerRegex.test(left)) {
        return true
    }

    if (continuationPunctuationRegex.test(left)) {
        return true
    }

    if (!sentenceEndRegex.test(left) && isLowerCaseStart(right)) {
        return true
    }

    if (listMarkerPrefixRegex.test(left) && !sentenceEndRegex.test(left)) {
        return true
    }

    return false
}

const normalizePlainTextBlock = (block = '') => {
    const lines = block
        .split('\n')
        .map((line) =>
            line
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
        )
        .filter(Boolean)

    const normalized = []

    lines.forEach((line) => {
        if (!normalized.length) {
            normalized.push(line)
            return
        }

        const previous = normalized[normalized.length - 1]

        if (shouldJoinWrappedLine(previous, line)) {
            normalized[normalized.length - 1] = `${previous} ${line}`.replace(/\s+/g, ' ').trim()
            return
        }

        normalized.push(line)
    })

    return normalized.join('\n')
}

const normalizePlainTextBlocks = (text = '') =>
    text
        .replace(/\r\n?/g, '\n')
        .split(/\n\s*\n+/)
        .map(normalizePlainTextBlock)
        .filter(Boolean)

const isListItemContinuation = (current = '', next = '') => {
    const left = current.trim()
    const right = next.trim()

    if (!left || !right) {
        return false
    }

    if (standaloneListMarkerRegex.test(left)) {
        return true
    }

    return listMarkerPrefixRegex.test(left) && shouldJoinWrappedLine(left, right)
}

export const normalizePlainText = (text = '') => {
    if (!text || typeof text !== 'string') {
        return text
    }

    return normalizePlainTextBlocks(text)
        .reduce((blocks, block) => {
            const previous = blocks[blocks.length - 1]

            if (isListItemContinuation(previous, block)) {
                blocks[blocks.length - 1] = normalizePlainTextBlock(`${previous}\n${block}`)
                return blocks
            }

            blocks.push(block)
            return blocks
        }, [])
        .join('\n\n')
}

const escapeHtml = (value = '') =>
    value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')

export const plainTextToParagraphHtml = (text = '') => {
    const normalized = normalizePlainText(text)

    if (!normalized) {
        return ''
    }

    return normalized
        .split(/\n+/)
        .map((line) => line.trim())
        .filter(Boolean)
        .map((line) => `<p>${escapeHtml(line)}</p>`)
        .join('')
}

const listMetaFromMarker = (marker = '') => {
    const numeric = marker.match(numericMarkerRegex)

    if (numeric) {
        const start = Number.parseInt(numeric[1], 10) || 1

        return {
            tag: 'ol',
            key: 'ol:1',
            attrs: start > 1 ? ` start="${start}"` : '',
        }
    }

    const alpha = marker.match(alphaMarkerRegex)

    if (alpha) {
        const value = alpha[1]
        const start = value.toLocaleLowerCase().charCodeAt(0) - 96

        return {
            tag: 'ol',
            key: `ol:${value === value.toLocaleUpperCase() ? 'A' : 'a'}`,
            attrs: [
                ` type="${value === value.toLocaleUpperCase() ? 'A' : 'a'}"`,
                start > 1 ? ` start="${start}"` : '',
            ].join(''),
        }
    }

    const roman = marker.match(romanMarkerRegex)

    if (roman) {
        const value = roman[1]

        return {
            tag: 'ol',
            key: `ol:${value === value.toLocaleUpperCase() ? 'I' : 'i'}`,
            attrs: ` type="${value === value.toLocaleUpperCase() ? 'I' : 'i'}"`,
        }
    }

    if (bulletMarkerRegex.test(marker)) {
        return {
            tag: 'ul',
            key: 'ul',
            attrs: '',
        }
    }

    return null
}

const parseListLine = (line = '') => {
    const match = line.match(listLineRegex)

    if (!match) {
        return null
    }

    const [, marker, content] = match
    const meta = listMetaFromMarker(marker)

    if (!meta || !content.trim()) {
        return null
    }

    return {
        ...meta,
        content: content.trim(),
    }
}

export const plainTextToListAwareHtml = (text = '') => {
    const normalized = normalizePlainText(text)

    if (!normalized) {
        return ''
    }

    const blocks = []
    let currentList = null

    const closeList = () => {
        currentList = null
    }

    normalized
        .split(/\n+/)
        .map((line) => line.trim())
        .filter(Boolean)
        .forEach((line) => {
            const listLine = parseListLine(line)

            if (!listLine) {
                closeList()
                blocks.push({ tag: 'p', content: line })
                return
            }

            if (!currentList || currentList.key !== listLine.key) {
                currentList = {
                    tag: listLine.tag,
                    key: listLine.key,
                    attrs: listLine.attrs,
                    items: [],
                }
                blocks.push(currentList)
            }

            currentList.items.push(listLine.content)
        })

    return blocks
        .map((block) => {
            if (block.tag === 'p') {
                return `<p>${escapeHtml(block.content)}</p>`
            }

            const items = block.items
                .map((item) => item.trim())
                .filter(Boolean)
                .map((item) => `<li>${escapeHtml(item)}</li>`)
                .join('')

            return items ? `<${block.tag}${block.attrs}>${items}</${block.tag}>` : ''
        })
        .join('')
}

export const normalizeInlineStyles = (html = '') => {
    if (!html || typeof html !== 'string') {
        return html
    }

    try {
        const preFixedHtml = html.replace(/font-family:\s*"([^"]+)"/gi, "font-family:'$1'")
        const parser = new DOMParser()
        const doc = parser.parseFromString(preFixedHtml, 'text/html')

        doc.body.querySelectorAll('[style]').forEach((el) => {
            const rawStyle = el.getAttribute('style') || ''
            if (!rawStyle) return

            // MathML inside a math block is regenerated from data-latex on every render and keeps
            // Temml's own style strings; rewriting them here would make the model value differ
            // from editor.getHTML() and re-sync the document on every keystroke.
            if (el.closest('[data-type="math-block"]')) return

            const rules = rawStyle
                .split(';')
                .map((r) => r.trim())
                .filter(Boolean)
                .map((rule) => {
                    const [prop, ...rest] = rule.split(':')
                    if (!prop || rest.length === 0) return null

                    const key = prop.trim().toLowerCase()
                    let value = rest.join(':').trim()

                    if (key === 'font-family') {
                        value = value.replace(/^['"]+|['"]+$/g, '')
                    }

                    if (key === 'font-size') {
                        value = value.replace(/\s+/g, '')
                    }

                    return value ? `${key}: ${value}` : null
                })
                .filter(Boolean)

            el.setAttribute('style', rules.join('; '))
        })

        return doc.body.innerHTML
    } catch (e) {
        return html
    }
}

export const isWordHtml = (html) => wordHtmlRegex.test(html || '')

const extractAllowedInlineStyles = (styleAttr = '') => {
    if (!styleAttr || typeof styleAttr !== 'string') {
        return []
    }

    return styleAttr
        .split(';')
        .map((rule) => rule.trim())
        .filter(Boolean)
        .map((rule) => {
            const [property, ...rest] = rule.split(':')
            if (!property || !rest.length) {
                return null
            }

            const prop = property.trim().toLowerCase()
            const value = rest.join(':').trim()

            if (!allowedStyleProps.has(prop) || !value) {
                return null
            }

            return `${prop}: ${value}`
        })
        .filter(Boolean)
}

const takeStyleValue = (styles, prop) => {
    const match = Array.from(styles).find((s) => s.toLowerCase().startsWith(`${prop}:`))
    if (!match) {
        return null
    }

    styles.delete(match)
    return match.split(':').slice(1).join(':').trim()
}

const paragraphPlainText = (el) =>
    (el.textContent || '')
        .replace(/\u00a0/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()

const isWordListParagraph = (el) => {
    const className = el.getAttribute('class') || ''
    const style = el.getAttribute('style') || ''

    return /MsoListParagraph/i.test(className) || /mso-list\s*:/i.test(style)
}

const wordListStyleRegex = /mso-list\s*:\s*(l\d+)\s+level(\d+)/i

const wordListGroupKey = (p) => {
    const match = (p.getAttribute('style') || '').match(wordListStyleRegex)

    return match ? `${match[1]}:level${match[2]}` : 'unscoped-list'
}

// A real Word list (`class=MsoListParagraph...`, `style="...mso-list:l1 level1 lfo1"`) is
// exported as one <p> per item with the marker baked into a `mso-list:Ignore` span inside the
// SAME paragraph as its content. Converting these runs into a real <ol>/<ul> in place - instead
// of falling back to a plain-text rebuild for the whole document - keeps every other
// paragraph's inline formatting (bold/color/links/footnotes) untouched.
const convertWordListParagraphs = (root) => {
    let node = root.firstElementChild

    while (node) {
        if (node.tagName !== 'P' || !isWordListParagraph(node)) {
            node = node.nextElementSibling
            continue
        }

        const groupKey = wordListGroupKey(node)
        const group = [node]
        let next = node.nextElementSibling

        while (
            next &&
            next.tagName === 'P' &&
            isWordListParagraph(next) &&
            wordListGroupKey(next) === groupKey
        ) {
            group.push(next)
            next = next.nextElementSibling
        }

        const items = group.map((p) => {
            const markerSpan = p.querySelector('span[style*="mso-list:Ignore"]')
            const markerText = markerSpan
                ? markerSpan.textContent.replace(/\u00a0/g, ' ').trim()
                : ''

            return { p, markerSpan, meta: listMetaFromMarker(markerText) }
        })

        const isCleanList = items.every((item) => item.meta && item.meta.key === items[0].meta.key)

        if (!isCleanList) {
            // A genuine Word list paragraph we couldn't confidently convert - flag it (rather
            // than guessing from its text later) so the plain-text fallback only kicks in for
            // this specific, real failure, not for any ordinary paragraph that merely starts
            // with something that looks like "1. ".
            group.forEach((p) => p.setAttribute('data-mso-list-unconverted', '1'))
            node = next
            continue
        }

        const { tag, attrs } = items[0].meta
        const listItemsHtml = items
            .map((item) => {
                item.markerSpan?.remove()
                return `<li>${item.p.innerHTML.trim()}</li>`
            })
            .join('')

        group[0].insertAdjacentHTML('beforebegin', `<${tag}${attrs}>${listItemsHtml}</${tag}>`)
        group.forEach((p) => p.remove())

        node = next
    }
}

const canMergeWrappedParagraphs = (prev, next) => {
    if (!prev || !next || prev.tagName !== 'P' || next.tagName !== 'P') {
        return false
    }

    if (prev.querySelector('img') || next.querySelector('img')) {
        return false
    }

    if (isWordListParagraph(prev) || isWordListParagraph(next)) {
        return false
    }

    return shouldJoinWrappedLine(paragraphPlainText(prev), paragraphPlainText(next))
}

// Word sometimes exports one visual paragraph (soft line-wrap or centered title) as several
// consecutive <p> elements. Merge those back together in the DOM so inline formatting
// (bold/color/spans) survives instead of being flattened through a plain-text rebuild.
const mergeWrappedWordParagraphs = (root) => {
    Array.from(root.children).forEach((child) => mergeWrappedWordParagraphs(child))

    let node = root.firstElementChild

    while (node) {
        const next = node.nextElementSibling

        if (canMergeWrappedParagraphs(node, next)) {
            if (node.lastChild && next.firstChild) {
                node.appendChild(node.ownerDocument.createTextNode(' '))
            }

            while (next.firstChild) {
                node.appendChild(next.firstChild)
            }

            next.remove()
            continue
        }

        node = next
    }
}

const cleanFootnoteEntryHtml = (entry) => {
    const clone = entry.cloneNode(true)

    clone
        .querySelectorAll('a[style*="mso-footnote-id"], span.MsoFootnoteReference')
        .forEach((el) => el.remove())

    const paragraph = clone.querySelector('p') || clone

    return paragraph.innerHTML.trim()
}

// Word's clipboard HTML keeps footnote text in a separate `mso-element:footnote-list`
// block rather than inline. Pull it out before the generic cleanup runs so the footnote
// text isn't lost, and re-render it as a trailing numbered list.
const extractWordFootnotes = (doc) => {
    const container = doc.body.querySelector('[style*="mso-element:footnote-list"]')

    if (!container) {
        return []
    }

    const entries = Array.from(
        container.querySelectorAll('[style*="mso-element:footnote"]')
    ).filter((el) => !/footnote-list/i.test(el.getAttribute('style') || ''))

    const footnotes = entries.map(cleanFootnoteEntryHtml).filter((html) => html)

    container.remove()

    return footnotes
}

const wordBookmarkFragmentRegex = /^_(ftn|edn)(ref)?\d+$/i

// Copying an already-published article (instead of pasting straight from the source .docx)
// bakes the page's full URL into every in-page anchor, and Word's footnote/endnote bookmarks
// (`_ftnN`, `_ftnrefN`, ...) always carry `target="_blank"`. Once the fragment target still
// exists in the pasted content, the link should just jump within the same page/tab.
const fixFootnoteAnchorLinks = (doc) => {
    const localTargets = new Set()

    doc.body.querySelectorAll('[id], [name]').forEach((el) => {
        if (el.id) {
            localTargets.add(el.id)
        }

        const name = el.getAttribute('name')
        if (name) {
            localTargets.add(name)
        }
    })

    doc.body.querySelectorAll('a[href]').forEach((a) => {
        const href = a.getAttribute('href') || ''
        const fragment = href.match(/^(?:https?:\/\/[^#]*)?#(.+)$/)?.[1]

        if (!fragment || !wordBookmarkFragmentRegex.test(fragment) || !localTargets.has(fragment)) {
            return
        }

        a.setAttribute('href', `#${fragment}`)
        a.removeAttribute('target')
    })
}

export const rewriteInternalAnchorLinks = (html = '') => {
    if (!html || typeof html !== 'string') {
        return html
    }

    const parser = new DOMParser()
    const doc = parser.parseFromString(html, 'text/html')

    fixFootnoteAnchorLinks(doc)

    return doc.body.innerHTML
}

const applyFootnoteReferenceMarkers = (doc, footnoteCount) => {
    if (!footnoteCount) {
        return
    }

    const references = Array.from(
        doc.body.querySelectorAll('a[style*="mso-footnote-id"], a[href*="_ftn"]')
    )

    references.forEach((ref, index) => {
        const n = Math.min(index + 1, footnoteCount)
        const sup = doc.createElement('sup')
        sup.textContent = String(n)

        const link = doc.createElement('a')
        link.setAttribute('href', `#_ftn${n}`)
        link.setAttribute('id', `_ftnref${n}`)
        link.appendChild(sup)

        const marker = ref.closest('span.MsoFootnoteReference') || ref
        marker.replaceWith(link)
    })
}

export const sanitizeWordHtml = (html) => {
    const parser = new DOMParser()
    const doc = parser.parseFromString(html, 'text/html')

    doc.querySelectorAll('style, meta, link, xml, script').forEach((el) => el.remove())

    fixFootnoteAnchorLinks(doc)
    const footnotes = extractWordFootnotes(doc)
    applyFootnoteReferenceMarkers(doc, footnotes.length)
    convertWordListParagraphs(doc.body)
    mergeWrappedWordParagraphs(doc.body)

    doc.body.querySelectorAll('*').forEach((node) => {
        if (node.tagName === 'FONT') {
            const colorAttr = node.getAttribute('color')
            const span = doc.createElement('span')
            span.innerHTML = node.innerHTML

            if (colorAttr) {
                span.setAttribute('style', `color: ${colorAttr}`)
            }

            node.replaceWith(span)
            return
        }

        const { style } = node
        if (node.tagName === 'IMG') {
            const width = normalizeMediaImageWidth(
                node.getAttribute('data-media-width') ||
                    node.getAttribute('width') ||
                    style?.width ||
                    ''
            )

            if (width) {
                node.setAttribute('data-media-width', width)
            }
        }

        // `extractAllowedInlineStyles` already reads every allow-listed property (including
        // text-align/color/font-family/etc.) straight from the raw style attribute. Re-adding
        // them here from the parsed CSSOM `style` object used to create duplicate, differently
        // formatted entries for the same property (e.g. "color: #EE0000" alongside
        // "color: rgb(238, 0, 0)"), which `takeStyleValue` below only de-duplicates one at a
        // time. Only pull in `color` from the legacy `color` attribute, which isn't part of the
        // style attribute at all.
        const allowedStyles = new Set(extractAllowedInlineStyles(node.getAttribute('style')))

        const attrColor = node.getAttribute('color')
        if (attrColor) {
            allowedStyles.add(`color: ${attrColor}`)
        }

        const colorValue = takeStyleValue(allowedStyles, 'color')
        const backgroundValue = takeStyleValue(allowedStyles, 'background-color')

        if (colorValue && backgroundValue) {
            allowedStyles.add(`color: ${colorValue}`)
            allowedStyles.add(`background-color: ${backgroundValue}`)
        } else if (colorValue) {
            allowedStyles.add(`color: ${colorValue}`)
        } else if (backgroundValue) {
            allowedStyles.add(`background-color: ${backgroundValue}`)
        }

        const transferableInlineStyles = Array.from(allowedStyles).filter(
            (s) => s.startsWith('color:') || s.startsWith('background-color:')
        )

        if (transferableInlineStyles.length && node.tagName !== 'SPAN') {
            const span = doc.createElement('span')
            span.setAttribute('style', transferableInlineStyles.join('; '))

            while (node.firstChild) {
                span.appendChild(node.firstChild)
            }

            node.appendChild(span)

            transferableInlineStyles.forEach((styleValue) => allowedStyles.delete(styleValue))
        }

        node.removeAttribute('class')
        node.removeAttribute('lang')
        node.removeAttribute('width')
        node.removeAttribute('height')
        node.removeAttribute('color')

        if (allowedStyles.size) {
            node.setAttribute('style', Array.from(allowedStyles).join('; '))
        } else {
            node.removeAttribute('style')
        }

        if (node.tagName === 'SPAN' && !node.attributes.length) {
            node.replaceWith(...node.childNodes)
        }
    })

    doc.body.querySelectorAll('p').forEach((p) => {
        const hasMedia = Boolean(p.querySelector('img, figure.content-media'))

        if (!p.textContent.replace(/\u00a0/g, ' ').trim() && !hasMedia) {
            p.remove()
            return
        }

        if (!hasMedia) {
            p.innerHTML = p.innerHTML.replace(/<br\s*\/?>\s*/gi, ' ')
        }
    })

    const bodyHtml = convertHtmlImagesToMediaFigures(doc.body.innerHTML).trim()
    const footnotesHtml = footnotes.length
        ? `<ol>${footnotes
              .map((entry, index) => {
                  const n = index + 1

                  return `<li><a href="#_ftnref${n}" id="_ftn${n}">↩</a> ${entry}</li>`
              })
              .join('')}</ol>`
        : ''

    return `${bodyHtml}${footnotesHtml}`
}

const paragraphTexts = (html = '') => {
    const parser = new DOMParser()
    const doc = parser.parseFromString(html, 'text/html')

    return Array.from(doc.body.querySelectorAll('p')).map((p) =>
        p.textContent
            .replace(/\u00a0/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
    )
}

const shouldPreferPlainTextForWordPaste = (cleanedHtml = '', normalizedText = '', rawHtml = '') => {
    if (!cleanedHtml || !normalizedText) {
        return false
    }

    // Plain text cannot carry a table: Word puts each cell in its own <p> but a whole row on
    // one tab-separated plain-text line, so the paragraph-count checks below would always
    // pick plain text and drop every table in the paste.
    if (/<table[\s>]/i.test(cleanedHtml)) {
        return false
    }

    const htmlParagraphs = paragraphTexts(cleanedHtml).filter(Boolean)
    const normalizedLines = normalizedText
        .split(/\n+/)
        .map((line) => line.trim())
        .filter(Boolean)

    if (!htmlParagraphs.length || !normalizedLines.length) {
        return false
    }

    const normalizedHasListMarker = normalizedLines.some(
        (text) => standaloneListMarkerRegex.test(text) || listMarkerPrefixRegex.test(text)
    )

    // `convertWordListParagraphs` already turns clean Word lists into real <ol>/<ul> markup
    // (preserving every other paragraph's formatting/footnotes untouched). Falling back here
    // whenever a paragraph merely LOOKS LIKE a list item (e.g. a heading a human author typed
    // as "1. Some Title") would keep destroying the rest of the document's footnotes/formatting
    // over ordinary text. Only fall back when a paragraph that WAS a genuine Word list item
    // (`class=MsoListParagraph` / `mso-list:`) failed to convert cleanly - flagged explicitly by
    // `convertWordListParagraphs` itself, not guessed from text patterns here.
    const hasUnconvertedWordListParagraph = cleanedHtml.includes('data-mso-list-unconverted')

    if (
        wordListHtmlRegex.test(rawHtml) &&
        normalizedHasListMarker &&
        hasUnconvertedWordListParagraph
    ) {
        return true
    }

    const hasStandaloneListMarker = htmlParagraphs.some((text) =>
        standaloneListMarkerRegex.test(text)
    )
    const normalizedHasStandaloneListMarker = normalizedLines.some((text) =>
        standaloneListMarkerRegex.test(text)
    )

    if (hasStandaloneListMarker && !normalizedHasStandaloneListMarker) {
        return true
    }

    return htmlParagraphs.length > normalizedLines.length + 2
}

export const normalizeWordPasteHtml = (html = '', text = '') => {
    const cleanedHtml = sanitizeWordHtml(html)
    const normalizedText = normalizePlainText(text)

    if (shouldPreferPlainTextForWordPaste(cleanedHtml, normalizedText, html)) {
        return plainTextToListAwareHtml(normalizedText)
    }

    // Internal-only marker from `convertWordListParagraphs`, never meant to reach saved content.
    const finalHtml = cleanedHtml.replace(/ data-mso-list-unconverted="1"/g, '')

    return finalHtml || plainTextToParagraphHtml(normalizedText)
}
