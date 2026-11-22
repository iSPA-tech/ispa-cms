<!--
  Card to set the data-scope nodes of one user and to read what the user can reach.
  Props:
    - userUuid: uuid of the user
  Emits `loaded` with [{ dimension, node }] after every successful load.
  The operator picks a dimension, adds nodes by name through the node picker and chooses how far each node
  reaches. "Save scope" replaces the user's assignments; afterwards the data (including the effective scope per
  scoped permission) is reloaded. Node keys and ids are never shown or typed.
-->
<template>
    <div class="i-box space-y-4" data-testid="user-data-scope-card">
        <h2 class="text-sm font-semibold uppercase text-slate-700">{{ t('text.data_scope.action') }}</h2>

        <div v-if="loadingData" class="py-4 text-center text-ink-muted" data-testid="scope-loading">
            {{ t('text.loading') }}
        </div>

        <div v-else-if="loadFailed" class="space-y-2 py-4 text-center" data-testid="scope-load-error">
            <div class="text-red-600">{{ t('text.data_scope.load_failed') }}</div>
            <a-button data-testid="scope-retry" @click="load">{{ t('text.data_scope.retry') }}</a-button>
        </div>

        <template v-else>
            <form class="space-y-4" @submit.prevent="submit">
                <div v-if="generalError" class="text-sm text-red-600" role="alert" data-testid="scope-general-error">
                    {{ generalError }}
                </div>

                <section class="space-y-3">
                    <h3 class="m-0 text-sm font-medium">{{ t('text.data_scope.user_assigned') }}</h3>
                    <div class="text-xs text-ink-muted">{{ t('text.data_scope.expansion_help') }}</div>

                    <div v-if="assignments.length === 0" class="text-sm text-ink-muted" data-testid="scope-no-nodes">
                        {{ t('text.data_scope.user_no_nodes') }}
                    </div>

                    <div v-for="dim in usedDimensions" :key="dim.name" class="space-y-2" data-testid="dimension-section">
                        <div class="text-sm font-medium">{{ scopeLabel(dim, t, locale) }}</div>
                        <ul class="m-0 list-none space-y-2 p-0">
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
                    </div>
                </section>

                <section v-if="dimensions.length" class="space-y-2" data-testid="scope-add">
                    <a-select
                        v-model:value="addDimension"
                        :aria-label="t('text.data_scope.choose_dimension')"
                        :disabled="loading"
                        :options="dimensionOptions"
                        :placeholder="t('text.data_scope.choose_dimension')"
                        class="w-full"
                        data-testid="scope-dimension"
                    />
                    <scope-node-picker v-if="addDimension" :key="addDimension" :dimension="addDimension" @select="addNode" />
                </section>

                <div class="flex justify-end">
                    <a-button :disabled="loading" :loading="loading" html-type="submit" type="primary" data-testid="scope-save">
                        {{ t('text.data_scope.save_scope') }}
                    </a-button>
                </div>
            </form>

            <section class="space-y-3" data-testid="scope-effective">
                <h3 class="m-0 text-sm font-medium">{{ t('text.data_scope.user_effective') }}</h3>
                <div v-if="effective.length === 0" class="text-sm text-ink-muted" data-testid="scope-no-effective">
                    {{ t('text.data_scope.user_no_effective') }}
                </div>
                <div
                    v-for="item in effective"
                    :key="item.permission_key"
                    class="space-y-1 rounded border border-slate-100 p-2"
                    data-testid="effective-row"
                >
                    <div class="text-sm font-medium break-words">{{ scopeLabel(item, t, locale) }}</div>
                    <scope-summary :summary="item.summary" />
                </div>
            </section>
        </template>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { iAlert } from '@/mixins'
import { getScopeDimensions, getUserDataScope, scopeLabel, updateUserDataScope } from '@/libs/data-scope'
import ScopeNodePicker from '@/components/data-scope/scope-node-picker.vue'
import ScopeSummary from '@/components/data-scope/scope-summary.vue'

defineOptions({ name: 'UserDataScopeCard' })

const props = defineProps({
    userUuid: { type: String, default: '' },
})

const emit = defineEmits(['loaded'])

const { t, te, locale } = useI18n()

const NOT_ALLOWED_REASONS = [
    'scope_escalation',
    'level_all_requires_all',
    'self_edit_forbidden',
    'held_role_forbidden',
]

