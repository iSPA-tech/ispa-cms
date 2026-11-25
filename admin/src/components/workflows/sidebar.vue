<script setup>
import useDragAndDrop from '@/components/workflows/useDnd.js'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useVueFlow } from '@vue-flow/core'
import { normalizeBlueprints } from './blueprintNormalizer'
import { buildPaletteGroups, useNodeCatalog } from './nodeCatalog'
import CoreNodePalette from '@/components/workflow/CoreNodePalette.vue'
import WorkflowGraphWarnings from '@/components/workflow/WorkflowGraphWarnings.vue'

defineOptions({
    name: 'VueFlowSidebar',
})

const props = defineProps({
    blueprints: {
        type: Array,
        default: () => [],
    },
    /** A workflow that cannot be edited: steps cannot be dragged or added. */
    disabled: {
        type: Boolean,
        default: false,
    },
})

const searchQuery = ref('')
const showHidden = ref(false)
const expandedCategories = ref([])
const expandedGroups = ref([])
const { t } = useI18n()
const { catalog, load: loadCatalog } = useNodeCatalog()
const { getNodes: canvasNodes, getEdges: canvasEdges } = useVueFlow()

// Refresh on every open: the catalog depends on the signed-in user's permissions and on installed modules.
onMounted(() => loadCatalog(true))

const usedStepCodes = computed(() =>
    (canvasNodes.value || []).map((node) => String(node?.data?.step_code || node?.id || ''))
)

// Built-in list, used only when the node catalog cannot be loaded (older server).
const coreNodes = computed(() => [
    {
        step_type: 'ai_action',
        title: t('text.workflow.node.ai_action.title'),
        description: t('text.workflow.node.ai_action.desc'),
        default_config: {
            agentProfileSlug: '',
            toolName: '',
            moduleScope: '',
            goal: '',
            storeAs: 'vars.agent_result',
            toolInput: {},
        },
    },
    {
        step_type: 'set_variable',
        title: t('text.workflow.node.set_variable.title'),
        description: t('text.workflow.node.set_variable.desc'),
        default_config: { assignments: [{ key: 'vars.foo', valueExpr: 'root.id' }] },
    },
    {
        step_type: 'clear_variable',
        title: t('text.workflow.node.clear_variable.title'),
        description: t('text.workflow.node.clear_variable.desc'),
        default_config: { targets: ['vars.temp'] },
    },
    {
        step_type: 'retrieve',
        title: t('text.workflow.node.retrieve.title'),
        description: t('text.workflow.node.retrieve.desc'),
        default_config: {
            model: '',
            where: [],
            first: true,
            limit: 1,
            orderBy: [],
            storeAs: 'vars.result',
            includeTrashed: false,
        },
    },
    {
        step_type: 'change',
        title: t('text.workflow.node.change.title'),
        description: t('text.workflow.node.change.desc'),
        default_config: {
            targetExpr: 'vars.target',
            changes: [{ field: '', valueExpr: '' }],
            save: true,
        },
    },
    {
        step_type: 'create_object',
        title: t('text.workflow.node.create_object.title'),
        description: t('text.workflow.node.create_object.desc'),
        default_config: {
            model: '',
            fields: [{ field: '', valueExpr: '' }],
            save: true,
            storeAs: 'vars.created',
        },
    },
    {
        step_type: 'delete_object',
        title: t('text.workflow.node.delete_object.title'),
        description: t('text.workflow.node.delete_object.desc'),
        default_config: { targetExpr: 'vars.target', force: false },
    },
    {
        step_type: 'assert_condition',
        title: t('text.workflow.node.assert_condition.title'),
        description: t('text.workflow.node.assert_condition.desc'),
        default_config: {
            expression: 'true',
            message: t('text.workflow.node.assertion_failed'),
        },
    },
    {
        step_type: 'log_message',
        title: t('text.workflow.node.log_message.title'),
        description: t('text.workflow.node.log_message.desc'),
        default_config: { level: 'info', message: 'Hello {{ root.id }}', context: {} },
    },
    {
        step_type: 'terminate_workflow',
        title: t('text.workflow.node.terminate_workflow.title'),
        description: t('text.workflow.node.terminate_workflow.desc'),
        default_config: { status: 'success', message: '' },
    },
])

const { onDragStart, addAtFreeSpot } = useDragAndDrop()

const startDrag = (event, payload) => {
    if (props.disabled) return event.preventDefault()
    onDragStart(event, payload)
}
const addStep = (payload) => {
    if (!props.disabled) addAtFreeSpot?.(payload)
}

