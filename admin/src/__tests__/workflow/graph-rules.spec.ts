import { describe, expect, it } from 'vitest'

import {
    canvasEdge,
    errorLinkVisible,
    errorModeOf,
    mainTargetOf,
    outputTone,
    planErrorMode,
    planLink,
    checkGraph,
    createHistory,
    handleLabelKey,
    isStartNode,
    isValidConnection,
    planConnection,
    serializeEdge,
    serializeGraph,
    serializeNode,
    stepHandles,
    stepTypeForSave,
    toCanvasGraph,
} from '@/components/workflows/graphRules'

const byType: Record<string, any> = {
    trigger_manual: { type: 'trigger_manual', group: 'trigger', outputs: ['main'] },
    ai_prompt: { type: 'ai_prompt', group: 'ai', outputs: ['main'] },
    approval: { type: 'approval', group: 'human', outputs: ['approved', 'rejected', 'expired', 'changes_requested'] },
    switch: { type: 'switch', group: 'flow', outputs: ['default'] },
    loop_each: { type: 'loop_each', group: 'flow', outputs: ['each', 'done'] },
    terminate_workflow: { type: 'terminate_workflow', group: 'flow', outputs: [] },
    log_message: { type: 'log_message', group: 'data', outputs: ['main'] },
}

const node = (id: string, stepType: string, extra: Record<string, any> = {}, type = 'custom') => ({
    id,
    type,
    position: { x: 0, y: 0 },
    data: { step_code: id, name: id, step_type: stepType, step_config: {}, ...extra },
})

const ids = (handles: Array<{ id: string }>) => handles.map((handle) => handle.id)

describe('workflow designer: output handles of a step', () => {
    it('gives every step its outputs and an error output, a start step only its next output', () => {
        expect(ids(stepHandles(byType.ai_prompt))).toEqual(['output', 'error'])
        expect(ids(stepHandles(byType.approval))).toEqual(['approved', 'rejected', 'expired', 'changes_requested', 'error'])
        expect(ids(stepHandles(byType.trigger_manual, {}, [], { start: true }))).toEqual(['output'])
        // A step that ends the workflow, and the legacy end node, lead nowhere.
        expect(stepHandles(byType.terminate_workflow)).toEqual([])
        expect(stepHandles(null, {}, [], { end: true })).toEqual([])
        // A step the catalog does not know.
        expect(ids(stepHandles(null))).toEqual(['output', 'error'])
    })

    it('adds one output per switch case and per AI classify label, before the catch-all output', () => {
        const cases = stepHandles(byType.switch, { cases: [{ key: 'urgent', match: 'urgent' }, { key: '', match: 'x' }, { key: 'later' }] })
        expect(cases.map((handle) => [handle.id, handle.kind, handle.name])).toEqual([
            ['case:urgent', 'case', 'urgent'],
            ['case:later', 'case', 'later'],
            ['default', 'named', 'default'],
            ['error', 'error', 'error'],
        ])
        expect(ids(stepHandles(byType.switch, { labels: { spam: 'Junk', ok: 'Fine' } }))).toEqual(['case:spam', 'case:ok', 'default', 'error'])
        expect(ids(stepHandles(byType.switch, { labels: ['a', { key: 'b' }] }))).toEqual(['case:a', 'case:b', 'default', 'error'])
    })

    it('keeps the handle an existing link uses: a runtime alias, a removed case, an old catch-all link', () => {
        // `loop` is what older graphs call the `each` output of a loop.
        expect(ids(stepHandles(byType.loop_each, {}, ['loop']))).toEqual(['loop', 'done', 'error'])
        expect(ids(stepHandles(byType.loop_each, {}, ['each']))).toEqual(['each', 'done', 'error'])
        const stale = stepHandles(byType.switch, { cases: [] }, ['case:gone', null])
        expect(stale.map((handle) => [handle.id, handle.kind])).toEqual([
            ['default', 'named'],
            ['case:gone', 'legacy'],
            ['output', 'main'],
            ['error', 'error'],
        ])
    })

    it('names outputs through the output translation keys, cases by their own name', () => {
        expect(handleLabelKey({ id: 'output', kind: 'main', name: 'main' })).toBe('text.workflow.output.main')
        expect(handleLabelKey({ id: 'loop', kind: 'named', name: 'each' })).toBe('text.workflow.output.each')
        expect(handleLabelKey({ id: 'error', kind: 'error', name: 'error' })).toBe('text.workflow.output.error')
        expect(handleLabelKey({ id: 'case:urgent', kind: 'case', name: 'urgent' })).toBe('')
    })
})

