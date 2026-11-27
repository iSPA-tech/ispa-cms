/**
 * Rules of the workflow designer canvas, kept free of Vue so they can be tested on their own.
 *
 * - which node starts a workflow, and which canvas card a step uses
 * - the output handles of a step card (named outputs, switch cases, the error output, handles only old links use)
 * - which links may be drawn, and what a new link replaces
 * - the look of an output (colour by meaning) and what "when this step fails" does to the links
 * - the graph checks shown before a save
 * - the saved shape of nodes and edges (PUT workflows/{id}); the server contract is pinned by
 *   WorkflowBuilderRoundTripTest and NodeContractTest in cms/Core
 */

/** Handle id of the plain "next step" output on the canvas. It is stored as an edge without a source handle. */
export const MAIN_HANDLE = 'output'
/** Output a failed step leaves from when it is wired (WorkflowGraphExecutor::ERROR_OUTPUT). */
export const ERROR_HANDLE = 'error'

/**
 * Whether an error link is drawn while error paths are hidden: always when error paths are shown or one of its ends is
 * selected, and also when its target is reached only through error links (an error branch would look unconnected).
 */
export function errorLinkVisible(edge, edges, { showErrors = false, sourceSelected = false, targetSelected = false } = {}) {
    if (edge?.sourceHandle !== ERROR_HANDLE) return true
    if (showErrors || sourceSelected || targetSelected) return true
    return !(edges || []).some((other) => other.target === edge.target && other.sourceHandle !== ERROR_HANDLE)
}
export const TARGET_HANDLE = 'input'

/** Output names the runtime treats as the same branch (WorkflowGraphExecutor::OUTPUT_ALIASES). */
const HANDLE_ALIASES = { loop: 'each', each: 'loop', yes: 'true', true: 'yes', no: 'false', false: 'no' }

const toNumber = (value) => {
    const number = typeof value === 'string' ? parseFloat(value) : value
    return Number.isFinite(number) ? number : 0
}

const stepConfigOf = (node) => (node?.data?.step_config && typeof node.data.step_config === 'object' ? node.data.step_config : {})
const stepTypeOf = (node) => String(node?.data?.step_type || '')
export const stepCodeOf = (node) => String(node?.data?.step_code || node?.id || '')

const definitionOf = (node, byType = {}) => byType?.[stepTypeOf(node)] || null

/** A trigger from the node catalog, a legacy input node, or a step the server marked as the start. */
export const isStartNode = (node, byType = {}) => {
    if (!node) return false
    if (definitionOf(node, byType)?.group === 'trigger') return true
    const config = stepConfigOf(node)
    return (
        node.type === 'input' ||
        config.node_type === 'input' ||
        node.data?.node_type === 'input' ||
        stepTypeOf(node) === 'input' ||
        node.data?.is_start === true
    )
}

/** A legacy output node: the workflow ends there. */
export const isEndNode = (node) => {
    if (!node) return false
    return (
        node.type === 'output' ||
        stepConfigOf(node).node_type === 'output' ||
        node.data?.node_type === 'output' ||
        stepTypeOf(node) === 'output'
    )
}

/**
 * The yes/no card made on the canvas stores which handle means "yes" (`yes_output` / `no_output`, `target-a` /
 * `target-b`); a decision written by a template installer has neither and links on its named outputs `true` / `false`.
 * A canvas card saved before it stored `yes_output` still links on `target-a` / `target-b` (the server then takes
 * `target-a` as yes), so its links decide too.
 */
export const isLegacyDecision = (config = {}, outgoingEdges = []) =>
    Boolean(config?.yes_output || config?.no_output) ||
    (outgoingEdges || []).some((edge) => edge?.sourceHandle === 'target-a' || edge?.sourceHandle === 'target-b')

/** Card component on the canvas: every node uses the step card, except the legacy yes/no card. */
export const CARD_STEP = 'step'
export const CARD_DECISION = 'decision'

/** Stored node type: `input` (start), `output` (legacy end), `decision` (legacy yes/no card) or `custom`. */
export const canvasNodeType = (node, byType = {}) => {
    if (isStartNode(node, byType)) return 'input'
    if (isEndNode(node)) return 'output'
    if (node?.type === 'decision' || stepTypeOf(node) === 'decision') return 'decision'
    return 'custom'
}

