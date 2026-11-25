<template>
    <div class="space-y-2" data-testid="extract-schema-picker">
        <a-select
            class="w-full"
            show-search
            option-filter-prop="label"
            :value="model"
            :options="modelOptions"
            :loading="modelsLoading"
            :disabled="readOnly"
            :aria-label="t('text.workflow.pick.extract_into')"
            data-testid="extract-schema-model"
            @update:value="chooseModel"
        />
        <p v-if="failed" class="mb-0 break-words text-xs text-amber-700" role="alert" data-testid="extract-schema-failed">{{ t('text.workflow.pick.extract_failed') }}</p>

        <div v-if="fieldsLoading" class="flex items-center gap-2 text-xs text-gray-600"><a-spin size="small" /> {{ t('text.workflow.pick.loading') }}</div>
        <ul v-else class="m-0 grid list-none grid-cols-1 gap-1 p-0 sm:grid-cols-2" data-testid="extract-schema-fields">
            <li v-for="row in rows" :key="row.name" :data-field-name="row.name">
                <a-checkbox :checked="row.on" :disabled="readOnly" @update:checked="() => toggle(row)">
                    <span class="break-words text-sm text-gray-900">{{ row.label }}</span>
                </a-checkbox>
            </li>
        </ul>
        <p v-if="model && !fieldsLoading" class="mb-0 break-words text-xs text-gray-600">{{ t('text.workflow.pick.extract_hint') }}</p>
        <p v-if="!chosen.length" class="mb-0 break-words text-xs text-amber-700" data-testid="extract-schema-empty">{{ t('text.workflow.pick.extract_empty') }}</p>

        <div v-if="adding" class="flex flex-wrap items-center gap-2">
            <a-input
                v-model:value="ownName"
                size="small"
                class="min-w-0 flex-1"
                :placeholder="t('text.workflow.pick.own_field_placeholder')"
                :aria-label="t('text.workflow.pick.own_field')"
                data-testid="extract-schema-own-input"
                @press-enter="addOwn"
            />
            <a-select v-model:value="ownType" size="small" class="w-28" :options="typeOptions" :aria-label="t('text.workflow.pick.own_field_type')" />
            <a-button size="small" type="primary" :disabled="!validOwn" data-testid="extract-schema-own-add" @click="addOwn">{{ t('text.workflow.pick.add') }}</a-button>
        </div>
        <a-button v-else-if="!readOnly" type="link" size="small" class="!h-auto !p-0" data-testid="extract-schema-own" @click="adding = true">
            + {{ t('text.workflow.pick.own_field') }}
        </a-button>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { humanize } from '@/components/workflow/inputs/dataTokens.js'
import { fetchModelList, fetchModelSchema } from '@/services/workflowApi'
import { columnType, extractableFields, readSchemaFields, toggleSchemaField } from './widgetRules.js'

/**
 * What an "AI extract" step looks for: the fields of one of the site's data types, ticked from a list, so the result
 * can be stored straight into such a record. Stored as the JSON schema the engine reads (`schema`); the data type the
 * fields came from is remembered in the schema's `x-model`. A field that was stored stays ticked whatever the list says.
 */
defineOptions({ name: 'ExtractSchemaPicker' })

const props = defineProps({
    modelValue: { type: null, default: null },
    readOnly: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const { t, te } = useI18n()

const chosen = computed(() => readSchemaFields(props.modelValue))
const model = computed(() => (props.modelValue && typeof props.modelValue === 'object' && typeof props.modelValue['x-model'] === 'string' ? props.modelValue['x-model'] : ''))

const models = ref([])
const modelsLoading = ref(false)
const failed = ref(false)
onMounted(async () => {
    modelsLoading.value = true
    try {
        models.value = (await fetchModelList()) || []
    } catch (error) {
        failed.value = true
    } finally {
        modelsLoading.value = false
    }
})
const shortName = (name) => String(name || '').split('\\').filter(Boolean).pop() || ''
const modelOptions = computed(() => {
    const options = models.value.filter((row) => row?.class).map((row) => ({ value: row.class, label: row.label || row.short_name || shortName(row.class) }))
    if (model.value && !options.some((option) => option.value === model.value)) options.push({ value: model.value, label: shortName(model.value) })
    return [...options, { value: '', label: t('text.workflow.pick.extract_own') }]
})

const fields = ref([])
const fieldsLoading = ref(false)
watch(
    model,
    async (name) => {
        fields.value = []
        if (!name) return
        fieldsLoading.value = true
        try {
            const schema = await fetchModelSchema(name)
            if (model.value === name) fields.value = extractableFields(schema?.fields)
        } catch (error) {
            failed.value = true
        } finally {
            fieldsLoading.value = false
        }
    },
    { immediate: true }
)

const fieldLabel = (name) => {
    const key = `text.workflow.field.${name}`
    const text = te(key) ? t(key) : ''
    return typeof text === 'string' && text && text !== key ? text : humanize(name)
}
const rows = computed(() => {
    const list = fields.value.map((field) => ({ name: field.name, type: columnType(field.type), label: field.label || fieldLabel(field.name) }))
    chosen.value.forEach((field) => {
        if (!list.some((row) => row.name === field.name)) list.push({ name: field.name, type: field.type, label: fieldLabel(field.name) })
    })
    return list.map((row) => ({ ...row, on: chosen.value.some((field) => field.name === row.name) }))
})

const base = () => (props.modelValue && typeof props.modelValue === 'object' && !Array.isArray(props.modelValue) ? props.modelValue : { type: 'object', properties: {} })
const toggle = (row) => !props.readOnly && emit('update:modelValue', toggleSchemaField(base(), row.name, row.type))
/** Another data type: the fields already ticked stay; only where the list comes from changes. */
const chooseModel = (name) => {
    if (props.readOnly) return
    const next = { ...base(), type: 'object' }
    if (!next.properties || typeof next.properties !== 'object' || Array.isArray(next.properties)) next.properties = {}
    if (name) next['x-model'] = name
    else delete next['x-model']
    emit('update:modelValue', next)
}

const adding = ref(false)
const ownName = ref('')
const ownType = ref('string')
const typeOptions = computed(() => ['string', 'number', 'integer', 'boolean', 'array'].map((value) => ({ value, label: t(`text.workflow.pick.type_${value}`) })))
const validOwn = computed(() => /^[A-Za-z_][A-Za-z0-9_]*$/.test(ownName.value.trim()) && !chosen.value.some((field) => field.name === ownName.value.trim()))
const addOwn = () => {
    if (!validOwn.value) return
    emit('update:modelValue', toggleSchemaField(base(), ownName.value.trim(), ownType.value))
    ownName.value = ''
    adding.value = false
}
</script>
