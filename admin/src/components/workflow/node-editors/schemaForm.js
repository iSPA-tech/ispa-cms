/**
 * Helpers for the generic schema form (JSON-Schema subset used by workflow node definitions).
 * Core schemas are lenient (type unions such as ['integer', 'string', 'null']), so the widget is chosen from the
 * allowed types together with the value already stored.
 */

export const BIND_PREFIX = '$bind:'

const jsTypeOf = (value) => {
    if (value === null || value === undefined) return null
    if (Array.isArray(value)) return 'array'
    if (typeof value === 'number') return 'number'
    return typeof value // string | boolean | object
}

const allowedTypes = (schema) =>
    (Array.isArray(schema?.type) ? schema.type : schema?.type ? [schema.type] : [])
        .filter((type) => type !== 'null')
        .map((type) => (type === 'integer' ? 'number' : type))

/**
 * Widget for a field: binding | expr | model | users | duration | enum | boolean | number | string | object | array | json.
 */
export const fieldKind = (schema = {}, value = undefined) => {
    if (schema['x-secret']) return 'binding'
    if (schema['x-widget'] === 'model') return 'model'
    // People picked by name; a setting that already holds an expression (data from an earlier step) stays one.
    if (schema['x-widget'] === 'users') return typeof value === 'string' && value.trim() !== '' ? 'expr' : 'users'
    if (schema['x-widget'] === 'duration') return 'duration'
    if (schema['x-expr']) return 'expr'
    if (Array.isArray(schema.enum) && schema.enum.length) return 'enum'

    const types = [...new Set(allowedTypes(schema))]
    const hasProperties = schema.properties && typeof schema.properties === 'object'
    if (!types.length) return hasProperties ? 'object' : 'json'
    // "Anything goes" fields are edited as JSON.
    if (types.length > 3) return 'json'

    const current = jsTypeOf(value)
    const type = current && types.includes(current) ? current : types[0]

    if (type === 'object') return hasProperties && Object.keys(schema.properties).length ? 'object' : 'json'
    if (type === 'array') return schema.items && typeof schema.items === 'object' ? 'array' : 'json'
    return type
}

/** `collectionExpr` → "Collection expr"; a schema `title` wins. */
export const fieldLabel = (name, schema = {}) => {
    if (typeof schema.title === 'string' && schema.title !== '') return schema.title
    const words = String(name ?? '')
        .replace(/[_-]+/g, ' ')
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .trim()
        .toLowerCase()
    return words ? words.charAt(0).toUpperCase() + words.slice(1) : ''
}

/**
 * Authored wording for a field, when the admin pack has it: `text.workflow.field.<scope>_<name>` (a field inside a
 * repeater or a group, `scope` = the parent field's name), then `text.workflow.field.<name>`. `suffix` `_hint` reads
 * the help line. Returns '' when there is no row, so the caller falls back to the schema.
 */
export const authoredFieldText = (name, scope, t, te, suffix = '') => {
    if (typeof te !== 'function' || !name) return ''
    const keys = [scope ? `text.workflow.field.${scope}_${name}${suffix}` : '', `text.workflow.field.${name}${suffix}`]
    for (const key of keys) {
        if (!key || !te(key)) continue
        const text = t(key)
        // A pack that echoes the key has no wording for it.
        if (typeof text === 'string' && text !== '' && text !== key) return text
    }
    return ''
}

/**
 * Settings whose shape the designer knows better than a lenient server schema: the rewrite choices of an approval
 * step are rows of { key, label_key, choices: [{ value, label_key }] }, edited as a repeater instead of raw JSON.
 * A schema that already describes the rows is left as it is.
 * - `model_choice`: one of the selectable AI models (a select, never a typed "provider:model").
 * - `reject_reasons`: rows of { key, label } the reviewer picks from when rejecting.
 * - `revision_model_choice`: a plain on/off switch.
 * - `assignees`: the reviewers of the step, picked by name and stored as user uuids (never typed).
 * - `sla`: the target time to decide, a number with a unit, stored as `30m` / `4h` / `2d`.
 * - `expires_after`: how long a person has before the step expires, the same number with a unit.
 */
const text = { type: 'string' }
/** Most reviewers one step may name. */
export const MAX_ASSIGNEES = 10
const KNOWN_SHAPES = {
    model_choice: (schema) => ({ ...schema, 'x-widget': 'model' }),
    revision_model_choice: (schema) => ({ ...schema, type: 'boolean' }),
    reject_reasons: (schema) =>
        schema.items?.properties
            ? schema
            : { ...schema, maxItems: 10, items: { type: 'object', properties: { key: { ...text, maxLength: 40 }, label: text } } },
    max_rounds: (schema) => ({ minimum: 1, maximum: 5, ...schema }),
    assignees: (schema) => ({ maxItems: MAX_ASSIGNEES, ...schema, 'x-widget': 'users' }),
    // The people a notice step tells by name (every admin account may be told; no right to approve needed).
    users: (schema) => ({ maxItems: 20, ...schema, 'x-widget': 'users', 'x-audience': 'notice' }),
    sla: (schema) => ({ ...schema, 'x-widget': 'duration' }),
    expires_after: (schema) => ({ ...schema, 'x-widget': 'duration' }),
    revision_options: (schema) =>
        schema.items?.properties
            ? schema
            : {
                  ...schema,
                  items: {
                      type: 'object',
                      properties: {
                          key: text,
                          label_key: text,
                          choices: { type: 'array', items: { type: 'object', properties: { value: text, label_key: text } } },
                      },
                  },
              },
}