const caseKeys = (config = {}) => {
    const keys = []
    const add = (key) => {
        const clean = String(key ?? '').trim()
        if (clean && !keys.includes(clean)) keys.push(clean)
    }
    // switch: cases[{ key, match }]
    if (Array.isArray(config.cases)) config.cases.forEach((row) => add(row && typeof row === 'object' ? row.key : row))
    // ai_classify: labels as [{ key }], ['a', 'b'] or { key: description }
    if (Array.isArray(config.labels)) {
        config.labels.forEach((row) => add(row && typeof row === 'object' ? row.key : row))
    } else if (config.labels && typeof config.labels === 'object') {
        Object.keys(config.labels).forEach(add)
    }
    return keys
}

/**
 * Output handles of a step card, top to bottom.
 *
 * - `definition`: the node from the catalog (may be null for steps the catalog does not know)
 * - `stepConfig`: `cases` (switch) and `labels` (AI classify) add one `case:<key>` output each
 * - `usedHandles`: source handles of the links that already leave this node; a handle only an old link uses is
 *   kept so the link stays attached and can be removed
 * - `start` / `end`: a start node has no error output, an end node has no outputs at all
 *
 * Returns `[{ id, kind, name }]` with kind `main | named | case | error | legacy`; `id` is the VueFlow handle id.
 */
export const stepHandles = (definition, stepConfig = {}, usedHandles = [], { start = false, end = false } = {}) => {
    if (end) return []
    const used = new Set((usedHandles || []).map((id) => (id === null || id === undefined || id === '' ? MAIN_HANDLE : String(id))))
    const outputs = Array.isArray(definition?.outputs)
        ? definition.outputs.filter((name) => typeof name === 'string' && name)
        : null
    const handles = []
    const push = (id, kind, name) => {
        if (!handles.some((handle) => handle.id === id)) handles.push({ id, kind, name })
    }

    if (!outputs) {
        push(MAIN_HANDLE, 'main', 'main')
    } else {
        // Cases first, the catch-all `default` after them.
        const cases = caseKeys(stepConfig)
        outputs
            .filter((name) => name !== 'default')
            .forEach((name) => {
                if (name === 'main') return push(MAIN_HANDLE, 'main', 'main')
                if (name === ERROR_HANDLE) return
                // A link saved under the runtime alias (`loop` for `each`) keeps that id.
                const alias = HANDLE_ALIASES[name]
                const id = alias && used.has(alias) && !used.has(name) ? alias : name
                push(id, name.startsWith('case:') ? 'case' : 'named', name)
            })
        cases.forEach((key) => push(`case:${key}`, 'case', key))
        if (outputs.includes('default')) push('default', 'named', 'default')
    }

    // Links whose handle the step no longer offers (a removed case, an old catch-all link).
    used.forEach((id) => {
        if (id === ERROR_HANDLE || handles.some((handle) => handle.id === id)) return
        if (id === MAIN_HANDLE) return push(MAIN_HANDLE, 'main', 'main')
        push(id, 'legacy', id.startsWith('case:') ? id.slice(5) : id)
    })

    // Any step can hand a failure to another step; a step that ends the workflow cannot.
    const terminal = Array.isArray(outputs) && outputs.length === 0
    if ((!start && !terminal) || used.has(ERROR_HANDLE)) push(ERROR_HANDLE, 'error', ERROR_HANDLE)

    return handles
}

/** Translation key of an output name (`text.workflow.output.<name>`), '' for a switch case or an unknown handle. */
export const handleLabelKey = (handle) =>
    handle && (handle.kind === 'main' || handle.kind === 'named' || handle.kind === 'error')
        ? `text.workflow.output.${handle.name}`
        : ''

// ---------------------------------------------------------------------------------------------------------------------
// Loading
// ---------------------------------------------------------------------------------------------------------------------

/**
 * A node as the canvas holds it: `type` picks the card component, and what the node is (start, end, step) is kept
 * in its data, so the stored type can be worked out again on save.
 */
