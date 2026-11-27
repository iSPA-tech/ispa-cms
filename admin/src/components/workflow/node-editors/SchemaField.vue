<template>
    <div v-if="!hidden" class="space-y-1" :data-field="name" :data-kind="kind">
        <p v-if="label && kind !== 'boolean'" class="mb-1 break-words text-sm font-semibold text-gray-700">
            {{ label }}
            <span v-if="required" class="text-red-600" :title="t('text.workflow.schema_form.required')">*</span>
        </p>

        <!-- A record or a record type: taken from the workflow's own data, never typed. -->
        <div
            v-if="kind === 'derived'"
            class="flex items-start gap-2 rounded-lg border border-solid border-gray-200 bg-gray-50 px-3 py-2"
            data-testid="schema-field-derived"
        >
            <i-icon icon="lock-outline" class="mt-0.5 h-4 w-4 shrink-0 text-gray-500" />
            <span class="min-w-0">
                <span class="block break-words text-sm text-gray-800">{{ derivedText }}</span>
                <span class="block break-words text-xs text-gray-600">{{ t(derivedNote) }}</span>
            </span>
        </div>

        <EditableFieldsPicker v-else-if="kind === 'editable_fields'" :model-value="modelValue" :read-only="readOnly" @update:model-value="update" />
        <JevCriteriaPicker v-else-if="kind === 'jev_criteria'" :model-value="modelValue" :read-only="readOnly" :fixed="hasFixedCriteria(stepType)" />
        <SwitchCasesPicker v-else-if="kind === 'switch_cases'" :model-value="modelValue" :read-only="readOnly" :values="switchValues" @update:model-value="update" />
        <PermissionPicker v-else-if="kind === 'permission'" :model-value="modelValue" :read-only="readOnly" :required="required" :label="label" @update:model-value="update" />
        <ExtractSchemaPicker v-else-if="kind === 'extract_schema'" :model-value="modelValue" :read-only="readOnly" @update:model-value="update" />
        <ClassifyLabelsPicker v-else-if="kind === 'classify_labels'" :model-value="modelValue" :read-only="readOnly" @update:model-value="update" />
        <RubricRows v-else-if="kind === 'rubric'" :model-value="modelValue" :read-only="readOnly" @update:model-value="update" />
        <NameValueRows
            v-else-if="kind === 'name_values' || kind === 'named_inputs'"
            :model-value="modelValue"
            :read-only="readOnly"
            :variant="kind === 'named_inputs' ? 'inputs' : 'map'"
            :names="name === 'headers' ? COMMON_HEADERS : []"
            :empty-text="authoredFieldText(name, scope, t, te, '_none')"
            :add-text="authoredFieldText(name, scope, t, te, '_add')"
            @update:model-value="update"
        />

        <ExpressionEditor
            v-else-if="kind === 'path'"
            :model-value="modelValue"
            variant="pick"
            :model-class="rootModel"
            :disabled="readOnly"
            @update:model-value="update"
        />

        <DataTokenInput
            v-else-if="kind === 'template'"
            :model-value="modelValue"
            :multiline="longText"
            :disabled="readOnly"
            :aria-label="label"
            :mentions="mentions"
            @update:model-value="update"
        />

        <StoreNameInput
            v-else-if="kind === 'store'"
            :model-value="modelValue"
            :disabled="readOnly"
            :label="label"
            @update:model-value="update"
        />

        <template v-else-if="kind === 'binding'">
            <a-input
                :value="bindName"
                :disabled="readOnly"
                :placeholder="t('text.workflow.schema_form.binding_placeholder')"
                @update:value="(value) => update(toBinding(value))"
            >
                <template #prefix>
                    <i-icon icon="lock-outline" class="h-4 w-4 text-gray-400" />
                </template>
            </a-input>
            <p class="mb-0 break-words text-xs text-gray-500">{{ t('text.workflow.schema_form.binding_help') }}</p>
        </template>

        <template v-else-if="kind === 'model'">
            <a-select
                class="w-full"
                :value="modelValue ?? ''"
                :disabled="readOnly"
                :loading="modelsLoading"
                :options="modelOptions"
                :aria-label="label"
                data-testid="schema-field-model"
                @update:value="(value) => update(value || '')"
            />
            <p v-if="modelsFailed" class="mb-0 break-words text-xs text-amber-600" data-testid="schema-field-model-failed">
                {{ t('text.workflow.field.model_choice_load_failed') }}
            </p>
            <p v-else-if="modelMissing" class="mb-0 break-words text-xs text-amber-600" data-testid="schema-field-model-missing">
                {{ t('text.workflow.field.model_choice_unavailable') }}
            </p>
        </template>

        <ReviewerPicker
            v-else-if="kind === 'users'"
            :model-value="modelValue"
            :read-only="readOnly"
            :max="schema.maxItems || 10"
            :audience="schema['x-audience'] || 'review'"
            :label="label"
            @update:model-value="(value) => update(value)"
        />

        <SlaDurationInput
            v-else-if="kind === 'duration'"
            :model-value="modelValue"
            :read-only="readOnly"
            :label="label"
            :empty-label="authoredFieldText(name, scope, t, te, '_none')"
            @update:model-value="(value) => update(value)"
        />

        <a-select
            v-else-if="kind === 'enum'"
            class="w-full"
            :value="modelValue ?? undefined"
            :disabled="readOnly"
            :options="enumOptions"
            :allow-clear="!required"
            @update:value="(value) => update(value ?? null)"
        />

        <label v-else-if="kind === 'boolean'" class="flex items-center gap-2 text-sm font-semibold text-gray-700">
            <a-switch :checked="modelValue === true" :disabled="readOnly" size="small" @update:checked="update" />
            <span class="break-words">{{ label }}</span>
        </label>

        <a-input-number
            v-else-if="kind === 'number'"
            class="w-full"
            :value="modelValue ?? null"
            :disabled="readOnly"
            :min="schema.minimum"
            :max="schema.maximum"
            :precision="isInteger ? 0 : undefined"
            @update:value="(value) => update(value ?? null)"
        />

        <template v-else-if="kind === 'string'">
            <a-textarea
                v-if="schema['x-widget'] === 'textarea'"
                :value="modelValue ?? ''"
                :disabled="readOnly"
                :auto-size="{ minRows: 3, maxRows: 10 }"
                @update:value="update"
            />
            <a-input v-else :value="modelValue ?? ''" :disabled="readOnly" :maxlength="schema.maxLength" @update:value="update" />
        </template>

        <div v-else-if="kind === 'object'" class="space-y-3 border-0 border-l-2 border-solid border-slate-200 pl-3">
            <SchemaField
                v-for="(childSchema, childName) in schema.properties"
                :key="childName"
                :name="String(childName)"
                :schema="childSchema"
                :model-value="objectValue[childName]"
                :required="requiredList.includes(childName)"
                :read-only="readOnly"
                :root-model="rootModel"
                :step-type="stepType"
                :scope="ownScope"
                @update:model-value="(value) => updateChild(childName, value)"
            />
        </div>

        <div v-else-if="kind === 'array'" class="space-y-2">
            <div
                v-for="(item, index) in arrayValue"
                :key="index"
                class="flex items-start gap-2 rounded-md border border-solid border-slate-200 p-2"
            >
                <SchemaField
                    class="min-w-0 flex-1"
                    :name="String(index)"
                    :schema="schema.items"
                    :model-value="item"
                    :read-only="readOnly"
                    :root-model="rootModel"
                    :step-type="stepType"
                    :scope="ownScope"
                    hide-label
                    @update:model-value="(value) => updateItem(index, value)"
                />
                <a-button
                    v-if="!readOnly"
                    size="small"
                    type="text"
                    danger
                    :aria-label="t('text.workflow.schema_form.remove_row')"
                    :title="t('text.workflow.schema_form.remove_row')"
                    @click="removeItem(index)"
                >
                    <i-icon icon="trash-can-outline" class="h-4 w-4" />
                </a-button>
            </div>
            <a-button v-if="!readOnly && !rowsFull" size="small" type="dashed" block @click="addItem">
                {{ t('text.workflow.schema_form.add_row') }}
            </a-button>
        </div>

        <template v-else>
            <a-textarea
                v-model:value="jsonText"
                :disabled="readOnly"
                :auto-size="{ minRows: 2, maxRows: 10 }"
                class="font-mono"
                @blur="commitJson"
            />
            <p v-if="jsonError" class="mb-0 text-xs text-red-600">{{ t('text.workflow.schema_form.json_invalid') }}</p>
            <p v-else class="mb-0 text-xs text-gray-500">{{ t('text.workflow.schema_form.json_help') }}</p>
        </template>

        <!-- The branches picker explains itself ("click a value"); the row wording of the plain field would mislead. -->
        <p v-if="hint && kind !== 'switch_cases'" class="mb-0 break-words text-xs text-gray-500" data-testid="schema-field-hint">{{ hint }}</p>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTokenInput from '@/components/workflow/inputs/DataTokenInput.vue'
