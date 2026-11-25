import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const api = vi.hoisted(() => ({ fetchAutoApproval: vi.fn() }))

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params && Object.keys(params).length ? `${key} ${JSON.stringify(params)}` : key), te: () => false, locale: { value: 'vi' } }),
}))
vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => false }))
vi.mock('@/services/workflowApi', () => ({
    fetchNodeCatalog: vi.fn(),
    fetchSelectableModels: vi.fn().mockResolvedValue([]),
    searchReviewers: vi.fn().mockResolvedValue([]),
    resolveReviewers: vi.fn().mockResolvedValue([]),
    fetchLastRunContext: vi.fn().mockResolvedValue(null),
    fetchPermissionOptions: vi.fn().mockResolvedValue([]),
    fetchWorkflowPresets: vi.fn().mockResolvedValue([]),
    fetchModelList: vi.fn().mockResolvedValue([]),
    fetchModelSchema: vi.fn().mockResolvedValue(null),
    validateExpression: vi.fn().mockResolvedValue({ ok: true, errors: [] }),
    ...api,
}))
vi.mock('@/ajax', () => ({ default: { put: vi.fn(), get: vi.fn(), post: vi.fn(), delete: vi.fn() } }))
vi.mock('@/mixins', () => ({ iAlert: vi.fn() }))

import NodeEditorShell from '@/components/workflow/node-editors/NodeEditorShell.vue'
import {
    approvalHeldReason,
    autoPublishState,
    cardSubtitle,
    estimateFor,
    heldReasonLine,
    isNotReadyRefusal,
    jevChip,
    levelKey,
    levelPatch,
    levelText,
    readsVerdict,
    remainingDecisions,
    turnOffPatch,
    turnOnPatch,
    waitsForPublish,
} from '@/components/workflow/widgets/autoPublishRules.js'
import { canvasUi, resetCanvasUi } from '@/components/workflows/canvasUi.js'
import { loadAutoApproval, refreshAutoApproval } from '@/components/workflows/autoApprovalStore.js'
import { applyNodeCatalog, mapSaveErrors } from '@/components/workflows/nodeCatalog'
import { antdStubs } from './antd-stubs'
import { storySplitGraph } from './story-split-fixture'

// Shapes of GET workflows/{project}/steps/{step_code}/auto-approval (the contract of 2026-11-24).
const base = { available: true, mode: 'human', min_confidence: 0.8, source: 'sample_module.place_story', required: 50, minimum_agreement: 0.9, approved_after_edit: 2, excluded: 1, paused: false, estimate: { '0.6': 41, '0.8': 33, '0.9': 20, of: 50 }, last_30_days: { auto: 0, human: 12, withdrawn: 0 } }
const learning = { ...base, decided: 12, agreed: 11, disagreed: 1, agreement_rate: 0.92, ready: false }
const ready = { ...base, decided: 50, agreed: 47, disagreed: 3, agreement_rate: 0.94, ready: true }
const on = { ...ready, mode: 'jev', last_30_days: { auto: 41, human: 9, withdrawn: 1 } }
const paused = { ...on, paused: true, agreement_rate: 0.86 }

const jev = { verdict_from: 'vars.jev', min_confidence: 0.8, hold_if: "vars.draft.status != 'draft'" }
const human = { mode: 'human', jev }
const auto = { mode: 'jev', jev }

