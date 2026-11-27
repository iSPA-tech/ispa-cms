<template>
    <div class="space-y-1" data-testid="reviewer-picker">
        <a-select
            mode="multiple"
            class="w-full"
            :value="selected"
            :options="options"
            :filter-option="false"
            :loading="loading"
            :disabled="readOnly"
            :placeholder="t('text.workflow.field.assignees_placeholder')"
            :aria-label="label || t('text.workflow.field.assignees')"
            :not-found-content="loading ? t('text.workflow.field.assignees_loading') : t('text.workflow.field.assignees_none_found')"
            show-search
            data-testid="reviewer-picker-select"
            @search="onSearch"
            @focus="onFocus"
            @update:value="onChange"
        />
        <p v-if="failed" class="mb-0 break-words text-xs text-amber-600" role="alert" data-testid="reviewer-picker-failed">
            {{ t('text.workflow.field.assignees_load_failed') }}
        </p>
        <p v-if="lostRight.length" class="mb-0 break-words text-xs text-amber-600" role="alert" data-testid="reviewer-picker-cannot-review">
            {{ t('text.workflow.field.assignees_cannot_review', { names: lostRight.join(', ') }) }}
        </p>
        <p v-if="!failed && full" class="mb-0 break-words text-xs text-gray-500" data-testid="reviewer-picker-full">
            {{ t('text.workflow.field.assignees_full', { max }) }}
        </p>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { resolveReviewers, searchReviewers } from '@/services/workflowApi'

/**
 * Reviewers of a workflow step, picked by name. The value is a list of user uuids; a name is all the operator sees.
 */
defineOptions({ name: 'ReviewerPicker' })

const props = defineProps({
    modelValue: { type: null, default: () => [] },
    readOnly: { type: Boolean, default: false },
    max: { type: Number, default: 10 },
    label: { type: String, default: '' },
    // 'review': people who may approve (approval assignees); 'notice': every admin account (who is told by a notice).
    audience: { type: String, default: 'review' },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const selected = computed(() => (Array.isArray(props.modelValue) ? props.modelValue.filter((uuid) => typeof uuid === 'string' && uuid !== '') : []))
const full = computed(() => selected.value.length >= props.max)

// People learnt so far (from a search or from the saved uuids), so a chosen person always shows by name.
// `null` = the account no longer exists.
const known = ref({})
const found = ref([])
const loading = ref(false)
const failed = ref(false)
let loadedOnce = false
let searchTimer = null
let searchRun = 0

const remember = (rows) => {
    known.value = { ...known.value, ...Object.fromEntries(rows.map((row) => [row.uuid, row])) }
}

const search = async (keywords = '') => {
    const run = ++searchRun
    loading.value = true
    failed.value = false
    try {
        const rows = await searchReviewers(keywords, props.audience)
        if (run !== searchRun) return
        found.value = rows
        remember(rows)
        loadedOnce = true
    } catch (error) {
        if (run === searchRun) failed.value = true
    } finally {
        if (run === searchRun) loading.value = false
    }
}

const onFocus = () => {
    if (!loadedOnce && !loading.value) search('')
}
const onSearch = (keywords) => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => search(keywords), 300)
}

// Saved reviewers are stored as uuids only: their names are asked in one request so no uuid is ever shown.
const asked = new Set()
watch(
    selected,
    async (uuids) => {
        const missing = uuids.filter((uuid) => !(uuid in known.value) && !asked.has(uuid))
        if (!missing.length) return
        missing.forEach((uuid) => asked.add(uuid))
        try {
            const rows = await resolveReviewers(missing, props.audience)
            // An uuid the server does not return belongs to an account that is gone.
            known.value = { ...known.value, ...Object.fromEntries(missing.map((uuid) => [uuid, null])), ...Object.fromEntries(rows.map((row) => [row.uuid, row])) }
        } catch (error) {
            missing.forEach((uuid) => asked.delete(uuid))
            failed.value = true
        }
    },
    { immediate: true }
)

/** "Name - username": the username (or the email of an account without one) tells two people with one name apart. */
const personLabel = (row) => {
    const name = row?.name || t('text.workflow.field.assignees_unknown')
    return row?.detail && row.detail !== row?.name ? `${name} - ${row.detail}` : name
}
const labelOf = (uuid) => {
    if (!(uuid in known.value)) return t('text.workflow.field.assignees_loading')
    return known.value[uuid] ? personLabel(known.value[uuid]) : t('text.workflow.field.assignees_unknown')
}

/** Chosen people who may no longer approve: their approvals go to the people who may. */
const lostRight = computed(() => selected.value.filter((uuid) => known.value[uuid]?.can_review === false).map((uuid) => labelOf(uuid)))

const options = computed(() => {
    const rows = found.value.map((row) => ({ value: row.uuid, label: personLabel(row) }))
    selected.value.forEach((uuid) => {
        if (!rows.some((row) => row.value === uuid)) rows.push({ value: uuid, label: labelOf(uuid) })
    })
    // At the cap, nobody else can be added; the chosen ones stay removable.
    return rows.map((row) => ({ ...row, disabled: full.value && !selected.value.includes(row.value) }))
})

const onChange = (value) => {
    const next = [...new Set((Array.isArray(value) ? value : []).filter((uuid) => typeof uuid === 'string' && uuid !== ''))].slice(0, props.max)
    emit('update:modelValue', next)
}
</script>
