<template>
    <section class="space-y-2 rounded-md border border-solid border-danger p-3" data-testid="cf-purge">
        <h4 class="m-0 text-sm font-semibold">{{ t('text.custom_fields.purge_title') }}</h4>
        <p class="m-0 text-xs text-ink-muted">{{ t('text.custom_fields.purge_intro') }}</p>
        <p v-if="usageFailed" class="m-0 text-xs text-danger" data-testid="cf-purge-usage-failed">{{ t('text.custom_fields.purge_usage_failed') }}</p>
        <p v-else-if="records === null" class="m-0 text-xs text-ink-muted" data-testid="cf-purge-loading">{{ t('text.custom_fields.form_preview_loading') }}</p>
        <p v-else class="m-0 text-sm" data-testid="cf-purge-count">
            {{ records > 0 ? t('text.custom_fields.purge_count', { count: records }) : t('text.custom_fields.purge_count_none') }}
        </p>
        <p v-if="!backupReady" class="m-0 text-xs text-ink-muted" data-testid="cf-purge-unavailable">
            {{ t('text.custom_fields.purge_unavailable') }}
        </p>
        <p v-else class="m-0 text-xs text-ink-muted">{{ t('text.custom_fields.purge_backup_note') }}</p>
        <div class="space-y-1">
            <label for="cf-purge-key" class="block text-sm font-medium">{{ t('text.custom_fields.purge_type_key', { key: field.key }) }}</label>
            <a-input id="cf-purge-key" v-model:value="typed" :disabled="!backupReady || loading" autocomplete="off" data-testid="cf-purge-input" />
        </div>
        <p v-if="error" class="m-0 text-xs text-danger" data-testid="cf-purge-error">{{ error }}</p>
        <i-button variant="danger" :disabled="!canRun" data-testid="cf-purge-button" @click="run">
            {{ t('text.custom_fields.purge_button') }}
        </i-button>
    </section>
</template>

<script setup>
/**
 * Delete the saved answers of one field for good. Shows how many
 * records hold an answer. The button stays disabled until the typed key equals the field key AND the automatic backup
 * before deletion is connected (`backupReady`; until then a plain note says so and nothing can be deleted). One request
 * at a time; `loading` is reset only on error, because success hands over to the parent through `purged`.
 */
import { computed, defineComponent, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchCustomFieldUsage, purgeCustomField } from '@/libs/custom-fields'

defineComponent({ name: 'CustomFieldPurge' })

const props = defineProps({
    target: { type: Object, required: true },
    field: { type: Object, required: true },
    backupReady: { type: Boolean, default: false },
})
const emit = defineEmits(['purged'])
const { t } = useI18n()

const typed = ref('')
const records = ref(null)
const usageFailed = ref(false)
const loading = ref(false)
const error = ref('')

const canRun = computed(() => props.backupReady && !loading.value && typed.value === props.field.key)

onMounted(async () => {
    try {
        records.value = Number((await fetchCustomFieldUsage(props.target, props.field.uuid)).data?.records) || 0
    } catch (e) {
        usageFailed.value = true
    }
})

/** Run the purge once. */
async function run() {
    if (loading.value || !canRun.value) return
    loading.value = true
    error.value = ''
    try {
        const body = await purgeCustomField(props.target, props.field.uuid, typed.value)
        emit('purged', Number(body?.data?.removed_values) || 0)
    } catch (e) {
        // A refused backup (422 backup_required) and a throttled try (429) are not typing mistakes: say what really failed.
        const reason = e?.response?.data?.reason
        if (e?.response?.status === 429) error.value = t('text.custom_fields.purge_too_many')
        else error.value = t(reason === 'backup_required' ? 'text.custom_fields.purge_backup_failed' : 'text.custom_fields.purge_failed')
        loading.value = false
    }
}
defineExpose({ run })
</script>
