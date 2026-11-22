<template>
    <a-form :id="formId" :model="localForm" layout="vertical" @finish="emitSubmit">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4">
            <a-form-item
                :label="subjectLabel"
                required
                name="name"
                :rules="requiredRules(subjectLabel)"
            >
                <a-input v-model:value="localForm.name" :placeholder="subjectLabel" />
            </a-form-item>
            <a-form-item
                v-if="showTechnicalFields"
                :label="keyNameLabel"
                name="key_name"
                :rules="requiredRules(keyNameLabel)"
            >
                <a-input
                    v-model:value="localForm.key_name"
                    :disabled="disableKeyName"
                    :placeholder="keyNameLabel"
                />
            </a-form-item>
            <a-form-item v-if="showTechnicalFields" :label="bladeLayoutLabel" name="blade_layout">
                <a-input v-model:value="localForm.blade_layout" :placeholder="bladeLayoutLabel" />
            </a-form-item>
            <select-input-language
                v-if="showTechnicalFields"
                v-model="localForm.language_id"
                :label="languageLabel"
                name="language_id"
                required
            />

            <div v-if="showTechnicalFields" class="flex flex-col w-full mb-3 pb-2">
                <div class="flex">
                    <div class="flex-1">{{ variablesLabel }}</div>
                    <a-button class="btn-succes text-xs" size="small" @click="addVariable">
                        {{ addVariableLabel }}
                    </a-button>
                </div>
                <div
                    v-for="(variable, index) in localForm.variables || []"
                    :key="`${variable.value || 'variable'}-${index}`"
                    class="flex items-center gap-1 pt-2 options-list"
                >
                    <a-form-item no-style :name="['variables', index, 'value']">
                        <a-input
                            v-model:value="variable.value"
                            :placeholder="valueLabel"
                            size="small"
                            required
                            :addon-before="valueLabel"
                        />
                    </a-form-item>
                    <a-form-item no-style :name="['variables', index, 'label']">
                        <a-input
                            v-model:value="variable.label"
                            :placeholder="labelLabel"
                            class="m-0"
                            size="small"
                            :addon-before="labelLabel"
                        />
                    </a-form-item>
                    <a-button
                        danger
                        :disabled="(localForm.variables || []).length < 2"
                        size="small"
                        type="primary"
                        @click="removeVariable(index)"
                    >
                        <i-icon icon="delete" class="size-4" />
                    </a-button>
                </div>
            </div>

            <div v-if="showTechnicalFields" class="flex flex-col w-full mb-3 pb-2">
                <div class="flex">
                    <div class="flex-1">{{ sampleDataLabel }}</div>
                </div>
                <div
                    v-for="(variable, index) in localForm.variables || []"
                    :key="`${variable.value || 'sample'}-${index}`"
                    class="flex items-center gap-1 pt-2 options-list"
                >
                    <a-form-item
                        v-if="variable.value && String(variable.value).trim().length"
                        no-style
                        :name="['sample_data', variable.value]"
                    >
                        <a-input
                            v-model:value="localForm.sample_data[variable.value]"
                            :placeholder="valueLabel"
                            size="small"
                            :addon-before="`{ ${variable.value} }`"
                        />
                    </a-form-item>
                </div>
            </div>

            <div
                v-if="!showTechnicalFields"
                class="col-span-2 mb-3 pb-2 flex flex-wrap text-xs gap-2"
            >
                <template
                    v-for="(variable, index) in localForm.variables || []"
                    :key="`${variable.value}-${index}`"
                >
                    <a-tooltip :title="variable.label">
                        <code class="bg-gray-100 px-1 rounded-sm">
                            {{ '\{\{' }}{{ variable.value }}{{ '\}\}' }}
                        </code>
                    </a-tooltip>
                </template>
            </div>
        </div>

        <div class="col-span-1 md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-x-4">
            <a-form-item :label="contentLabel" name="content" :rules="requiredRules(contentLabel)">
                <a-textarea v-model:value="localForm.content" rows="12" />
                <template v-if="contentHelp" #help>
                    <SafeHtml :html="contentHelp" />
                </template>
            </a-form-item>
            <div>
                <label>{{ previewLabel }}</label>
                <SafeHtml
                    class="mt-2 p-4 rounded-sm bg-gray-50 border shadow-sm overflow-auto"
                    :html="renderedPreview"
                />
            </div>
        </div>
    </a-form>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import SafeHtml from '@/components/common/SafeHtml.vue'

const props = defineProps({
    modelValue: { type: Object, required: true },
    formId: { type: String, default: 'email-template-form' },
    subjectLabel: { type: String, required: true },
    keyNameLabel: { type: String, required: true },
    bladeLayoutLabel: { type: String, required: true },
    languageLabel: { type: String, required: true },
    variablesLabel: { type: String, required: true },
    addVariableLabel: { type: String, required: true },
    sampleDataLabel: { type: String, required: true },
    valueLabel: { type: String, required: true },
    labelLabel: { type: String, required: true },
    contentLabel: { type: String, required: true },
    contentHelp: { type: String, default: '' },
    previewLabel: { type: String, required: true },
    requiredMessage: { type: Function, required: true },
    showTechnicalFields: { type: Boolean, default: true },
    disableKeyName: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'submit'])

