<!--
  Drawer to set the data scope of one role.
  Props:
    - visible (v-model:visible): drawer open state
    - roleId: id of the role
    - roleName: role name shown in the title
  Emits:
    - update:visible
    - saved after a successful save (the drawer closes)
  Per scoped permission the operator picks a level (all data, own data, or a hierarchy dimension). For every
  dimension in use the role's nodes are listed, added by name through the node picker and given an expansion.
  Node keys and ids are never shown or typed.
-->
<template>
    <a-drawer
        :open="visible"
        :title="t('text.data_scope.title', { role: roleName })"
        placement="right"
        width="min(560px, 100vw)"
        @close="handleClose"
    >
        <div v-if="loadingData" class="py-6 text-center text-ink-muted" data-testid="scope-loading">
            {{ t('text.loading') }}
        </div>

        <div v-else-if="loadFailed" class="space-y-2 py-6 text-center" data-testid="scope-load-error">
            <div class="text-red-600">{{ t('text.data_scope.load_failed') }}</div>
            <a-button data-testid="scope-retry" @click="load">{{ t('text.data_scope.retry') }}</a-button>
        </div>

        <div v-else-if="grants.length === 0" class="py-6 text-center text-ink-muted" data-testid="scope-empty">
            {{ t('text.data_scope.empty') }}
        </div>

        <form v-else class="space-y-6" @submit.prevent="submit">
            <div v-if="generalError" class="text-sm text-red-600" role="alert" data-testid="scope-general-error">
                {{ generalError }}
            </div>

            <section class="space-y-4">
                <div
                    v-for="(grant, index) in grants"
                    :key="grant.permission_key"
                    class="space-y-1"
                    data-testid="grant-row"
                >
                    <div class="text-sm font-medium break-words">
                        {{ t('text.data_scope.permission') }}: {{ scopeLabel(grant, t, locale) }}
                    </div>
                    <a-select
                        v-model:value="grant.level"
                        :aria-label="t('text.data_scope.level')"
                        :disabled="loading"
                        :options="levelOptions(grant)"
                        class="w-full"
                        data-testid="grant-level"
                    />
                    <div
                        v-for="msg in grantErrors[index] || []"
                        :key="msg"
                        class="text-xs text-red-600"
                        data-testid="grant-error"
                    >
                        {{ msg }}
                    </div>
                </div>
            </section>

            <section v-for="dim in usedDimensions" :key="dim.name" class="space-y-3" data-testid="dimension-section">
                <div class="text-sm font-medium">{{ scopeLabel(dim, t, locale) }}</div>
                <div class="text-xs text-ink-muted">{{ t('text.data_scope.expansion_help') }}</div>

                <ul class="m-0 list-none space-y-3 p-0">
                    <li
                        v-for="row in nodesOf(dim.name)"
                        :key="row.uid"
                        class="space-y-1 rounded border border-slate-200 p-2"
                        data-testid="node-row"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="break-words text-sm">{{ row.node.label }}</div>
                                <div v-if="row.node.trail && row.node.trail.length > 1" class="text-xs text-ink-faint">
                                    {{ row.node.trail.join(' › ') }}
                                </div>
                            </div>
                            <a-button
                                :disabled="loading"
                                size="small"
                                type="link"
                                data-testid="node-remove"
                                @click="removeNode(row.uid)"
                            >
                                {{ t('text.data_scope.remove_node') }}
                            </a-button>
                        </div>
                        <a-radio-group
                            v-model:value="row.expansion"
                            :disabled="loading"
                            :options="expansionOptions"
                            data-testid="node-expansion"
                        />
                        <div
                            v-for="msg in nodeErrors[row.uid] || []"
                            :key="msg"
                            class="text-xs text-red-600"
                            data-testid="node-error"
                        >
                            {{ msg }}
                        </div>
                    </li>
                </ul>

                <scope-node-picker :dimension="dim.name" @select="(node) => addNode(dim.name, node)" />
            </section>

            <div class="flex items-center justify-end gap-3">
                <a-button :disabled="loading" @click="handleClose">{{ t('nav.b.cancel') }}</a-button>
                <a-button :disabled="loading" :loading="loading" html-type="submit" type="primary" data-testid="scope-save">
                    {{ t('text.data_scope.save') }}
                </a-button>
            </div>
        </form>
    </a-drawer>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { iAlert } from '@/mixins'
import { getRoleDataScope, getScopeDimensions, scopeLabel, updateRoleDataScope } from '@/libs/data-scope'
import ScopeNodePicker from '@/components/data-scope/scope-node-picker.vue'

defineOptions({ name: 'RoleDataScopeDrawer' })

const props = defineProps({
    visible: { type: Boolean, default: false },
    roleId: { type: [Number, String], default: null },
    roleName: { type: String, default: '' },
})
const emit = defineEmits(['update:visible', 'saved'])

const { t, te, locale } = useI18n()

const NOT_ALLOWED_REASONS = [
    'scope_escalation',
    'level_all_requires_all',
    'self_edit_forbidden',
    'held_role_forbidden',
]

const dimensions = ref([])
const grants = ref([])
const assignments = ref([])
const loadingData = ref(false)
const loadFailed = ref(false)
const loading = ref(false)
const grantErrors = ref({})
const nodeErrors = ref({})
const generalError = ref('')
let uidSeq = 0