export const withCard = (node, byType = {}) => {
    const stored = canvasNodeType(node, byType)
    const data = { ...(node.data || {}) }
    if (stored === 'input') data.is_start = true
    if (stored === 'input' || stored === 'output') data.node_type = stored
    return {
        id: String(node.id),
        type: stored === 'decision' ? CARD_DECISION : CARD_STEP,
        position: { x: toNumber(node.position?.x), y: toNumber(node.position?.y) },
        data,
    }
}

/**
 * Nodes and edges of a stored workflow as the canvas wants them.
 * - a node gets its canvas card type and numeric position
 * - an edge without a source handle leaves from the main output; edges to or from a missing node are dropped
 */
export const toCanvasGraph = (nodes = [], edges = [], byType = {}) => {
    const canvasNodes = (Array.isArray(nodes) ? nodes : [])
        .filter((node) => node && node.id !== undefined && node.id !== null)
        .map((node) => {
            const data = { ...(node.data || {}) }
            delete data.__error
            return withCard({ id: String(node.id), type: node.type, position: node.position, data }, byType)
        })
    const ids = new Set(canvasNodes.map((node) => node.id))
    const decisionIds = new Set(canvasNodes.filter((node) => node.type === CARD_DECISION).map((node) => node.id))
    const seen = new Set()
    const canvasEdges = []
    ;(Array.isArray(edges) ? edges : []).forEach((edge, index) => {
        const source = String(edge?.source ?? '')
        const target = String(edge?.target ?? '')
        if (!ids.has(source) || !ids.has(target)) return
        const stored = edge.sourceHandle === undefined || edge.sourceHandle === '' ? null : edge.sourceHandle
        const sourceHandle = stored === null && !decisionIds.has(source) ? MAIN_HANDLE : stored
        const key = `${source}|${sourceHandle ?? ''}|${target}`
        if (seen.has(key)) return
        seen.add(key)
        canvasEdges.push({
            id: String(edge.id ?? `edge-${source}-${sourceHandle ?? 'out'}-${target}-${index}`),
            source,
            target,
            sourceHandle,
            targetHandle: edge.targetHandle || TARGET_HANDLE,
            data: { ...(edge.data || {}) },
        })
    })
    return { nodes: canvasNodes, edges: canvasEdges }
}

// ---------------------------------------------------------------------------------------------------------------------
// Drawing links
// ---------------------------------------------------------------------------------------------------------------------

/** A link must join two different steps and may not end on a start node. */
export const isValidConnection = (connection, nodes = [], byType = {}) => {
    if (!connection?.source || !connection?.target) return false
    if (connection.source === connection.target) return false
    const target = nodes.find((node) => node.id === connection.target)
    if (!target || isStartNode(target, byType)) return false
    return nodes.some((node) => node.id === connection.source)
}

/**
 * What drawing `connection` does to the links: an output leads to one step, so a link already leaving the same
 * output is replaced. Returns `{ edge, replacedIds }`, or null when the same link exists.
 */
export const planConnection = (connection, edges = []) => {
    const sourceHandle = connection.sourceHandle || MAIN_HANDLE
    const sameOutput = edges.filter(
        (edge) => edge.source === connection.source && (edge.sourceHandle || MAIN_HANDLE) === sourceHandle
    )
    if (sameOutput.some((edge) => edge.target === connection.target)) return null
    return {
        edge: {
            id: `edge-${connection.source}-${sourceHandle}-${connection.target}`,
            source: connection.source,
            target: connection.target,
            sourceHandle,
            targetHandle: connection.targetHandle || TARGET_HANDLE,
            data: {},
        },
        replacedIds: sameOutput.map((edge) => edge.id),
    }
}

/** Edge component every link of the designer uses. */
export const EDGE_TYPE = 'link'

/** A link as the canvas store wants it. */
export const canvasEdge = (edge) => ({ ...edge, type: EDGE_TYPE })

const TONES = {
    green: ['approved', 'true', 'yes', 'pass', 'valid', 'success'],
    red: ['rejected', 'false', 'no', 'fail', 'invalid'],
    amber: ['changes_requested'],
    grey: ['expired'],
}

/**
 * Colour of an output by what it means: `green` (approved / yes), `red` (rejected / no), `amber` (rewrite),
 * `grey` (expired), `error` (the failure output) and `slate` for everything else.
 * Accepts a handle from `stepHandles` or a stored source handle id.
 */
