<script>
/** Highest resource schema version this admin build can render. Newer schemas show an update notice instead. */
export const SUPPORTED_SCHEMA_VERSION = 1
</script>

<script setup>
/**
 * Generic admin page rendered from a resource schema: breadcrumbs with create / reload actions, the resource list,
 * a detail drawer opened from a list row, a create drawer and an edit drawer (the schema's `form.update` context). The schema is fetched on mount via `getResourceSchema`.
 * The open detail drawer is addressed by `query.record=<uuid>` (validated, otherwise ignored; written with
 * `router.replace`), so a reload or a shared link reopens it and closing it leaves the list filters untouched.
 * A record without a uuid still opens, only that drawer is not in the address.
 * The saved views bar sits above the list (list key = the resource key); a view can be shared when the schema has a form
 * (the share ability is the form ability, enforced by the server).
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { getResourceSchema } from '@/libs/resource'
import ResourceDetail from './resource-detail.vue'
import ResourceForm from './resource-form.vue'
import ResourceList from './resource-list.vue'
import SavedViewsBar from '@/components/saved-views-bar.vue'
import { isUuid, listStateSchema, RECORD_QUERY_KEY } from '@/utils/resource/list-schema'
import { parseListState, withReturnTo } from '@/utils/list-state'

const props = defineProps({
    /** Resource path `<module>/<resource>`, e.g. `helpdesk/organizations`. */
    resource: { type: String, required: true },
    /** Page title: a translation key or plain text. */
    title: { type: String, default: '' },
    /** Optional subtitle: a translation key or plain text. */
    subtitle: { type: String, default: '' },
    /** Route name of a profile page (`params.uuid`): a row opens it, with `?from=` set to this list address, instead of the drawer. */
    detailRoute: { type: String, default: '' },
})
const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const schema = ref(null)
const loading = ref(true)
const failed = ref(false)
const createOpen = ref(false)
const editRecord = ref(null)
const localDetailKey = ref('')
/** Record key from the address: the first `record` value when it is a uuid, else ''. */
const queryRecord = computed(() => {
    const raw = route.query?.[RECORD_QUERY_KEY]
    const value = Array.isArray(raw) ? raw[0] : raw
    return isUuid(value) ? String(value).toLowerCase() : ''
})
/** Key of the open detail record: from the address, else a record that has no uuid (kept out of the address). */
const detailUuid = computed(() => queryRecord.value || localDetailKey.value)
const detailName = ref('')
/** Route-query schema of the list (page, search, filters) shared with the saved views bar. */
const stateSchema = computed(() => listStateSchema(schema.value))
/** Current list state read from the address, compared with each saved view to mark the active one. */
const listState = computed(() => parseListState(route.query || {}, stateSchema.value))
/** Whether the schema declares a create or update form, which is what lets the operator share a view. */
const canShareViews = computed(() => Boolean(schema.value?.form))
const listKey = ref(0)
const detailKey = ref(0)
let sequence = 0

/** Page text declared by the schema (`list.labels`): title, subtitle, create and reload; route props are the fallback. */
const labels = computed(() => schema.value?.list?.labels || {})
const pageTitle = computed(() => labels.value.title || props.title)
const pageSubtitle = computed(() => labels.value.subtitle || props.subtitle)
/** A schema that says the user lacks `manage` (`abilities.manage === false`) is read-only: no create or edit drawers. */
const readOnly = computed(() => schema.value?.abilities?.manage === false)
const createForm = computed(() => (readOnly.value ? null : schema.value?.form?.create || null))
const updateForm = computed(() => (readOnly.value ? null : schema.value?.form?.update || null))
const editOpen = computed({
    get: () => editRecord.value !== null,
    set: (open) => {
        if (!open) editRecord.value = null
    },
})
/** Create drawer text declared by the schema (`form.labels`): title, hint and submit button. */
const formLabels = computed(() => schema.value?.form?.labels || {})
const createTitle = computed(() => t(formLabels.value.title || labels.value.create || 'text.resource.create'))
/** Edit drawer title: the detail title for the record, else the page title. */
const editTitle = computed(() => detailTitle.value)
/** Detail drawer title (`detail.labels.title`, `{name}` = record name); the page title when the schema has none. */
const detailTitle = computed(() => {
    const key = schema.value?.detail?.labels?.title
    if (key) return t(key, { name: detailName.value })
    return pageTitle.value ? t(pageTitle.value) : ''
})
/** Parent link before the page title (`list.breadcrumbParent`). */
const breadcrumbParent = computed(() => schema.value?.list?.breadcrumbParent || null)
const outdated = computed(() => Number(schema.value?.schema_version ?? SUPPORTED_SCHEMA_VERSION) > SUPPORTED_SCHEMA_VERSION)
const ready = computed(() => Boolean(schema.value) && !outdated.value)
const detailOpen = computed({
    get: () => detailUuid.value !== '',
    set: (open) => {
        if (!open) closeDetail()
    },
})
const breadcrumbItems = computed(() => {
    const title = pageTitle.value ? t(pageTitle.value) : ''
    if (!breadcrumbParent.value) return [{ icon: 'view-dashboard-outline', title, text: title }]
    const parent = t(breadcrumbParent.value.label)

    const icon = breadcrumbParent.value.icon

    return [{ ...(icon ? { icon } : {}), title: parent, text: parent, name: breadcrumbParent.value.route }, { text: title }]
})

