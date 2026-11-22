<template>
    <div class="node-config-editor">
        <div class="flex items-center justify-between mb-2">
            <div class="flex items-center gap-2">
                <p class="text-sm font-semibold text-gray-700 mb-0">{{ t('text.workflow.editor.config.title') }}</p>
                <span v-if="isAlias" class="text-xs text-amber-600">{{ t('text.workflow.editor.config.legacy_alias') }}</span>
            </div>
            <a-switch v-model:checked="showRaw" size="small" :disabled="readOnly">
                <template #checked>{{ t('text.workflow.editor.mode.raw') }}</template>
                <template #unchecked>{{ t('text.workflow.editor.mode.editor') }}</template>
            </a-switch>
        </div>

        <a-alert
            v-if="readOnly"
            type="info"
            show-icon
            :message="t('text.workflow.editor.config.read_only_notice')"
            class="mb-3"
        />

        <component
            v-if="EditorComponent && !showRaw"
            :is="EditorComponent"
            :step="step"
            :step-type="stepType"
            :model-value="modelValue"
            :root-model="rootModel"
            :steps="steps"
            :read-only="readOnly"
            @update:model-value="onUpdate"
        />
        <template v-else>
            <div class="flex items-center justify-between mb-2">
                <p class="text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.config.step_config_json') }}</p>
                <span class="text-xs text-gray-500">{{ t('text.workflow.editor.config.raw_editor') }}</span>
            </div>
            <a-textarea
                v-model:value="rawJson"
                :auto-size="{ minRows: 6, maxRows: 14 }"
                :placeholder="t('text.workflow.editor.config.raw_placeholder')"
                @blur="syncRaw"
                :disabled="readOnly"
            />
        </template>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { getEditorComponent } from './editorRegistry.js'

const props = defineProps({
    step: {
        type: Object,
        default: () => ({}),
    },
    rootModel: {
        type: String,
        default: '',
    },
    steps: {
        type: Array,
        default: () => [],
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
    stepType: {
        type: String,
        default: '',
    },
    modelValue: {
        type: Object,
        default: () => ({}),
    },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const EditorComponent = computed(() => getEditorComponent(props.stepType))
const showRaw = ref(false)

const rawJson = ref('')
const isAlias = computed(() => {
    const aliases = ['condition', 'output', 'output_text', 'discord_service', 'service']
    return aliases.includes(props.stepType)
})

watch(
    () => props.modelValue,
    (val) => {
        try {
            rawJson.value = JSON.stringify(val || {}, null, 2)
        } catch (error) {
            rawJson.value = ''
        }
    },
    { immediate: true, deep: true }
)

const onUpdate = (value) => {
    emit('update:modelValue', value)
}

const syncRaw = () => {
    try {
        const parsed = rawJson.value ? JSON.parse(rawJson.value) : {}
        emit('update:modelValue', parsed)
    } catch (error) {
        // keep previous value if parse fails
    }
}
</script>

<style scoped>
.node-config-editor {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
</style>
