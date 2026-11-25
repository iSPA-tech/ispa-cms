/**
 * Rules of the "pick, don't type" widgets of the workflow step form, kept free of Vue so they can be tested on their
 * own. Every widget reads the setting exactly as the engine stores it and writes it back in the same shape; a setting
 * that is opened and not touched is never rewritten.
 */

// ---------------------------------------------------------------------------------------------------------------------
// Which widget a setting uses
// ---------------------------------------------------------------------------------------------------------------------

// A node type a module adds for its own judge step (`<module>.jev_review`) shares one rule set, whatever the module.
const MODULE_JEV_REVIEW = 'module.jev_review'
const typeKey = (stepType) => {
    const type = String(stepType || '')
    return /^[a-z0-9_]+\.jev_review$/.test(type) ? MODULE_JEV_REVIEW : type
}

const WIDGETS = {
    approval: { editable_fields: 'editable_fields', reviewer_permission: 'permission' },
    ask_and_wait: { reviewer_permission: 'permission' },
    notify: { permission: 'permission' },
    jev_review: { criteria: 'jev_criteria' },
    ai_extract: { schema: 'extract_schema' },
    ai_classify: { labels: 'classify_labels' },
    ai_score: { rubric: 'rubric' },
    http_request: { headers: 'name_values', query: 'name_values' },
    switch: { cases: 'switch_cases' },
    [MODULE_JEV_REVIEW]: { criteria: 'jev_criteria' },
}

/** Settings another widget of the same step writes, so they are not shown as fields of their own. */
const HIDDEN = {
    jev_review: ['criteria_preset', 'criteria_items', 'min_confidence'],
    [MODULE_JEV_REVIEW]: ['criteria_preset', 'criteria_items'],
}

/**
 * Step types whose judge always checks the same thing: the set of criteria is shown, and cannot be changed (a module
 * node can ask one fixed evidence question whatever is ticked).
 */
const FIXED_CRITERIA = [MODULE_JEV_REVIEW]
export const hasFixedCriteria = (stepType) => FIXED_CRITERIA.includes(typeKey(stepType))

const isAiStep = (type) => /^ai_/.test(type) && type !== 'ai_action' && type !== 'ai_reasoning'

/** Widget of a top-level setting of a step type, or '' for the plain schema field. A schema may name one (`x-widget`). */
export const widgetFor = (stepType, name, schema = {}) => {
    const type = typeKey(stepType)
    const hinted = schema?.['x-widget']
    if (['switch_cases', 'permission', 'name_values', 'rubric', 'classify_labels', 'extract_schema', 'jev_criteria', 'editable_fields', 'named_inputs'].includes(hinted)) return hinted
    if (WIDGETS[type]?.[name]) return WIDGETS[type][name]
    if (name === 'inputs' && isAiStep(type)) return 'named_inputs'
    return ''
}

export const isHiddenField = (stepType, name) => (HIDDEN[typeKey(stepType)] || []).includes(name)

// ---------------------------------------------------------------------------------------------------------------------
// JEV criteria: a ready-made set, each criterion on or off, own lines after them
// ---------------------------------------------------------------------------------------------------------------------

/** How strict the judge is, stored as `min_confidence`. */
export const STRICTNESS = [
    { key: 'easy', value: 0.6 },
    { key: 'medium', value: 0.8 },
    { key: 'strict', value: 0.9 },
]

export const strictnessOf = (value) => {
    const number = Number(value)
    if (value === null || value === undefined || value === '' || !Number.isFinite(number)) return ''
    return STRICTNESS.find((row) => Math.abs(row.value - number) < 1e-9)?.key || ''
}

