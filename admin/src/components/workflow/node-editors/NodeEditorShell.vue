<template>
    <div v-if="node" class="node-editor-shell">
        <div class="flex items-start justify-between mb-3">
            <div>
                <p class="mb-0 text-sm font-semibold text-gray-800">
                    {{ node.data?.name || node.id }}
                </p>
                <p class="mb-0 text-xs text-gray-500">
                    {{ t('text.workflow.editor.shell.step_type', { type: stepType || t('text.workflow.editor.shell.unknown') }) }}
                </p>
            </div>
            <a-switch v-model:checked="showRaw" size="small" :disabled="readOnly || !EditorComponent">
                <template #checked>{{ t('text.workflow.editor.mode.raw') }}</template>
                <template #unchecked>{{ t('text.workflow.editor.mode.editor') }}</template>
            </a-switch>
        </div>

        <component
            v-if="EditorComponent && !showRaw"
            :is="EditorComponent"
            :step="node"
            :step-type="stepType"
            :root-model="modelClass"
            :steps="steps"
            :read-only="readOnly"
            v-model:model-value="localConfig"
        />
        <JsonViewerFallback
            v-else
            :value="localConfig"
            :read-only="readOnly || (!EditorComponent && !showRaw)"
            @update:value="onFallbackUpdate"
        />
    </div>
    <div v-else class="text-gray-500 text-sm">{{ t('text.workflow.editor.shell.select_node') }}</div>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { getEditorComponent } from './editorRegistry.js'
import JsonViewerFallback from './JsonViewerFallback.vue'

const props = defineProps({
    selectedNode: {
        type: Object,
        default: null,
    },
    modelClass: {
        type: String,
        default: null,
    },
    steps: {
        type: Array,
        default: () => [],
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['updateNodeConfig'])

const { t } = useI18n()

const node = computed(() => props.selectedNode || null)
const detectStepTypeForEditor = (data = {}) => {
    const cfg = data.step_config || {}
    const code = data.step_code || ''
    const st = data.step_type || ''
    if ((st === 'event' && cfg.node_type === 'input') || cfg.node_type === 'input' || st === 'input') return 'input'
    if (cfg.node_type === 'output' || st === 'output') return 'output'
    if (st === 'decision') return 'decision'
    if (st === 'ai_action') return 'ai_action'
    if (code === 'log_message' || (cfg.level !== undefined && cfg.message !== undefined && !(cfg.class && cfg.method))) {
        return 'log_message'
    }
    if (st === 'set_variable') return 'set_variable'
    if (code === 'retrieve' || cfg.where !== undefined || cfg.model !== undefined) return 'retrieve'
    if (code === 'change' || cfg.targetExpr !== undefined || cfg.changes !== undefined) return 'change'
    if (cfg.class && cfg.method) return 'blueprint_callable'
    return st || 'custom'
}

const stepType = computed(() => detectStepTypeForEditor(node.value?.data || {}))
const EditorComponent = computed(() => getEditorComponent(stepType.value))
const showRaw = ref(false)
const localConfig = ref({})
const isSyncing = ref(false)

watch(
    () => node.value,
    async (val) => {
        isSyncing.value = true
        const cfg = val?.data?.step_config
        if (cfg && typeof cfg === 'object') {
            localConfig.value = JSON.parse(JSON.stringify(cfg))
        } else {
            localConfig.value = {}
        }
        await nextTick()
        isSyncing.value = false
    },
    { immediate: true, deep: true }
)

watch(
    () => localConfig.value,
    (val) => {
        if (isSyncing.value) return
        if (!node.value) return
        emit('updateNodeConfig', node.value.id, val)
    },
    { deep: true }
)

const onFallbackUpdate = (val) => {
    localConfig.value = val
}
</script>

<style scoped>
.node-editor-shell {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
</style>