const buildBlueprintPayload = (className, fnName) => ({
    type: 'custom',
    data: {
        name: `${className} :: ${fnName}`,
        step_code: fnName,
        step_type: 'script',
        step_config: {
            class: className,
            method: fnName,
            payload: {
                message: '',
            },
        },
    },
})

const hasCatalog = computed(() => catalog.loaded && catalog.nodes.length > 0)

// The plain start / decision / finish cards are for sites whose catalog has no step for that job.
const builtinNodes = computed(() => {
    const groups = new Set(catalog.nodes.map((node) => node.group))
    return [
        {
            type: 'input',
            label: t('text.workflow.node.input.label'),
            hint: t('text.workflow.node.input.hint'),
            hidden: groups.has('trigger'),
        },
        {
            type: 'decision',
            label: t('text.workflow.node.decision.label'),
            hint: t('text.workflow.node.decision.hint'),
            hidden: !!catalog.byType?.decision,
        },
        {
            type: 'output',
            label: t('text.workflow.node.output.label'),
            hint: t('text.workflow.node.output.hint'),
            hidden: !!catalog.byType?.terminate_workflow,
        },
    ].filter((node) => !node.hidden)
})

const paletteGroups = computed(() => {
    if (hasCatalog.value) {
        return buildPaletteGroups(catalog, searchQuery.value, t)
    }
    if (catalog.loading && !catalog.failed) return []

    const term = (searchQuery.value || '').trim().toLowerCase()
    const nodes = coreNodes.value
        .map((node) => ({
            type: node.step_type,
            title: node.title,
            summary: node.description,
            default_config: node.default_config,
            safety: [],
        }))
        .filter((node) => !term || `${node.type} ${node.title} ${node.summary}`.toLowerCase().includes(term))

    return nodes.length ? [{ key: 'core', label: t('text.workflow.palette.title'), icon: 'puzzle-outline', nodes }] : []
})

const groupedBlueprints = computed(() => {
    const normalized = normalizeBlueprints(props.blueprints || [], { showHidden: showHidden.value })
    return normalized
})

const filteredBlueprints = computed(() => {
    const term = (searchQuery.value || '').toLowerCase()
    if (!term) {
        expandedCategories.value = []
        expandedGroups.value = []
        return groupedBlueprints.value.map((cat) => ({
            ...cat,
            groups: (cat.groups || []).map((g) => ({
                ...g,
                items: limitItems(g.items || [], g.groupKey),
            })),
        }))
    }

    const matches = []
    groupedBlueprints.value.forEach((cat) => {
        const groups = (cat.groups || []).map((g) => {
            const items = (g.items || []).filter((item) => {
                const hay = `${g.title} ${g.subtitle || ''} ${item.label}`.toLowerCase()
                return hay.includes(term)
            })
            return { ...g, items }
        }).filter((g) => g.items && g.items.length)

        if (groups.length) {
            matches.push({ ...cat, groups })
            if (!expandedCategories.value.includes(cat.category)) {
                expandedCategories.value.push(cat.category)
            }
            groups.forEach((g) => {
                if (!expandedGroups.value.includes(g.groupKey)) {
                    expandedGroups.value.push(g.groupKey)
                }
            })
        }
    })
    return matches
})

const methodLimit = 6
const expandedGroupMethods = ref(new Set())

const limitItems = (items, groupKey) => {
    if (!items) return []
    if ((searchQuery.value || '').length) return items
    if (groupKey && expandedGroupMethods.value.has(groupKey)) return items
    return items.length > methodLimit ? items.slice(0, methodLimit) : items
}

const showMoreVisible = (group) => {
    if (!group?.items) return false
    if ((searchQuery.value || '').length) return false
    return group.items.length > methodLimit && !expandedGroupMethods.value.has(group.groupKey)
}

const toggleExpanded = (groupKey) => {
    if (expandedGroupMethods.value.has(groupKey)) {
        expandedGroupMethods.value.delete(groupKey)
    } else {
        expandedGroupMethods.value.add(groupKey)
    }
}
</script>

