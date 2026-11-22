<script setup>
import useDragAndDrop from '@/components/workflows/useDnd.js'
import { computed, defineComponent, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { normalizeBlueprints } from './blueprintNormalizer'
import CoreNodePalette from '@/components/workflow/CoreNodePalette.vue'

defineComponent({
    name: 'VueFlowSidebar',
})

const props = defineProps({
    blueprints: {
        type: Array,
        default: () => [],
    },
})

const searchQuery = ref('')
const showHidden = ref(false)
const expandedCategories = ref([])
const expandedGroups = ref([])
const { t } = useI18n()
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

const { onDragStart } = useDragAndDrop()

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

const builtinNodes = computed(() => [
    {
        type: 'input',
        label: t('text.workflow.node.input.label'),
        hint: t('text.workflow.node.input.hint'),
    },
    {
        type: 'decision',
        label: t('text.workflow.node.decision.label'),
        hint: t('text.workflow.node.decision.hint'),
    },
    {
        type: 'output',
        label: t('text.workflow.node.output.label'),
        hint: t('text.workflow.node.output.hint'),
    },
])

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
    <aside class="builder-sidebar">
        <div class="description">{{ t('text.workflow.sidebar.drag_nodes_into_canvas') }}</div>

        <div v-if="blueprints.length" class="mb-3">
            <input
                v-model="searchQuery"
                type="search"
                class="search"
                :placeholder="t('text.workflow.sidebar.search_class_or_function')"
            />
            <button v-if="searchQuery" class="clear-btn" type="button" @click="searchQuery = ''">
                {{ t('text.workflow.sidebar.clear_search') }}
            </button>
            <label class="mt-2 flex items-center gap-2 text-xs text-gray-600">
                <input type="checkbox" v-model="showHidden" />
                {{ t('text.workflow.sidebar.show_hidden_request_methods') }}
            </label>
        </div>

        <div class="nodes">
            <div
                v-for="node in builtinNodes"
                :key="node.type"
                class="node-pill"
                :draggable="true"
                @dragstart="onDragStart($event, node.type)"
            >
                <div class="node-label">{{ node.label }}</div>
                <div class="node-hint">{{ node.hint }}</div>
            </div>
        </div>

        <div v-if="coreNodes.length" class="mt-6">
            <CoreNodePalette :nodes="coreNodes" :search="searchQuery" />
        </div>

        <div v-if="filteredBlueprints.length" class="mt-6 space-y-3">
            <div class="font-semibold text-sm text-slate-900">
                {{ t('text.workflow.sidebar.blueprint_library') }}
            </div>
            <a-collapse accordion size="small" v-model:activeKey="expandedCategories">
                <a-collapse-panel
                    v-for="cat in filteredBlueprints"
                    :key="cat.category"
                    :header="`${cat.groupLabel} (${cat.groups.length})`"
                >
                    <a-collapse accordion size="small" v-model:activeKey="expandedGroups">
                        <a-collapse-panel
                            v-for="group in cat.groups"
                            :key="group.groupKey"
                            :header="`${group.title} (${group.items.length})`"
                        >
                            <div class="text-[11px] text-gray-500 truncate mb-2">
                                {{ group.subtitle }}
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <a-tooltip
                                    v-for="fn in group.items"
                                    :key="fn.key"
                                    :title="fn.hidden ? (fn.disallowed_reason || fn.reason || t('text.workflow.sidebar.hidden')) : ''"
                                >
                                    <span
                                        class="fn-pill"
                                        :class="fn.hidden ? 'fn-pill--disabled' : ''"
                                        :draggable="!fn.hidden"
                                        @dragstart="!fn.hidden && onDragStart($event, buildBlueprintPayload(group.groupKey, fn.label))"
                                    >
                                        {{ fn.label }}
                                        <span v-if="fn.hidden" class="badge-disallowed">
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

<style scoped>
.builder-sidebar {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 14px;
    color: #0f172a;
    overflow-y: auto;
    max-height: 100%;
    box-shadow: 0 10px 30px -10px rgba(15, 23, 42, 0.18);
}

.description {
    font-size: 12px;
    color: #475569;
    margin-bottom: 10px;
}

.search {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 13px;
}

.clear-btn {
    margin-top: 6px;
    font-size: 11px;
    color: #0ea5e9;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
}

.nodes {
    display: grid;
    gap: 8px;
}

.node-pill {
    cursor: grab;
    padding: 12px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: linear-gradient(135deg, #ffffff, #f8fafc);
    color: #0f172a;
    box-shadow: 0 6px 16px -10px rgba(15, 23, 42, 0.25);
    transition: transform 0.12s ease, box-shadow 0.12s ease;
}

.node-pill:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 22px -10px rgba(15, 23, 42, 0.35);
}

.node-label {
    font-weight: 700;
    font-size: 15px;
    color: #0f172a;
}

.node-hint {
    font-size: 12px;
    color: #6b7280;
    margin-top: 2px;
}

.blueprint-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px;
    box-shadow: 0 8px 18px -10px rgba(15, 23, 42, 0.25);
}

.fn-pill {
    cursor: grab;
    padding: 6px 10px;
    font-size: 11px;
    border: 1px solid #bae6fd;
    border-radius: 999px;
    background: #ecfeff;
    color: #075985;
    transition: background 0.1s ease, border-color 0.1s ease;
}

.fn-pill:hover {
    background: #dbeafe;
    border-color: #93c5fd;
}

.fn-pill--disabled {
    cursor: not-allowed;
    opacity: 0.5;
    background: #e5e7eb;
    border-color: #cbd5e1;
    color: #475569;
}

.badge-disallowed {
    margin-left: 6px;
    font-size: 10px;
    background: #fee2e2;
    color: #b91c1c;
    padding: 2px 6px;
    border-radius: 999px;
    border: 1px solid #fecaca;
}
</style>
