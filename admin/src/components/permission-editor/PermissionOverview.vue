<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  Permission Overview Component - Readable, auditable role permission summary
  Transforms text-heavy permission lists into scannable cards with visual hierarchy.
-->

<template>
    <div v-if="!resources.length" class="text-xs text-slate-400">—</div>
    <div v-else class="permission-overview">
        <!-- Compact summary (always visible) -->
        <div class="flex flex-wrap gap-1 items-center">
            <!-- Stats badges -->
            <a-tooltip :title="t('text.permission.overview.total_resources')">
                <span class="stat-badge">{{ resources.length }}</span>
            </a-tooltip>
            <a-tooltip v-if="stats.crud > 0" :title="t('text.permission.overview.full_crud_access')">
                <span class="stat-badge stat-crud">{{ stats.crud }} CRUD</span>
            </a-tooltip>
            <a-tooltip v-if="stats.read > 0" :title="t('text.permission.overview.read_only_access')">
                <span class="stat-badge stat-read">
                    {{ stats.read }} {{ t('text.permission.overview.read_badge') }}
                </span>
            </a-tooltip>
            <a-tooltip v-if="stats.partial > 0" :title="t('text.permission.overview.partial_permissions')">
                <span class="stat-badge stat-partial">
                    {{ stats.partial }} {{ t('text.permission.overview.partial_badge') }}
                </span>
            </a-tooltip>
            <a-tooltip
                v-if="stats.critical > 0"
                :title="t('text.permission.overview.critical_irreversible')"
            >
                <span class="stat-badge stat-critical">🔴 {{ stats.critical }}</span>
            </a-tooltip>
            <a-tooltip
                v-if="stats.sensitive > 0"
                :title="t('text.permission.overview.sensitive_data_access')"
            >
                <span class="stat-badge stat-sensitive">🔒 {{ stats.sensitive }}</span>
            </a-tooltip>

            <!-- Expand toggle -->
            <button class="expand-toggle" @click="isExpanded = !isExpanded">
                <i-icon :icon="isExpanded ? 'chevron-up' : 'chevron-down'" class="size-3" />
                {{
                    isExpanded
                        ? t('text.permission.overview.hide')
                        : t('text.permission.overview.details')
                }}
            </button>
        </div>

        <!-- Expanded view -->
        <div v-if="isExpanded" class="mt-2">
            <!-- Filter bar (sticky) -->
            <div class="filter-bar">
                <a-input
                    v-model:value="searchTerm"
                    :placeholder="t('text.permission.overview.search')"
                    size="small"
                    class="w-24"
                    allow-clear
                />
                <a-checkbox v-model:checked="onlyCritical" size="small">
                    <span class="text-red-600">{{ t('text.permission.overview.critical') }}</span>
                </a-checkbox>
                <a-checkbox v-model:checked="onlySensitive" size="small">
                    <span class="text-purple-600">{{ t('text.permission.overview.sensitive') }}</span>
                </a-checkbox>
                <a-checkbox v-model:checked="onlyPartial" size="small">
                    {{ t('text.permission.overview.partial') }}
                </a-checkbox>
                <button class="audit-btn" @click="applyAuditPreset">
                    ⚠️ {{ t('text.permission.overview.audit_risky') }}
                </button>
                <button v-if="hasActiveFilters" class="clear-btn" @click="clearFilters">
                    {{ t('text.permission.overview.clear') }}
                </button>
            </div>

            <!-- Resource list -->
            <div class="resource-list">
                <div
                    v-for="res in filteredResources"
                    :key="res.resourceKey"
                    class="resource-row"
                    :class="{
                        'resource-expanded': expandedResources.has(res.resourceKey),
                        'resource-critical': res.hasCritical,
                        'resource-sensitive': res.hasSensitive,
                    }"
                >
                    <!-- Main row -->
                    <div class="resource-main" @click="toggleResource(res.resourceKey)">
                        <!-- Level badge FIRST -->
                        <span :class="['level-badge', `level-${res.level}`]">
                            {{ levelLabel(res.level) }}
                        </span>

                        <!-- Resource name -->
                        <a-tooltip :title="res.resourceKey">
                            <span class="resource-name">{{ resourceLabel(res.resourceKey) }}</span>
                        </a-tooltip>

                        <!-- Special badges (priority order: SENS, DANG, DEL) -->
                        <a-tooltip
                            v-if="res.hasSensitive"
                            :title="t('text.permission.overview.sensitive_private_data')"
                        >
                            <span class="special-badge special-sensitive">🔒 {{ t('text.ui.permission-overview.badge_sensitive') }}</span>
                        </a-tooltip>
                        <a-tooltip
                            v-if="res.hasCritical"
                            :title="t('text.permission.overview.critical_force_delete')"
                        >
                            <span class="special-badge special-critical">🔴 {{ t('text.ui.permission-overview.badge_critical') }}</span>
                        </a-tooltip>
                        <a-tooltip
                            v-if="res.hasDelete && !res.hasCritical"
                            :title="t('text.permission.overview.delete_reversible')"
                        >
                            <span class="special-badge special-delete">🟠 {{ t('text.ui.permission-overview.badge_delete') }}</span>
                        </a-tooltip>
                        <a-tooltip
                            v-if="res.hasImport"
                            :title="t('text.permission.overview.import_bulk')"
                        >
                            <span class="special-badge special-import">📥 {{ t('text.ui.permission-overview.badge_import') }}</span>
                        </a-tooltip>

                        <!-- Spacer -->
                        <span class="flex-1"></span>

                        <!-- Extra actions count (non-CRUD only) -->
                        <span v-if="res.extraActions.length" class="extra-count">
                            +{{ res.extraActions.length }}
                        </span>

                        <!-- Expand chevron -->
                        <i-icon
                            :icon="expandedResources.has(res.resourceKey) ? 'chevron-up' : 'chevron-down'"
                            class="size-3 text-gray-400"
                        />
                    </div>

                    <!-- Expanded details (only non-CRUD actions) -->
                    <div v-if="expandedResources.has(res.resourceKey)" class="resource-details">
                        <template v-if="res.extraActions.length">
                            <div class="extra-actions">
                                <a-tooltip
                                    v-for="action in res.extraActions"
                                    :key="action"
                                    :title="ACTION_HELP_KEY[action] ? t(ACTION_HELP_KEY[action]) : action"
                                >
                                    <span
                                        :class="[
                                            'action-tag',
                                            isActionCritical(action) && 'action-critical',
                                            isActionSensitive(action) && 'action-sensitive',
                                            isActionDelete(action) && 'action-delete',
                                        ]"
                                    >
                                        <span v-if="isActionCritical(action)">🔴</span>
                                        <span v-else-if="isActionDelete(action)">🟠</span>
                                        <span v-else-if="isActionSensitive(action)">🔒</span>
                                        {{ action }}
                                    </span>
                                </a-tooltip>
                            </div>
                        </template>
                        <template v-else>
                            <span class="text-xs text-gray-400 italic">
                                {{ t('text.permission.overview.all_standard_crud_enabled') }}
                            </span>
                        </template>
                    </div>
                </div>

                <!-- No results -->
                <div v-if="!filteredResources.length" class="text-xs text-gray-400 py-2 text-center">
                    {{ t('text.permission.overview.no_resources_match_filters') }}
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { permissionResourceLabel } from './resource-label'

