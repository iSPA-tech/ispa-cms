<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="flex flex-col w-full items-stretch">
        <div
            v-if="original && original.hasOwnProperty('is_translated')"
            class="border border-dashed p-3 rounded-sm bg-gray-50 flex items-center mb-2"
        >
            <div class="text-rose-600 text-sm flex-1">
                <span v-if="original.is_translated === false">
                    {{ t('text.editor.translation_pending') }}
                </span>
            </div>
            <div>{{ t('text.editor.total_chars', { count: editor?.getHTML().length }) }}</div>
        </div>
        <div
            v-if="editorObject && !hideToolbar"
            class="border border-gray-400 border-b-0 px-2 py-1 v-toolbar rounded-t-lg bg-gray-50"
        >
            <tip-tap-editor-toolbar :editor="editorObject" :compact="compact" />
        </div>
        <editor-content :editor="editorObject" class="*:border-gray-400 *:rounded-b-lg" />
    </div>
</template>

<script setup>
import { Editor, EditorContent } from '@tiptap/vue-3'
import { mergeAttributes } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { Highlight as BaseHighlight } from '@tiptap/extension-highlight'
import { TextAlign } from '@tiptap/extension-text-align'
import { Link as BaseLink } from '@tiptap/extension-link'
import { Placeholder } from '@tiptap/extension-placeholder'
import { Subscript } from '@tiptap/extension-subscript'
import { Superscript } from '@tiptap/extension-superscript'
import { Table } from '@tiptap/extension-table'
import { TableCell } from '@tiptap/extension-table-cell'
import { TableHeader } from '@tiptap/extension-table-header'
import { TableRow } from '@tiptap/extension-table-row'
import { Color as BaseColor } from '@tiptap/extension-color'
import { TextStyle } from '@tiptap/extension-text-style'
import FontFamily from '@tiptap/extension-font-family'
import { Underline } from '@tiptap/extension-underline'
import { defineComponent, onMounted, onUnmounted, ref, watch } from 'vue'
import { classNames } from '@/mixins'
import TipTapEditorToolbar from '@/components/tiptap-editor-toolbar.vue'
import { useI18n } from 'vue-i18n'
import { FontSize } from '@/components/tiptap-font-size'
import {
    emptyParagraphRegex,
    isWordHtml,
    normalizeInlineStyles,
    normalizePlainText,
    normalizePastedListWhitespace,
    normalizeWordPasteHtml,
    plainTextToParagraphHtml,
    rewriteInternalAnchorLinks,
} from '@/components/tiptap-word-paste'
import { MediaImage } from '@/components/tiptap-media-image-extension'
import { MathBlock } from '@/components/tiptap-math-extension'

defineComponent({
    name: 'TipTapEditor',
})

const { t } = useI18n()