describe('auto-publish: which state the section shows', () => {
    it('shows nothing unless the server says the step can publish on its own', () => {
        expect(autoPublishState(null, human)).toBe('')
        expect(autoPublishState({ available: false }, human)).toBe('')
        expect(autoPublishState({ ...learning, available: undefined }, human)).toBe('')
    })

    it('follows the calibration while a person decides, and the draft once it is turned on', () => {
        expect(autoPublishState(learning, human)).toBe('learning')
        expect(autoPublishState(ready, human)).toBe('ready')
        expect(autoPublishState(on, auto)).toBe('on')
        expect(autoPublishState(paused, auto)).toBe('paused')
        // Turned on in the draft, not published yet; and turned off in the draft while it still runs.
        expect(autoPublishState(ready, auto)).toBe('on')
        expect(autoPublishState(on, human)).toBe('ready')
        // A draft without a mode follows what runs.
        expect(autoPublishState(on, { jev })).toBe('on')
    })

    it('knows a step that reads a stored verdict, and how many decisions are missing', () => {
        expect(readsVerdict(human)).toBe(true)
        expect(readsVerdict({ mode: 'jev', jev: { criteria: 'x' } })).toBe(false)
        expect(readsVerdict({})).toBe(false)
        expect(remainingDecisions(learning)).toBe(38)
        expect(remainingDecisions(ready)).toBe(0)
    })

    it('says when the draft differs from what runs', () => {
        expect(waitsForPublish(ready, human)).toBe(false)
        expect(waitsForPublish(ready, auto)).toBe(true)
        expect(waitsForPublish(on, auto)).toBe(false)
        expect(waitsForPublish(on, human)).toBe(true)
        expect(waitsForPublish(on, { mode: 'jev', jev: { ...jev, min_confidence: 0.9 } })).toBe(true)
    })
})

describe('auto-publish: levels and the estimate line', () => {
    it('writes a level the way the operator reads it', () => {
        expect(levelText(0.8, 'vi')).toBe('0,8')
        expect(levelText(0.74, 'vi')).toBe('0,74')
        expect(levelText(0.8, 'en')).toBe('0.8')
        expect(levelKey(human)).toBe('medium')
        expect(levelKey({ jev: { verdict_from: 'vars.jev' } }, { min_confidence: 0.9 })).toBe('strict')
        expect(levelKey({ jev: { min_confidence: 0.75 } })).toBe('')
    })

    it('reads how many items a level would have published out of the last reviewed ones', () => {
        expect(estimateFor(ready, 0.8)).toEqual({ auto: 33, rest: 17, of: 50 })
        expect(estimateFor(ready, 0.6)).toEqual({ auto: 41, rest: 9, of: 50 })
        expect(estimateFor(ready, 0.75)).toBeNull()
        expect(estimateFor({ ...ready, estimate: { of: 0 } }, 0.8)).toBeNull()
        expect(estimateFor({ ...ready, estimate: undefined }, 0.8)).toBeNull()
    })

    it('turns on, turns off and changes the level without touching the other settings of the verdict', () => {
        expect(turnOnPatch(human, 0.9)).toEqual({ mode: 'jev', jev: { ...jev, min_confidence: 0.9 } })
        expect(turnOnPatch(human)).toEqual({ mode: 'jev', jev })
        expect(turnOffPatch()).toEqual({ mode: 'human' })
        expect(levelPatch(auto, 0.6)).toEqual({ jev: { ...jev, min_confidence: 0.6 } })
    })
})

describe('auto-publish: second line of the step card', () => {
    it('says who decides, with the counts when the server gave them', () => {
        expect(cardSubtitle(human, learning)).toEqual({ key: 'text.workflow.auto_publish.card_learning', params: { decided: 12, required: 50 } })
        expect(cardSubtitle(auto, on)).toEqual({ key: 'text.workflow.auto_publish.card_on', params: { level: '0,8' } })
        expect(cardSubtitle(auto, on, 'en')).toEqual({ key: 'text.workflow.auto_publish.card_on', params: { level: '0.8' } })
        expect(cardSubtitle(auto, paused)).toEqual({ key: 'text.workflow.auto_publish.card_paused', params: {} })
        expect(cardSubtitle(human, null)).toEqual({ key: 'text.workflow.auto_publish.card_learning_plain', params: {} })
    })

    it('adds no line to a review step that cannot publish on its own', () => {
        expect(cardSubtitle({ mode: 'human' }, null)).toBeNull()
        expect(cardSubtitle({ mode: 'jev', jev: { criteria: 'x' } }, null)).toBeNull()
        expect(cardSubtitle(human, { available: false })).toBeNull()
    })
})

