<template>
    <div class="space-y-4" data-testid="email-send-editor">
        <!-- Typed text or a managed template -->
        <div class="space-y-1">
            <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.email.mode_label') }}</p>
            <a-radio-group
                :value="mode"
                :disabled="readOnly"
                button-style="solid"
                class="!flex w-full"
                data-testid="email-send-mode"
                @update:value="setMode"
            >
                <a-radio-button :value="MODE_TEXT" class="flex-1 text-center" data-testid="email-send-mode-text">{{ t('text.workflow.email.mode_text') }}</a-radio-button>
                <a-radio-button :value="MODE_TEMPLATE" class="flex-1 text-center" data-testid="email-send-mode-template">{{ t('text.workflow.email.mode_template') }}</a-radio-button>
            </a-radio-group>
            <p class="mb-0 break-words text-xs text-gray-600">
                {{ mode === MODE_TEMPLATE ? t('text.workflow.email.mode_template_hint') : t('text.workflow.email.mode_text_hint') }}
            </p>
        </div>

        <SchemaFormEditor
            v-if="mode === MODE_TEXT"
            :schema="textSchema"
            :step-type="stepType"
            :model-value="modelValue"
            :root-model="rootModel"
            :read-only="readOnly"
            @update:model-value="(value) => emit('update:modelValue', value)"
        />

        <template v-else>
            <!-- Which template -->
            <div class="space-y-1" data-testid="email-send-template">
                <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.email.template_label') }} <span class="text-red-600">*</span></p>
                <a-select
                    class="w-full"
                    show-search
                    option-filter-prop="label"
                    :value="config.template_key || undefined"
                    :options="templateOptions"
                    :loading="loading"
                    :disabled="readOnly"
                    :placeholder="t('text.workflow.email.template_placeholder')"
                    :aria-label="t('text.workflow.email.template_label')"
                    :not-found-content="loading ? t('text.workflow.pick.loading') : t('text.workflow.email.template_none')"
                    data-testid="email-send-template-select"
                    @update:value="setTemplate"
                >
                    <template #option="{ label, mailClass, languages }">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 whitespace-normal">
                            <span class="min-w-0 break-words">{{ label }}</span>
                            <a-tag v-if="mailClass" :color="classColor(mailClass)" class="!mr-0">{{ classLabel(mailClass) }}</a-tag>
                            <span v-if="languages" class="text-xs text-gray-500">{{ languages }}</span>
                        </div>
                    </template>
                </a-select>
                <p v-if="failed" class="mb-0 break-words text-xs text-amber-700" role="alert" data-testid="email-send-template-failed">
                    {{ t('text.workflow.email.template_failed') }}
                    <a-button type="link" size="small" class="!h-auto !p-0 !text-xs" @click="load">{{ t('text.workflow.pick.retry') }}</a-button>
                </p>
                <p v-else-if="missing" class="mb-0 break-words text-xs text-amber-700" role="alert" data-testid="email-send-template-missing">
                    {{ t('text.workflow.email.template_missing') }}
                </p>
                <p v-else-if="!loading && !templates.length" class="mb-0 break-words text-xs text-gray-600" data-testid="email-send-template-empty">
                    {{ t('text.workflow.email.template_none') }}
                </p>
                <p v-if="picked" class="mb-0 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-600" data-testid="email-send-template-picked">
                    <a-tag :color="classColor(picked.mail_class)" class="!mr-0" data-testid="email-send-class">{{ classLabel(picked.mail_class) }}</a-tag>
                    <span class="break-words">{{ t(`text.workflow.email.class_${picked.mail_class === 'system' ? 'system' : 'business'}_note`) }}</span>
                </p>
                <router-link v-if="canManageTemplates" :to="{ name: 'setting-email-templates' }" target="_blank" class="inline-block text-xs" data-testid="email-send-manage">
                    {{ t('text.workflow.email.manage_link') }}
                </router-link>
            </div>

            <template v-if="picked">
                <!-- Business mail: sends only while this switch is on (off unless set) -->
                <div
                    v-if="picked.mail_class !== 'system'"
                    class="space-y-1 rounded-lg border border-solid border-amber-200 bg-amber-50 px-3 py-2"
                    data-testid="email-send-business"
                >
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-800">
                        <a-switch
                            size="small"
                            :checked="businessOn"
                            :disabled="readOnly"
                            data-testid="email-send-business-switch"
                            @update:checked="(value) => patch({ business_enabled: value ? true : undefined })"
                        />
                        <span class="break-words">{{ t('text.workflow.email.business_label') }}</span>
                    </label>
                    <p class="mb-0 break-words text-xs text-gray-700">
                        {{ businessOn ? t('text.workflow.email.business_on_hint') : t('text.workflow.email.business_off_hint') }}
                    </p>
                </div>

                <!-- Who receives it -->
                <div class="space-y-1" data-testid="email-send-recipient">
                    <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.email.recipient_label') }}</p>
                    <a-radio-group :value="recipient" :disabled="readOnly" class="!flex flex-col gap-2" @update:value="(value) => patch(switchRecipient(config, value))">
                        <a-radio v-if="hasSubject || recipient === RECIPIENT_SUBJECT" :value="RECIPIENT_SUBJECT" class="!items-start !whitespace-normal" data-testid="email-send-recipient-subject">
                            <span class="block break-words">{{ t('text.workflow.email.recipient_subject') }}</span>
                            <span class="block break-words text-xs text-gray-600">{{ t('text.workflow.email.recipient_subject_hint') }}</span>
                        </a-radio>
                        <a-radio :value="RECIPIENT_STAFF" class="!items-start !whitespace-normal" data-testid="email-send-recipient-staff">
                            <span class="block break-words">{{ t('text.workflow.email.recipient_staff') }}</span>
                            <span class="block break-words text-xs text-gray-600">{{ t('text.workflow.email.recipient_staff_hint') }}</span>
                        </a-radio>
                        <a-radio :value="RECIPIENT_ADDRESS" class="!items-start !whitespace-normal" data-testid="email-send-recipient-address">
                            <span class="block break-words">{{ t('text.workflow.email.recipient_address') }}</span>
                            <span class="block break-words text-xs text-gray-600">{{ t('text.workflow.email.recipient_address_hint') }}</span>
                        </a-radio>
                    </a-radio-group>
                    <p v-if="recipient === RECIPIENT_SUBJECT && !hasSubject" class="mb-0 break-words text-xs text-amber-700" role="alert" data-testid="email-send-subject-unavailable">
                        {{ t('text.workflow.issue.email_recipient_unavailable') }}
                    </p>
                    <p v-else-if="!hasSubject" class="mb-0 break-words text-xs text-gray-600" data-testid="email-send-subject-why">
                        {{ t('text.workflow.email.recipient_subject_why') }}
                    </p>
                    <div v-if="recipient === RECIPIENT_STAFF" class="pt-1">
                        <PermissionPicker
                            :model-value="config.recipient_permission"
                            :read-only="readOnly"
                            required
                            :label="t('text.workflow.email.recipient_staff')"
                            @update:model-value="(value) => patch({ recipient_permission: value || '' })"
                        />
                    </div>
                    <div v-else-if="recipient === RECIPIENT_ADDRESS" class="space-y-1 pt-1" data-testid="email-send-address">
                        <DataTokenInput
                            :model-value="config.to"
                            :disabled="readOnly"
                            :aria-label="t('text.workflow.email.recipient_address')"
                            :placeholder="t('text.workflow.email.to_placeholder')"
                            @update:model-value="(value) => patch({ to: value })"
                        />
                        <a-alert type="warning" show-icon :message="t('text.workflow.email.address_needs_approval')" data-testid="email-send-address-approval" />
                    </div>
                </div>

                <!-- Language of the mail -->
                <div v-if="(picked.languages || []).filter((row) => row.code).length > 1 || langKind !== 'default'" class="space-y-1" data-testid="email-send-language">
                    <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.email.language_label') }}</p>
                    <a-select
                        class="w-full"
                        :value="langKind === 'fixed' ? `fixed:${config.language}` : langKind"
                        :options="languageOptions"
                        :disabled="readOnly"
                        :aria-label="t('text.workflow.email.language_label')"
                        data-testid="email-send-language-select"
                        @update:value="setLanguage"
                    />
                    <DataTokenInput
                        v-if="langKind === 'data'"
                        :model-value="config.language"
                        :disabled="readOnly"
                        :aria-label="t('text.workflow.email.language_from_data')"
                        @update:model-value="(value) => patch({ language: value || DATA_LANGUAGE })"
                    />
                    <p class="mb-0 break-words text-xs text-gray-600">{{ t('text.workflow.email.language_hint') }}</p>
                </div>

                <!-- What fills the blanks of the template -->
                <div class="space-y-2" data-testid="email-send-variables">
                    <p class="mb-0 text-sm font-semibold text-gray-700">{{ t('text.workflow.email.variables_label') }}</p>
                    <p class="mb-0 break-words text-xs text-gray-600">
                        {{ rows.length ? t('text.workflow.email.variables_hint') : t('text.workflow.email.variables_none') }}
                    </p>
                    <div v-for="row in rows" :key="row.key" class="space-y-1 rounded-md border border-solid border-slate-200 p-2" :data-variable="row.key">
                        <p class="mb-0 break-words text-sm text-gray-800">
                            {{ row.label }}
                            <span v-if="row.sample" class="text-xs text-gray-500">({{ t('text.workflow.email.variable_sample', { sample: row.sample }) }})</span>
                        </p>
                        <DataTokenInput
                            :model-value="row.value"
                            :disabled="readOnly"
                            :aria-label="row.label"
                            :placeholder="t('text.workflow.email.variable_placeholder')"
                            @update:model-value="(value) => patch({ variables: writeVariable(config.variables, row.key, value) })"
                        />
                    </div>
                </div>

                <p v-if="managed" class="mb-0 break-words rounded-lg border border-solid border-green-200 bg-green-50 px-3 py-2 text-xs text-green-800" data-testid="email-send-managed">
                    {{ t('text.workflow.email.no_approval_needed') }}
                </p>
            </template>

            <!-- Own mail server and the name of the result: rarely changed -->
            <div class="space-y-3">
                <a-button type="link" class="!h-auto !px-0" :aria-expanded="showMore ? 'true' : 'false'" data-testid="email-send-more" @click="showMore = !showMore">
                    <i-icon :icon="showMore ? 'chevron-down' : 'chevron-right'" class="mr-1 inline-block h-4 w-4 align-text-bottom" />
                    {{ showMore ? t('text.workflow.schema_form.less_options') : t('text.workflow.schema_form.more_options') }}
                </a-button>
                <div v-show="showMore" class="space-y-3">
                    <SchemaField
                        v-for="name in MORE_FIELDS"
                        :key="name"
                        :name="name"
                        :schema="properties[name] || {}"
                        :model-value="config[name]"
                        :read-only="readOnly"
                        :root-model="rootModel"
                        :step-type="stepType"
                        :scope="stepType"
                        top
                        @update:model-value="(value) => patch({ [name]: value })"
                    />
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { rule } from '@/mixins'
import { fetchEmailTemplateCatalog } from '@/services/workflowApi'
import { useNodeCatalog } from '@/components/workflows/nodeCatalog'
import { useStepData } from '@/components/workflow/inputs/useStepData.js'
import DataTokenInput from '@/components/workflow/inputs/DataTokenInput.vue'
import PermissionPicker from '@/components/workflow/widgets/PermissionPicker.vue'
import SchemaField from './SchemaField.vue'
import SchemaFormEditor from './SchemaFormEditor.vue'
import {
    MODE_TEMPLATE,
    MODE_TEXT,
    RECIPIENT_ADDRESS,
    RECIPIENT_STAFF,
    RECIPIENT_SUBJECT,
    TEMPLATE_KEYS,
    TEXT_KEYS,
    isManaged,
    languageKind,
    modeOf,
    recipientOf,
    startsOnSubjectEvent,
    switchMode,
    switchRecipient,
    switchTemplate,
    variableRows,
    writeVariable,
} from '@/components/workflow/widgets/emailSendRules.js'