const dimensions = ref([])
const assignments = ref([])
const effective = ref([])
const addDimension = ref(null)
const loadingData = ref(false)
const loadFailed = ref(false)
const loading = ref(false)
const nodeErrors = ref({})
const generalError = ref('')
let uidSeq = 0

const expansionOptions = computed(() => [
    { value: 'self', label: t('text.data_scope.expansion_self_choice') },
    { value: 'descendants', label: t('text.data_scope.expansion_descendants_choice') },
    { value: 'branch', label: t('text.data_scope.expansion_branch_choice') },
])

const dimensionOptions = computed(() => dimensions.value.map((d) => ({ value: d.name, label: scopeLabel(d, t, locale.value) })))

/** Dimensions that currently have at least one assignment, in a stable order. */
const usedDimensions = computed(() => {
    const used = new Set(assignments.value.map((a) => a.dimension))
    const known = new Set(dimensions.value.map((d) => d.name))
    return [
        ...dimensions.value.filter((d) => used.has(d.name)),
        ...[...used].filter((name) => !known.has(name)).map((name) => ({ name, label: name })),
    ]
})

const nodesOf = (dimension) => assignments.value.filter((a) => a.dimension === dimension)

/** Add a picked node to the chosen dimension once (default expansion: the item and those below). */
function addNode(node) {
    const dimension = addDimension.value
    if (!dimension || assignments.value.some((a) => a.dimension === dimension && a.node.key === node.key)) return
    assignments.value.push({ uid: ++uidSeq, dimension, node, expansion: 'descendants' })
}

function removeNode(uid) {
    assignments.value = assignments.value.filter((a) => a.uid !== uid)
}

function resetErrors() {
    nodeErrors.value = {}
    generalError.value = ''
}

/** Load dimensions and the user's assignments with the effective scope. */
async function load() {
    if (!props.userUuid) return
    loadingData.value = true
    loadFailed.value = false
    loading.value = false
    resetErrors()
    try {
        const [dims, scope] = await Promise.all([getScopeDimensions(), getUserDataScope(props.userUuid)])
        dimensions.value = Array.isArray(dims) ? dims : []
        if (!addDimension.value && dimensions.value.length === 1) addDimension.value = dimensions.value[0].name
        assignments.value = (scope?.assignments || []).map((a) => ({
            uid: ++uidSeq,
            dimension: a.dimension,
            node: a.node,
            expansion: a.expansion || 'descendants',
        }))
        effective.value = scope?.effective || []
        // Lets the host page resolve node names (for example in the history list) without a second request.
        emit('loaded', assignments.value.map((a) => ({ dimension: a.dimension, node: a.node })))
    } catch (_error) {
        loadFailed.value = true
    } finally {
        loadingData.value = false
    }
}

watch(() => props.userUuid, load, { immediate: true })

/** Show a 403 reason in plain words, with a generic fallback for an unknown code. */
function forbiddenText(code) {
    if (NOT_ALLOWED_REASONS.includes(code) && te(`text.data_scope.not_allowed_${code}`)) {
        return t(`text.data_scope.not_allowed_${code}`)
    }
    return t('text.data_scope.not_allowed')
}

/** Map a 422 `errors` object onto the node rows that were sent. */
function applyValidationErrors(errors, sentRows) {
    const nodeMap = {}
    const general = []
    for (const [field, messages] of Object.entries(errors || {})) {
        const list = Array.isArray(messages) ? messages : [String(messages)]
        const m = /^assignments\.(\d+)\./.exec(field)
        const row = m ? sentRows[Number(m[1])] : null
        if (row) {
            nodeMap[row.uid] = [...(nodeMap[row.uid] || []), ...list]
        } else {
            general.push(...list)
        }
    }
    nodeErrors.value = nodeMap
    generalError.value = general.join(' ')
}

/** Save the assignments (replace semantics); one request at a time, reload on success. */
async function submit() {
    if (loading.value) return
    loading.value = true
    resetErrors()

    const sentRows = [...assignments.value]
    const payload = {
        assignments: sentRows.map((a) => ({ dimension: a.dimension, node_key: a.node.key, expansion: a.expansion })),
    }

    try {
        await updateUserDataScope(props.userUuid, payload)
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
    // load() clears the guard once the fresh data is in.
    await load()
}
</script>
