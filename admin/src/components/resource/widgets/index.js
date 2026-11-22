import { defineAsyncComponent } from 'vue'
import { adminResourceWidgets } from '@/config/admin-modules'
import DisplayText from './display-text.vue'
import DisplayStatus from './display-status.vue'
import DisplayEnum from './display-enum.vue'
import DisplayCount from './display-count.vue'
import DisplayDatetime from './display-datetime.vue'
import DisplayNumber from './display-number.vue'
import DisplayRelation from './display-relation.vue'
import DisplayEnumerate from './display-enumerate.vue'
import DisplayDateRange from './display-date-range.vue'
import DisplayBoolean from './display-boolean.vue'
import InputDate from './input-date.vue'
import InputDatetime from './input-datetime.vue'
import InputNumber from './input-number.vue'
import InputTextarea from './input-textarea.vue'
import InputText from './input-text.vue'
import InputEnum from './input-enum.vue'
import InputBoolean from './input-boolean.vue'
import InputRelation from './input-relation.vue'
import InputEnumerate from './input-enumerate.vue'
import InputSlug from './input-slug.vue'

/** Built-in display widgets by schema field type. */
export const displayWidgets = {
    text: DisplayText,
    textarea: DisplayText,
    email: DisplayText,
    number: DisplayNumber,
    boolean: DisplayBoolean,
    slug: DisplayText,
    status: DisplayStatus,
    enum: DisplayEnum,
    count: DisplayCount,
    date: DisplayDatetime,
    datetime: DisplayDatetime,
    relation: DisplayRelation,
    enumerate: DisplayEnumerate,
    date_range: DisplayDateRange,
}

/** Built-in input widgets by schema field type. */
export const inputWidgets = {
    text: InputText,
    textarea: InputTextarea,
    email: InputText,
    number: InputNumber,
    date: InputDate,
    datetime: InputDatetime,
    enum: InputEnum,
    status: InputEnum,
    boolean: InputBoolean,
    relation: InputRelation,
    enumerate: InputEnumerate,
    slug: InputSlug,
}

/**
 * Build a resolver over a module widget map (`{ '<module>.<widget>': () => import(...) }`).
 *
 * `resolve(typeOrName, { mode })`: `mode` is 'display' (default) or 'input'. A name containing a dot is looked up in the
 * module widgets (wrapped with defineAsyncComponent, cached); anything else is a built-in field type for that mode.
 * Unknown names or types fall back to the plain text display widget and warn only in dev.
 *
 * @param {Record<string, () => Promise<unknown>>} moduleWidgets
 * @returns {(typeOrName: string, options?: { mode?: 'display' | 'input' }) => object}
 */
export function createWidgetResolver(moduleWidgets = {}) {
    const cache = {}

    return function resolveWidget(typeOrName, { mode = 'display' } = {}) {
        const key = String(typeOrName ?? '')
        const loader = key.includes('.') ? moduleWidgets[key] : null

        if (loader) {
            cache[key] ||= defineAsyncComponent(loader)
            return cache[key]
        }

        const builtin = (mode === 'input' ? inputWidgets : displayWidgets)[key]
        if (builtin) return builtin

        if (import.meta.env.DEV) console.warn(`[resource] Unknown ${mode} widget "${key}", rendering plain text.`)
        return DisplayText
    }
}

/** Resolver bound to the widgets registered by admin modules. */
export const resolveWidget = createWidgetResolver(adminResourceWidgets)
