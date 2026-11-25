import { describe, expect, it } from 'vitest'

import {
    CARD_HEIGHT,
    CARD_WIDTH,
    arrowHead,
    enclosingLoops,
    graphBounds,
    isLoopReturn,
    layoutGraph,
    linkPath,
    loopBodies,
    loopFrames,
    needsLayout,
    openingView,
    walkGraph,
} from '@/components/workflows/graphLayout'
import { serializeGraph, toCanvasGraph } from '@/components/workflows/graphRules'

const node = (id: string, extra: Record<string, any> = {}, position = { x: 0, y: 0 }) => ({
    id,
    type: 'step',
    position,
    data: { step_code: id, name: id, step_type: 'log_message', step_config: {}, ...extra },
})
const edge = (source: string, target: string, sourceHandle: string | null = null) => ({
    id: `${source}-${sourceHandle ?? 'output'}-${target}`,
    source,
    target,
    sourceHandle: sourceHandle ?? 'output',
})

// A module sample workflow "Story (split)": a loop, a rewrite link that goes back, four error links.
const storyCodes = ['trigger', 'select', 'each', 'lock', 'research', 'gate', 'jev', 'route', 'review', 'publish', 'notify']
const storyNodes = storyCodes.map((code, index) => node(code, code === 'trigger' ? { is_start: true } : {}, { x: index * 280, y: 0 }))
const storyEdges = [
    edge('trigger', 'select'),
    edge('select', 'each'),
    edge('each', 'lock', 'loop'),
    edge('lock', 'research', 'acquired'),
    edge('lock', 'each', 'busy'),
    edge('research', 'gate'),
    edge('research', 'each', 'error'),
    edge('gate', 'jev'),
    edge('gate', 'each', 'error'),
    edge('jev', 'route'),
    edge('jev', 'each', 'error'),
    edge('route', 'each', 'case:fail'),
    edge('route', 'review', 'default'),
    edge('review', 'publish', 'approved'),
    edge('review', 'research', 'changes_requested'),
    edge('review', 'each', 'rejected'),
    edge('review', 'each', 'expired'),
    edge('publish', 'notify'),
    edge('publish', 'each', 'error'),
    edge('notify', 'each'),
]
const body = ['lock', 'research', 'gate', 'jev', 'route', 'review', 'publish', 'notify']

const place = (nodes: any[], positions: Record<string, { x: number; y: number }>) =>
    nodes.map((item) => ({ ...item, position: positions[item.id] }))

describe('workflow canvas: which links go back, which steps a loop repeats', () => {
    it('tells the links that return to an earlier step from the ones that lead on', () => {
        const { order, backIds } = walkGraph(storyNodes, storyEdges)
        expect(order).toEqual(storyCodes)
        expect([...backIds].sort()).toEqual(
            ['gate-error-each', 'jev-error-each', 'lock-busy-each', 'notify-output-each', 'publish-error-each', 'research-error-each', 'review-changes_requested-research', 'review-expired-each', 'review-rejected-each', 'route-case:fail-each'].sort()
        )
    })

    it('finds the steps of a loop: what "each item" leads to, without what comes after the loop', () => {
        expect(loopBodies(storyNodes, storyEdges)).toEqual({ each: body })

        // A step the "done" output leads to is after the loop, even when a repeated step can reach it too.
        const nodes = [node('start', { is_start: true }), node('each'), node('work'), node('report')]
        const edges = [edge('start', 'each'), edge('each', 'work', 'each'), edge('work', 'each'), edge('work', 'report', 'error'), edge('each', 'report', 'done')]
        expect(loopBodies(nodes, edges)).toEqual({ each: ['work'] })
    })

    it('knows the innermost loop of a step and what "next item" means', () => {
        const nodes = [node('start', { is_start: true }), node('outer'), node('inner'), node('work'), node('after')]
        const edges = [edge('start', 'outer'), edge('outer', 'inner', 'each'), edge('inner', 'work', 'each'), edge('work', 'inner'), edge('inner', 'after', 'done'), edge('after', 'outer')]
        const loopOf = enclosingLoops(loopBodies(nodes, edges))
        expect(loopOf).toEqual({ inner: 'outer', work: 'inner', after: 'outer' })
        expect(isLoopReturn(edge('work', 'inner'), loopOf)).toBe(true)
        expect(isLoopReturn(edge('after', 'outer'), loopOf)).toBe(true)
        expect(isLoopReturn(edge('inner', 'work', 'each'), loopOf)).toBe(false)
    })
})

