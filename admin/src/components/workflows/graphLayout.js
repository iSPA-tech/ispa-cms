/**
 * Layout of the workflow designer canvas, kept free of Vue so it can be tested on its own.
 *
 * A workflow reads top to bottom: the link comes in at the top of a card and each output leaves from its bottom.
 * - which links go back up (they are left out of the layering and drawn on the right)
 * - which steps a loop repeats (drawn inside a frame)
 * - the layered top-to-bottom arrangement ("Tidy up")
 * - the path of a link between two cards
 *
 * Only positions come out of here: steps, links and their handles are never changed.
 */
import { ERROR_HANDLE, MAIN_HANDLE } from './graphRules'

/** Size of a step card until the browser has measured it. */
export const CARD_WIDTH = 300
export const CARD_HEIGHT = 96

const COLUMN_GAP = 48
const ROW_GAP = 64
/** Room above the first step of a loop for the frame title, and below its last step. */
const FRAME_HEAD = 48
const FRAME_PAD = 24
const ORIGIN = { x: 40, y: 32 }

/** Outputs of a loop step that lead into the steps it repeats. */
const LOOP_HANDLES = new Set(['each', 'loop', 'batch'])

const handleOf = (edge) => edge?.sourceHandle || MAIN_HANDLE
const isErrorEdge = (edge) => handleOf(edge) === ERROR_HANDLE
const isStart = (node) => node?.data?.is_start === true || node?.type === 'input' || node?.data?.node_type === 'input'

const sizeFrom = (sizes, id) => {
    const size = typeof sizes === 'function' ? sizes(id) : sizes?.[id]
    return { width: size?.width > 0 ? size.width : CARD_WIDTH, height: size?.height > 0 ? size.height : CARD_HEIGHT }
}

/** Links between steps that are on the canvas, a step's error link after its other links. */
const cleanEdges = (nodes, edges) => {
    const ids = new Set(nodes.map((node) => node.id))
    const kept = (edges || []).filter((edge) => ids.has(edge.source) && ids.has(edge.target) && edge.source !== edge.target)
    return [...kept.filter((edge) => !isErrorEdge(edge)), ...kept.filter(isErrorEdge)]
}

/**
 * Walk the graph from its start and tell forward links from links that go back to a step already on the way.
 * Returns `{ order, backIds }`: steps in the order they are met, and the ids of the links that go back.
 */
export const walkGraph = (nodes = [], edges = []) => {
    const list = cleanEdges(nodes, edges)
    const next = {}
    list.forEach((edge) => (next[edge.source] ||= []).push(edge))
    const hasIncoming = new Set(list.map((edge) => edge.target))
    const roots = [
        ...nodes.filter(isStart),
        ...nodes.filter((node) => !isStart(node) && !hasIncoming.has(node.id)),
        ...nodes,
    ]

    const state = {}
    const order = []
    const backIds = new Set()
    roots.forEach((root) => {
        if (state[root.id]) return
        state[root.id] = 'open'
        order.push(root.id)
        const stack = [{ id: root.id, index: 0 }]
        while (stack.length) {
            const frame = stack[stack.length - 1]
            const edge = (next[frame.id] || [])[frame.index++]
            if (!edge) {
                state[frame.id] = 'done'
                stack.pop()
            } else if (state[edge.target] === 'open') {
                backIds.add(edge.id)
            } else if (!state[edge.target]) {
                state[edge.target] = 'open'
                order.push(edge.target)
                stack.push({ id: edge.target, index: 0 })
            }
        }
    })
    return { order, backIds }
}

/**
 * Steps each loop repeats: `{ [loopStepId]: [stepId, ...] }`.
 * The body is what the loop's "each item" output leads to, without the steps its other outputs also reach.
 */
export const loopBodies = (nodes = [], edges = []) => {
    const { backIds } = walkGraph(nodes, edges)
    const forward = cleanEdges(nodes, edges).filter((edge) => !backIds.has(edge.id))
    const next = {}
    forward.forEach((edge) => (next[edge.source] ||= []).push(edge))
    const reach = (starts, blocked) => {
        const seen = new Set()
        const queue = [...starts]
        while (queue.length) {
            const id = queue.shift()
            if (id === blocked || seen.has(id)) continue
            seen.add(id)
            ;(next[id] || []).forEach((edge) => queue.push(edge.target))
        }
        return seen
    }

    const bodies = {}
    nodes.forEach((node) => {
        const mine = next[node.id] || []
        const into = mine.filter((edge) => LOOP_HANDLES.has(handleOf(edge))).map((edge) => edge.target)
        if (!into.length) return
        const after = reach(mine.filter((edge) => !LOOP_HANDLES.has(handleOf(edge))).map((edge) => edge.target), node.id)
        const body = [...reach(into, node.id)].filter((id) => !after.has(id))
        if (body.length) bodies[node.id] = body
    })
    return bodies
}

