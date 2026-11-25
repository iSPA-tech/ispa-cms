<template>
    <div class="space-y-2" data-testid="advanced-config">
        <a-alert type="warning" show-icon :message="t('text.workflow.advanced.warning')" />
        <a-textarea
            v-model:value="text"
            :disabled="readOnly"
            :auto-size="{ minRows: 6, maxRows: 18 }"
            :aria-label="t('text.workflow.advanced.title')"
            class="font-mono text-xs"
            spellcheck="false"
            data-testid="advanced-config-json"
            @blur="commit"
        />
        <p v-if="invalid" class="mb-0 break-words text-xs text-red-700" role="alert" data-testid="advanced-config-invalid">
            {{ t('text.workflow.schema_form.json_invalid') }}
        </p>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Advanced mode of the step form (Webmasters only): the raw settings of the step as JSON. A text that is not valid
 * JSON, or not a group of settings, is not saved.
 */
defineOptions({ name: 'AdvancedConfig' })

const props = defineProps({
    value: { type: null, default: null },
    readOnly: { type: Boolean, default: false },
})
const emit = defineEmits(['update:value'])

const { t } = useI18n()
const text = ref('')
const invalid = ref(false)

const pretty = (value) => {
    try {
        return JSON.stringify(value ?? {}, null, 2)
    } catch (error) {
        return ''
    }
}

watch(
    () => props.value,
    (value) => {
        // Text being typed is kept unless the settings really changed underneath it.
        let current
        try {
            current = JSON.stringify(JSON.parse(text.value))
        } catch (error) {
            current = null
        }
        if (current === JSON.stringify(value ?? {})) return
        text.value = pretty(value)
        invalid.value = false
    },
    { immediate: true, deep: true }
)

const commit = () => {
    if (props.readOnly) return
    let parsed
    try {
        parsed = text.value.trim() ? JSON.parse(text.value) : {}
    } catch (error) {
        invalid.value = true
        return
    }
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
        invalid.value = true
        return
    }
    invalid.value = false
    if (JSON.stringify(parsed) !== JSON.stringify(props.value ?? {})) emit('update:value', parsed)
}
</script>