describe('auto-publish: the approvals inbox', () => {
    const row = (node: Record<string, unknown>, extra: Record<string, unknown> = {}) => ({ status: 'pending', diff_data: { workflow_node: node }, ...extra })

    it('builds the JEV chip from the row, the API field first', () => {
        expect(jevChip(row({ jev: { verdict: 'pass', probability: 0.74, min_confidence: 0.8 } }))).toEqual({ verdict: 'pass', verdictKey: 'text.agentic.workflow_node.jev_verdict_pass', level: '0,74', color: 'green' })
        expect(jevChip(row({ jev: { verdict: 'pass', probability: 0.5 } }, { jev: { verdict: 'unsure', probability: null } }))).toEqual({ verdict: 'unsure', verdictKey: 'text.agentic.workflow_node.jev_verdict_unsure', level: '', color: 'gold' })
        expect(jevChip(row({ jev: { verdict: 'unsure', probability: 0.55 } }))!.level).toBe('')
        expect(jevChip(row({ jev: { verdict: 'maybe' } }))).toBeNull()
        expect(jevChip(row({}))).toBeNull()
    })

    it('maps every reason a row waits to plain words, and says nothing for an unknown one', () => {
        const node = (held_reason: string) => row({ held_reason, jev: { verdict: 'pass', probability: 0.74, min_confidence: 0.8 } })
        expect(heldReasonLine(node('below_threshold'))).toEqual({ key: 'text.agentic.auto_publish.held.below_threshold', params: { level: '0,8' } })
        for (const reason of ['learning', 'flagged', 'jev_unsure', 'jev_missing', 'paused']) {
            expect(heldReasonLine(node(reason))).toEqual({ key: `text.agentic.auto_publish.held.${reason}`, params: {} })
        }
        expect(heldReasonLine(row({ held_reason: 'below_threshold' }))).toEqual({ key: 'text.agentic.auto_publish.held.below_threshold_plain', params: {} })
        expect(heldReasonLine(node('something_new'))).toBeNull()
        expect(heldReasonLine(row({}))).toBeNull()
        // The API field wins; a row that no longer waits says nothing.
        expect(approvalHeldReason(row({ held_reason: 'learning' }, { held_reason: 'flagged' }))).toBe('flagged')
        expect(heldReasonLine({ ...node('flagged'), status: 'approved' })).toBeNull()
    })
})

describe('auto-publish: a refused save', () => {
    it('recognises the refusal wherever the server puts its code', () => {
        expect(isNotReadyRefusal({ response: { data: { code: 'auto_approval_not_ready' } } })).toBe(true)
        expect(isNotReadyRefusal({ response: { data: { message: 'auto_approval_not_ready' } } })).toBe(true)
        expect(isNotReadyRefusal({ response: { data: { data: { errors: [{ step_code: 'review', code: 'auto_approval_not_ready' }] } } } })).toBe(true)
        expect(isNotReadyRefusal({ response: { data: { data: { problems: [{ code: 'auto_approval_not_ready' }] } } } })).toBe(true)
        expect(isNotReadyRefusal({ response: { data: { data: { errors: [{ step_code: 'review', code: 'config_invalid' }] } } } })).toBe(false)
        expect(isNotReadyRefusal(new Error('network'))).toBe(false)
    })

    it('keeps the code on the step and drops the server sentence, so the card uses the authored wording', () => {
        const mapped = mapSaveErrors({ response: { data: { data: { errors: [{ step_code: 'review', code: 'auto_approval_not_ready', message: 'review: calibration is not ready' }] } } } })
        expect(mapped.byStep.review).toEqual([{ code: 'auto_approval_not_ready', message: '' }])
    })
})

describe('auto-publish: answers are asked once per step and shared', () => {
    beforeEach(() => {
        resetCanvasUi()
        api.fetchAutoApproval.mockReset()
    })

    it('asks once, keeps the answer for the card, and asks again after a save', async () => {
        api.fetchAutoApproval.mockResolvedValue(learning)
        canvasUi.projectId = 7
        await loadAutoApproval(7, 'review')
        await loadAutoApproval(7, 'review')
        expect(api.fetchAutoApproval).toHaveBeenCalledTimes(1)
        expect(api.fetchAutoApproval).toHaveBeenCalledWith(7, 'review')
        expect(canvasUi.autoApproval.review).toEqual(learning)

        api.fetchAutoApproval.mockResolvedValue(ready)
        refreshAutoApproval()
        await flushPromises()
        expect(canvasUi.autoApproval.review).toEqual(ready)
    })

    it('treats a failed request as "not available" and asks nothing without a workflow or a step', async () => {
        api.fetchAutoApproval.mockRejectedValue(new Error('404'))
        await loadAutoApproval(7, 'review')
        expect(canvasUi.autoApproval.review).toEqual({ available: false, failed: true })
        expect(await loadAutoApproval(null, 'review')).toBeNull()
        expect(await loadAutoApproval(7, '')).toBeNull()
        expect(api.fetchAutoApproval).toHaveBeenCalledTimes(1)
    })
})

