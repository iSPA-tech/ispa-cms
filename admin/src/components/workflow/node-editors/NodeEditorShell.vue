<template>
    <div v-if="node" class="flex flex-col gap-3" data-testid="node-editor-shell">
        <p class="mb-0 break-words text-sm font-semibold text-gray-800" data-testid="node-editor-type">
            {{ typeLabel || node.data?.name || node.id }}
        </p>

        <div v-if="definition" class="space-y-1" data-testid="node-definition-summary">
            <p v-if="definitionSummary" class="mb-0 break-words text-xs text-gray-500">{{ definitionSummary }}</p>
            <NodeSafetyBadges :node="definition" />
        </div>

        <a-alert v-if="nodeErrors.length" type="error" show-icon data-testid="node-save-errors">
            <template #message>
                <ul class="m-0 list-none space-y-1 p-0">
                    <li v-for="(row, index) in nodeErrors" :key="index" class="break-words text-xs">
                        {{ t(`text.workflow.issue.${row.code}`) }}
                        <span v-if="row.message" class="block text-gray-500">{{ row.message }}</span>
                    </li>
                </ul>
            </template>
        </a-alert>
        <a-alert
            v-if="nodeWarning"
            type="warning"
            show-icon
            data-testid="node-taint-warning"
            :message="t('text.workflow.warning.taint_node')"
        />

        <component
            v-if="EditorComponent"
            :is="EditorComponent"
            :step="node"
            :step-type="stepType"
            :root-model="modelClass"
            :steps="steps"
            :read-only="readOnly"
            v-model:model-value="localConfig"
        />
        <a-alert
            v-else
            type="info"
            show-icon
            data-testid="node-editor-no-form"
            :message="t('text.workflow.editor.json_fallback.no_editor_notice')"
        />

        <OnErrorSetting
            v-if="showOnError"
            :mode="errorMode"
            :can-skip="!!mainTarget"
            :own-target="ownTargetName"
            :step-key="String(node.id)"
            :disabled="readOnly"
            @change="(mode) => emit('updateOnError', node.id, mode)"
        />

        <!-- Raw settings: Webmasters only, at the end of the form, behind a warning. -->
        <div v-if="canAdvanced" class="space-y-2 border-0 border-t border-solid border-gray-200 pt-3" data-testid="node-editor-advanced">
            <div class="flex flex-wrap items-center gap-x-2">
                <a-button
                    type="link"
                    class="!h-auto !p-0"
                    :aria-expanded="showAdvanced ? 'true' : 'false'"
                    data-testid="node-editor-advanced-toggle"
                    @click="showAdvanced = !showAdvanced"
                >
                    {{ showAdvanced ? t('text.workflow.advanced.hide') : t('text.workflow.advanced.title') }}
                </a-button>
                <span class="text-xs text-gray-600">{{ t('text.workflow.advanced.webmaster_only') }}</span>
            </div>
            <AdvancedConfig v-if="showAdvanced" :value="localConfig" :read-only="readOnly" @update:value="onAdvancedUpdate" />
        </div>
    </div>
    <div v-else class="text-sm text-gray-500">{{ t('text.workflow.editor.shell.select_node') }}</div>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { editorRegistry, hasSchemaFields, resolveEditorComponent } from './editorRegistry.js'
import AdvancedConfig from './AdvancedConfig.vue'
import OnErrorSetting from './OnErrorSetting.vue'
import { ON_ERROR_OWN, errorModeOf, errorTargetOf, mainTargetOf, offersOnError } from './onErrorRule.js'
import { provideStepData } from '@/components/workflow/inputs/useStepData.js'
import { isWebmaster } from '@/hooks/useCan'
import NodeSafetyBadges from '@/components/workflow/NodeSafetyBadges.vue'
import { nodeDescription, nodeLabel, useNodeCatalog, useNodeIssues } from '@/components/workflows/nodeCatalog'

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
    /** Links of the designer graph: the data picker orders the steps with them, "when this step fails" reads them. */
    edges: {
        type: Array,
        default: () => [],
    },
    /** Uuid of the workflow, for sample values from its last run. */
    workflowId: {
        type: [Number, String],
        default: null,
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
})

