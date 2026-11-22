<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-modal
        :keyboard="false"
        :maskClosable="false"
        :open="open"
        :title="workflow ? workflow.name : $t('nav.a.workflows')"
        class="modal-fullscreen"
        width="100%"
        wrap-class-name="is-fullscreen"
        @cancel="$emit('close')"
        @update:open="
            (value) => {
                if (!value) $emit('close')
            }
        "
    >
        <div class="builder-shell">
            <WorkflowBuilderHeader
                :graph-health-summary="graphHealthSummary"
                :graph-health-tone="graphHealthTone"
                :has-unsaved-changes="hasUnsavedChanges"
                :is-read-only="isReadOnly"
                :saving="saving"
                :target-entity-type="workflow?.target_entity_type || ''"
                :trigger-event="workflow?.trigger_event || ''"
                @review="reviewGraphHealth"
            />
            <form
                id="workflow-design-form"
                class="dnd-flow workflow-builder"
                @drop="onDrop"
                @submit.prevent="saveWorkflowDesign"
            >
                <aside class="builder-side-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="panel-title">{{ t('text.workflow.builder.node_library') }}</p>
                            <p class="panel-subtitle">{{ t('text.workflow.builder.add_steps_desc') }}</p>
                        </div>
                    </div>
                    <VueFlowSidebar :blueprints="groupedFunctions" class="sidebar-panel" />
                </aside>

                <div class="canvas-stack">
                    <div class="canvas-summary-bar">
                        <span>{{ nodes.length }} {{ t('text.workflow.list.steps') }}</span>
                        <span>{{ edges.length }} {{ t('text.workflow.builder.links') }}</span>
                        <span v-if="selectedNode">
                            {{ t('text.workflow.builder.editing') }}: {{ selectedNode.data?.name || selectedNode.id }}
                        </span>
                        <span v-else>{{ t('text.workflow.builder.select_node') }}</span>
                    </div>
                    <div class="canvas-panel">
                        <VueFlow
                            :edges="edges"
                            :nodes="nodes"
                            @dragleave="onDragLeave"
                            @dragover="onDragOver"
                            @edgesChange="handleEdgesChange"
                            @nodeClick="onNodeClick"
                            @nodesChange="handleNodesChange"
                            @paneClick="onPaneClick"
                        >
                            <template #node-input="customNodeProps">
                                <NodeInput v-bind="customNodeProps" @edit="onEditNode" />
                            </template>
                            <template #node-custom="customNodeProps">
                                <CustomNode v-bind="customNodeProps" @edit="onEditNode" />
                            </template>
                            <template #node-decision="customNodeProps">
                                <DecisionNode v-bind="customNodeProps" @edit="onEditNode" />
                            </template>
                            <template #node-output="customNodeProps">
                                <NodeOutput v-bind="customNodeProps" />
                            </template>

                            <DropzoneBackground
                                :style="{
                                    backgroundColor: isDragOver ? '#e7f3ff' : 'transparent',
                                    transition: 'background-color 0.2s ease',
                                }"
                            >
                                <p v-if="isDragOver">{{ t('text.workflow.builder.drop_node') }}</p>
                            </DropzoneBackground>
                        </VueFlow>
                    </div>
                </div>

                <aside class="builder-side-panel">
                    <a-tabs v-model:activeKey="activeSidebarTab" size="small">
                        <a-tab-pane key="inspector" :tab="t('text.workflow.builder.inspector')">
                            <div v-if="selectedNode" class="space-y-3">
                                <div>
                                    <p class="panel-title mb-1">{{ t('text.workflow.builder.step_details') }}</p>
                                    <p class="panel-subtitle">
                                        {{ t('text.workflow.builder.canvas_hint') }}
                                    </p>
                                </div>
                                <a-form layout="vertical">
                                    <a-form-item :label="t('text.workflow.builder.step_name')">
                                        <a-input
                                            :disabled="isReadOnly"
                                            :value="selectedNode.data?.name"
                                            @update:value="updateSelectedNodeName"
                                        />
                                    </a-form-item>
                                    <NodeEditorShell
                                        :model-class="currentRootModel"
                                        :read-only="isReadOnly"
                                        :selected-node="selectedNode"
                                        :steps="nodes"
                                        @update-node-config="handleUpdateNodeConfig"
                                    />
                                </a-form>
                            </div>
                            <a-empty
                                v-else
                                :description="t('text.workflow.builder.select_node_inspect')"
                            />
                        </a-tab-pane>
                        <a-tab-pane key="variables" :tab="t('text.workflow.builder.variables')">
                            <VariableBrowser
                                :root-model="currentRootModel"
                                :steps="nodes"
                                class="sidebar-panel"
                                @insert="handleInsertExpression"
                            />
                        </a-tab-pane>
                        <a-tab-pane key="health" :tab="t('text.workflow.builder.graph_health')">
                            <WorkflowGraphHealthPanel
                                :checks="graphHealth.checks"
                                :summary="graphHealthSummary"
                                :tone="graphHealthTone"
                                @focus-issue="focusIssue"
                            />
                        </a-tab-pane>
                    </a-tabs>
                </aside>
            </form>
        </div>

        <template #footer>
            <div class="flex flex-nowrap items-center justify-end gap-2">
                <a-button :disabled="saving" @click="$emit('close')">
                    {{ $t('nav.b.cancel') }}
                </a-button>
                <a-button @click="reviewGraphHealth">{{ t('text.workflow.builder.review_graph') }}</a-button>
                <a-button
                    :disabled="isReadOnly"
                    :loading="saving"
                    form="workflow-design-form"
                    html-type="submit"
                    type="primary"
                >
                    {{ t('text.workflow.builder.save_design') }}
                </a-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { addEdge, applyEdgeChanges, applyNodeChanges, useVueFlow, VueFlow } from '@vue-flow/core'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { iAlert } from '@/mixins'