/** `{ [stepId]: loopStepId }`: the innermost loop that repeats each step. */
export const enclosingLoops = (bodies = {}) => {
    const loopOf = {}
    Object.keys(bodies)
        .sort((a, b) => bodies[b].length - bodies[a].length)
        .forEach((loopId) => bodies[loopId].forEach((id) => (loopOf[id] = loopId)))
    return loopOf
}

/** A link from a repeated step back to its loop: "go on with the next item". */
export const isLoopReturn = (edge, loopOf = {}) => !!edge && loopOf[edge.source] === edge.target

/**
 * Top-to-bottom arrangement of the whole graph.
 * `sizes` is `{ [id]: { width, height } }` or a function; a step without a measured size counts as one card.
 * Returns `{ [id]: { x, y } }`.
 */
export const layoutGraph = (nodes = [], edges = [], sizes = {}) => {
    if (!nodes.length) return {}
    const { order, backIds } = walkGraph(nodes, edges)
    const rank = Object.fromEntries(order.map((id, index) => [id, index]))
    const forward = cleanEdges(nodes, edges).filter((edge) => !backIds.has(edge.id))
    const bodies = loopBodies(nodes, edges)

    // What comes after a loop sits below everything the loop repeats.
    const below = []
    const firstOfLoop = new Set()
    const bodyIds = new Set()
    Object.entries(bodies).forEach(([loopId, body]) => {
        const inside = new Set(body)
        body.forEach((id) => bodyIds.add(id))
        forward
            .filter((edge) => edge.source === loopId)
            .forEach((edge) => {
                if (inside.has(edge.target)) firstOfLoop.add(edge.target)
                else body.forEach((id) => below.push({ source: id, target: edge.target }))
            })
    })

    // Layers: a step sits one layer below the lowest step that leads to it.
    const layerOf = {}
    const incoming = {}
    const outgoing = {}
    ;[...forward, ...below].forEach((edge) => {
        incoming[edge.target] = (incoming[edge.target] || 0) + 1
        ;(outgoing[edge.source] ||= []).push(edge.target)
    })
    const queue = order.filter((id) => !incoming[id])
    queue.forEach((id) => (layerOf[id] = 0))
    for (let i = 0; i < queue.length; i++) {
        const id = queue[i]
        ;(outgoing[id] || []).forEach((target) => {
            layerOf[target] = Math.max(layerOf[target] ?? 0, layerOf[id] + 1)
            if (--incoming[target] === 0) queue.push(target)
        })
    }
    const deepest = Math.max(0, ...Object.values(layerOf))
    order.forEach((id) => (layerOf[id] ??= deepest + 1))

    const layers = []
    order.forEach((id) => (layers[layerOf[id]] ||= []).push(id))

    // Columns: a step sits under the steps that lead to it; the outputs of one step spread left to right.
    const children = {}
    const parents = {}
    forward.forEach((edge) => {
        const list = (children[edge.source] ||= [])
        if (!list.includes(edge.target)) list.push(edge.target)
        const sources = (parents[edge.target] ||= [])
        if (!sources.includes(edge.source)) sources.push(edge.source)
    })
    const center = {}
    const wish = (id) => {
        const placed = (parents[id] || []).filter((parent) => center[parent] !== undefined)
        if (!placed.length) return null
        const total = placed.reduce((sum, parent) => {
            const siblings = children[parent]
            const spread = Math.max(...siblings.map((child) => sizeFrom(sizes, child).width)) + COLUMN_GAP
            return sum + center[parent] + (siblings.indexOf(id) - (siblings.length - 1) / 2) * spread
        }, 0)
        return total / placed.length
    }
    let freeRight = -Infinity
    layers.forEach((layer) => {
        const wished = layer
            .map((id) => ({ id, x: wish(id) }))
            .sort((a, b) => (a.x === null) - (b.x === null) || a.x - b.x || rank[a.id] - rank[b.id])
        let right = -Infinity
        wished.forEach(({ id, x }) => {
            const half = sizeFrom(sizes, id).width / 2
            const edge = Math.max(right, x === null ? freeRight : -Infinity)
            // A step nothing leads to starts its own column, right of what is placed so far.
            const wanted = x === null ? (edge === -Infinity ? 0 : edge + COLUMN_GAP + half) : x
            center[id] = right === -Infinity ? wanted : Math.max(wanted, right + COLUMN_GAP + half)
            right = center[id] + half
        })
        freeRight = Math.max(freeRight, right)
    })

    // Rows.
    const top = []
    let y = 0
    layers.forEach((layer, index) => {
        if (layer.some((id) => firstOfLoop.has(id))) y += FRAME_HEAD
        else if (index > 0 && layers[index - 1].some((id) => bodyIds.has(id)) && !layer.some((id) => bodyIds.has(id))) y += FRAME_PAD
        top[index] = y
        y += Math.max(...layer.map((id) => sizeFrom(sizes, id).height)) + ROW_GAP
    })

    const left = Math.min(...order.map((id) => center[id] - sizeFrom(sizes, id).width / 2))
    return Object.fromEntries(
        order.map((id) => [
            id,
            {
                x: Math.round(center[id] - sizeFrom(sizes, id).width / 2 - left + ORIGIN.x),
                y: Math.round(top[layerOf[id]] + ORIGIN.y),
            },
        ])
    )
}