describe('workflow designer: loading a stored graph', () => {
    it('recognises the start node from the catalog, the server flag or the legacy input node', () => {
        expect(isStartNode(node('a', 'trigger_manual'), byType)).toBe(true)
        expect(isStartNode(node('a', 'trigger_manual', { is_start: true }), {})).toBe(true)
        expect(isStartNode(node('a', 'event', { step_config: { node_type: 'input' } }), {})).toBe(true)
        expect(isStartNode(node('a', 'ai_prompt'), byType)).toBe(false)
    })

    it('attaches a link without a source handle to the main output and drops links to missing steps', () => {
        const graph = toCanvasGraph(
            [
                { ...node('start', 'trigger_manual', { node_type: 'input', is_start: true }, 'input'), position: { x: '10', y: '20.5' } },
                node('ai', 'ai_prompt'),
                node('check', 'approval'),
            ],
            [
                { id: 'edge-1', source: 'start', target: 'ai', sourceHandle: null, targetHandle: null },
                { id: 'edge-2', source: 'ai', target: 'check' },
                { id: 'edge-3', source: 'check', target: 'ai', sourceHandle: 'changes_requested', data: { condition_value: 'changes_requested' } },
                { id: 'edge-4', source: 'check', target: 'ghost', sourceHandle: 'approved' },
                { id: 'edge-5', source: 'start', target: 'ai', sourceHandle: '' },
            ],
            byType
        )

        expect(graph.nodes.map((item: any) => [item.id, item.type, item.position.x, item.position.y, item.data.is_start === true])).toEqual([
            ['start', 'step', 10, 20.5, true],
            ['ai', 'step', 0, 0, false],
            ['check', 'step', 0, 0, false],
        ])
        // The duplicate start → ai link and the link to a missing step are gone.
        expect(graph.edges.map((edge: any) => [edge.id, edge.sourceHandle, edge.targetHandle])).toEqual([
            ['edge-1', 'output', 'input'],
            ['edge-2', 'output', 'input'],
            ['edge-3', 'changes_requested', 'input'],
        ])
    })
})

describe('workflow designer: drawing links', () => {
    const nodes = [node('start', 'trigger_manual'), node('ai', 'ai_prompt'), node('check', 'approval')]

    it('refuses a link from a step to itself, onto the start step, or to a step that is gone', () => {
        expect(isValidConnection({ source: 'ai', target: 'check' }, nodes, byType)).toBe(true)
        expect(isValidConnection({ source: 'ai', target: 'ai' }, nodes, byType)).toBe(false)
        expect(isValidConnection({ source: 'ai', target: 'start' }, nodes, byType)).toBe(false)
        expect(isValidConnection({ source: 'ai', target: 'ghost' }, nodes, byType)).toBe(false)
    })

    it('moves the link when an output is linked a second time, and ignores a repeated link', () => {
        const edges = [
            { id: 'e1', source: 'check', target: 'ai', sourceHandle: 'approved' },
            { id: 'e2', source: 'check', target: 'ai', sourceHandle: 'rejected' },
        ]

        const moved = planConnection({ source: 'check', sourceHandle: 'approved', target: 'start', targetHandle: 'input' }, edges)
        expect(moved?.replacedIds).toEqual(['e1'])
        expect(moved?.edge).toMatchObject({ source: 'check', sourceHandle: 'approved', target: 'start', targetHandle: 'input' })

        expect(planConnection({ source: 'check', sourceHandle: 'approved', target: 'ai' }, edges)).toBeNull()
        // Another output of the same step is a link of its own.
        expect(planConnection({ source: 'check', sourceHandle: 'expired', target: 'ai' }, edges)?.replacedIds).toEqual([])
    })
})

