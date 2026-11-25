import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'

const edges = ref<any[]>([])
const connectionStartHandle = ref<any>(null)
const connectionEndHandle = ref<any>(null)
vi.mock('@vue-flow/core', () => ({
    Handle: defineComponent({
        name: 'HandleStub',
        props: { id: String, type: String, position: String },
        setup: (props, { slots }) => () => h('i', { 'data-handle': props.id, 'data-handle-type': props.type, 'data-position': props.position }, slots.default?.()),
    }),
    Position: { Top: 'top', Bottom: 'bottom', Left: 'left', Right: 'right' },
    useVueFlow: () => ({
        getEdges: edges,
        getNodes: ref([]),
        findNode: (id: string) => (id === 'loop1' ? { id, data: { name: 'For each church' } } : undefined),
        removeEdges: (ids: string[]) => (edges.value = edges.value.filter((edge) => !ids.includes(edge.id))),
        connectionStartHandle,
        connectionEndHandle,
    }),
    useNodeConnections: () => ref([]),
}))

const put = vi.fn()
vi.mock('@/ajax', () => ({ default: { put: (...args: unknown[]) => put(...args), get: vi.fn(), post: vi.fn(), delete: vi.fn() } }))
vi.mock('@/mixins', () => ({ iAlert: vi.fn() }))

import CustomNode from '@/components/workflows/custom-node.vue'
import WorkflowGraphWarnings from '@/components/workflow/WorkflowGraphWarnings.vue'
import NodeEditorShell from '@/components/workflow/node-editors/NodeEditorShell.vue'
import { applyNodeCatalog, clearSaveErrors, useNodeIssues } from '@/components/workflows/nodeCatalog'
import { updateWorkflow } from '@/libs/setting'
import { canvasUi, resetCanvasUi } from '@/components/workflows/canvasUi'
import { antdStubs } from './antd-stubs'
import { catalogFixture } from './node-catalog-fixture'

const refusal = {
    response: {
        status: 422,
        data: {
            status: 'error',
            message: 'save_post: reads untrusted data from write_text; add an approval step between them.',
            data: {
                errors: [
                    { step_code: 'save_post', code: 'taint_needs_approval', message: 'save_post: reads untrusted data from write_text; add an approval step between them.' },
                ],
            },
        },
    },
}

const aiNode = { id: 'n1', type: 'custom', data: { name: 'Write text', step_code: 'write_text', step_type: 'ai_action', step_config: { storeAs: 'vars.draft' } } }
const writeNode = { id: 'n2', type: 'custom', data: { name: 'Save post', step_code: 'save_post', step_type: 'change', step_config: { changes: [{ field: 'body', valueExpr: 'vars.draft' }] } } }

const mountNode = (node: any) =>
    mount(CustomNode, { props: { id: node.id, data: node.data, position: { x: 0, y: 0 } }, global: { stubs: antdStubs } })

