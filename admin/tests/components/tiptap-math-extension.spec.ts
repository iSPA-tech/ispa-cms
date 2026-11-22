import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { MathBlock, renderMathMl } from '@/components/tiptap-math-extension'
import { normalizeInlineStyles } from '@/components/tiptap-word-paste'
import TipTapMath from '@/components/tiptap-math.vue'

const createEditor = (content = '') =>
    new Editor({
        extensions: [StarterKit, MathBlock],
        content,
    })

describe('renderMathMl', () => {
    it('renders display MathML without class or style on the math root', () => {
        const { html, error } = renderMathMl('\\frac{a}{b} \\times 100\\%')
        const rootTag = html.match(/^<math\b[^>]*>/)?.[0] ?? ''

        expect(error).toBe('')
        expect(rootTag).toBe('<math display="block">')
        expect(rootTag).not.toContain('style=')
        expect(rootTag).not.toContain('class=')
        expect(html).toContain('<mfrac>')
    })

    it('returns an empty result for blank input', () => {
        expect(renderMathMl('')).toEqual({ html: '', error: '' })
        expect(renderMathMl('   \n ')).toEqual({ html: '', error: '' })
    })

    it('keeps Vietnamese diacritics inside \\text{} in a single mtext', () => {
        const { html } = renderMathMl('\\text{Khoảng cách (m)}')

        expect(html.match(/<mtext>/g)).toHaveLength(1)
        // Temml turns the spaces inside \text{} into no-break spaces; \s matches U+00A0 too.
        expect(html).toMatch(/<mtext>Khoảng\s+cách\s+\(m\)<\/mtext>/)
    })

    it('reports a parse error instead of throwing', () => {
        const { html, error } = renderMathMl('\\frac{a')

        expect(html).toBe('')
        expect(error).not.toBe('')
        expect(error).not.toContain('\u0332')
    })

    it('never emits links for \\href because trust stays disabled', () => {
        const { html } = renderMathMl('\\href{javascript:alert(1)}{x}')

        expect(html).not.toContain('<a')
        expect(html).not.toContain('href')
    })

    it('returns the same result for repeated input', () => {
        expect(renderMathMl('x^2')).toBe(renderMathMl(' x^2 '))
    })
})

describe('MathBlock extension', () => {
    it('parses a stored math block and serializes MathML again', () => {
        const editor = createEditor(
            '<div data-type="math-block" data-latex="\\frac{a}{b}"></div>'
        )
        const html = editor.getHTML()
        editor.destroy()

        expect(html).toBe(
            '<div class="content-math content-math--block" data-type="math-block" data-latex="\\frac{a}{b}">' +
                '<math display="block"><mfrac><mi>a</mi><mi>b</mi></mfrac></math></div>'
        )
    })

    it('round-trips stored MathML without drift', () => {
        const editor = createEditor('<p>Before</p><div data-type="math-block" data-latex="x^2"></div>')
        const first = editor.getHTML()
        editor.commands.setContent(first)
        const second = editor.getHTML()
        editor.commands.setContent(second)
        const third = editor.getHTML()
        editor.destroy()

        // The first pass may append the trailing empty paragraph; after that it must be stable.
        expect(third).toBe(second)
        expect(second).toContain('<math display="block">')
        expect(second).toContain('data-latex="x^2"')
    })

    it('renders the escaped source in an error code element for invalid LaTeX', () => {
        const editor = createEditor()
        editor.commands.setMathBlock({ latex: '\\frac{a} <b> & "c"' })
        const html = editor.getHTML()
        editor.destroy()

        expect(html).not.toContain('<math')
        expect(html).toContain('<code class="content-math__error">')
        expect(html).toContain('\\frac{a} &lt;b&gt; &amp; "c"</code>')
        expect(html).toContain('&amp; &quot;c&quot;"><code')
    })

    it('inserts and updates a math block through commands', () => {
        const editor = createEditor('<p>Intro</p>')

        expect(editor.commands.setMathBlock({ latex: 'a+b' })).toBe(true)

        let mathPos = -1
        editor.state.doc.descendants((node, pos) => {
            if (node.type.name === 'mathBlock') {
                mathPos = pos
            }
        })

        expect(mathPos).toBeGreaterThan(-1)

        editor.commands.setNodeSelection(mathPos)
        expect(editor.isActive('mathBlock')).toBe(true)
        expect(editor.getAttributes('mathBlock').latex).toBe('a+b')

        expect(editor.commands.updateMathBlock({ latex: '\\sqrt{x}' })).toBe(true)

        const html = editor.getHTML()
        editor.destroy()

        expect(html).toContain('data-latex="\\sqrt{x}"')
        expect(html).toContain('<msqrt>')
        expect(html).not.toContain('data-latex="a+b"')
    })

    it('is left untouched by inline style normalization even with Temml cell padding', () => {
        const editor = createEditor()
        editor.commands.setMathBlock({ latex: '\\begin{pmatrix}a&b\\\\c&d\\end{pmatrix}' })
        const html = editor.getHTML()
        editor.destroy()

        expect(html).toContain('style="padding-left:')
        expect(normalizeInlineStyles(html)).toBe(html)
    })
})

describe('TipTapMath popover', () => {
    const mountPopover = (editor: Editor) =>
        mount(TipTapMath, {
            props: {
                // test-utils makes props deeply reactive; a proxied Editor breaks ProseMirror state.
                editor: markRaw(editor),
                toolbar: { title: 'Math formula' },
                isActive: (type: string) => editor.isActive(type),
            },
            global: {
                stubs: {
                    'a-popover': {
                        props: ['open'],
                        template: '<div><slot /><div><slot name="content" /></div></div>',
                    },
                    'a-form': {
                        template: '<form @submit.prevent="$emit(\'finish\')"><slot /></form>',
                    },
                    'a-form-item': { template: '<div><slot /></div>' },
                    'a-textarea': {
                        props: ['value'],
                        template:
                            '<textarea :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
                    },
                    'a-button': {
                        props: ['disabled'],
                        template: '<button type="button" :disabled="disabled"><slot /></button>',
                    },
                    'i-icon': true,
                },
            },
        })

    it('previews MathML, blocks saving invalid source, and inserts a block on save', async () => {
        const editor = createEditor('<p>Intro</p>')
        const wrapper = mountPopover(editor)
        const vm = wrapper.vm as unknown as { innerValue: { latex: string } }
        const saveButton = () =>
            wrapper.findAll('button').find((button) => button.text() === 'nav.b.save')

        expect(saveButton()?.attributes('disabled')).toBeDefined()
        expect(wrapper.text()).toContain('text.editor.math.empty')

        await wrapper.find('textarea').setValue('\\frac{a')
        expect(wrapper.find('[role="alert"]').exists()).toBe(true)
        expect(saveButton()?.attributes('disabled')).toBeDefined()

        await wrapper.find('textarea').setValue('\\frac{a}{b}')
        expect(wrapper.find('[role="alert"]').exists()).toBe(false)
        expect(wrapper.find('math[display="block"]').exists()).toBe(true)
        expect(saveButton()?.attributes('disabled')).toBeUndefined()

        await wrapper.find('form').trigger('submit')
        await nextTick()

        expect(vm.innerValue.latex).toBe('\\frac{a}{b}')
        expect(editor.getHTML()).toContain('data-latex="\\frac{a}{b}"')
        editor.destroy()
    })
})
