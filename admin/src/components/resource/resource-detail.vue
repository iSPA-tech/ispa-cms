<script setup>
/**
 * Generic resource detail screen rendered from a resource schema's `detail` and `actions` parts: loads the record,
 * shows sections with display widgets, schema actions (confirm / inline form) and module widget tabs.
 * Actions honour the schema keys `danger` (confirm first, danger styling), `after` (client hook, run once after
 * success) and `refresh` (`record` reloads the record, `list` only tells the page, `close` asks the page to close the
 * drawer); bulk actions are left to the list. When the form has an `update` context an edit button asks the page to
 * open it. When the schema carries `detail.timeline` the notes and history timeline is the last tab. Emits `changed` after an action succeeded, `close` for `refresh: 'close'`, `edit(record)` and `loaded`.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import ajax from '@/ajax'
import { runActionHook, serverMessage } from '@/utils/resource/action-hooks'
import { evaluateCondition } from '@/utils/resource/conditions'
import { resolvePath, toAjaxPath } from '@/utils/resource/endpoints'
import { getPath, safeEntries } from '@/utils/resource/field-path'
import { customLabel, resolveOptions } from '@/utils/resource/custom-fields'
import RecordTimeline from '@/components/records/record-timeline.vue'
import ResourceForm from './resource-form.vue'
import { resolveWidget } from './widgets'

const props = defineProps({
    /** Full resource schema from the schema endpoint. */
    schema: { type: Object, required: true },
    /** UUID of the record to show. */
    uuid: { type: String, required: true },
})
const emit = defineEmits(['changed', 'loaded', 'edit', 'close'])
const { t, locale } = useI18n()
/** Active locale code; specs that mock `useI18n` without a locale fall back to Vietnamese. */
const activeLocale = () => String(locale?.value ?? locale ?? 'vi')

const record = ref(null)
const loading = ref(false)
const failed = ref(false)
const acting = ref(false)
const openForm = ref('')

let sequence = 0
let controller = null

const detail = computed(() => props.schema?.detail || {})
const label = (value) => (value ? t(value) : '')
/** A schema that says the user lacks `manage` (`abilities.manage === false`) is read-only: no edit button, no actions. */
const readOnly = computed(() => props.schema?.abilities?.manage === false)
const canManage = computed(() => !readOnly.value && (props.schema?.actions || []).length > 0)
/** The schema grants an update form (the server omits `form` for users without the ability). */
const canEdit = computed(() => !readOnly.value && Boolean(props.schema?.form?.update))

const sections = computed(() =>
    safeEntries(detail.value.sections).map((section) => ({
        key: section.field,
        label: customLabel(section, activeLocale()) ?? label(section.label),
        widget: resolveWidget(section.widget || section.type),
        field: {
            ...section,
            context: 'detail',
            // A custom date is a bare day: show it without a time part.
            ...(section.custom && section.type === 'date' ? { date_only: true } : {}),
            options: resolveOptions(section, activeLocale()).map((o) => ({ ...o, label: o.literal ? o.label : label(o.label) })),
        },
    })),
)
const tabs = computed(() => (detail.value.tabs || []).map((tab) => ({ ...tab, component: resolveWidget(tab.widget) })))
/** Record type of the timeline tab; the server sends it only when the user may view the history. */
const timelineType = computed(() => detail.value.timeline?.record_type || '')
const actions = computed(() =>
    record.value && !readOnly.value ? (props.schema?.actions || []).filter((a) => !a.bulk && (!a.visible || evaluateCondition(a.visible, record.value))) : [],
)

/** Abort any in-flight request and invalidate its result. */
function cancelInFlight() {
    sequence += 1
    controller?.abort()
    controller = null
}

/** Load the record; a response that is no longer the latest request is ignored. */
async function load() {
    cancelInFlight()
    const current = sequence
    controller = new AbortController()
    loading.value = true
    try {
        const path = toAjaxPath(resolvePath(detail.value.endpoint?.path, { uuid: props.uuid }))
        const res = await ajax.get(path, { signal: controller.signal, silent: true })
        if (current !== sequence) return
        record.value = res.data?.data ?? res.data
        failed.value = false
        emit('loaded', record.value)
    } catch {
        if (current !== sequence) return
        failed.value = true
    }
    loading.value = false
}

/** Reload the record and tell the parent that something changed. */
async function afterChange() {
    openForm.value = ''
    await load()
    emit('changed')
}

/**
 * Finish a successful action: run its client hook once (a hook failure only shows a message), then apply its
 * `refresh` target. The server change already happened, so nothing here can undo it.
 */
async function finish(action, response) {
    openForm.value = ''
    await runActionHook(action.after, { action, record: record.value, response })
    if (action.refresh === 'close') {
        emit('changed')
        emit('close')
    } else if (action.refresh === 'list') {
        emit('changed')
    } else {
        await afterChange()
    }
}