/**
 * Whether a stored graph was arranged for the old left-to-right canvas (or never arranged): fewer than two thirds
 * of its forward links lead to a step further down.
 */
export const needsLayout = (nodes = [], edges = []) => {
    if (nodes.length < 2) return false
    const { backIds } = walkGraph(nodes, edges)
    const at = Object.fromEntries(nodes.map((node) => [node.id, node.position || { x: 0, y: 0 }]))
    const forward = cleanEdges(nodes, edges).filter((edge) => !backIds.has(edge.id))
    if (!forward.length) return nodes.every((node) => at[node.id].y === at[nodes[0].id].y)
    const down = forward.filter((edge) => at[edge.target].y >= at[edge.source].y + 40).length
    return down / forward.length < 2 / 3
}

/**
 * Frames around the steps of each loop, from where the cards are now.
 * Returns `[{ id, x, y, width, height }]`, the outer loop first.
 */
export const loopFrames = (nodes = [], bodies = {}, sizes = {}) => {
    const byId = Object.fromEntries(nodes.map((node) => [node.id, node]))
    return Object.keys(bodies)
        .sort((a, b) => bodies[b].length - bodies[a].length)
        .map((loopId) => {
            const boxes = bodies[loopId]
                .filter((id) => byId[id])
                .map((id) => ({ ...(byId[id].position || { x: 0, y: 0 }), ...sizeFrom(sizes, id) }))
            if (!boxes.length || !byId[loopId]) return null
            const x = Math.min(...boxes.map((box) => box.x)) - FRAME_PAD
            const y = Math.min(...boxes.map((box) => box.y)) - FRAME_HEAD
            return {
                id: loopId,
                x,
                y,
                width: Math.max(...boxes.map((box) => box.x + box.width)) + FRAME_PAD - x,
                height: Math.max(...boxes.map((box) => box.y + box.height)) + FRAME_PAD - y,
            }
        })
        .filter(Boolean)
}

/** Box around every card: `{ x, y, width, height }`, or null for an empty canvas. */
export const graphBounds = (nodes = [], sizes = {}) => {
    if (!nodes.length) return null
    const boxes = nodes.map((node) => ({ ...(node.position || { x: 0, y: 0 }), ...sizeFrom(sizes, node.id) }))
    const x = Math.min(...boxes.map((box) => box.x))
    const y = Math.min(...boxes.map((box) => box.y))
    return {
        x,
        y,
        width: Math.max(...boxes.map((box) => box.x + box.width)) - x,
        height: Math.max(...boxes.map((box) => box.y + box.height)) - y,
    }
}

/**
 * First view of a workflow: everything when the cards stay readable, otherwise its top at the smallest readable
 * zoom (the rest is reached by scrolling). Returns `{ x, y, zoom }` for the canvas viewport.
 * `focusX` is the centre of the start card: a diagram wider than the canvas opens with that card in the middle.
 */