const defaultForm = () => ({
    id: 0,
    name: '',
    blade_layout: '',
    group: '',
    key_name: '',
    content: '',
    language_id: 1,
    variables: [],
    sample_data: {},
    description: '',
})

const cloneForm = (source = {}) => ({
    ...defaultForm(),
    ...source,
    variables: Array.isArray(source.variables) ? source.variables.map((item) => ({ ...item })) : [],
    sample_data:
        source.sample_data &&
        typeof source.sample_data === 'object' &&
        !Array.isArray(source.sample_data)
            ? { ...source.sample_data }
            : {},
})

const localForm = reactive(cloneForm(props.modelValue))
let syncingFromParent = false
let syncingToParent = false

const requiredRules = (label) => [
    {
        required: true,
        trigger: 'blur',
        message: props.requiredMessage(label),
    },
]

const normalizeSampleData = () => {
    if (
        !localForm.sample_data ||
        typeof localForm.sample_data !== 'object' ||
        Array.isArray(localForm.sample_data)
    ) {
        localForm.sample_data = {}
    }

    ;['null', 'undefined', ''].forEach((key) => {
        if (key in localForm.sample_data) delete localForm.sample_data[key]
    })
}

const currentVarKeys = () =>
    (localForm.variables || []).map((variable) => (variable?.value ?? '').trim()).filter(Boolean)

const ensureSampleForKey = (key) => {
    if (!key) return
    if (!(key in localForm.sample_data)) localForm.sample_data[key] = ''
}

const removeSampleByKey = (key) => {
    if (!key) return
    if (key in localForm.sample_data) delete localForm.sample_data[key]
}

const syncSampleDataFromVariables = () => {
    normalizeSampleData()

    const keys = new Set(currentVarKeys())

    Object.keys(localForm.sample_data).forEach((key) => {
        if (!keys.has(key)) delete localForm.sample_data[key]
    })
    keys.forEach((key) => ensureSampleForKey(key))
}

const addVariable = () => {
    localForm.variables.push({ label: '', value: '' })
}

const removeVariable = (index) => {
    const item = localForm.variables[index]
    const oldKey = (item?.value || '').trim()
    localForm.variables.splice(index, 1)
    removeSampleByKey(oldKey)
}

const escapeRegExp = (value = '') => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
const extractTemplateVars = (html = '') => {
    const keys = new Set()
    const re = /{{\s*([A-Za-z_][\w.-]*)\s*}}|{\s*([A-Za-z_][\w.-]*)\s*}/g
    let match

    while ((match = re.exec(html)) !== null) {
        const key = (match[1] || match[2] || '').trim()
        if (key) keys.add(key)
    }

    return Array.from(keys)
}
const humanize = (key = '') =>
    key.replace(/[_.-]+/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
const upsertVariablesFromContent = (keysInContent) => {
    const existing = localForm.variables || []
    const keySet = new Set(keysInContent)
    const indexByKey = new Map()

    existing.forEach((variable, index) => {
        const key = (variable?.value || '').trim()
        if (key) indexByKey.set(key, index)
    })

    keysInContent.forEach((key) => {
        if (!key || indexByKey.has(key)) return
        existing.push({ label: humanize(key), value: key, __auto: true })
        ensureSampleForKey(key)
    })

    for (let index = existing.length - 1; index >= 0; index -= 1) {
        const variable = existing[index]
        const key = (variable?.value || '').trim()
        if (variable.__auto && key && !keySet.has(key)) {
            existing.splice(index, 1)
            removeSampleByKey(key)
        }
    }
}

const syncFromContent = () => {
    normalizeSampleData()
    localForm.variables = (localForm.variables || [])
        .map((variable) => ({
            value: (variable?.value || '').toString().trim(),
            label: (variable?.label || '').toString().trim(),
            __auto: variable?.__auto === true,
        }))
        .filter((variable) => variable.value)
    upsertVariablesFromContent(extractTemplateVars(localForm.content || ''))
    syncSampleDataFromVariables()
}

const renderedPreview = computed(() => {
    let html = (localForm.content || '').toString()

    Object.entries(localForm.sample_data || {}).forEach(([key, value]) => {
        if (!key) return
        const escaped = escapeRegExp(key)
        const replacement = String(value ?? '').trim() || `{${key}}`
        html = html.replace(
            new RegExp(`(?:{{\\s*${escaped}\\s*}}|{\\s*${escaped}\\s*})`, 'g'),
            replacement
        )
    })

    return html.replaceAll('\n', '<br/>')
})

const emitUpdate = () => {
    syncingToParent = true
    emit('update:modelValue', cloneForm(localForm))
}

const emitSubmit = (value = {}) => {
    emitUpdate()
    emit('submit', { ...cloneForm(localForm), ...value })
}

watch(
    () => props.modelValue,
    (value) => {
        if (syncingToParent) {
            syncingToParent = false
            return
        }

        syncingFromParent = true
        Object.assign(localForm, cloneForm(value))
        syncFromContent()
        syncingFromParent = false
    },
    { deep: true, immediate: true }
)

watch(
    () => localForm.content,
    () => {
        if (!syncingFromParent) syncFromContent()
    }
)

watch(
    () => localForm.variables,
    () => {
        if (!syncingFromParent) syncSampleDataFromVariables()
    },
    { deep: true }
)

watch(
    localForm,
    () => {
        if (!syncingFromParent) emitUpdate()
    },
    { deep: true }
)
</script>
