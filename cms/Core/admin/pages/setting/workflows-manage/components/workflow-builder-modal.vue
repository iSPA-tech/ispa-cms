<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
  Workflow designer: palette on the left, canvas in the middle, step settings on the right.
  The VueFlow store is the only copy of the graph. Loading, undo and a finished save write into it; the checks,
  the step settings and the save read from it.
-->
<template>
    <a-modal
        :footer="null"
        :keyboard="false"
        :maskClosable="false"
        :open="open"
        class="modal-fullscreen"
        width="100%"
        wrap-class-name="is-fullscreen"
        @cancel="requestClose"
    >
        <template #title>
            <WorkflowBuilderHeader
                :can-edit="abilities.canEdit"
                :can-publish="abilities.canPublish"
                :draft-status="draftStatus"
                :has-steps="nodes.length > 0"
                :has-unsaved-changes="hasUnsavedChanges"
                :is-read-only="isReadOnly"
                :issue-count="nodes.length ? graphHealth.issueCount : 0"
                :loading="loading"
                :name="workflow ? workflow.name : $t('nav.a.workflows')"
                :pending-count="pendingCount"
                :publishing="publishing"
                :saving="saving"
                @history="historyOpen = true"
                @publish="startPublish"
                @review="reviewGraphHealth"
                @save="saveWorkflowDesign()"
            />
        </template>

        <div
            class="grid gap-3 lg:h-[calc(100vh-96px)] lg:min-h-[420px]"
            :class="railMode ? 'lg:grid-cols-[64px_minmax(0,1fr)_360px]' : 'lg:grid-cols-[300px_minmax(0,1fr)_360px]'"
            data-testid="workflow-builder"
        >
            <!-- The palette shrinks to a rail while a step is being set up, so the diagram keeps its room. -->
            <nav
                v-if="railMode"
                class="flex flex-wrap items-center gap-2 rounded-xl border border-solid border-slate-200 bg-white p-2 lg:flex-col lg:flex-nowrap"
                :aria-label="t('text.workflow.builder.node_library')"
                data-testid="builder-rail"
            >
                <a-tooltip placement="right" :title="t('text.workflow.canvas.rail_expand')">
                    <a-button
                        :aria-label="t('text.workflow.canvas.rail_expand')"
                        class="!h-11 !w-11"
                        data-testid="builder-rail-add"
                        ghost
                        type="primary"
                        @click="paletteOpen = true"
                    >
                        <i-icon icon="plus" class="h-5 w-5" />
                    </a-button>
                </a-tooltip>
                <span class="text-xs text-slate-600">{{ t('text.workflow.canvas.rail_add') }}</span>
                <a-tooltip v-for="group in railGroups" :key="group.key" placement="right" :title="group.label">
                    <a-button :aria-label="group.label" class="!h-11 !w-11" type="text" @click="paletteOpen = true">
                        <i-icon :icon="group.icon" class="h-5 w-5 text-slate-600" />
                    </a-button>
                </a-tooltip>
            </nav>
            <!-- Hidden by class, not v-show: the admin's Tailwind utilities are !important and would beat an inline style. -->
            <aside class="min-h-0 flex-col gap-2 max-lg:max-h-80" :class="railMode ? 'hidden' : 'flex'">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="m-0 text-[0.95rem] font-semibold text-gray-900">{{ t('text.workflow.builder.node_library') }}</p>
                        <p class="m-0 text-xs text-gray-600">{{ t('text.workflow.builder.add_steps_desc') }}</p>
                    </div>
                    <a-button
                        v-if="selectedNode"
                        :aria-label="t('text.workflow.canvas.rail_collapse')"
                        :title="t('text.workflow.canvas.rail_collapse')"
                        data-testid="builder-rail-collapse"
                        size="small"
                        type="text"
                        @click="paletteOpen = false"
                    >
                        <i-icon icon="chevron-left" class="h-4 w-4" />
                    </a-button>
                </div>
                <VueFlowSidebar :blueprints="groupedFunctions" :disabled="isReadOnly" class="min-h-0 flex-1" />
            </aside>

            <section
                class="relative flex h-[70vh] min-h-[360px] flex-col overflow-hidden rounded-xl border border-solid bg-slate-50 lg:h-full"
                :class="isDragOver ? 'border-sky-400' : 'border-gray-200'"
                data-testid="builder-canvas"
            >
                <div class="flex flex-wrap items-center gap-2 border-0 border-b border-solid border-gray-200 bg-white/90 px-3 py-2">
                    <span
                        class="rounded-lg border border-solid border-gray-200 bg-white px-2.5 py-1 text-[13px] text-slate-700"
                        data-testid="builder-counts"
                    >
                        {{ t('text.workflow.canvas.steps_count', { count: nodes.length }) }}
                    </span>
                    <a-button
                        :disabled="isReadOnly || loading || nodes.length < 2"
                        data-testid="builder-arrange"
                        @click="arrange"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            <i-icon icon="file-tree" class="h-4 w-4" />
                            {{ t('text.workflow.canvas.arrange') }}
                        </span>
                    </a-button>
                    <a-tooltip :title="t('text.workflow.canvas.error_paths_hint')">
                        <a-button
                            :aria-pressed="ui.showErrors"
                            :class="ui.showErrors ? '!border-red-400 !bg-red-50 !text-red-800' : ''"
                            data-testid="builder-error-paths"
                            @click="ui.showErrors = !ui.showErrors"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <span aria-hidden="true" class="h-2 w-2 rounded-full bg-red-600" />
                                {{ t('text.workflow.canvas.error_paths', { count: errorLinkCount }) }}
                            </span>
                        </a-button>
                    </a-tooltip>
                    <a-button
                        v-if="setupOpenCount > 0 && !isReadOnly"
                        class="!border-amber-400 !bg-amber-50 !text-amber-900"
                        data-testid="builder-setup"
                        @click="openSetup"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            <span aria-hidden="true" class="h-2 w-2 rounded-full bg-amber-500" />
                            {{ t('text.workflow.setup.list_badge', { count: setupOpenCount }) }}
                        </span>
                    </a-button>
                    <!-- Everything answered: the setup screen stays one click away, to change an answer later. -->
                    <a-button v-else-if="setupQuestions.length && !isReadOnly" data-testid="builder-setup-again" @click="openSetup">
                        {{ t('text.workflow.setup.nav') }}
                    </a-button>

                    <div class="ml-auto flex items-center gap-0.5 rounded-lg border border-solid border-gray-200 bg-white p-0.5">
                        <a-tooltip :title="t('text.workflow.builder.undo')">
                            <a-button
                                :aria-label="t('text.workflow.builder.undo')"
                                :disabled="isReadOnly || !canUndo"
                                :icon="h(UndoOutlined)"
                                data-testid="builder-undo"
                                type="text"
                                @click="undo"
                            />
                        </a-tooltip>
                        <a-tooltip :title="t('text.workflow.builder.redo')">
                            <a-button
                                :aria-label="t('text.workflow.builder.redo')"
                                :disabled="isReadOnly || !canRedo"
                                :icon="h(RedoOutlined)"
                                data-testid="builder-redo"
                                type="text"
                                @click="redo"
                            />
                        </a-tooltip>
                        <span class="mx-0.5 h-4 w-px bg-gray-200" />
                        <a-tooltip :title="t('text.workflow.builder.zoom_out')">
                            <a-button
                                :aria-label="t('text.workflow.builder.zoom_out')"
                                :icon="h(ZoomOutOutlined)"
                                data-testid="builder-zoom-out"
                                type="text"
                                @click="zoomOut({ duration: 150 })"
                            />
                        </a-tooltip>
                        <span class="min-w-[44px] text-center text-[13px] text-slate-700" data-testid="builder-zoom">{{ zoomPercent }}%</span>
                        <a-tooltip :title="t('text.workflow.builder.zoom_in')">
                            <a-button
                                :aria-label="t('text.workflow.builder.zoom_in')"
                                :icon="h(ZoomInOutlined)"
                                data-testid="builder-zoom-in"
                                type="text"
                                @click="zoomIn({ duration: 150 })"
                            />
                        </a-tooltip>
                        <a-tooltip :title="t('text.workflow.builder.fit_view')">
                            <a-button
                                :aria-label="t('text.workflow.builder.fit_view')"
                                :icon="h(ExpandOutlined)"
                                data-testid="builder-fit"
                                type="text"
                                @click="fitGraph(200)"
                            />
                        </a-tooltip>
                    </div>
                </div>

                <div
                    ref="canvasBox"
                    class="relative min-h-0 flex-1"
                    :class="isDragOver ? 'bg-sky-50' : ''"
                    @dragover="onDragOver"
                    @dragleave="onDragLeave"
                    @drop="handleDrop"
                >
                    <VueFlow
                        :connect-on-click="false"
                        :connection-line-style="{ stroke: '#0f6e7d', strokeWidth: 2.5, strokeDasharray: '6 5' }"
                        :connection-radius="30"
                        :default-edge-options="edgeDefaults"
                        :delete-key-code="isReadOnly ? null : ['Backspace', 'Delete']"
                        :edges-updatable="false"
                        :is-valid-connection="validConnection"
                        :max-zoom="1.75"
                        :min-zoom="0.2"
                        :nodes-connectable="!isReadOnly"
                        :nodes-draggable="!isReadOnly"
                        :pan-on-scroll="true"
                    >
                        <template #node-step="nodeProps">
                            <CustomNode v-bind="nodeProps" />
                        </template>
                        <template #node-decision="nodeProps">
                            <DecisionNode v-bind="nodeProps" />
                        </template>
                        <template #edge-link="edgeProps">
                            <StepEdge v-bind="edgeProps" />
                        </template>

                        <!-- The steps a loop repeats sit in a frame named after the loop step. -->
                        <template #zoom-pane>
                            <div
                                v-for="frame in frames"
                                :key="frame.id"
                                class="pointer-events-none absolute left-0 top-0 -z-10 rounded-[14px] border border-solid border-slate-300 bg-slate-200/40"
                                :style="{ transform: `translate(${frame.x}px, ${frame.y}px)`, width: `${frame.width}px`, height: `${frame.height}px` }"
                                data-testid="loop-frame"
                            >
                                <p class="m-0 flex items-center gap-2 px-3.5 py-2.5 text-[13.5px] text-slate-600">
                                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-slate-200 text-slate-700">
                                        <i-icon icon="sync" class="h-4 w-4" />
                                    </span>
                                    <span class="truncate">
                                        <strong class="font-semibold text-slate-800">{{ frame.name }}</strong>
                                        · {{ t('text.workflow.canvas.loop_hint') }}
                                    </span>
                                </p>
                            </div>
                        </template>

                        <CanvasBackground />
                    </VueFlow>

                    <StepPicker
                        v-if="pickerBox"
                        :left="pickerBox.left"
                        :top="pickerBox.top"
                        :used-codes="usedStepCodes"
                        @close="ui.picker = null"
                        @pick="addConnectedStep"
                    />

                    <div
                        v-if="loading || !nodes.length"
                        class="pointer-events-none absolute inset-0 flex items-center justify-center p-6"
                        data-testid="builder-empty"
                    >
                        <a-spin v-if="loading" />
                        <div v-else class="max-w-sm text-center">
                            <p class="mb-1 text-base font-semibold text-slate-700">
                                {{ isDragOver ? t('text.workflow.builder.drop_node') : t('text.workflow.builder.empty_title') }}
                            </p>
                            <p class="mb-0 text-sm text-slate-600">{{ t('text.workflow.builder.empty_hint') }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <aside class="flex min-h-0 flex-col overflow-y-auto">
                <a-tabs v-model:activeKey="activeSidebarTab" size="small">
                    <a-tab-pane key="inspector" :tab="t('text.workflow.builder.inspector')">
                        <div v-if="selectedNode" class="space-y-3" data-testid="builder-inspector">
                            <a-form layout="vertical">
                                <a-form-item :label="t('text.workflow.builder.step_name')" class="!mb-3">
                                    <a-input
                                        :disabled="isReadOnly"
                                        :value="selectedNode.data?.name"
                                        data-testid="builder-step-name"
                                        @update:value="updateSelectedNodeName"
                                    />
                                </a-form-item>
                                <NodeEditorShell
                                    :model-class="currentRootModel"
                                    :read-only="isReadOnly"
                                    :selected-node="selectedNode"
                                    :steps="nodes"
                                    :edges="edges"
                                    :workflow-id="workflow?.uuid ?? null"
                                    @update-node-config="handleUpdateNodeConfig"
                                    @update-on-error="handleUpdateOnError"
                                />
                            </a-form>
                            <div class="flex flex-wrap items-center justify-between gap-2 border-0 border-t border-solid border-gray-100 pt-3">
                                <span class="min-w-0 break-all text-xs text-gray-500">
                                    {{ t('text.workflow.node.code_label', { code: selectedNode.data?.step_code || selectedNode.id }) }}
                                </span>
                                <a-button
                                    v-if="!isReadOnly"
                                    :icon="h(DeleteOutlined)"
                                    danger
                                    data-testid="builder-delete-step"
                                    size="small"
                                    @click="removeSelectedNode"
                                >
                                    {{ t('text.workflow.builder.delete_step') }}
                                </a-button>
                            </div>
                        </div>
                        <a-empty v-else :description="t('text.workflow.builder.select_node_inspect')" />
                    </a-tab-pane>
                    <a-tab-pane key="variables" :tab="t('text.workflow.builder.variables')">
                        <VariableBrowser :root-model="currentRootModel" :steps="nodes" class="min-h-0" @insert="handleInsertExpression" />
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
        </div>

        <WorkflowPublishDialog
            :names="stepNameMap"
            :open="publishOpen"
            :workflow="workflow"
            @close="closePublish"
            @done="handlePublished"
            @refused="publishRefused = true"
        />
        <WorkflowVersionDrawer
            :can-publish="abilities.canPublish"
            :names="stepNameMap"
            :open="historyOpen"
            :workflow="workflow"
            @changed="handlePublished"
            @close="historyOpen = false"
        />
    </a-modal>
</template>

<script setup>
import { DeleteOutlined, ExpandOutlined, RedoOutlined, UndoOutlined, ZoomInOutlined, ZoomOutOutlined } from '@ant-design/icons-vue'
import CanvasBackground from '@/components/workflows/canvas-background.vue'
import { useVueFlow, VueFlow } from '@vue-flow/core'
import { Modal } from 'ant-design-vue'
import { computed, h, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { iAlert, rule } from '@/mixins'
import { useWorkflow } from '@/hooks'
import { updateWorkflow } from '@/libs'
import CustomNode from '@/components/workflows/custom-node.vue'
import DecisionNode from '@/components/workflows/decision-node.vue'
import StepEdge from '@/components/workflows/step-edge.vue'
import StepPicker from '@/components/workflows/step-picker.vue'
import VueFlowSidebar from '@/components/workflows/sidebar.vue'
import VariableBrowser from '@/components/workflows/variable-browser.vue'
import NodeEditorShell from '@/components/workflow/node-editors/NodeEditorShell.vue'
import { buildPaletteGroups, useNodeCatalog, useNodeIssues } from '@/components/workflows/nodeCatalog.js'
import {
    ERROR_HANDLE,
    TARGET_HANDLE,
    canvasEdge,
    createHistory,
    isValidConnection,
    planErrorMode,
    planLink,
    planRelink,
    serializeGraph,
    stepCodeOf,
    toCanvasGraph,
} from '@/components/workflows/graphRules.js'
import {
    CARD_HEIGHT,
    CARD_WIDTH,
    enclosingLoops,
    graphBounds,
    layoutGraph,
    loopBodies,
    loopFrames,
    needsLayout,
    openingView,
} from '@/components/workflows/graphLayout.js'
import { canvasUi as ui, onGraphEdited, onRelink, resetCanvasUi } from '@/components/workflows/canvasUi.js'
import { stepsNeedingSetup } from '@/components/workflows/setupRules.js'
import { loadAutoApproval, refreshAutoApproval } from '@/components/workflows/autoApprovalStore.js'
import { isNotReadyRefusal, readsVerdict } from '@/components/workflow/widgets/autoPublishRules.js'
import useDragAndDrop from '@/components/workflows/useDnd.js'
import WorkflowBuilderHeader from './workflow-builder-header.vue'
import WorkflowGraphHealthPanel from './workflow-graph-health-panel.vue'
import WorkflowPublishDialog from './workflow-publish-dialog.vue'
import WorkflowVersionDrawer from './workflow-version-drawer.vue'
import { fetchDraftStatus } from '../../../../workflow-guard-api'
import { fetchWorkflowSetup } from '../../../../workflow-setup-api'
import { stepNames, versionState, workflowAbilities } from '../../../../workflow-guard-report'
import {
    buildWorkflowValidationReport,
    getWorkflowGraphDesign,
    mapWorkflowConnectionsToEdges,
} from '../workflow-project-utils'

defineOptions({
    name: 'WorkflowBuilderModal',
})

const { t } = useI18n()
const router = useRouter()

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

/** `published` after a publish or a rollback was accepted (live now, or waiting): the page reads its lists again. */
const emit = defineEmits(['close', 'saved', 'published'])

const saving = ref(false)
const loading = ref(false)
const activeNodeId = ref(null)
const activeSidebarTab = ref('inspector')

const workflowId = ref(null)
const { refetch: refetchWorkflowDetail, data: workflowDetailQuery } = useWorkflow(workflowId, {
    enabled: false,
    retry: 0,
})

const flow = useVueFlow()
const {
    getNodes,
    getEdges,
    setNodes,
    setEdges,
    addEdges,
    removeEdges,
    removeNodes,
    updateNodeData,
    findNode,
    addSelectedNodes,
    removeSelectedElements,
    fitView,
    zoomIn,
    zoomOut,
    setCenter,
    setViewport,
    getViewport,
    dimensions,
    viewport,
    updateNode,
    addNodes,
    screenToFlowCoordinate,
    onInit,
    onConnect,
    onConnectStart,
    onConnectEnd,
    onNodeClick,
    onPaneClick,
    onNodeDragStop,
    onNodesChange,
    onEdgesChange,
} = flow
const { onDragOver, onDrop, onDragLeave, isDragOver, onNodeAdded, addStepAt } = useDragAndDrop()
const { catalog } = useNodeCatalog()
const { saveErrors } = useNodeIssues()

const nodes = computed(() => getNodes.value || [])
const edges = computed(() => getEdges.value || [])

// Every link uses the designer's own link component, which draws its arrow itself.
const edgeDefaults = canvasEdge({})
const decorateEdge = canvasEdge

const currentRootModel = computed(() => {
    return props.workflow?.target_entity_type || workflowDetailQuery.value?.target_entity_type || null
})

/** What this account may do here: edit the draft (`update`), publish and roll back (`publish`). */
const abilities = computed(() => workflowAbilities(props.workflow, rule))

// A workflow the platform manages (schedule wrappers) is shown but cannot be changed here; neither can an account
// that may not edit drafts.
const isReadOnly = computed(() => {
    const status = props.workflow?.status || workflowDetailQuery.value?.status
    return !!props.workflow?.system_managed || (!!status && status !== 'draft') || !abilities.value.canEdit
})

// ---------------------------------------------------------------------------------------------------------------------
// Graph state: snapshots, undo / redo, unsaved changes
// ---------------------------------------------------------------------------------------------------------------------

const history = createHistory(60)
/** Bumped after every history move so the buttons and the "unsaved" tag re-read it. */
const revision = ref(0)
let savedState = { nodes: [], edges: [] }

const snapshot = () => ({
    nodes: nodes.value.map((node) => ({
        id: node.id,
        type: node.type,
        position: { x: Math.round(node.position.x), y: Math.round(node.position.y) },
        data: JSON.parse(JSON.stringify(node.data || {})),
    })),
    edges: edges.value.map((edge) => ({
        id: edge.id,
        source: edge.source,
        target: edge.target,
        sourceHandle: edge.sourceHandle ?? null,
        targetHandle: edge.targetHandle ?? null,
        data: JSON.parse(JSON.stringify(edge.data || {})),
    })),
})

const canUndo = computed(() => revision.value >= 0 && history.canUndo())
const canRedo = computed(() => revision.value >= 0 && history.canRedo())
const hasUnsavedChanges = computed(() => revision.value >= 0 && history.differsFrom(savedState))

/** Record the graph as it is now. Called after every change the operator makes. */
const commit = () => {
    if (loading.value) return
    history.commit(snapshot())
    revision.value++
}
const commitSoon = () => nextTick(commit)

let typingTimer = null
/** Typing in the step settings: one history entry per pause, not per key. */
const commitAfterTyping = () => {
    clearTimeout(typingTimer)
    typingTimer = setTimeout(commit, 400)
}
const flushTyping = () => {
    if (!typingTimer) return
    clearTimeout(typingTimer)
    typingTimer = null
    commit()
}

const showGraph = async (state) => {
    // Links first out, last in: a link must never point at a step that is not on the canvas.
    setEdges([])
    setNodes(state.nodes.map((node) => ({ ...node })))
    await nextTick()
    setEdges(state.edges.map(decorateEdge))
    if (activeNodeId.value && !state.nodes.some((node) => node.id === activeNodeId.value)) {
        activeNodeId.value = null
    }
}

const stepBack = async (move) => {
    if (isReadOnly.value) return
    flushTyping()
    const state = move()
    if (!state) return
    await showGraph(state)
    revision.value++
}
const undo = () => stepBack(history.undo)
const redo = () => stepBack(history.redo)

const fitGraph = (duration = 0) => fitView({ padding: 0.1, maxZoom: 1, duration })

const zoomPercent = computed(() => Math.round((viewport.value?.zoom || 1) * 100))

/** Measured size of every card; a card the browser has not measured yet counts as a plain one. */
const cardSize = (id) => {
    const size = findNode(id)?.dimensions
    return { width: size?.width || CARD_WIDTH, height: size?.height || CARD_HEIGHT }
}

/** Smallest zoom at which a step card can still be read (its small text stays at 12px). */
const READABLE_ZOOM = 0.9

/**
 * First view of a workflow: the whole diagram when it stays readable, otherwise its top at a readable size; the
 * rest is reached by scrolling (the "whole diagram" button still shows everything).
 */
const showStart = (duration = 0) => {
    if (!nodes.value.length) return
    const first = nodes.value.find((node) => node.data?.is_start) || nodes.value[0]
    const view = openingView(graphBounds(nodes.value, cardSize), dimensions.value, {
        minZoom: READABLE_ZOOM,
        // Room for the frame of a loop, which reaches past its cards.
        padding: 32,
        focusX: first.position.x + cardSize(first.id).width / 2,
    })
    setViewport(view, { duration })
}

// ---------------------------------------------------------------------------------------------------------------------
// Layout: top to bottom, loops in a frame
// ---------------------------------------------------------------------------------------------------------------------

const bodies = computed(() => loopBodies(nodes.value, edges.value))
watch(
    bodies,
    (value) => {
        ui.loopOf = enclosingLoops(value)
    },
    { immediate: true }
)

const frames = computed(() =>
    loopFrames(nodes.value, bodies.value, cardSize).map((frame) => {
        const loop = findNode(frame.id)
        return { ...frame, name: loop?.data?.name || loop?.id || '' }
    })
)

const errorLinkCount = computed(() => edges.value.filter((edge) => edge.sourceHandle === ERROR_HANDLE).length)

/** Put every card in its place of the top-to-bottom arrangement. Steps and links are not touched. */
const applyLayout = () => {
    const positions = layoutGraph(nodes.value, edges.value, cardSize)
    Object.entries(positions).forEach(([id, position]) => updateNode(id, { position }))
}

const nextFrame = () => new Promise((resolve) => requestAnimationFrame(() => resolve()))

/** "Tidy up": one undo step. */
const arrange = async () => {
    if (isReadOnly.value || loading.value) return
    flushTyping()
    applyLayout()
    await nextTick()
    commit()
    showStart(200)
}

// ---------------------------------------------------------------------------------------------------------------------
// Draft and published version
// ---------------------------------------------------------------------------------------------------------------------

/** `{ differs, published_number }` of the saved draft; null until the server answered. */
const draftStatus = ref(null)
const publishOpen = ref(false)
const publishing = ref(false)
const publishRefused = ref(false)
const historyOpen = ref(false)

const pendingCount = computed(() => versionState(props.workflow).pending)
/** step_code => name, so the publish and history sentences name steps the way the canvas does. */
const stepNameMap = computed(() => stepNames(nodes.value))

let draftToken = 0
/** Ask whether the saved draft differs from the version that runs. A failure only leaves the tags out. */
const loadDraftStatus = async () => {
    const id = props.workflow?.uuid
    const token = ++draftToken
    if (!id) {
        draftStatus.value = null
        return
    }
    try {
        const status = await fetchDraftStatus(id)
        if (token === draftToken) draftStatus.value = status
    } catch (error) {
        if (token === draftToken) draftStatus.value = null
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Choices the site still has to make ("needs a choice" on a step card, link to the setup screen)
// ---------------------------------------------------------------------------------------------------------------------

/** Setup questions of the server, for requirements the node schemas do not carry. A failure only leaves them out. */
const setupQuestions = ref([])
let setupToken = 0
const loadSetupQuestions = async () => {
    const id = props.workflow?.uuid
    const token = ++setupToken
    setupQuestions.value = []
    if (!id) return
    try {
        const body = await fetchWorkflowSetup(id)
        if (token === setupToken) setupQuestions.value = body.questions
    } catch (error) {
        // The marks then follow the node schemas alone.
    }
}
const needsSetup = computed(() => stepsNeedingSetup(nodes.value, catalog.byType, setupQuestions.value))
watch(needsSetup, (value) => (ui.needsSetup = value), { immediate: true })
/** Open choices: the marked steps, plus the questions that belong to no step (when it runs). */
const setupOpenCount = computed(
    () => Object.keys(needsSetup.value).length + setupQuestions.value.filter((question) => question.required && !question.answered && !question.step_code).length
)
// Review steps that read a JEV verdict say on their card who decides: ask the server where auto-publish stands.
watch(
    () => [
        props.workflow?.uuid || null,
        nodes.value
            .filter((node) => catalog.approvalTypes.includes(node.data?.step_type) && readsVerdict(node.data?.step_config) && node.data?.step_code)
            .map((node) => node.data.step_code)
            .join('|'),
    ],
    ([id, codes]) => {
        ui.projectId = id
        if (id && codes) codes.split('|').forEach((code) => loadAutoApproval(id, code))
    },
    { immediate: true }
)

/** To the setup screen; unsaved changes are saved as the draft first, so the screen asks about what is on the canvas. */
const openSetup = async () => {
    if (!props.workflow?.id) return
    if (hasUnsavedChanges.value && !isReadOnly.value && !(await saveWorkflowDesign({ quiet: true }))) return
    router.push({ name: 'workflows-setup', params: { project: props.workflow.slug || props.workflow.uuid } })
}

// ---------------------------------------------------------------------------------------------------------------------
// Loading
// ---------------------------------------------------------------------------------------------------------------------

// The canvas mounts with the modal's first open; a graph written into the store before that is dropped.
let markCanvasReady
const canvasReady = new Promise((resolve) => (markCanvasReady = resolve))
onInit(() => markCanvasReady())

const applyDesign = async (workflow, { fit = true } = {}) => {
    const stored = getWorkflowGraphDesign(workflow || {})
    const graph = toCanvasGraph(stored.nodes, stored.edges, catalog.byType)
    loading.value = true
    ui.picker = null
    await canvasReady
    await showGraph(graph)
    await nextTick()
    if (fit) {
        // The cards need a frame to get their size before they can be arranged and the view set around them.
        await nextFrame()
        await nextFrame()
        // A diagram saved for the old left-to-right canvas (or never arranged) is laid out top to bottom. This is
        // not an edit: the draft counts as saved until the operator changes something.
        if (needsLayout(nodes.value, edges.value)) {
            applyLayout()
            await nextTick()
        }
    }
    loading.value = false
    savedState = snapshot()
    history.reset(savedState)
    revision.value++
    if (fit) showStart()
}

let loadToken = 0
const loadWorkflowDesign = async (workflow) => {
    const token = ++loadToken
    activeNodeId.value = null
    if (!workflow?.id) {
        await applyDesign(workflow)
        return
    }

    loading.value = true
    workflowId.value = workflow.uuid
    let data = null
    try {
        const res = await refetchWorkflowDetail()
        data = res?.data || workflowDetailQuery.value || null
    } catch (error) {
        iAlert(error, true)
    }
    // A newer open / workflow switch has taken over.
    if (token !== loadToken) return

    await applyDesign({
        ...workflow,
        ...(data || {}),
        edges:
            (data?.edges?.length ? data.edges : workflow?.edges) ||
            mapWorkflowConnectionsToEdges(data?.connections || workflow?.connections || []),
        nodes: data?.nodes?.length ? data.nodes : workflow?.nodes,
    })
}

// Two sources, compared one by one: the page hands over a fresh copy of the same workflow after every save and
// publish, and that must not load the design again (it would drop edits made since).
watch(
    [() => props.open, () => props.workflow?.id],
    ([open]) => {
        if (open) {
            ui.showErrors = false
            activeSidebarTab.value = 'inspector'
            draftStatus.value = null
            loadWorkflowDesign(props.workflow)
            loadDraftStatus()
            loadSetupQuestions()
            document.addEventListener('keydown', handleKeydown)
        } else {
            document.removeEventListener('keydown', handleKeydown)
            clearTimeout(typingTimer)
            activeNodeId.value = null
            ui.picker = null
            publishOpen.value = false
            historyOpen.value = false
        }
    },
    { immediate: true }
)

// ---------------------------------------------------------------------------------------------------------------------
// Selection and step settings
// ---------------------------------------------------------------------------------------------------------------------

const selectedNode = computed(() => {
    const node = activeNodeId.value ? findNode(activeNodeId.value) : null
    return node ? { id: node.id, type: node.type, data: node.data } : null
})

const selectNode = (id) => {
    if (!id || !findNode(id)) return
    if (activeNodeId.value !== id) paletteOpen.value = false
    activeNodeId.value = id
    activeSidebarTab.value = 'inspector'
}

/** The operator opened the palette from the rail while a step is selected. */
const paletteOpen = ref(false)
const railMode = computed(() => !!selectedNode.value && !paletteOpen.value)
const railGroups = computed(() => buildPaletteGroups(catalog, '', t).map(({ key, label, icon }) => ({ key, label, icon })))

onNodeClick(({ node }) => selectNode(node?.id))
onPaneClick(() => {
    activeNodeId.value = null
})

const handleUpdateNodeConfig = (nodeId, config) => {
    if (!nodeId || isReadOnly.value || !findNode(nodeId)) return
    updateNodeData(nodeId, { step_config: config || {} })
    commitAfterTyping()
}

/** "When this step fails" of the step form: the choice is a link of the diagram, so it goes through the graph store. */
const handleUpdateOnError = (nodeId, mode) => {
    if (!nodeId || isReadOnly.value || !findNode(nodeId)) return
    const plan = planErrorMode(nodeId, mode, edges.value)
    if (!plan) return
    if (plan.removedIds.length) removeEdges(plan.removedIds)
    if (plan.add.length) addEdges(plan.add.map(decorateEdge))
    if (plan.removedIds.length || plan.add.length) commitSoon()
}

const updateSelectedNodeName = (value) => {
    if (!activeNodeId.value || isReadOnly.value) return
    updateNodeData(activeNodeId.value, { name: value || '' })
    commitAfterTyping()
}

const removeSelectedNode = () => {
    if (!activeNodeId.value || isReadOnly.value) return
    removeNodes([activeNodeId.value])
}

// ---------------------------------------------------------------------------------------------------------------------
// Canvas changes
// ---------------------------------------------------------------------------------------------------------------------

const validConnection = (connection) => !isReadOnly.value && isValidConnection(connection, nodes.value, catalog.byType)

/** Draw a link. An output leads to one step: drawing a second link from it moves the link. */
const connect = (connection) => {
    if (!validConnection(connection)) return false
    const plan = planLink(connection, edges.value)
    if (!plan) return false
    if (plan.removedIds.length) removeEdges(plan.removedIds)
    addEdges(plan.add.map(decorateEdge))
    // A link just drawn from the error output stays in sight: error paths are otherwise hidden once the step is left.
    if (connection.sourceHandle === ERROR_HANDLE) ui.showErrors = true
    commitSoon()
    return true
}

/** The output a link is being dragged from, until the drag ends. */
let dragFrom = null
let linked = false

onConnectStart((params) => {
    dragFrom = params?.handleType === 'source' && params.nodeId ? { source: params.nodeId, handle: params.handleId } : null
    linked = false
    ui.picker = null
})

onConnect((connection) => {
    linked = true
    connect(connection)
})

// A drag that ends on the empty canvas, or a click on an output, asks which step comes next.
onConnectEnd((event) => {
    const from = dragFrom
    dragFrom = null
    if (!from || linked || isReadOnly.value) return
    const touch = event?.changedTouches?.[0]
    const client = { x: touch?.clientX ?? event?.clientX, y: touch?.clientY ?? event?.clientY }
    if (!Number.isFinite(client.x) || !Number.isFinite(client.y)) return
    const card = document.elementFromPoint(client.x, client.y)?.closest?.('.vue-flow__node')
    const onSource = !!card && card.getAttribute('data-id') === from.source
    // Dropped on a card that cannot take the link (the start step): nothing happens.
    if (card && !onSource) return
    ui.picker = { ...from, client, point: onSource ? null : screenToFlowCoordinate(client) }
})

const canvasBox = ref(null)
const usedStepCodes = computed(() => nodes.value.map((node) => stepCodeOf(node)))

/** Where the step picker opens: beside the pointer, kept inside the canvas. */
const pickerBox = computed(() => {
    const picker = ui.picker
    if (!picker || isReadOnly.value) return null
    const rect = canvasBox.value?.getBoundingClientRect?.()
    if (!rect) return { left: 8, top: 8 }
    const left = (picker.client?.x ?? rect.left + rect.width / 2) - rect.left + 12
    const top = (picker.client?.y ?? rect.top + 24) - rect.top + 12
    return {
        left: Math.round(Math.max(8, Math.min(left, rect.width - 328))),
        top: Math.round(Math.max(8, Math.min(top, rect.height - 300))),
    }
})

/** A free place for a new card under `source`, in canvas coordinates. */
const placeUnder = (source) => {
    const size = cardSize(source.id)
    const position = { x: Math.round(source.position.x), y: Math.round(source.position.y + size.height + 64) }
    const taken = () =>
        nodes.value.some((node) => Math.abs(node.position.x - position.x) < CARD_WIDTH && Math.abs(node.position.y - position.y) < CARD_HEIGHT)
    for (let guard = 0; taken() && guard < 20; guard++) position.x += CARD_WIDTH + 48
    return position
}

/** The step picked for an output: the card is added and already connected. One undo step. */
const addConnectedStep = (payload) => {
    const picker = ui.picker
    ui.picker = null
    const source = picker ? findNode(picker.source) : null
    if (!source || !payload || isReadOnly.value) return
    const position = picker.point
        ? { x: Math.round(picker.point.x - CARD_WIDTH / 2), y: Math.round(picker.point.y - 20) }
        : placeUnder(source)
    const node = addStepAt(payload, position)
    if (!node) return
    connect({ source: source.id, sourceHandle: picker.handle, target: node.id, targetHandle: TARGET_HANDLE })
}

/** The arrowhead of a link was dropped on another step: the same output now leads there. One undo step. */
const stopRelink = onRelink((edgeId, targetId) => {
    if (isReadOnly.value) return
    const edge = edges.value.find((row) => row.id === edgeId)
    const plan = edge ? planRelink(edge, targetId, edges.value, nodes.value, catalog.byType) : null
    if (!plan) return
    if (plan.removedIds.length) removeEdges(plan.removedIds)
    addEdges(plan.add.map(decorateEdge))
    commitSoon()
})

// The step settings changed the links ("when this step fails").
const stopGraphEdited = onGraphEdited(() => commitSoon())

onNodeDragStop(() => commitSoon())

onNodesChange((changes) => {
    const removed = changes.filter((change) => change.type === 'remove').map((change) => change.id)
    if (removed.includes(activeNodeId.value)) activeNodeId.value = null
    if (removed.length) commitSoon()
})

onEdgesChange((changes) => {
    if (changes.some((change) => change.type === 'remove')) commitSoon()
})

const stopNodeAdded = onNodeAdded((node) => {
    nextTick(() => {
        commit()
        removeSelectedElements()
        addSelectedNodes([findNode(node.id)].filter(Boolean))
        selectNode(node.id)
    })
})

const handleDrop = (event) => {
    if (isReadOnly.value) return
    onDrop(event)
}

const isTypingTarget = (target) =>
    !!target && (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.isContentEditable === true)

// Undo / redo from the keyboard. Deleting is VueFlow's own key handling, which already ignores form fields.
function handleKeydown(event) {
    if (!props.open || isTypingTarget(event.target)) return
    if (!(event.ctrlKey || event.metaKey) || event.altKey) return
    const key = event.key.toLowerCase()
    if (key === 'z') {
        event.preventDefault()
        event.shiftKey ? redo() : undo()
    } else if (key === 'y') {
        event.preventDefault()
        redo()
    }
}

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeydown)
    clearTimeout(typingTimer)
    stopNodeAdded()
    stopGraphEdited()
    stopRelink()
    resetCanvasUi()
})

// ---------------------------------------------------------------------------------------------------------------------
// Checks
// ---------------------------------------------------------------------------------------------------------------------

const graphHealth = computed(() =>
    buildWorkflowValidationReport({
        nodes: nodes.value,
        edges: edges.value,
        byType: catalog.byType,
    })
)

const graphHealthTone = computed(() => {
    if (!nodes.value.length) return 'default'
    return graphHealth.value.isValid ? 'green' : 'orange'
})

const graphHealthSummary = computed(() => {
    if (!nodes.value.length) return t('text.workflow.builder.not_designed')
    return graphHealth.value.isValid
        ? t('text.workflow.builder.ready_to_save')
        : t('text.workflow.builder.issues_to_fix', { count: graphHealth.value.issueCount })
})

const highlightNodes = (ids = []) => {
    const found = ids.map((id) => findNode(id)).filter(Boolean)
    removeSelectedElements()
    if (found.length) addSelectedNodes(found)
    return found
}

const reviewGraphHealth = () => {
    activeSidebarTab.value = 'health'
    highlightNodes(graphHealth.value.checks.flatMap((check) => (check.ok ? [] : check.nodeIds || [])))
}

const centerOn = (node) => {
    const { width, height } = cardSize(node.id)
    setCenter(node.position.x + width / 2, node.position.y + height / 2, { zoom: 1, duration: 300 })
}

const focusIssue = (check) => {
    const [first] = highlightNodes(check?.nodeIds || [])
    if (!first) return
    centerOn(first)
    if (!check.ok && (check.nodeIds || []).length === 1) selectNode(first.id)
}

// ---------------------------------------------------------------------------------------------------------------------
// Variables tab
// ---------------------------------------------------------------------------------------------------------------------

/** Copy text; the admin often runs on plain HTTP, where the clipboard API does not exist. */
const copyText = async (value) => {
    try {
        if (navigator?.clipboard?.writeText) {
            await navigator.clipboard.writeText(value)
            return true
        }
    } catch (error) {
        // Fall through to the selection copy below.
    }
    const field = document.createElement('textarea')
    field.value = value
    field.setAttribute('readonly', '')
    field.className = 'fixed left-0 top-0 opacity-0'
    document.body.appendChild(field)
    field.select()
    let copied = false
    try {
        copied = document.execCommand('copy')
    } catch (error) {
        copied = false
    }
    field.remove()
    return copied
}

const handleInsertExpression = async (expression) => {
    const value = String(expression || '').trim()
    if (!value) return
    if (await copyText(value)) {
        iAlert({ type: 'success', message: t('text.workflow.builder.expression_copied', { value }) })
    } else {
        iAlert({ type: 'info', message: value })
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Save and close
// ---------------------------------------------------------------------------------------------------------------------

/** Show the first step a refused save or publish names. */
const showFirstFaultyStep = () => {
    const faulty = nodes.value.find((node) => saveErrors.value[stepCodeOf(node)])
    if (!faulty) return
    highlightNodes([faulty.id])
    centerOn(faulty)
    selectNode(faulty.id)
}

/**
 * Save the draft. Instant: a draft needs no review and no confirmation. Resolves true when the draft is stored.
 * @param {{ quiet?: boolean }} [options] `quiet` leaves the "saved" message out (the save before a publish).
 */
const saveWorkflowDesign = async ({ quiet = false } = {}) => {
    if (!props.workflow?.id || saving.value || isReadOnly.value) return false
    flushTyping()

    if (!graphHealth.value.isValid) {
        reviewGraphHealth()
        iAlert({ type: 'warning', message: t('text.workflow.builder.resolve_health') })
        return false
    }

    saving.value = true
    try {
        const state = snapshot()
        const res = await updateWorkflow(props.workflow.uuid, serializeGraph(state.nodes, state.edges, catalog.byType))

        if (res?.status !== 'success') {
            iAlert(res && typeof res === 'object' ? res : { message: t('text.workflow.builder.save_failed') }, true)
            return false
        }

        if (!quiet) iAlert({ type: 'success', message: t('text.workflow.guard.draft.saved') })
        if (res.data?.nodes || res.data?.edges) {
            // Keep the operator where they were: same view, same step open.
            await applyDesign(res.data, { fit: false })
        } else {
            savedState = snapshot()
            history.reset(savedState)
            revision.value++
        }
        emit('saved', res.data || null)
        loadDraftStatus()
        loadSetupQuestions()
        refreshAutoApproval()
        return true
    } catch (error) {
        iAlert(error, true)
        // Auto-publish turned on before JEV agreed often enough: when the refusal names no step (the step's card would
        // carry the reason), say it here in plain words.
        if (isNotReadyRefusal(error) && !nodes.value.some((node) => saveErrors.value[stepCodeOf(node)])) {
            iAlert({ type: 'error', message: t('text.workflow.issue.auto_approval_not_ready') })
        }
        // A refused save names the steps at fault: show the first one.
        showFirstFaultyStep()
        return false
    } finally {
        saving.value = false
    }
}

/** Publish: unsaved changes are saved as the draft first (a publish always freezes the saved draft), then the dialog opens. */
const startPublish = async () => {
    if (publishing.value || saving.value || !props.workflow?.id || !abilities.value.canPublish) return
    flushTyping()
    if (hasUnsavedChanges.value && !isReadOnly.value) {
        publishing.value = true
        const saved = await saveWorkflowDesign({ quiet: true })
        publishing.value = false
        if (!saved) return
    }
    publishRefused.value = false
    publishOpen.value = true
}

/** Closing the dialog after a refused publish goes to the first step it named (its card carries the reason). */
const closePublish = () => {
    publishOpen.value = false
    if (!publishRefused.value) return
    publishRefused.value = false
    showFirstFaultyStep()
}

const handlePublished = (answer) => {
    loadDraftStatus()
    refreshAutoApproval()
    emit('published', answer || null)
}

const requestClose = () => {
    if (saving.value) return
    flushTyping()
    if (!hasUnsavedChanges.value || isReadOnly.value) {
        emit('close')
        return
    }
    Modal.confirm({
        title: t('text.workflow.builder.unsaved_title'),
        content: t('text.workflow.builder.unsaved_body'),
        okText: t('text.workflow.builder.unsaved_leave'),
        okButtonProps: { danger: true },
        cancelText: t('text.workflow.builder.unsaved_stay'),
        onOk: () => emit('close'),
    })
}
</script>
