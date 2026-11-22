<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<script setup>
import { computed, defineComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import TipTapEditorToolbarButtons from '@/components/tiptap-editor-toolbar-buttons.vue'
import { useI18n } from 'vue-i18n'

defineComponent({
    name: 'TipTapEditorToolbar',
})

const { t } = useI18n()

const currentEditorActive = ref({
    heading: undefined,
    isIncludeHeader: false,
    fontFamily: 'unset',
    fontSize: 'unset',
})

const { editor, compact } = defineProps({
    editor: {
        type: Object,
        required: true,
    },
    compact: {
        type: Boolean,
        default: false,
    },
})

const isActive = (type, options = {}) => {
    return editor.isActive(type, options)
}

const basicExtension = [
    {
        name: 'bold',
        icon: 'format-bold',
        action: () => editor.chain().focus().toggleBold().run(),
        isActive: () => isActive('bold'),
        title: t('text.form.bold'),
    },
    {
        name: 'italic',
        icon: 'format-italic',
        action: () => editor.chain().focus().toggleItalic().run(),
        isActive: () => isActive('italic'),
        title: t('text.form.italic'),
    },
]
const historyExtension = [
    {
        name: 'break',
    },
    //  Undo & Redo
    {
        name: 'undo',
        icon: 'undo',
        action: () => editor.chain().focus().undo().run(),
        isActive: () => false,
        title: t('text.form.undo'),
    },
    {
        name: 'redo',
        icon: 'redo',
        action: () => editor.chain().focus().redo().run(),
        isActive: () => false,
        title: t('text.form.redo'),
    },
]

//  Color and highlight
const colorsExtension = [
    {
        name: 'color-select',
        title: t('text.form.color-select'),
    },
    {
        name: 'highlight-select',
        title: t('text.form.highlight-select'),
    },
    {
        name: 'link-select',
        title: t('text.form.link-select'),
    },
    {
        name: 'media-image-select',
        title: t('text.editor.media_image.title'),
    },
    {
        name: 'math-block-select',
        title: t('text.form.math-block-select'),
    },
    {
        name: 'deleteLink',
        icon: 'link-off',
        action: () => editor.chain().focus().extendMarkRange('link').unsetLink().run(),
        isActive: () => false,
        title: t('text.form.delete-link'),
    },
]

const toolbars = computed(() => compact ? [
    ...basicExtension,
    { name: 'underline', icon: 'format-underline', action: () => editor.chain().focus().toggleUnderline().run(), isActive: () => isActive('underline'), title: t('text.form.underline') },
    { name: 'bulletList', icon: 'format-list-bulleted', action: () => editor.chain().focus().toggleBulletList().run(), isActive: () => isActive('bulletList'), title: t('text.form.bullet-list') },
    { name: 'orderedList', icon: 'format-list-numbered', action: () => editor.chain().focus().toggleOrderedList().run(), isActive: () => isActive('orderedList'), title: t('text.form.ordered-list') },
    ...historyExtension,
] : [
    ...basicExtension,
    //  underline
    {
        name: 'underline',
        icon: 'format-underline',
        action: () => editor.chain().focus().toggleUnderline().run(),
        isActive: () => isActive('underline'),
        title: t('text.form.underline'),
    },
    {
        name: 'strike',
        icon: 'format-strike-through-variant',
        action: () => editor.chain().focus().toggleStrike().run(),
        isActive: () => isActive('strike'),
        title: t('text.form.strike'),
    },
    //  blockquote
    {
        name: 'blockquote',
        icon: 'format-quote-open',
        action: () => editor.chain().focus().toggleBlockquote().run(),
        isActive: () => isActive('blockquote'),
        title: t('text.form.blockquote'),
    },
    {
        name: 'codeBlock',
        icon: 'xml',
        action: () => editor.chain().focus().toggleCodeBlock().run(),
        isActive: () => isActive('codeBlock'),
        title: t('text.form.code-block'),
    },
    {
        name: 'break',
    },
    {
        name: 'heading',
        icon: 'format-header-1',
        action: () => {
            editor.chain().focus().toggleHeading({ level: 1 }).run()
            checkCurrentHeading()
        },
        isActive: () => isActive('heading', { level: 1 }),
        title: t('text.form.heading') + ' h1',
    },
    {
        name: 'heading',
        icon: 'format-header-2',
        action: () => {
            editor.chain().focus().toggleHeading({ level: 2 }).run()

            checkCurrentHeading()
        },
        isActive: () => isActive('heading', { level: 2 }),
        title: t('text.form.heading') + ' h2',
    },
    {
        name: 'heading',
        icon: 'format-header-3',
        action: () => {
            editor.chain().focus().toggleHeading({ level: 3 }).run()

            checkCurrentHeading()
        },
        isActive: () => isActive('heading', { level: 3 }),
        title: t('text.form.heading') + ' h3',
    },
    {
        name: 'break',
    },
    {
        name: 'bulletList',
        icon: 'format-list-bulleted',
        action: () => editor.chain().focus().toggleBulletList().run(),
        isActive: () => isActive('bulletList'),
        title: t('text.form.bullet-list'),
    },
    {
        name: 'orderedList',
        icon: 'format-list-numbered',
        action: () => editor.chain().focus().toggleOrderedList().run(),
        isActive: () => isActive('orderedList'),
        title: t('text.form.ordered-list'),
    },
    {
        name: 'break',
    },
    {
        name: 'subscript',
        icon: 'format-subscript',
        action: () => editor.chain().focus().toggleSubscript().run(),
        isActive: () => isActive('subscript'),
        title: t('text.form.subscript'),
    },
    {
        name: 'superscript',
        icon: 'format-superscript',
        action: () => editor.chain().focus().toggleSuperscript().run(),
        isActive: () => isActive('superscript'),
        title: t('text.form.superscript'),
    },
    {
        name: 'break',
    },
    {
        name: 'textAlign',
        icon: 'format-align-left',
        action: () => editor.chain().focus().setTextAlign('left').run(),
        isActive: () => isActive({ textAlign: 'left' }),
        title: t('text.form.text-align-left'),
    },
    {
        name: 'textAlign',
        icon: 'format-align-center',
        action: () => editor.chain().focus().setTextAlign('center').run(),
        isActive: () => isActive({ textAlign: 'center' }),
        title: t('text.form.text-align-center'),
    },
    {
        name: 'textAlign',
        icon: 'format-align-right',
        action: () => editor.chain().focus().setTextAlign('right').run(),
        isActive: () => isActive({ textAlign: 'right' }),
        title: t('text.form.text-align-right'),
    },
    {
        name: 'textAlign',
        icon: 'format-align-justify',
        action: () => editor.chain().focus().setTextAlign('justify').run(),
        isActive: () => isActive({ textAlign: 'justify' }),
        title: t('text.form.text-align-justify'),
    },
    {
        name: 'break',
    },
    ...colorsExtension,
    {
        name: 'paragraph',
        icon: 'format-paragraph',
        action: () => editor.chain().focus().setParagraph().run(),
        isActive: () => isActive('paragraph'),
        title: t('text.form.paragraph'),
    },
    {
        name: 'break',
    },
    {
        name: 'heading-select',
        title: t('text.form.heading-select'),
    },
    {
        name: 'font-family-select',
        title: t('text.form.font-family-select'),
    },
    {
        name: 'font-size-select',
        title: t('text.form.font-size-select'),
    },
    {
        name: 'break',
    },
    {
        name: 'table-select',
        title: t('text.form.table-select'),
        isActive: () => isActive('paragraph'),
        action: () => editor.chain().focus().setParagraph().run(),
    },
    ...historyExtension,
])

//  Table toolbar
const tableToolbar = [
    ...basicExtension,
    {
        name: 'table-select',
        title: t('text.form.table-select'),
    },
    {
        name: 'remove',
        icon: 'table-remove',
        action: () => editor.chain().focus().deleteTable().run(),
        isActive: () => false,
        title: t('text.form.remove-table'),
    },
    { name: 'break' },
    //  Add column before
    {
        name: 'addColumnBefore',
        icon: 'table-column-plus-before',
        action: () => editor.chain().focus().addColumnBefore().run(),
        isActive: () => false,
        title: t('text.form.add-column-before'),
    },
    {
        name: 'addColumnAfter',
        icon: 'table-column-plus-after',
        action: () => editor.chain().focus().addColumnAfter().run(),
        isActive: () => false,
        title: t('text.form.add-column-after'),
    },
    {
        name: 'deleteColumn',
        icon: 'table-column-remove',
        action: () => editor.chain().focus().deleteColumn().run(),
        isActive: () => false,
        title: t('text.form.delete-column'),
    },
    { name: 'break' },
    //  Add row
    {
        name: 'addRowBefore',
        icon: 'table-row-plus-before',
        action: () => editor.chain().focus().addRowBefore().run(),
        isActive: () => false,
        title: t('text.form.add-row-before'),
    },
    {
        name: 'addRowAfter',
        icon: 'table-row-plus-after',
        action: () => editor.chain().focus().addRowAfter().run(),
        isActive: () => false,
        title: t('text.form.add-row-after'),
    },
    {
        name: 'deleteRow',
        icon: 'table-row-remove',
        action: () => editor.chain().focus().deleteRow().run(),
        isActive: () => false,
        title: t('text.form.delete-row'),
    },
    //  Merge cell
    {
        name: 'mergeCells',
        icon: 'table-merge-cells',
        action: () => editor.chain().focus().mergeCells().run(),
        isActive: () => false,
        title: t('text.form.merge-cells'),
    },
    { name: 'break' },
    ...colorsExtension,

    ...historyExtension,
]

const checkCurrentHeading = () => {
    currentEditorActive.value.heading = editor.getAttributes('heading').level || undefined
}

//  Watch current heading by focus
watch(
    () => editor.isActive('heading'),
    () => {
        checkCurrentHeading()
    }
)

const checkCurrentTextStyle = () => {
    const textStyle = editor.getAttributes('textStyle')
    currentEditorActive.value.fontFamily = textStyle.fontFamily || 'unset'
    currentEditorActive.value.fontSize = textStyle.fontSize || 'unset'
}

//  Watch current heading by focus
watch(
    () => editor.isActive('textStyle'),
    () => {
        checkCurrentTextStyle()
    }
)

// Keep toolbar state in sync with selection changes
const selectionHandler = () => {
    checkCurrentHeading()
    checkCurrentTextStyle()
}

onMounted(() => {
    editor.on('selectionUpdate', selectionHandler)
    editor.on('transaction', selectionHandler)
})

onBeforeUnmount(() => {
    editor.off('selectionUpdate', selectionHandler)
    editor.off('transaction', selectionHandler)
})
</script>

<template>
    <div class="flex flex-wrap gap-x-1 gap-y-0.5">
        <a-popover v-if="!compact" class="max-w-xs">
            <template #title>{{ t('text.form.accepted-markdown') }}</template>
            <template #content>
                <div v-html="t('text.form.markdown-content')"></div>
            </template>

            <button
                type="button"
                class="hover:bg-gray-100 text-cyan-600 text-center text-base rounded-sm w-6 h-6 inline-flex items-center justify-center"
            >
                <i-icon icon="information" class="size-4" />
            </button>
        </a-popover>
        <tip-tap-editor-toolbar-buttons
            v-if="!isActive('table')"
            :currentEditorActive="currentEditorActive"
            :editor="editor"
            :toolbars="toolbars"
        />
        <tip-tap-editor-toolbar-buttons
            v-else
            :currentEditorActive="currentEditorActive"
            :editor="editor"
            :toolbars="tableToolbar"
        />
    </div>
</template>