/** Fetch the schema for `resource`; a stale response (older request) is ignored. */
async function loadSchema() {
    sequence += 1
    const current = sequence
    const [module, name] = props.resource.split('/')
    loading.value = true
    failed.value = false
    try {
        const result = await getResourceSchema(module, name)
        if (current !== sequence) return
        schema.value = result
    } catch {
        if (current !== sequence) return
        failed.value = true
    } finally {
        if (current === sequence) loading.value = false
    }
}

/** Reload the list and, when open, the detail drawer. */
function reload() {
    listKey.value += 1
    detailKey.value += 1
}

/** Replace the `record` query key (other keys, the list state, stay), or remove it when `value` is ''. */
function setRecordQuery(value) {
    const query = { ...(route.query || {}) }
    if (value) query[RECORD_QUERY_KEY] = value
    else delete query[RECORD_QUERY_KEY]
    return router.replace({ query })
}

/** Close the detail drawer: drop the record from the address and the local key. */
function closeDetail() {
    localDetailKey.value = ''
    if (queryRecord.value || route.query?.[RECORD_QUERY_KEY] !== undefined) setRecordQuery('')
}

/** Open the detail drawer for a list row. */
function openRecord(record) {
    detailName.value = String(record.name ?? '')
    const key = String(record.uuid ?? record.id ?? '')
    if (props.detailRoute && isUuid(key)) {
        router.push(withReturnTo({ name: props.detailRoute, params: { uuid: key.toLowerCase() } }, route))
    } else if (isUuid(key)) {
        localDetailKey.value = ''
        setRecordQuery(key.toLowerCase())
    } else {
        localDetailKey.value = key
    }
}

/** Keep the drawer title in step with the record the detail view loaded. */
function onDetailLoaded(record) {
    if (record?.name) detailName.value = String(record.name)
}

/** Open the edit drawer for the record the detail view loaded. */
function openEdit(record) {
    if (updateForm.value) editRecord.value = record
}

/** Close the edit drawer, reload the list and the open detail record. */
function onUpdated() {
    editRecord.value = null
    reload()
}

/** Close the detail drawer and reload the list (after a delete, for instance). */
function onDetailClosed() {
    closeDetail()
    editRecord.value = null
    listKey.value += 1
}

/** Close the create drawer, refresh the list and open the new record when the server returned it. */
function onCreated(response) {
    createOpen.value = false
    listKey.value += 1
    const created = response?.data?.data ?? response?.data
    if (created?.uuid) openRecord(created)
}

onMounted(loadSchema)
</script>

<template>
    <div class="mx-auto max-w-7xl space-y-4">
        <breadcrumbs :items="breadcrumbItems">
            <i-button v-if="ready && createForm" size="sm" data-test="create" @click="createOpen = true">{{ t(labels.create || 'text.resource.create') }}</i-button>
            <i-button v-if="ready" size="sm" data-test="reload" @click="reload">{{ t(labels.reload || 'text.resource.reload') }}</i-button>
            <template v-if="pageSubtitle" #description>{{ t(pageSubtitle) }}</template>
        </breadcrumbs>

        <a-skeleton v-if="loading" active />
        <a-alert v-else-if="failed" type="error" show-icon :message="t('text.resource.load_failed')">
            <template #action>
                <i-button size="sm" data-test="retry" @click="loadSchema">{{ t('text.resource.retry') }}</i-button>
            </template>
        </a-alert>
        <a-alert v-else-if="outdated" type="warning" show-icon :message="t('text.resource.update_needed')" />
        <template v-else-if="ready">
            <saved-views-bar :list-key="resource" :schema="stateSchema" :state="listState" :can-share="canShareViews" />
            <i-box>
                <resource-list :key="listKey" :schema="schema" @open="openRecord" />
            </i-box>

            <a-drawer v-model:open="detailOpen" width="min(640px, 100vw)" :title="detailTitle">
                <resource-detail
                    v-if="detailUuid"
                    :key="`${detailUuid}-${detailKey}`"
                    :schema="schema"
                    :uuid="detailUuid"
                    @changed="listKey += 1"
                    @loaded="onDetailLoaded"
                    @edit="openEdit"
                    @close="onDetailClosed"
                />
            </a-drawer>

            <a-drawer v-if="updateForm" v-model:open="editOpen" width="min(480px, 100vw)" :title="editTitle" destroy-on-close>
                <resource-form
                    v-if="editRecord"
                    :fields="updateForm.fields"
                    :endpoint="updateForm.endpoint"
                    :record="editRecord"
                    :success-message="updateForm.success_message || ''"
                    :after-hook="updateForm.after || ''"
                    :required-label="formLabels.required || ''"
                    mode="drawer"
                    @saved="onUpdated"
                    @cancel="editOpen = false"
                />
            </a-drawer>
            <a-drawer v-if="createForm" v-model:open="createOpen" width="min(480px, 100vw)" :title="createTitle" destroy-on-close>
                <p v-if="formLabels.hint" class="mb-4 text-sm text-gray-600" data-test="create-hint">{{ t(formLabels.hint) }}</p>
                <resource-form
                    :fields="createForm.fields"
                    :endpoint="createForm.endpoint"
                    :success-message="createForm.success_message || ''"
                    :after-hook="createForm.after || ''"
                    :submit-label="formLabels.submit ? t(formLabels.submit) : ''"
                    :required-label="formLabels.required || ''"
                    mode="drawer"
                    @saved="onCreated"
                    @cancel="createOpen = false"
                />
            </a-drawer>
        </template>
    </div>
</template>