const lines = (text) =>
    String(text ?? '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean)

/**
 * The stored settings as the widget shows them: `{ presetKey, itemKeys, custom }`.
 * - a stored `criteria_items` list wins; without one, an item is on when its text is one of the `criteria` lines
 * - `custom`: the `criteria` lines that are not the text of an item of the set (all lines when no set is chosen)
 */
export const readCriteria = (config = {}, presets = []) => {
    const preset = presets.find((row) => row.key === config.criteria_preset) || null
    const text = lines(config.criteria)
    if (!preset) return { presetKey: '', itemKeys: [], custom: text }
    const items = Array.isArray(preset.items) ? preset.items : []
    const texts = new Map(items.map((item) => [String(item.text ?? '').trim(), item.key]))
    const itemKeys = Array.isArray(config.criteria_items)
        ? config.criteria_items.filter((key) => items.some((item) => item.key === key))
        : items.filter((item) => text.includes(String(item.text ?? '').trim())).map((item) => item.key)
    return { presetKey: preset.key, itemKeys, custom: text.filter((line) => !texts.has(line)) }
}

/**
 * The settings to store for a choice: `criteria` is the text the judge reads (one line per chosen criterion, in the
 * order of the set, then the operator's own lines); `criteria_preset` / `criteria_items` remember the choice and are
 * removed (`undefined`) when no set is chosen.
 */
export const writeCriteria = ({ presetKey = '', itemKeys = [], custom = [] } = {}, presets = []) => {
    const preset = presets.find((row) => row.key === presetKey) || null
    const own = custom.map((line) => String(line ?? '').trim()).filter(Boolean)
    if (!preset) return { criteria: own.join('\n'), criteria_preset: undefined, criteria_items: undefined }
    const chosen = (Array.isArray(preset.items) ? preset.items : []).filter((item) => itemKeys.includes(item.key))
    return {
        criteria: [...chosen.map((item) => String(item.text ?? '').trim()).filter(Boolean), ...own].join('\n'),
        criteria_preset: preset.key,
        criteria_items: chosen.map((item) => item.key),
    }
}

/** The criteria a set starts with: the ones it marks `default`, or all of them when it marks none. */
export const defaultItemKeys = (preset) => {
    const items = Array.isArray(preset?.items) ? preset.items : []
    const marked = items.filter((item) => item.default === true)
    return (marked.length ? marked : items).map((item) => item.key)
}

// ---------------------------------------------------------------------------------------------------------------------
// Branches of a "switch" step
// ---------------------------------------------------------------------------------------------------------------------

/** Stored cases of a switch as rows `{ key, match }` (a row keeps every other key it was stored with). */
export const readCases = (stored) => (Array.isArray(stored) ? stored.filter((row) => row && typeof row === 'object' && String(row.key ?? '').trim() !== '') : [])

/** Whether a case answers to `value` (its `match` is the value, or a list that holds it). */
const caseMatches = (row, value) => (Array.isArray(row.match) ? row.match.map(String).includes(String(value)) : String(row.match ?? '') === String(value))
export const caseFor = (stored, value) => readCases(stored).find((row) => caseMatches(row, value)) || null

/**
 * The cases with the branch for `value` switched on or off. A new branch is `{ key: value, match: value }`, so its
 * output on the canvas is named after the value; every other case is kept as it was stored.
 */
export const toggleCase = (stored, value) => {
    const list = Array.isArray(stored) ? stored : []
    const found = caseFor(list, value)
    return found ? list.filter((row) => row !== found) : [...list, { key: String(value), match: value }]
}

// ---------------------------------------------------------------------------------------------------------------------
// Name / value rows (`headers`, `query`, AI `inputs`)
// ---------------------------------------------------------------------------------------------------------------------

/** Header names offered first; any other name can still be typed. */
export const COMMON_HEADERS = ['Accept', 'Accept-Language', 'Authorization', 'Cache-Control', 'Content-Type', 'If-None-Match', 'User-Agent', 'X-Api-Key', 'X-Requested-With']

/**
 * A stored map as rows `{ name, value }`. The engine reads `{ name: value }`; a list of `{ name, value }` rows
 * (AI inputs may be stored that way) is read too.
 */
export const readPairs = (stored) => {
    if (Array.isArray(stored)) {
        return stored.filter((row) => row && typeof row === 'object' && 'name' in row).map((row) => ({ ...row, name: String(row.name ?? '') }))
    }
    if (stored && typeof stored === 'object') return Object.entries(stored).map(([name, value]) => ({ name, value }))
    return []
}

/**
 * Rows back to the stored shape: a list stays a list, anything else is a `{ name: value }` map (rows without a name are
 * left out; a later row with the same name wins). `emptyAs` is what no rows are stored as (the shape that was stored).
 */
export const writePairs = (rows = [], stored = null) => {
    const named = rows.filter((row) => String(row?.name ?? '').trim() !== '')
    if (Array.isArray(stored) && stored.some((row) => row && typeof row === 'object' && 'name' in row)) {
        return named.map((row) => ({ ...row, name: String(row.name).trim() }))
    }
    if (!named.length) return Array.isArray(stored) ? [] : stored === null || stored === undefined ? null : {}
    return Object.fromEntries(named.map((row) => [String(row.name).trim(), row.value ?? '']))
}

/** An AI input value as the form shows it: `{ value, trusted }` (a plain text is an untrusted value). */
export const readInput = (stored) =>
    stored && typeof stored === 'object' && !Array.isArray(stored)
        ? { value: stored.value ?? '', trusted: stored.trusted === true || stored.trusted === 'true' || stored.trusted === 1 || stored.trusted === '1' }
        : { value: stored ?? '', trusted: false }

/** The stored form of an AI input: the plain text while it is untrusted and was not stored as a group. */
export const writeInput = ({ value, trusted }, stored = undefined) => {
    const wasGroup = stored && typeof stored === 'object' && !Array.isArray(stored)
    if (!trusted && !wasGroup) return value ?? ''
    return { ...(wasGroup ? stored : {}), value: value ?? '', trusted: !!trusted }
}

// ---------------------------------------------------------------------------------------------------------------------
// AI classify labels
// ---------------------------------------------------------------------------------------------------------------------

/** Stored labels as rows `{ key, label }`: `[{ key, description }]`, a list of keys, or `{ key: description }`. */
export const readLabels = (stored) => {
    const rows = Array.isArray(stored)
        ? stored.map((row) => (row && typeof row === 'object' ? { key: row.key, label: row.description || row.label || '' } : { key: row, label: '' }))
        : stored && typeof stored === 'object'
          ? Object.entries(stored).map(([key, description]) => ({ key, label: typeof description === 'string' ? description : '' }))
          : []
    return rows.map((row) => ({ key: String(row.key ?? '').trim(), label: String(row.label ?? '') })).filter((row) => row.key)
}

/**
 * Labels with `key` switched on or off, in the stored shape: a row that was stored keeps its exact value; a new one is
 * `{ key, description }` when it has wording (so the model reads the name, not only the key), else the bare key.
 */
export const toggleLabel = (stored, key, label = '') => {
    const clean = String(key ?? '').trim()
    if (!clean) return stored
    const keyOf = (row) => String((row && typeof row === 'object' ? row.key : row) ?? '').trim()
    if (stored && typeof stored === 'object' && !Array.isArray(stored)) {
        const next = { ...stored }
        if (clean in next) delete next[clean]
        else next[clean] = label || ''
        return next
    }
    const list = Array.isArray(stored) ? stored : []
    if (list.some((row) => keyOf(row) === clean)) return list.filter((row) => keyOf(row) !== clean)
    return [...list, label && label !== clean ? { key: clean, description: label } : clean]
}

/** The set whose items cover every stored label (the first one), or '' when the labels are the operator's own. */
export const labelSourceOf = (stored, presets = []) => {
    const keys = readLabels(stored).map((row) => row.key)
    if (!keys.length) return ''
    return presets.find((preset) => keys.every((key) => (preset.items || []).some((item) => String(item.key) === key)))?.key || ''
}

// ---------------------------------------------------------------------------------------------------------------------
// AI extract: fields of a site data type
// ---------------------------------------------------------------------------------------------------------------------

/** JSON-schema type of a model column. */
const COLUMN_TYPES = { string: 'string', text: 'string', char: 'string', date: 'string', datetime: 'string', timestamp: 'string', uuid: 'string', integer: 'integer', int: 'integer', bigint: 'integer', smallint: 'integer', tinyint: 'integer', float: 'number', double: 'number', decimal: 'number', number: 'number', boolean: 'boolean', bool: 'boolean', json: 'object', array: 'array', object: 'object' }
export const columnType = (type) => COLUMN_TYPES[String(type || '').toLowerCase()] || 'string'

/** Columns the site fills in by itself: never offered as something to extract. */
const SYSTEM_COLUMN = /^(id|uuid|created_at|updated_at|deleted_at|created_by|updated_by|deleted_by|password|remember_token)$|_(id|uuid)$/
export const extractableFields = (fields = []) => (Array.isArray(fields) ? fields : []).filter((field) => field?.name && !SYSTEM_COLUMN.test(field.name))

/** Properties of a stored extract schema as rows `{ name, type }`; a schema whose properties are a list has none. */
export const readSchemaFields = (schema) => {
    const properties = schema && typeof schema === 'object' && !Array.isArray(schema) ? schema.properties : null
    if (!properties || typeof properties !== 'object' || Array.isArray(properties)) return []
    return Object.entries(properties).map(([name, child]) => {
        const types = (Array.isArray(child?.type) ? child.type : [child?.type]).filter((type) => type && type !== 'null')
        return { name, type: types[0] || 'string' }
    })
}

/** The schema with `name` switched on (as `type`) or off; every other key of the schema and of its fields is kept. */
export const toggleSchemaField = (schema, name, type = 'string') => {
    const base = schema && typeof schema === 'object' && !Array.isArray(schema) ? { ...schema } : { type: 'object' }
    const properties = base.properties && typeof base.properties === 'object' && !Array.isArray(base.properties) ? { ...base.properties } : {}
    if (name in properties) {
        delete properties[name]
        if (Array.isArray(base.required)) base.required = base.required.filter((key) => key !== name)
    } else {
        properties[name] = { type }
    }
    return { ...base, type: base.type || 'object', properties }
}

// ---------------------------------------------------------------------------------------------------------------------
// Editable fields of an approval
// ---------------------------------------------------------------------------------------------------------------------

/** Parts of a result a reviewer would not rewrite (ids, states, technical marks). */
const NOT_EDITABLE = /(^|_)(id|uuid|status|version|error|mode|model|pending|reused|url|slug)$|^(review|tainted|cost_usd|quality_flags|sources)$/

/**
 * What a reviewer may be allowed to edit: the text parts of the step whose result the approval reviews
 * (`review: vars.draft.review` reads the step that stores `vars.draft`). Returns `{ group, items }`; `group` is null
 * when no step stores that data.
 */
export const editableCandidates = (reviewPath, groups = []) => {
    const path = String(reviewPath || '').replace(/^\{\{\s*|\s*\}\}$/g, '')
    let best = null
    groups.forEach((group) =>
        group.items.forEach((item) => {
            if (item.role !== 'result') return
            if (path !== item.path && !path.startsWith(`${item.path}.`)) return
            if (!best || item.path.length > best.store.length) best = { group, store: item.path }
        })
    )
    if (!best) return { group: null, items: [] }
    const items = best.group.items
        .filter((item) => item.role === 'part' && item.path.startsWith(`${best.store}.`))
        .map((item) => ({ ...item, key: item.path.slice(best.store.length + 1) }))
        .filter((item) => ['text', 'empty', 'data'].includes(item.type) && !item.key.includes('.') && !NOT_EDITABLE.test(item.key))
    return { group: best.group, items }
}

// ---------------------------------------------------------------------------------------------------------------------
// Conditions of a yes / no step
// ---------------------------------------------------------------------------------------------------------------------

/** Comparisons offered for each kind of data (DecisionRunner::OPERATORS). */
export const OPERATORS_BY_KIND = {
    choice: ['eq', 'ne'],
    boolean: ['is_true', 'is_false'],
    number: ['eq', 'ne', 'gt', 'gte', 'lt', 'lte'],
    text: ['eq', 'ne', 'contains', 'not_contains', 'empty', 'not_empty'],
    list: ['not_empty', 'empty', 'contains'],
    data: ['eq', 'ne', 'not_empty', 'empty'],
}
/** Comparisons that need no value. */
export const UNARY_OPERATORS = ['empty', 'not_empty', 'is_true', 'is_false']

/** Kind of comparison a picked piece of data takes: one of a known set of values, else by its type. */
export const conditionKind = (item) => {
    if (item?.values?.length) return 'choice'
    if (item?.type === 'boolean' || item?.type === 'number' || item?.type === 'list') return item.type
    return item?.type === 'text' || item?.type === 'empty' ? 'text' : 'data'
}

/** The rows of a decision: its stored `conditions`, or none when it holds a typed expression. */
export const readConditions = (config = {}) =>
    Array.isArray(config.conditions) ? config.conditions.filter((row) => row && typeof row === 'object').map((row) => ({ ...row })) : []

/** Whether the decision is still a typed expression (older workflows): shown as it is until the operator rebuilds it. */
export const isTypedCondition = (config = {}) => {
    if (readConditions(config).length) return false
    const text = String(config.expression ?? config.condition ?? '').trim()
    return text !== '' && text !== 'true'
}

/** The settings of a decision built from rows. The typed expression is removed: the engine reads the rows first. */
export const writeConditions = (rows = [], combinator = 'and') => ({
    conditions: rows.map((row) => {
        const next = { left: row.left, operator: row.operator || 'eq' }
        if (UNARY_OPERATORS.includes(next.operator)) return next
        if (typeof row.rightExpr === 'string' && row.rightExpr.trim() !== '') return { ...next, rightExpr: row.rightExpr }
        return { ...next, right: row.right ?? '' }
    }),
    combinator: combinator === 'or' ? 'or' : 'and',
    expression: undefined,
    condition: undefined,
})

/**
 * Ready questions for a yes / no step, from the data of the steps before it: one per known value of a part
 * ("Verdict is Pass") and one per yes / no part. Returns `[{ key, left, operator, right?, item, group, value? }]`.
 */
export const commonQuestions = (groups = [], limit = 8) => {
    const questions = []
    groups
        .filter((group) => !group.later && group.key !== 'root')
        .forEach((group) =>
            group.items.forEach((item) => {
                if (item.role !== 'part') return
                if (item.values?.length) {
                    item.values.forEach((value) => questions.push({ key: `${item.path}=${value.value}`, left: item.path, operator: 'eq', right: value.value, item, group, value }))
                } else if (item.type === 'boolean') {
                    questions.push({ key: `${item.path}?`, left: item.path, operator: 'is_true', item, group })
                }
            })
        )
    // The steps right before this one are the ones a question is most often about.
    return questions.reverse().slice(0, limit).reverse()
}
