<template>
    <div class="space-y-1" data-testid="store-name-input">
        <a-input
            :value="shown"
            :disabled="disabled"
            :placeholder="placeholder"
            :aria-label="label"
            :maxlength="80"
            data-testid="store-name"
            @update:value="onInput"
        />
        <p v-if="unusable" class="mb-0 break-words text-xs text-amber-700" data-testid="store-name-unusable">
            {{ t('text.workflow.schema_form.store_hint') }}
        </p>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * The name a step gives to the data it produces. The engine stores it as `vars.<name>`; the operator only sees and
 * types the name. A stored value is rewritten only when the name is edited.
 */
defineOptions({ name: 'StoreNameInput' })

const PREFIX = 'vars.'

const props = defineProps({
    modelValue: { type: null, default: '' },
    disabled: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
    label: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const stored = computed(() => (typeof props.modelValue === 'string' ? props.modelValue : ''))
const shown = computed(() => (stored.value.startsWith(PREFIX) ? stored.value.slice(PREFIX.length) : stored.value))
/** A name saved without its prefix is not readable by later steps until it is typed again. */
const unusable = computed(() => stored.value !== '' && !stored.value.startsWith(PREFIX))

const onInput = (value) => {
    const name = String(value ?? '')
        .trim()
        .replace(/^vars\./, '')
        .replace(/\s+/g, '_')
    emit('update:modelValue', name ? `${PREFIX}${name}` : '')
}
</script>
