<!--
    Content of the field editor of the widget live preview, shared by the desktop popover and
    the phone bottom sheet (`touch`): header (title, hint, close), the control for the field
    type, and the footer with "Xong".
-->
<template>
    <div class="flex flex-col text-sm text-[#1f2933]" :class="touch ? 'gap-2.5 px-4 pb-7 pt-2' : ''">
        <div v-if="touch" class="h-1 w-10 self-center rounded-sm bg-[#c5cbd3]" aria-hidden="true" />
        <div class="flex items-center gap-2" :class="touch ? '' : 'py-2.5 pl-4 pr-3'">
            <div class="flex min-w-0 flex-1" :class="touch ? 'flex-col' : 'flex-wrap items-baseline gap-x-2'">
                <div class="font-semibold" :class="touch ? 'text-base leading-[22px]' : ''">{{ input.label }}</div>
                <div v-if="subtitle" class="text-xs leading-4 text-[#5b6470]">{{ subtitle }}</div>
            </div>
            <button
                type="button"
                class="flex shrink-0 cursor-pointer items-center justify-center border-0 bg-transparent text-[#4b5563] hover:bg-[#eef1f4] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#0b6f7f]"
                :class="touch ? 'size-11 rounded-lg' : 'size-7 rounded-md'"
                :aria-label="t('nav.b.close')"
                data-action="close"
                @click="emit('close')"
            >
                <svg viewBox="0 0 24 24" :class="touch ? 'size-5' : 'size-4'" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>
        <div :class="touch ? '' : 'px-4'">
            <widget-preview-rich-text
                v-if="mode === 'rich'"
                :label="input.label"
                :model-value="values[input.name] || ''"
                :touch="touch"
                @update:model-value="(html) => emit('rich-change', html)"
            />
            <widget-preview-image-picker
                v-else-if="mode === 'image'"
                :limit="limit"
                :model-value="values[input.name] || []"
                :touch="touch"
                @update:model-value="(files) => emit('image-change', files)"
                @close="emit('close')"
            />
            <widget-input-fields v-else :inputs="[input]" :model-value="values" @update:model-value="(next) => emit('fields-change', next)" />
        </div>
        <div v-if="mode === 'image'" :class="touch ? '' : 'h-3.5'" />
        <div v-else class="flex items-center gap-2" :class="touch ? '' : 'px-4 pb-3.5 pt-3'">
            <div v-if="!touch" class="min-w-0 flex-1 text-xs text-[#5b6470]">
                {{ mode === 'rich' ? hint : '' }}
            </div>
            <a-button
                type="primary"
                class="border-[#0f7f91]! bg-[#0f7f91]! font-semibold! shadow-none! focus-visible:outline-2! focus-visible:outline-offset-2! focus-visible:outline-[#0b6f7f]!"
                :class="touch ? 'h-12! w-full justify-center! rounded-lg! text-base!' : 'h-8! rounded-md! px-[18px]!'"
                @click="emit('close')"
            >
                {{ t('text.widget.edit_on_preview.done') }}
            </a-button>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import WidgetInputFields from '@/components/widget-input-fields.vue'
import WidgetPreviewImagePicker from '@/components/widget-preview-image-picker.vue'
import WidgetPreviewRichText from '@/components/widget-preview-rich-text.vue'

defineOptions({ name: 'WidgetPreviewPanel' })

const props = defineProps({
    input: { type: Object, required: true },
    values: { type: Object, default: () => ({}) },
    touch: { type: Boolean, default: false },
    // Footer hint of the desktop popover (the live preview sits beside or above it).
    hint: { type: String, default: '' },
})
const emit = defineEmits(['rich-change', 'image-change', 'fields-change', 'close'])
const { t } = useI18n()

// Rich text and library-backed images get their own in-place control; an image input that is a
// plain URL (and anything else) keeps the shared field grid.
const mode = computed(() => {
    if (props.input.type === 'textarea') return 'rich'
    if (props.input.type === 'image' && props.input.options?.src === 'unknown') return 'image'
    return 'fields'
})
// Same rule as widget-input-fields' media limit.
const limit = computed(() => props.input.media_limit ?? props.input.options?.limit ?? 1)
const subtitle = computed(() => {
    if (mode.value === 'image') return t('text.widget.edit_on_preview.image_hint')
    if (mode.value !== 'rich') return ''
    return props.touch ? t('text.widget.edit_on_preview.live_hint_above') : t('text.widget.edit_on_preview.rich_text_hint')
})
</script>
