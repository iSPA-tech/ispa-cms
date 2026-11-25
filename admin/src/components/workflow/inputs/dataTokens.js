/**
 * Data tokens of the workflow step form, kept free of Vue so they can be tested on their own.
 *
 * A step setting stores data references in one of two syntaxes, and the form never changes what is stored:
 * - `template`: free text with `{{ expression }}` placeholders (TemplateRenderer::renderString on the server)
 * - `path`: one bare expression, most often a path such as `vars.draft.review` (WorkflowExpressionEngine::evaluate)
 *
 * The form shows a reference as a chip ("Draft › Title"). A chip is only a view: it keeps the exact text it was
 * read from, so a setting that is opened and saved without edits is byte-identical.
 */

/** Same pattern as TemplateRenderer::renderString. */
const PLACEHOLDER = /\{\{\s*(.+?)\s*\}\}/gs

/** A plain data path: `vars.a.b`, `root.title`, `steps.code.result`, `trigger.meta.x`, `item.id`. */
const SIMPLE_PATH = /^(vars|root|steps|trigger|item)(\.[A-Za-z_][A-Za-z0-9_]*|\[\d+\])*$/

export const isSimplePath = (value) => typeof value === 'string' && SIMPLE_PATH.test(value)

// ---------------------------------------------------------------------------------------------------------------------
// Which syntax a setting uses
// ---------------------------------------------------------------------------------------------------------------------

/** `x-expr` settings the runners evaluate as one bare expression (NodeHelpers::evalField), by step type. */
const PATH_FIELDS = {
    approval: ['review', 'payload', 'subject_id', 'changes'],
    jev_review: ['content'],
    notify: ['users', 'approval_id'],
    ask_and_wait: ['options'],
}
/** Evaluated as a bare expression on every step type (AiNodeRunner::revision reads both forms). */
const PATH_FIELDS_ANY = ['revision']
/** Plain text settings the runners render as a template, by step type. */
const TEMPLATE_STRINGS = {
    approval: ['title'],
    notify: ['message'],
    ask_and_wait: ['question'],
}

/**
 * Syntax of a setting: `'path'`, `'template'`, or null for a setting that holds no data reference.
 * - a module node (`module.node`) evaluates every `x-expr` setting as a bare expression (ModuleNodeRunner)
 * - `...Expr` names are bare expressions everywhere
 * - the other `x-expr` settings of Core nodes are rendered as templates
 */
export const fieldSyntax = (stepType, name, schema = {}) => {
    const type = String(stepType || '')
    const field = String(name || '')
    if (schema?.['x-expr']) {
        if (type.includes('.')) return 'path'
        if (/Expr$/.test(field) || PATH_FIELDS_ANY.includes(field) || (PATH_FIELDS[type] || []).includes(field)) return 'path'
        return 'template'
    }
    return (TEMPLATE_STRINGS[type] || []).includes(field) ? 'template' : null
}

/**
 * Settings that name a record or a record type (`subject_id`, `place_uuid`, `subject_model`). The form never asks
 * for them: a stored value is shown read-only, an empty one is left out, and only advanced mode changes them.
 */
const ID_PATH = /(^|_)(id|uuid)$|^version$/
const ID_TEXT = /(^|_)(id|uuid)$|_model$/
export const isIdField = (name, syntax) => (syntax === 'path' ? ID_PATH : ID_TEXT).test(String(name || ''))

// ---------------------------------------------------------------------------------------------------------------------
// Template syntax
// ---------------------------------------------------------------------------------------------------------------------

/**
 * A template as segments: `{ type: 'text', value }` and `{ type: 'token', raw, expr, path }`. `raw` is the exact
 * placeholder text; `path` is set when the placeholder is a plain data path (shown by its friendly name).
 */
export const parseTemplate = (value) => {
    const text = value === null || value === undefined ? '' : String(value)
    const segments = []
    let last = 0
    for (const match of text.matchAll(PLACEHOLDER)) {
        if (match.index > last) segments.push({ type: 'text', value: text.slice(last, match.index) })
        const expr = match[1]
        segments.push({ type: 'token', raw: match[0], expr, path: isSimplePath(expr) ? expr : null })
        last = match.index + match[0].length
    }
    if (last < text.length) segments.push({ type: 'text', value: text.slice(last) })
    return segments
}

/** The stored text of segments: text as typed, tokens exactly as they were read. */
export const serializeTemplate = (segments = []) =>
    segments.map((segment) => (segment.type === 'token' ? segment.raw : segment.value ?? '')).join('')

