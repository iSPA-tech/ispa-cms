<template>
    <div class="space-y-1" data-testid="data-token-input">
        <div
            class="relative rounded-lg border border-solid px-2 py-1 focus-within:border-cyan-600 focus-within:ring-1 focus-within:ring-cyan-600"
            :class="disabled ? 'border-gray-200 bg-gray-50' : 'border-gray-300 bg-white'"
        >
            <div
                ref="editorRef"
                role="textbox"
                tabindex="0"
                class="min-h-[1.75rem] whitespace-pre-wrap break-words py-0.5 text-sm leading-6 text-gray-900 outline-none"
                :class="multiline ? 'min-h-[4.5rem]' : ''"
                :contenteditable="disabled ? 'false' : 'true'"
                :aria-multiline="multiline ? 'true' : 'false'"
                :aria-label="ariaLabel || placeholder"
                :aria-disabled="disabled ? 'true' : 'false'"
                spellcheck="false"
                data-testid="data-token-editor"
                @input="onInput"
                @keydown="onKeydown"
                @paste="onPaste"
                @drop.prevent
                @blur="rememberCaret"
                @keyup="rememberCaret"
                @mouseup="rememberCaret"
                @click="onClick"
            />
            <span
                v-if="isEmpty && placeholder"
                class="pointer-events-none absolute left-2 right-2 top-1.5 truncate text-sm leading-6 text-gray-500"
                aria-hidden="true"
            >
                {{ placeholder }}
            </span>
            <a-button
                v-if="!disabled"
                type="link"
                size="small"
                class="!ml-auto !block !h-auto !px-0 !py-0.5"
                :aria-expanded="pickerOpen ? 'true' : 'false'"
                data-testid="data-token-insert"
                @mousedown.prevent
                @click="pickerOpen = !pickerOpen"
            >
                + {{ t('text.workflow.data.insert') }}
            </a-button>
        </div>
        <div
            v-if="mentionOpen && !disabled && mentions.length"
            role="listbox"
            :aria-label="t('text.workflow.pick.mention_title')"
            class="flex flex-wrap items-center gap-1 rounded-lg border border-solid border-gray-300 bg-white p-2 shadow"
            data-testid="data-token-mentions"
        >
            <span class="text-xs text-gray-600">{{ t('text.workflow.pick.mention_title') }}</span>
            <a-button v-for="name in mentions" :key="name" size="small" role="option" :data-mention="name" @mousedown.prevent @click="insertMention(name)">
                {{ name }}
            </a-button>
        </div>
        <DataPicker
            v-if="pickerOpen && !disabled"
            :groups="stepData.groups.value"
            :loading="stepData.loading.value"
            :has-sample="stepData.hasSample.value"
            :unplaced="!stepData.placed.value"
            @pick="insertPath"
            @close="pickerOpen = false"
        />
    </div>
</template>