const props = defineProps({
    permissions: { type: Array, default: () => [] },
})

const { t, te } = useI18n()
const resourceLabel = (resource) => permissionResourceLabel(resource, t, te)

// Short access-level label shown in the resource row (the badge CSS upper-cases it).
const levelLabel = (level) => {
    const labels = {
        crud: 'CRUD',
        read: t('text.permission.overview.read_badge'),
        partial: t('text.permission.overview.partial_badge'),
        none: t('text.ui.permission-overview.level_none'),
    }
    return labels[level] || level
}

// Constants - redefined danger levels
const CRUD_ACTIONS = new Set(['viewAny', 'view', 'create', 'edit', 'update', 'delete'])
const CRITICAL_ACTIONS = new Set(['forceDelete']) // RED - only irreversible
const DELETE_ACTIONS = new Set(['delete', 'restore']) // AMBER - reversible
const SENSITIVE_ACTIONS = new Set(['view_sensitive'])
const WRITE_ACTIONS = new Set(['create', 'edit', 'update', 'delete', 'forceDelete', 'import'])

const ACTION_ORDER = ['forceDelete', 'restore', 'import', 'view_sensitive']

const ACTION_HELP_KEY = {
    forceDelete: 'text.permission.action.forceDelete',
    restore: 'text.permission.action.restore',
    view_sensitive: 'text.permission.action.view_sensitive',
    import: 'text.permission.action.import',
    delete: 'text.permission.action.delete',
    hub: 'text.permission.action.hub',
}