import { useWorkflow } from '@/hooks'
import { updateWorkflow } from '@/libs'
import DropzoneBackground from '@/components/workflows/drop-zone-background.vue'
import CustomNode from '@/components/workflows/custom-node.vue'
import DecisionNode from '@/components/workflows/decision-node.vue'
import NodeInput from '@/components/workflows/node-input.vue'
import NodeOutput from '@/components/workflows/node-output.vue'
import VueFlowSidebar from '@/components/workflows/sidebar.vue'
import VariableBrowser from '@/components/workflows/variable-browser.vue'
import NodeEditorShell from '@/components/workflow/node-editors/NodeEditorShell.vue'
import useDragAndDrop from '@/components/workflows/useDnd.js'
import WorkflowBuilderHeader from './workflow-builder-header.vue'
import WorkflowGraphHealthPanel from './workflow-graph-health-panel.vue'
import {
    buildWorkflowValidationReport,
    getWorkflowGraphDesign,
    mapWorkflowConnectionsToEdges,
} from '../workflow-project-utils'

defineOptions({
    name: 'WorkflowBuilderModal',
})

const { t } = useI18n()

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    workflow: {
        type: Object,
        default: null,
    },
    groupedFunctions: {
        type: Array,
        default: () => [],
    },
})

const emit = defineEmits(['close', 'saved'])

const nodes = ref([])
const edges = ref([])
const selectedNode = ref(null)
const saving = ref(false)
const hasUnsavedChanges = ref(false)
const activeNodeId = ref(null)
const activeSidebarTab = ref('health')

const setActiveNodeId = (id) => {
    activeNodeId.value = id || null
}

const workflowId = ref(null)
const { refetch: refetchWorkflowDetail, data: workflowDetailQuery } = useWorkflow(workflowId, {
    enabled: false,
    retry: 0,
})

const { onConnect, setCenter, toObject } = useVueFlow()
const { onDragOver, onDrop, onDragLeave, isDragOver, setIdSeed } = useDragAndDrop()

const currentRootModel = computed(() => {
    return (
        props.workflow?.target_entity_type || workflowDetailQuery.value?.target_entity_type || null
    )
})

const isReadOnly = computed(() => {
    const status = props.workflow?.status || workflowDetailQuery.value?.status
    return status && status !== 'draft'
})

const cloneDesign = (design = { nodes: [], edges: [] }) =>
    JSON.parse(JSON.stringify({ nodes: design.nodes || [], edges: design.edges || [] }))

