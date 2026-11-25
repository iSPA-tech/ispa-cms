<template>
    <a-form :id="formId" :model="localForm" layout="vertical" @finish="emitSubmit">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4">
            <a-form-item
                :label="subjectLabel"
                required
                name="name"
                :rules="requiredRules(subjectLabel)"
                :class="showTechnicalFields ? '' : 'md:col-span-2'"
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
            <!-- Mail class (system / business): shown only to screens that pass the choices. -->
            <a-form-item v-if="mailClassOptions" :label="mailClassLabel" name="mail_class" class="md:col-span-2">
                <a-radio-group v-model:value="localForm.mail_class" class="!flex flex-col gap-2" data-testid="email-template-mail-class">
                    <a-radio v-for="option in mailClassOptions" :key="option.value" :value="option.value" :disabled="!!option.disabled" class="!items-start !whitespace-normal">
                        <span class="block font-medium">{{ option.label }}</span>
                        <span v-if="option.hint" class="block break-words text-xs text-ink-muted">{{ option.hint }}</span>
                    </a-radio>
                </a-radio-group>
                <template v-if="mailClassHelp" #extra>{{ mailClassHelp }}</template>
            </a-form-item>
            <!-- Layout: a pick from the allowed frames when the screen passes them, else the free field of the technical screens. -->
            <a-form-item v-if="layoutOptions" :label="bladeLayoutLabel" name="blade_layout">
                <a-select
                    v-model:value="localForm.blade_layout"
                    :options="layoutOptions"
                    :aria-label="bladeLayoutLabel"
                    data-testid="email-template-layout"
                />
            </a-form-item>
            <a-form-item v-else-if="showTechnicalFields" :label="bladeLayoutLabel" name="blade_layout">
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

            <div v-if="variableList" class="md:col-span-2 mb-3 space-y-2" data-testid="email-template-variable-list">
                <div class="text-sm font-medium">{{ variablesLabel }}</div>
                <p v-if="variableListHint" class="m-0 break-words text-xs text-ink-muted">{{ variableListHint }}</p>
                <ul v-if="variableList.length" class="m-0 list-none space-y-1 p-0">
                    <li v-for="variable in variableList" :key="variable.key" class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-xs">
                        <a-button
                            size="small"
                            class="!h-auto !px-1.5 !py-0 font-mono !text-xs"
                            :title="variableInsertTitle"
                            data-testid="email-template-variable-insert"
                            @click="insertVariable(variable.key)"
                        >
                            {{ '\{\{' }} {{ variable.key }} {{ '\}\}' }}
                        </a-button>
                        <span class="min-w-0 break-words text-ink">{{ variable.description || variable.key }}</span>
                        <span v-if="variable.sample" class="min-w-0 break-words text-ink-muted">({{ variable.sample }})</span>
                    </li>
                </ul>
            </div>
            <div
                v-else-if="!showTechnicalFields"
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

        <div class="col-span-1 md:col-span-2 grid grid-cols-1 gap-x-4" :class="showPreview ? 'md:grid-cols-2' : ''">
            <a-form-item :label="contentLabel" name="content" :rules="requiredRules(contentLabel)">
                <div ref="contentBox" @focusin="contentTouched = true">
                    <a-textarea v-model:value="localForm.content" rows="12" />
                </div>
                <template v-if="contentHelp" #help>
                    <SafeHtml :html="contentHelp" />
                </template>
            </a-form-item>
            <div v-if="showPreview">
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
import { computed, nextTick, reactive, ref, watch } from 'vue'
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
    /** Mail class choices `[{ value, label, hint }]`; the field is shown only when given. */
    mailClassOptions: { type: Array, default: null },
    mailClassLabel: { type: String, default: '' },
    mailClassHelp: { type: String, default: '' },
    /** Allowed mail frames `[{ value, label }]`; when given, the layout is picked from them instead of typed. */
    layoutOptions: { type: Array, default: null },
    /** Variables as the server describes them `[{ key, description, sample }]`; a click inserts one into the content. */
    variableList: { type: Array, default: null },
    variableListHint: { type: String, default: '' },
    variableInsertTitle: { type: String, default: '' },
    /** The quick preview next to the content; a screen with its own preview turns it off. */
    showPreview: { type: Boolean, default: true },
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

/** Put `{{ key }}` where the caret is in the content (at the end when the field was never focused). */
const contentBox = ref(null)
const contentTouched = ref(false)
const insertVariable = (key) => {
    const token = `{{ ${key} }}`
    const field = contentBox.value?.querySelector?.('textarea') || null
    const text = (localForm.content || '').toString()
    // A field that was never focused has its caret at 0; the token then goes to the end.
    const placed = !!field && typeof field.selectionStart === 'number' && contentTouched.value
    const start = placed ? field.selectionStart : text.length
    const end = placed ? field.selectionEnd : text.length
    localForm.content = text.slice(0, start) + token + text.slice(end)
    nextTick(() => {
        if (!field) return
        field.focus()
        field.setSelectionRange(start + token.length, start + token.length)
    })
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
