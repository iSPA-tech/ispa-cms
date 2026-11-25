<template>
    <div class="space-y-3" data-testid="schema-form-editor">
        <a-empty
            v-if="!fields.length"
            :description="t('text.workflow.schema_form.no_settings')"
            :image-style="{ height: '32px' }"
        />
        <!-- A review step that reads a JEV verdict: who decides is chosen here, not in the plain "mode" setting -->
        <AutoPublishSection v-if="canAutoPublish" :read-only="readOnly" @available="(value) => (autoPublishShown = value)" />
        <SchemaField
            v-for="field in mainFields"
            :key="field.name"
            :name="field.name"
            :schema="field.schema"
            :model-value="config[field.name]"
            :required="field.required"
            :read-only="readOnly"
            :root-model="rootModel"
            :step-type="stepType"
            :scope="typeScope"
            top
            @update:model-value="(value) => updateField(field.name, value)"
        />
        <template v-if="moreFields.length">
            <a-button
                type="link"
                class="!h-auto !px-0"
                :aria-expanded="showMore ? 'true' : 'false'"
                data-testid="schema-form-more"
                @click="showMore = !showMore"
            >
                <i-icon :icon="showMore ? 'chevron-down' : 'chevron-right'" class="mr-1 inline-block h-4 w-4 align-text-bottom" />
                {{ showMore ? t('text.workflow.schema_form.less_options') : t('text.workflow.schema_form.more_options') }}
            </a-button>
            <div v-show="showMore" class="space-y-3" data-testid="schema-form-more-fields">
                <SchemaField
                    v-for="field in moreFields"
                    :key="field.name"
                    :name="field.name"
                    :schema="field.schema"
                    :model-value="config[field.name]"
                    :required="field.required"
                    :read-only="readOnly"
                    :root-model="rootModel"
                    :step-type="stepType"
                    :scope="typeScope"
                    top
                    @update:model-value="(value) => updateField(field.name, value)"
                />
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNodeCatalog } from '@/components/workflows/nodeCatalog'
import SchemaField from './SchemaField.vue'
import { primaryFields, refineFieldSchema } from './schemaForm.js'
import { provideStepForm } from '@/components/workflow/inputs/useStepData.js'
import { isHiddenField } from '@/components/workflow/widgets/widgetRules.js'
import { readsVerdict } from '@/components/workflow/widgets/autoPublishRules.js'
import AutoPublishSection from '@/components/workflow/widgets/AutoPublishSection.vue'

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    /** `config_schema` of the node; when absent it is read from the node catalog by `stepType`. */
    schema: {
        type: Object,
        default: null,
    },
    stepType: {
        type: String,
        default: '',
    },
    rootModel: {
        type: String,
        default: '',
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const { catalog, findNode } = useNodeCatalog()

const activeSchema = computed(() => props.schema || findNode(props.stepType)?.config_schema || {})

const fields = computed(() => {
    const properties = activeSchema.value?.properties
    if (!properties || typeof properties !== 'object' || Array.isArray(properties)) return []
    const required = Array.isArray(activeSchema.value.required) ? activeSchema.value.required : []
    // A setting another widget of the step writes (the JEV criteria set and its strictness) is not a field of its own.
    return Object.entries(properties).filter(([name]) => !isHiddenField(props.stepType, name) && !(name === 'mode' && autoPublishShown.value)).map(([name, schema]) => ({
        name,
        schema: refineFieldSchema(name, schema && typeof schema === 'object' ? schema : {}),
        required: required.includes(name),
    }))
})

/** Prefix of this step type's own field wording: `text.workflow.field.<type>_<name>` wins over the shared name. */
const typeScope = computed(() => String(props.stepType || '').replace(/[^A-Za-z0-9]+/g, '_'))

// The settings most people change come first; the others wait behind "More options". A required setting, and every
// setting of a step type without such a list, is always shown.
const showMore = ref(false)
const primary = computed(() => primaryFields(props.stepType))
const isMain = (field) => !primary.value || field.required || primary.value.includes(field.name)
const mainFields = computed(() => {
    const list = fields.value.filter(isMain)
    if (!primary.value) return list
    const order = (field) => (primary.value.includes(field.name) ? primary.value.indexOf(field.name) : -1)
    return [...list].sort((a, b) => order(a) - order(b))
})
const moreFields = computed(() => fields.value.filter((field) => !isMain(field)))
watch(
    () => props.stepType,
    () => (showMore.value = false)
)

// Keys the schema does not describe (`_v`, `on_error`, settings of a newer version) are kept untouched.
const config = ref({})
watch(
    () => props.modelValue,
    (value) => {
        const next = value && typeof value === 'object' && !Array.isArray(value) ? value : {}
        if (JSON.stringify(next) !== JSON.stringify(config.value)) {
            config.value = JSON.parse(JSON.stringify(next))
        }
    },
    { immediate: true, deep: true }
)

const updateField = (name, value) => {
    config.value = { ...config.value, [name]: value }
    emit('update:modelValue', JSON.parse(JSON.stringify(config.value)))
}

/** Several settings at once, for a widget that writes more than its own; a key given as `undefined` is removed. */
const patch = (values = {}) => {
    if (props.readOnly) return
    const next = { ...config.value }
    Object.entries(values).forEach(([name, value]) => {
        if (value === undefined) delete next[name]
        else next[name] = value
    })
    if (JSON.stringify(next) === JSON.stringify(config.value)) return
    config.value = next
    emit('update:modelValue', JSON.parse(JSON.stringify(next)))
}
// "Auto-publish when JEV is sure": only a review step that reads a verdict another step stored can have it.
const canAutoPublish = computed(() => (catalog.approvalTypes || ['approval']).includes(props.stepType) && readsVerdict(config.value))
const autoPublishShown = ref(false)
watch(canAutoPublish, (value) => !value && (autoPublishShown.value = false))

provideStepForm({ config: computed(() => config.value), patch, stepType: computed(() => props.stepType) })
</script>