describe('workflow node issues', () => {
    beforeEach(() => {
        applyNodeCatalog(catalogFixture)
        clearSaveErrors()
        edges.value = []
        connectionStartHandle.value = null
        connectionEndHandle.value = null
        resetCanvasUi()
        put.mockReset()
    })

    it('records the nodes a refused save names, and clears them on the next save', async () => {
        const { saveErrors } = useNodeIssues()
        put.mockRejectedValueOnce(refusal)

        await expect(updateWorkflow(7, { nodes: [], edges: [] })).rejects.toBe(refusal)
        expect(Object.keys(saveErrors.value)).toEqual(['save_post'])

        put.mockResolvedValueOnce({ data: { status: 'success' } })
        await expect(updateWorkflow(7, { nodes: [], edges: [] })).resolves.toEqual({ status: 'success' })
        expect(saveErrors.value).toEqual({})
    })

    it('highlights the node at fault with the server message and leaves the others alone', async () => {
        const faulty = mountNode(writeNode)
        const fine = mountNode(aiNode)
        expect(faulty.find('[data-testid="node-error"]').exists()).toBe(false)

        put.mockRejectedValueOnce(refusal)
        await updateWorkflow(7, {}).catch(() => {})
        await nextTick()

        expect(faulty.find('[data-testid="node-card"]').classes()).toContain('ring-red-400')
        expect(faulty.find('[data-testid="node-error"]').text()).toContain('reads untrusted data from write_text')
        expect(fine.find('[data-testid="node-card"]').classes()).not.toContain('ring-red-400')
        expect(fine.find('[data-testid="node-error"]').exists()).toBe(false)
    })

    it('lists refused steps by name and warns before save about a missing approval', async () => {
        const wrapper = mount(WorkflowGraphWarnings, {
            props: { nodes: [aiNode, writeNode], edges: [{ source: 'n1', target: 'n2' }] },
            global: { stubs: antdStubs },
        })
        const card = mountNode(writeNode)
        await nextTick()

        const warning = wrapper.find('[data-testid="graph-taint-warnings"]')
        expect(warning.text()).toContain('text.workflow.warning.title')
        expect(warning.findAll('li')).toHaveLength(1)
        expect(wrapper.find('[data-testid="graph-save-errors"]').exists()).toBe(false)
        // The node card is marked too.
        expect(card.find('[data-testid="node-warning"]').exists()).toBe(true)
        expect(card.find('[data-testid="node-card"]').classes()).toContain('ring-amber-300')

        put.mockRejectedValueOnce(refusal)
        await updateWorkflow(7, {}).catch(() => {})
        await nextTick()

        const errors = wrapper.find('[data-testid="graph-save-errors"]')
        expect(errors.text()).toContain('Save post')
        expect(errors.text()).toContain('text.workflow.issue.taint_needs_approval')

        // An approval step between them removes the warning.
        const approval = { id: 'n3', type: 'custom', data: { name: 'Check', step_code: 'check', step_type: 'approval', step_config: {} } }
        await wrapper.setProps({ nodes: [aiNode, approval, writeNode], edges: [{ source: 'n1', target: 'n3' }, { source: 'n3', target: 'n2' }] })
        expect(wrapper.find('[data-testid="graph-taint-warnings"]').exists()).toBe(false)
        expect(card.find('[data-testid="node-warning"]').exists()).toBe(false)

        wrapper.unmount()
        expect(useNodeIssues().saveErrors.value).toEqual({})
    })

    it('draws one named pill per output on the bottom edge, the error output on the right, the way in on top', () => {
        const approval = mountNode({ id: 'n3', data: { name: 'Check', step_code: 'check', step_type: 'approval', step_config: {} } })
        expect(approval.findAll('[data-handle-type="source"]').map((el) => el.attributes('data-handle'))).toEqual(['approved', 'rejected', 'expired', 'error'])
        // One pill per output, with its sign; a pack without the output's wording shows the output's own name, never a raw key.
        expect(approval.findAll('[data-handle-type="source"]').map((el) => [el.attributes('data-position'), el.text()])).toEqual([
            ['bottom', '✓ approved'],
            ['bottom', '✗ rejected'],
            ['bottom', '⏱ expired'],
            ['right', '⚡ text.workflow.canvas.error_port'],
        ])
        // Colour by meaning; an output without a link is outlined, not filled.
        const pill = (name: string) => approval.find(`[data-handle="${name}"]`).classes()
        expect(pill('approved')).toContain('!bg-green-50')
        expect(pill('rejected')).toContain('!bg-red-50')
        expect(pill('expired')).toContain('!bg-slate-50')
        // The link comes in at the top of the card.
        expect(approval.findAll('[data-handle-type="target"]').map((el) => [el.attributes('data-handle'), el.attributes('data-position')])).toEqual([['input', 'top']])
        // An approval waits for a person.
        expect(approval.find('[data-safety="waits_person"]').exists()).toBe(true)

        const plain = mountNode(aiNode)
        expect(plain.findAll('[data-handle-type="source"]').map((el) => el.attributes('data-handle'))).toEqual(['output', 'error'])
        expect(plain.find('[data-handle="output"]').text()).toBe('main')
        expect(plain.find('[data-safety="ai_spend"]').exists()).toBe(true)

        // A step the catalog does not know keeps its plain output.
        const legacy = mountNode({ id: 'n9', data: { name: 'Old', step_code: 'old', step_type: 'script', step_config: {} } })
        expect(legacy.findAll('[data-handle-type="source"]').map((el) => el.attributes('data-handle'))).toEqual(['output', 'error'])
    })

    it('fills a linked output, offers "add step" under an output that leads nowhere, and nothing of that when read-only', async () => {
        edges.value = [{ id: 'e1', source: 'n3', target: 'n2', sourceHandle: 'approved' }]
        const approval = mountNode({ id: 'n3', data: { name: 'Check', step_code: 'check', step_type: 'approval', step_config: {} } })
        expect(approval.find('[data-handle="approved"]').classes()).toContain('!bg-green-600')
        expect(approval.find('[data-output="approved"] [data-testid="node-add-step"]').exists()).toBe(false)
        expect(approval.find('[data-output="rejected"] [data-testid="node-add-step"]').text()).toBe('+ text.workflow.canvas.add_step')

        await approval.find('[data-output="rejected"] [data-testid="node-add-step"]').trigger('click')
        expect(canvasUi.picker).toMatchObject({ source: 'n3', handle: 'rejected', point: null })

        canvasUi.readOnly = true
        await nextTick()
        expect(approval.find('[data-testid="node-add-step"]').exists()).toBe(false)
    })

    it('shows the error output only while error paths are on or the step is selected; a red dot says a path exists', async () => {
        edges.value = [{ id: 'e1', source: 'n1', target: 'n2', sourceHandle: 'error' }]
        const card = mountNode(aiNode)
        expect(card.find('[data-testid="node-error-port"]').classes()).toContain('invisible')
        expect(card.find('[data-testid="node-error-dot"]').exists()).toBe(true)

        canvasUi.showErrors = true
        await nextTick()
        expect(card.find('[data-testid="node-error-port"]').classes()).not.toContain('invisible')

        canvasUi.showErrors = false
        await card.setProps({ selected: true })
        expect(card.find('[data-testid="node-error-port"]').classes()).not.toContain('invisible')
        expect(mountNode(writeNode).find('[data-testid="node-error-dot"]').exists()).toBe(false)
    })

    it('says "next item" under an output that returns to its loop, and removes that link from there', async () => {
        edges.value = [{ id: 'e1', source: 'n3', target: 'loop1', sourceHandle: 'rejected' }]
        canvasUi.loopOf = { n3: 'loop1' }
        const approval = mountNode({ id: 'n3', data: { name: 'Check', step_code: 'check', step_type: 'approval', step_config: {} } })
        const note = approval.find('[data-output="rejected"] [data-testid="node-next-item"]')
        expect(note.text()).toContain('text.workflow.canvas.next_item')
        // The hint names the loop step (the i18n stand-in of this spec returns the key).
        expect(note.attributes('title')).toBe('text.workflow.canvas.next_item_hint')

        await note.find('[data-testid="node-next-item-remove"]').trigger('click')
        expect(edges.value).toEqual([])
    })

    it('marks the card a dragged link is over as the place to drop it', async () => {
        const card = mountNode(writeNode)
        expect(card.find('[data-testid="node-drop-hint"]').exists()).toBe(false)
        connectionStartHandle.value = { nodeId: 'n1', id: 'output', type: 'source' }
        connectionEndHandle.value = { nodeId: 'n2', id: 'input', type: 'target' }
        await nextTick()
        expect(card.find('[data-testid="node-drop-hint"]').text()).toBe('text.workflow.canvas.drop_to_link')
        expect(card.find('[data-testid="node-card"]').classes()).toContain('border-teal-700')
        // The card the link leaves is never its own target.
        expect(mountNode(aiNode).find('[data-testid="node-drop-hint"]').exists()).toBe(false)
    })

    it('shows the step kind instead of internal codes or coordinates', () => {
        const named = mountNode(aiNode)
        expect(named.find('[data-testid="node-name"]').text()).toBe('Write text')
        expect(named.find('[data-testid="node-type-label"]').text()).toBe('AI action')
        expect(named.text()).not.toContain('write_text')
        expect(named.text()).not.toMatch(/x:\s*\d/)
    })

    it('draws a start step without an incoming handle and without an error output', () => {
        const start = mountNode({ id: 's1', data: { name: 'Start', step_code: 'start', step_type: 'trigger_manual', step_config: {}, is_start: true } })
        expect(start.findAll('[data-handle-type="target"]')).toHaveLength(0)
        expect(start.findAll('[data-handle-type="source"]').map((el) => el.attributes('data-handle'))).toEqual(['output'])
    })

    it('keeps a link drawn from the classic handle attached when the step now has named outputs', () => {
        edges.value = [{ source: 'n3', target: 'n2', sourceHandle: 'output' }]
        const approval = mountNode({ id: 'n3', data: { name: 'Check', step_code: 'check', step_type: 'approval', step_config: {} } })

        expect(approval.findAll('[data-handle-type="source"]').map((el) => el.attributes('data-handle'))).toEqual(['approved', 'rejected', 'expired', 'output', 'error'])
        edges.value = []
    })

    it('shows safety, the generic form and the node problems in the inspector', async () => {
        put.mockRejectedValueOnce(refusal)
        await updateWorkflow(7, {}).catch(() => {})

        const wrapper = mount(NodeEditorShell, {
            props: { selectedNode: { id: 'n5', data: { name: 'Wait', step_code: 'save_post', step_type: 'ai_action', step_config: { goal: 'Summarise' } } }, steps: [] },
            global: { stubs: antdStubs },
        })
        await nextTick()

        expect(wrapper.find('[data-testid="node-definition-summary"]').text()).toContain('Let an AI assistant do one job.')
        expect(wrapper.find('[data-testid="node-save-errors"]').text()).toContain('text.workflow.issue.taint_needs_approval')
    })
})