export const outputTone = (handle) => {
    const raw = typeof handle === 'object' && handle !== null ? handle.name || handle.id : handle
    const name = String(raw || MAIN_HANDLE).replace(/^case:/, '')
    if ((typeof handle === 'object' && handle?.kind === 'error') || name === ERROR_HANDLE) return 'error'
    return Object.keys(TONES).find((tone) => TONES[tone].includes(name)) || 'slate'
}

/** Colour of a link leaving a decision: its yes branch green, its no branch red. */
export const decisionLinkTone = (config = {}, handle = '') => {
    if (handle === 'true' || handle === 'false') return outputTone(handle)
    const yes = config?.yes_output === 'target-b' ? 'target-b' : 'target-a'
    return handle === yes ? 'green' : 'red'
}

/** Small sign shown before an output name. */
export const OUTPUT_GLYPHS = { green: '✓', red: '✗', amber: '↺', grey: '⏱', error: '⚡', slate: '' }

const handleIdOf = (edge) => edge?.sourceHandle || MAIN_HANDLE
const outgoing = (nodeId, edges = []) => (edges || []).filter((edge) => edge.source === nodeId)

/** Step the plain "next" output of `nodeId` leads to, or null. */
export const mainTargetOf = (nodeId, edges = []) =>
    outgoing(nodeId, edges).find((edge) => handleIdOf(edge) === MAIN_HANDLE)?.target ?? null

/**
 * What happens when the step fails, read from its links:
 * - `stop`: no error link, the run fails (the default)
 * - `continue`: the error link goes where the next output goes, so the run carries on
 * - `route`: the error link goes to its own step
 */
export const errorModeOf = (nodeId, edges = []) => {
    const error = outgoing(nodeId, edges).find((edge) => handleIdOf(edge) === ERROR_HANDLE)
    if (!error) return 'stop'
    return error.target === mainTargetOf(nodeId, edges) ? 'continue' : 'route'
}

/**
 * Links to add and remove so the step fails the way `mode` says. Returns `{ add, removedIds }`, or null when the
 * mode cannot be set (`continue` on a step whose next output leads nowhere).
 * `route` keeps a link the operator already drew to another step and only drops the "carry on" link.
 */
export const planErrorMode = (nodeId, mode, edges = []) => {
    const errors = outgoing(nodeId, edges).filter((edge) => handleIdOf(edge) === ERROR_HANDLE)
    const current = errorModeOf(nodeId, edges)
    if (mode === current) return { add: [], removedIds: [] }
    if (mode === 'stop') return { add: [], removedIds: errors.map((edge) => edge.id) }
    if (mode === 'route') return { add: [], removedIds: current === 'continue' ? errors.map((edge) => edge.id) : [] }
    if (mode !== 'continue') return null
    const target = mainTargetOf(nodeId, edges)
    if (!target) return null
    const plan = planConnection({ source: nodeId, target, sourceHandle: ERROR_HANDLE }, edges)
    return plan ? { add: [plan.edge], removedIds: plan.replacedIds } : { add: [], removedIds: [] }
}

/**
 * Drawing `connection` in full: the link it adds, the links it replaces, and the "carry on when it fails" link
 * that has to follow when the next output moves to another step.
 * Returns `{ add: [edge], removedIds }`, or null when the same link exists.
 */
export const planLink = (connection, edges = []) => {
    const plan = planConnection(connection, edges)
    if (!plan) return null
    const add = [plan.edge]
    const removedIds = [...plan.replacedIds]
    if (plan.edge.sourceHandle === MAIN_HANDLE && errorModeOf(connection.source, edges) === 'continue') {
        const follow = planConnection({ source: connection.source, target: connection.target, sourceHandle: ERROR_HANDLE }, edges)
        if (follow) {
            add.push(follow.edge)
            removedIds.push(...follow.replacedIds)
        }
    }
    return { add, removedIds }
}

/**
 * Dragging the arrowhead of `edge` onto another step: the same output now leads to `targetId`. Returns what
 * `planLink` returns (the moved link replaces the old one, so the output still has one link; an error link stays an
 * error link, and a "carry on when it fails" link follows a moved next output), or null when nothing changes or the
 * step cannot take a link.
 */