const normalizeStepTypeForSave = (node) => {
    const clone = {
        ...node,
        data: { ...(node.data || {}) },
        position: {
            x: node.position?.x ?? 0,
            y: node.position?.y ?? 0,
        },
    }
    const data = clone.data
    const cfg = { ...(data.step_config || {}) }
    const code = data.step_code || node.id
    let stepType = data.step_type || node.type || ''

    if (
        node.type === 'input' ||
        cfg.node_type === 'input' ||
        data.step_type === 'input' ||
        (data.step_type === 'event' && cfg.node_type === 'input')
    ) {
        stepType = 'input'
        cfg.node_type = 'input'
        clone.type = 'input'
    } else if (node.type === 'output' || cfg.node_type === 'output') {
        stepType = 'output'
        cfg.node_type = 'output'
        clone.type = 'output'
    } else if (node.type === 'decision' || data.step_type === 'decision') {
        stepType = 'decision'
        clone.type = 'decision'
    } else if (
        code === 'log_message' ||
        (cfg.level !== undefined && cfg.message !== undefined && !(cfg.class && cfg.method))
    ) {
        stepType = 'log_message'
    } else if (data.step_type === 'set_variable') {
        stepType = 'set_variable'
    } else if (
        code === 'retrieve' ||
        cfg.where !== undefined ||
        cfg.model !== undefined ||
        cfg.first !== undefined ||
        cfg.limit !== undefined
    ) {
        stepType = 'retrieve'
    } else if (
        code === 'change' ||
        cfg.targetExpr !== undefined ||
        cfg.changes !== undefined ||
        cfg.save !== undefined
    ) {
        stepType = 'change'
    } else if (cfg.class && cfg.method) {
        stepType = 'blueprint_callable'
    }

    data.step_type = stepType
    data.step_config = cfg
    data.step_code = data.step_code || code
    clone.data = data
    return clone
}

const getDesignForWorkflow = (workflow) => {
    if (!workflow) return { nodes: [], edges: [] }

    const { nodes: nodeList, edges: edgeList } = getWorkflowGraphDesign(workflow)

    if (nodeList?.length || edgeList?.length) {
        const normalizedNodes = (nodeList || []).map((n) => {
            return {
                ...n,
                type: n.type || 'custom',
                position: {
                    x:
                        typeof n.position?.x === 'string'
                            ? parseFloat(n.position.x)
                            : n.position?.x ?? 0,
                    y:
                        typeof n.position?.y === 'string'
                            ? parseFloat(n.position.y)
                            : n.position?.y ?? 0,
                },
            }
        })
        const normalizedEdges = (edgeList || []).map((e) => ({
            ...e,
            sourceHandle: e.sourceHandle ?? null,
            targetHandle: e.targetHandle ?? null,
        }))
        return cloneDesign({ nodes: normalizedNodes, edges: normalizedEdges })
    }
    return { nodes: [], edges: [] }
}

const getNextIdSeed = (nodesArr = []) => {
    const maxId = nodesArr
        .map((n) => n.id)
        .map((id) => {
            const match = String(id).match(/dndnode_(\d+)/)
            return match ? parseInt(match[1], 10) : -1
        })
        .reduce((acc, curr) => (curr > acc ? curr : acc), -1)

    return maxId + 1
}

const applyDesign = async (workflow) => {
    const cloned = getDesignForWorkflow(workflow)
    nodes.value = cloned.nodes || []
    const nodeIds = new Set(nodes.value.map((n) => String(n.id)))
    const validEdges = (cloned.edges || []).filter(
        (e) => nodeIds.has(String(e.source)) && nodeIds.has(String(e.target))
    )
    await nextTick()
    edges.value = validEdges
    setIdSeed(getNextIdSeed(nodes.value))
    selectedNode.value = null
    setActiveNodeId(null)
    hasUnsavedChanges.value = false
}

const loadWorkflowDesign = (workflow) => {
    if (!workflow) {
        nodes.value = []
        edges.value = []
        return
    }

    if (workflow.id) {
        workflowId.value = workflow.id
        refetchWorkflowDetail().then((res) => {
            const data = res?.data || workflowDetailQuery.value || workflow
            const merged = {
                ...workflow,
                ...data,
                edges:
                    (data?.edges?.length ? data.edges : workflow?.edges) ||
                    mapWorkflowConnectionsToEdges(data?.connections || workflow?.connections || []),
                nodes: data?.nodes?.length ? data.nodes : workflow?.nodes,
            }
            applyDesign(merged)
        })
    } else {
        applyDesign(workflow)
    }
}

