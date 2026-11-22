import { cloneDeep, isEmpty } from 'lodash-es'

export const cloneWidgetDraft = (value) => cloneDeep(value)

export const widgetSnapshot = (value) => JSON.stringify(cloneDeep(value ?? {}))

export const isCurrentPreviewRequest = (
    sequence,
    currentSequence,
    requestSnapshot,
    currentSnapshot
) => sequence === currentSequence && requestSnapshot === currentSnapshot

export const removeWidgetListItem = (values = {}, name = '', index = -1) => {
    const nextValues = cloneDeep(values)
    const items = nextValues[name]

    if (!Array.isArray(items) || index < 0 || index >= items.length) {
        return nextValues
    }

    items.splice(index, 1)

    return nextValues
}

export const hasWidgetInputData = (values = {}) =>
    Object.values(values).some((value) => {
        if (Array.isArray(value)) return value.length > 0
        if (value && typeof value === 'object') return Object.keys(value).length > 0
        if (typeof value === 'boolean') return value

        return value !== null && value !== undefined && String(value).trim() !== ''
    })

// -----------------------------------------------------------------------------
// Chunk 3 Task 28: pure helpers shared by BOTH the legacy Page Widget editor
// (i-content-widget-v2.vue) and widget-input-fields.vue (the new shared typed
// field renderer also used by the Site Layout Placement Inspector), so a
// Widget/Template (or Widget/Theme) definition switch applies the IDENTICAL
// compatible-value-preservation, defaulting, sorting, and labeling rules in
// both places. Extracted verbatim from i-content-widget-v2.vue's original
// resetInputsByTemplate()/inputLabel() -- byte-identical output, not a
// redesign.
// -----------------------------------------------------------------------------

/** Input types whose "empty" value is an array (media/choice/list controls)
 * rather than a string. */
const EMPTY_ARRAY_INPUT_TYPES = ['image', 'upload', 'checkbox', 'radio', 'select', 'list', 'repeater']

/** Input types rendered LAST by sortWidgetInputs, regardless of name. */
const LATE_INPUT_TYPES = ['image', 'upload', 'textarea']

/** The zero-value for a freshly-added/reset typed input, by its `type`. */
export const emptyValueForType = (type) => (EMPTY_ARRAY_INPUT_TYPES.includes(type) ? [] : '')

/**
 * Computes the `values` object for a new set of typed `inputs` (e.g. after a
 * Widget/Template or Widget/Theme definition switch): for each input, carries
 * over a non-empty value already present in `currentValue`, else falls back
 * to `originalValue` (the baseline before any in-progress edits), else
 * defaults by type. An input name absent from `inputs` is never carried over.
 */
export const resolveInputValues = (inputs = [], currentValue = {}, originalValue = {}) => {
    const parsedValues = {}

    inputs.forEach((item) => {
        const existingValue = currentValue[item.name] ?? originalValue[item.name]
        parsedValues[item.name] =
            existingValue !== undefined && !isEmpty(existingValue) ? existingValue : emptyValueForType(item.type)
    })

    return parsedValues
}

/**
 * Sorts a COPY of `inputs` alphabetically by name, then re-sorts so
 * image/upload/textarea types always render last -- mirrors the original
 * two-pass sort exactly (including its quirky non-transitive comparator for
 * two "late" types, whose relative order then depends on sort stability),
 * rather than a mutation of the caller's array.
 */
export const sortWidgetInputs = (inputs = []) => {
    const sorted = [...inputs]
    sorted.sort((a, b) => a.name.localeCompare(b.name))
    sorted.sort((a, b) => {
        if (LATE_INPUT_TYPES.includes(a.type)) return 1
        if (LATE_INPUT_TYPES.includes(b.type)) return -1
        return a.name.localeCompare(b.name)
    })
    return sorted
}

/**
 * Formats a typed input's display label: prefers `item.label`, then
 * `item.title`, then a title-cased `item.name`; falls back to
 * `unknownLabel` (the caller's OWN `t('text.unknown')` translation, kept out
 * of this i18n-free pure function) when none is set. By default appends the
 * input's `{NAME}` token in upper-case when a name exists; pass
 * `withToken: false` for a clean human label (the renderer shows the token
 * as its own de-emphasized hint instead of baking it into the label).
 */
export const formatInputLabel = (
    item = {},
    unknownLabel = '',
    { withToken = true, translate = null } = {}
) => {
    const label = resolveInputLabelText(item, translate) || unknownLabel
    const token = withToken ? formatInputToken(item) : ''

    return token ? `${label} · ${token}` : label
}

/**
 * Human text of a typed input. With a caller-supplied `translate(key)` (returns the
 * translation, or a falsy value / the key itself when none exists) an explicit
 * `label` / `title` that is a translation key resolves first, then the common field
 * label `validation.attributes.<name>` (so an input named `title` reads "Tiêu đề"
 * instead of the raw word "Title"). Without `translate`, or when nothing resolves,
 * the label is the title-cased `label` / `title` / `name`. A label stored with the
 * input definition (`options.label`, e.g. set by a project's widget seeder) counts as
 * an explicit label.
 */
const resolveInputLabelText = (item = {}, translate = null) => {
    const explicit = item.label || item.title || item.options?.label
    const tryKey = (key) => {
        if (typeof translate !== 'function' || !key) return ''
        const text = translate(key)

        return text && text !== key ? String(text) : ''
    }

    if (explicit && String(explicit).includes('.')) {
        const translated = tryKey(String(explicit))
        if (translated) return translated
    }
    if (!explicit && item.name) {
        const translated = tryKey(`validation.attributes.${String(item.name).replace(/-/g, '_')}`)
        if (translated) return translated
        // Numbered repeater fields ("title_2") reuse the base field's label plus the number.
        const numbered = String(item.name).match(/^(.+?)[_-](\d+)$/)
        if (numbered) {
            const base = tryKey(`validation.attributes.${numbered[1].replace(/-/g, '_')}`)
            if (base) return `${base} ${numbered[2]}`
        }
    }

    const rawName = String(explicit || item.name || '').replace(/[_-]+/g, ' ')

    return rawName ? rawName.charAt(0).toUpperCase() + rawName.slice(1) : ''
}

/** The `{NAME}` template token an input substitutes into the theme markup. */
export const formatInputToken = (item = {}) =>
    item.name ? `{${String(item.name).toUpperCase()}}` : ''
