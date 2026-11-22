import { Node } from '@tiptap/core'
import temml from 'temml'

const MATH_CACHE_LIMIT = 200
// Error value for a failure that has no message; the UI translates this key.
export const MATH_ERROR_FALLBACK = 'text.editor.math.invalid_formula'
const mathCache = new Map()

// Temml puts class/style on the <math> root; both are redundant in MathML Core
// (`display="block"` already centers the formula), so drop them to keep stored HTML clean.
// Only the opening tag is touched: inner elements keep their attributes.
const stripRootPresentationAttributes = (mathMl) => {
    return mathMl.replace(/^<math\b[^>]*>/, (openingTag) =>
        openingTag.replace(/\s(?:class|style)="[^"]*"/g, '')
    )
}

// Temml underlines the offending source span with U+0332 and breaks the message over lines.
const cleanErrorMessage = (error) => {
    const message = String(error?.message || error || '')
        .replace(/\u0332/g, '')
        .replace(/\s+/g, ' ')
        .trim()

    return message || MATH_ERROR_FALLBACK
}

const rememberResult = (source, result) => {
    if (mathCache.size >= MATH_CACHE_LIMIT) {
        mathCache.delete(mathCache.keys().next().value)
    }

    mathCache.set(source, result)

    return result
}

/**
 * Render LaTeX to browser-native MathML (display mode). `trust` stays at Temml's default
 * (false), so \href, \class and \style are rejected instead of reaching the markup.
 *
 * @param {string} latex
 * @returns {{ html: string, error: string }}
 */
export const renderMathMl = (latex) => {
    const source = String(latex ?? '').trim()

    if (!source) {
        return { html: '', error: '' }
    }

    const cached = mathCache.get(source)
    if (cached) {
        return cached
    }

    try {
        const mathMl = temml.renderToString(source, { displayMode: true, throwOnError: true })

        return rememberResult(source, { html: stripRootPresentationAttributes(mathMl), error: '' })
    } catch (error) {
        return rememberResult(source, { html: '', error: cleanErrorMessage(error) })
    }
}

export const MathBlock = Node.create({
    name: 'mathBlock',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            latex: {
                default: '',
                parseHTML: (element) => element.getAttribute('data-latex') || '',
                rendered: false,
            },
        }
    },

    parseHTML() {
        return [{ tag: 'div[data-type="math-block"]' }]
    },

    // A fresh DOM element per call: the live editor and getHTML() both show/serialize MathML,
    // and ProseMirror never receives a node it already attached elsewhere.
    renderHTML({ node }) {
        const latex = String(node.attrs.latex || '')
        const element = document.createElement('div')

        element.setAttribute('class', 'content-math content-math--block')
        element.setAttribute('data-type', 'math-block')
        element.setAttribute('data-latex', latex)

        const { html, error } = renderMathMl(latex)

        if (html) {
            element.innerHTML = html
        } else if (error) {
            const code = document.createElement('code')

            code.setAttribute('class', 'content-math__error')
            code.textContent = latex.trim()
            element.appendChild(code)
        }

        return element
    },

    addCommands() {
        return {
            setMathBlock:
                ({ latex = '' } = {}) =>
                ({ commands }) => {
                    return commands.insertContent({
                        type: this.name,
                        attrs: { latex: String(latex) },
                    })
                },
            updateMathBlock:
                ({ latex = '' } = {}) =>
                ({ commands }) => {
                    return commands.updateAttributes(this.name, { latex: String(latex) })
                },
        }
    },
})

export default MathBlock