const handleInsertExpression = async (expression) => {
    const value = String(expression || '').trim()
    if (!value) return

    try {
        if (navigator?.clipboard?.writeText) {
            await navigator.clipboard.writeText(value)
            iAlert({ status: 'success', message: t('text.workflow.builder.expression_copied', { value }) })
            return
        }
    } catch (error) {
        // eslint-disable-next-line no-console
        console.error('Clipboard copy failed', error)
    }

    iAlert({ status: 'info', message: value })
}

function handleKeydown(event) {
    if (event.key === 'Delete') {
        event.preventDefault()
        deleteSelectedElements()
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            loadWorkflowDesign(props.workflow)
            activeSidebarTab.value = 'health'
            document.addEventListener('keydown', handleKeydown)
        } else {
            document.removeEventListener('keydown', handleKeydown)
            selectedNode.value = null
            setActiveNodeId(null)
            activeSidebarTab.value = 'health'
        }
    },
    { immediate: true }
)

watch(
    () => props.workflow,
    (workflow) => {
        if (props.open && workflow) {
            loadWorkflowDesign(workflow)
        }
    }
)

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeydown)
})

const onEditNode = (rawNode) => {
    const node = rawNode?.data
        ? rawNode
        : { id: rawNode?.id || rawNode?.step_code, data: rawNode || {} }

    if (!node.data.step_config || typeof node.data.step_config !== 'object') {
        node.data.step_config = {}
    }

    if (node.data.step_type === 'decision' || node.type === 'decision') {
        node.data.step_config = {
            condition: node.data.step_config.condition ?? '',
            yes_output: node.data.step_config.yes_output ?? 'target-a',
            no_output: node.data.step_config.no_output ?? 'target-b',
        }
    }

    selectedNode.value = node
    setActiveNodeId(node.id)
    activeSidebarTab.value = 'inspector'
}

const onNodeClick = (_, node) => {
    if (!node) return
    onEditNode(node)
}

const onPaneClick = () => {
    setActiveNodeId(null)
    selectedNode.value = null
}

const handleUpdateNodeConfig = (nodeId, config) => {
    if (!nodeId) return
    const idx = nodes.value.findIndex((n) => n.id === nodeId)
    if (idx !== -1) {
        nodes.value[idx] = {
            ...nodes.value[idx],
            data: {
                ...(nodes.value[idx].data || {}),
                step_config: config || {},
            },
        }
    }
    if (selectedNode.value?.id === nodeId) {
        selectedNode.value = {
            ...selectedNode.value,
            data: {
                ...(selectedNode.value.data || {}),
                step_config: config || {},
            },
        }
    }
    hasUnsavedChanges.value = true
}

const updateSelectedNodeName = (value) => {
    if (!selectedNode.value?.id) return

    const nextName = value || ''
    const idx = nodes.value.findIndex((node) => node.id === selectedNode.value.id)

    if (idx !== -1) {
        nodes.value[idx] = {
            ...nodes.value[idx],
            data: {
                ...(nodes.value[idx].data || {}),
                name: nextName,
            },
        }
    }

    selectedNode.value = {
        ...selectedNode.value,
        data: {
            ...(selectedNode.value.data || {}),
            name: nextName,
        },
    }
    hasUnsavedChanges.value = true
}

const propagateData = (sourceNodeId) => {
    const sourceNode = nodes.value.find((n) => n.id === sourceNodeId)
    if (!sourceNode) return

    const outgoingEdges = edges.value.filter((edge) => edge.source === sourceNodeId)

    outgoingEdges.forEach((edge) => {
        const targetNode = nodes.value.find((n) => n.id === edge.target)
        if (targetNode) {
            targetNode.data.input = sourceNode.data.output

            if (targetNode.type === 'decision') {
                evaluateDecisionNode(targetNode)
            }
        }
    })
}