/**
 * The settings of a step type that are shown first, in this order; the rest wait behind "More options".
 * Null for a step type without a list: all its settings are shown.
 */
const AI_PRIMARY = ['instructions', 'text', 'inputs', 'labels', 'schema', 'rubric', 'threshold', 'target_locale', 'style', 'audience', 'length', 'checks', 'policy', 'output', 'model_choice']
const PRIMARY_FIELDS = {
    approval: ['mode', 'reviewer_permission', 'assignees', 'expires_after', 'sla', 'title', 'review', 'editable_fields', 'subject_model', 'subject_id'],
    jev_review: ['criteria', 'content'],
    http_request: ['method', 'url', 'query', 'headers', 'body_type', 'body', 'auth', 'credential'],
    telegram_send: ['credential', 'chat', 'text'],
    email_send: ['credential', 'to', 'subject', 'body'],
}
export const primaryFields = (stepType) => {
    const type = String(stepType || '')
    if (PRIMARY_FIELDS[type]) return PRIMARY_FIELDS[type]
    return /^ai_/.test(type) && type !== 'ai_action' ? AI_PRIMARY : null
}

export const refineFieldSchema = (name, schema = {}) => (KNOWN_SHAPES[name] ? KNOWN_SHAPES[name](schema) : schema)

/** "Provider · model" for a selectable model row; the stored "provider:model" value is never shown as it is. */
export const modelOptionLabel = (row = {}) => {
    const stored = String(row.value ?? '')
    const provider = row.provider_label || row.provider || stored.split(':')[0] || ''
    const model = row.model || stored.split(':').slice(1).join(':')
    const name = provider ? provider.charAt(0).toUpperCase() + provider.slice(1) : ''
    return [name, model].filter(Boolean).join(' · ')
}

/** Empty value for a new array row or a missing nested object. */
export const emptyValue = (schema = {}) => {
    if (schema.default !== undefined) return JSON.parse(JSON.stringify(schema.default))
    const kind = fieldKind(schema)
    if (kind === 'object') {
        return Object.fromEntries(
            Object.entries(schema.properties || {}).map(([key, child]) => [key, emptyValue(child)])
        )
    }
    if (kind === 'array') return []
    if (kind === 'boolean') return false
    if (kind === 'number') return null
    if (kind === 'json') return null
    return ''
}

export const bindingName = (value) =>
    typeof value === 'string' && value.startsWith(BIND_PREFIX) ? value.slice(BIND_PREFIX.length) : ''

export const bindingValue = (name) => {
    const clean = String(name ?? '').trim()
    return clean ? `${BIND_PREFIX}${clean}` : ''
}

/** Seconds per unit of the review deadline (SLA). */
const SLA_UNITS = { m: 60, h: 3600, d: 86400 }
/** Bounds of the review deadline: 5 minutes to 30 days. */
export const SLA_MIN_SECONDS = 300
export const SLA_MAX_SECONDS = 30 * 86400

/**
 * A stored review deadline as the two inputs of the form: `'4h'` → { amount: 4, unit: 'h' }. A number of seconds
 * (older configs) is shown in the largest unit that keeps it whole. Empty or unreadable → { amount: null, unit: 'h' }.
 */
export const parseSla = (value) => {
    const empty = { amount: null, unit: 'h' }
    if (value === null || value === undefined || value === '') return empty
    const match = /^\s*(\d+)\s*([mhd])\s*$/i.exec(String(value))
    if (match) return { amount: Number(match[1]), unit: match[2].toLowerCase() }
    const seconds = Number(value)
    if (!Number.isFinite(seconds) || seconds <= 0) return empty
    if (seconds % SLA_UNITS.d === 0) return { amount: seconds / SLA_UNITS.d, unit: 'd' }
    if (seconds % SLA_UNITS.h === 0) return { amount: seconds / SLA_UNITS.h, unit: 'h' }
    return { amount: Math.max(1, Math.round(seconds / SLA_UNITS.m)), unit: 'm' }
}

/** The stored form of a review deadline: `30m` / `4h` / `2d`; null when no amount is given (nothing is measured). */
export const formatSla = (amount, unit) => {
    const count = Math.round(Number(amount))
    if (amount === null || amount === undefined || amount === '' || !Number.isFinite(count) || count <= 0) return null
    return `${count}${SLA_UNITS[unit] ? unit : 'h'}`
}

/** Whether a stored review deadline is inside the allowed 5 minutes to 30 days (an empty one is fine). */
export const slaInRange = (value) => {
    const { amount, unit } = parseSla(value)
    if (amount === null) return true
    const seconds = amount * SLA_UNITS[unit]
    return seconds >= SLA_MIN_SECONDS && seconds <= SLA_MAX_SECONDS
}