import ExpressionEditor from '@/components/workflow/inputs/ExpressionEditor.vue'
import StoreNameInput from '@/components/workflow/inputs/StoreNameInput.vue'
import { fieldSyntax, findDataItem, isIdField, readPath } from '@/components/workflow/inputs/dataTokens.js'
import { useStepData } from '@/components/workflow/inputs/useStepData.js'
import ReviewerPicker from '@/components/workflow/inputs/ReviewerPicker.vue'
import SlaDurationInput from '@/components/workflow/inputs/SlaDurationInput.vue'
import { fetchSelectableModels } from '@/services/workflowApi'
import { useStepForm } from '@/components/workflow/inputs/useStepData.js'
import ClassifyLabelsPicker from '@/components/workflow/widgets/ClassifyLabelsPicker.vue'
import EditableFieldsPicker from '@/components/workflow/widgets/EditableFieldsPicker.vue'
import ExtractSchemaPicker from '@/components/workflow/widgets/ExtractSchemaPicker.vue'
import JevCriteriaPicker from '@/components/workflow/widgets/JevCriteriaPicker.vue'
import NameValueRows from '@/components/workflow/widgets/NameValueRows.vue'
import PermissionPicker from '@/components/workflow/widgets/PermissionPicker.vue'
import RubricRows from '@/components/workflow/widgets/RubricRows.vue'
import SwitchCasesPicker from '@/components/workflow/widgets/SwitchCasesPicker.vue'
import { COMMON_HEADERS, hasFixedCriteria, readPairs, widgetFor } from '@/components/workflow/widgets/widgetRules.js'
import { authoredFieldText, bindingName, bindingValue, emptyValue, fieldKind, fieldLabel, modelOptionLabel } from './schemaForm.js'