/** `updateOnError(nodeId, mode)`: the designer applies the choice to its links (see onErrorRule.js). */
const emit = defineEmits(['updateNodeConfig', 'updateOnError'])

const { t } = useI18n()
const { catalog, findNode } = useNodeCatalog()
const { errorsFor, warningFor } = useNodeIssues()

provideStepData({
    nodes: () => props.steps,
    edges: () => props.edges,
    currentId: () => props.selectedNode?.id ?? null,
    projectId: () => props.workflowId,
    rootModel: () => props.modelClass,
    readOnly: () => props.readOnly,
})

const node = computed(() => props.selectedNode || null)
const detectStepTypeForEditor = (data = {}) => {
    const cfg = data.step_config || {}
    const code = data.step_code || ''
    const st = data.step_type || ''
    if ((st === 'event' && cfg.node_type === 'input') || cfg.node_type === 'input' || st === 'input') return 'input'
    if (cfg.node_type === 'output' || st === 'output') return 'output'
    if (st === 'decision') return 'decision'
    // A step type with its own editor, or one the node catalog describes, is taken as stored; the guesses below
    // are only for steps saved before step types were reliable.
    if (st && (editorRegistry[st] || hasSchemaFields(findNode(st)))) return st
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
const definition = computed(() => findNode(stepType.value))
const definitionSummary = computed(() => nodeDescription(t, definition.value))
// The step's kind in the operator's words; a step the catalog does not know shows its stored type.
const typeLabel = computed(() => (definition.value ? nodeLabel(t, definition.value) : stepType.value && stepType.value !== 'custom' ? stepType.value : ''))
const EditorComponent = computed(() => resolveEditorComponent(stepType.value, definition.value))
const nodeErrors = computed(() => (node.value ? errorsFor(node.value) : []))
const nodeWarning = computed(() => (node.value ? warningFor(node.value) : null))
// Advanced mode (the raw settings) is for Webmasters only; everyone else never sees it.
const canAdvanced = computed(() => isWebmaster())
const showAdvanced = ref(false)

// "When this step fails" is read from the links of the diagram.
const showOnError = computed(() => offersOnError(node.value, definition.value, catalog.byType))
const errorMode = computed(() => errorModeOf(node.value?.id, props.edges))
const mainTarget = computed(() => mainTargetOf(node.value?.id, props.edges))
const ownTargetName = computed(() => {
    if (errorMode.value !== ON_ERROR_OWN) return ''
    const target = errorTargetOf(node.value?.id, props.edges)
    const step = props.steps.find((row) => row.id === target)
    return String(step?.data?.name || step?.data?.step_code || target || '')
})

const localConfig = ref({})
const isSyncing = ref(false)

// Settings of the selected step, copied for editing. The copy is replaced only when another step is opened or
// its stored settings really differ: an editor that tidies its value on mount must not start an update loop.
const sameConfig = (a, b) => JSON.stringify(a ?? {}) === JSON.stringify(b ?? {})
let syncedNodeId = null

watch(
    () => node.value,
    async (val) => {
        const cfg = val?.data?.step_config && typeof val.data.step_config === 'object' ? val.data.step_config : {}
        const id = val?.id ?? null
        if (id === syncedNodeId && sameConfig(cfg, localConfig.value)) return
        if (id !== syncedNodeId) showAdvanced.value = false
        syncedNodeId = id
        isSyncing.value = true
        localConfig.value = JSON.parse(JSON.stringify(cfg))
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
        if (sameConfig(val, node.value.data?.step_config)) return
        emit('updateNodeConfig', node.value.id, JSON.parse(JSON.stringify(val)))
    },
    { deep: true }
)

const onAdvancedUpdate = (val) => {
    localConfig.value = val
}
</script>