// State
const isExpanded = ref(false)
const searchTerm = ref('')
const onlyCritical = ref(false)
const onlySensitive = ref(false)
const onlyPartial = ref(false)
const expandedResources = ref(new Set())

// Transform permissions into resource summaries
const resources = computed(() => {
    if (!Array.isArray(props.permissions) || !props.permissions.length) {
        return []
    }

    // Group by resource (group field)
    const grouped = {}
    for (const perm of props.permissions) {
        const key = perm.group || 'other'
        if (!grouped[key]) {
            grouped[key] = new Set()
        }
        grouped[key].add(perm.name)
    }

    // Transform to summaries
    return Object.entries(grouped).map(([resourceKey, actionsSet]) => {
        const actions = [...actionsSet]

        // Infer level
        const hasCrud = [...CRUD_ACTIONS].every((a) => actionsSet.has(a))
        const hasNoWrite = ![...WRITE_ACTIONS].some((a) => actionsSet.has(a))
        const hasRead = actionsSet.has('viewAny') && actionsSet.has('view')
        let level = 'partial'
        if (hasCrud) level = 'crud'
        else if (hasRead && hasNoWrite) level = 'read'
        else if (actions.length === 0) level = 'none'

        // Flags - new danger classification
        const hasCritical = [...CRITICAL_ACTIONS].some((a) => actionsSet.has(a)) // RED only for forceDelete
        const hasDelete = actionsSet.has('delete') || actionsSet.has('restore') // AMBER for regular delete
        const hasSensitive = [...SENSITIVE_ACTIONS].some((a) => actionsSet.has(a))
        const hasImport = actionsSet.has('import')
        const hasWrite = [...WRITE_ACTIONS].some((a) => actionsSet.has(a))

        // Extra actions (non-CRUD only)
        const extraActions = actions
            .filter((a) => !CRUD_ACTIONS.has(a))
            .sort((a, b) => {
                const ai = ACTION_ORDER.indexOf(a)
                const bi = ACTION_ORDER.indexOf(b)
                return (ai === -1 ? 999 : ai) - (bi === -1 ? 999 : bi)
            })

        return {
            resourceKey,
            actions,
            level,
            hasCritical,
            hasDelete,
            hasSensitive,
            hasImport,
            hasWrite,
            extraActions,
        }
    }).sort((a, b) => a.resourceKey.localeCompare(b.resourceKey))
})

// Stats
const stats = computed(() => {
    const res = resources.value
    return {
        crud: res.filter((r) => r.level === 'crud').length,
        read: res.filter((r) => r.level === 'read').length,
        partial: res.filter((r) => r.level === 'partial').length,
        critical: res.filter((r) => r.hasCritical).length, // Only forceDelete
        sensitive: res.filter((r) => r.hasSensitive).length,
    }
})

// Has active filters
const hasActiveFilters = computed(() => {
    return searchTerm.value || onlyCritical.value || onlySensitive.value || onlyPartial.value
})

// Filtered resources
const filteredResources = computed(() => {
    let list = resources.value

    if (searchTerm.value) {
        const term = searchTerm.value.toLowerCase()
        list = list.filter((r) => r.resourceKey.toLowerCase().includes(term) || resourceLabel(r.resourceKey).toLowerCase().includes(term))
    }

    if (onlyCritical.value) {
        list = list.filter((r) => r.hasCritical) // Only forceDelete triggers this
    }

    if (onlySensitive.value) {
        list = list.filter((r) => r.hasSensitive)
    }

    if (onlyPartial.value) {
        list = list.filter((r) => r.level === 'partial')
    }

    return list
})

// Methods
function toggleResource(key) {
    const newSet = new Set(expandedResources.value)
    if (newSet.has(key)) {
        newSet.delete(key)
    } else {
        newSet.add(key)
    }
    expandedResources.value = newSet
}

function applyAuditPreset() {
    onlyCritical.value = true
    onlySensitive.value = true
    onlyPartial.value = true
}

function clearFilters() {
    searchTerm.value = ''
    onlyCritical.value = false
    onlySensitive.value = false
    onlyPartial.value = false
}

function isActionCritical(action) {
    return CRITICAL_ACTIONS.has(action)
}

