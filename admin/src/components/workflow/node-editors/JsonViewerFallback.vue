<template>
    <div class="json-viewer-fallback">
        <a-alert
            v-if="readOnly"
            type="info"
            show-icon
            :message="$t('text.workflow.editor.json_fallback.no_editor_notice')"
            class="mb-2"
        />
        <a-textarea
            v-model:value="localJson"
            :disabled="readOnly"
            :auto-size="{ minRows: 6, maxRows: 14 }"
            :placeholder="$t('text.workflow.editor.json_fallback.placeholder')"
            @blur="sync"
        />
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
    value: {
        type: null,
        default: null,
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:value'])

const localJson = ref('')

watch(
    () => props.value,
    (val) => {
        try {
            localJson.value = JSON.stringify(val ?? {}, null, 2)
        } catch (error) {
            localJson.value = ''
        }
    },
    { immediate: true, deep: true }
)

const sync = () => {
    if (props.readOnly) return
    try {
        const parsed = localJson.value ? JSON.parse(localJson.value) : {}
        emit('update:value', parsed)
    } catch (error) {
        // ignore parse error, keep previous
    }
}
</script>

<style scoped>
.json-viewer-fallback {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
</style>
