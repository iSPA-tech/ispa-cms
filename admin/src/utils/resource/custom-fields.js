/**
 * Custom field helpers for the generic resource renderer: literal per-locale labels, help text, option labels and the
 * list-filter slots of custom filters. Pure helpers; components translate keys (utils stay i18n-free).
 */
const LOCALES = ['vi', 'en']

/**
 * Pick text from a `{ vi, en }` map: the active locale, then the other language.
 *
 * @param {Record<string, string>|null|undefined} map
 * @param {string} locale Active locale code.
 * @returns {string} Empty when neither language has text.
 */
export function pickLocaleText(map, locale) {
    if (!map || typeof map !== 'object') return ''
    const order = [locale, ...LOCALES.filter((code) => code !== locale)]
    for (const code of order) {
        const text = map[code]
        if (typeof text === 'string' && text.trim() !== '') return text
    }
    return ''
}

/**
 * Literal label of a schema entry, or null when the entry is not custom (the caller translates `label`).
 * A custom entry without any literal text falls back to its field key (the part after `custom_fields.`).
 *
 * @param {{custom?: boolean, label_text?: object, field?: string}} entry Schema field, column, section or filter.
 * @param {string} locale
 * @returns {string|null}
 */
export function customLabel(entry, locale) {
    if (!entry?.custom) return null
    return pickLocaleText(entry.label_text, locale) || String(entry.field ?? '').replace(/^custom_fields\./, '')
}

/**
 * Literal help text of a custom entry (`help_text`, or `help` as an older schema wrote it); empty when none.
 *
 * @param {object} entry
 * @param {string} locale
 * @returns {string}
 */
export function customHelp(entry, locale) {
    if (!entry?.custom) return ''
    const source = entry.help_text ?? entry.help
    return typeof source === 'string' ? source : pickLocaleText(source, locale)
}

/**
 * Options of an entry with literal labels resolved: an option with `label_text` shows it, others keep `label`
 * (the caller translates that key).
 *
 * @param {object} entry
 * @param {string} locale
 * @returns {Array<{value: *, label: string, literal: boolean}>}
 */
export function resolveOptions(entry, locale) {
    return (entry?.options || []).map((option) => {
        const text = pickLocaleText(option.label_text, locale)
        return text ? { ...option, label: text, literal: true } : { ...option, literal: false }
    })
}

/**
 * Query-state slots of a list filter. A plain filter has one slot named after its field; a `multiple` filter one list
 * slot; a `range` filter two (`<field>:from`, `<field>:to`).
 *
 * @param {{field: string, range?: boolean, multiple?: boolean}} filter
 * @returns {Array<{key: string, part: 'value'|'from'|'to', kind: 'string'|'list'}>}
 */
export function filterSlots(filter) {
    if (filter.range) {
        return [
            { key: `${filter.field}:from`, part: 'from', kind: 'string' },
            { key: `${filter.field}:to`, part: 'to', kind: 'string' },
        ]
    }
    return [{ key: filter.field, part: 'value', kind: filter.multiple ? 'list' : 'string' }]
}

/**
 * Request params of one filter: the schema `param` (`cf[<key>]`) or the field name; a range sends `[from]` / `[to]`,
 * a multiple filter an array. Empty values send nothing.
 *
 * @param {object} filter Schema filter entry.
 * @param {Record<string, *>} values Slot values keyed by slot key.
 * @returns {Record<string, *>}
 */
export function filterParams(filter, values) {
    const base = filter.param || filter.field
    const params = {}
    for (const slot of filterSlots(filter)) {
        const value = values[slot.key]
        const empty = value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)
        if (empty) continue
        params[slot.part === 'value' ? base : `${base}[${slot.part}]`] = value
    }
    return params
}
