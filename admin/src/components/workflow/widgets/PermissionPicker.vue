<template>
    <div class="space-y-1" data-testid="permission-picker">
        <a-select
            class="w-full"
            show-search
            option-filter-prop="label"
            :value="value || undefined"
            :options="options"
            :loading="loading"
            :disabled="readOnly"
            :allow-clear="!required"
            :placeholder="t('text.workflow.pick.permission_placeholder')"
            :aria-label="label"
            :not-found-content="loading ? t('text.workflow.pick.loading') : t('text.workflow.pick.none_found')"
            data-testid="permission-picker-select"
            @update:value="(next) => emit('update:modelValue', next ?? null)"
        />
        <p v-if="failed" class="mb-0 break-words text-xs text-amber-700" role="alert" data-testid="permission-picker-failed">
            {{ t('text.workflow.pick.permission_failed') }}
            <a-button type="link" size="small" class="!h-auto !p-0 !text-xs" @click="load">{{ t('text.workflow.pick.retry') }}</a-button>
        </p>
        <p v-else-if="sameAs" class="mb-0 break-words text-xs text-green-700" data-testid="permission-picker-reason">
            ✓ {{ t('text.workflow.pick.permission_same_as', { name: sameAs }) }}
        </p>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchPermissionOptions } from '@/services/workflowApi'
import { useStepData } from '@/components/workflow/inputs/useStepData.js'

/**
 * A permission picked from the site's list (who reviews, who is told), stored as its key. The operator sees the
 * permission's name grouped by module; a stored key the list does not have stays selected and readable.
 */
defineOptions({ name: 'PermissionPicker' })

const props = defineProps({
    modelValue: { type: null, default: '' },
    readOnly: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    label: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const stepData = useStepData()

const value = computed(() => (typeof props.modelValue === 'string' ? props.modelValue : ''))
const rows = ref([])
const loading = ref(false)
const failed = ref(false)

const load = async () => {
    loading.value = true
    failed.value = false
    try {
        rows.value = await fetchPermissionOptions()
    } catch (error) {
        failed.value = true
    } finally {
        loading.value = false
    }
}
onMounted(load)

const options = computed(() => {
    const groups = []
    rows.value.forEach((row) => {
        const title = row.module_label || row.module || ''
        let group = groups.find((item) => item.label === title)
        if (!group) groups.push((group = { label: title, options: [] }))
        group.options.push({ value: row.key, label: row.label || row.key })
    })
    // A saved permission stays readable while the list loads or after it left the list.
    if (value.value && !rows.value.some((row) => row.key === value.value)) {
        groups.unshift({ label: t('text.workflow.pick.permission_saved'), options: [{ value: value.value, label: value.value }] })
    }
    return groups
})

/** Another step of the workflow that names the same people (an approval): the reason the sample chose them. */
const sameAs = computed(() => {
    if (!value.value) return ''
    const other = stepData.nodes.value.find(
        (node) => String(node.id) !== String(stepData.currentId.value) && node.data?.step_type === 'approval' && node.data?.step_config?.reviewer_permission === value.value
    )
    return other ? String(other.data?.name || other.data?.step_code || '') : ''
})
</script>
