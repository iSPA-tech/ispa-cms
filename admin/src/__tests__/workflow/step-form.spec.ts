import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const account = vi.hoisted(() => ({ webmaster: false }))
const api = vi.hoisted(() => ({
    fetchLastRunContext: vi.fn(),
    fetchModelSchema: vi.fn(),
    validateExpression: vi.fn(),
}))

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key} ${JSON.stringify(params)}` : key), te: () => false }),
}))
vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => account.webmaster }))
vi.mock('@/services/workflowApi', () => ({
    fetchNodeCatalog: vi.fn(),
    fetchSelectableModels: vi.fn().mockResolvedValue([]),
    searchReviewers: vi.fn().mockResolvedValue([]),
    resolveReviewers: vi.fn().mockResolvedValue([]),
    fetchLastRunContext: api.fetchLastRunContext,
    fetchModelSchema: api.fetchModelSchema,
    validateExpression: api.validateExpression,
}))
vi.mock('@/ajax', () => ({ default: { put: vi.fn(), get: vi.fn(), post: vi.fn(), delete: vi.fn() } }))
vi.mock('@/mixins', () => ({ iAlert: vi.fn() }))

import DataPicker from '@/components/workflow/inputs/DataPicker.vue'
import ExpressionEditor from '@/components/workflow/inputs/ExpressionEditor.vue'
import {
    buildDataGroups,
    fieldSyntax,
    filterGroups,
    isIdField,
    isPlaced,
    parseTemplate,
    pathLabel,
    readPath,
    serializeTemplate,
    stepsBefore,
    tokenFor,
} from '@/components/workflow/inputs/dataTokens.js'
import NodeEditorShell from '@/components/workflow/node-editors/NodeEditorShell.vue'
import OnErrorSetting from '@/components/workflow/node-editors/OnErrorSetting.vue'
import SchemaFormEditor from '@/components/workflow/node-editors/SchemaFormEditor.vue'
import { errorModeOf, offersOnError, planErrorMode } from '@/components/workflow/node-editors/onErrorRule.js'
import { applyNodeCatalog } from '@/components/workflows/nodeCatalog'
import { antdStubs } from './antd-stubs'
import { coreDefaultConfigs, storySplitGraph, storySplitSteps } from './story-split-fixture'

const expr = { type: ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'], 'x-expr': true }
const str = { type: ['string', 'null'] }
const approvalSchema = {
    type: 'object',
    properties: {
        mode: { type: ['string', 'null'], enum: ['human', 'auto', 'jev', null] },
        title: str,
        review: expr,
        payload: expr,
        subject_model: str,
        subject_id: expr,
        changes: expr,
        editable_fields: { type: ['array', 'null'], items: { type: 'string' } },
        reviewer_permission: str,
        notify: { type: ['boolean', 'string', 'integer', 'null'] },
        expires_after: { type: ['string', 'integer', 'null'] },
        assignees: { type: ['array', 'null'], items: { type: 'string' }, maxItems: 10 },
        sla: { type: ['string', 'integer', 'null'] },
        max_rounds: { type: ['integer', 'string', 'null'] },
        revision_options: { type: ['array', 'null'], items: { type: 'object' } },
        reject_reasons: { type: ['array', 'null'], items: { type: 'object' } },
        storeAs: { type: ['string', 'null'], 'x-store': true },
    },
}
const catalog = {
    groups: [{ key: 'human', label_key: 'text.workflow.group.human', order: 60, label: 'People' }],
    nodes: [
        { type: 'approval', version: 1, group: 'human', label: 'Approval', config_schema: approvalSchema, default_config: {}, outputs: ['approved', 'rejected', 'expired', 'changes_requested'], safety: ['read'] },
        { type: 'notify', version: 1, group: 'human', label: 'Notify', config_schema: { type: 'object', properties: { permission: str, message: str, storeAs: { type: ['string', 'null'], 'x-store': true } } }, default_config: {}, safety: ['internal_write'] },
        { type: 'trigger_manual', version: 1, group: 'trigger', label: 'Start', config_schema: { type: 'object', properties: {} }, default_config: {}, safety: ['read'] },
        { type: 'mystery', version: 1, group: 'human', label: 'Mystery', config_schema: { type: 'object', properties: {} }, default_config: {}, safety: ['read'] },
    ],
}

const radioStubs = {
    ...antdStubs,
    'a-radio-group': {
        props: ['value', 'disabled'],
        emits: ['update:value'],
        provide() {
            return { pick: (value: string) => this.$emit('update:value', value) }
        },
        template: '<div role="radiogroup" :data-value="value"><slot /></div>',
    },
    'a-radio': {
        props: ['value', 'disabled'],
        inject: ['pick'],
        template: '<button type="button" :data-radio="value" :disabled="disabled" @click="pick(value)"><slot /></button>',
    },
}

/** Every text setting of a config, at any depth, with the name of the top-level setting it belongs to. */
const textsOf = (config: Record<string, unknown>) => {
    const found: Array<{ name: string; value: string }> = []
    const walk = (name: string, value: unknown) => {
        if (typeof value === 'string') found.push({ name, value })
        else if (value && typeof value === 'object') Object.values(value).forEach((child) => walk(name, child))
    }
    Object.entries(config).forEach(([name, value]) => walk(name, value))
    return found
}

beforeEach(() => {
    account.webmaster = false
    api.fetchLastRunContext.mockReset().mockResolvedValue(null)
    api.fetchModelSchema.mockReset().mockResolvedValue(null)
    api.validateExpression.mockReset().mockResolvedValue({ ok: true, errors: [] })
    applyNodeCatalog(catalog)
})

describe('data tokens: what is stored never changes by being shown', () => {
    const realConfigs: Array<[string, Record<string, unknown>]> = [
        ...Object.values(storySplitSteps).map(([type, , config]) => [type, config] as [string, Record<string, unknown>]),
        ...Object.entries(coreDefaultConfigs),
    ]

    it('reads every text of the real configs into segments and writes back the same bytes', () => {
        let texts = 0
        realConfigs.forEach(([, config]) =>
            textsOf(config).forEach(({ value }) => {
                texts++
                expect(serializeTemplate(parseTemplate(value))).toBe(value)
                expect(readPath(value).raw).toBe(value)
            })
        )
        expect(texts).toBeGreaterThan(60)
    })

    it('keeps the exact placeholder text of a chip, whatever its spacing or content', () => {
        const value = 'A {{vars.a}} B {{  vars.b.c  }} C {{ vars.items | length }}\n{{ vars.d }}'
        const segments = parseTemplate(value)

        expect(segments.filter((segment) => segment.type === 'token').map((segment) => [segment.raw, segment.path])).toEqual([
            ['{{vars.a}}', 'vars.a'],
            ['{{  vars.b.c  }}', 'vars.b.c'],
            ['{{ vars.items | length }}', null],
            ['{{ vars.d }}', 'vars.d'],
        ])
        expect(serializeTemplate(segments)).toBe(value)
        expect(tokenFor('vars.draft.name').raw).toBe('{{ vars.draft.name }}')
        expect(parseTemplate('')).toEqual([])
        expect(parseTemplate(null)).toEqual([])
    })

    it('shows a path setting as a chip, a wrapped path as a chip, anything else as a formula', () => {
        expect(readPath('vars.draft.review')).toEqual({ kind: 'chip', path: 'vars.draft.review', raw: 'vars.draft.review' })
        expect(readPath('{{ vars.approval }}')).toEqual({ kind: 'chip', path: 'vars.approval', raw: '{{ vars.approval }}' })
        expect(readPath("'church-story:' ~ vars.place_uuid").kind).toBe('formula')
        expect(readPath('vars.draft.pending == true').kind).toBe('formula')
        expect(readPath('').kind).toBe('empty')
        expect(readPath(null).kind).toBe('empty')
    })

    it('knows which syntax each setting is stored in', () => {
        // Core nodes: evalField settings are one expression, the other data settings are templates.
        expect(fieldSyntax('approval', 'review', expr)).toBe('path')
        expect(fieldSyntax('approval', 'subject_id', expr)).toBe('path')
        expect(fieldSyntax('approval', 'title', str)).toBe('template')
        expect(fieldSyntax('notify', 'message', str)).toBe('template')
        expect(fieldSyntax('ai_prompt', 'instructions', expr)).toBe('template')
        expect(fieldSyntax('ai_rewrite', 'revision', expr)).toBe('path')
        expect(fieldSyntax('http_request', 'url', expr)).toBe('template')
        expect(fieldSyntax('lock', 'keyExpr', expr)).toBe('path')
        // A module node evaluates every x-expr setting as an expression.
        expect(fieldSyntax('sample_module.research_place', 'place_uuid', expr)).toBe('path')
        expect(fieldSyntax('content.create_post', 'title', expr)).toBe('path')
        // Not a data setting.
        expect(fieldSyntax('approval', 'reviewer_permission', str)).toBe(null)
        expect(fieldSyntax('http_request', 'content_type', str)).toBe(null)

        expect(isIdField('subject_id', 'path')).toBe(true)
        expect(isIdField('place_uuid', 'path')).toBe(true)
        expect(isIdField('subject_model', null)).toBe(true)
        expect(isIdField('model', null)).toBe(false)
        expect(isIdField('review', 'path')).toBe(false)
    })
})

describe('the step form keeps a saved config unchanged', () => {
    const [, , reviewConfig] = storySplitSteps.review
    const mountForm = (modelValue: Record<string, unknown>, stepType = 'approval') =>
        mount(SchemaFormEditor, { props: { stepType, modelValue: JSON.parse(JSON.stringify(modelValue)) }, global: { stubs: antdStubs }, attachTo: document.body })

    it('loads the approval step of the story sample as chips and emits nothing', async () => {
        const wrapper = mountForm(reviewConfig)
        await flushPromises()

        expect(wrapper.emitted('update:modelValue')).toBeUndefined()

        const title = wrapper.get('[data-field="title"]')
        expect(title.attributes('data-kind')).toBe('template')
        const chip = title.get('[data-testid="data-token-editor"] [data-raw]')
        expect(chip.attributes('data-raw')).toBe('{{ vars.draft.name }}')
        expect(chip.attributes('contenteditable')).toBe('false')
        expect(title.get('[data-testid="data-token-editor"]').element.textContent).toContain('Church story: ')
        // The placeholder syntax is never shown as text.
        expect(title.get('[data-testid="data-token-editor"]').element.textContent).not.toContain('{{')

        const review = wrapper.get('[data-field="review"]')
        expect(review.attributes('data-kind')).toBe('path')
        expect(review.get('[data-testid="expression-chip"]').text()).not.toContain('vars.')
        expect(review.text()).not.toContain('text.workflow.editor.validate')
        wrapper.unmount()
    })

    it('changes only the edited setting; every other value keeps its bytes', async () => {
        const wrapper = mountForm(reviewConfig)
        await flushPromises()

        const editor = wrapper.get('[data-field="title"] [data-testid="data-token-editor"]')
        editor.element.insertBefore(document.createTextNode('New '), editor.element.firstChild)
        await editor.trigger('input')

        const events = wrapper.emitted('update:modelValue') as Array<[Record<string, unknown>]>
        const saved = events[events.length - 1][0]
        expect(saved.title).toBe('New Church story: {{ vars.draft.name }}')
        expect(JSON.stringify({ ...saved, title: reviewConfig.title })).toBe(JSON.stringify(reviewConfig))
        wrapper.unmount()
    })

    it('shows a record setting read-only, leaves an empty one out, and never asks for an id', async () => {
        const wrapper = mountForm({ ...reviewConfig, subject_model: 'Cms\\SampleModule\\Models\\Place', subject_id: 'vars.place_uuid' })
        await flushPromises()

        const id = wrapper.get('[data-field="subject_id"]')
        expect(id.attributes('data-kind')).toBe('derived')
        expect(id.find('input, textarea, [contenteditable="true"]').exists()).toBe(false)
        expect(id.text()).toContain('text.workflow.data.derived_note')
        const model = wrapper.get('[data-field="subject_model"]')
        expect(model.attributes('data-kind')).toBe('derived')
        expect(model.text()).toContain('Place')
        expect(model.text()).not.toContain('Cms\\')
        wrapper.unmount()

        const empty = mountForm(reviewConfig)
        await flushPromises()
        expect(empty.find('[data-field="subject_id"]').exists()).toBe(false)
        expect(empty.find('[data-field="subject_model"]').exists()).toBe(false)
        empty.unmount()
    })

    it('shows the name of a result without its prefix and stores it with the prefix', async () => {
        const wrapper = mountForm({ permission: 'x', message: 'Hi', storeAs: 'vars.notified' }, 'notify')
        await flushPromises()

        const store = wrapper.get('[data-field="storeAs"]')
        expect(store.attributes('data-kind')).toBe('store')
        expect((store.get('input').element as HTMLInputElement).value).toBe('notified')

        await store.get('input').setValue('editors told')
        const events = wrapper.emitted('update:modelValue') as Array<[Record<string, unknown>]>
        expect(events[events.length - 1][0].storeAs).toBe('vars.editors_told')
        wrapper.unmount()
    })

    it('keeps the less used settings of an approval behind "more options"', async () => {
        const wrapper = mountForm(reviewConfig)
        await flushPromises()

        const main = wrapper.findAll('[data-testid="schema-form-editor"] > [data-field]').map((field) => field.attributes('data-field'))
        // Who reviews and what the reviewer may edit are picked from lists, so they sit with the main settings.
        expect(main).toEqual(['mode', 'reviewer_permission', 'assignees', 'expires_after', 'sla', 'title', 'review', 'editable_fields'])
        expect(wrapper.get('[data-testid="schema-form-more-fields"]').attributes('style')).toContain('display: none')

        await wrapper.get('[data-testid="schema-form-more"]').trigger('click')
        expect(wrapper.get('[data-testid="schema-form-more-fields"]').attributes('style') || '').not.toContain('display: none')
        expect(wrapper.find('[data-testid="schema-form-more-fields"] [data-field="reject_reasons"]').exists()).toBe(true)
        wrapper.unmount()
    })
})

describe('insert data: grouped by the step that produced it', () => {
    const { nodes, edges } = storySplitGraph()
    const sample = {
        vars: {
            selection: { place_uuids: ['a', 'b'], count: 2 },
            place_uuid: 'a',
            place_index: 0,
            draft: { name: 'Nhà thờ Đức Bà', pending: true, review: { artifact_type: 'x', payload: {} } },
            approval: { decision: 'approved' },
        },
    }

    it('orders the steps of the story sample: a loop or a rewrite link does not make a later step an earlier one', () => {
        expect([...stepsBefore(nodes, edges, 'review')].sort()).toEqual(['each', 'gate', 'jev', 'lock', 'research', 'route', 'select', 'trigger'])
        expect([...stepsBefore(nodes, edges, 'research')].sort()).toEqual(['each', 'lock', 'select', 'trigger'])
        expect([...stepsBefore(nodes, edges, 'trigger')]).toEqual([])
    })

    it('lists earlier data with samples, and greys the data of later steps', () => {
        const groups = buildDataGroups({ nodes, edges, currentId: 'review', sample })
        const byKey = Object.fromEntries(groups.map((group) => [group.key, group]))

        expect(byKey.research.title).toBe('Research the story')
        expect(byKey.research.later).toBe(false)
        expect(byKey.research.items.map((item: any) => [item.path, item.sample, item.type])).toEqual([
            ['vars.draft', '', 'group'],
            ['vars.draft.name', 'Nhà thờ Đức Bà', 'text'],
            ['vars.draft.pending', 'true', 'boolean'],
            ['vars.draft.review', '', 'group'],
        ])
        expect(byKey.each.items.map((item: any) => [item.path, item.role, item.sample])).toEqual([
            ['vars.place_uuid', 'item', 'a'],
            ['vars.place_index', 'index', '0'],
        ])
        // Produced by this step, or after it: listed, but not usable here.
        expect(byKey.review.later).toBe(true)
        expect(byKey.publish.later).toBe(true)
        expect(byKey.notify.later).toBe(true)
        expect(byKey.publish.items.every((item: any) => item.later)).toBe(true)
    })

    it('offers the data of every step to a step that is not linked into the diagram yet', () => {
        const loose = { id: 'new', type: 'step', data: { name: 'New', step_code: 'new', step_type: 'ai_prompt', step_config: { storeAs: 'vars.prompt' } } }
        const groups = buildDataGroups({ nodes: [...nodes, loose], edges, currentId: 'new', sample })
        const later = groups.filter((group: any) => group.later).map((group: any) => group.key)

        // Only its own result is "not yet": nothing says which steps run before it.
        expect(later).toEqual(['new'])
        expect(isPlaced([...nodes, loose], edges, 'new')).toBe(false)
        expect(isPlaced(nodes, edges, 'review')).toBe(true)
        expect(isPlaced(nodes, edges, 'trigger')).toBe(true)
    })

    it('shows the kind of data instead of a sample when the workflow never ran', () => {
        const groups = buildDataGroups({ nodes, edges, currentId: 'review', sample: null })
        const research = groups.find((group: any) => group.key === 'research')

        expect(research.items).toEqual([{ path: 'vars.draft', role: 'result', label: '', sample: '', type: 'data', later: false }])
    })

    it('names a chip by its step and part, never by its path', () => {
        const groups = buildDataGroups({ nodes, edges, currentId: 'review', sample })
        const roleLabel = (role: string) => (role === 'item' ? 'Current item' : 'Number')

        expect(pathLabel('vars.draft.name', groups, { roleLabel })).toBe('Research the story › Name')
        expect(pathLabel('vars.draft', groups, { roleLabel })).toBe('Research the story')
        expect(pathLabel('vars.place_uuid', groups, { roleLabel })).toBe('For each church › Current item')
        expect(pathLabel('vars.unknown.some_key', groups, { roleLabel })).toBe('Unknown › Some key')
    })

    it('searches by step name and by data name', () => {
        const groups = buildDataGroups({ nodes, edges, currentId: 'review', sample }).map((group: any) => ({
            ...group,
            items: group.items.map((item: any) => ({ ...item, label: item.label || 'Whole result' })),
        }))

        expect(filterGroups(groups, 'research').map((group: any) => group.key)).toEqual(['research'])
        expect(filterGroups(groups, 'pending').flatMap((group: any) => group.items.map((item: any) => item.path))).toEqual(['vars.draft.pending'])
        expect(filterGroups(groups, 'nothing-like-this')).toEqual([])
    })

    it('picker: an earlier item is a button, a later one is not, and the note says where samples come from', async () => {
        const groups = buildDataGroups({ nodes, edges, currentId: 'review', sample }).map((group: any) => ({
            ...group,
            items: group.items.map((item: any) => ({ ...item, label: item.label || 'Whole result', typeLabel: `type:${item.type}` })),
        }))
        const wrapper = mount(DataPicker, { props: { groups, hasSample: true }, global: { stubs: antdStubs } })

        const usable = wrapper.findAll('[data-testid="data-picker-item"]')
        expect(usable.every((item) => item.element.tagName === 'BUTTON')).toBe(true)
        expect(usable.map((item) => item.attributes('data-path'))).toContain('vars.draft.name')
        expect(usable.map((item) => item.attributes('data-path'))).not.toContain('vars.published')
        expect(wrapper.get('[data-path="vars.draft.name"]').text()).toContain('Nhà thờ Đức Bà')
        expect(wrapper.get('[data-path="vars.draft"]').text()).toContain('type:group')

        const later = wrapper.get('[data-testid="data-picker-later"]')
        expect(later.text()).toContain('text.workflow.data.later_title')
        expect(later.text()).toContain('Publish the approved story')
        expect(later.findAll('button')).toHaveLength(0)
        expect(later.findAll('[aria-disabled="true"]').length).toBeGreaterThan(0)
        expect(wrapper.get('[data-testid="data-picker-note"]').text()).toBe('text.workflow.data.sample_note')

        await wrapper.get('[data-path="vars.draft.name"]').trigger('click')
        expect(wrapper.emitted('pick')).toEqual([['vars.draft.name']])

        await wrapper.setProps({ groups: [], hasSample: false })
        expect(wrapper.get('[data-testid="data-picker-empty"]').text()).toBe('text.workflow.data.none')
        expect(wrapper.get('[data-testid="data-picker-note"]').text()).toBe('text.workflow.data.no_run_note')
        await wrapper.setProps({ loading: true })
        expect(wrapper.find('[data-testid="data-picker-loading"]').exists()).toBe(true)
    })

    it('a picked data setting stores the bare path; a formula is kept and only a Webmaster may rewrite it', async () => {
        const pick = mount(ExpressionEditor, { props: { modelValue: '', variant: 'pick' }, global: { stubs: antdStubs } })
        expect(pick.text()).toContain('text.workflow.data.none_picked')
        expect(pick.find('textarea').exists()).toBe(false)

        const formula = mount(ExpressionEditor, { props: { modelValue: "'church-story:' ~ vars.place_uuid", variant: 'pick' }, global: { stubs: antdStubs } })
        expect(formula.get('[data-testid="expression-formula"]').text()).toBe("'church-story:' ~ vars.place_uuid")
        expect(formula.find('[data-testid="expression-edit-formula"]').exists()).toBe(false)
        expect(formula.emitted('update:modelValue')).toBeUndefined()

        account.webmaster = true
        const asWebmaster = mount(ExpressionEditor, { props: { modelValue: "'church-story:' ~ vars.place_uuid", variant: 'pick' }, global: { stubs: antdStubs } })
        expect(asWebmaster.find('[data-testid="expression-edit-formula"]').exists()).toBe(true)
    })

    it('a condition is checked while typing: there is no "check" button', async () => {
        vi.useFakeTimers()
        api.validateExpression.mockResolvedValue({ ok: false, errors: [{ message: 'Unknown name' }] })
        const wrapper = mount(ExpressionEditor, { props: { modelValue: 'vars.a > 1' }, global: { stubs: antdStubs } })
        expect(wrapper.text()).not.toContain('text.workflow.editor.validate')

        await wrapper.get('textarea').setValue('vars.a >')
        expect(wrapper.emitted('update:modelValue')).toEqual([['vars.a >']])
        await vi.advanceTimersByTimeAsync(700)
        await flushPromises()
        expect(api.validateExpression).toHaveBeenCalledWith('vars.a >', { modelClass: '' })
        expect(wrapper.get('[data-testid="expression-errors"]').text()).toContain('Unknown name')
        vi.useRealTimers()
    })
})

describe('when this step fails', () => {
    const edge = (id: string, source: string, target: string, sourceHandle = 'output') => ({ id, source, target, sourceHandle, targetHandle: 'input' })

    it('maps the three choices to links of the diagram', () => {
        const main = edge('m', 'a', 'b')

        // Default: no error link.
        expect(errorModeOf('a', [main])).toBe('stop')
        // "Skip and carry on": an error link to the same step as the main output.
        const skip = planErrorMode('a', 'continue', [main])
        expect(skip.removedIds).toEqual([])
        expect(skip.add).toHaveLength(1)
        expect(skip.add[0]).toMatchObject({ source: 'a', target: 'b', sourceHandle: 'error', targetHandle: 'input' })
        expect(errorModeOf('a', [main, skip.add[0]])).toBe('continue')
        // "Own error path": the operator's link is kept.
        const own = edge('own', 'a', 'c', 'error')
        expect(errorModeOf('a', [main, own])).toBe('route')
        expect(planErrorMode('a', 'route', [main, own])).toEqual({ add: [], removedIds: [] })
        // Choosing "own path" on a step that skips frees the error port; choosing "stop" removes any error link.
        expect(planErrorMode('a', 'route', [main, skip.add[0]]).removedIds).toEqual([skip.add[0].id])
        expect(planErrorMode('a', 'stop', [main, own])).toEqual({ add: [], removedIds: ['own'] })
        // Skipping replaces an own path, and needs a next step.
        const replace = planErrorMode('a', 'continue', [main, own])
        expect(replace.removedIds).toEqual(['own'])
        expect(replace.add[0]).toMatchObject({ target: 'b', sourceHandle: 'error' })
        expect(planErrorMode('a', 'continue', [])).toBe(null)
    })

    it('reads the error links of the story sample', () => {
        const { edges } = storySplitGraph()
        expect(errorModeOf('research', edges)).toBe('route')
        expect(errorModeOf('review', edges)).toBe('stop')
        expect(errorModeOf('notify', edges)).toBe('stop')
    })

    it('is not offered on a trigger', () => {
        const byType = Object.fromEntries(catalog.nodes.map((node) => [node.type, node]))
        const { nodes } = storySplitGraph()
        expect(offersOnError(nodes.find((node) => node.id === 'trigger'), byType.trigger_manual, byType)).toBe(false)
        expect(offersOnError(nodes.find((node) => node.id === 'review'), byType.approval, byType)).toBe(true)
    })

    it('shows the choice, disables "skip" without a next step and remembers a pending own path', async () => {
        const wrapper = mount(OnErrorSetting, { props: { mode: 'stop', canSkip: false, stepKey: 'a' }, global: { stubs: radioStubs } })
        expect(wrapper.get('[role="radiogroup"]').attributes('data-value')).toBe('stop')
        expect(wrapper.get('[data-radio="continue"]').attributes('disabled')).toBeDefined()
        expect(wrapper.get('[data-radio="continue"]').text()).toContain('text.workflow.on_error.skip_unavailable')

        await wrapper.get('[data-radio="route"]').trigger('click')
        expect(wrapper.emitted('change')).toEqual([['route']])
        // Nothing is linked yet, so the diagram still says "stop": the form keeps showing the choice just made.
        expect(wrapper.get('[role="radiogroup"]').attributes('data-value')).toBe('route')
        expect(wrapper.get('[data-radio="route"]').text()).toContain('text.workflow.on_error.own_hint')

        await wrapper.setProps({ mode: 'route', ownTarget: 'Tell the editors' })
        expect(wrapper.get('[data-radio="route"]').text()).toContain('Tell the editors')

        // Another step starts from what its own links say.
        await wrapper.setProps({ mode: 'stop', ownTarget: '', stepKey: 'b' })
        expect(wrapper.get('[role="radiogroup"]').attributes('data-value')).toBe('stop')
    })

    it('the step settings hand the choice to the designer, with the step it is for', async () => {
        const { nodes, edges } = storySplitGraph()
        const notify = nodes.find((node) => node.id === 'notify')
        const wrapper = mount(NodeEditorShell, { props: { selectedNode: notify, steps: nodes, edges, workflowId: 7 }, global: { stubs: radioStubs } })
        await flushPromises()

        expect(wrapper.get('[role="radiogroup"]').attributes('data-value')).toBe('stop')
        await wrapper.get('[data-radio="continue"]').trigger('click')
        expect(wrapper.emitted('updateOnError')).toEqual([['notify', 'continue']])
        // The choice is a link: the step settings are not touched.
        expect(wrapper.emitted('updateNodeConfig')).toBeUndefined()

        const trigger = nodes.find((node) => node.id === 'trigger')
        await wrapper.setProps({ selectedNode: trigger })
        expect(wrapper.find('[data-testid="on-error-setting"]').exists()).toBe(false)
    })
})

describe('advanced mode', () => {
    const { nodes, edges } = storySplitGraph()
    const review = nodes.find((node) => node.id === 'review')
    const mountShell = (selectedNode = review) =>
        mount(NodeEditorShell, { props: { selectedNode, steps: nodes, edges, workflowId: 7 }, global: { stubs: radioStubs }, attachTo: document.body })

    it('has no raw switch beside the step type, and nothing advanced for an editor', async () => {
        const wrapper = mountShell()
        await flushPromises()

        expect(wrapper.find('[data-testid="node-editor-advanced"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="advanced-config"]').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('text.workflow.editor.mode.raw')
        // No raw settings anywhere in the form.
        expect(wrapper.html()).not.toContain('reviewer_permission&quot;')
        wrapper.unmount()
    })

    it('a Webmaster opens the raw settings at the end of the form, behind a warning', async () => {
        account.webmaster = true
        const wrapper = mountShell()
        await flushPromises()

        const advanced = wrapper.get('[data-testid="node-editor-advanced"]')
        expect(advanced.text()).toContain('text.workflow.advanced.webmaster_only')
        expect(wrapper.find('[data-testid="advanced-config"]').exists()).toBe(false)
        // It is the last block of the form.
        expect(wrapper.get('[data-testid="node-editor-shell"]').element.lastElementChild).toBe(advanced.element)

        await wrapper.get('[data-testid="node-editor-advanced-toggle"]').trigger('click')
        expect(wrapper.get('[data-testid="advanced-config"]').html()).toContain('text.workflow.advanced.warning')
        const raw = wrapper.get('[data-testid="advanced-config-json"]')
        expect(JSON.parse((raw.element as HTMLTextAreaElement).value)).toEqual(review.data.step_config)

        // Invalid JSON is not saved.
        await raw.setValue('{ "mode": ')
        await raw.trigger('blur')
        expect(wrapper.find('[data-testid="advanced-config-invalid"]').exists()).toBe(true)
        expect(wrapper.emitted('updateNodeConfig')).toBeUndefined()

        await raw.setValue(JSON.stringify({ ...review.data.step_config, subject_id: 'vars.place_uuid' }))
        await raw.trigger('blur')
        await nextTick()
        const events = wrapper.emitted('updateNodeConfig') as Array<[string, Record<string, unknown>]>
        expect(events[events.length - 1][0]).toBe('review')
        expect(events[events.length - 1][1].subject_id).toBe('vars.place_uuid')
        wrapper.unmount()
    })

    it('a step without a form says so instead of showing JSON', async () => {
        const plain = { id: 'x', type: 'step', data: { name: 'Odd', step_code: 'x', step_type: 'mystery', step_config: { secret_key: 'abc' } } }
        const wrapper = mountShell(plain)
        await flushPromises()

        expect(wrapper.get('[data-testid="node-editor-no-form"]').exists()).toBe(true)
        expect(wrapper.html()).not.toContain('secret_key')
        wrapper.unmount()
    })

    it('loads sample values of the last run once per workflow', async () => {
        const wrapper = mountShell()
        await flushPromises()
        expect(api.fetchLastRunContext).toHaveBeenCalledTimes(1)
        expect(api.fetchLastRunContext).toHaveBeenCalledWith(7)

        await wrapper.setProps({ selectedNode: nodes.find((node) => node.id === 'notify') })
        await flushPromises()
        expect(api.fetchLastRunContext).toHaveBeenCalledTimes(1)
        wrapper.unmount()
    })
})