/** A new placeholder for a picked data path, in the form the samples use. */
export const tokenFor = (path) => ({ type: 'token', raw: `{{ ${path} }}`, expr: path, path })

// ---------------------------------------------------------------------------------------------------------------------
// Path syntax
// ---------------------------------------------------------------------------------------------------------------------

/**
 * A path setting as the form shows it: `{ kind: 'empty' }`, `{ kind: 'chip', path, raw }` (a plain data path, also
 * when wrapped as `{{ path }}`) or `{ kind: 'formula', raw }` (any other expression, shown as it is).
 */
export const readPath = (value) => {
    if (value === null || value === undefined || value === '') return { kind: 'empty', raw: '' }
    const raw = String(value)
    const trimmed = raw.trim()
    if (trimmed === '') return { kind: 'empty', raw }
    if (isSimplePath(trimmed)) return { kind: 'chip', path: trimmed, raw }
    const wrapped = /^\{\{\s*(.+?)\s*\}\}$/s.exec(trimmed)
    if (wrapped && isSimplePath(wrapped[1])) return { kind: 'chip', path: wrapped[1], raw }
    return { kind: 'formula', raw }
}

// ---------------------------------------------------------------------------------------------------------------------
// Order of the steps
// ---------------------------------------------------------------------------------------------------------------------

const sourceHandleOf = (edge) => edge?.sourceHandle ?? edge?.source_handle ?? null
const isErrorEdge = (edge) => sourceHandleOf(edge) === 'error'

/**
 * Ids of the steps that run before `currentId`: its ancestors once the links that go back up the diagram (a loop
 * returning to its start, "rewrite" going back to the writer) are left out. The current step is not included.
 */
export const stepsBefore = (nodes = [], edges = [], currentId = null) => {
    const ids = new Set(nodes.map((node) => String(node.id)))
    const outgoing = {}
    edges.forEach((edge) => {
        const source = String(edge.source)
        const target = String(edge.target)
        if (!ids.has(source) || !ids.has(target) || source === target) return
        ;(outgoing[source] ||= []).push({ target, error: isErrorEdge(edge) })
    })
    // The normal way forward is walked first, so an error link is the one that counts as going back.
    Object.values(outgoing).forEach((list) => list.sort((a, b) => Number(a.error) - Number(b.error)))

    const incoming = {}
    const state = {} // 1 = on the walk, 2 = done
    const walk = (start) => {
        const stack = [{ id: start, index: 0 }]
        state[start] = 1
        while (stack.length) {
            const frame = stack[stack.length - 1]
            const next = (outgoing[frame.id] || [])[frame.index++]
            if (!next) {
                state[frame.id] = 2
                stack.pop()
                continue
            }
            // A link to a step still on the walk goes back up: it does not make that step an earlier one.
            if (state[next.target] === 1) continue
            ;(incoming[next.target] ||= new Set()).add(frame.id)
            if (!state[next.target]) {
                state[next.target] = 1
                stack.push({ id: next.target, index: 0 })
            }
        }
    }
    const hasIncoming = new Set(edges.map((edge) => String(edge.target)))
    const starts = nodes.filter((node) => node.data?.is_start || !hasIncoming.has(String(node.id))).map((node) => String(node.id))
    ;[...starts, ...nodes.map((node) => String(node.id))].forEach((id) => !state[id] && walk(id))

    const before = new Set()
    const queue = [...(incoming[String(currentId)] || [])]
    while (queue.length) {
        const id = queue.pop()
        if (before.has(id)) continue
        before.add(id)
        queue.push(...(incoming[id] || []))
    }
    before.delete(String(currentId))
    return before
}

// ---------------------------------------------------------------------------------------------------------------------
// Data the picker offers
// ---------------------------------------------------------------------------------------------------------------------

const VAR_PATH = /^vars\.[A-Za-z_][A-Za-z0-9_.]*$/
const configOf = (node) => (node?.data?.step_config && typeof node.data.step_config === 'object' ? node.data.step_config : {})

/** "place_uuid" → "Place uuid". */
export const humanize = (key) => {
    const words = String(key ?? '')
        .replace(/[_-]+/g, ' ')
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .trim()
        .toLowerCase()
    return words ? words.charAt(0).toUpperCase() + words.slice(1) : ''
}

/** Kind of a sample value: text | number | boolean | list | group | empty. */
export const valueType = (value) => {
    if (value === null || value === undefined || value === '') return 'empty'
    if (Array.isArray(value)) return 'list'
    if (typeof value === 'object') return 'group'
    if (typeof value === 'number') return 'number'
    if (typeof value === 'boolean') return 'boolean'
    return 'text'
}

