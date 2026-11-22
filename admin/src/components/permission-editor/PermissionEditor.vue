<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-drawer
        :open="visible"
        :title="t('text.permission.editor.title', { role: roleName })"
        placement="right"
        width="90%"
        @close="handleClose"
    >
        <template #extra>
            <i-button :loading="isSaving" variant="red" @click="handleSave">
                <template #icon><i-icon class="size-4" icon="content-save" /></template>
                <span>{{ $t('nav.b.save') }}</span>
            </i-button>
        </template>

        <div class="flex h-full gap-4">
            <!-- Left: Module List -->
            <div class="w-56 shrink-0 border-r pr-4 overflow-y-auto">
                <a-input
                    v-model:value="moduleSearch"
                    allow-clear
                    class="mb-3"
                    :placeholder="t('text.permission.editor.filter_modules')"
                >
                    <template #prefix>
                        <i-icon class="size-4 text-gray-400" icon="magnify" />
                    </template>
                </a-input>

                <div class="space-y-1">
                    <div
                        v-for="mod in filteredModules"
                        :key="mod.module"
                        :class="[
                            'flex items-center justify-between px-3 py-2 rounded cursor-pointer transition-colors text-sm',
                            selectedModule === mod.module
                                ? 'bg-blue-100 text-blue-700'
                                : 'hover:bg-gray-100',
                        ]"
                        @click="selectModule(mod.module)"
                    >
                        <span class="font-medium truncate">{{ moduleLabel(mod.module) }}</span>
                        <a-badge
                            :count="mod.count"
                            :number-style="{
                                backgroundColor:
                                    selectedModule === mod.module ? '#1d4ed8' : '#6b7280',
                            }"
                        />
                    </div>
                </div>

                <div v-if="isLoadingModules" class="text-center py-4 text-gray-400">
                    {{ t('text.permission.editor.loading_modules') }}
                </div>
            </div>

            <!-- Right: Permission Matrix -->
            <div class="flex-1 flex flex-col overflow-hidden">
                <!-- Top Bar -->
                <div class="flex items-center gap-3 mb-3 flex-wrap">
                    <a-input
                        v-model:value="permissionSearch"
                        allow-clear
                        class="w-48"
                        :placeholder="t('text.permission.editor.search_permissions')"
                        @input="handleSearchDebounced"
                    >
                        <template #prefix>
                            <i-icon class="size-4 text-gray-400" icon="magnify" />
                        </template>
                    </a-input>

                    <a-input
                        v-model:value="resourceFilter"
                        allow-clear
                        class="w-40"
                        :placeholder="t('text.permission.editor.filter_resources')"
                        @input="handleResourceFilterDebounced"
                    >
                        <template #prefix>
                            <i-icon class="size-4 text-gray-400" icon="filter-variant" />
                        </template>
                    </a-input>

                    <!-- Module-level check all -->
                    <a-checkbox
                        :checked="isModuleAllChecked"
                        :indeterminate="isModuleIndeterminate"
                        @change="toggleModuleAll"
                    >
                        {{ t('text.permission.editor.all_count', { count: modulePerms.length }) }}
                    </a-checkbox>

                    <a-button size="small" @click="clearModuleAll">
                        {{ t('text.permission.overview.clear') }}
                    </a-button>

                    <span class="text-sm text-gray-500 ml-auto">
                        {{
                            t('text.permission.editor.selected_summary', {
                                selected: selectedCount,
                                resources: filteredResourceKeys.length,
                            })
                        }}
                    </span>
                </div>

                <!-- Loading state -->
                <div v-if="isLoadingPerms" class="flex-1 flex items-center justify-center">
                    <a-spin size="large" />
                </div>

                <!-- Empty state -->
                <div
                    v-else-if="!modulePerms.length"
                    class="flex-1 flex items-center justify-center text-gray-400"
                >
                    {{
                        selectedModule
                            ? t('text.permission.editor.no_permissions_found')
                            : t('text.permission.editor.select_module')
                    }}
                </div>

                <!-- Matrix View -->
                <div
                    v-else
                    ref="matrixScrollRef"
                    :class="{ 'has-scroll': hasHorizontalScroll }"
                    :style="{ '--action-cols': actionColumns.length }"
                    class="flex-1 overflow-auto border rounded permission-matrix"
                    @scroll="handleMatrixScroll"
                >
                    <!-- Header row with action columns -->
                    <div class="permission-matrix-header">
                        <div class="cell-resource sticky-col-resource font-semibold">
                            {{ t('text.permission.editor.resource') }}
                        </div>
                        <div class="cell-all sticky-col-all font-semibold text-center">
                            {{ t('text.permission.editor.all') }}
                        </div>
                        <a-tooltip
                            v-for="action in actionColumns"
                            :key="action"
                            :title="ACTION_HELP_KEY[action] ? `${action}: ${t(ACTION_HELP_KEY[action])}` : action"
                        >
                            <div class="cell-action font-semibold text-center text-xs cursor-help">
                                {{ getActionShortLabel(action) }}
                            </div>
                        </a-tooltip>
                    </div>

                    <!-- Resource rows -->
                    <div
                        v-for="resourceKey in filteredResourceKeys"
                        :key="resourceKey"
                        class="permission-matrix-row"
                    >
                        <!-- Resource name with presets -->
                        <div class="cell-resource sticky-col-resource">
                            <div class="flex-1 min-w-0">
                                <a-tooltip :title="resourceKey">
                                    <span class="font-medium text-sm truncate block">{{ resourceLabel(resourceKey) }}</span>
                                </a-tooltip>
                                <span class="text-xs text-gray-400">
                                    {{ getResourceSelectedCount(resourceKey) }}/{{
                                        groupedResources[resourceKey]?.perms.length || 0
                                    }}
                                </span>
                            </div>
                            <div class="preset-buttons">
                                <a-tooltip :title="t('text.permission.editor.select_crud_actions')">
                                    <button
                                        class="preset-btn"
                                        @click.stop="applyResourcePreset(resourceKey, 'crud')"
                                    >
                                        C
                                    </button>
                                </a-tooltip>
                                <a-tooltip :title="t('text.permission.editor.select_read_only_actions')">
                                    <button
                                        class="preset-btn"
                                        @click.stop="applyResourcePreset(resourceKey, 'read')"
                                    >
                                        R
                                    </button>
                                </a-tooltip>
                                <a-tooltip :title="t('text.permission.editor.clear_all_permissions')">
                                    <button
                                        class="preset-btn preset-btn-danger"
                                        @click.stop="applyResourcePreset(resourceKey, 'none')"
                                    >
                                        ×
                                    </button>
                                </a-tooltip>
                            </div>
                        </div>

                        <!-- Row-level check all -->
                        <div class="cell-all sticky-col-all text-center">
                            <a-checkbox
                                :checked="isResourceAllChecked(resourceKey)"
                                :indeterminate="isResourceIndeterminate(resourceKey)"
                                @change="toggleResourceAll(resourceKey)"
                            />
                        </div>

                        <!-- Action cells -->
                        <div
                            v-for="action in actionColumns"
                            :key="`${resourceKey}-${action}`"
                            class="cell-action text-center"
                        >
                            <template v-if="getPermissionId(resourceKey, action)">
                                <a-checkbox
                                    :checked="
                                        selectedPermissionIds.has(
                                            getPermissionId(resourceKey, action)
                                        )
                                    "
                                    @change="togglePermission(getPermissionId(resourceKey, action))"
                                />
                            </template>
                            <span v-else class="text-gray-300">-</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </a-drawer>
