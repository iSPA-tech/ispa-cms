<template>
    <div class="max-w-5xl mx-auto space-y-4" data-testid="custom-fields-page">
        <section class="i-box space-y-3">
            <div class="space-y-1">
                <h3 class="m-0 text-sm font-semibold">{{ t('text.custom_fields.title') }}</h3>
                <p class="m-0 text-sm text-ink-muted">{{ t('text.custom_fields.intro') }}</p>
            </div>

            <a-skeleton v-if="resourcesLoading && !resources.length" active :paragraph="{ rows: 1 }" data-testid="cf-resources-loading" />
            <a-alert
                v-else-if="resourcesError"
                type="error"
                show-icon
                :message="t('text.custom_fields.load_failed')"
                data-testid="cf-resources-error"
            >
                <template #action>
                    <i-button size="sm" variant="default" data-testid="cf-resources-retry" @click="loadResources">
                        {{ t('text.custom_fields.retry') }}
                    </i-button>
                </template>
            </a-alert>
            <p
                v-else-if="!resources.length"
                class="m-0 rounded-md bg-surface-sunken px-4 py-6 text-center text-sm text-ink-muted"
                data-testid="cf-no-resources"
            >
                {{ t('text.custom_fields.no_resources') }}
            </p>
            <div v-else class="space-y-1">
                <label for="cf-resource" class="block text-sm font-medium">{{ t('text.custom_fields.pick_resource') }}</label>
                <a-select
                    id="cf-resource"
                    v-model:value="selectedKey"
                    class="w-full sm:max-w-sm"
                    :options="resourceOptions"
                    :placeholder="t('text.custom_fields.pick_placeholder')"
                    :disabled="busy"
                    data-testid="cf-resource-select"
                    @change="loadFields"
                />
            </div>
        </section>

        <section v-if="selected" class="i-box space-y-3" data-testid="cf-fields-box">
            <a-alert
                v-if="notice"
                type="success"
                show-icon
                closable
                :message="notice"
                data-testid="cf-notice"
                @close="notice = ''"
            />
            <a-alert
                v-if="actionError"
                type="error"
                show-icon
                closable
                :message="actionError"
                data-testid="cf-action-error"
                @close="actionError = ''"
            />

            <a-skeleton v-if="fieldsLoading && !fields.length" active :paragraph="{ rows: 4 }" data-testid="cf-fields-loading" />
            <a-alert
                v-else-if="fieldsError"
                type="error"
                show-icon
                :message="t('text.custom_fields.load_failed')"
                data-testid="cf-fields-error"
            >
                <template #action>
                    <i-button size="sm" variant="default" data-testid="cf-fields-retry" @click="loadFields">
                        {{ t('text.custom_fields.retry') }}
                    </i-button>
                </template>
            </a-alert>
            <template v-else>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="m-0 text-xs text-ink-muted" data-testid="cf-count">
                        {{ t('text.custom_fields.count', { count: fields.length, limit: limit }) }}
                    </p>
                    <i-button
                        v-if="canManage"
                        variant="primary"
                        :disabled="busy || atLimit"
                        data-testid="cf-add"
                        @click="openForm(null)"
                    >
                        {{ t('text.custom_fields.form_add') }}
                    </i-button>
                </div>
                <p v-if="canManage && atLimit" class="m-0 text-xs text-ink-muted" data-testid="cf-limit-hint">
                    {{ t('text.custom_fields.form_limit_reached') }}
                </p>
                <a-table
                    size="small"
                    row-key="uuid"
                    :columns="columns"
                    :data-source="fields"
                    :loading="fieldsLoading"
                    :pagination="false"
                    :scroll="{ x: 640 }"
                    :locale="{ emptyText: t('text.custom_fields.empty') }"
                    data-testid="cf-table"
                >
                    <template #bodyCell="{ column, record, index }">
                        <template v-if="column.key === 'label'">
                            <span class="font-medium break-words" :class="{ 'text-ink-muted': record.archived }">
                                {{ labelOf(record) }}
                            </span>
                            <div v-if="canManage" class="mt-2 flex flex-wrap items-center gap-1">
                                <i-button
                                    size="sm"
                                    variant="default"
                                    :disabled="busy"
                                    :aria-label="t('text.custom_fields.form_edit_label', { name: labelOf(record) })"
                                    :data-testid="`cf-edit-${record.uuid}`"
                                    @click="openForm(record)"
                                >
                                    {{ t('text.custom_fields.form_edit') }}
                                </i-button>
                                <i-button
                                    size="sm"
                                    variant="default"
                                    :disabled="busy || index === 0"
                                    :aria-label="t('text.custom_fields.move_up_label', { name: labelOf(record) })"
                                    :data-testid="`cf-up-${record.uuid}`"
                                    @click="move(index, -1)"
                                >
                                    {{ t('text.custom_fields.move_up') }}
                                </i-button>
                                <i-button
                                    size="sm"
                                    variant="default"
                                    :disabled="busy || index === fields.length - 1"
                                    :aria-label="t('text.custom_fields.move_down_label', { name: labelOf(record) })"
                                    :data-testid="`cf-down-${record.uuid}`"
                                    @click="move(index, 1)"
                                >
                                    {{ t('text.custom_fields.move_down') }}
                                </i-button>
                                <a-popconfirm
                                    v-if="!record.archived"
                                    :title="t('text.custom_fields.archive_confirm')"
                                    :ok-text="t('text.custom_fields.archive')"
                                    :cancel-text="t('text.custom_fields.cancel')"
                                    @confirm="archive(record)"
                                >
                                    <i-button size="sm" variant="warning" :disabled="busy" :data-testid="`cf-archive-${record.uuid}`">
                                        {{ t('text.custom_fields.archive') }}
                                    </i-button>
                                </a-popconfirm>
                                <a-popconfirm
                                    v-else
                                    :title="t('text.custom_fields.restore_confirm')"
                                    :ok-text="t('text.custom_fields.restore')"
                                    :cancel-text="t('text.custom_fields.cancel')"
                                    @confirm="restore(record)"
                                >
                                    <i-button size="sm" variant="info" :disabled="busy" :data-testid="`cf-restore-${record.uuid}`">
                                        {{ t('text.custom_fields.restore') }}
                                    </i-button>
                                </a-popconfirm>
                            </div>
                        </template>
                        <template v-else-if="column.key === 'type'">{{ typeName(record.type) }}</template>
                        <template v-else-if="['is_required', 'show_in_list', 'is_filterable', 'is_searchable'].includes(column.key)">
                            {{ flagOf(record, column.key) ? t('text.custom_fields.yes') : t('text.custom_fields.no') }}
                        </template>
                        <template v-else-if="column.key === 'status'">
                            <a-tag
                                v-if="record.archived"
                                color="default"
                                class="m-0"
                                :data-testid="`cf-archived-${record.uuid}`"
                            >
                                {{ t('text.custom_fields.archived_badge') }}
                            </a-tag>
                            <a-tag v-else color="green" class="m-0">{{ t('text.custom_fields.active_badge') }}</a-tag>
                        </template>
                    </template>
                </a-table>
            </template>
        </section>

        <custom-field-form
            v-if="selected"
            :open="formOpen"
            :target="selected"
            :field="editing"
            :can-purge="canManage"
            backup-ready
            @close="formOpen = false"
            @saved="onSaved"
            @purged="onPurged"
        />
    </div>
