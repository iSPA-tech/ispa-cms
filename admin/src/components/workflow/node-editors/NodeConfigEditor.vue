<template>
    <div class="flex flex-col gap-2" data-testid="node-config-editor">
        <div class="flex items-center gap-2">
            <p class="mb-0 text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.config.title') }}</p>
            <span v-if="isAlias" class="text-xs text-amber-600">{{ t('text.workflow.editor.config.legacy_alias') }}</span>
        </div>

        <a-alert v-if="readOnly" type="info" show-icon :message="t('text.workflow.editor.config.read_only_notice')" />

        <component
            v-if="EditorComponent"
            :is="EditorComponent"
            :step="step"
            :step-type="stepType"
            :model-value="modelValue"
            :root-model="rootModel"
            :steps="steps"
            :read-only="readOnly"
            @update:model-value="onUpdate"
        />
        <a-alert v-else type="info" show-icon :message="t('text.workflow.editor.json_fallback.no_editor_notice')" />

        <!-- Raw settings: Webmasters only, at the end of the form, behind a warning. -->
        <div v-if="canAdvanced" class="space-y-2 border-0 border-t border-solid border-gray-200 pt-3">
            <div class="flex flex-wrap items-center gap-x-2">
                <a-button type="link" class="!h-auto !p-0" :aria-expanded="showAdvanced ? 'true' : 'false'" @click="showAdvanced = !showAdvanced">
                    {{ showAdvanced ? t('text.workflow.advanced.hide') : t('text.workflow.advanced.title') }}
                </a-button>
                <span class="text-xs text-gray-600">{{ t('text.workflow.advanced.webmaster_only') }}</span>
            </div>
            <AdvancedConfig v-if="showAdvanced" :value="modelValue" :read-only="readOnly" @update:value="onUpdate" />
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AdvancedConfig from './AdvancedConfig.vue'
import { resolveEditorComponent } from './editorRegistry.js'
import { isWebmaster } from '@/hooks/useCan'
import { useNodeCatalog } from '@/components/workflows/nodeCatalog'

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

const { findNode } = useNodeCatalog()
const EditorComponent = computed(() => resolveEditorComponent(props.stepType, findNode(props.stepType)))
const canAdvanced = computed(() => isWebmaster())
const showAdvanced = ref(false)

const isAlias = computed(() => {
    const aliases = ['condition', 'output', 'output_text', 'discord_service', 'service']
    return aliases.includes(props.stepType)
})

const onUpdate = (value) => {
    emit('update:modelValue', value)
}
</script>