describe('workflow designer: one link per output', () => {
    const link = (source: string, target: string, sourceHandle = 'output') => ({ id: `edge-${source}-${sourceHandle}-${target}`, source, target, sourceHandle, targetHandle: 'input', data: {} })

    it('replaces the link of an output that is connected again, and leaves the other outputs alone', () => {
        const edges = [link('review', 'publish', 'approved'), link('review', 'stop', 'rejected')]
        const plan = planLink({ source: 'review', target: 'archive', sourceHandle: 'approved' }, edges)!
        expect(plan.removedIds).toEqual(['edge-review-approved-publish'])
        expect(plan.add).toEqual([link('review', 'archive', 'approved')])

        // Applying the plan never leaves two links on one output.
        const after = [...edges.filter((edge) => !plan.removedIds.includes(edge.id)), ...plan.add]
        expect(after.filter((edge) => edge.source === 'review' && edge.sourceHandle === 'approved')).toHaveLength(1)
        expect(after).toContainEqual(link('review', 'stop', 'rejected'))
        // The same link again changes nothing.
        expect(planLink({ source: 'review', target: 'archive', sourceHandle: 'approved' }, after)).toBeNull()
    })

    it('lets many links end on one step: that is how branches join', () => {
        const edges = [link('yes', 'join')]
        const plan = planLink({ source: 'no', target: 'join', sourceHandle: 'output' }, edges)!
        expect(plan.removedIds).toEqual([])
        expect(plan.add).toHaveLength(1)
    })

    it('gives every link the designer\'s link component', () => {
        expect(canvasEdge(link('a', 'b'))).toMatchObject({ type: 'link', source: 'a', target: 'b', sourceHandle: 'output' })
    })

    it('colours an output by what it means', () => {
        expect(['approved', 'true', 'yes'].map(outputTone)).toEqual(['green', 'green', 'green'])
        expect(['rejected', 'false', 'no'].map(outputTone)).toEqual(['red', 'red', 'red'])
        expect(outputTone('expired')).toBe('grey')
        expect(outputTone('changes_requested')).toBe('amber')
        expect(outputTone('error')).toBe('error')
        expect([null, 'output', 'done', 'default', 'case:urgent'].map(outputTone)).toEqual(['slate', 'slate', 'slate', 'slate', 'slate'])
        expect(outputTone({ id: 'loop', kind: 'named', name: 'each' })).toBe('slate')
        expect(outputTone({ id: 'error', kind: 'error', name: 'error' })).toBe('error')
    })
})

describe('workflow designer: when a step fails', () => {
    const link = (source: string, target: string, sourceHandle = 'output') => ({ id: `edge-${source}-${sourceHandle}-${target}`, source, target, sourceHandle, targetHandle: 'input', data: {} })
    const apply = (edges: any[], plan: any) => [...edges.filter((edge) => !plan.removedIds.includes(edge.id)), ...plan.add]

    it('reads the mode from the links: stop, carry on, or its own path', () => {
        expect(errorModeOf('post', [link('post', 'tell')])).toBe('stop')
        expect(errorModeOf('post', [link('post', 'tell'), link('post', 'tell', 'error')])).toBe('continue')
        expect(errorModeOf('post', [link('post', 'tell'), link('post', 'alert', 'error')])).toBe('route')
        expect(mainTargetOf('post', [link('post', 'tell')])).toBe('tell')
        expect(mainTargetOf('review', [link('review', 'publish', 'approved')])).toBeNull()
    })

    it('"carry on" links the error output to the next step, as an `error` link', () => {
        const edges = [link('post', 'tell')]
        const after = apply(edges, planErrorMode('post', 'continue', edges))
        expect(after).toContainEqual(link('post', 'tell', 'error'))
        expect(errorModeOf('post', after)).toBe('continue')
        // A step whose next output leads nowhere cannot carry on.
        expect(planErrorMode('review', 'continue', [link('review', 'publish', 'approved')])).toBeNull()
    })

    it('"stop" removes the error link; "own path" drops only the carry-on link and keeps a path already drawn', () => {
        const carryOn = [link('post', 'tell'), link('post', 'tell', 'error')]
        expect(apply(carryOn, planErrorMode('post', 'stop', carryOn))).toEqual([link('post', 'tell')])
        expect(apply(carryOn, planErrorMode('post', 'route', carryOn))).toEqual([link('post', 'tell')])

        const own = [link('post', 'tell'), link('post', 'alert', 'error')]
        expect(planErrorMode('post', 'route', own)).toEqual({ add: [], removedIds: [] })
        expect(apply(own, planErrorMode('post', 'continue', own))).toEqual([link('post', 'tell'), link('post', 'tell', 'error')])
        expect(planErrorMode('post', 'retry', own)).toBeNull()
    })

    it('moves the carry-on link with the next output, and leaves an own error path where it is', () => {
        const carryOn = [link('post', 'tell'), link('post', 'tell', 'error')]
        const moved = apply(carryOn, planLink({ source: 'post', target: 'archive', sourceHandle: 'output' }, carryOn))
        expect(moved).toEqual([link('post', 'archive'), link('post', 'archive', 'error')])

        const own = [link('post', 'tell'), link('post', 'alert', 'error')]
        expect(apply(own, planLink({ source: 'post', target: 'archive', sourceHandle: 'output' }, own))).toEqual([link('post', 'alert', 'error'), link('post', 'archive')])
    })
})