// ---------------------------------------------------------------------------------------------------------------------
// The section in the step form
// ---------------------------------------------------------------------------------------------------------------------

const str = { type: ['string', 'null'] }
const catalog = {
    groups: [],
    nodes: [
        {
            type: 'approval',
            group: 'human',
            config_schema: {
                type: 'object',
                properties: {
                    mode: { type: ['string', 'null'], enum: ['human', 'auto', 'jev', null] },
                    title: str,
                    jev: { type: ['object', 'null'], properties: { criteria: str, min_confidence: { type: ['number', 'null'] }, verdict_from: str, hold_if: str } },
                },
            },
            default_config: {},
            outputs: ['approved', 'rejected', 'expired', 'changes_requested'],
        },
    ],
    approval_types: ['approval'],
}

describe('auto-publish: the section of the review step form', () => {
    const graph = storySplitGraph()
    const review = graph.nodes.find((node: any) => node.id === 'review') as any
    const shell = (config: Record<string, unknown>, extra: Record<string, unknown> = {}) =>
        mount(NodeEditorShell, {
            props: { selectedNode: { ...review, data: { ...review.data, step_config: { ...review.data.step_config, ...config } } }, steps: graph.nodes, edges: graph.edges, workflowId: 7, ...extra },
            global: { stubs: antdStubs },
            attachTo: document.body,
        })
    const saved = (wrapper: ReturnType<typeof shell>) => (wrapper.emitted('updateNodeConfig') || []).at(-1)?.[1] as Record<string, any> | undefined
    const section = (wrapper: ReturnType<typeof shell>) => wrapper.find('[data-testid="auto-publish-section"]')

    beforeEach(() => {
        resetCanvasUi()
        api.fetchAutoApproval.mockReset()
        applyNodeCatalog(catalog)
    })

    it('is not shown, and nothing is asked, for a review step that reads no stored verdict', async () => {
        const wrapper = shell({ mode: 'human' })
        await flushPromises()
        expect(api.fetchAutoApproval).not.toHaveBeenCalled()
        expect(section(wrapper).exists()).toBe(false)
        expect(wrapper.find('[data-field="mode"]').exists()).toBe(true)
        wrapper.unmount()
    })

    it('is not shown when the server says the step cannot publish on its own', async () => {
        api.fetchAutoApproval.mockResolvedValue({ available: false })
        const wrapper = shell(human)
        await flushPromises()
        expect(api.fetchAutoApproval).toHaveBeenCalledWith(7, 'review')
        expect(section(wrapper).exists()).toBe(false)
        expect(wrapper.find('[data-field="mode"]').exists()).toBe(true)
        wrapper.unmount()
    })

    it('learning: shows the progress and keeps the button off, saying how many decisions are missing', async () => {
        api.fetchAutoApproval.mockResolvedValue(learning)
        const wrapper = shell(human)
        await flushPromises()
        expect(section(wrapper).attributes('data-state')).toBe('learning')
        expect(wrapper.get('[data-testid="auto-publish-decided"]').text()).toBe('12 / 50')
        expect(wrapper.get('[data-testid="auto-publish-agreement"]').text()).toContain('92%')
        expect(wrapper.get('[data-testid="auto-publish-agreement"]').text()).toContain('{"percent":90}')
        expect(wrapper.get('[data-testid="auto-publish-notes"]').text()).toContain('text.workflow.auto_publish.edited {"count":2}')
        expect(wrapper.get('[data-testid="auto-publish-notes"]').text()).toContain('text.workflow.auto_publish.excluded {"count":1}')
        expect(wrapper.get('[data-testid="auto-publish-turn-on"]').attributes('disabled')).toBeDefined()
        expect(wrapper.get('[data-testid="auto-publish-why-not"]').text()).toBe('text.workflow.auto_publish.need_more {"count":38}')
        // The plain "who decides" setting gives way to the section.
        expect(wrapper.find('[data-field="mode"]').exists()).toBe(false)
        wrapper.unmount()
    })

    it('learning with enough decisions but too little agreement says so', async () => {
        api.fetchAutoApproval.mockResolvedValue({ ...learning, decided: 60, agreement_rate: 0.8 })
        const wrapper = shell(human)
        await flushPromises()
        expect(wrapper.get('[data-testid="auto-publish-why-not"]').text()).toBe('text.workflow.auto_publish.need_agreement {"percent":90}')
        wrapper.unmount()
    })

    it('ready: shows the estimate of the chosen level and writes the draft when turned on', async () => {
        api.fetchAutoApproval.mockResolvedValue(ready)
        const wrapper = shell(human)
        await flushPromises()
        expect(section(wrapper).attributes('data-state')).toBe('ready')
        expect(wrapper.get('[data-level="medium"]').attributes('aria-pressed')).toBe('true')
        expect(wrapper.get('[data-level="medium"]').text()).toContain('0,8')
        expect(wrapper.get('[data-testid="auto-publish-estimate"]').text()).toContain('"auto":33,"rest":17,"of":50')

        await wrapper.get('[data-level="strict"]').trigger('click')
        await flushPromises()
        expect(saved(wrapper)!.mode).toBe('human')
        expect(saved(wrapper)!.jev).toEqual({ ...jev, min_confidence: 0.9 })
        expect(wrapper.get('[data-testid="auto-publish-estimate"]').text()).toContain('"auto":20,"rest":30,"of":50')

        await wrapper.get('[data-testid="auto-publish-turn-on"]').trigger('click')
        await flushPromises()
        expect(saved(wrapper)!.mode).toBe('jev')
        expect(saved(wrapper)!.jev).toEqual({ ...jev, min_confidence: 0.9 })
        // Every other setting of the step is left as it was.
        expect(saved(wrapper)!.reviewer_permission).toBe(review.data.step_config.reviewer_permission)
        expect(section(wrapper).attributes('data-state')).toBe('on')
        expect(wrapper.find('[data-testid="auto-publish-pending"]').exists()).toBe(true)
        wrapper.unmount()
    })

    it('on: shows the last 30 days and writes "a person decides" when turned off', async () => {
        api.fetchAutoApproval.mockResolvedValue(on)
        const wrapper = shell(auto)
        await flushPromises()
        expect(section(wrapper).attributes('data-state')).toBe('on')
        expect(wrapper.get('[data-tile="auto"]').text()).toContain('41')
        expect(wrapper.get('[data-tile="human"]').text()).toContain('9')
        expect(wrapper.get('[data-tile="withdrawn"]').text()).toContain('1')
        expect(wrapper.find('[data-testid="auto-publish-pause-rule"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="auto-publish-pending"]').exists()).toBe(false)

        await wrapper.get('[data-testid="auto-publish-turn-off"]').trigger('click')
        await flushPromises()
        expect(saved(wrapper)!.mode).toBe('human')
        expect(saved(wrapper)!.jev).toEqual(jev)
        wrapper.unmount()
    })

    it('paused: says items go to a person, and can still be turned off', async () => {
        api.fetchAutoApproval.mockResolvedValue(paused)
        const wrapper = shell(auto)
        await flushPromises()
        expect(section(wrapper).attributes('data-state')).toBe('paused')
        expect(wrapper.get('[data-testid="auto-publish-paused"]').text()).toContain('text.workflow.auto_publish.paused_body')
        expect(wrapper.find('[data-testid="auto-publish-pause-rule"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="auto-publish-turn-off"]').exists()).toBe(true)
        wrapper.unmount()
    })

    it('writes nothing on a workflow that cannot be edited', async () => {
        api.fetchAutoApproval.mockResolvedValue(ready)
        const wrapper = shell(human, { readOnly: true })
        await flushPromises()
        await wrapper.get('[data-testid="auto-publish-turn-on"]').trigger('click')
        await wrapper.get('[data-level="easy"]').trigger('click')
        await flushPromises()
        expect(saved(wrapper)).toBeUndefined()
        wrapper.unmount()
    })
})
