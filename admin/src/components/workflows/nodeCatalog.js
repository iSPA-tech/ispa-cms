/**
 * Workflow node catalog helpers for the designer.
 *
 * - catalog store: one shared copy of `GET workflow/node-catalog` for the palette, the node cards and the editors
 * - palette grouping and search
 * - output handles and safety badges of a node definition
 * - taint / approval lint (mirrors NodeConfigValidator::taintProblems on the server) for a warning before save
 * - mapping of the 422 graph-save answer (`data.errors[{step_code, code, message}]`) onto nodes
 */
import { reactive, ref } from 'vue'
import { fetchNodeCatalog } from '@/services/workflowApi'

// ---------------------------------------------------------------------------------------------------------------------
// Catalog store
// ---------------------------------------------------------------------------------------------------------------------

const catalogState = reactive({
    groups: [],
    nodes: [],
    byType: {},
    approvalTypes: ['approval'],
    // App events that name "the trigger's own person" (an `email_send` step may write to them); null = an older
    // server that does not say.
    triggerSubjectEvents: null,
    loaded: false,
    loading: false,
    failed: false,
})

let pendingLoad = null

export const applyNodeCatalog = (payload = {}) => {
    const nodes = Array.isArray(payload.nodes) ? payload.nodes : []
    catalogState.groups = Array.isArray(payload.groups) ? payload.groups : []
    catalogState.nodes = nodes
    catalogState.byType = Object.fromEntries(nodes.map((node) => [node.type, node]))
    catalogState.approvalTypes = Array.isArray(payload.approval_types) ? payload.approval_types : ['approval']
    catalogState.triggerSubjectEvents = Array.isArray(payload.trigger_subject_events) ? payload.trigger_subject_events : null
    catalogState.loaded = true
    catalogState.failed = false
}

export const loadNodeCatalog = (force = false) => {
    if (pendingLoad) return pendingLoad
    if (catalogState.loaded && !force) return Promise.resolve(catalogState)

    catalogState.loading = true
    pendingLoad = fetchNodeCatalog()
        .then((payload) => {
            applyNodeCatalog(payload)
        })
        .catch(() => {
            catalogState.failed = true
        })
        .then(() => {
            catalogState.loading = false
            pendingLoad = null
            return catalogState
        })

    return pendingLoad
}

export const useNodeCatalog = () => ({
    catalog: catalogState,
    load: loadNodeCatalog,
    findNode: (type) => (type ? catalogState.byType[type] || null : null),
})

/** True when the node catalog describes this step type (the stored type is then authoritative). */
export const isCatalogStepType = (type) => !!type && !!catalogState.byType[type]

// ---------------------------------------------------------------------------------------------------------------------
// Labels
// ---------------------------------------------------------------------------------------------------------------------

/** Translate `key`; when the admin pack has no row for it, use the text the API sent (or the fallback). */
export const labelOr = (t, key, fallback = '') => {
    if (!key) return fallback || ''
    const text = t(key)
    return text && text !== key ? text : fallback || key
}

export const nodeLabel = (t, node) => labelOr(t, node?.label_key, pickText(node?.label, node?.label_key) || node?.type || '')

export const nodeDescription = (t, node) => {
    const text = labelOr(t, node?.description_key, pickText(node?.description, node?.description_key))
    return text === node?.description_key ? '' : text
}

// The API echoes the key when the site has no translation row; never show a raw key as a label.
function pickText(text, key) {
    return typeof text === 'string' && text !== '' && text !== key ? text : ''
}

// ---------------------------------------------------------------------------------------------------------------------
// Palette
// ---------------------------------------------------------------------------------------------------------------------

/** Icons registered in `@/config/icon-maps` (an unlisted name renders the "missing" icon). */
export const GROUP_ICONS = {
    trigger: 'clock-outline',
    flow: 'arrow-right-bottom',
    data: 'table',
    cms: 'database',
    ai: 'robot-outline',
    human: 'account-check',
    integration: 'earth',
    utility: 'tools',
    module: 'puzzle-outline',
    advanced: 'cog',
}

/**
 * Group catalog nodes for the palette and filter them by a search term.
 * Returns `[{ key, label, icon, nodes: [{ ...node, title, summary }] }]`, empty groups left out.
 */
