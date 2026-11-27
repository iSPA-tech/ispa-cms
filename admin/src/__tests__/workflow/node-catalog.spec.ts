import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/services/workflowApi', () => ({ fetchNodeCatalog: vi.fn() }))

import {
    applyNodeCatalog,
    buildDropPayload,
    buildPaletteGroups,
    clearSaveErrors,
    lintTaint,
    mapSaveErrors,
    outputHandles,
    recordSaveErrors,
    safetyBadges,
    uniqueStepCode,
    useNodeCatalog,
    useNodeIssues,
} from '@/components/workflows/nodeCatalog'
import { catalogFixture } from './node-catalog-fixture'

const t = (key: string) => key

const canvasNode = (id: string, stepType: string, config: Record<string, unknown> = {}, code = id) => ({
    id,
    type: 'custom',
    data: { name: id, step_code: code, step_type: stepType, step_config: config },
})

describe('workflow node catalog helpers', () => {
    beforeEach(() => {
        applyNodeCatalog(catalogFixture)
        clearSaveErrors()
    })

    it('groups palette nodes in server order and leaves empty groups out', () => {
        const groups = buildPaletteGroups(catalogFixture, '', t)

        expect(groups.map((group) => group.key)).toEqual(['flow', 'cms', 'ai', 'human'])
        expect(groups[0].label).toBe('Flow control')
        expect(groups[2].nodes[0]).toMatchObject({ type: 'ai_action', title: 'AI action', summary: 'Let an AI assistant do one job.' })
        expect(groups[2].icon).toBe('robot-outline')
    })

    it('searches by label, description, type and group name', () => {
        expect(buildPaletteGroups(catalogFixture, 'assistant', t).map((g) => g.key)).toEqual(['ai'])
        expect(buildPaletteGroups(catalogFixture, 'CHANGE', t).flatMap((g) => g.nodes.map((n) => n.type))).toEqual(['change'])
        expect(buildPaletteGroups(catalogFixture, 'people', t).flatMap((g) => g.nodes.map((n) => n.type))).toEqual(['approval'])
        expect(buildPaletteGroups(catalogFixture, 'nothing-like-this', t)).toEqual([])
    })

    it('prefers the admin translation and never shows a raw key', () => {
        const translate = (key: string) => (key === 'text.workflow.group.ai' ? 'Trí tuệ nhân tạo' : key)
        const catalog = {
            groups: [{ key: 'ai', label_key: 'text.workflow.group.ai', label: 'text.workflow.group.ai', order: 1 }],
            nodes: [{ type: 'x_node', group: 'ai', label_key: 'text.workflow.node.x_node.title', label: 'text.workflow.node.x_node.title', description_key: 'text.workflow.node.x_node.desc', description: 'text.workflow.node.x_node.desc' }],
        }
        const [group] = buildPaletteGroups(catalog, '', translate)

        expect(group.label).toBe('Trí tuệ nhân tạo')
        expect(group.nodes[0].title).toBe('x_node')
        expect(group.nodes[0].summary).toBe('')
    })

    it('builds the drop payload with a fresh step code, the default config and the node version', () => {
        const aiAction = catalogFixture.nodes[1]
        const payload = buildDropPayload(aiAction, 'AI action', ['ai_action', 'ai_action_2']) as any

        expect(payload.type).toBe('custom')
        expect(payload.data).toMatchObject({ name: 'AI action', step_type: 'ai_action', step_code: 'ai_action_3' })
        expect(payload.data.step_config).toEqual({ goal: '', storeAs: 'vars.agent_result', toolInput: [], _v: 2 })
        expect(payload.data.step_config).not.toBe(aiAction.default_config)
        // The built-in decision card keeps handling decisions.
        expect(buildDropPayload(catalogFixture.nodes[0], 'Decision')).toBe('decision')
        expect(uniqueStepCode('content.save_post_draft', [])).toBe('content_save_post_draft')
    })

    it('describes output handles: classic, named, case and error route', () => {
        expect(outputHandles(null)).toEqual([{ id: 'output', labelKey: '', label: '' }])
        expect(outputHandles({ outputs: ['main'] })).toEqual([{ id: 'output', labelKey: '', label: '' }])
        expect(outputHandles({ outputs: [] })).toEqual([])
        expect(outputHandles({ outputs: ['approved', 'rejected', 'expired'] }).map((h) => [h.id, h.labelKey])).toEqual([
            ['approved', 'text.workflow.output.approved'],
            ['rejected', 'text.workflow.output.rejected'],
            ['expired', 'text.workflow.output.expired'],
        ])
        expect(outputHandles({ outputs: ['case:urgent', 'default'] })).toEqual([
            { id: 'case:urgent', labelKey: '', label: 'urgent' },
            { id: 'default', labelKey: 'text.workflow.output.default', label: 'default' },
        ])
        expect(outputHandles({ outputs: ['main'] }, { on_error: 'route' }).map((h) => h.id)).toEqual(['output', 'error'])
    })

    it('lists safety badges for known classes only', () => {
        expect(safetyBadges({ safety: ['external_call', 'publish', 'made_up'] }).map((b) => b.key)).toEqual(['external_call', 'publish'])
        expect(safetyBadges(null)).toEqual([])
    })

    it('warns when a write step reads AI output with no approval in between', () => {
        const nodes = [
            canvasNode('n1', 'ai_action', { goal: 'Write', storeAs: 'vars.draft' }, 'write_text'),
            canvasNode('n2', 'change', { targetExpr: 'vars.post', changes: [{ field: 'body', valueExpr: 'vars.draft.text' }] }, 'save_post'),
        ]
        const edges = [{ source: 'n1', target: 'n2' }]

        expect(lintTaint(nodes, edges)).toEqual([
            { step_code: 'save_post', node_id: 'n2', origin: 'write_text', origin_node_id: 'n1', code: 'taint_needs_approval' },
        ])
    })

    it('follows tainted data through a copy step and through steps.<code> references', () => {
        const nodes = [
            canvasNode('n1', 'ai_action', { storeAs: 'vars.draft' }, 'ai'),
            canvasNode('n2', 'set_variable', { assignments: [{ key: 'vars.copy', valueExpr: 'vars.draft' }] }, 'copy'),
            canvasNode('n3', 'change', { changes: [{ field: 'a', valueExpr: 'vars.copy' }] }, 'write_a'),
            canvasNode('n4', 'change', { changes: [{ field: 'b', valueExpr: 'steps.ai.result' }] }, 'write_b'),
        ]
        const edges = [
            { source: 'n1', target: 'n2' },
            { source: 'n2', target: 'n3' },
            { source: 'n3', target: 'n4' },
        ]

        expect(lintTaint(nodes, edges).map((p) => p.step_code)).toEqual(['write_a', 'write_b'])
    })

    it('stays quiet with an approval on the path, for clean data and for unrelated variables', () => {
        const withApproval = [
            canvasNode('n1', 'ai_action', { storeAs: 'vars.draft' }),
            canvasNode('n2', 'approval', {}),
            canvasNode('n3', 'change', { changes: [{ field: 'body', valueExpr: 'vars.draft' }] }),
        ]
        expect(lintTaint(withApproval, [{ source: 'n1', target: 'n2' }, { source: 'n2', target: 'n3' }])).toEqual([])

        const cleanData = [
            canvasNode('n1', 'ai_action', { storeAs: 'vars.draft' }),
            canvasNode('n2', 'change', { changes: [{ field: 'title', valueExpr: 'root.title' }] }),
        ]
        expect(lintTaint(cleanData, [{ source: 'n1', target: 'n2' }])).toEqual([])

        // `vars.draft_old` is another variable; `myvars.draft` is not a variable read.
        const lookalike = [
            canvasNode('n1', 'ai_action', { storeAs: 'vars.draft' }),
            canvasNode('n2', 'change', { changes: [{ field: 'x', valueExpr: 'vars.draft_old + myvars.draft' }] }),
        ]
        expect(lintTaint(lookalike, [{ source: 'n1', target: 'n2' }])).toEqual([])
    })

    it('skips a write the catalog marks taint_sink false, as the server does', () => {
        const catalog = {
            approvalTypes: ['approval'],
            byType: {
                ai_extract: { type: 'ai_extract', safety: ['ai_spend'], taint_output: true },
                'helpdesk.add_note': { type: 'helpdesk.add_note', safety: ['internal_write'], taint_output: false, taint_sink: false },
                change: { type: 'change', safety: ['internal_write'], taint_output: false, taint_sink: true },
                telegram_send: { type: 'telegram_send', safety: ['external_call'], taint_output: false },
            },
        }
        const nodes = [
            canvasNode('n1', 'ai_extract', { storeAs: 'vars.triage' }, 'triage'),
            canvasNode('n2', 'helpdesk.add_note', { summary: 'vars.triage.summary' }, 'note'),
            canvasNode('n3', 'change', { changes: [{ field: 'body', valueExpr: 'vars.triage.summary' }] }, 'save_post'),
        ]
        const edges = [
            { source: 'n1', target: 'n2' },
            { source: 'n2', target: 'n3' },
        ]

        expect(lintTaint(nodes, edges, catalog).map((p) => p.step_code)).toEqual(['save_post'])

        // An outside call is guarded too (the server refuses it on save).
        const outside = [
            canvasNode('n1', 'ai_extract', { storeAs: 'vars.triage' }, 'triage'),
            canvasNode('n2', 'telegram_send', { text: '{{ vars.triage.summary }}' }, 'tell'),
        ]
        expect(lintTaint(outside, [{ source: 'n1', target: 'n2' }], catalog).map((p) => p.step_code)).toEqual(['tell'])
    })

    it('one bypass path around the approval is enough to warn', () => {
        const nodes = [
            canvasNode('n1', 'ai_action', { storeAs: 'vars.draft' }),
            canvasNode('n2', 'approval', {}),
            canvasNode('n3', 'change', { changes: [{ field: 'body', valueExpr: 'vars.draft' }] }),
        ]
        const edges = [
            { source: 'n1', target: 'n2' },
            { source: 'n2', target: 'n3' },
            { source: 'n1', target: 'n3' },
        ]

        expect(lintTaint(nodes, edges).map((p) => p.step_code)).toEqual(['n3'])
    })

    it('maps a refused save (422) onto step codes', () => {
        const error = {
            response: {
                status: 422,
                data: {
                    status: 'error',
                    message: 'save_post: reads untrusted data from write_text; add an approval step between them.; fetch: unknown step type',
                    data: {
                        errors: [
                            { step_code: 'save_post', code: 'taint_needs_approval', message: 'save_post: reads untrusted data from write_text; add an approval step between them.' },
                            { step_code: 'save_post', code: 'config_invalid', message: 'save_post: changes must be a list' },
                            { step_code: 'fetch', code: 'unknown_type', message: 'fetch: unknown step type' },
                            { step_code: 'odd', code: 'brand_new_code', message: 'odd: something else' },
                            { code: 'config_invalid', message: 'no step' },
                        ],
                    },
                },
            },
        }

        const mapped = mapSaveErrors(error)

        expect(mapped.list).toHaveLength(4)
        expect(mapped.byStep.save_post).toEqual([
            { code: 'taint_needs_approval', message: 'reads untrusted data from write_text; add an approval step between them.' },
            { code: 'config_invalid', message: 'changes must be a list' },
        ])
        expect(mapped.byStep.fetch).toEqual([{ code: 'unknown_type', message: 'unknown step type' }])
        // An error code this admin build does not know still marks the node.
        expect(mapped.byStep.odd[0].code).toBe('config_invalid')
        // The response body alone works too; anything else maps to nothing.
        expect(mapSaveErrors(error.response.data).byStep.fetch).toHaveLength(1)
        expect(mapSaveErrors(new Error('Network Error')).byStep).toEqual({})
    })

    it('shares recorded save errors with the node cards by step code or node id', () => {
        const { errorsFor } = useNodeIssues()
        recordSaveErrors({ response: { data: { data: { errors: [
            { step_code: 'save_post', code: 'permission_denied', message: 'save_post: not allowed' },
            { step_code: 'dndnode_4', code: 'model_not_allowed', message: 'dndnode_4: retrieve: model not allowed' },
        ] } } } })

        expect(errorsFor({ id: 'n2', data: { step_code: 'save_post' } })).toEqual([{ code: 'permission_denied', message: 'not allowed' }])
        expect(errorsFor({ id: 'dndnode_4', data: {} })).toHaveLength(1)
        expect(errorsFor({ id: 'n9', data: { step_code: 'other' } })).toEqual([])

        clearSaveErrors()
        expect(errorsFor({ id: 'n2', data: { step_code: 'save_post' } })).toEqual([])
    })

    it('loads the catalog once and marks a failed request', async () => {
        const api = await import('@/services/workflowApi')
        const { catalog, load, findNode } = useNodeCatalog()

        ;(api.fetchNodeCatalog as any).mockResolvedValueOnce({ ...catalogFixture, nodes: [catalogFixture.nodes[3]] })
        await load(true)
        expect(catalog.nodes).toHaveLength(1)
        expect(findNode('approval')?.outputs).toEqual(['approved', 'rejected', 'expired'])
        expect(findNode('ai_action')).toBeNull()

        await load()
        expect(api.fetchNodeCatalog).toHaveBeenCalledTimes(1)

        ;(api.fetchNodeCatalog as any).mockRejectedValueOnce(new Error('boom'))
        await load(true)
        expect(catalog.failed).toBe(true)
        expect(catalog.loading).toBe(false)
    })
})