const evaluateDecisionNode = (node) => {
    if (!node.data.input || !node.data.step_config?.condition) return

    try {
        const context = { $data: node.data.input }
        const result = new Function(
            ...Object.keys(context),
            `return ${node.data.step_config.condition}`
        )(...Object.values(context))

        edges.value = edges.value.map((edge) => {
            if (edge.source === node.id) {
                const isYesEdge = edge.sourceHandle === node.data.step_config.yes_output
                return {
                    ...edge,
                    label: isYesEdge
                        ? result
                            ? t('text.workflow.node.decision.edge_true')
                            : t('text.workflow.node.decision.edge_false')
                        : result
                        ? t('text.workflow.node.decision.edge_false')
                        : t('text.workflow.node.decision.edge_true'),
                    style: {
                        stroke: isYesEdge === result ? 'green' : 'red',
                        strokeWidth: 2,
                    },
                }
            }
            return edge
        })
    } catch (error) {
        // eslint-disable-next-line no-console
        console.error('Decision node evaluation failed', error)
    }
}

const handleNodesChange = (changes) => {
    nodes.value = applyNodeChanges(changes, nodes.value)
    const removedIds = new Set(changes.filter((c) => c.type === 'remove' && c.id).map((c) => c.id))
    if (removedIds.size) {
        edges.value = edges.value.filter(
            (e) => !removedIds.has(e.source) && !removedIds.has(e.target)
        )
        if (selectedNode.value?.id && removedIds.has(selectedNode.value.id)) {
            selectedNode.value = null
            setActiveNodeId(null)
        }
    }
    hasUnsavedChanges.value = true
}

const handleEdgesChange = (changes) => {
    edges.value = applyEdgeChanges(changes, edges.value)
    hasUnsavedChanges.value = true
}

const syncFromFlow = () => {
    const { nodes: liveNodes = [], edges: liveEdges = [] } = toObject()
    if (Array.isArray(liveNodes)) {
        nodes.value = liveNodes
    }
    if (Array.isArray(liveEdges)) {
        edges.value = liveEdges
    }
}

const deleteSelectedElements = () => {
    if (!props.open) return
    const selectedNodeIds = new Set(nodes.value.filter((n) => n.selected).map((n) => n.id))
    const selectedEdgeIds = new Set(edges.value.filter((e) => e.selected).map((e) => e.id))

    if (!selectedNodeIds.size && !selectedEdgeIds.size) {
        return
    }

    for (let i = nodes.value.length - 1; i >= 0; i--) {
        if (selectedNodeIds.has(nodes.value[i].id)) {
            nodes.value.splice(i, 1)
        }
    }
    for (let i = edges.value.length - 1; i >= 0; i--) {
        const e = edges.value[i]
        if (
            selectedEdgeIds.has(e.id) ||
            selectedNodeIds.has(e.source) ||
            selectedNodeIds.has(e.target)
        ) {
            edges.value.splice(i, 1)
        }
    }
    hasUnsavedChanges.value = true
}

onConnect((params) => {
    const newEdge = {
        ...params,
        targetHandle: params.targetHandle || 'input',
        data: {
            payload: nodes.value.find((n) => n.id === params.source)?.data?.output || {},
        },
    }

    edges.value = addEdge(newEdge, edges.value)
    propagateData(params.source)
})

const markErrorNodes = (ids = []) => {
    const set = new Set(ids)
    nodes.value = nodes.value.map((n) => ({
        ...n,
        data: { ...(n.data || {}), __error: set.has(n.id) },
    }))
}

const graphHealth = computed(() =>
    buildWorkflowValidationReport({
        nodes: nodes.value,
        edges: edges.value,
    })
)

const graphHealthTone = computed(() => {
    if (!nodes.value.length) {
        return 'default'
    }

    return graphHealth.value.isValid ? 'green' : 'orange'
})

const graphHealthSummary = computed(() => {
    if (!nodes.value.length) {
        return t('text.workflow.builder.not_designed')
    }

    return graphHealth.value.isValid
        ? t('text.workflow.builder.ready_to_save')
        : t('text.workflow.builder.issues_to_fix', { count: graphHealth.value.issueCount })
})

const reviewGraphHealth = () => {
    syncFromFlow()
    activeSidebarTab.value = 'health'
    const issueIds = graphHealth.value.checks.flatMap((check) => (check.ok ? [] : check.nodeIds || []))
    markErrorNodes(issueIds)
}