<script setup>
import { nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataPicker from './DataPicker.vue'
import { parseTemplate, tokenFor } from './dataTokens.js'
import { useStepData } from './useStepData.js'

/**
 * Text with data chips for a step setting stored as a template (`Church story: {{ vars.draft.name }}`).
 * The operator types text and inserts data from a list; a chip keeps the exact placeholder it was read from, so an
 * untouched value is saved back byte-identical.
 *
 * The editable box is filled by hand (not by the Vue renderer): re-rendering it while a person types, above all with
 * an input method such as Telex, would drop characters and the caret.
 */
defineOptions({ name: 'DataTokenInput' })

const props = defineProps({
    modelValue: { type: null, default: '' },
    multiline: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
    ariaLabel: { type: String, default: '' },
    /** Names offered when the operator types @ (the named inputs of an AI step); the name is inserted as text. */
    mentions: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const stepData = useStepData()
const editorRef = ref(null)
const pickerOpen = ref(false)
const isEmpty = ref(true)

const CHIP = 'mx-0.5 inline-block max-w-full select-none truncate rounded-md border border-solid px-2 align-middle text-xs leading-5'
const CHIP_DATA = `${CHIP} border-cyan-200 bg-cyan-50 text-cyan-900`
const CHIP_FORMULA = `${CHIP} border-slate-300 bg-slate-100 text-slate-700`

const textOf = (value) => (value === null || value === undefined ? '' : String(value))

const chipLabel = (segment) => (segment.path ? stepData.labelFor(segment.path) : t('text.workflow.data.formula'))

const chipNode = (segment) => {
    const chip = document.createElement('span')
    chip.setAttribute('contenteditable', 'false')
    chip.dataset.raw = segment.raw
    if (segment.path) chip.dataset.path = segment.path
    chip.className = segment.path ? CHIP_DATA : CHIP_FORMULA
    chip.textContent = chipLabel(segment)
    // The stored placeholder stays one hover away for the people who know the syntax.
    chip.title = segment.raw
    return chip
}

/** The stored text of what the box shows now: text as typed, chips as the placeholder they stand for. */
const readNode = (node) => {
    if (node.nodeType === Node.TEXT_NODE) return node.nodeValue || ''
    if (node.nodeType !== Node.ELEMENT_NODE) return ''
    if (node.dataset?.raw !== undefined) return node.dataset.raw
    // The line break kept at the very end so the last empty line can hold the caret is not content.
    if (node.tagName === 'BR') return node.dataset.filler !== undefined ? '' : '\n'
    const inner = Array.from(node.childNodes).map(readNode).join('')
    // A browser may wrap a new line in a block of its own.
    return node.tagName === 'DIV' || node.tagName === 'P' ? `\n${inner}` : inner
}
const read = () => (editorRef.value ? Array.from(editorRef.value.childNodes).map(readNode).join('') : '')

/** A text that ends with a new line needs one line break after it, or the browser shows no last line to type on. */
const keepLastLine = (value) => {
    const editor = editorRef.value
    if (!editor) return
    const filler = editor.querySelector(':scope > br[data-filler]')
    if (!value.endsWith('\n')) return filler?.remove()
    if (filler && filler === editor.lastChild) return
    filler?.remove()
    const line = document.createElement('br')
    line.dataset.filler = ''
    editor.appendChild(line)
}

let shown = null
const render = (value) => {
    const editor = editorRef.value
    if (!editor) return
    const nodes = parseTemplate(value).map((segment) => (segment.type === 'token' ? chipNode(segment) : document.createTextNode(segment.value)))
    editor.replaceChildren(...nodes)
    shown = textOf(value)
    isEmpty.value = shown === ''
    keepLastLine(shown)
}

const relabel = () => {
    editorRef.value?.querySelectorAll('[data-path]').forEach((chip) => {
        chip.textContent = stepData.labelFor(chip.dataset.path)
    })
}

const commit = () => {
    // An emptied box may keep one stray line break: that is an empty value, not a new line.
    const only = editorRef.value?.childNodes.length === 1 ? editorRef.value.firstChild : null
    if (only?.nodeName === 'BR') only.remove()
    const value = read()
    isEmpty.value = value === ''
    keepLastLine(value)
    if (value === shown) return
    shown = value
    emit('update:modelValue', value)
}

const mentionOpen = ref(false)
/** The @ right before the caret, when the operator just typed one. */
const atBeforeCaret = () => {
    const selection = window.getSelection?.()
    const range = selection?.rangeCount ? selection.getRangeAt(0) : null
    const node = range?.startContainer
    if (!node || node.nodeType !== Node.TEXT_NODE || !editorRef.value?.contains(node)) return null
    return range.startOffset > 0 && node.nodeValue[range.startOffset - 1] === '@' ? { node, offset: range.startOffset } : null
}

const onInput = () => {
    commit()
    if (props.mentions.length) mentionOpen.value = !!atBeforeCaret()
}

// ---------------------------------------------------------------------------------------------------------------------
// Caret
// ---------------------------------------------------------------------------------------------------------------------

let caret = null
const rememberCaret = () => {
    const editor = editorRef.value
    const selection = window.getSelection?.()
    if (!editor || !selection || !selection.rangeCount) return
    const range = selection.getRangeAt(0)
    if (editor.contains(range.startContainer) && editor.contains(range.endContainer)) caret = range.cloneRange()
}

const placeCaretAfter = (node) => {
    const range = document.createRange()
    range.setStartAfter(node)
    range.collapse(true)
    caret = range
    const selection = window.getSelection?.()
    if (selection) {
        selection.removeAllRanges()
        selection.addRange(range)
    }
}

/** A chip cannot hold the caret: a click on one puts the caret right after it, so typing goes on from there. */
const onClick = (event) => {
    const chip = event.target?.closest?.('[data-raw]')
    if (!chip || props.disabled || !editorRef.value?.contains(chip)) return
    editorRef.value.focus()
    placeCaretAfter(chip)
}

const insertAtCaret = (node) => {
    const editor = editorRef.value
    if (!editor) return
    const inside = caret && editor.contains(caret.startContainer) && editor.contains(caret.endContainer)
    if (inside) {
        caret.deleteContents()
        caret.insertNode(node)
    } else {
        editor.appendChild(node)
    }
    placeCaretAfter(node)
}

const insertText = (text) => {
    if (!text) return
    rememberCaret()
    insertAtCaret(document.createTextNode(text))
    editorRef.value?.normalize()
    commit()
}

const insertPath = (path) => {
    insertAtCaret(chipNode(tokenFor(path)))
    pickerOpen.value = false
    commit()
    nextTick(() => editorRef.value?.focus?.())
}

/** Replace the typed @ with the input's name. */
const insertMention = (name) => {
    editorRef.value?.focus?.()
    const at = atBeforeCaret()
    mentionOpen.value = false
    if (!at) return insertText(name)
    at.node.nodeValue = `${at.node.nodeValue.slice(0, at.offset - 1)}${name}${at.node.nodeValue.slice(at.offset)}`
    const range = document.createRange()
    range.setStart(at.node, at.offset - 1 + name.length)
    range.collapse(true)
    const selection = window.getSelection()
    selection.removeAllRanges()
    selection.addRange(range)
    caret = range
    commit()
}

const onKeydown = (event) => {
    if (event.key === 'Escape' && mentionOpen.value) {
        event.stopPropagation()
        mentionOpen.value = false
        return
    }
    if (event.key !== 'Enter' || event.isComposing) return
    event.preventDefault()
    if (props.multiline) insertText('\n')
}

const onPaste = (event) => {
    event.preventDefault()
    const text = event.clipboardData?.getData('text/plain') || ''
    insertText(props.multiline ? text : text.replace(/\s*\n\s*/g, ' '))
}

onMounted(() => render(props.modelValue))
watch(
    () => props.modelValue,
    (value) => {
        if (textOf(value) !== shown) render(value)
    }
)
watch(() => stepData.groups.value, relabel)

defineExpose({ insertText, insertPath })
</script>