export const buildPaletteGroups = (catalog, search, t) => {
    const term = String(search || '').trim().toLowerCase()
    const groups = [...(catalog?.groups || [])].sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
    const known = new Set(groups.map((group) => group.key))
    const nodes = catalog?.nodes || []

    // A node whose group the server did not list still shows, at the end.
    nodes.forEach((node) => {
        if (node.group && !known.has(node.group)) {
            known.add(node.group)
            groups.push({ key: node.group, label_key: `text.workflow.group.${node.group}`, label: node.group })
        }
    })

    return groups
        .map((group) => {
            // A module's group comes without a label key; its row is `text.workflow.group.<key>` when the pack has one.
            const label = labelOr(t, group.label_key || `text.workflow.group.${group.key}`, readableGroup(group))
            const items = nodes
                .filter((node) => node.group === group.key)
                .map((node) => ({ ...node, title: nodeLabel(t, node), summary: nodeDescription(t, node) }))
                .filter((node) => {
                    if (!term) return true
                    return `${node.type} ${node.title} ${node.summary} ${label}`.toLowerCase().includes(term)
                })
            return { key: group.key, label, icon: GROUP_ICONS[group.key] || 'puzzle-outline', nodes: items }
        })
        .filter((group) => group.nodes.length > 0)
}

// "church_posts" → "Church posts": never show a raw group key.
function readableGroup(group) {
    const text = pickText(group.label, group.label_key)
    const words = String(text && text.toLowerCase() !== String(group.key).toLowerCase() ? text : group.key).replace(/[_-]+/g, ' ').trim()
    return words ? words.charAt(0).toUpperCase() + words.slice(1) : ''
}

/** A readable, unused step code for a new node: `ai_action`, then `ai_action_2`, ... */
export const uniqueStepCode = (type, usedCodes = []) => {
    const base = String(type || 'step').replace(/[^A-Za-z0-9_]+/g, '_')
    const used = new Set(usedCodes)
    if (!used.has(base)) return base
    let n = 2
    while (used.has(`${base}_${n}`)) n++
    return `${base}_${n}`
}

