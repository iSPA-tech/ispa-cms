<!--
    Rich text field of the widget live preview: the shared compact Tiptap editor behind a
    three-button toolbar (bold, italic, link), as in the approved mockup. `touch` grows the
    controls to 44px for the phone bottom sheet.
-->
<template>
    <div
        class="overflow-hidden border border-[#17a2b8] bg-white shadow-[0_0_0_2px_rgba(23,162,184,.18)]"
        :class="touch ? 'rounded-lg' : 'rounded-md'"
    >
        <div
            class="flex items-center border-b border-[#e5e7eb] bg-[#f8fafb]"
            :class="touch ? 'gap-1 px-1 py-0.5' : 'gap-0.5 px-1.5 py-1'"
            role="toolbar"
            :aria-label="label"
        >
            <button
                v-for="item in marks"
                :key="item.name"
                type="button"
                :class="[buttonClass, item.class, isOn(item.name) ? onClass : offClass]"
                :aria-label="item.title"
                :aria-pressed="isOn(item.name)"
                :title="item.title"
                :disabled="!editor"
                @click="item.run"
            >
                {{ item.text }}
            </button>
            <button
                type="button"
                :class="[buttonClass, linkOpen || isOn('link') ? onClass : offClass]"
                :aria-label="t('text.form.link-select')"
                :aria-expanded="linkOpen"
                :title="t('text.form.link-select')"
                :disabled="!editor"
                @click="toggleLink"
            >
                <svg viewBox="0 0 24 24" :class="touch ? 'size-5' : 'size-4'" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" />
                </svg>
            </button>
        </div>
        <form v-if="linkOpen" class="flex flex-wrap items-center gap-2 border-b border-[#e5e7eb] bg-white px-2 py-1.5" @submit.prevent="applyLink">
            <input
                ref="linkInput"
                v-model.trim="href"
                type="url"
                inputmode="url"
                class="min-w-0 flex-1 rounded-md border border-[#d0d5db] px-2.5 text-[#1f2933] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#0b6f7f]"
                :class="touch ? 'h-11 text-base' : 'h-8 text-sm'"
                :aria-label="t('text.menu-url')"
                :placeholder="t('text.menu-url')"
                @keydown.esc.stop="closeLink"
            />
            <button type="submit" :class="[textButtonClass, 'border-[#0f7f91] bg-[#0f7f91] font-semibold text-white']">
                {{ t('nav.b.confirm') }}
            </button>
            <button v-if="isOn('link')" type="button" :class="[textButtonClass, 'border-[#d0d5db] bg-white text-[#1f2933]']" @click="removeLink">
                {{ t('text.form.delete-link') }}
            </button>
        </form>
        <tip-tap-editor
            compact
            hide-toolbar
            allow-link
            :class="editorClass"
            :label="label"
            :model-value="modelValue"
            @update:model-value="(html) => emit('update:modelValue', html)"
            @ready="onReady"
        />
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import TipTapEditor from '@/components/tiptap-editor.vue'

defineOptions({ name: 'WidgetPreviewRichText' })

const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, default: '' },
    touch: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const editor = shallowRef(null)
// Tiptap state is not reactive: bump on every transaction so pressed states re-render.
const tick = ref(0)
const linkOpen = ref(false)
const linkInput = ref(null)
const href = ref('')
const bump = () => tick.value++

const buttonClass = computed(() =>
    [
        'inline-flex items-center justify-center border-0 cursor-pointer disabled:cursor-default disabled:opacity-50',
        'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#0b6f7f]',
        props.touch ? 'size-11 rounded-md text-[17px]' : 'size-7 rounded text-sm',
    ].join(' ')
)
const textButtonClass = computed(() =>
    [
        'cursor-pointer rounded-md border px-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0b6f7f]',
        props.touch ? 'h-11 text-base' : 'h-8 text-sm',
    ].join(' ')
)
const onClass = 'bg-[#e6f5f8] text-[#0b6f7f]'
const offClass = 'bg-transparent text-[#1f2933] hover:bg-[#eef1f4]'
// The shared editor ships its own frame and a tall minimum height: flatten it into this box.
const editorClass = computed(() =>
    [
        '[&_.v-editor]:border-0! [&_.v-editor]:rounded-none! [&_.v-editor]:px-3! [&_.v-editor]:leading-[1.6] [&_.v-editor]:text-[#1f2933]',
        props.touch
            ? '[&_.v-editor]:min-h-[132px]! [&_.v-editor]:py-3! [&_.v-editor]:text-base'
            : '[&_.v-editor]:min-h-24! [&_.v-editor]:max-h-60! [&_.v-editor]:py-2.5! [&_.v-editor]:text-sm',
    ].join(' ')
)

const isOn = (name) => (tick.value, !!editor.value?.isActive(name))
const marks = computed(() => [
    { name: 'bold', text: 'B', class: 'font-serif font-bold', title: t('text.form.bold'), run: () => editor.value?.chain().focus().toggleBold().run() },
    { name: 'italic', text: 'I', class: 'font-serif font-semibold italic', title: t('text.form.italic'), run: () => editor.value?.chain().focus().toggleItalic().run() },
])

function onReady(instance) {
    editor.value = instance
    instance.on('transaction', bump)
}
function toggleLink() {
    linkOpen.value = !linkOpen.value
    if (!linkOpen.value) return
    href.value = editor.value?.getAttributes('link').href || ''
    nextTick(() => linkInput.value?.focus())
}
// Back to the text, so the next Esc reaches the popover and closes it.
function closeLink() {
    linkOpen.value = false
    editor.value?.commands.focus()
}
function applyLink() {
    if (!href.value) return removeLink()
    editor.value?.chain().focus().extendMarkRange('link').setLink({ href: href.value }).run()
    linkOpen.value = false
}
function removeLink() {
    editor.value?.chain().focus().extendMarkRange('link').unsetLink().run()
    linkOpen.value = false
}

onBeforeUnmount(() => editor.value?.off('transaction', bump))
</script>