</template>

<script setup>
/**
 * "Custom fields" (Settings > Data). The operator picks a resource by its name, sees its fields in display order
 * (archived ones marked), moves a field up or down and archives or restores it. Reorder sends the whole uuid list once.
 * Move, archive and restore need `custom_field.manage` (the API reports it as `meta.can_manage`; the controls are not
 * rendered without it). Add and edit open the CustomFieldForm drawer. No timers or listeners: nothing to clean up.
 */
import { computed, defineComponent, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import CustomFieldForm from '@/components/custom-fields/custom-field-form.vue'
import {
    archiveCustomField,
    fetchCustomFieldResources,
    fetchCustomFields,
    fieldLabel,
    movedOrder,
    reorderCustomFields,
    restoreCustomField,
} from '@/libs/custom-fields'

defineComponent({ name: 'SettingCustomFields' })

const { t, locale } = useI18n()

const resources = ref([])
const resourcesLoading = ref(false)
const resourcesError = ref(false)
const selectedKey = ref(undefined)

const fields = ref([])
const fieldsLoading = ref(false)
const fieldsError = ref(false)
const limit = ref(0)
const canManage = ref(false)

const formOpen = ref(false)
const editing = ref(null)
const atLimit = computed(() => limit.value > 0 && fields.value.length >= limit.value)

const busy = ref(false)
const notice = ref('')
const actionError = ref('')

const resourceOptions = computed(() => resources.value.map((r) => ({ value: r.key, label: r.label })))
const selected = computed(() => resources.value.find((r) => r.key === selectedKey.value) || null)

const columns = computed(() => [
    { key: 'label', title: t('text.custom_fields.col_name') },
    { key: 'type', title: t('text.custom_fields.col_type') },
    { key: 'is_required', title: t('text.custom_fields.col_required') },
    { key: 'show_in_list', title: t('text.custom_fields.col_list') },
    { key: 'is_filterable', title: t('text.custom_fields.col_filter') },
    { key: 'is_searchable', title: t('text.custom_fields.col_search') },
    { key: 'status', title: t('text.custom_fields.col_status') },
])

const labelOf = (field) => fieldLabel(field, locale.value)
const typeName = (type) => (type ? t(`text.custom_fields.type.${type}`) : '')

/** Apply a field-list body to the screen. */
const apply = (body) => {
    fields.value = Array.isArray(body?.data) ? body.data : []
    limit.value = Number(body?.meta?.limit) || 0
    canManage.value = Boolean(body?.meta?.can_manage)
}

async function loadResources() {
    if (resourcesLoading.value) return
    resourcesLoading.value = true
    resourcesError.value = false
    try {
        const body = await fetchCustomFieldResources()
        resources.value = Array.isArray(body.data) ? body.data : []
        canManage.value = Boolean(body.meta?.can_manage)
        // One resource: nothing to pick, show it at once.
        if (resources.value.length === 1 && !selectedKey.value) {
            selectedKey.value = resources.value[0].key
            await loadFields()
        }
    } catch (e) {
        resourcesError.value = true
    } finally {
        resourcesLoading.value = false
    }
}

async function loadFields() {
    if (!selected.value || fieldsLoading.value) return
    fieldsLoading.value = true
    fieldsError.value = false
    notice.value = ''
    actionError.value = ''
    try {
        apply(await fetchCustomFields(selected.value))
    } catch (e) {
        fieldsError.value = true
        fields.value = []
    } finally {
        fieldsLoading.value = false
    }
}

/** Run a write once; a second click while it runs is ignored. */
async function run(task, doneKey) {
    if (busy.value || !selected.value) return
    busy.value = true
    notice.value = ''
    actionError.value = ''
    try {
        const body = await task(selected.value)
        notice.value = t(doneKey)
        if (Array.isArray(body?.data)) apply(body)
        else await refresh()
    } catch (e) {
        actionError.value = t('text.custom_fields.action_failed')
    } finally {
        busy.value = false
    }
}

/** Reload the list after a write whose answer is a single field. */
async function refresh() {
    try {
        apply(await fetchCustomFields(selected.value))
    } catch (e) {
        fieldsError.value = true
    }
}

/** Open the drawer to add (null) or edit a field. */
const openForm = (field) => {
    editing.value = field
    formOpen.value = true
}

/** A save worked: close the drawer, say so and show the refreshed list. */
async function onSaved(_field, wasEdit) {
    formOpen.value = false
    notice.value = t(wasEdit ? 'text.custom_fields.form_saved' : 'text.custom_fields.form_created')
    actionError.value = ''
    await refresh()
}

/** Answers were deleted for good: close the drawer and show the refreshed list. */
async function onPurged(count) {
    formOpen.value = false
    notice.value = t('text.custom_fields.purge_done', { count })
    actionError.value = ''
    await refresh()
}

const move = (index, step) => {
    const uuids = movedOrder(fields.value, index, step)
    if (uuids) run((target) => reorderCustomFields(target, uuids), 'text.custom_fields.reordered')
}
/** Types the list filters without the operator flag (server side CustomFieldsStage): the column must say so. */
const TYPE_FILTERED = ['select', 'multiselect', 'boolean', 'integer', 'date']

/**
 * Value of one yes/no column for a field. "Filterable" is also true for the types the list filters by themselves, so the
 * column agrees with what the operator sees on the list screen.
 */
const flagOf = (record, key) => (key === 'is_filterable' ? Boolean(record.is_filterable) || TYPE_FILTERED.includes(record.type) : Boolean(record[key]))

const archive = (field) => run((target) => archiveCustomField(target, field.uuid), 'text.custom_fields.archived_done')
const restore = (field) => run((target) => restoreCustomField(target, field.uuid), 'text.custom_fields.restored_done')

onMounted(loadResources)
defineExpose({ loadResources, loadFields })
</script>