describe('workflow canvas: top-to-bottom arrangement', () => {
    it('lays the sample out as one readable column, every forward link leading down', () => {
        const positions = layoutGraph(storyNodes, storyEdges)
        expect(Object.keys(positions)).toEqual(storyCodes)

        // One step per row, in reading order, with room between the cards.
        storyCodes.slice(1).forEach((code, index) => {
            expect(positions[code].y - positions[storyCodes[index]].y).toBeGreaterThanOrEqual(CARD_HEIGHT + 40)
        })
        expect(new Set(Object.values(positions).map((position) => position.x)).size).toBe(1)
        // The first step of the loop leaves room for the title of the loop's frame.
        expect(positions.lock.y - positions.each.y).toBeGreaterThan(positions.select.y - positions.trigger.y)
    })

    it('spreads the outputs of a step left to right in catalog order and never overlaps two cards', () => {
        const nodes = [node('start', { is_start: true }), node('ask'), node('yes'), node('no'), node('join')]
        const edges = [edge('start', 'ask'), edge('ask', 'yes', 'true'), edge('ask', 'no', 'false'), edge('yes', 'join'), edge('no', 'join')]
        const positions = layoutGraph(nodes, edges)

        expect(positions.yes.y).toBe(positions.no.y)
        expect(positions.no.x - positions.yes.x).toBeGreaterThanOrEqual(CARD_WIDTH + 24)
        // The branches join under the step that split them.
        expect(positions.join.y).toBeGreaterThan(positions.yes.y)
        expect(positions.join.x).toBe(positions.ask.x)
        expect(positions.ask.x).toBe((positions.yes.x + positions.no.x) / 2)
    })

    it('puts what comes after a loop below everything the loop repeats', () => {
        const nodes = [node('start', { is_start: true }), node('each'), node('a'), node('b'), node('after')]
        const edges = [edge('start', 'each'), edge('each', 'a', 'each'), edge('a', 'b'), edge('b', 'each'), edge('each', 'after', 'done')]
        const positions = layoutGraph(nodes, edges)
        expect(positions.after.y).toBeGreaterThan(positions.b.y + CARD_HEIGHT)
    })

    it('uses the measured size of the cards, keeps a step nothing leads to in its own column, and survives an empty canvas', () => {
        expect(layoutGraph([], [])).toEqual({})

        const nodes = [node('start', { is_start: true }), node('wide'), node('next'), node('lonely')]
        const edges = [edge('start', 'wide'), edge('wide', 'next')]
        const positions = layoutGraph(nodes, edges, { wide: { width: 460, height: 150 } })
        expect(positions.next.y - positions.wide.y).toBeGreaterThanOrEqual(150 + 40)
        // Cards share their centre line, whatever their width.
        expect(positions.wide.x + 230).toBe(positions.start.x + CARD_WIDTH / 2)
        expect(positions.lonely.x).toBeGreaterThanOrEqual(positions.start.x + CARD_WIDTH + 24)
        expect(Math.min(...Object.values(positions).map((position) => position.x))).toBeGreaterThanOrEqual(0)
    })

    it('arranges on open only a diagram saved for the left-to-right canvas, never one already arranged', () => {
        // The sample as its installer stores it: every step on one row.
        expect(needsLayout(storyNodes, storyEdges)).toBe(true)
        const arranged = place(storyNodes, layoutGraph(storyNodes, storyEdges))
        expect(needsLayout(arranged, storyEdges)).toBe(false)
        // An operator who moved one card sideways keeps their arrangement.
        arranged[4] = { ...arranged[4], position: { x: arranged[4].position.x + 400, y: arranged[4].position.y } }
        expect(needsLayout(arranged, storyEdges)).toBe(false)
        expect(needsLayout([node('only')], [])).toBe(false)
    })

    it('changes nothing but positions: the saved steps and links are the same before and after', () => {
        const byType = {}
        const before = serializeGraph(storyNodes, storyEdges, byType)
        const after = serializeGraph(place(storyNodes, layoutGraph(storyNodes, storyEdges)), storyEdges, byType)

        const strip = (graph: any) => ({ nodes: graph.nodes.map(({ position, ...rest }: any) => rest), edges: graph.edges })
        expect(strip(after)).toEqual(strip(before))
        expect(after.nodes.some((item: any, index: number) => item.position.y !== before.nodes[index].position.y)).toBe(true)
        // Handles survive a load of what was saved (the error output stays `error`).
        const loaded = toCanvasGraph(after.nodes, after.edges, byType)
        expect(loaded.edges.filter((item: any) => item.sourceHandle === 'error')).toHaveLength(4)
    })
})