/** A sample value in one short line; '' when there is nothing worth showing (lists and groups show their type). */
export const samplePreview = (value, max = 48) => {
    const type = valueType(value)
    if (type === 'empty' || type === 'list' || type === 'group') return ''
    const text = String(value).replace(/\s+/g, ' ').trim()
    return text.length > max ? `${text.slice(0, max - 1)}…` : text
}

const readSample = (sample, path) => {
    if (!sample || typeof sample !== 'object') return undefined
    return path.split('.').reduce((value, key) => (value && typeof value === 'object' ? value[key] : undefined), sample)
}

/** Where a runner stores its result when the step names none (the fallback of `NodeHelpers::storePath`). */
const DEFAULT_STORES = {
    approval: 'vars.approval',
    jev_review: 'vars.jev',
    notify: 'vars.notified',
    ask_and_wait: 'vars.answer',
    split_in_batches: 'vars.batch',
    datetime: 'vars.datetime',
    render_template: 'vars.rendered',
    dedupe: 'vars.new_items',
    crypto: 'vars.crypto',
    text_transform: 'vars.text',
    json_transform: 'vars.json',
    execute_workflow: 'vars.child',
}

/** The path a step type writes to when its `storeAs` is not set: AI steps use `vars.<name>` (AiNodeRunner). */
export const defaultStorePath = (stepType) => {
    const type = String(stepType || '')
    if (DEFAULT_STORES[type]) return DEFAULT_STORES[type]
    return /^ai_[a-z_]+$/.test(type) && type !== 'ai_action' && type !== 'ai_reasoning' ? `vars.${type.slice(3)}` : null
}

/**
 * The places a step writes to: `[{ path, role, label? }]` with role `result` (its `storeAs`, or the runner's own
 * default when none is set), `item` and `index` (a loop) and `var` (a variable a "set values" step assigns).
 * A path outside `vars.` is not offered.
 */
export const stepStores = (node, definition = null) => {
    const config = configOf(node)
    const stores = []
    const add = (path, role, extra = {}) =>
        typeof path === 'string' && VAR_PATH.test(path) && !stores.some((row) => row.path === path) && stores.push({ path, role, ...extra })
    add(config.itemVar, 'item')
    add(config.indexVar, 'index')
    if (definition?.dynamic === 'assignments' && Array.isArray(config.assignments)) {
        config.assignments.forEach((row) => add(row?.key, 'var', { label: humanize(String(row?.key || '').split('.').pop()) }))
    }
    // An empty text is "store nothing"; only a missing name falls back to the runner's default.
    add(config.storeAs === undefined || config.storeAs === null ? defaultStorePath(node?.data?.step_type) : config.storeAs, 'result')
    return stores
}

/** Kind of a declared result field (NodeDefinition::RESULT_TYPES) in the picker's words. */
const RESULT_KINDS = { string: 'text', number: 'number', boolean: 'boolean', list: 'list', object: 'group', datetime: 'text' }
/** Kind of a JSON-schema property or a model column. */
const SCHEMA_KINDS = { string: 'text', text: 'text', integer: 'number', int: 'number', bigint: 'number', number: 'number', float: 'number', decimal: 'number', boolean: 'boolean', bool: 'boolean', array: 'list', json: 'group', object: 'group', date: 'text', datetime: 'text' }
const PART_KEY = /^[A-Za-z_][A-Za-z0-9_]*$/

const cleanValues = (values) =>
    (Array.isArray(values) ? values : [])
        .filter((row) => row !== null && row !== undefined)
        .map((row) =>
            typeof row === 'object'
                ? { value: row.value, label: String(row.label ?? row.value ?? ''), ...(row.label_key ? { label_key: row.label_key } : {}) }
                : { value: row, label: String(row) }
        )
        .filter((row) => row.value !== undefined && row.value !== null && row.value !== '')

const schemaKind = (schema) => {
    const types = (Array.isArray(schema?.type) ? schema.type : [schema?.type]).filter((type) => type && type !== 'null')
    return SCHEMA_KINDS[String(types[0] || '').toLowerCase()] || 'data'
}

/** Keys of the labels an "AI classify" step may answer (`[{key}]`, a list of keys, or `{key: description}`). */
const labelValues = (labels) => {
    const rows = Array.isArray(labels)
        ? labels.map((row) => (row && typeof row === 'object' ? { value: row.key, label: row.label || row.description || row.key } : { value: row, label: row }))
        : labels && typeof labels === 'object'
          ? Object.entries(labels).map(([key, description]) => ({ value: key, label: typeof description === 'string' && description ? description : key }))
          : []
    return cleanValues(rows)
}