function isActionDelete(action) {
    return DELETE_ACTIONS.has(action)
}

function isActionSensitive(action) {
    return SENSITIVE_ACTIONS.has(action)
}

</script>

<style scoped>
.permission-overview {
    font-size: 11px;
}

.stat-badge {
    display: inline-flex;
    align-items: center;
    padding: 1px 5px;
    border-radius: 3px;
    background: #f1f5f9;
    color: #475569;
    font-size: 9px;
    font-weight: 500;
}

.stat-crud {
    background: #dcfce7;
    color: #166534;
}

.stat-read {
    background: #dbeafe;
    color: #1e40af;
}

.stat-partial {
    background: #fef3c7;
    color: #92400e;
}

.stat-critical {
    background: transparent;
    border: 1px solid #fca5a5;
    color: #dc2626;
    font-weight: 600;
}

.stat-sensitive {
    background: #f3e8ff;
    color: #7c3aed;
    font-weight: 600;
}

.expand-toggle {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    padding: 1px 5px;
    border-radius: 3px;
    background: transparent;
    border: 1px solid #e2e8f0;
    color: #64748b;
    font-size: 9px;
    cursor: pointer;
    transition: all 0.15s;
}

.expand-toggle:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    padding: 4px 6px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-bottom: none;
    border-radius: 6px 6px 0 0;
    position: sticky;
    top: 0;
    z-index: 10;
}

.audit-btn {
    padding: 2px 6px;
    border-radius: 3px;
    background: #fef3c7;
    border: 1px solid #f59e0b;
    color: #92400e;
    font-size: 9px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
}

.audit-btn:hover {
    background: #fde68a;
}

.clear-btn {
    padding: 2px 6px;
    border-radius: 3px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    color: #64748b;
    font-size: 9px;
    cursor: pointer;
}

.clear-btn:hover {
    background: #e2e8f0;
}

.resource-list {
    max-height: 280px;
    overflow-y: auto;
    border: 1px solid #e2e8f0;
    border-radius: 0 0 6px 6px;
}

.resource-row {
    border-bottom: 1px solid #f1f5f9;
}

.resource-row:last-child {
    border-bottom: none;
}

.resource-row:hover {
    background: #f8fafc;
}

.resource-critical {
    border-left: 2px solid #ef4444;
}

.resource-sensitive {
    border-left: 2px solid #8b5cf6;
}

.resource-main {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px 6px;
    cursor: pointer;
}

.resource-name {
    color: #475569;
    max-width: 140px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 10px;
}

.level-badge {
    padding: 1px 4px;
    border-radius: 2px;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
    flex-shrink: 0;
}

.level-crud {
    background: #dcfce7;
    color: #166534;
}

.level-read {
    background: #dbeafe;
    color: #1e40af;
}

.level-partial {
    background: #fef3c7;
    color: #92400e;
}

.level-none {
    background: #f1f5f9;
    color: #64748b;
}

.special-badge {
    padding: 1px 4px;
    border-radius: 2px;
    font-size: 8px;
    font-weight: 600;
    flex-shrink: 0;
}

/* Critical: outline style, not solid red */
.special-critical {
    background: transparent;
    color: #dc2626;
    border: 1px solid #fca5a5;
}

/* Delete: amber, calmer */
.special-delete {
    background: #fffbeb;
    color: #b45309;
    border: 1px solid #fcd34d;
}

.special-sensitive {
    background: #f3e8ff;
    color: #7c3aed;
    border: 1px solid #c4b5fd;
}

.special-import {
    background: #e0e7ff;
    color: #4338ca;
}

.extra-count {
    font-size: 9px;
    color: #94a3b8;
    font-weight: 500;
}

.resource-details {
    padding: 4px 6px 6px 26px;
    background: #fafafa;
    border-top: 1px solid #f1f5f9;
}

.extra-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.action-tag {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    padding: 2px 5px;
    border-radius: 3px;
    background: #f1f5f9;
    color: #475569;
    font-size: 9px;
}

/* Critical action: outline red */
.action-critical {
    background: transparent;
    color: #dc2626;
    border: 1px solid #fca5a5;
    font-weight: 600;
}

/* Delete action: amber */
.action-delete {
    background: #fffbeb;
    color: #b45309;
    border: 1px solid #fcd34d;
}

.action-sensitive {
    background: #f3e8ff;
    color: #7c3aed;
    font-weight: 600;
}
</style>