/** Drag payload understood by `useDnd().onDrop`. The built-in decision node keeps its own canvas card. */
export const buildDropPayload = (node, title, usedCodes = []) => {
    if (node.type === 'decision') return 'decision'

    const config = JSON.parse(JSON.stringify(node.default_config || {}))
    const stepConfig = Array.isArray(config) ? {} : config
    if (Number(node.version) > 1) stepConfig._v = Number(node.version)

    return {
        type: 'custom',
        data: {
            name: title,
            display_name: title,
            step_code: uniqueStepCode(node.type, usedCodes),
            step_type: node.type,
            step_config: stepConfig,
        },
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Safety badges and output handles
// ---------------------------------------------------------------------------------------------------------------------

export const SAFETY_META = {
    read: { color: 'default', icon: 'eye-outline' },
    internal_write: { color: 'blue', icon: 'pencil-outline' },
    external_call: { color: 'purple', icon: 'open-in-new' },
    ai_spend: { color: 'gold', icon: 'cash' },
    publish: { color: 'red', icon: 'send' },
}

export const safetyBadges = (node) =>
    (Array.isArray(node?.safety) ? node.safety : [])
        .filter((name) => SAFETY_META[name])
        .map((name) => ({
            key: name,
            ...SAFETY_META[name],
            labelKey: `text.workflow.safety.${name}`,
            hintKey: `text.workflow.safety.${name}_hint`,
        }))

/** Badges of a step card, the most consequential first. `waits_person` is not a safety flag: an approval step waits. */
const CARD_BADGE_CLASSES = {
    publish: 'bg-red-100 text-red-800',
    external_call: 'bg-purple-100 text-purple-800',
    ai_spend: 'bg-amber-100 text-amber-900',
    waits_person: 'bg-violet-100 text-violet-800',
    internal_write: 'bg-blue-100 text-blue-800',
    read: 'bg-slate-100 text-slate-700',
}

/**
 * At most two badges for a step card, in plain words: what the step may do that an operator should know.
 * A step that waits for a person or does something consequential does not also say "changes nothing".
 */
export const cardBadges = (node, approvalTypes = ['approval']) => {
    const flags = new Set(Array.isArray(node?.safety) ? node.safety : [])
    if (node && approvalTypes.includes(node.type)) flags.add('waits_person')
    const keys = Object.keys(CARD_BADGE_CLASSES).filter((key) => flags.has(key))
    return (keys.length > 1 ? keys.filter((key) => key !== 'read') : keys).slice(0, 2).map((key) => ({
        key,
        className: CARD_BADGE_CLASSES[key],
        labelKey: `text.workflow.safety.${key}`,
        hintKey: `text.workflow.safety.${key}_hint`,
    }))
}

const NAMED_OUTPUTS = ['true', 'false', 'each', 'done', 'approved', 'rejected', 'changes_requested', 'expired', 'error', 'default']

/**
 * Source handles of a node card.
 * - no definition, or the single `main` output: the classic `output` handle (unlabelled)
 * - named outputs: one handle each, id = output name (the server stores it as the edge condition)
 * - `on_error: route` adds an `error` handle
 * Returns `[{ id, labelKey, label }]`; `labelKey` is set for the fixed names, `label` for `case:<key>`.
 */
export const outputHandles = (node, stepConfig = {}) => {
    const outputs = Array.isArray(node?.outputs) ? node.outputs.filter((name) => typeof name === 'string' && name) : null
    const handles = []

    if (!outputs) {
        handles.push({ id: 'output', labelKey: '', label: '' })
    } else {
        const named = outputs.length > 1 || (outputs.length === 1 && outputs[0] !== 'main')
        outputs.forEach((name) => {
            if (name === 'main') {
                handles.push({ id: 'output', labelKey: named ? 'text.workflow.output.main' : '', label: '' })
            } else if (name.startsWith('case:')) {
                handles.push({ id: name, labelKey: '', label: name.slice(5) })
            } else {
                handles.push({
                    id: name,
                    labelKey: NAMED_OUTPUTS.includes(name) ? `text.workflow.output.${name}` : '',
                    label: name,
                })
            }
        })
    }

    if (stepConfig?.on_error === 'route' && !handles.some((handle) => handle.id === 'error')) {
        handles.push({ id: 'error', labelKey: 'text.workflow.output.error', label: 'error' })
    }

    return handles
}

// ---------------------------------------------------------------------------------------------------------------------
// Taint / approval lint (client mirror of the server rule; the server stays the authority)
// ---------------------------------------------------------------------------------------------------------------------

const WRITE_KEYS = ['storeAs', 'to', 'key']
const RESERVED_KEYS = ['node_type', '_v', '_upgrade_log']

const scanConfig = (value, key, reads, writes) => {
    if (Array.isArray(value)) {
        value.forEach((child, index) => scanConfig(child, index, reads, writes))
        return
    }
    if (value && typeof value === 'object') {
        Object.entries(value).forEach(([childKey, child]) => scanConfig(child, childKey, reads, writes))
        return
    }
    if (typeof value !== 'string') return

    if (WRITE_KEYS.includes(key)) {
        const target = value.match(/^vars\.([A-Za-z_][A-Za-z0-9_]*)/)
        if (target) {
            writes.add(`v:${target[1]}`)
            return
        }
    }
    for (const match of value.matchAll(/(^|[^\w.])vars\.([A-Za-z_][A-Za-z0-9_]*)/g)) reads.add(`v:${match[2]}`)
    for (const match of value.matchAll(/(^|[^\w.])steps\.([A-Za-z0-9_-]+)/g)) reads.add(`s:${match[2]}`)
}

const stepCodeOf = (node) => String(node?.data?.step_code || node?.id || '')

/**
 * Find write / publish nodes that read AI or fetched data with no approval node on the way.
 * `nodes` / `edges` are the canvas (VueFlow) nodes and edges.
 * Returns `[{ step_code, node_id, origin, origin_node_id, code: 'taint_needs_approval' }]`.
 */
/** Same list as NodeDefinition::GUARDED_SAFETY: these steps may not use AI or outside data without an approval first. */
const GUARDED_SAFETY = ['internal_write', 'external_call', 'publish']

export const lintTaint = (nodes = [], edges = [], catalog = catalogState) => {
    const byType = catalog?.byType || {}
    const approvalTypes = catalog?.approvalTypes || ['approval']
    const steps = new Map()
    const codeById = {}

    nodes.forEach((node) => {
        const code = stepCodeOf(node)
        if (!code) return
        codeById[node.id] = code
        const type = node?.data?.step_type || node?.type || ''
        const config = { ...(node?.data?.step_config || {}) }
        RESERVED_KEYS.forEach((key) => delete config[key])
        const reads = new Set()
        const writes = new Set([`s:${code}`])
        scanConfig(config, null, reads, writes)
        const definition = byType[type] || null
        steps.set(code, {
            nodeId: node.id,
            approval: approvalTypes.includes(type),
            source: !!definition?.taint_output,
            // Same rule as NodeDefinition::isGuardedSink(): a draft-only or bookkeeping write marks itself taint_sink false.
            sink: definition?.taint_sink !== false && (definition?.safety || []).some((name) => GUARDED_SAFETY.includes(name)),
            reads,
            writes,
        })
    })

    const sources = [...steps].filter(([, step]) => !step.approval && step.source).map(([code]) => code)
    if (!sources.length) return []

    // token => set of origin step codes
    const tainted = new Map()
    const taint = (token, origin) => {
        if (!tainted.has(token)) tainted.set(token, new Set())
        const set = tainted.get(token)
        const before = set.size
        set.add(origin)
        return set.size !== before
    }
    sources.forEach((source) => steps.get(source).writes.forEach((token) => taint(token, source)))

    for (let changed = true, guard = 0; changed && guard < 1000; guard++) {
        changed = false
        steps.forEach((step) => {
            if (step.approval) return
            const origins = new Set()
            step.reads.forEach((token) => (tainted.get(token) || []).forEach((origin) => origins.add(origin)))
            step.writes.forEach((token) => origins.forEach((origin) => (changed = taint(token, origin) || changed)))
        })
    }

    const next = {}
    if (!edges.length) {
        const codes = [...steps.keys()]
        for (let i = 1; i < codes.length; i++) (next[codes[i - 1]] ||= []).push(codes[i])
    } else {
        edges.forEach((edge) => {
            const from = codeById[edge.source]
            const to = codeById[edge.target]
            if (from && to) (next[from] ||= []).push(to)
        })
    }

    const reachable = {}
    sources.forEach((source) => {
        const seen = new Set()
        const queue = [...(next[source] || [])]
        while (queue.length) {
            const code = queue.shift()
            if (seen.has(code) || !steps.has(code)) continue
            seen.add(code)
            if (!steps.get(code).approval) queue.push(...(next[code] || []))
        }
        reachable[source] = seen
    })

    const problems = []
    steps.forEach((step, code) => {
        if (step.approval || !step.sink) return
        for (const token of step.reads) {
            for (const origin of tainted.get(token) || []) {
                if (origin !== code && reachable[origin]?.has(code)) {
                    problems.push({
                        step_code: code,
                        node_id: step.nodeId,
                        origin,
                        origin_node_id: steps.get(origin)?.nodeId ?? null,
                        code: 'taint_needs_approval',
                    })
                    return
                }
            }
        }
    })

    return problems
}

// ---------------------------------------------------------------------------------------------------------------------
// Save errors (422) and warnings, shared with the node cards
// ---------------------------------------------------------------------------------------------------------------------

export const SAVE_ERROR_CODES = [
    'unknown_type',
    'class_not_allowed',
    'model_not_allowed',
    'config_invalid',
    'config_version_unsupported',
    'permission_denied',
    'taint_needs_approval',
    'auto_approval_not_ready',
]

/** Refusals the designer words itself: the server's own sentence is not shown. */
const OWN_WORDING = ['auto_approval_not_ready']

/**
 * Read the graph-save refusal. Accepts the axios error, its response or the response body.
 * Returns `{ message, list: [{ step_code, code, message }], byStep: { [step_code]: [{ code, message }] } }`.
 */
export const mapSaveErrors = (input) => {
    const payload = input?.response?.data ?? input
    const rows = Array.isArray(payload?.data?.errors) ? payload.data.errors : []
    const list = rows
        .filter((row) => row && row.step_code !== undefined && row.step_code !== null && row.step_code !== '')
        .map((row) => ({
            step_code: String(row.step_code),
            code: SAVE_ERROR_CODES.includes(row.code) ? row.code : 'config_invalid',
            message: OWN_WORDING.includes(row.code) ? '' : stripStepPrefix(String(row.message || ''), String(row.step_code)),
        }))
    const byStep = {}
    list.forEach((row) => (byStep[row.step_code] ||= []).push({ code: row.code, message: row.message }))

    return { message: typeof payload?.message === 'string' ? payload.message : '', list, byStep }
}

// Server messages start with "<step_code>: "; the node card already says which step it is.
function stripStepPrefix(message, stepCode) {
    return message.startsWith(`${stepCode}: `) ? message.slice(stepCode.length + 2) : message
}

/** step_code => [{ code, message }] from the last refused save. */
const saveErrors = ref({})
/** step_code => { origin } from the live taint lint. */
const taintWarnings = ref({})

export const recordSaveErrors = (input) => {
    saveErrors.value = mapSaveErrors(input).byStep
    return saveErrors.value
}

export const clearSaveErrors = () => {
    if (Object.keys(saveErrors.value).length) saveErrors.value = {}
}

export const setTaintWarnings = (problems = []) => {
    taintWarnings.value = Object.fromEntries(problems.map((problem) => [problem.step_code, problem]))
}

export const useNodeIssues = () => ({
    saveErrors,
    taintWarnings,
    errorsFor: (node) => saveErrors.value[stepCodeOf(node)] || saveErrors.value[String(node?.id ?? '')] || [],
    warningFor: (node) => taintWarnings.value[stepCodeOf(node)] || null,
})
