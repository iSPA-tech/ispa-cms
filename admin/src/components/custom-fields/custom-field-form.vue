<template>
    <a-drawer
        :open="open"
        width="min(560px, 100vw)"
        :title="isEdit ? t('text.custom_fields.form_edit_title') : t('text.custom_fields.form_add_title')"
        data-testid="cf-form-drawer"
        @close="close"
    >
        <form class="space-y-4" novalidate data-testid="cf-form" @submit.prevent="submit">
            <a-alert
                v-if="saveError"
                type="error"
                show-icon
                :message="saveError"
                data-testid="cf-form-error"
            />

            <nav class="flex flex-wrap gap-1" :aria-label="t('text.custom_fields.form_steps')">
                <i-button
                    v-for="(name, index) in stepNames"
                    :key="name"
                    size="sm"
                    :variant="step === index + 1 ? 'primary' : 'default'"
                    :aria-current="step === index + 1 ? 'step' : undefined"
                    :data-testid="`cf-step-${index + 1}`"
                    @click="goTo(index + 1)"
                >
                    {{ index + 1 }}. {{ t(`text.custom_fields.form_step_${name}`) }}
                </i-button>
            </nav>

            <!-- Step 1: kind of answer -->
            <section v-show="step === 1" class="space-y-2" data-testid="cf-step-type">
                <p class="m-0 text-sm font-medium">{{ t('text.custom_fields.form_pick_type') }}</p>
                <p v-if="typeLocked" class="m-0 text-xs text-ink-muted" data-testid="cf-type-locked">
                    {{ t('text.custom_fields.form_type_locked') }}
                </p>
                <div class="grid gap-2 sm:grid-cols-2" role="radiogroup" :aria-label="t('text.custom_fields.form_pick_type')">
                    <button
                        v-for="kind in FIELD_TYPES"
                        :key="kind"
                        type="button"
                        role="radio"
                        :aria-checked="form.type === kind"
                        :disabled="typeLocked && form.type !== kind"
                        class="rounded-md border border-solid px-3 py-2 text-left text-sm"
                        :class="form.type === kind ? 'border-brand bg-surface-sunken' : 'border-line bg-surface'"
                        :data-testid="`cf-type-${kind}`"
                        @click="pickType(kind)"
                    >
                        <span class="block font-medium">{{ t(`text.custom_fields.type.${kind}`) }}</span>
                        <span class="block text-xs text-ink-muted">{{ t(`text.custom_fields.type_${kind}_example`) }}</span>
                    </button>
                </div>
                <p v-if="errors.type" class="m-0 text-xs text-danger" data-testid="cf-err-type">{{ t('text.custom_fields.form_err_type') }}</p>
                <p v-if="errors.typeLocked" class="m-0 text-xs text-danger" data-testid="cf-err-type-locked">{{ t('text.custom_fields.form_type_locked') }}</p>
            </section>

            <!-- Step 2: details -->
            <section v-show="step === 2" class="space-y-3" data-testid="cf-step-details">
                <div class="space-y-1">
                    <label for="cf-label-vi" class="block text-sm font-medium">{{ t('text.custom_fields.form_label_vi') }}</label>
                    <a-input id="cf-label-vi" v-model:value="form.labelVi" :maxlength="120" data-testid="cf-label-vi" />
                </div>
                <div class="space-y-1">
                    <label for="cf-label-en" class="block text-sm font-medium">{{ t('text.custom_fields.form_label_en') }}</label>
                    <a-input id="cf-label-en" v-model:value="form.labelEn" :maxlength="120" data-testid="cf-label-en" />
                    <p v-if="isEdit" class="m-0 text-xs text-ink-muted" data-testid="cf-key-note">
                        {{ t('text.custom_fields.form_key_note', { key: field.key }) }}
                    </p>
                </div>
                <p v-if="errors.labels" class="m-0 text-xs text-danger" data-testid="cf-err-labels">{{ t('text.custom_fields.form_err_labels') }}</p>
                <p v-if="errors.key" class="m-0 text-xs text-danger" data-testid="cf-err-key">{{ t('text.custom_fields.form_err_key') }}</p>
                <p v-if="errors.duplicate" class="m-0 text-xs text-danger" data-testid="cf-err-duplicate">{{ t('text.custom_fields.form_err_duplicate') }}</p>

                <div class="space-y-1">
                    <label for="cf-help-vi" class="block text-sm font-medium">{{ t('text.custom_fields.form_help_vi') }}</label>
                    <a-input id="cf-help-vi" v-model:value="form.helpVi" :maxlength="200" data-testid="cf-help-vi" />
                </div>
                <div class="space-y-1">
                    <label for="cf-help-en" class="block text-sm font-medium">{{ t('text.custom_fields.form_help_en') }}</label>
                    <a-input id="cf-help-en" v-model:value="form.helpEn" :maxlength="200" data-testid="cf-help-en" />
                </div>
                <p v-if="errors.help" class="m-0 text-xs text-danger" data-testid="cf-err-help">{{ t('text.custom_fields.form_err_help') }}</p>

                <div class="flex items-center gap-2">
                    <a-switch v-model:checked="form.isRequired" data-testid="cf-required" aria-labelledby="cf-required-label" />
                    <span id="cf-required-label" class="text-sm">{{ t('text.custom_fields.form_required') }}</span>
                </div>

                <!-- Limits that fit the type -->
                <div v-if="form.type === 'text' || form.type === 'textarea'" class="grid gap-3 sm:grid-cols-2" data-testid="cf-controls-length">
                    <div class="space-y-1">
                        <label for="cf-min" class="block text-sm font-medium">{{ t('text.custom_fields.form_text_min') }}</label>
                        <a-input-number id="cf-min" v-model:value="form.min" :min="0" :max="form.type === 'text' ? 500 : 20000" class="w-full" data-testid="cf-min" />
                    </div>
                    <div class="space-y-1">
                        <label for="cf-max" class="block text-sm font-medium">{{ t('text.custom_fields.form_text_max') }}</label>
                        <a-input-number id="cf-max" v-model:value="form.max" :min="1" :max="form.type === 'text' ? 500 : 20000" class="w-full" data-testid="cf-max" />
                    </div>
                </div>
                <div v-if="form.type === 'text'" class="space-y-2" data-testid="cf-controls-format">
                    <div class="space-y-1">
                        <label for="cf-format" class="block text-sm font-medium">{{ t('text.custom_fields.form_format') }}</label>
                        <a-select id="cf-format" v-model:value="form.format" :options="formatOptions" class="w-full sm:max-w-xs" data-testid="cf-format" />
                    </div>
                    <details class="text-sm" :open="Boolean(form.pattern)" data-testid="cf-advanced">
                        <summary class="cursor-pointer">{{ t('text.custom_fields.form_advanced') }}</summary>
                        <div class="mt-2 space-y-1">
                            <label for="cf-pattern" class="block text-sm font-medium">{{ t('text.custom_fields.form_pattern') }}</label>
                            <a-input id="cf-pattern" v-model:value="form.pattern" :maxlength="200" data-testid="cf-pattern" />
                            <p class="m-0 text-xs text-ink-muted">{{ t('text.custom_fields.form_pattern_hint') }}</p>
                        </div>
                    </details>
                    <p v-if="errors.pattern" class="m-0 text-xs text-danger" data-testid="cf-err-pattern">{{ t('text.custom_fields.form_err_pattern') }}</p>
                </div>
                <div v-if="form.type === 'integer'" class="grid gap-3 sm:grid-cols-2" data-testid="cf-controls-integer">
                    <div class="space-y-1">
                        <label for="cf-int-min" class="block text-sm font-medium">{{ t('text.custom_fields.form_int_min') }}</label>
                        <a-input-number id="cf-int-min" v-model:value="form.min" :precision="0" class="w-full" data-testid="cf-int-min" />
                    </div>
                    <div class="space-y-1">
                        <label for="cf-int-max" class="block text-sm font-medium">{{ t('text.custom_fields.form_int_max') }}</label>
                        <a-input-number id="cf-int-max" v-model:value="form.max" :precision="0" class="w-full" data-testid="cf-int-max" />
                    </div>
                </div>
                <div v-if="form.type === 'date'" class="grid gap-3 sm:grid-cols-2" data-testid="cf-controls-date">
                    <div class="space-y-1">
                        <label for="cf-date-min" class="block text-sm font-medium">{{ t('text.custom_fields.form_date_min') }}</label>
                        <a-input id="cf-date-min" v-model:value="form.dateMin" type="date" data-testid="cf-date-min" />
                    </div>
                    <div class="space-y-1">
                        <label for="cf-date-max" class="block text-sm font-medium">{{ t('text.custom_fields.form_date_max') }}</label>
                        <a-input id="cf-date-max" v-model:value="form.dateMax" type="date" data-testid="cf-date-max" />
                    </div>
                </div>
                <div v-if="form.type === 'multiselect'" class="space-y-1" data-testid="cf-controls-multi">
                    <label for="cf-multi-max" class="block text-sm font-medium">{{ t('text.custom_fields.form_multi_max') }}</label>
                    <a-input-number id="cf-multi-max" v-model:value="form.max" :min="1" :max="50" :precision="0" class="w-full sm:max-w-xs" data-testid="cf-multi-max" />
                </div>
                <p v-if="errors.limits" class="m-0 text-xs text-danger" data-testid="cf-err-limits">{{ t('text.custom_fields.form_err_limits') }}</p>

                <!-- Choices -->
                <div v-if="isChoice" class="space-y-2" data-testid="cf-controls-options">
                    <p class="m-0 text-sm font-medium">{{ t('text.custom_fields.form_options') }}</p>
                    <p v-if="optionsLocked" class="m-0 text-xs text-ink-muted" data-testid="cf-options-hint">
                        {{ t('text.custom_fields.form_option_in_use') }}
                    </p>
                    <div
                        v-for="(option, index) in form.options"
                        :key="option.value"
                        class="grid gap-2 rounded-md bg-surface-sunken p-2 sm:grid-cols-[1fr_1fr_auto]"
                        :data-testid="`cf-option-${index}`"
                    >
                        <a-input
                            v-model:value="option.vi"
                            :maxlength="120"
                            :aria-label="t('text.custom_fields.form_option_vi')"
                            :placeholder="t('text.custom_fields.form_option_vi')"
                            :data-testid="`cf-option-vi-${index}`"
                        />
                        <a-input
                            v-model:value="option.en"
                            :maxlength="120"
                            :aria-label="t('text.custom_fields.form_option_en')"
                            :placeholder="t('text.custom_fields.form_option_en')"
                            :data-testid="`cf-option-en-${index}`"
                        />
                        <i-button
                            size="sm"
                            variant="default"
                            :disabled="option.saved && optionsLocked"
                            :aria-label="t('text.custom_fields.form_option_remove_label', { name: option.vi || option.en })"
                            :data-testid="`cf-option-remove-${index}`"
                            @click="removeOption(index)"
                        >
                            {{ t('text.custom_fields.form_option_remove') }}
                        </i-button>
                    </div>
                    <i-button size="sm" variant="default" data-testid="cf-option-add" @click="addOption">
                        {{ t('text.custom_fields.form_option_add') }}
                    </i-button>
                    <p v-if="errors.options" class="m-0 text-xs text-danger" data-testid="cf-err-options">{{ t('text.custom_fields.form_err_options') }}</p>
                    <p v-if="errors.optionInUse" class="m-0 text-xs text-danger" data-testid="cf-err-option-in-use">{{ t('text.custom_fields.form_err_option_in_use') }}</p>
                </div>
            </section>

            <!-- Step 3: where it shows -->
            <section v-show="step === 3" class="space-y-3" data-testid="cf-step-show">
                <div class="flex items-center gap-2">
                    <a-switch v-model:checked="form.showInList" data-testid="cf-show-in-list" aria-labelledby="cf-show-label" />
                    <span id="cf-show-label" class="text-sm">{{ t('text.custom_fields.form_show_in_list') }}</span>
                </div>
                <p v-if="FILTERED_BY_TYPE.includes(form.type)" class="m-0 pb-2 text-xs text-ink-muted" data-testid="cf-filter-auto">{{ t('text.custom_fields.form_filter_auto') }}</p>
                <div v-else-if="form.type !== 'textarea'" class="flex items-center gap-2">
                    <a-switch v-model:checked="form.isFilterable" data-testid="cf-filterable" aria-labelledby="cf-filter-label" />
                    <span id="cf-filter-label" class="text-sm">{{ t('text.custom_fields.form_filterable') }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <a-switch v-model:checked="form.isSearchable" data-testid="cf-searchable" aria-labelledby="cf-search-label" />
                    <span id="cf-search-label" class="text-sm">{{ t('text.custom_fields.form_searchable') }}</span>
                </div>
            </section>

            <!-- Live preview, built by the server from the same definition -->
            <section v-if="form.type && step > 1" class="space-y-2 rounded-md border border-solid border-line p-3" data-testid="cf-preview">
                <p class="m-0 text-xs font-medium uppercase text-ink-muted">{{ t('text.custom_fields.form_preview_title') }}</p>
                <p v-if="previewLoading && !preview" class="m-0 text-xs text-ink-muted" data-testid="cf-preview-loading">{{ t('text.custom_fields.form_preview_loading') }}</p>
                <p v-else-if="!preview" class="m-0 text-xs text-ink-muted" data-testid="cf-preview-wait">{{ t('text.custom_fields.form_preview_wait') }}</p>
                <div v-else class="space-y-1" data-testid="cf-preview-field">
                    <label class="block text-sm font-medium">
                        {{ previewLabel }}<span v-if="preview.rules?.required" class="text-danger" aria-hidden="true"> *</span>
                    </label>
                    <a-switch v-if="preview.type === 'boolean'" disabled data-testid="cf-preview-input" />
                    <a-textarea v-else-if="preview.type === 'textarea'" disabled :rows="3" data-testid="cf-preview-input" />
                    <a-select
                        v-else-if="preview.type === 'enum'"
                        disabled
                        class="w-full"
                        :mode="preview.multiple ? 'multiple' : undefined"
                        :options="previewOptions"
                        data-testid="cf-preview-input"
                    />
                    <a-input v-else disabled :type="preview.type === 'date' ? 'date' : preview.type === 'number' ? 'number' : 'text'" data-testid="cf-preview-input" />
                    <p v-if="previewHelp" class="m-0 text-xs text-ink-muted" data-testid="cf-preview-help">{{ previewHelp }}</p>
                </div>
            </section>

            <div class="flex flex-wrap gap-2">
                <i-button v-if="step > 1" variant="default" data-testid="cf-back" @click="goTo(step - 1)">
                    {{ t('text.custom_fields.form_back') }}
                </i-button>
                <i-button v-if="step < 3" variant="default" :disabled="step === 1 && !form.type" data-testid="cf-next" @click="goTo(step + 1)">
                    {{ t('text.custom_fields.form_next') }}
                </i-button>
                <i-button
                    v-if="step === 3 || isEdit"
                    variant="primary"
                    native-type="submit"
                    :disabled="loading || !form.type"
                    data-testid="cf-save"
                >
                    {{ loading ? t('text.custom_fields.form_saving') : t('text.custom_fields.form_save') }}
                </i-button>
                <i-button variant="default" data-testid="cf-close" @click="close">{{ t('text.custom_fields.cancel') }}</i-button>
            </div>
        </form>

        <custom-field-purge
            v-if="isEdit && canPurge"
            class="mt-6"
            :target="target"
            :field="field"
            :backup-ready="backupReady"
            @purged="(count) => emit('purged', count)"
        />
    </a-drawer>
</template>

<script setup>
/**
 * Drawer to add or edit a custom field in three steps: kind of answer, details (names in both languages, limits that
 * fit the kind, choices) and where it shows. The key comes from the English name and never changes after saving. A live
 * preview comes from the server (`preview` endpoint), so it is built by the same code that builds the real form. The
 * server's 422 sentences are never shown: each failure maps to a plain sentence under its own box. Submit is guarded
 * (`loading`) and re-enabled only after an error. No listeners: the preview timer is cleared on unmount.
 */
import { computed, defineComponent, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import CustomFieldPurge from './custom-field-purge.vue'
import {
    FIELD_TYPES,
    FORMAT_PRESETS,
    buildPayload,
    createCustomField,
    emptyForm,
    fetchCustomFieldUsage,
    fieldErrorCodes,
    formFromField,
    keyFromLabel,
    optionValueFromLabel,
    previewCustomField,
    updateCustomField,
} from '@/libs/custom-fields'

defineComponent({ name: 'CustomFieldForm' })

const props = defineProps({
    open: { type: Boolean, default: false },
    /** Resource the field belongs to: `{ module, resource }`. */
    target: { type: Object, required: true },
    /** Field to edit, or null to add one. */
    field: { type: Object, default: null },
    /** Whether the operator may delete answers for good (the purge box is hidden otherwise). */
    canPurge: { type: Boolean, default: false },
    /** Whether the automatic backup before a purge exists; passed on to the purge box. */
    backupReady: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'saved', 'purged'])

const { t, locale } = useI18n()

const stepNames = ['type', 'details', 'show']
const step = ref(1)
const form = reactive(emptyForm())
const loading = ref(false)
const saveError = ref('')
const errors = ref({})
const usage = ref(0)
const preview = ref(null)
const previewLoading = ref(false)
let previewTimer = null
let previewSeq = 0

const isEdit = computed(() => Boolean(props.field))
const isChoice = computed(() => form.type === 'select' || form.type === 'multiselect')
/** The kind cannot change while records hold answers. */
const typeLocked = computed(() => isEdit.value && usage.value > 0)
/** Saved choices cannot be removed while records hold answers. */
const optionsLocked = computed(() => isEdit.value && usage.value > 0)
const fieldKey = computed(() => (isEdit.value ? props.field.key : keyFromLabel(form.labelEn)))

const formatOptions = computed(() => [
    { value: '', label: t('text.custom_fields.form_format_any') },
    ...Object.keys(FORMAT_PRESETS).map((name) => ({ value: name, label: t(`text.custom_fields.form_format_${name}`) })),
])

const previewLabel = computed(() => {
    const texts = preview.value?.label_text || {}
    return texts[locale.value] || texts.vi || texts.en || ''
})
const previewHelp = computed(() => {
    const texts = preview.value?.help_text || {}
    return texts[locale.value] || texts.vi || texts.en || ''
})
const previewOptions = computed(() =>
    (preview.value?.options || []).map((o) => ({ value: o.value, label: o.label_text?.[locale.value] || o.label_text?.vi || o.value })))

/** Reset the form each time the drawer opens. */
watch(
    () => props.open,
    async (isOpen) => {
        if (!isOpen) return
        Object.assign(form, props.field ? formFromField(props.field) : emptyForm())
        step.value = props.field ? 2 : 1
        saveError.value = ''
        errors.value = {}
        usage.value = 0
        preview.value = null
        if (props.field) {
            try {
                usage.value = Number((await fetchCustomFieldUsage(props.target, props.field.uuid)).data?.records) || 0
            } catch (e) {
                saveError.value = t('text.custom_fields.form_usage_failed')
            }
        }
        schedulePreview()
    },
    { immediate: true },
)

/** Ask the server for a fresh preview shortly after the form stops changing. */
function schedulePreview() {
    clearTimeout(previewTimer)
    if (!props.open || !form.type) return
    previewTimer = setTimeout(loadPreview, 500)
}
watch(form, schedulePreview, { deep: true })

async function loadPreview() {
    const seq = ++previewSeq
    previewLoading.value = true
    // Placeholders keep the preview alive while the operator is still typing names.
    const draft = { ...form, labelVi: form.labelVi || form.labelEn || t('text.custom_fields.form_preview_untitled'), labelEn: form.labelEn || form.labelVi || t('text.custom_fields.form_preview_untitled') }
    if (isChoice.value) draft.options = form.options.filter((o) => o.vi.trim() && o.en.trim())
    // No complete choice yet: nothing to preview, and no request that is sure to fail.
    if (isChoice.value && !draft.options.length) {
        preview.value = null
        previewLoading.value = false
        return
    }
    const payload = buildPayload(draft, fieldKey.value || 'preview')
    payload.help = form.helpVi.trim() && form.helpEn.trim() ? payload.help : null
    try {
        const body = await previewCustomField(props.target, payload)
        if (seq === previewSeq) preview.value = body?.data?.field || null
    } catch (e) {
        if (seq === previewSeq) preview.value = null
    } finally {
        if (seq === previewSeq) previewLoading.value = false
    }
}

onBeforeUnmount(() => clearTimeout(previewTimer))

const goTo = (next) => {
    step.value = Math.min(3, Math.max(1, next))
}

/** Kinds the list always filters by type, whatever the switch says: no switch is offered for them. */
const FILTERED_BY_TYPE = ['select', 'multiselect', 'boolean', 'integer', 'date']

/** Pick a kind; choices and limits that do not fit the new kind are dropped. */
function pickType(kind) {
    if (typeLocked.value && kind !== form.type) return
    if (kind === form.type) return
    form.type = kind
    form.min = null
    form.max = null
    form.dateMin = ''
    form.dateMax = ''
    form.format = ''
    form.pattern = ''
    if (kind === 'textarea') form.isFilterable = false
    if (!['select', 'multiselect'].includes(kind)) form.options = []
    else if (!form.options.length) addOption()
    errors.value = {}
}

/** Add an empty choice; its value is made from its English text when saving. */
function addOption() {
    form.options.push({ value: `new_${form.options.length + 1}_${Date.now() % 100000}`, vi: '', en: '', saved: false })
}

/** Remove a choice; a saved one stays while records hold answers. */
function removeOption(index) {
    const option = form.options[index]
    if (!option || (option.saved && optionsLocked.value)) return
    form.options.splice(index, 1)
}

/** Give each new choice a value from its English text (saved choices keep theirs). */
function settleOptionValues() {
    const taken = form.options.filter((o) => o.saved).map((o) => o.value)
    for (const option of form.options) {
        if (option.saved) continue
        option.value = optionValueFromLabel(option.en || option.vi, taken)
        taken.push(option.value)
    }
}

/** Save once; a second submit while it runs is ignored. Loading is reset only on error (the drawer closes on success). */
async function submit() {
    if (loading.value) return
    loading.value = true
    saveError.value = ''
    errors.value = {}
    try {
        if (!isEdit.value) settleOptionValues()
        const payload = buildPayload(form, fieldKey.value)
        const body = isEdit.value
            ? await updateCustomField(props.target, props.field.uuid, payload)
            : await createCustomField(props.target, payload)
        emit('saved', body?.data || null, isEdit.value)
    } catch (e) {
        errors.value = fieldErrorCodes(e?.response?.data)
        if (Object.keys(errors.value).length === 0) errors.value = { general: true }
        saveError.value = t('text.custom_fields.form_save_failed')
        if (errors.value.tooMany) saveError.value = t('text.custom_fields.form_limit_reached')
        // Show the step that holds the first problem.
        if (errors.value.type || errors.value.typeLocked) step.value = 1
        else if (errors.value.limits || errors.value.labels || errors.value.help || errors.value.key || errors.value.duplicate || errors.value.pattern || errors.value.options || errors.value.optionInUse) step.value = 2
        loading.value = false
    }
}

const close = () => emit('close')
defineExpose({ submit, loadPreview })
</script>