export const planRelink = (edge, targetId, edges = [], nodes = [], byType = {}) => {
    if (!edge || !targetId || edge.target === targetId) return null
    const connection = { source: edge.source, sourceHandle: edge.sourceHandle || MAIN_HANDLE, target: targetId, targetHandle: TARGET_HANDLE }
    if (!isValidConnection(connection, nodes, byType)) return null
    return planLink(connection, edges)
}

// ---------------------------------------------------------------------------------------------------------------------
// Checks before a save
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Graph checks, without wording. Each check is `{ key, ok, nodeIds, count, name }`.
 * - `has-nodes`: the canvas is not empty
 * - `single-start`: exactly one start (trigger) node
 * - `decision-branches`: every legacy decision node has both branches wired
 * - `connected`: every other step can be reached from the start
 * A step without a next step simply ends its branch, so it is not a problem.
 */
export const checkGraph = (nodes = [], edges = [], byType = {}) => {
    const nodeList = Array.isArray(nodes) ? nodes : []
    const ids = new Set(nodeList.map((node) => node.id))
    const edgeList = (Array.isArray(edges) ? edges : []).filter((edge) => ids.has(edge.source) && ids.has(edge.target))
    const starts = nodeList.filter((node) => isStartNode(node, byType))

    const next = {}
    edgeList.forEach((edge) => (next[edge.source] ||= []).push(edge.target))
    const reached = new Set()
    const queue = starts.map((node) => node.id)
    while (queue.length) {
        const id = queue.pop()
        if (reached.has(id)) continue
        reached.add(id)
        queue.push(...(next[id] || []))
    }
    const unreached = starts.length ? nodeList.filter((node) => !reached.has(node.id)).map((node) => node.id) : []
    // A start that leads nowhere while other steps exist.
    const idleStarts = nodeList.length > 1 ? starts.filter((node) => !(next[node.id] || []).length).map((node) => node.id) : []

    const decisionIssues = nodeList
        .filter((node) => node.type === 'decision')
        .filter((node) => {
            const config = stepConfigOf(node)
            const outgoing = edgeList.filter((edge) => edge.source === node.id)
            // A template decision links on `true` / `false`; an unlinked branch just ends there, as for any step.
            if (!isLegacyDecision(config, outgoing)) return !outgoing.some((edge) => edge.sourceHandle === 'true' || edge.sourceHandle === 'false')
            const yes = config.yes_output || 'target-a'
            const no = config.no_output || 'target-b'
            return !outgoing.some((edge) => edge.sourceHandle === yes) || !outgoing.some((edge) => edge.sourceHandle === no)
        })
        .map((node) => node.id)
    const decisionCount = nodeList.filter((node) => node.type === 'decision').length

    const disconnected = [...new Set([...unreached, ...idleStarts])]

    return [
        { key: 'has-nodes', ok: nodeList.length > 0, nodeIds: [], count: nodeList.length },
        {
            key: 'single-start',
            ok: starts.length === 1,
            nodeIds: starts.length > 1 ? starts.map((node) => node.id) : [],
            count: starts.length,
            name: starts.length === 1 ? starts[0].data?.name || starts[0].id : '',
        },
        { key: 'decision-branches', ok: decisionIssues.length === 0, nodeIds: decisionIssues, count: decisionIssues.length || decisionCount, total: decisionCount },
        { key: 'connected', ok: disconnected.length === 0, nodeIds: disconnected, count: disconnected.length },
    ]
}

// ---------------------------------------------------------------------------------------------------------------------
// Saving
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Step type stored for a node.
 * A type the node catalog describes is kept as it is (a trigger stays `trigger_manual`); the shape guesses only
 * repair steps saved before step types were reliable.
 */