<template>
    <aside class="max-h-full overflow-y-auto rounded-xl border border-solid border-slate-200 bg-slate-50 p-3 text-slate-900">
        <WorkflowGraphWarnings :nodes="canvasNodes" :edges="canvasEdges" class="mb-3" />

        <div class="mb-3">
            <a-input
                v-model:value="searchQuery"
                allow-clear
                :aria-label="t('text.workflow.palette.search')"
                :placeholder="t('text.workflow.palette.search')"
            >
                <template #prefix>
                    <i-icon icon="magnify" class="h-4 w-4 text-gray-400" />
                </template>
            </a-input>
            <p class="mb-0 mt-2 text-xs text-slate-500">{{ t('text.workflow.sidebar.drag_nodes_into_canvas') }}</p>
        </div>

        <div v-if="builtinNodes.length" class="mb-4 grid gap-2">
            <div
                v-for="node in builtinNodes"
                :key="node.type"
                class="node-pill cursor-grab rounded-lg border border-solid border-slate-200 bg-white px-3 py-2 shadow-sm transition hover:border-sky-300 hover:shadow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-200"
                :class="{ 'cursor-not-allowed opacity-60': disabled }"
                :draggable="!disabled"
                role="button"
                :tabindex="disabled ? -1 : 0"
                :data-builtin-node="node.type"
                @dragstart="startDrag($event, node.type)"
                @click="addStep(node.type)"
                @keydown.enter.prevent="addStep(node.type)"
            >
                <p class="mb-0 text-sm font-semibold text-gray-800">{{ node.label }}</p>
                <p class="mb-0 text-xs text-gray-500">{{ node.hint }}</p>
            </div>
        </div>

        <CoreNodePalette
            :groups="paletteGroups"
            :loading="catalog.loading"
            :search="searchQuery"
            :used-codes="usedStepCodes"
            :disabled="disabled"
        />

        <div v-if="filteredBlueprints.length" class="mt-6 space-y-3">
            <div class="text-sm font-semibold text-slate-900">
                {{ t('text.workflow.sidebar.blueprint_library') }}
            </div>
            <label class="flex items-center gap-2 text-xs text-gray-600">
                <input v-model="showHidden" type="checkbox" />
                {{ t('text.workflow.sidebar.show_hidden_request_methods') }}
            </label>
            <a-collapse v-model:activeKey="expandedCategories" accordion size="small">
                <a-collapse-panel
                    v-for="cat in filteredBlueprints"
                    :key="cat.category"
                    :header="`${cat.groupLabel} (${cat.groups.length})`"
                >
                    <a-collapse v-model:activeKey="expandedGroups" accordion size="small">
                        <a-collapse-panel
                            v-for="group in cat.groups"
                            :key="group.groupKey"
                            :header="`${group.title} (${group.items.length})`"
                        >
                            <div class="mb-2 truncate text-[11px] text-gray-500">
                                {{ group.subtitle }}
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <a-tooltip
                                    v-for="fn in group.items"
                                    :key="fn.key"
                                    :title="fn.hidden ? (fn.disallowed_reason || fn.reason || t('text.workflow.sidebar.hidden')) : ''"
                                >
                                    <span
                                        class="rounded-full border border-solid px-2.5 py-1 text-[11px] transition"
                                        :class="
                                            fn.hidden || disabled
                                                ? 'cursor-not-allowed border-slate-300 bg-gray-200 text-slate-600 opacity-50'
                                                : 'cursor-grab border-sky-200 bg-cyan-50 text-sky-800 hover:border-blue-300 hover:bg-blue-100'
                                        "
                                        :draggable="!fn.hidden && !disabled"
                                        @dragstart="!fn.hidden && startDrag($event, buildBlueprintPayload(group.groupKey, fn.label))"
                                    >
                                        {{ fn.label }}
                                        <span
                                            v-if="fn.hidden"
                                            class="ml-1.5 rounded-full border border-solid border-red-200 bg-red-100 px-1.5 py-0.5 text-[10px] text-red-700"
                                        >
                                            {{ t('text.workflow.sidebar.disallowed') }}
                                        </span>
                                    </span>
                                </a-tooltip>
                            </div>
                            <div v-if="showMoreVisible(group)" class="mt-2">
                                <a-button type="link" size="small" @click="toggleExpanded(group.groupKey)">
                                    {{
                                        expandedGroupMethods.has(group.groupKey)
                                            ? t('text.workflow.sidebar.show_less')
                                            : t('text.workflow.sidebar.show_more_count', {
                                                  count: group.items.length - methodLimit,
                                              })
                                    }}
                                </a-button>
                            </div>
                        </a-collapse-panel>
                    </a-collapse>
                </a-collapse-panel>
            </a-collapse>
        </div>
    </aside>
</template>