defineOptions({ name: 'SchemaField' })

const props = defineProps({
    name: {
        type: String,
        default: '',
    },
    schema: {
        type: Object,
        default: () => ({}),
    },
    modelValue: {
        type: null,
        default: undefined,
    },
    required: {
        type: Boolean,
        default: false,
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
    rootModel: {
        type: String,
        default: '',
    },
    hideLabel: {
        type: Boolean,
        default: false,
    },
    /** Step type of the form: it decides how a data setting is stored (a template or one expression). */
    stepType: {
        type: String,
        default: '',
    },
    /** Name of the parent field (a repeater or a group), used to find this field's authored wording. */
    scope: {
        type: String,
        default: '',
    },
    /** A setting of the step itself (not a field inside a group or a row): it may have a "pick" widget. */
    top: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])

const { t, te } = useI18n()

const stepData = useStepData()

const baseKind = computed(() => fieldKind(props.schema, props.modelValue))
/** `path` (one expression) or `template` (text with placeholders) for a setting that may hold data, else null. */
const syntax = computed(() => fieldSyntax(props.stepType, props.name, props.schema))
const isText = computed(() => baseKind.value === 'expr' || baseKind.value === 'string')
const filled = computed(() => props.modelValue !== null && props.modelValue !== undefined && props.modelValue !== '')
const idField = computed(() => isText.value && isIdField(props.name, syntax.value))
/**
 * Widget of the field. On top of the schema kinds:
 * - `derived`: a record or record type, shown read-only (changed only in advanced mode)
 * - `path` / `template`: data picked from a list, shown as chips
 * - `store`: the name of the step's own result
 */
const stepForm = useStepForm()
/** "Pick, don't type" widget of this setting, '' for none. */
const widget = computed(() => (props.top ? widgetFor(props.stepType, props.name, props.schema) : ''))
// The named inputs of an AI step can be called by name in its instructions: typing @ lists them.
const mentions = computed(() =>
    props.top && ['instructions', 'system'].includes(props.name) && /^ai_/.test(props.stepType)
        ? readPairs(stepForm?.config.value?.inputs).map((row) => row.name).filter(Boolean)
        : []
)
/** The values the data a "switch" step looks at may hold; its branches are then picked instead of typed. */
const switchValues = computed(() => {
    if (widget.value !== 'switch_cases') return []
    const view = readPath(stepForm?.config.value?.valueExpr)
    return view.kind === 'chip' ? findDataItem(view.path, stepData.groups.value)?.item?.values || [] : []
})
const kind = computed(() => {
    // Branches are picked only when the values are known; otherwise they stay rows of a key and a value.
    if (widget.value === 'switch_cases' && !switchValues.value.length) return baseKind.value
    if (widget.value) return widget.value
    if (idField.value && filled.value) return 'derived'
    if (props.schema['x-store'] && isText.value) return 'store'
    // A data setting saved as a list or a group is edited as JSON, never flattened to text.
    if (baseKind.value === 'expr' && props.modelValue !== null && typeof props.modelValue === 'object') return 'json'
    if (isText.value && syntax.value) return syntax.value
    return baseKind.value === 'expr' ? 'template' : baseKind.value
})
// A record setting nobody filled in is left out: there is nothing to show, and it is never typed.
// A required one is the exception: it is picked from the data of an earlier step, which is not typing an id.
const hidden = computed(() => !widget.value && idField.value && !filled.value && !(props.required && syntax.value === 'path'))

const derivedPath = computed(() => {
    const view = syntax.value === 'path' ? readPath(props.modelValue) : null
    return view?.kind === 'chip' ? view.path : ''
})
const derivedText = computed(() => {
    if (derivedPath.value) return stepData.labelFor(derivedPath.value)
    // A record type is stored as a class name: its last word is the only readable part.
    return String(props.modelValue ?? '')
        .split('\\')
        .filter(Boolean)
        .pop()
})
const derivedNote = computed(() => (derivedPath.value ? 'text.workflow.data.derived_note' : 'text.workflow.data.derived_fixed_note'))

/** Settings that hold a paragraph rather than a line. */
const LONG_TEXT = ['instructions', 'system', 'body', 'text', 'message', 'html', 'content', 'policy', 'criteria', 'question']
const longText = computed(
    () => props.schema['x-widget'] === 'textarea' || LONG_TEXT.includes(props.name) || (typeof props.modelValue === 'string' && props.modelValue.includes('\n'))
)
// A repeater row has no name of its own: its fields are looked up under the repeater's name.
const ownScope = computed(() => (props.hideLabel ? props.scope : props.name))
const label = computed(() =>
    props.hideLabel ? '' : authoredFieldText(props.name, props.scope, t, te) || fieldLabel(props.name, props.schema)
)
const hint = computed(() =>
    props.hideLabel ? props.schema.description || '' : authoredFieldText(props.name, props.scope, t, te, '_hint') || props.schema.description || ''
)
const requiredList = computed(() => (Array.isArray(props.schema.required) ? props.schema.required : []))
const isInteger = computed(() => {
    const types = Array.isArray(props.schema.type) ? props.schema.type : [props.schema.type]
    return types.includes('integer') && !types.includes('number')
})

const update = (value) => emit('update:modelValue', value)

const bindName = computed(() => bindingName(props.modelValue))
const toBinding = (value) => bindingValue(value)

// A choice shows its authored wording (`text.workflow.field.<name>_option_<value>`) when the pack has it.
const enumOptions = computed(() =>
    (props.schema.enum || [])
        .filter((value) => value !== null)
        .map((value) => ({
            value,
            label: authoredFieldText(props.name, props.scope, t, te, `_option_${value}`) || String(value),
        }))
)

// AI model picker: the models the site can call, after an empty entry that keeps the AI Runtime setting.
const models = ref([])
const modelsLoading = ref(false)
const modelsFailed = ref(false)
const loadModels = async () => {
    if (modelsLoading.value) return
    modelsLoading.value = true
    modelsFailed.value = false
    try {
        models.value = await fetchSelectableModels()
    } catch (error) {
        modelsFailed.value = true
    } finally {
        modelsLoading.value = false
    }
}
watch(kind, (value) => value === 'model' && !models.value.length && loadModels(), { immediate: true })
const storedModel = computed(() => (typeof props.modelValue === 'string' ? props.modelValue : ''))
/** The saved model is no longer offered (its API key was removed, or the list changed). */
const modelMissing = computed(
    () => storedModel.value !== '' && !modelsLoading.value && !modelsFailed.value && !models.value.some((row) => row.value === storedModel.value)
)
const modelOptions = computed(() => {
    const options = [
        { value: '', label: t('text.workflow.field.model_choice_default') },
        ...models.value.map((row) => ({ value: row.value, label: modelOptionLabel(row) })),
    ]
    // Keep a saved model readable while the list loads or after it left the list.
    if (storedModel.value !== '' && !options.some((option) => option.value === storedModel.value)) {
        options.push({ value: storedModel.value, label: modelOptionLabel({ value: storedModel.value }) })
    }
    return options
})

const objectValue = computed(() =>
    props.modelValue && typeof props.modelValue === 'object' && !Array.isArray(props.modelValue) ? props.modelValue : {}
)
const updateChild = (childName, value) => update({ ...objectValue.value, [childName]: value })

const arrayValue = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []))
const updateItem = (index, value) => update(arrayValue.value.map((item, i) => (i === index ? value : item)))
const removeItem = (index) => update(arrayValue.value.filter((_, i) => i !== index))
const rowsFull = computed(() => Number.isInteger(props.schema.maxItems) && arrayValue.value.length >= props.schema.maxItems)
const addItem = () => update([...arrayValue.value, emptyValue(props.schema.items)])

const jsonText = ref('')
const jsonError = ref(false)
watch(
    () => props.modelValue,
    (value) => {
        if (kind.value !== 'json') return
        jsonText.value = value === undefined || value === null ? '' : JSON.stringify(value, null, 2)
        jsonError.value = false
    },
    { immediate: true, deep: true }
)
const commitJson = () => {
    const text = jsonText.value.trim()
    if (text === '') {
        jsonError.value = false
        update(null)
        return
    }
    try {
        const parsed = JSON.parse(text)
        jsonError.value = false
        update(parsed)
    } catch (error) {
        // Keep the stored value until the text is valid JSON.
        jsonError.value = true
    }
}
</script>