describe('workflow designer: checks before a save', () => {
    const report = (nodes: any[], edges: any[]) => Object.fromEntries(checkGraph(nodes, edges, byType).map((check: any) => [check.key, check]))

    it('accepts a workflow that starts with a catalog trigger and ends on steps without a next step', () => {
        const checks = report(
            [node('start', 'trigger_manual'), node('ai', 'ai_prompt'), node('check', 'approval'), node('log', 'log_message')],
            [
                { source: 'start', target: 'ai' },
                { source: 'ai', target: 'check' },
                { source: 'check', target: 'log', sourceHandle: 'approved' },
                { source: 'check', target: 'ai', sourceHandle: 'changes_requested' },
            ]
        )
        expect(Object.values(checks).every((check: any) => check.ok)).toBe(true)
        expect(checks['single-start'].name).toBe('start')
    })

    it('reports a missing or doubled start, and the steps the start does not lead to', () => {
        expect(report([node('ai', 'ai_prompt')], [])['single-start']).toMatchObject({ ok: false, count: 0 })
        expect(report([node('a', 'trigger_manual'), node('b', 'trigger_manual')], [])['single-start']).toMatchObject({ ok: false, count: 2, nodeIds: ['a', 'b'] })

        const loose = report([node('start', 'trigger_manual'), node('ai', 'ai_prompt'), node('log', 'log_message')], [{ source: 'start', target: 'ai' }])
        expect(loose.connected).toMatchObject({ ok: false, nodeIds: ['log'], count: 1 })
        expect(report([], [])['has-nodes'].ok).toBe(false)
    })

    it('checks both branches of the legacy yes/no card only', () => {
        const decision = { ...node('d', 'decision', { step_config: { yes_output: 'target-a', no_output: 'target-b' } }), type: 'decision' }
        const nodes = [node('start', 'trigger_manual'), decision, node('log', 'log_message')]
        const half = report(nodes, [{ source: 'start', target: 'd' }, { source: 'd', target: 'log', sourceHandle: 'target-a' }])
        expect(half['decision-branches']).toMatchObject({ ok: false, nodeIds: ['d'], total: 1 })
        const full = report(nodes, [
            { source: 'start', target: 'd' },
            { source: 'd', target: 'log', sourceHandle: 'target-a' },
            { source: 'd', target: 'log', sourceHandle: 'target-b' },
        ])
        expect(full['decision-branches'].ok).toBe(true)
    })

    it('accepts a template decision linked on its named outputs true / false, one branch being enough', () => {
        const decision = { ...node('d', 'decision', { step_config: { expression: 'vars.spam != null' } }), type: 'decision' }
        const nodes = [node('start', 'trigger_manual'), decision, node('mail', 'email_send')]
        const none = report(nodes, [{ source: 'start', target: 'd' }])
        expect(none['decision-branches']).toMatchObject({ ok: false, nodeIds: ['d'] })
        const oneBranch = report(nodes, [{ source: 'start', target: 'd' }, { source: 'd', target: 'mail', sourceHandle: 'false' }])
        expect(oneBranch['decision-branches'].ok).toBe(true)
        const legacyHandleOnTemplate = report(nodes, [{ source: 'start', target: 'd' }, { source: 'd', target: 'mail', sourceHandle: 'target-a' }])
        expect(legacyHandleOnTemplate['decision-branches'].ok).toBe(false)
    })
})