export const stepTypeForSave = (node, byType = {}) => {
    const data = node?.data || {}
    const config = stepConfigOf(node)
    const code = data.step_code || node?.id
    const stored = data.step_type || ''

    if (stored && byType?.[stored]) return stored
    if (node?.type === 'input' || config.node_type === 'input' || stored === 'input') return stored && stored !== 'input' && stored !== 'event' ? stored : 'input'
    if (node?.type === 'output' || config.node_type === 'output') return 'output'
    if (node?.type === 'decision' || stored === 'decision') return 'decision'
    if (code === 'log_message' || (config.level !== undefined && config.message !== undefined && !(config.class && config.method))) {
        return 'log_message'
    }
    if (stored === 'set_variable') return 'set_variable'
    if (code === 'retrieve' || config.where !== undefined || config.model !== undefined || config.first !== undefined || config.limit !== undefined) {
        return 'retrieve'
    }
    if (code === 'change' || config.targetExpr !== undefined || config.changes !== undefined || config.save !== undefined) {
        return 'change'
    }
    if (config.class && config.method) return 'blueprint_callable'
    return stored || node?.type || ''
}

/** A canvas node as the save endpoint reads it: id, card type, position and step data. */
export const serializeNode = (node, byType = {}) => {
    const type = canvasNodeType(node, byType)
    const data = { ...(node.data || {}) }
    delete data.__error
    const config = { ...stepConfigOf(node) }
    if (type === 'input' || type === 'output') config.node_type = type

    data.step_code = data.step_code || String(node.id)
    data.step_type = stepTypeForSave({ ...node, type }, byType)
    data.step_config = config
    if (type === 'input') data.is_start = true

    return {
        id: String(node.id),
        type,
        position: { x: Math.round(toNumber(node.position?.x)), y: Math.round(toNumber(node.position?.y)) },
        data,
    }
}

/**
 * A canvas edge as the save endpoint reads it. The main output is stored without a source handle: the runtime
 * follows such an edge for a step's normal result, while a handle name is matched against a named output.
 */
export const serializeEdge = (edge, nodes = []) => {
    const source = nodes.find((node) => node.id === edge.source)
    const isDecision = source?.type === 'decision'
    const main = !isDecision && (!edge.sourceHandle || edge.sourceHandle === MAIN_HANDLE)
    const sourceHandle = main ? null : edge.sourceHandle ?? null
    const stored = edge.data || {}
    // A condition belongs to the handle it was saved with; a redrawn or main link gets it from the server again.
    const keepCondition = !main && stored.condition_value === sourceHandle
    return {
        id: String(edge.id),
        source: edge.source,
        target: edge.target,
        sourceHandle,
        targetHandle: edge.targetHandle || TARGET_HANDLE,
        data: keepCondition
            ? {
                  condition: stored.condition ?? null,
                  condition_type: stored.condition_type ?? null,
                  condition_value: stored.condition_value ?? null,
              }
            : {},
    }
}

export const serializeGraph = (nodes = [], edges = [], byType = {}) => {
    const savedNodes = nodes.map((node) => serializeNode(node, byType))
    const ids = new Set(savedNodes.map((node) => node.id))
    return {
        nodes: savedNodes,
        edges: edges.filter((edge) => ids.has(edge.source) && ids.has(edge.target)).map((edge) => serializeEdge(edge, nodes)),
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// History (undo / redo)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Snapshot history of the canvas. `commit(state)` records the state after a change; `undo()` / `redo()` return the
 * state to show, or null at either end. States are compared as JSON, so committing an unchanged graph is a no-op.
 */
export const createHistory = (limit = 50) => {
    let stack = []
    let index = -1
    const read = () => (index >= 0 ? JSON.parse(stack[index]) : null)
    return {
        reset(state) {
            stack = [JSON.stringify(state)]
            index = 0
        },
        commit(state) {
            const snapshot = JSON.stringify(state)
            if (index >= 0 && stack[index] === snapshot) return false
            stack = stack.slice(0, index + 1)
            stack.push(snapshot)
            if (stack.length > limit) stack = stack.slice(stack.length - limit)
            index = stack.length - 1
            return true
        },
        undo() {
            if (index <= 0) return null
            index -= 1
            return read()
        },
        redo() {
            if (index >= stack.length - 1) return null
            index += 1
            return read()
        },
        canUndo: () => index > 0,
        canRedo: () => index < stack.length - 1,
        /** Whether the current state differs from the one given (the saved graph). */
        differsFrom: (state) => index >= 0 && stack[index] !== JSON.stringify(state),
    }
}