const focusIssue = async (check) => {
    activeSidebarTab.value = check.ok ? 'health' : 'inspector'

    if (!check?.nodeIds?.length) {
        return
    }

    const nodeId = check.nodeIds[0]
    const node = nodes.value.find((item) => item.id === nodeId)
    if (!node) {
        return
    }

    onEditNode(node)
    await nextTick()
    if (typeof setCenter === 'function') {
        setCenter(node.position?.x ?? 0, node.position?.y ?? 0, {
            zoom: 1.1,
            duration: 300,
        })
    }
}

const validateDesign = () => {
    reviewGraphHealth()

    if (graphHealth.value.isValid) {
        markErrorNodes([])
        return true
    }

    iAlert({
        status: 'warning',
        message: t('text.workflow.builder.resolve_health'),
    })
    return false
}

const serializeNode = (node) =>
    normalizeStepTypeForSave({
        id: node.id,
        type: node.type,
        position: {
            x: node.position?.x ?? 0,
            y: node.position?.y ?? 0,
        },
        data: node.data,
    })

const serializeEdge = (edge) => ({ ...edge })

const saveWorkflowDesign = async () => {
    if (!props.workflow?.id) {
        return
    }

    // Pull the freshest positions from VueFlow before validating/saving
    syncFromFlow()

    if (!validateDesign()) {
        return
    }

    if (saving.value) {
        return
    }
    saving.value = true
    try {
        const payload = {
            nodes: nodes.value.map(serializeNode),
            edges: edges.value.map(serializeEdge),
        }

        const res = await updateWorkflow(props.workflow.id, payload)

        if (!res || typeof res !== 'object') {
            iAlert({ status: 'error', message: t('text.workflow.builder.save_failed') }, true)
            return
        }

        if (res?.status === 'success') {
            iAlert(res)
            if (res?.data?.nodes || res?.data?.edges) {
                applyDesign(res.data)
            }
            emit('saved', res?.data || null)
        } else {
            iAlert(res, true)
        }
    } catch (error) {
        iAlert(error, true)
    } finally {
        saving.value = false
        hasUnsavedChanges.value = false
    }
}

// QA checklist (manual):
// - Create a node, open its editor, save, reload modal -> step_config persists.
// - Use expression/template validate buttons -> expect success/error feedback.
// - Non-vars.* paths in set/clear/storeAs inputs should show warnings.
</script>

<style>
.vue-flow__minimap {
    transform: scale(75%);
    transform-origin: bottom right;
}

.builder-shell {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.dnd-flow {
    display: grid;
    grid-template-columns: 320px minmax(0, 1.6fr) 360px;
    gap: 12px;
    height: calc(100vh - 240px);
}

.workflow-builder {
    background: transparent;
}

.builder-side-panel {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-height: 0;
}

.panel-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.panel-title {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
    color: #111827;
}

.panel-subtitle {
    margin: 0;
    font-size: 0.75rem;
    color: #6b7280;
}

.sidebar-panel {
    min-height: 0;
}

.canvas-stack {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
}

.canvas-summary-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #f8fafc;
    color: #475569;
    font-size: 0.75rem;
    padding: 10px 12px;
}

.canvas-panel {
    min-height: 0;
    height: 100%;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 12px;
    box-shadow: 0 10px 30px -12px rgba(15, 23, 42, 0.2);
}

.dnd-flow .vue-flow-wrapper {
    height: 100%;
}

.dropzone-background {
    position: relative;
    height: 100%;
    width: 100%;
}

.dropzone-background .overlay {
    position: absolute;
    top: 0;
    left: 0;
    height: 100%;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
    pointer-events: none;
}

@media screen and (max-width: 1024px) {
    .dnd-flow {
        grid-template-columns: 1fr;
        grid-auto-rows: auto;
        height: auto;
    }

    .workflow-builder {
        height: auto;
    }
}

.vue-flow__node-input {
    border: none !important;
    background: transparent !important;
    box-shadow: none !important;
}

.vue-flow__node-input.selected {
    box-shadow: none !important;
    border: none !important;
}

.vue-flow__node-output {
    border: none !important;
    background: transparent !important;
    box-shadow: none !important;
}

.vue-flow__node-output.selected {
    box-shadow: none !important;
    border: none !important;
}
</style>