describe('workflow designer: the saved graph shape', () => {
    it('keeps a catalog trigger as the start node with its own step type', () => {
        const saved = serializeNode({ id: 'start', type: 'step', position: { x: 12.4, y: -3.6 }, data: { name: 'Start', step_code: 'start', step_type: 'trigger_manual', step_config: { storeAs: 'vars.trigger' }, is_start: true, __error: true } }, byType)

        expect(saved).toEqual({
            id: 'start',
            type: 'input',
            position: { x: 12, y: -4 },
            data: { name: 'Start', step_code: 'start', step_type: 'trigger_manual', step_config: { storeAs: 'vars.trigger', node_type: 'input' }, is_start: true },
        })
        // Without the catalog (it failed to load) the stored type still wins over the legacy `input` guess.
        expect(stepTypeForSave({ id: 'start', type: 'input', data: { step_type: 'trigger_manual', step_config: {} } }, {})).toBe('trigger_manual')
        expect(stepTypeForSave({ id: 'in', type: 'input', data: { step_type: 'event', step_config: { node_type: 'input' } } }, {})).toBe('input')
    })

    it('saves steps as `custom` nodes with their catalog type, whatever their settings look like', () => {
        // `level` + `message` used to turn any step into a log step.
        const saved = serializeNode(node('notify', 'ai_prompt', { step_config: { level: 'info', message: 'hi' } }, 'step'), byType)
        expect(saved.type).toBe('custom')
        expect(saved.data.step_type).toBe('ai_prompt')
        expect(saved.data.step_config).toEqual({ level: 'info', message: 'hi' })
    })

    it('stores the main output without a source handle and a named output with its handle', () => {
        const nodes = [node('ai', 'ai_prompt'), node('check', 'approval')]
        expect(serializeEdge({ id: 'e1', source: 'ai', target: 'check', sourceHandle: 'output', targetHandle: 'input', data: { payload: {} } }, nodes)).toEqual({
            id: 'e1', source: 'ai', target: 'check', sourceHandle: null, targetHandle: 'input', data: {},
        })
        // An old link stored as `output` is healed the same way.
        expect(serializeEdge({ id: 'e2', source: 'ai', target: 'check', sourceHandle: 'output', data: { condition: 'output', condition_type: 'equals', condition_value: 'output' } }, nodes).data).toEqual({})
        expect(serializeEdge({ id: 'e3', source: 'check', target: 'ai', sourceHandle: 'changes_requested', data: { condition: 'changes_requested', condition_type: 'equals', condition_value: 'changes_requested' } }, nodes)).toEqual({
            id: 'e3', source: 'check', target: 'ai', sourceHandle: 'changes_requested', targetHandle: 'input',
            data: { condition: 'changes_requested', condition_type: 'equals', condition_value: 'changes_requested' },
        })
        expect(serializeEdge({ id: 'e4', source: 'ai', target: 'check', sourceHandle: 'error' }, nodes).sourceHandle).toBe('error')
    })

    it('keeps the handles of the legacy yes/no card and drops links to removed steps', () => {
        const decision = { ...node('d', 'decision', { step_config: { condition: 'a > 1', yes_output: 'target-a', no_output: 'target-b' } }), type: 'decision' }
        const graph = serializeGraph(
            [decision, node('log', 'log_message')],
            [
                { id: 'e1', source: 'd', target: 'log', sourceHandle: 'target-a' },
                { id: 'e2', source: 'd', target: 'gone', sourceHandle: 'target-b' },
            ],
            byType
        )
        expect(graph.nodes.map((item: any) => [item.id, item.type, item.data.step_type])).toEqual([['d', 'decision', 'decision'], ['log', 'custom', 'log_message']])
        expect(graph.edges.map((edge: any) => [edge.source, edge.sourceHandle, edge.target])).toEqual([['d', 'target-a', 'log']])
    })

    it('round-trips a stored graph: load, then save, gives the same steps and links', () => {
        const stored = {
            nodes: [
                { id: 'trigger', type: 'input', position: { x: 0, y: 0 }, data: { name: 'Start', step_code: 'trigger', step_type: 'trigger_manual', step_config: { storeAs: 'vars.trigger' }, node_type: 'input', is_start: true } },
                { id: 'each', type: 'custom', position: { x: 280, y: 0 }, data: { name: 'Each', step_code: 'each', step_type: 'loop_each', step_config: { collectionExpr: 'vars.items' }, node_type: 'custom', is_start: false } },
                { id: 'log', type: 'custom', position: { x: 560, y: 0 }, data: { name: 'Log', step_code: 'log', step_type: 'log_message', step_config: { level: 'info', message: 'x' }, node_type: 'custom', is_start: false } },
            ],
            edges: [
                { id: 'edge-1', source: 'trigger', target: 'each', sourceHandle: null, targetHandle: null, data: { condition: null, condition_type: null, condition_value: null } },
                { id: 'edge-2', source: 'each', target: 'log', sourceHandle: 'loop', targetHandle: null, data: { condition: 'loop', condition_type: 'equals', condition_value: 'loop' } },
                { id: 'edge-3', source: 'log', target: 'each', sourceHandle: 'error', targetHandle: null, data: { condition: 'error', condition_type: 'equals', condition_value: 'error' } },
            ],
        }
        const canvas = toCanvasGraph(stored.nodes, stored.edges, byType)
        const saved = serializeGraph(canvas.nodes, canvas.edges, byType)

        expect(saved.nodes.map((item: any) => [item.id, item.type, item.data.step_type, item.data.step_code, item.position])).toEqual(
            stored.nodes.map((item) => [item.id, item.type, item.data.step_type, item.data.step_code, item.position])
        )
        expect(saved.nodes[1].data.step_config).toEqual({ collectionExpr: 'vars.items' })
        expect(saved.edges.map((edge: any) => [edge.source, edge.sourceHandle, edge.target, edge.data.condition_value ?? null])).toEqual([
            ['trigger', null, 'each', null],
            ['each', 'loop', 'log', 'loop'],
            ['log', 'error', 'each', 'error'],
        ])
    })
})