/**
 * The parts of a step's result, known before any run from the node catalog: `[{ key, type, label, label_key, values }]`
 * (`key` a dot path under the place the result is stored).
 * - the catalog `result` fields, each with its `values` when the stored value is one of a known set
 * - `dynamic: schema`: the properties of `config.schema` (two levels), under the field marked `dynamic`, else at the root
 * - `dynamic: model`: the fields of the record type in `config.model` (`modelFields`), unless the step stores a list
 */
export const resultParts = (definition, config = {}, modelFields = null) => {
    const parts = []
    const push = (part) => PART_KEY.test(String(part.key).split('.').pop()) && !parts.some((row) => row.key === part.key) && parts.push(part)
    const fromSchema = (schema, prefix, depth = 0) => {
        const properties = schema && typeof schema === 'object' && !Array.isArray(schema) ? schema.properties : null
        if (!properties || typeof properties !== 'object' || Array.isArray(properties)) return
        Object.entries(properties).forEach(([name, child]) => {
            const type = schemaKind(child)
            push({ key: `${prefix}${name}`, type, label: typeof child?.title === 'string' ? child.title : '', label_key: '', values: cleanValues(child?.enum) })
            if (type === 'group' && depth < 1) fromSchema(child, `${prefix}${name}.`, depth + 1)
        })
    }

    const declared = Array.isArray(definition?.result) ? definition.result : []
    declared.forEach((field) => {
        if (!field?.key) return
        let values = cleanValues(field.values)
        // `values_from`: the set is one of the step's own settings (the labels of an "AI classify" step).
        if (!values.length && typeof field.values_from === 'string') values = labelValues(config[field.values_from])
        if (!values.length && definition?.type === 'ai_classify' && field.key === 'label') values = labelValues(config.labels)
        push({ key: field.key, type: RESULT_KINDS[field.type] || 'data', label: field.label || '', label_key: field.label_key || '', values })
        if (field.dynamic === true && definition?.dynamic === 'schema') fromSchema(config.schema, `${field.key}.`)
    })
    if (definition?.dynamic === 'schema' && !declared.some((field) => field?.dynamic === true)) fromSchema(config.schema, '')
    if (definition?.dynamic === 'model' && Array.isArray(modelFields) && !(definition.type === 'retrieve' && !config.first)) {
        modelFields.forEach((field) => field?.name && push({ key: field.name, type: SCHEMA_KINDS[String(field.type || '').toLowerCase()] || 'data', label: '', label_key: '', values: [] }))
    }
    return parts
}

/** Kind of the whole stored value of a step, when the catalog says it (else `data`). */
const wholeKind = (definition, config = {}) => {
    if (!definition) return 'data'
    if (definition.dynamic === 'model') return definition.type === 'retrieve' && !config.first ? 'list' : 'group'
    if (!Array.isArray(definition.result) && !definition.dynamic && (!definition.result_type || definition.result_type === 'object')) return 'data'
    return RESULT_KINDS[definition.result_type] || 'group'
}

/** Whether the step is linked into the diagram (a link arrives at it, or it is the start). */
export const isPlaced = (nodes = [], edges = [], currentId = null) => {
    if (currentId === null || currentId === undefined) return true
    const id = String(currentId)
    return edges.some((edge) => String(edge.target) === id) || nodes.some((node) => String(node.id) === id && node.data?.is_start)
}

/**
 * Groups of data for the picker, one per step that produces data, in diagram order.
 *
 * - `nodes`, `edges`: the designer graph; `currentId`: the step being edited
 * - `sample`: the context of the last run (`{ vars, trigger }`), or null when the workflow never ran
 * - `rootFields`: `[{ name, type }]` of the record the workflow runs on, when it has one
 * - `label(key)`: authored wording for a data key, '' when there is none
 * - `byType`: node catalog by step type; its `result` lists the parts of each step's data before any run
 * - `modelFields`: `{ [model]: [{ name, type }] }` for the steps that store a record
 * - `partLabel(part)`: wording of a declared result part, '' when there is none
 *
 * Returns `[{ key, title, later, items: [{ path, label, sample, type, later, values? }] }]`. `later` marks data a step
 * after the current one produces: it is listed, greyed, and cannot be picked. `values` lists the values a part may
 * hold (`[{ value, label }]`), for the condition builder.
 */