export const openingView = (bounds, view, { minZoom = 0.9, maxZoom = 1, padding = 24, focusX = null } = {}) => {
    if (!bounds || !view?.width || !view?.height) return { x: 0, y: 0, zoom: maxZoom }
    const fit = Math.min((view.width - padding * 2) / bounds.width, (view.height - padding * 2) / bounds.height)
    const zoom = Math.min(maxZoom, Math.max(minZoom, fit))
    const width = bounds.width * zoom
    const height = bounds.height * zoom
    const middle = focusX ?? bounds.x + bounds.width / 2
    return {
        x: Math.round(width <= view.width - padding * 2 ? (view.width - width) / 2 - bounds.x * zoom : view.width / 2 - middle * zoom),
        // Centred when it fits, otherwise from its top edge.
        y: Math.round(height <= view.height - padding * 2 ? (view.height - height) / 2 - bounds.y * zoom : padding - bounds.y * zoom),
        zoom,
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Link paths
// ---------------------------------------------------------------------------------------------------------------------

/** SVG path through `points` with rounded corners. */
export const roundedPath = (points, radius = 10) => {
    const distinct = points.filter((point, index) => index === 0 || point.x !== points[index - 1].x || point.y !== points[index - 1].y)
    // A point on the straight line between its neighbours is not a corner.
    const clean = distinct.filter((point, index) => {
        const before = distinct[index - 1]
        const after = distinct[index + 1]
        if (!before || !after) return true
        return (point.x - before.x) * (after.y - point.y) !== (point.y - before.y) * (after.x - point.x)
    })
    if (clean.length < 2) return ''
    let path = `M ${clean[0].x} ${clean[0].y}`
    for (let i = 1; i < clean.length - 1; i++) {
        const before = clean[i - 1]
        const corner = clean[i]
        const after = clean[i + 1]
        const inLength = Math.hypot(corner.x - before.x, corner.y - before.y)
        const outLength = Math.hypot(after.x - corner.x, after.y - corner.y)
        const r = Math.min(radius, inLength / 2, outLength / 2)
        const start = { x: corner.x - ((corner.x - before.x) / inLength) * r, y: corner.y - ((corner.y - before.y) / inLength) * r }
        const end = { x: corner.x + ((after.x - corner.x) / outLength) * r, y: corner.y + ((after.y - corner.y) / outLength) * r }
        path += ` L ${start.x} ${start.y} Q ${corner.x} ${corner.y} ${end.x} ${end.y}`
    }
    const last = clean[clean.length - 1]
    return `${path} L ${last.x} ${last.y}`
}

/**
 * Path of a link. Every link ends on the top of its target card, pointing down.
 * - `kind: 'forward'`: down from the output, across, down into the target
 * - `kind: 'back'`: out to the right of the cards (`laneX`), up, and in from above the target
 * - `kind: 'error'`: leaves the error output on the right edge of the card
 * Returns `{ path, labelX, labelY }`; the label point is where the remove button of a selected link goes.
 */
export const linkPath = ({ kind = 'forward', sourceX, sourceY, targetX, targetY, laneX = null }) => {
    const rise = 24
    if (kind === 'error') {
        const x = Math.max(sourceX + 28, laneX ?? -Infinity)
        const points = [
            { x: sourceX, y: sourceY },
            { x, y: sourceY },
            { x, y: targetY - rise },
            { x: targetX, y: targetY - rise },
            { x: targetX, y: targetY },
        ]
        return { path: roundedPath(points), labelX: x, labelY: (sourceY + targetY - rise) / 2 }
    }
    if (kind === 'back') {
        const x = Math.max(sourceX, targetX) + 60
        const lane = Math.max(x, laneX ?? -Infinity)
        // Lands a little right of the middle, beside the link that comes from above.
        const landing = targetX + 28
        const points = [
            { x: sourceX, y: sourceY },
            { x: sourceX, y: sourceY + rise },
            { x: lane, y: sourceY + rise },
            { x: lane, y: targetY - rise },
            { x: landing, y: targetY - rise },
            { x: landing, y: targetY },
        ]
        return { path: roundedPath(points), labelX: lane, labelY: (sourceY + targetY) / 2, endX: landing }
    }
    // An output right above its target: one straight line (the two centres can differ by a fraction of a pixel).
    if (Math.abs(sourceX - targetX) < 3) {
        return { path: `M ${sourceX} ${sourceY} L ${targetX} ${targetY}`, labelX: targetX, labelY: (sourceY + targetY) / 2 }
    }
    const drop = Math.max(12, Math.min(34, (targetY - sourceY) / 2))
    const points = [
        { x: sourceX, y: sourceY },
        { x: sourceX, y: sourceY + drop },
        { x: targetX, y: sourceY + drop },
        { x: targetX, y: targetY },
    ]
    return {
        path: roundedPath(points),
        labelX: targetX,
        labelY: (sourceY + drop + targetY) / 2,
    }
}

/** Arrowhead pointing down onto `(x, y)`. */
export const arrowHead = (x, y, size = 6) => `M ${x - size} ${y - size * 1.6} L ${x + size} ${y - size * 1.6} L ${x} ${y} Z`