describe('workflow canvas: loop frame and first view', () => {
    it('frames the steps of a loop where the cards are now, with room for its title', () => {
        const arranged = place(storyNodes, layoutGraph(storyNodes, storyEdges))
        const [frame] = loopFrames(arranged, loopBodies(storyNodes, storyEdges))
        const at = Object.fromEntries(arranged.map((item) => [item.id, item.position]))

        expect(frame.id).toBe('each')
        expect(frame.y).toBeLessThan(at.lock.y - 30)
        // The loop step itself and what is above stay outside the frame.
        expect(frame.y).toBeGreaterThan(at.each.y + CARD_HEIGHT)
        expect(frame.y + frame.height).toBeGreaterThan(at.notify.y + CARD_HEIGHT)
        expect(frame.x).toBeLessThan(at.lock.x)
        expect(frame.x + frame.width).toBeGreaterThan(at.lock.x + CARD_WIDTH)
    })

    it('opens a long workflow at its top at 90 %, a short one whole, and never smaller', () => {
        const arranged = place(storyNodes, layoutGraph(storyNodes, storyEdges))
        const bounds = graphBounds(arranged)!
        const tall = openingView(bounds, { width: 900, height: 600 })
        expect(tall.zoom).toBe(0.9)
        // Top of the diagram at the top of the canvas, centred across.
        expect(tall.y).toBe(Math.round(24 - bounds.y * 0.9))
        expect(tall.x).toBe(Math.round((900 - bounds.width * 0.9) / 2 - bounds.x * 0.9))

        const short = openingView({ x: 40, y: 32, width: 300, height: 260 }, { width: 900, height: 600 })
        expect(short.zoom).toBe(1)
        expect(short.y).toBe(Math.round((600 - 260) / 2 - 32))

        // A phone: the cards still open readable, with the start card in the middle of the screen.
        const phone = openingView({ x: 40, y: 32, width: 520, height: 1800 }, { width: 328, height: 500 }, { focusX: 300 })
        expect(phone.zoom).toBe(0.9)
        expect(phone.x).toBe(Math.round(328 / 2 - 300 * 0.9))
        expect(phone.y).toBe(Math.round(24 - 32 * 0.9))
    })
})

describe('workflow canvas: link paths', () => {
    const numbers = (path: string) => (path.match(/-?\d+(\.\d+)?/g) || []).map(Number)

    it('draws a forward link down from its output and into the top of the next card', () => {
        const straight = linkPath({ sourceX: 100, sourceY: 50, targetX: 100, targetY: 150 })
        expect(straight.path).toBe('M 100 50 L 100 150')

        const bent = linkPath({ sourceX: 100, sourceY: 50, targetX: 300, targetY: 150 })
        expect(bent.path.startsWith('M 100 50')).toBe(true)
        expect(bent.path.endsWith('L 300 150')).toBe(true)
        expect(bent.labelX).toBe(300)
    })

    it('routes a link that goes back on the right of the cards and lands it from above', () => {
        const back = linkPath({ kind: 'back', sourceX: 100, sourceY: 500, targetX: 150, targetY: 100, laneX: 520 })
        const xs = numbers(back.path).filter((_, index) => index % 2 === 0)
        expect(Math.max(...xs)).toBe(520)
        expect(back.labelX).toBe(520)
        // It ends beside the middle of the card, pointing down.
        expect(back.endX).toBe(178)
        expect(back.path.endsWith('L 178 100')).toBe(true)
        expect(back.path).toContain('76')

        // Without cards in the way it still clears both ends.
        expect(linkPath({ kind: 'back', sourceX: 100, sourceY: 500, targetX: 150, targetY: 100 }).labelX).toBe(210)
    })

    it('starts an error link on the right edge of the card', () => {
        const error = linkPath({ kind: 'error', sourceX: 340, sourceY: 60, targetX: 600, targetY: 300 })
        expect(error.path.startsWith('M 340 60 L')).toBe(true)
        expect(error.path.endsWith('L 600 300')).toBe(true)
        expect(error.labelX).toBe(368)
    })

    it('points every arrow down onto the target', () => {
        expect(arrowHead(100, 50)).toBe('M 94 40.4 L 106 40.4 L 100 50 Z')
    })
})