/** Run a field-less action against its endpoint. */
async function run(action) {
    if (acting.value) return
    acting.value = true
    try {
        const path = toAjaxPath(resolvePath(action.endpoint.path, record.value))
        const response = await ajax.request({ url: path, method: (action.endpoint.method || 'POST').toLowerCase(), data: action.payload, silent: true })
        message.success(t(action.success_message || 'text.resource.saved'))
        await finish(action, response)
    } catch (error) {
        message.error(serverMessage(error) || t('text.resource.save_failed'))
    } finally {
        acting.value = false
    }
}

/** Whether an action asks for confirmation: it says so, or it is destructive. */
const needsConfirm = (action) => Boolean(action.confirm || action.danger)

/** Button text of an action: `label_from.on` / `.off` chosen by the record's field, else `label`. */
const actionLabel = (action) => {
    const source = action.label_from
    return source && record.value ? t(record.value[source.field] ? source.on : source.off) : t(action.label)
}

/** Confirmation text of an action; a destructive action without its own text falls back to its label. */
const confirmText = (action) => (action.confirm ? t(action.confirm) : actionLabel(action))

/** Button variant of an action. */
const variantOf = (action) => (action.danger ? 'danger' : undefined)

/** Endpoint of an inline action form with the record's params already resolved. */
const formEndpoint = (action) => ({ ...action.endpoint, path: resolvePath(action.endpoint.path, record.value) })

/** Toggle the inline form of an action. */
const toggleForm = (name) => {
    openForm.value = openForm.value === name ? '' : name
}

watch(() => props.uuid, load)
onBeforeUnmount(cancelInFlight)

load()
</script>

<template>
    <div class="space-y-4">
        <a-skeleton v-if="loading && !record" active />
        <a-alert v-else-if="failed && !record" type="error" show-icon :message="t('text.resource.load_failed')">
            <template #action>
                <i-button size="small" @click="load">{{ t('text.resource.retry') }}</i-button>
            </template>
        </a-alert>
        <template v-else-if="record">
            <a-alert v-if="failed" type="warning" show-icon :message="t('text.resource.load_failed')">
                <template #action>
                    <i-button size="small" @click="load">{{ t('text.resource.reload') }}</i-button>
                </template>
            </a-alert>
            <dl class="grid gap-3 sm:grid-cols-2">
                <div v-for="s in sections" :key="s.key" class="section" :data-section="s.key">
                    <dt class="text-sm text-gray-500">{{ s.label }}</dt>
                    <dd><component :is="s.widget" :field="s.field" :record="record" :value="getPath(record, s.key)" /></dd>
                </div>
            </dl>
            <div v-if="actions.length || canEdit" class="flex flex-wrap gap-2">
                <i-button v-if="canEdit" class="action" data-test="edit" @click="emit('edit', record)">{{ t('action.update') }}</i-button>
                <template v-for="action in actions" :key="action.name">
                    <a-popconfirm
                        v-if="!action.fields?.length && needsConfirm(action)"
                        :title="confirmText(action)"
                        :ok-text="actionLabel(action)"
                        :cancel-text="t('text.resource.cancel')"
                        @confirm="run(action)"
                    >
                        <i-button class="action" :data-action="action.name" :variant="variantOf(action)" :disabled="acting">{{ actionLabel(action) }}</i-button>
                    </a-popconfirm>
                    <i-button
                        v-else-if="!action.fields?.length"
                        class="action"
                        :data-action="action.name"
                        :variant="variantOf(action)"
                        :disabled="acting"
                        @click="run(action)"
                    >
                        {{ actionLabel(action) }}
                    </i-button>
                    <i-button v-else class="action" :data-action="action.name" :variant="variantOf(action)" @click="toggleForm(action.name)">{{ actionLabel(action) }}</i-button>
                </template>
            </div>
            <template v-for="action in actions" :key="`form-${action.name}`">
                <resource-form
                    v-if="action.fields?.length && openForm === action.name"
                    mode="inline"
                    :fields="action.fields"
                    :endpoint="formEndpoint(action)"
                    :success-message="action.success_message || ''"
                    :submit-label="action.submitLabel ? t(action.submitLabel) : actionLabel(action)"
                    :required-label="schema.form?.labels?.required || ''"
                    @saved="(response) => finish(action, response)"
                    @cancel="openForm = ''"
                />
            </template>
            <a-tabs v-if="tabs.length || timelineType">
                <a-tab-pane v-for="tab in tabs" :key="tab.name" :tab="label(tab.label)">
                    <component :is="tab.component" :record="record" :can-manage="canManage" :reload="load" />
                </a-tab-pane>
                <a-tab-pane v-if="timelineType" key="timeline" :tab="t('text.timeline.title')">
                    <record-timeline :type="timelineType" :uuid="record.uuid || uuid" />
                </a-tab-pane>
            </a-tabs>
        </template>
    </div>
</template>