/**
 * Form of the "send an e-mail" step (`email_send`). Two ways to write the mail: text typed in the step, or a managed
 * e-mail template (Settings > Email templates, group `workflow`) picked from the site's list. A template step asks
 * only what a person knows: which template, who receives it (the person who triggered the workflow, staff holding a
 * permission, or a typed address, which needs an approval step), which data fills its blanks, and, for a business
 * template, the switch that lets it send (off unless switched on). A system template sends without that switch.
 * The stored settings are the server's (`EmailSendRunner`): nothing here is checked only on the client.
 */
defineOptions({ name: 'EmailSendEditor' })

const props = defineProps({
    step: { type: Object, default: () => ({}) },
    stepType: { type: String, default: 'email_send' },
    modelValue: { type: Object, default: () => ({}) },
    rootModel: { type: String, default: '' },
    steps: { type: Array, default: () => [] },
    readOnly: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const { catalog, findNode } = useNodeCatalog()
const stepData = useStepData()

/** A language taken from the workflow's data starts as the language of the trigger (a sign-up carries one). */
const DATA_LANGUAGE = '{{ vars.trigger.event.payload.locale }}'
const TEXT_FIELDS = ['credential', 'to', ...TEXT_KEYS, 'storeAs']
const MORE_FIELDS = ['credential', 'storeAs']

const config = computed(() => (props.modelValue && typeof props.modelValue === 'object' && !Array.isArray(props.modelValue) ? props.modelValue : {}))
const mode = computed(() => modeOf(config.value))
const recipient = computed(() => recipientOf(config.value))
const properties = computed(() => findNode(props.stepType)?.config_schema?.properties || {})
/** The typed-text mode keeps the generic form, limited to its own settings. */
const textSchema = computed(() => ({
    type: 'object',
    properties: Object.fromEntries(TEXT_FIELDS.filter((name) => properties.value[name]).map((name) => [name, properties.value[name]])),
}))

/** Merge settings into the step; a key given as `undefined` is removed. Keys this form does not know are kept. */
const patch = (values = {}) => {
    if (props.readOnly) return
    const next = { ...config.value }
    Object.entries(values).forEach(([name, value]) => {
        if (value === undefined) delete next[name]
        else next[name] = value
    })
    if (JSON.stringify(next) === JSON.stringify(config.value)) return
    emit('update:modelValue', next)
}

// What the other mode held, so a switch made by mistake loses nothing while the form stays open.
const kept = ref({ text: {}, template: {} })
const hasSubject = computed(() => startsOnSubjectEvent(stepData.nodes.value, catalog.triggerSubjectEvents))
const setMode = (next) => {
    if (next === mode.value) return
    const pick = (keys) => Object.fromEntries(keys.filter((key) => config.value[key] !== undefined).map((key) => [key, config.value[key]]))
    if (next === MODE_TEMPLATE) {
        kept.value.text = pick(['to', ...TEXT_KEYS])
        patch(switchMode(config.value, next, { hasSubject: hasSubject.value, kept: kept.value.template }))
    } else {
        kept.value.template = pick([...TEMPLATE_KEYS, 'recipient'])
        patch(switchMode(config.value, next, { kept: kept.value.text }))
    }
}

// Templates of the site
const templates = ref([])
const loading = ref(false)
const failed = ref(false)
const load = async () => {
    if (loading.value) return
    loading.value = true
    failed.value = false
    try {
        templates.value = await fetchEmailTemplateCatalog()
    } catch (error) {
        failed.value = true
    } finally {
        loading.value = false
    }
}
onMounted(() => mode.value === MODE_TEMPLATE && load())
watch(mode, (value) => value === MODE_TEMPLATE && !templates.value.length && load())

const classLabel = (mailClass) => t(`text.workflow.value.${mailClass === 'system' ? 'system' : 'business'}`)
const classColor = (mailClass) => (mailClass === 'system' ? 'blue' : 'gold')
const languageNames = (row) =>
    (row.languages || [])
        .map((language) => language.name || language.code)
        .filter(Boolean)
        .join(', ')

const picked = computed(() => templates.value.find((row) => row.key_name === config.value.template_key) || null)
/** A saved template the site no longer lists (deleted, or made on another site). */
const missing = computed(() => !!config.value.template_key && !loading.value && !failed.value && !picked.value)
const templateOptions = computed(() => {
    const options = templates.value.map((row) => ({ value: row.key_name, label: row.name || row.key_name, mailClass: row.mail_class, languages: languageNames(row) }))
    if (config.value.template_key && !picked.value) options.unshift({ value: config.value.template_key, label: t('text.workflow.email.template_saved'), mailClass: '', languages: '' })
    return options
})
const setTemplate = (key) => patch(switchTemplate(config.value, templates.value.find((row) => row.key_name === key) || { key_name: key }))
const canManageTemplates = computed(() => rule('email_template.viewAny'))

const businessOn = computed(() => config.value.business_enabled === true || config.value.business_enabled === 1 || config.value.business_enabled === 'true' || config.value.business_enabled === '1')
const managed = computed(() => isManaged(config.value))

const langKind = computed(() => languageKind(config.value.language, picked.value))
const languageOptions = computed(() => [
    { value: 'default', label: t('text.workflow.email.language_default') },
    ...(picked.value?.languages || []).filter((row) => row.code).map((row) => ({ value: `fixed:${row.code}`, label: row.name || row.code })),
    { value: 'data', label: t('text.workflow.email.language_from_data') },
])
const setLanguage = (value) => {
    if (value === 'default') patch({ language: undefined })
    else if (value === 'data') patch({ language: DATA_LANGUAGE })
    else patch({ language: String(value).slice('fixed:'.length) })
}

const rows = computed(() => variableRows(picked.value, config.value.variables))
const showMore = ref(false)
</script>
