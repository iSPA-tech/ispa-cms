<template>
    <a-popover
        v-model:open="popoverState"
        placement="bottom"
        :title="t('text.editor.math.title')"
        trigger="click"
    >
        <template #content>
            <a-form
                :model="innerValue"
                class="w-[420px] max-w-[80vw]"
                layout="vertical"
                @finish="submitForm"
            >
                <a-form-item
                    class="mb-2!"
                    :label="t('text.editor.math.source')"
                    name="latex"
                >
                    <a-textarea
                        ref="sourceInput"
                        v-model:value="innerValue.latex"
                        autofocus
                        class="font-mono text-xs"
                        :placeholder="latexPlaceholder"
                        :rows="4"
                        spellcheck="false"
                    />
                </a-form-item>

                <div class="mb-2">
                    <div class="mb-1 text-xs text-gray-500">
                        {{ t('text.editor.math.preview') }}
                    </div>
                    <div
                        class="min-h-12 overflow-x-auto rounded-sm border border-dashed border-gray-300 bg-gray-50 p-2"
                    >
                        <!-- Temml output with trust disabled: MathML only, no links or scripts. -->
                        <!-- eslint-disable-next-line vue/no-v-html -->
                        <div v-if="rendered.html" v-html="rendered.html" />
                        <span v-else-if="!rendered.error" class="text-xs text-gray-400">
                            {{ t('text.editor.math.empty') }}
                        </span>
                    </div>
                    <div v-if="rendered.error" class="mt-1 text-xs text-red-600" role="alert">
                        {{ t('text.editor.math.invalid') }}:
                        {{ renderError }}
                    </div>
                </div>

                <a-form-item class="mb-0!">
                    <div class="flex items-center gap-2">
                        <a-button
                            v-if="isCurrentMathBlock()"
                            danger
                            size="small"
                            type="default"
                            @click="removeCurrent"
                        >
                            {{ t('nav.b.delete') }}
                        </a-button>
                        <a-button class="ml-auto" size="small" @click="popoverState = false">
                            {{ t('nav.b.cancel') }}
                        </a-button>
                        <a-button
                            :disabled="!canSave"
                            html-type="submit"
                            size="small"
                            type="primary"
                        >
                            {{ t('nav.b.save') }}
                        </a-button>
                    </div>
                </a-form-item>
            </a-form>
        </template>

        <button
            :aria-label="toolbar?.title || t('text.editor.math.title')"
            :class="
                classNames(
                    isCurrentMathBlock() ? 'bg-cyan-600 text-white' : '',
                    'hover:bg-gray-200 hover:text-gray-900 text-gray-600 text-center text-base rounded-sm',
                    'w-6 h-6 inline-flex items-center justify-center'
                )
            "
            type="button"
            @click="onOpen"
        >
            <i-icon icon="sigma" class="size-4" />
        </button>
    </a-popover>
</template>

<script setup>
import { computed, defineComponent, nextTick, ref } from 'vue'
import { classNames } from '@/mixins'
import { useI18n } from 'vue-i18n'
import { MATH_ERROR_FALLBACK, renderMathMl } from '@/components/tiptap-math-extension'

defineComponent({
    name: 'TipTapMath',
})

const { t } = useI18n()

const { editor, toolbar, isActive } = defineProps({
    editor: {
        type: Object,
    },
    toolbar: {
        type: Object,
    },
    isActive: {
        type: Function,
    },
})

// Static on purpose: curly braces in a vue-i18n message would be parsed as placeholders.
const latexPlaceholder = '\\frac{a}{b} \\times 100\\%'

const innerValue = ref({ latex: '' })
const popoverState = ref(false)
const sourceInput = ref(null)

const isCurrentMathBlock = () => {
    return Boolean(isActive?.('mathBlock'))
}

const rendered = computed(() => renderMathMl(innerValue.value.latex))
// A render failure without a message carries a fallback key instead of text.
const renderError = computed(() =>
    rendered.value.error === MATH_ERROR_FALLBACK ? t(MATH_ERROR_FALLBACK) : rendered.value.error
)

const canSave = computed(() => Boolean(innerValue.value.latex.trim()) && !rendered.value.error)

const onOpen = async () => {
    innerValue.value = {
        latex: isCurrentMathBlock() ? String(editor.getAttributes('mathBlock').latex || '') : '',
    }

    popoverState.value = true

    await nextTick()
    sourceInput.value?.focus?.()
}

const removeCurrent = () => {
    editor.chain().focus().deleteSelection().run()
    popoverState.value = false
}

const submitForm = () => {
    if (!canSave.value) {
        return
    }

    const latex = innerValue.value.latex.trim()

    if (isCurrentMathBlock()) {
        editor.chain().focus().updateMathBlock({ latex }).run()
    } else {
        editor.chain().focus().setMathBlock({ latex }).run()
    }

    popoverState.value = false
}
</script>