const props = defineProps({
    editor: {
        type: Object,
    },
    modelValue: {
        type: String,
        default: ``,
    },
    original: {
        type: Object,
    },
    compact: {
        type: Boolean,
        default: false,
    },
    label: {
        type: String,
        default: '',
    },
    // Hide the built-in toolbar when the host renders its own (the instance comes with `ready`).
    hideToolbar: {
        type: Boolean,
        default: false,
    },
    // Compact mode has no links by default; a host with its own link control turns them on.
    allowLink: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue', 'change', 'ready'])

const preserveWhitespaceParseOptions = { preserveWhitespace: 'full' }
const inlineColorCssVar = '--v-editor-inline-color'

const Highlight = BaseHighlight.extend({
    parseHTML() {
        return [
            { tag: 'mark' },
            { style: 'background-color' },
            {
                tag: 'span',
                getAttrs: (element) => {
                    if (!(element instanceof HTMLElement)) {
                        return false
                    }
                    const bg = element.style?.backgroundColor || element.style?.background
                    return bg ? {} : false
                },
            },
        ]
    },
    renderHTML({ HTMLAttributes }) {
        const styles = []
        const attrs = { ...HTMLAttributes }

        if (HTMLAttributes?.color) {
            styles.push(`background-color: ${HTMLAttributes.color}`)
            delete attrs.color
        }

        if (HTMLAttributes?.textColor) {
            styles.push(`color: ${HTMLAttributes.textColor}`)
            delete attrs.textColor
        }

        if (styles.length) {
            attrs.style = styles.join('; ')
        }

        return ['mark', mergeAttributes(this.options.HTMLAttributes, attrs), 0]
    },
    addAttributes() {
        return {
            ...this.parent?.(),
            color: {
                default: null,
                parseHTML: (element) => {
                    const dataColor = element.getAttribute('data-color')
                    const bg = element.style?.backgroundColor || element.style?.background
                    const value = dataColor || bg
                    return value ? value.replace(/['"]+/g, '') : null
                },
                renderHTML: (attributes) => {
                    if (!attributes.color) {
                        return {}
                    }

                    return {
                        style: `background-color: ${attributes.color}`,
                        'data-color': attributes.color,
                    }
                },
            },
            textColor: {
                default: null,
                parseHTML: (element) => {
                    const inlineColor =
                        element.style?.color ||
                        element.getAttribute('data-text-color') ||
                        element.closest('span[style*=\"color\"]')?.style?.color ||
                        element.closest('[data-text-color]')?.getAttribute('data-text-color')
                    return inlineColor ? inlineColor.replace(/['"]+/g, '') : null
                },
                renderHTML: (attributes) => {
                    if (!attributes.textColor) {
                        return {}
                    }

                    return {
                        style: `color: ${attributes.textColor}`,
                        'data-text-color': attributes.textColor,
                    }
                },
            },
        }
    },
})

const Color = BaseColor.extend({
    addGlobalAttributes() {
        return (this.parent?.() || []).map((attributeGroup) => ({
            ...attributeGroup,
            attributes: {
                ...attributeGroup.attributes,
                color: {
                    ...attributeGroup.attributes?.color,
                    renderHTML: (attributes) => {
                        if (!attributes.color) {
                            return {}
                        }

                        return {
                            style: `color: ${attributes.color}; ${inlineColorCssVar}: ${attributes.color}`,
                        }
                    },
                },
            },
        }))
    },
    addCommands() {
        const parentCommands = this.parent?.()

        return {
            ...parentCommands,
            setColor:
                (color) =>
                ({ chain, editor }) => {
                    const result = chain().setMark('textStyle', { color }).run()

                    if (editor.isActive('highlight')) {
                        editor.commands.updateAttributes('highlight', { textColor: color })
                    }

                    return result
                },
            unsetColor:
                () =>
                ({ chain, editor }) => {
                    const result = chain()
                        .setMark('textStyle', { color: null })
                        .removeEmptyTextStyle()
                        .run()

                    if (editor.isActive('highlight')) {
                        editor.commands.updateAttributes('highlight', { textColor: null })
                    }

                    return result
                },
        }
    },
})

// A same-page anchor jump (footnotes, table of contents, ...) should never open in a new
// tab/window, unlike a genuine external reference link.
const Link = BaseLink.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            id: {
                default: null,
                parseHTML: (element) => element.getAttribute('id'),
                renderHTML: (attributes) => (attributes.id ? { id: attributes.id } : {}),
            },
        }
    },
    renderHTML({ HTMLAttributes }) {
        const rendered = this.parent?.({ HTMLAttributes })
        const href = typeof HTMLAttributes.href === 'string' ? HTMLAttributes.href : ''

        if (!rendered || !href.startsWith('#')) {
            return rendered
        }

        const [tag, attrs, content] = rendered
        const { target, rel, ...rest } = attrs

        return [tag, rest, content]
    },
})

const updateModelValue = (value) => {
    lastLocalModelValue = value ?? ''
    emit('update:modelValue', value)
    emit('change', value)
}

// Prevent propagating model updates while we are programmatically syncing content
let isSyncingContent = true
let lastLocalModelValue = normalizeInlineStyles(props.modelValue) || ''

const editorObject = new Editor({
    content: normalizeInlineStyles(props.modelValue),
    // Match the whitespace handling used when the parent echoes content back via `setContent`
    // below, so a fresh mount renders identically to a live paste instead of reflowing on reload.
    parseOptions: preserveWhitespaceParseOptions,
    onUpdate({ editor }) {
        if (isSyncingContent) {
            return
        }

        const html = editor.getHTML().trim()

        // Remove empty paragraphs and Word-only spacer nodes so pasted content does not add stray blank lines.
        const cleaned = html.replace(emptyParagraphRegex, '').trim()

        // The content has changed.
        updateModelValue(cleaned === '' ? null : cleaned)
    },
    editorProps: {
        attributes: {
            spellcheck: 'false',
            ...(props.label ? { 'aria-label': props.label } : {}),
            class: classNames(
                'px-4 py-2 border w-full focus:outline-hidden focus:border-blue-500',
                'min-h-[12rem] max-h-[20rem] overflow-y-auto prose max-w-none! v-editor'
            ),
        },
        handlePaste(view, event) {
            const html = event.clipboardData?.getData('text/html') || ''
            const text = event.clipboardData?.getData('text/plain') || ''

            // Use the same `preserveWhitespace: 'full'` parsing as the initial mount and the
            // `setContent` echo below, so pasted content doesn't reflow the moment the page is
            // reloaded from what was actually saved.
            const insertOptions = { parseOptions: preserveWhitespaceParseOptions }

            if (html && isWordHtml(html)) {
                event.preventDefault()

                const cleanedHtml = normalizePastedListWhitespace(
                    normalizeWordPasteHtml(html, text)
                )

                editorObject.chain().focus().insertContent(cleanedHtml, insertOptions).run()
                return true
            }

            if (html) {
                // Pasting an already-published page (rather than the source .docx) bakes that
                // page's URL into every in-page footnote/anchor link; fix those up even when
                // the clipboard HTML isn't Word's own markup.
                const normalizedHtml = normalizePastedListWhitespace(html)
                const rewrittenHtml = rewriteInternalAnchorLinks(normalizedHtml)

                if (rewrittenHtml !== html) {
                    event.preventDefault()
                    editorObject.chain().focus().insertContent(rewrittenHtml, insertOptions).run()
                    return true
                }
            }

            const normalizedText = normalizePlainText(text)
            if (!html && normalizedText !== text) {
                event.preventDefault()
                editorObject
                    .chain()
                    .focus()
                    .insertContent(plainTextToParagraphHtml(normalizedText), insertOptions)
                    .run()
                return true
            }

            return false
        },
        transformPastedHTML(html) {
            return normalizePastedListWhitespace(html)
        },
        transformPastedText(text) {
            return normalizePlainText(text)
        },
    },
    extensions: [
        StarterKit.configure(props.compact ? {
            blockquote: false,
            codeBlock: false,
            code: false,
            heading: false,
            horizontalRule: false,
            strike: false,
            link: false,
            underline: false,
        } : {
            link: false,
            underline: false,
        }),
        ...(props.compact ? [Underline, ...(props.allowLink ? [Link.configure({ openOnClick: false })] : [])] : [
        // Keep TextStyle inside bold/italic marks so inline color remains visible under typography CSS.
        TextStyle.configure({
            priority: 80,
        }),
        Color.configure({
            priority: 105,
        }),
        Highlight.configure({
            priority: 90,
            multicolor: true,
        }),
        // No default alignment: Tiptap 3 writes `text-align` for any non-null value, and a
        // forced `left` overrides centered widget/theme layouts on every save.
        TextAlign.configure({
            types: ['heading', 'paragraph'],
            defaultAlignment: null,
        }),
        Link.configure({
            openOnClick: false,
        }),
        Subscript,
        Superscript,
        Table.configure({
            resizable: true,
        }),
        TableCell,
        TableHeader,
        TableRow,
        Underline,
        Placeholder.configure({
            placeholder: t('text.form.content-placeholder'),
        }),
        FontFamily,
        FontSize,
        MediaImage,
        MathBlock,
        ]),
    ],
})

watch(
    () => props.modelValue,
    (value) => {
        const nextValue = normalizeInlineStyles(value) || ''
        const current = editorObject.getHTML()
        if (nextValue === current || nextValue === lastLocalModelValue) {
            return
        }

        const selection = {
            from: editorObject.state.selection.from,
            to: editorObject.state.selection.to,
        }
        isSyncingContent = true
        // Avoid triggering onUpdate when syncing external content
        editorObject.commands.setContent(nextValue, {
            emitUpdate: false,
            parseOptions: preserveWhitespaceParseOptions,
        })
        const docSize = editorObject.state.doc.content.size
        editorObject.commands.setTextSelection({
            from: Math.min(selection.from, docSize),
            to: Math.min(selection.to, docSize),
        })
        setTimeout(() => {
            isSyncingContent = false
        }, 0)
    },
    {
        immediate: true,
        deep: true,
    }
)

onUnmounted(() => {
    editorObject.destroy()
})

onMounted(() => {
    emit('ready', editorObject)
    // Release the initial sync lock once the component mounts
    setTimeout(() => {
        isSyncingContent = false
    }, 0)
})
</script>
