/**
 * Pure client-side validation for resource form fields. Returns message codes, never translated text, so callers
 * translate them (utils stay i18n-free).
 */
import { evaluateCondition } from './conditions'

/** Whether a value counts as blank for the required check (text of spaces only is blank). */
const isBlank = (v) => v === null || v === undefined || (typeof v === 'string' && v.trim() === '') || (Array.isArray(v) && v.length === 0)

/**
 * Whether a field is visible for the given values. Fields without a `visible` condition are always visible.
 *
 * @param {object} field Schema form field.
 * @param {object} values Current form values.
 * @returns {boolean}
 */
export function isFieldVisible(field, values) {
    return field.visible ? evaluateCondition(field.visible, values) : true
}

/**
 * Whether a field is required: statically (`rules.required`) or through its `required_when` condition.
 *
 * @param {object} field Schema form field.
 * @param {object} values Current form values.
 * @returns {boolean}
 */
export function isFieldRequired(field, values) {
    return Boolean(field.rules?.required) || (field.required_when ? evaluateCondition(field.required_when, values) : false)
}

/**
 * Validate one field value against its rules.
 *
 * @param {object} field Schema form field.
 * @param {*} value Current value.
 * @param {object} values All form values.
 * @returns {{ code: 'required'|'max_length'|'min_length'|'invalid', params?: object }|null} First failure or null.
 */
export function validateField(field, value, values) {
    if (isBlank(value)) {
        return isFieldRequired(field, values) ? { code: 'required' } : null
    }
    const rules = field.rules || {}
    const isNumber = field.type === 'number'
    if (!isNumber && typeof value === 'string') {
        if (rules.max != null && value.length > rules.max) return { code: 'max_length', params: { max: rules.max } }
        if (rules.min != null && value.length < rules.min) return { code: 'min_length', params: { min: rules.min } }
    }
    if (rules.pattern && typeof value === 'string') {
        try {
            const match = String(rules.pattern).match(/^\/(.*)\/([a-z]*)$/s)
            const re = match ? new RegExp(match[1], match[2].replace(/[^dgimsuy]/g, '')) : new RegExp(rules.pattern)
            if (!re.test(value)) return { code: 'invalid' }
        } catch {
            // An unparsable pattern is left to the server.
        }
    }
    return null
}