describe('workflow designer: undo history', () => {
    it('walks back and forward, drops the redo branch after a new change, and knows what is unsaved', () => {
        const history = createHistory(3)
        const saved = { nodes: ['a'] }
        history.reset(saved)
        expect(history.canUndo()).toBe(false)
        expect(history.differsFrom(saved)).toBe(false)

        expect(history.commit({ nodes: ['a', 'b'] })).toBe(true)
        // An unchanged graph is not a new entry.
        expect(history.commit({ nodes: ['a', 'b'] })).toBe(false)
        expect(history.differsFrom(saved)).toBe(true)

        expect(history.undo()).toEqual(saved)
        expect(history.differsFrom(saved)).toBe(false)
        expect(history.undo()).toBeNull()
        expect(history.redo()).toEqual({ nodes: ['a', 'b'] })
        expect(history.redo()).toBeNull()

        history.undo()
        history.commit({ nodes: ['a', 'c'] })
        expect(history.canRedo()).toBe(false)

        // Only the newest entries are kept.
        history.commit({ nodes: ['a', 'c', 'd'] })
        history.commit({ nodes: ['a', 'c', 'd', 'e'] })
        expect(history.undo()).toEqual({ nodes: ['a', 'c', 'd'] })
        expect(history.undo()).toEqual({ nodes: ['a', 'c'] })
        expect(history.undo()).toBeNull()
    })
})

describe('error links while error paths are hidden', () => {
    const edges = [
        { source: 'pick', target: 'each', sourceHandle: 'main' },
        { source: 'pick', target: 'telegram', sourceHandle: 'error' },
        { source: 'research', target: 'each', sourceHandle: 'error' },
        { source: 'telegram', target: 'stop', sourceHandle: 'main' },
    ]

    it('keeps a link to an error-only branch in sight, so the branch never looks unconnected', () => {
        expect(errorLinkVisible(edges[1], edges)).toBe(true)
    })

    it('hides an error link into a step the normal flow also reaches, until shown or one end is selected', () => {
        expect(errorLinkVisible(edges[2], edges)).toBe(false)
        expect(errorLinkVisible(edges[2], edges, { showErrors: true })).toBe(true)
        expect(errorLinkVisible(edges[2], edges, { sourceSelected: true })).toBe(true)
        expect(errorLinkVisible(edges[2], edges, { targetSelected: true })).toBe(true)
    })

    it('always draws ordinary links', () => {
        expect(errorLinkVisible(edges[0], edges)).toBe(true)
    })
})
