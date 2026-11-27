import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const api = vi.hoisted(() => ({
    fetchWorkflowPresets: vi.fn(),
    fetchPermissionOptions: vi.fn(),
    fetchModelList: vi.fn(),
    fetchModelSchema: vi.fn(),
}))

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key} ${JSON.stringify(params)}` : key), te: () => false }),
}))
vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => false }))
vi.mock('@/services/workflowApi', () => ({
    fetchNodeCatalog: vi.fn(),
    fetchSelectableModels: vi.fn().mockResolvedValue([]),
    searchReviewers: vi.fn().mockResolvedValue([]),
    resolveReviewers: vi.fn().mockResolvedValue([]),
    fetchLastRunContext: vi.fn().mockResolvedValue(null),
    validateExpression: vi.fn().mockResolvedValue({ ok: true, errors: [] }),
    ...api,
}))
vi.mock('@/ajax', () => ({ default: { put: vi.fn(), get: vi.fn(), post: vi.fn(), delete: vi.fn() } }))
vi.mock('@/mixins', () => ({ iAlert: vi.fn() }))

import { buildDataGroups, defaultStorePath, pathLabel, resultParts, stepStores } from '@/components/workflow/inputs/dataTokens.js'
import NodeEditorShell from '@/components/workflow/node-editors/NodeEditorShell.vue'
import SchemaFormEditor from '@/components/workflow/node-editors/SchemaFormEditor.vue'
import {
    caseFor,
    commonQuestions,
    conditionKind,
    defaultItemKeys,
    editableCandidates,
    isTypedCondition,
    labelSourceOf,
    readCriteria,
    readInput,
    readLabels,
    readPairs,
    readSchemaFields,
    strictnessOf,
    toggleCase,
    toggleLabel,
    toggleSchemaField,
    widgetFor,
    writeConditions,
    writeCriteria,
    writeInput,
    writePairs,
} from '@/components/workflow/widgets/widgetRules.js'
import { missingSettings, stepsNeedingSetup } from '@/components/workflows/setupRules.js'
import { planRelink } from '@/components/workflows/graphRules.js'
import { applyNodeCatalog } from '@/components/workflows/nodeCatalog'
import { antdStubs } from './antd-stubs'
import { coreDefaultConfigs, storySplitGraph, storySplitSteps } from './story-split-fixture'

// ---------------------------------------------------------------------------------------------------------------------
// Catalog rows, copied from GET workflow/node-catalog (result fields, values, setup marks)
// ---------------------------------------------------------------------------------------------------------------------

const expr = { type: ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'], 'x-expr': true }
const str = { type: ['string', 'null'] }
const list = { type: ['array', 'object', 'null'] }
const store = { type: ['string', 'null'], 'x-store': true }
const part = (key: string, type: string, label: string, extra: Record<string, unknown> = {}) => ({ key, type, label, label_key: `text.workflow.result.${key.replace(/\./g, '_')}`, ...extra })
const verdicts = [
    { value: 'pass', label: 'Đạt' },
    { value: 'fail', label: 'Không đạt' },
    { value: 'unsure', label: 'Chưa chắc' },
]

const catalog = {
    groups: [],
    nodes: [
        { type: 'trigger_manual', group: 'trigger', config_schema: { type: 'object', properties: { storeAs: store } }, default_config: {}, outputs: ['main'], result: [part('source', 'string', 'Source')], result_type: 'object' },
        {
            type: 'sample_module.research_place',
            group: 'module',
            config_schema: { type: 'object', properties: { place_uuid: expr, storeAs: store } },
            default_config: { storeAs: 'vars.draft' },
            outputs: ['main'],
            result_type: 'object',
            result: [part('place_uuid', 'string', 'Place ID'), part('name', 'string', 'Name'), part('status', 'string', 'Status'), part('pending', 'boolean', 'Waiting for review'), part('summary', 'string', 'Summary'), part('history', 'string', 'History'), part('timeline', 'list', 'Timeline'), part('review', 'object', 'Review request')],
        },
        {
            type: 'sample_module.jev_review',
            group: 'module',
            config_schema: { type: 'object', properties: { place_uuid: expr, version: expr, storeAs: store } },
            default_config: { storeAs: 'vars.jev' },
            outputs: ['main'],
            result_type: 'object',
            result: [part('verdict', 'string', 'Verdict', { values: verdicts }), part('probability', 'number', 'Probability'), part('reused', 'boolean', 'Reused')],
        },
        {
            type: 'jev_review',
            group: 'human',
            config_schema: { type: 'object', properties: { criteria: { ...str, 'x-required': true, 'x-setup': 'required_field' }, criteria_preset: str, criteria_items: list, content: expr, min_confidence: { type: ['number', 'integer', 'string', 'null'] }, mode: { type: ['string', 'null'], enum: ['active', 'shadow', null] }, storeAs: store } },
            default_config: { criteria: '', content: 'vars.draft', min_confidence: 0.8, mode: 'active', storeAs: 'vars.jev' },
            outputs: ['pass', 'fail', 'unsure'],
            result_type: 'object',
            result: [part('verdict', 'string', 'Verdict', { values: verdicts }), part('confidence', 'number', 'Confidence')],
        },
        {
            type: 'approval',
            group: 'human',
            config_schema: {
                type: 'object',
                properties: {
                    mode: { type: ['string', 'null'], enum: ['human', 'auto', 'jev', null] },
                    title: str,
                    review: expr,
                    editable_fields: { type: ['array', 'null'], items: { type: 'string' } },
                    reviewer_permission: { ...str, 'x-setup': 'reviewers' },
                    notify: { type: ['boolean', 'string', 'integer', 'null'] },
                    assignees: { type: ['array', 'null'], items: { type: 'string' }, maxItems: 10 },
                    max_rounds: { type: ['integer', 'string', 'null'] },
                    revision_options: { type: ['array', 'null'], items: { type: 'object' } },
                    reject_reasons: { type: ['array', 'null'], items: { type: 'object' } },
                    storeAs: store,
                },
            },
            default_config: { storeAs: 'vars.approval' },
            outputs: ['approved', 'rejected', 'expired', 'changes_requested'],
            result_type: 'object',
            result: [part('decision', 'string', 'Decision', { values: [{ value: 'approved', label: 'Đã duyệt' }, { value: 'rejected', label: 'Bị từ chối' }] }), part('note', 'string', 'Note')],
        },
        { type: 'notify', group: 'human', config_schema: { type: 'object', properties: { permission: { ...str, 'x-setup': 'notify_channel' }, message: str, storeAs: store } }, default_config: {}, outputs: ['main'], result_type: 'object', result: [part('count', 'number', 'Count')] },
        { type: 'ai_classify', group: 'ai', config_schema: { type: 'object', properties: { text: expr, inputs: list, instructions: expr, labels: { ...list, 'x-required': true, 'x-setup': 'required_field' }, storeAs: store } }, default_config: { labels: [], storeAs: 'vars.classify' }, outputs: ['default'], result_type: 'object', result: [part('label', 'string', 'Chosen label', { values_from: 'labels' }), part('confidence', 'number', 'Confidence')] },
        { type: 'ai_extract', group: 'ai', config_schema: { type: 'object', properties: { text: expr, schema: list, storeAs: store } }, default_config: { storeAs: 'vars.extract' }, outputs: ['main'], result_type: 'object', dynamic: 'schema', result: [] },
        { type: 'ai_score', group: 'ai', config_schema: { type: 'object', properties: { text: expr, rubric: list, storeAs: store } }, default_config: {}, outputs: ['pass', 'fail'], result_type: 'object', result: [part('score', 'number', 'Score')] },
        {
            type: 'http_request',
            group: 'integration',
            config_schema: { type: 'object', properties: { method: str, url: expr, headers: { type: ['object', 'array', 'null'] }, query: { type: ['object', 'array', 'null'] }, auth: { type: ['string', 'null'], enum: ['none', 'header', 'basic', null] }, credential: { ...str, 'x-secret': 'http_auth', 'x-setup': 'credential', 'x-setup-when': { auth: ['header', 'basic'] } }, storeAs: store } },
            default_config: { storeAs: 'vars.http' },
            outputs: ['success', 'error'],
            result_type: 'object',
            result: [part('status', 'number', 'Status'), part('ok', 'boolean', 'Succeeded')],
        },
        { type: 'telegram_send', group: 'integration', config_schema: { type: 'object', properties: { credential: { ...str, 'x-secret': 'telegram_bot', 'x-setup': 'credential' }, chat: expr, text: expr, storeAs: store } }, default_config: {}, outputs: ['main'], result: [part('sent', 'boolean', 'Sent')], result_type: 'object' },
        { type: 'decision', group: 'flow', config_schema: { type: 'object', properties: { expression: expr, combinator: str, conditions: { type: ['array', 'null'] } } }, default_config: { expression: 'true' }, outputs: ['true', 'false'], result: null },
        { type: 'set_variable', group: 'data', config_schema: { type: 'object', properties: {} }, default_config: {}, outputs: ['main'], dynamic: 'assignments', result: [] },
        { type: 'loop_each', group: 'flow', config_schema: { type: 'object', properties: {} }, default_config: {}, outputs: ['each', 'done'], dynamic: 'loop', result: [] },
        { type: 'switch', group: 'flow', config_schema: { type: 'object', properties: {} }, default_config: {}, outputs: ['default'], result: null },
        { type: 'lock', group: 'utility', config_schema: { type: 'object', properties: {} }, default_config: {}, outputs: ['acquired', 'busy'], result: [] },
        { type: 'assert_condition', group: 'flow', config_schema: { type: 'object', properties: {} }, default_config: {}, outputs: ['main'], result: [] },
        { type: 'sample_module.select_places', group: 'module', config_schema: { type: 'object', properties: {} }, default_config: {}, outputs: ['main'], result: [part('place_uuids', 'list', 'Places'), part('count', 'number', 'Count')], result_type: 'object' },
        { type: 'sample_module.publish_place_story', group: 'module', config_schema: { type: 'object', properties: {} }, default_config: {}, outputs: ['main'], result: [part('public_url', 'string', 'Link')], result_type: 'object' },
    ],
}
const byType = Object.fromEntries(catalog.nodes.map((node) => [node.type, node]))

const criteriaPresets = [
    {
        key: 'church_profile',
        label: 'Bài giới thiệu nhà thờ',
        description: '',
        module: 'SampleModule',
        items: [
            { key: 'no_unsourced_claims', label: 'Không thêm thông tin ngoài nguồn đã dẫn', text: 'No claim beyond the cited sources.', default: true },
            { key: 'names', label: 'Đúng tên nhà thờ, giáo phận, địa chỉ', text: 'Names and addresses match the source.', default: true },
            { key: 'mass_times', label: 'Có giờ lễ khi nguồn có', text: 'Mass times are given when the source has them.', default: false },
        ],
    },
    { key: 'news', label: 'Tin tức giáo xứ', description: '', module: 'Core', items: [{ key: 'source_facts', label: 'Facts', text: 'Facts from the source only.', default: true }] },
]
const labelPresets = [
    { key: 'post_categories', label: 'Danh mục bài viết của website', source: 'content.categories', items: [{ key: 'tin-tuc', label: 'Tin tức' }, { key: 'suy-niem', label: 'Suy niệm' }, { key: 'thong-bao', label: 'Thông báo' }] },
    { key: 'contact_kinds', label: 'Loại tin nhắn liên hệ', items: [{ key: 'question', label: 'Câu hỏi' }, { key: 'complaint', label: 'Góp ý' }] },
]

const checkbox = defineComponent({
    name: 'ACheckboxStub',
    props: { checked: Boolean, disabled: Boolean },
    emits: ['update:checked'],
    setup(props, { emit, slots }) {
        return () => h('label', {}, [h('input', { type: 'checkbox', checked: props.checked, disabled: props.disabled, onChange: (event: Event) => emit('update:checked', (event.target as HTMLInputElement).checked) }), slots.default?.()])
    },
})
const stubs = { ...antdStubs, 'a-checkbox': checkbox, 'a-auto-complete': antdStubs['a-input'] }

const mountForm = (modelValue: Record<string, unknown>, stepType: string) =>
    mount(SchemaFormEditor, { props: { stepType, modelValue: JSON.parse(JSON.stringify(modelValue)) }, global: { stubs }, attachTo: document.body })
const lastSaved = (wrapper: ReturnType<typeof mount>) => {
    const events = wrapper.emitted('update:modelValue') as Array<[Record<string, unknown>]>
    return events[events.length - 1][0]
}

beforeEach(() => {
    api.fetchWorkflowPresets.mockReset().mockImplementation(async (kind: string) => (kind === 'jev_criteria' ? criteriaPresets : kind === 'classify_labels' ? labelPresets : []))
    api.fetchPermissionOptions.mockReset().mockResolvedValue([
        { key: 'sample-module.stories.review', label: 'Duyệt bài viết', module: 'SampleModule', module_label: 'Mô-đun mẫu', resource_label: 'Truyện' },
        { key: 'agentic.approve', label: 'Duyệt việc AI đề xuất', module: 'Core', module_label: 'Hệ thống' },
    ])
    api.fetchModelList.mockReset().mockResolvedValue([{ class: 'Cms\\SampleModule\\Models\\Place', short_name: 'Place' }])
    api.fetchModelSchema.mockReset().mockResolvedValue({ fields: [{ name: 'id', type: 'integer' }, { name: 'name', type: 'string' }, { name: 'address', type: 'string' }, { name: 'built_year', type: 'integer' }, { name: 'diocese_id', type: 'integer' }] })
    applyNodeCatalog(catalog)
})

// ---------------------------------------------------------------------------------------------------------------------

describe('data picker: the parts of a result are listed before any run', () => {
    const graph = storySplitGraph()

    it('lists the declared result fields of the earlier steps with no sample at all', () => {
        const groups = buildDataGroups({ nodes: graph.nodes, edges: graph.edges, currentId: 'review', byType })
        const draft = groups.find((group) => group.key === 'research')!
        expect(draft.later).toBe(false)
        expect(draft.items.map((item) => item.path)).toEqual(['vars.draft', 'vars.draft.place_uuid', 'vars.draft.name', 'vars.draft.status', 'vars.draft.pending', 'vars.draft.summary', 'vars.draft.history', 'vars.draft.timeline', 'vars.draft.review'])
        expect(draft.items.find((item) => item.path === 'vars.draft.pending')!.type).toBe('boolean')
        expect(draft.items.find((item) => item.path === 'vars.draft.summary')!.label).toBe('Summary')
        expect(draft.items[0].type).toBe('group')
        // A part that holds one of a known set of values carries them.
        const verdict = groups.find((group) => group.key === 'jev')!.items.find((item) => item.path === 'vars.jev.verdict')!
        expect(verdict.values).toEqual(verdicts)
        expect(pathLabel('vars.jev.verdict', groups)).toBe('JEV second opinion › Verdict')
        // Later steps are still greyed.
        expect(groups.find((group) => group.key === 'publish')!.later).toBe(true)
    })

    it('shows a sample value beside a declared part once the workflow ran, and keeps undeclared parts of the run', () => {
        const sample = { vars: { draft: { name: 'Nhà thờ Đức Bà', summary: 'Một đoạn tóm tắt', extra_note: 'x' } } }
        const draft = buildDataGroups({ nodes: graph.nodes, edges: graph.edges, currentId: 'review', byType, sample }).find((group) => group.key === 'research')!
        expect(draft.items.find((item) => item.path === 'vars.draft.name')!.sample).toBe('Nhà thờ Đức Bà')
        expect(draft.items.filter((item) => item.path === 'vars.draft.name')).toHaveLength(1)
        expect(draft.items.some((item) => item.path === 'vars.draft.extra_note')).toBe(true)
    })

    it('uses the place a runner stores to when the step names none', () => {
        expect(defaultStorePath('ai_extract')).toBe('vars.extract')
        expect(defaultStorePath('notify')).toBe('vars.notified')
        expect(defaultStorePath('http_request')).toBeNull()
        expect(stepStores({ data: { step_type: 'ai_classify', step_config: {} } }, byType.ai_classify)).toEqual([{ path: 'vars.classify', role: 'result' }])
        // An empty name means "store nothing"; an explicit one wins.
        expect(stepStores({ data: { step_type: 'notify', step_config: { storeAs: '' } } }, byType.notify)).toEqual([])
        expect(stepStores({ data: { step_type: 'notify', step_config: { storeAs: 'vars.told' } } }, byType.notify)).toEqual([{ path: 'vars.told', role: 'result' }])
    })

    it('expands a result from the step settings: schema, labels, record type and assigned variables', () => {
        const schema = { type: 'object', properties: { name: { type: 'string' }, built_year: { type: ['integer', 'null'] }, address: { type: 'object', properties: { city: { type: 'string' } } }, kind: { type: 'string', enum: ['parish', 'shrine'] } } }
        expect(resultParts(byType.ai_extract, { schema }).map((row) => [row.key, row.type])).toEqual([['name', 'text'], ['built_year', 'number'], ['address', 'group'], ['address.city', 'text'], ['kind', 'text']])
        expect(resultParts(byType.ai_extract, { schema })[4].values).toEqual([{ value: 'parish', label: 'parish' }, { value: 'shrine', label: 'shrine' }])
        expect(resultParts(byType.ai_extract, coreDefaultConfigs.ai_prompt)).toEqual([])

        const labels = [{ key: 'tin-tuc', description: 'Tin tức' }, 'khac']
        expect(resultParts(byType.ai_classify, { labels })[0].values).toEqual([{ value: 'tin-tuc', label: 'Tin tức' }, { value: 'khac', label: 'khac' }])

        const retrieve = { type: 'retrieve', dynamic: 'model', result: [] }
        const fields = [{ name: 'title', type: 'string' }, { name: 'views', type: 'integer' }]
        expect(resultParts(retrieve, { model: 'Post', first: true }, fields).map((row) => row.key)).toEqual(['title', 'views'])
        expect(resultParts(retrieve, { model: 'Post', first: false }, fields)).toEqual([])

        const stores = stepStores({ data: { step_type: 'set_variable', step_config: { assignments: [{ key: 'vars.total_price', value: '1' }, { key: 'root.x' }] } } }, byType.set_variable)
        expect(stores).toEqual([{ path: 'vars.total_price', role: 'var', label: 'Total price' }])
    })
})

describe('widgets keep a saved config unchanged until a choice is made', () => {
    it('opens every step of the story sample and the Core defaults without writing anything', async () => {
        const configs: Array<[string, Record<string, unknown>]> = [
            ...Object.values(storySplitSteps).map(([type, , config]) => [type, config] as [string, Record<string, unknown>]),
            ...Object.entries(coreDefaultConfigs),
            ['jev_review', { criteria: 'No claim beyond the cited sources.\nMy own line', criteria_preset: 'church_profile', criteria_items: ['no_unsourced_claims'], content: 'vars.draft', min_confidence: 0.75, storeAs: 'vars.jev' }],
            ['ai_classify', { text: '{{ vars.message.text }}', labels: { question: 'A question', other: '' }, storeAs: 'vars.classify' }],
            ['ai_classify', { text: '', labels: ['a', { key: 'b', description: 'B' }], inputs: { doc: '{{ vars.post.text }}', note: { value: 'x', trusted: true } } }],
            ['ai_extract', { text: '', schema: { type: 'object', properties: { name: { type: 'string', description: 'Full name' } }, required: ['name'] } }],
            ['ai_extract', { text: '', schema: { type: 'object', properties: [] } }],
            ['ai_score', { text: '', rubric: [{ key: 'clarity', weight: 2, description: 'Clear' }], threshold: 70 }],
            ['http_request', { method: 'POST', url: 'https://example.org', headers: { Accept: 'application/json', 'X-Trace': '{{ vars.run_id }}' }, query: [], auth: 'none' }],
        ]
        for (const [type, config] of configs) {
            const wrapper = mountForm(config, type)
            await flushPromises()
            expect(wrapper.emitted('update:modelValue'), `${type} ${JSON.stringify(config)}`).toBeUndefined()
            wrapper.unmount()
        }
    })

    it('names which widget a setting uses', () => {
        expect(widgetFor('approval', 'editable_fields')).toBe('editable_fields')
        expect(widgetFor('approval', 'reviewer_permission')).toBe('permission')
        expect(widgetFor('notify', 'permission')).toBe('permission')
        expect(widgetFor('jev_review', 'criteria')).toBe('jev_criteria')
        expect(widgetFor('ai_extract', 'schema')).toBe('extract_schema')
        expect(widgetFor('ai_classify', 'labels')).toBe('classify_labels')
        expect(widgetFor('ai_score', 'rubric')).toBe('rubric')
        expect(widgetFor('http_request', 'headers')).toBe('name_values')
        expect(widgetFor('ai_prompt', 'inputs')).toBe('named_inputs')
        expect(widgetFor('ai_action', 'inputs')).toBe('')
        expect(widgetFor('json_transform', 'schema')).toBe('')
        expect(widgetFor('custom.node', 'who', { 'x-widget': 'permission' })).toBe('permission')
    })
})

describe('approval: what the reviewer may edit is a checklist', () => {
    const graph = storySplitGraph()
    const shell = () =>
        mount(NodeEditorShell, {
            props: { selectedNode: graph.nodes.find((node) => node.id === 'review'), steps: graph.nodes, edges: graph.edges, workflowId: null },
            global: { stubs },
            attachTo: document.body,
        })

    it('offers the text parts of the reviewed step and leaves ids and states out', () => {
        const groups = buildDataGroups({ nodes: graph.nodes, edges: graph.edges, currentId: 'review', byType })
        const found = editableCandidates('vars.draft.review', groups)
        expect(found.group!.title).toBe('Research the story')
        expect(found.items.map((item) => item.key)).toEqual(['name', 'summary', 'history'])
        expect(editableCandidates('vars.nothing', groups)).toEqual({ group: null, items: [] })
    })

    it('shows the sample choice ticked and stores a tick as one more name', async () => {
        const wrapper = shell()
        await flushPromises()
        const field = wrapper.get('[data-field="editable_fields"]')
        expect(field.attributes('data-kind')).toBe('editable_fields')
        expect(field.get('[data-testid="editable-fields-source"]').text()).toContain('Research the story')
        const ticked = field.findAll('li').filter((row) => (row.get('input').element as HTMLInputElement).checked).map((row) => row.attributes('data-field-key'))
        expect(ticked).toEqual(['summary', 'history'])
        expect(wrapper.emitted('updateNodeConfig')).toBeUndefined()

        await field.get('[data-field-key="name"] input').setValue(true)
        await flushPromises()
        const [, saved] = (wrapper.emitted('updateNodeConfig') as Array<[string, Record<string, unknown>]>).pop()!
        expect(saved.editable_fields).toEqual(['summary', 'history', 'name'])
        expect(JSON.stringify({ ...saved, editable_fields: ['summary', 'history'] })).toBe(JSON.stringify(storySplitSteps.review[2]))
        wrapper.unmount()
    })

    it('picks who reviews from the permission list, by name', async () => {
        const wrapper = shell()
        await flushPromises()
        const field = wrapper.get('[data-field="reviewer_permission"]')
        expect(field.attributes('data-kind')).toBe('permission')
        const select = field.getComponent({ name: 'ASelectStub' })
        expect(select.props('value')).toBe('sample-module.stories.review')
        // Grouped by module, each permission by its name.
        expect(select.props('options')).toEqual([
            { label: 'Mô-đun mẫu', options: [{ value: 'sample-module.stories.review', label: 'Mô-đun mẫu › Truyện › Duyệt bài viết' }] },
            { label: 'Hệ thống', options: [{ value: 'agentic.approve', label: 'Hệ thống › Duyệt việc AI đề xuất' }] },
        ])
        wrapper.unmount()
    })
})

describe('JEV: a ready-made set of criteria', () => {
    it('reads a stored choice and writes the text the judge reads', () => {
        const config = { criteria: 'No claim beyond the cited sources.\nMy own line', criteria_preset: 'church_profile', criteria_items: ['no_unsourced_claims'] }
        expect(readCriteria(config, criteriaPresets)).toEqual({ presetKey: 'church_profile', itemKeys: ['no_unsourced_claims'], custom: ['My own line'] })
        // Without the item list, an item is on when its text is one of the lines.
        expect(readCriteria({ criteria: 'Names and addresses match the source.', criteria_preset: 'church_profile' }, criteriaPresets).itemKeys).toEqual(['names'])
        // No set (or a set this site lacks): every line is the operator's own, nothing is lost.
        expect(readCriteria({ criteria: 'a\nb', criteria_preset: 'gone' }, criteriaPresets)).toEqual({ presetKey: '', itemKeys: [], custom: ['a', 'b'] })

        expect(writeCriteria({ presetKey: 'church_profile', itemKeys: ['mass_times', 'no_unsourced_claims'], custom: ['My own line', ' '] }, criteriaPresets)).toEqual({
            criteria: 'No claim beyond the cited sources.\nMass times are given when the source has them.\nMy own line',
            criteria_preset: 'church_profile',
            criteria_items: ['no_unsourced_claims', 'mass_times'],
        })
        expect(writeCriteria({ presetKey: '', itemKeys: [], custom: ['Only this'] }, criteriaPresets)).toEqual({ criteria: 'Only this', criteria_preset: undefined, criteria_items: undefined })
        expect(defaultItemKeys(criteriaPresets[0])).toEqual(['no_unsourced_claims', 'names'])
        expect([strictnessOf(0.6), strictnessOf(0.8), strictnessOf('0.9'), strictnessOf(0.75), strictnessOf(null)]).toEqual(['easy', 'medium', 'strict', '', ''])
    })

    it('switching the set writes its default criteria; a toggle and the strictness write only their settings', async () => {
        const stored = { criteria: 'No claim beyond the cited sources.', criteria_preset: 'church_profile', criteria_items: ['no_unsourced_claims'], content: 'vars.draft', min_confidence: 0.8, mode: 'active', storeAs: 'vars.jev' }
        const wrapper = mountForm(stored, 'jev_review')
        await flushPromises()
        const field = wrapper.get('[data-field="criteria"]')
        expect(field.attributes('data-kind')).toBe('jev_criteria')
        // The settings the widget writes are not fields of their own.
        expect(wrapper.find('[data-field="criteria_preset"]').exists()).toBe(false)
        expect(wrapper.find('[data-field="min_confidence"]').exists()).toBe(false)
        expect((field.get('[data-testid="jev-criteria-preset"]').element as HTMLSelectElement).value).toBe('church_profile')
        expect((field.get('[data-item="no_unsourced_claims"] input').element as HTMLInputElement).checked).toBe(true)
        expect((field.get('[data-item="names"] input').element as HTMLInputElement).checked).toBe(false)
        expect(field.get('[data-level="medium"]').attributes('aria-pressed')).toBe('true')

        await field.get('[data-item="names"] input').setValue(true)
        let saved = lastSaved(wrapper)
        expect(saved.criteria).toBe('No claim beyond the cited sources.\nNames and addresses match the source.')
        expect(saved.criteria_items).toEqual(['no_unsourced_claims', 'names'])
        expect(saved.content).toBe('vars.draft')

        await field.get('[data-testid="jev-criteria-preset"]').setValue('news')
        saved = lastSaved(wrapper)
        expect(saved).toMatchObject({ criteria: 'Facts from the source only.', criteria_preset: 'news', criteria_items: ['source_facts'] })

        await field.get('[data-level="strict"]').trigger('click')
        saved = lastSaved(wrapper)
        expect(saved.min_confidence).toBe(0.9)
        expect(saved.criteria_preset).toBe('news')
        wrapper.unmount()
    })

    it('keeps criteria written by hand as own lines when no set is stored', async () => {
        const wrapper = mountForm({ criteria: 'Line one\nLine two', content: 'vars.draft', min_confidence: 0.75 }, 'jev_review')
        await flushPromises()
        const lines = wrapper.findAll('[data-testid="jev-criteria-custom-line"]').map((row) => (row.element as HTMLTextAreaElement).value)
        expect(lines).toEqual(['Line one', 'Line two'])
        expect(wrapper.get('[data-testid="jev-criteria-strictness-note"]').text()).toContain('0.75')
        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
        wrapper.unmount()
    })
})

describe('name and value rows, labels and extract fields are stored in the shape they were read in', () => {
    it('rows of a map', () => {
        const headers = { Accept: 'application/json', 'X-Trace': '{{ vars.run_id }}' }
        expect(writePairs(readPairs(headers), headers)).toEqual(headers)
        expect(Object.keys(writePairs([...readPairs(headers), { name: 'X-New', value: '1' }, { name: ' ', value: 'dropped' }], headers) as object)).toEqual(['Accept', 'X-Trace', 'X-New'])
        expect(writePairs([], null)).toBeNull()
        expect(writePairs([], [])).toEqual([])
        expect(writePairs([], { a: 1 })).toEqual({})
        const rows = [{ name: 'doc', value: '{{ vars.a }}', trusted: true }]
        expect(writePairs(readPairs(rows), rows)).toEqual(rows)
    })

    it('adds a header line from the common names and stores the map', async () => {
        const wrapper = mountForm({ method: 'GET', url: 'https://example.org', auth: 'none' }, 'http_request')
        await flushPromises()
        const field = wrapper.get('[data-field="headers"]')
        expect(field.attributes('data-kind')).toBe('name_values')
        expect(field.find('[data-testid="name-value-empty"]').exists()).toBe(true)
        await field.get('[data-testid="name-value-add"]').trigger('click')
        // A row without a name is not stored.
        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
        await field.get('[data-testid="name-value-name"]').setValue('Accept')
        expect(lastSaved(wrapper).headers).toEqual({ Accept: '' })
        wrapper.unmount()
    })

    it('AI inputs: a plain value stays plain, a trusted one becomes a group', () => {
        expect(readInput('{{ vars.a }}')).toEqual({ value: '{{ vars.a }}', trusted: false })
        expect(readInput({ value: 'x', trusted: 'true' })).toEqual({ value: 'x', trusted: true })
        expect(writeInput({ value: 'y', trusted: false }, '{{ vars.a }}')).toBe('y')
        expect(writeInput({ value: 'y', trusted: true }, '{{ vars.a }}')).toEqual({ value: 'y', trusted: true })
        expect(writeInput({ value: 'y', trusted: false }, { value: 'x', trusted: true })).toEqual({ value: 'y', trusted: false })
    })

    it('labels: read in every stored form, toggled without rewriting the others', () => {
        expect(readLabels(['a', { key: 'b', description: 'B' }])).toEqual([{ key: 'a', label: '' }, { key: 'b', label: 'B' }])
        expect(readLabels({ q: 'A question', other: '' })).toEqual([{ key: 'q', label: 'A question' }, { key: 'other', label: '' }])
        const stored = ['a', { key: 'b', description: 'B', extra: 1 }]
        expect(toggleLabel(stored, 'tin-tuc', 'Tin tức')).toEqual(['a', { key: 'b', description: 'B', extra: 1 }, { key: 'tin-tuc', description: 'Tin tức' }])
        expect(toggleLabel(stored, 'a')).toEqual([{ key: 'b', description: 'B', extra: 1 }])
        expect(toggleLabel({ q: 'A question' }, 'new', 'New')).toEqual({ q: 'A question', new: 'New' })
        expect(toggleLabel([], 'own')).toEqual(['own'])
        expect(labelSourceOf(['tin-tuc', { key: 'suy-niem' }], labelPresets)).toBe('post_categories')
        expect(labelSourceOf(['tin-tuc', 'mine'], labelPresets)).toBe('')
    })

    it('classify: the stored labels show as chosen chips of their source; a click adds one', async () => {
        const wrapper = mountForm({ text: '', labels: ['tin-tuc'], storeAs: 'vars.classify' }, 'ai_classify')
        await flushPromises()
        const field = wrapper.get('[data-field="labels"]')
        expect((field.get('[data-testid="classify-labels-source"]').element as HTMLSelectElement).value).toBe('post_categories')
        expect(field.get('[data-label-key="tin-tuc"]').attributes('aria-pressed')).toBe('true')
        expect(field.get('[data-label-key="suy-niem"]').attributes('aria-pressed')).toBe('false')
        await field.get('[data-label-key="suy-niem"]').trigger('click')
        expect(lastSaved(wrapper).labels).toEqual(['tin-tuc', { key: 'suy-niem', description: 'Suy niệm' }])
        wrapper.unmount()
    })

    it('extract: stored fields stay ticked; ticking a field of the data type adds it with its type', async () => {
        expect(readSchemaFields({ type: 'object', properties: [] })).toEqual([])
        const schema = { type: 'object', properties: { name: { type: 'string', description: 'Full name' } }, required: ['name'], 'x-model': 'Cms\\SampleModule\\Models\\Place' }
        expect(toggleSchemaField(schema, 'built_year', 'integer')).toEqual({ ...schema, properties: { name: { type: 'string', description: 'Full name' }, built_year: { type: 'integer' } } })
        expect(toggleSchemaField(schema, 'name')).toEqual({ ...schema, properties: {}, required: [] })

        const wrapper = mountForm({ text: '', schema, storeAs: 'vars.extract' }, 'ai_extract')
        await flushPromises()
        const field = wrapper.get('[data-field="schema"]')
        // System columns (ids) are never offered.
        expect(field.findAll('[data-field-name]').map((row) => row.attributes('data-field-name'))).toEqual(['name', 'address', 'built_year'])
        expect((field.get('[data-field-name="name"] input').element as HTMLInputElement).checked).toBe(true)
        await field.get('[data-field-name="built_year"] input').setValue(true)
        expect((lastSaved(wrapper).schema as any).properties).toEqual({ name: { type: 'string', description: 'Full name' }, built_year: { type: 'integer' } })
        wrapper.unmount()
    })
})

describe('yes / no step: the value is picked, and common questions come from earlier steps', () => {
    const graph = storySplitGraph()
    const decision = { id: 'ask', type: 'step', data: { name: 'Bài đạt?', step_code: 'ask', step_type: 'decision', is_start: false, step_config: { expression: 'true' } } }
    const nodes = [...graph.nodes, decision]
    const edges = [...graph.edges, { id: 'x1', source: 'jev', target: 'ask', sourceHandle: 'output', targetHandle: 'input' }]
    const shell = (config: Record<string, unknown>) =>
        mount(NodeEditorShell, {
            props: { selectedNode: { ...decision, data: { ...decision.data, step_config: config } }, steps: nodes, edges, workflowId: null },
            global: { stubs },
            attachTo: document.body,
        })

    it('generates questions from the parts that have known values or are yes / no', () => {
        const groups = buildDataGroups({ nodes, edges, currentId: 'ask', byType })
        const questions = commonQuestions(groups, 20)
        expect(questions.map((row) => row.key)).toEqual(['vars.draft.pending?', 'vars.jev.verdict=pass', 'vars.jev.verdict=fail', 'vars.jev.verdict=unsure', 'vars.jev.reused?'])
        expect(commonQuestions(groups, 2).map((row) => row.key)).toEqual(['vars.jev.verdict=unsure', 'vars.jev.reused?'])
        expect(conditionKind(questions[1].item)).toBe('choice')
        expect(conditionKind(questions[0].item)).toBe('boolean')
    })

    it('writes rows the engine reads and drops the typed expression', () => {
        expect(writeConditions([{ left: 'vars.jev.verdict', operator: 'eq', right: 'pass' }, { left: 'vars.draft.pending', operator: 'is_true', right: 'ignored' }], 'or')).toEqual({
            conditions: [{ left: 'vars.jev.verdict', operator: 'eq', right: 'pass' }, { left: 'vars.draft.pending', operator: 'is_true' }],
            combinator: 'or',
            expression: undefined,
            condition: undefined,
        })
        expect(isTypedCondition({ expression: 'true' })).toBe(false)
        expect(isTypedCondition({ expression: 'vars.a > 3' })).toBe(true)
        expect(isTypedCondition({ expression: 'vars.a > 3', conditions: [{ left: 'vars.a', operator: 'gt', right: 3 }] })).toBe(false)
    })

    it('a common question becomes the condition; the value select lists the known values', async () => {
        const wrapper = shell({ expression: 'true', note: 'kept' })
        await flushPromises()
        expect(wrapper.find('[data-testid="expression-text"]').exists()).toBe(false)
        await wrapper.get('[data-question="vars.jev.verdict=pass"]').trigger('click')
        await flushPromises()
        const [, saved] = (wrapper.emitted('updateNodeConfig') as Array<[string, Record<string, unknown>]>).pop()!
        expect(saved).toEqual({ note: 'kept', conditions: [{ left: 'vars.jev.verdict', operator: 'eq', right: 'pass' }], combinator: 'and' })

        await wrapper.setProps({ selectedNode: { ...decision, data: { ...decision.data, step_config: saved } } })
        await flushPromises()
        const values = wrapper.get('[data-testid="decision-value-select"]')
        expect(values.findAll('option').map((option) => option.text())).toEqual(['Đạt', 'Không đạt', 'Chưa chắc'])
        expect(wrapper.get('[data-testid="decision-summary"]').text()).toContain('JEV second opinion › Verdict')
        expect(wrapper.get('[data-testid="decision-summary"]').text()).toContain('Đạt')
        await values.setValue('fail')
        const [, next] = (wrapper.emitted('updateNodeConfig') as Array<[string, Record<string, unknown>]>).pop()!
        expect(next.conditions).toEqual([{ left: 'vars.jev.verdict', operator: 'eq', right: 'fail' }])
        wrapper.unmount()
    })

    it('a condition typed in an older workflow is shown as it is and not rewritten', async () => {
        const wrapper = shell({ expression: 'vars.draft.pending == true and vars.jev.probability > 0.5' })
        await flushPromises()
        expect((wrapper.get('[data-testid="expression-text"]').element as HTMLTextAreaElement).value).toBe('vars.draft.pending == true and vars.jev.probability > 0.5')
        expect(wrapper.find('[data-testid="decision-row"]').exists()).toBe(false)
        expect(wrapper.emitted('updateNodeConfig')).toBeUndefined()
        await wrapper.get('[data-testid="decision-build"]').trigger('click')
        expect(wrapper.find('[data-testid="decision-row"]').exists()).toBe(true)
        // Nothing is stored until a row names its data.
        expect(wrapper.emitted('updateNodeConfig')).toBeUndefined()
        wrapper.unmount()
    })
})

describe('switch: branches are picked from the values its data may hold', () => {
    const graph = storySplitGraph()

    it('adds and removes a branch without touching the other cases', () => {
        const stored = [{ key: 'fail', match: 'fail' }, { key: 'odd', match: ['x', 'y'], note: 1 }]
        expect(caseFor(stored, 'fail')).toEqual({ key: 'fail', match: 'fail' })
        expect(caseFor(stored, 'y')).toEqual(stored[1])
        expect(toggleCase(stored, 'unsure')).toEqual([...stored, { key: 'unsure', match: 'unsure' }])
        expect(toggleCase(stored, 'fail')).toEqual([stored[1]])
    })

    it('shows the verdicts of the JEV step as choices on the sample, with "fail" already picked', async () => {
        const wrapper = mount(NodeEditorShell, {
            props: { selectedNode: graph.nodes.find((node) => node.id === 'route'), steps: graph.nodes, edges: graph.edges, workflowId: null },
            global: { stubs: { ...stubs } },
            attachTo: document.body,
        })
        await flushPromises()
        // The catalog rows of this spec give `switch` no schema: the picker is tested through the schema form below.
        wrapper.unmount()

        applyNodeCatalog({ ...catalog, nodes: catalog.nodes.map((node) => (node.type === 'switch' ? { ...node, config_schema: { type: 'object', properties: { valueExpr: expr, cases: { type: ['array', 'null'], items: { type: 'object', properties: { key: str, match: expr } } } } } } : node)) })
        const shell = mount(NodeEditorShell, {
            props: { selectedNode: graph.nodes.find((node) => node.id === 'route'), steps: graph.nodes, edges: graph.edges, workflowId: null },
            global: { stubs },
            attachTo: document.body,
        })
        await flushPromises()
        const field = shell.get('[data-field="cases"]')
        expect(field.attributes('data-kind')).toBe('switch_cases')
        expect(field.findAll('[data-case-value]').map((chip) => [chip.attributes('data-case-value'), chip.attributes('aria-pressed')])).toEqual([['pass', 'false'], ['fail', 'true'], ['unsure', 'false']])
        expect(shell.emitted('updateNodeConfig')).toBeUndefined()
        await field.get('[data-case-value="unsure"]').trigger('click')
        await flushPromises()
        const [, saved] = (shell.emitted('updateNodeConfig') as Array<[string, Record<string, unknown>]>).pop()!
        expect(saved).toEqual({ valueExpr: 'vars.jev.verdict', cases: [{ key: 'fail', match: 'fail' }, { key: 'unsure', match: 'unsure' }] })
        shell.unmount()
    })

    it('stays rows of a key and a value when the values are not known', async () => {
        applyNodeCatalog({ ...catalog, nodes: catalog.nodes.map((node) => (node.type === 'switch' ? { ...node, config_schema: { type: 'object', properties: { valueExpr: expr, cases: { type: ['array', 'null'], items: { type: 'object', properties: { key: str, match: expr } } } } } } : node)) })
        const wrapper = mountForm({ valueExpr: 'vars.category', cases: [{ key: 'a', match: 'a' }] }, 'switch')
        await flushPromises()
        expect(wrapper.get('[data-field="cases"]').attributes('data-kind')).toBe('array')
        wrapper.unmount()
    })
})

describe('a module judge with a fixed set of criteria', () => {
    it('shows the set of the sample read-only and never writes', async () => {
        applyNodeCatalog({
            ...catalog,
            nodes: catalog.nodes.map((node) =>
                node.type === 'sample_module.jev_review' ? { ...node, config_schema: { type: 'object', properties: { place_uuid: expr, version: expr, criteria_preset: str, criteria_items: list, criteria: str, storeAs: store } } } : node
            ),
        })
        const wrapper = mountForm({ place_uuid: 'vars.draft.place_uuid', version: 'vars.draft.version', criteria_preset: 'church_profile', criteria_items: ['no_unsourced_claims'], criteria: 'No claim beyond the cited sources.', storeAs: 'vars.jev' }, 'sample_module.jev_review')
        await flushPromises()
        const field = wrapper.get('[data-field="criteria"]')
        expect(field.get('[data-testid="jev-criteria-fixed-name"]').text()).toBe('Bài giới thiệu nhà thờ')
        expect(field.text()).toContain('text.workflow.pick.criteria_fixed_note')
        expect(field.find('input').exists()).toBe(false)
        expect(field.find('select').exists()).toBe(false)
        expect(wrapper.find('[data-field="criteria_preset"]').exists()).toBe(false)
        expect(wrapper.find('[data-field="criteria_items"]').exists()).toBe(false)
        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
        wrapper.unmount()
    })
})

describe('"needs a choice" on a step card', () => {
    it('reads the marks of the node schemas against the settings on screen', () => {
        expect(missingSettings(byType.jev_review, { criteria: '' })).toEqual(['criteria'])
        expect(missingSettings(byType.jev_review, { criteria: 'x' })).toEqual([])
        expect(missingSettings(byType.ai_classify, { labels: [] })).toEqual(['labels'])
        expect(missingSettings(byType.telegram_send, {})).toEqual(['credential'])
        expect(missingSettings(byType.telegram_send, { credential: '$bind:news-bot' })).toEqual([])
        // A credential only when the step signs in.
        expect(missingSettings(byType.http_request, { auth: 'none' })).toEqual([])
        expect(missingSettings(byType.http_request, { auth: 'header' })).toEqual(['credential'])
        // Who reviews: a permission or named people.
        expect(missingSettings(byType.approval, {})).toEqual(['reviewer_permission'])
        expect(missingSettings(byType.approval, { assignees: ['u-1'] })).toEqual([])
        // Who is told has a default (the in-app notice): not a blocker.
        expect(missingSettings(byType.notify, {})).toEqual([])
    })

    it('marks the steps of a diagram and clears a mark as soon as the choice is made', () => {
        const graph = storySplitGraph()
        expect(stepsNeedingSetup(graph.nodes, byType)).toEqual({})
        const review = graph.nodes.find((node) => node.id === 'review')!
        review.data.step_config = { ...review.data.step_config, reviewer_permission: '' }
        expect(stepsNeedingSetup(graph.nodes, byType)).toEqual({ review: true })
        review.data.step_config = { ...review.data.step_config, assignees: ['u-1'] }
        expect(stepsNeedingSetup(graph.nodes, byType)).toEqual({})

        // A question only the server knows: followed until its setting is filled on screen.
        const questions = [{ key: 'notify.message', step_code: 'notify', field: 'message', required: true, answered: false }, { key: 'schedule', kind: 'schedule', required: true, answered: false }]
        const notify = graph.nodes.find((node) => node.id === 'notify')!
        notify.data.step_config = { ...notify.data.step_config, message: '' }
        expect(stepsNeedingSetup(graph.nodes, byType, questions)).toEqual({ notify: true })
        notify.data.step_config = { ...notify.data.step_config, message: 'Published' }
        expect(stepsNeedingSetup(graph.nodes, byType, questions)).toEqual({})
    })
})

describe('dragging an arrowhead to another step', () => {
    const graph = storySplitGraph()
    const edge = (source: string, handle: string) => graph.edges.find((row) => row.source === source && row.sourceHandle === handle)!
    const apply = (plan: { add: any[]; removedIds: string[] }) => [...graph.edges.filter((row) => !plan.removedIds.includes(row.id)), ...plan.add]

    it('moves the link and keeps one link per output', () => {
        const plan = planRelink(edge('review', 'approved'), 'notify', graph.edges, graph.nodes, byType)!
        expect(plan.removedIds).toEqual([edge('review', 'approved').id])
        expect(plan.add).toEqual([{ id: 'edge-review-approved-notify', source: 'review', target: 'notify', sourceHandle: 'approved', targetHandle: 'input', data: {} }])
        const after = apply(plan)
        expect(after.filter((row) => row.source === 'review' && row.sourceHandle === 'approved')).toHaveLength(1)
        expect(after).toHaveLength(graph.edges.length)
    })

    it('an error link stays an error link', () => {
        const plan = planRelink(edge('research', 'error'), 'notify', graph.edges, graph.nodes, byType)!
        expect(plan.add[0]).toMatchObject({ source: 'research', target: 'notify', sourceHandle: 'error' })
        expect(apply(plan).filter((row) => row.source === 'research' && row.sourceHandle === 'error')).toHaveLength(1)
    })

    it('changes nothing when dropped on the same step, on its own step or on the start', () => {
        const link = edge('publish', 'output')
        expect(planRelink(link, 'notify', graph.edges, graph.nodes, byType)).toBeNull()
        expect(planRelink(link, 'publish', graph.edges, graph.nodes, byType)).toBeNull()
        expect(planRelink(link, 'trigger', graph.edges, graph.nodes, byType)).toBeNull()
        expect(planRelink(link, 'nowhere', graph.edges, graph.nodes, byType)).toBeNull()
    })

    it('a "carry on when it fails" link follows a moved next output', () => {
        const edges = [...graph.edges, { id: 'skip', source: 'notify', target: 'each', sourceHandle: 'error', targetHandle: 'input' }]
        const plan = planRelink(edges.find((row) => row.source === 'notify' && row.sourceHandle === 'output')!, 'select', edges, graph.nodes, byType)!
        expect(plan.add.map((row) => [row.sourceHandle, row.target])).toEqual([['output', 'select'], ['error', 'select']])
        expect(plan.removedIds).toHaveLength(2)
    })
})