export const buildDataGroups = ({
    nodes = [],
    edges = [],
    currentId = null,
    sample = null,
    rootFields = [],
    rootTitle = '',
    label = () => '',
    byType = {},
    modelFields = {},
    partLabel = () => '',
} = {}) => {
    const groups = []
    const itemLabel = (key) => label(key) || humanize(key)

    if (rootFields.length) {
        groups.push({
            key: 'root',
            title: rootTitle,
            later: false,
            items: rootFields.map((field) => ({ path: `root.${field.name}`, label: itemLabel(field.name), sample: '', type: field.type || 'text', later: false })),
        })
    }

    const before = stepsBefore(nodes, edges, currentId)
    const placed = isPlaced(nodes, edges, currentId)
    nodes.forEach((node) => {
        const definition = byType?.[String(node?.data?.step_type || '')] || null
        const config = configOf(node)
        const stores = stepStores(node, definition)
        if (!stores.length) return
        const id = String(node.id)
        // A step that is not linked into the diagram yet has no "before": every other step's data is offered.
        const later = currentId !== null && currentId !== undefined && (placed ? !before.has(id) : id === String(currentId))
        const items = []
        stores.forEach(({ path, role, label: own }) => {
            const value = readSample(sample, path)
            const known = role === 'index' ? 'number' : role === 'result' ? wholeKind(definition, config) : 'data'
            const type = value === undefined ? known : valueType(value)
            items.push({ path, role, label: own || '', sample: samplePreview(value), type, later })
            if (role !== 'result') return
            // The parts the catalog declares come first, with a sample when the workflow ran; then whatever else the
            // last run stored (one level).
            const seen = new Set()
            resultParts(definition, config, modelFields?.[config.model] || null).forEach((part) => {
                seen.add(part.key)
                const child = readSample(sample, `${path}.${part.key}`)
                const words = part.key.split('.')
                items.push({
                    path: `${path}.${part.key}`,
                    role: 'part',
                    label: partLabel(part) || label(words[words.length - 1]) || part.label || words.map(humanize).join(' › '),
                    sample: samplePreview(child),
                    type: child === undefined || child === null ? part.type : valueType(child),
                    later,
                    ...(part.values.length ? { values: part.values } : {}),
                })
            })
            if (valueType(value) !== 'group') return
            Object.entries(value).forEach(([key, child]) => {
                if (!PART_KEY.test(key) || seen.has(key)) return
                items.push({ path: `${path}.${key}`, role: 'part', label: itemLabel(key), sample: samplePreview(child), type: valueType(child), later })
            })
        })
        groups.push({ key: id, title: String(node.data?.name || node.data?.step_code || id), later, items })
    })
    return groups
}

/** The picker row of a data path (`null` when no step produces it), with the group it belongs to. */
export const findDataItem = (path, groups = []) => {
    const clean = String(path || '')
    for (const group of groups) {
        const item = group.items.find((row) => row.path === clean)
        if (item) return { group, item }
    }
    return null
}

/**
 * Friendly name of a data path for its chip: "Step name › Part", the step name alone for a whole result. `roleLabel`
 * names the roles (`item`, `index`). A path no step produces is shown by its own words.
 */
export const pathLabel = (path, groups = [], { roleLabel = () => '', label = () => '' } = {}) => {
    const clean = String(path || '')
    let best = null
    groups.forEach((group) =>
        group.items.forEach((item) => {
            if (item.role === 'part') return
            if (clean !== item.path && !clean.startsWith(`${item.path}.`)) return
            if (!best || item.path.length > best.item.path.length) best = { group, item }
        })
    )
    const words = (rest) => rest.split('.').filter(Boolean).map((key) => label(key) || humanize(key))
    // A part the catalog declares is named by its own wording ("Verdict"), not by its key.
    const part = findDataItem(clean, groups)
    if (part && part.item.role === 'part') return [part.group.title, part.item.label].filter(Boolean).join(' › ')
    if (!best) {
        const parts = clean.split('.')
        return words(parts.slice(1).join('.')).join(' › ') || clean
    }
    const head = [best.group.title, best.item.role === 'item' || best.item.role === 'index' ? roleLabel(best.item.role) : best.group.key === 'root' || best.item.role === 'var' ? best.item.label : '']
    return [...head, ...words(clean.slice(best.item.path.length + 1))].filter(Boolean).join(' › ')
}

/** Rows of the picker that match a search (step name or data name), keeping the groups. */
export const filterGroups = (groups = [], query = '') => {
    const needle = String(query || '').trim().toLowerCase()
    if (!needle) return groups
    return groups
        .map((group) => ({
            ...group,
            items: group.title.toLowerCase().includes(needle)
                ? group.items
                : group.items.filter((item) => `${item.label} ${item.path}`.toLowerCase().includes(needle)),
        }))
        .filter((group) => group.items.length)
}