</template>

<script setup>
import { computed, ref, toRef, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { iAlert } from '@/mixins'
import ajax from '@/ajax'
import { useRolePermissions, useSyncRolePermissions } from '@/hooks/usePermissionCatalog'
import { permissionResourceLabel } from './resource-label'

const props = defineProps({
    visible: { type: Boolean, default: false },
    roleId: { type: Number, default: null },
    roleName: { type: String, default: '' },
})

const emit = defineEmits(['update:visible', 'saved'])
const { t, te } = useI18n()

// Constants
const BASE_ACTIONS = ['viewAny', 'view', 'create', 'edit', 'update', 'delete']
const CRUD_ACTIONS = ['viewAny', 'view', 'create', 'edit', 'update', 'delete']
const READ_ACTIONS = ['viewAny', 'view']

// Short labels for compact header
const ACTION_SHORT_LABEL = {
    viewAny: 'vAny',
    view: 'view',
    create: '+',
    edit: 'edit',
    update: 'upd',
    delete: 'del',
    forceDelete: 'fDel',
    import: 'imp',
    manage: 'mgmt',
    restore: 'res',
    view_sensitive: 'vSen',
}

// Help text for special/sensitive actions
const ACTION_HELP_KEY = {
    forceDelete: 'text.permission.action.forceDelete',
    restore: 'text.permission.action.restore',
    manage: 'text.permission.action.manage',
    import: 'text.permission.action.import',
    view_sensitive: 'text.permission.action.view_sensitive',
    delete: 'text.permission.action.delete',
    hub: 'text.permission.action.hub',
    create: 'text.permission.action.create',
    edit: 'text.permission.action.edit',
    update: 'text.permission.action.update',
    view: 'text.permission.action.view',
    viewAny: 'text.permission.action.viewAny',
}

// State
const moduleSearch = ref('')
const permissionSearch = ref('')
const resourceFilter = ref('')
const debouncedSearch = ref('')
const debouncedResourceFilter = ref('')
const selectedModule = ref('')
const modulePerms = ref([])
const isLoadingPerms = ref(false)
const requestToken = ref(0)
const selectedPermissionIds = ref(new Set())
const isSaving = ref(false)
const isBulkUpdating = ref(false)
const matrixScrollRef = ref(null)
const hasHorizontalScroll = ref(false)

// Local state for modules (fetched manually when drawer opens)
const modulesData = ref([])
const isLoadingModules = ref(false)

// Fetch modules manually
async function fetchModules() {
    isLoadingModules.value = true
    try {
        const res = await ajax.get('permissions/modules')
        modulesData.value = res?.data?.data || []
    } catch (err) {
        console.error('Failed to fetch permission modules:', err)
        modulesData.value = []
    } finally {
        isLoadingModules.value = false
    }
}

// Queries for role permissions
const { data: rolePermData } = useRolePermissions(toRef(props, 'roleId'))
const syncMutation = useSyncRolePermissions()

// Computed: Filtered modules
const filteredModules = computed(() => {
    const modules = modulesData.value || []
    if (!moduleSearch.value) return modules
    const term = moduleSearch.value.toLowerCase()
    return modules.filter((m) => m.module.toLowerCase().includes(term))
})

// Computed: Selected count
const selectedCount = computed(() => selectedPermissionIds.value.size)

// Computed: Module permission IDs (precomputed for perf)
const modulePermIds = computed(() => modulePerms.value.map((p) => p.id))

// Computed: Module selected count (efficient counting)
const moduleSelectedCount = computed(() => {
    let count = 0
    const selected = selectedPermissionIds.value
    for (const id of modulePermIds.value) {
        if (selected.has(id)) count++
    }
    return count
})

// Helper: Parse resource key from group
function parseResourceKey(group, module) {
    if (!group) return 'other'
    const lowerModule = module?.toLowerCase() || ''
    const lowerGroup = group.toLowerCase()

    if (lowerGroup.startsWith(lowerModule + '.')) {
        return group.substring(lowerModule.length + 1)
    }
    return group
}

const moduleLabel = (module) => {
    const key = `nav.a.${String(module).toLowerCase()}`
    return te?.(key) ? t(key) : module
}
const resourceLabel = (resource) => permissionResourceLabel(`${String(selectedModule.value).toLowerCase()}.${resource}`, t, te, resource)

// Computed: Group permissions by resource
const groupedResources = computed(() => {
    const map = {}
    for (const perm of modulePerms.value) {
        const resourceKey = parseResourceKey(perm.group, selectedModule.value)
        if (!map[resourceKey]) {
            map[resourceKey] = {
                resourceKey,
                perms: [],
                actionToId: new Map(),
                permIds: [],
            }
        }
        map[resourceKey].perms.push(perm)
        map[resourceKey].actionToId.set(perm.name, perm.id)
        map[resourceKey].permIds.push(perm.id)
    }
    return map
})

// Computed: Resource keys (sorted)
const resourceKeys = computed(() => {
    return Object.keys(groupedResources.value).sort()
})

// Computed: Filtered resource keys (by search AND resource filter)
const filteredResourceKeys = computed(() => {
    let keys = resourceKeys.value

    // Apply resource filter (client-side only)
    if (debouncedResourceFilter.value) {
        const term = debouncedResourceFilter.value.toLowerCase()
        keys = keys.filter((key) => key.toLowerCase().includes(term) || resourceLabel(key).toLowerCase().includes(term))
    }

    // Apply permission search filter
    if (debouncedSearch.value) {
        const term = debouncedSearch.value.toLowerCase()
        keys = keys.filter((key) => {
            if (key.toLowerCase().includes(term)) return true
            const resource = groupedResources.value[key]
            return resource?.perms.some(
                (p) => p.name?.toLowerCase().includes(term) || p.title?.toLowerCase().includes(term)
            )
        })
    }

    return keys
})

// Computed: Action columns (base + extra)
const actionColumns = computed(() => {
    const allActions = new Set()
    for (const perm of modulePerms.value) {
        if (perm.name) allActions.add(perm.name)
    }
    const extras = [...allActions].filter((a) => !BASE_ACTIONS.includes(a)).sort()
    return [...BASE_ACTIONS.filter((a) => allActions.has(a)), ...extras]
})

// Computed: Module-level check state (optimized)
const isModuleAllChecked = computed(() => {
    if (!modulePermIds.value.length) return false
    return moduleSelectedCount.value === modulePermIds.value.length
})

const isModuleIndeterminate = computed(() => {
    if (!modulePermIds.value.length) return false
    const count = moduleSelectedCount.value
    return count > 0 && count < modulePermIds.value.length
})

// Helper: Get short action label
function getActionShortLabel(action) {
    return ACTION_SHORT_LABEL[action] || action.slice(0, 4)
}

// Helper: Get resource selected count
function getResourceSelectedCount(resourceKey) {
    const resource = groupedResources.value[resourceKey]
    if (!resource) return 0
    let count = 0
    const selected = selectedPermissionIds.value
    for (const id of resource.permIds) {
        if (selected.has(id)) count++
    }
    return count
}

// Methods: Resource-level check state
function isResourceAllChecked(resourceKey) {
    const resource = groupedResources.value[resourceKey]
    if (!resource?.permIds.length) return false
    const selected = selectedPermissionIds.value
    for (const id of resource.permIds) {
        if (!selected.has(id)) return false
    }
    return true
}

function isResourceIndeterminate(resourceKey) {
    const resource = groupedResources.value[resourceKey]
    if (!resource?.permIds.length) return false
    let count = 0
    const selected = selectedPermissionIds.value
    for (const id of resource.permIds) {
        if (selected.has(id)) count++
    }
    return count > 0 && count < resource.permIds.length
}

// Methods: Get permission ID for action
function getPermissionId(resourceKey, action) {
    const resource = groupedResources.value[resourceKey]
    return resource?.actionToId.get(action) || null
}

// Helper: Bulk update with optimization
function updateSelectedIds(mutatorFn) {
    isBulkUpdating.value = true
    const next = new Set(selectedPermissionIds.value)
    mutatorFn(next)
    selectedPermissionIds.value = next
    requestAnimationFrame(() => {
        isBulkUpdating.value = false
    })
}

// Helper: Get resource perm IDs by actions
function getResourcePermIdsByActions(resourceKey, actionNames) {
    const resource = groupedResources.value[resourceKey]
    if (!resource) return []
    const ids = []
    for (const action of actionNames) {
        const id = resource.actionToId.get(action)
        if (id) ids.push(id)
    }
    return ids
}

// Method: Apply resource preset
function applyResourcePreset(resourceKey, preset) {
    const resource = groupedResources.value[resourceKey]
    if (!resource) return

    updateSelectedIds((set) => {
        // First clear all resource perms
        for (const id of resource.permIds) {
            set.delete(id)
        }

        // Then add based on preset
        if (preset === 'crud') {
            const ids = getResourcePermIdsByActions(resourceKey, CRUD_ACTIONS)
            for (const id of ids) set.add(id)
        } else if (preset === 'read') {
            const ids = getResourcePermIdsByActions(resourceKey, READ_ACTIONS)
            for (const id of ids) set.add(id)
        }
        // 'none' just clears, which we already did
    })
}

// Method: Fetch all module permissions (1k/page, loop if needed)
async function fetchModulePerms(module, search = '') {
    if (!module) {
        modulePerms.value = []
        return
    }

    const token = ++requestToken.value
    isLoadingPerms.value = true

    try {
        let all = []
        let page = 1
        let lastPage = 1

        do {
            const params = new URLSearchParams()
            params.append('module', module)
            if (search) params.append('search', search)
            params.append('page', page.toString())
            params.append('per_page', '1000')

            const res = await ajax.get(`permissions/catalog?${params.toString()}`)

            // Check for stale request
            if (token !== requestToken.value) return

            const data = res?.data?.data?.data || []
            const meta = res?.data?.data?.meta || {}

            all = all.concat(data)
            lastPage = meta.last_page || 1
            page++
        } while (page <= lastPage)

        // Check for stale request again
        if (token !== requestToken.value) return

        modulePerms.value = all
    } catch (err) {
        console.error('Failed to fetch permissions:', err)
        modulePerms.value = []
    } finally {
        if (token === requestToken.value) {
            isLoadingPerms.value = false
        }
    }
}

// Method: Select module
function selectModule(module) {
    selectedModule.value = module
    permissionSearch.value = ''
    debouncedSearch.value = ''
    resourceFilter.value = ''
    debouncedResourceFilter.value = ''
    fetchModulePerms(module)
}

// Method: Toggle individual permission
function togglePermission(id) {
    updateSelectedIds((set) => {
        if (set.has(id)) {
            set.delete(id)
        } else {
            set.add(id)
        }
    })
}

// Method: Toggle all in resource
function toggleResourceAll(resourceKey) {
    const resource = groupedResources.value[resourceKey]
    if (!resource) return

    const allSelected = isResourceAllChecked(resourceKey)

    updateSelectedIds((set) => {
        for (const id of resource.permIds) {
            if (allSelected) {
                set.delete(id)
            } else {
                set.add(id)
            }
        }
    })
}

// Method: Toggle all in module
function toggleModuleAll() {
    const allSelected = isModuleAllChecked.value

    updateSelectedIds((set) => {
        for (const id of modulePermIds.value) {
            if (allSelected) {
                set.delete(id)
            } else {
                set.add(id)
            }
        }
    })
}

// Method: Clear all in module
function clearModuleAll() {
    updateSelectedIds((set) => {
        for (const id of modulePermIds.value) {
            set.delete(id)
        }
    })
}

// Method: Handle matrix scroll for sticky shadow
function handleMatrixScroll(e) {
    hasHorizontalScroll.value = e.target.scrollLeft > 0
}

// Debounced handlers
const handleSearchDebounced = useDebounceFn(() => {
    debouncedSearch.value = permissionSearch.value
}, 300)

const handleResourceFilterDebounced = useDebounceFn(() => {
    debouncedResourceFilter.value = resourceFilter.value
}, 200)

// Watch: Initialize selected permissions from role data
watch(
    rolePermData,
    (data) => {
        if (data?.permission_ids) {
            selectedPermissionIds.value = new Set(data.permission_ids)
        }
    },
    { immediate: true }
)

// Watch: Reset when drawer opens
watch(
    () => props.visible,
    async (val) => {
        if (val && props.roleId) {
            selectedModule.value = ''
            modulePerms.value = []
            permissionSearch.value = ''
            debouncedSearch.value = ''
            resourceFilter.value = ''
            debouncedResourceFilter.value = ''
            hasHorizontalScroll.value = false

            // Fetch modules when drawer opens
            await fetchModules()

            // Auto-select first module if modules are loaded
            if (modulesData.value?.data?.length) {
                selectModule(modulesData.value?.data)
            }
        }
    }
)

// Watch: Reload on search change
watch(debouncedSearch, (search) => {
    if (selectedModule.value) {
        fetchModulePerms(selectedModule.value, search)
    }
})

// Method: Close drawer
function handleClose() {
    emit('update:visible', false)
}

// Method: Save permissions
async function handleSave() {
    if (!props.roleId) return

    isSaving.value = true
    try {
        const result = await syncMutation.mutateAsync({
            roleId: props.roleId,
            permissionIds: Array.from(selectedPermissionIds.value),
        })

        if (result?.status === 'success') {
            iAlert({ message: result.message || t('text.permission.editor.permissions_saved') })
            emit('saved')
            handleClose()
        } else {
            iAlert({
                message: result?.message || t('text.permission.editor.save_failed'),
                status: 'error',
            })
        }
    } catch (err) {
        iAlert(err, true)
    } finally {
        isSaving.value = false
    }
}
</script>

<style scoped>
/* CSS Variables */
.permission-matrix {
    --resource-col-w: 240px;
    --all-col-w: 64px;
    --action-col-w: 72px;
}

/* Grid layout */
.permission-matrix-header,
.permission-matrix-row {
    display: grid;
    grid-template-columns: var(--resource-col-w) var(--all-col-w) repeat(
            var(--action-cols),
            var(--action-col-w)
        );
    min-width: max-content;
    box-sizing: border-box;
}

/* Header */
.permission-matrix-header {
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
    position: sticky;
    top: 0;
    z-index: 30;
}

/* Row */
.permission-matrix-row {
    border-bottom: 1px solid #e2e8f0;
}

.permission-matrix-row:hover {
    background: #f1f5f9;
}

/* Resource cell (first sticky column) */
.cell-resource {
    padding: 6px 8px;
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: var(--resource-col-w);
    box-sizing: border-box;
}

.sticky-col-resource {
    position: sticky;
    left: 0;
    z-index: 10;
    background: white;
    border-right: 1px solid #e2e8f0;
}

.permission-matrix-header .sticky-col-resource {
    background: #f8fafc;
    z-index: 40;
}

.permission-matrix-row:hover .sticky-col-resource {
    background: #f1f5f9;
}

/* All cell (second sticky column) */
.cell-all {
    padding: 6px 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: var(--all-col-w);
    box-sizing: border-box;
}

.sticky-col-all {
    position: sticky;
    left: var(--resource-col-w);
    z-index: 10;
    background: white;
    border-right: 1px solid #e2e8f0;
}

.permission-matrix-header .sticky-col-all {
    background: #f8fafc;
    z-index: 40;
}

.permission-matrix-row:hover .sticky-col-all {
    background: #f1f5f9;
}

/* Shadow on sticky columns when scrolled */
.has-scroll .sticky-col-all {
    box-shadow: 8px 0 10px -10px rgba(0, 0, 0, 0.35);
}

/* Action cell */
.cell-action {
    padding: 6px 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
}

/* Preset buttons */
.preset-buttons {
    display: flex;
    gap: 2px;
    flex-shrink: 0;
}

.preset-btn {
    width: 20px;
    height: 20px;
    padding: 0;
    border: 1px solid #d1d5db;
    border-radius: 3px;
    background: #f9fafb;
    font-size: 10px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #374151;
    transition: all 0.15s;
}

.preset-btn:hover {
    background: #e5e7eb;
    border-color: #9ca3af;
}

.preset-btn-danger {
    color: #dc2626;
    border-color: #fca5a5;
}

.preset-btn-danger:hover {
    background: #fee2e2;
    border-color: #f87171;
}
</style>
