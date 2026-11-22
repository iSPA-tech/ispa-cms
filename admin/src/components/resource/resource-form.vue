<script setup>
/**
 * Generic resource form rendered from a resource schema's form fields. Visible / required_when conditions are
 * evaluated live; hidden fields are neither validated nor submitted. Serves both contexts: create (no `record`) and
 * update (`record` prefills the values and fills `:uuid` in the endpoint, whose method is e.g. PUT). A 422 response is
 * mapped onto the fields; other failures show the server message when there is one. After a successful save the optional
 * `afterHook` runs through the action hooks (a failing hook never undoes the save). Emits `saved(response)` and `cancel`.
 */
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import ajax from '@/ajax'
import { runActionHook, serverMessage } from '@/utils/resource/action-hooks'
import { resolvePath, toAjaxPath } from '@/utils/resource/endpoints'
import { isFieldRequired, isFieldVisible, validateField } from '@/utils/resource/form-validation'
import { getPath, mapErrorKeys, nestPaths, safeEntries } from '@/utils/resource/field-path'
import { customHelp, customLabel, resolveOptions } from '@/utils/resource/custom-fields'
import { resolveWidget } from './widgets'

const props = defineProps({
    /** Schema form fields (`form.<context>.fields`). */
    fields: { type: Array, required: true },
    /** Schema form endpoint `{ path, method }`. */
    endpoint: { type: Object, required: true },
    /** Record to edit; omit for create. */
    record: { type: Object, default: null },
    /** Optional success message translation key from the schema (`form.<context>.success_message`). */
    successMessage: { type: String, default: '' },
    /** Translation key of the client-side required message; empty keeps `text.resource.required`. */
    requiredLabel: { type: String, default: '' },
    /** Submit button text; empty keeps the generic save label. */
    submitLabel: { type: String, default: '' },
    /** Optional client hook name (schema `form.<context>.after`) run once after a successful save. */
    afterHook: { type: String, default: '' },
    /** 'drawer' right-aligns the footer buttons for placement in a drawer; 'inline' keeps them left. */
    mode: { type: String, default: 'inline' },
})
const emit = defineEmits(['saved', 'cancel'])
const { t, locale } = useI18n()
/** Active locale code; specs that mock `useI18n` without a locale fall back to Vietnamese. */
const activeLocale = () => String(locale?.value ?? locale ?? 'vi')
/** Schema fields with unsafe paths (`__proto__`, `constructor`, `prototype`) dropped. */
const allFields = computed(() => safeEntries(props.fields))

/** Starting value of one field: the record value, else the schema default (create forms only), else false for booleans, else null. */
const initialValue = (f) => {
    const stored = getPath(props.record, f.field)
    if (stored !== undefined && stored !== null) return stored
    if (!props.record && f.default !== undefined && f.default !== null) return f.default
    if (f.multiple) return []
    return f.type === 'boolean' ? false : null
}
const initial = () => Object.fromEntries(allFields.value.map((f) => [f.field, initialValue(f)]))
const values = reactive(initial())
const errors = reactive({})
const loading = ref(false)

const label = (value) => (value ? t(value) : '')
/** Label of a field: the literal per-locale text of a custom field, else its translated key. */
const fieldLabel = (f) => customLabel(f, activeLocale()) ?? label(f.label)
/** Literal help text of a custom field. */
const helpOf = (f) => customHelp(f, activeLocale())
const visibleFields = computed(() => allFields.value.filter((f) => isFieldVisible(f, values)))

/** Translated label of the field another relation's endpoint follows (for its "choose X first" hint). */
const parentLabel = (f) => fieldLabel(allFields.value.find((p) => p.field === f.relation?.endpoints_by?.field) || {})

/** Fields passed to widgets, with option labels translated. */
const widgetField = (f) => ({
    ...f,
    options: resolveOptions(f, activeLocale()).map((o) => ({ ...o, label: o.literal ? o.label : label(o.label) })),
    ...(f.relation?.endpoints_by ? { parent_label: parentLabel(f) } : {}),
})

/** Widgets that read sibling values (slug source, dependent relation parent) get the whole form values. */
const needsValues = (f) => f.type === 'slug' || Boolean(f.relation?.depends_on || f.relation?.endpoints_by)

/** Translate a validation failure into text. */
const failureText = ({ code, params }) => (code === 'required' && props.requiredLabel ? t(props.requiredLabel) : t(`text.resource.${code}`, params || {}))

/** Validate visible fields; returns true when all pass. */
function validate() {
    for (const key of Object.keys(errors)) delete errors[key]
    for (const f of visibleFields.value) {
        const failure = validateField(f, values[f.field], values)
        if (failure) errors[f.field] = [failureText(failure)]
    }
    return Object.keys(errors).length === 0
}

/** Submit the visible values; loading stays true on success so the parent can close or remount. */
async function submit() {
    if (loading.value) return
    if (!validate()) return
    loading.value = true
    try {
        // Text is sent trimmed, like the values the required check looked at.
        const payload = nestPaths(Object.fromEntries(visibleFields.value.map((f) => [f.field, typeof values[f.field] === 'string' ? values[f.field].trim() : values[f.field]])))
        const path = toAjaxPath(resolvePath(props.endpoint.path, props.record || {}))
        const res = await ajax.request({ url: path, method: (props.endpoint.method || 'POST').toLowerCase(), data: payload, silent: true })
        message.success(t(props.successMessage || 'text.resource.saved'))
        await runActionHook(props.afterHook, { record: props.record, response: res })
        emit('saved', res)
    } catch (err) {
        const fieldErrors = err?.response?.status === 422 ? err.response.data?.errors : null
        if (fieldErrors && typeof fieldErrors === 'object') {
            // A message whose key has no control on the form (a server rule on another input, or a hidden field) would
            // stay invisible: show those once as a toast so the operator always sees why the save was refused.
            const shown = new Set(visibleFields.value.map((f) => f.field))
            const unplaced = []
            for (const [name, msgs] of Object.entries(mapErrorKeys(fieldErrors, allFields.value.map((f) => f.field)))) {
                if (shown.has(name)) errors[name] = msgs
                else unplaced.push(...msgs)
            }
            if (unplaced.length) message.error([...new Set(unplaced)].join(' '))
        } else {
            // A guard answer such as a 409 "has children" carries its own message; show it rather than the generic text.
            message.error(serverMessage(err) || t('text.resource.save_failed'))
        }
        loading.value = false
    }
}
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="submit">
        <div v-for="f in visibleFields" :key="f.field" class="space-y-1" :data-field="f.field">
            <label class="block text-sm font-medium">
                {{ fieldLabel(f) }}<span v-if="isFieldRequired(f, values)" class="text-red-500"> *</span>
            </label>
            <component
                :is="resolveWidget(f.widget || f.type, { mode: 'input' })"
                v-model="values[f.field]"
                :field="widgetField(f)"
                :record="record || {}"
                :disabled="loading"
                v-bind="needsValues(f) ? { values } : {}"
            />
            <p v-if="helpOf(f)" class="field-help text-xs text-gray-500">{{ helpOf(f) }}</p>
            <p v-for="(msg, i) in errors[f.field] || []" :key="i" class="field-error text-sm text-red-600" role="alert">{{ msg }}</p>
        </div>
        <div class="flex gap-2" :class="mode === 'drawer' ? 'justify-end' : ''">
            <i-button native-type="submit" variant="primary" :disabled="loading" :loading="loading">{{ submitLabel || t('text.resource.save') }}</i-button>
            <i-button :disabled="loading" @click="emit('cancel')">{{ t('text.resource.cancel') }}</i-button>
        </div>
    </form>
</template>