const expansionOptions = computed(() => [
    { value: 'self', label: t('text.data_scope.expansion_self_choice') },
    { value: 'descendants', label: t('text.data_scope.expansion_descendants_choice') },
    { value: 'branch', label: t('text.data_scope.expansion_branch_choice') },
])

const dimensionLabel = (name) => scopeLabel(dimensions.value.find((d) => d.name === name) || { name }, t, locale.value)

/** Options of one permission, limited to its allowed levels. */
function levelOptions(grant) {
    return (grant.allowed_levels || []).map((level) => {
        if (level === 'all') return { value: level, label: t('text.data_scope.level_all') }
        if (level === 'own') return { value: level, label: t('text.data_scope.level_own') }
        return { value: level, label: t('text.data_scope.level_dimension', { dimension: dimensionLabel(level) }) }
    })
}

/** Dimensions at least one grant currently uses, in a stable order. */
const usedDimensions = computed(() => {
    const used = new Set(grants.value.map((g) => g.level).filter((l) => l !== 'all' && l !== 'own'))
    const known = new Set(dimensions.value.map((d) => d.name))
    return [
        ...dimensions.value.filter((d) => used.has(d.name)),
        ...[...used].filter((name) => !known.has(name)).map((name) => ({ name, label: name })),
    ]
})

const nodesOf = (dimension) => assignments.value.filter((a) => a.dimension === dimension)

/** Add a picked node to a dimension once (default expansion: the item and those below). */
function addNode(dimension, node) {
    if (assignments.value.some((a) => a.dimension === dimension && a.node.key === node.key)) return
    assignments.value.push({ uid: ++uidSeq, dimension, node, expansion: 'descendants' })
}

function removeNode(uid) {
    assignments.value = assignments.value.filter((a) => a.uid !== uid)
}

function resetErrors() {
    grantErrors.value = {}
    nodeErrors.value = {}
    generalError.value = ''
}

/** Load dimensions and the role's scope. */
async function load() {
    if (props.roleId === null || props.roleId === undefined) return
    loadingData.value = true
    loadFailed.value = false
    loading.value = false
    resetErrors()
    try {
        const [dims, scope] = await Promise.all([getScopeDimensions(), getRoleDataScope(props.roleId)])
        dimensions.value = Array.isArray(dims) ? dims : []
        grants.value = (scope?.grants || []).map((g) => ({ ...g }))
        assignments.value = (scope?.assignments || []).map((a) => ({
            uid: ++uidSeq,
            dimension: a.dimension,
            node: a.node,
            expansion: a.expansion || 'descendants',
        }))
    } catch (_error) {
        loadFailed.value = true
    } finally {
        loadingData.value = false
    }
}

watch(
    () => props.visible,
    (open) => {
        if (open) load()
    },
    { immediate: true }
)

function handleClose() {
    if (loading.value) return
    emit('update:visible', false)
}

/** Show a 403 reason in plain words, with a generic fallback for an unknown code. */
function forbiddenText(code) {
    if (NOT_ALLOWED_REASONS.includes(code) && te(`text.data_scope.not_allowed_${code}`)) {
        return t(`text.data_scope.not_allowed_${code}`)
    }
    return t('text.data_scope.not_allowed')
}

/** Map a 422 `errors` object onto the grant and node rows that were sent. */
function applyValidationErrors(errors, sentRows) {
    const grantMap = {}
    const nodeMap = {}
    const general = []
    for (const [field, messages] of Object.entries(errors || {})) {
        const list = Array.isArray(messages) ? messages : [String(messages)]
        let m = /^grants\.(\d+)\./.exec(field)
        if (m) {
            grantMap[m[1]] = [...(grantMap[m[1]] || []), ...list]
            continue
        }
        m = /^assignments\.(\d+)\./.exec(field)
        const row = m ? sentRows[Number(m[1])] : null
        if (row) {
            nodeMap[row.uid] = [...(nodeMap[row.uid] || []), ...list]
            continue
        }
        general.push(...list)
    }
    grantErrors.value = grantMap
    nodeErrors.value = nodeMap
    generalError.value = general.join(' ')
}

/** Save the role scope (replace semantics); one request at a time. */
async function submit() {
    if (loading.value) return
    loading.value = true
    resetErrors()

    const used = new Set(usedDimensions.value.map((d) => d.name))
    const sentRows = assignments.value.filter((a) => used.has(a.dimension))
    const payload = {
        grants: grants.value.map((g) => ({ permission_key: g.permission_key, level: g.level })),
        assignments: sentRows.map((a) => ({ dimension: a.dimension, node_key: a.node.key, expansion: a.expansion })),
    }

    try {
        await updateRoleDataScope(props.roleId, payload)
    } catch (error) {
        const status = error?.response?.status
        const body = error?.response?.data || {}
        if (status === 422) {
            applyValidationErrors(body.errors, sentRows)
        } else if (status === 403) {
            generalError.value = forbiddenText(body.error_code)
        } else {
            generalError.value = t('text.data_scope.save_failed')
        }
        loading.value = false
        return
    }

    iAlert({ message: t('text.data_scope.saved') })
    emit('saved')
    // The guard stays set until the drawer is opened again (load() clears it).
    emit('update:visible', false)
}
</script>
