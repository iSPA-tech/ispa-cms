import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

// Admin copy the sidecar ships for the new settings; every other key has no row (the schema wording is used).
const authored: Record<string, string> = {
    'text.workflow.field.max_rounds': 'Số vòng viết lại tối đa',
    'text.workflow.field.max_rounds_hint': 'Hết số vòng này, người duyệt chỉ còn tự sửa, duyệt hoặc từ chối.',
    'text.workflow.field.revision_options': 'Lựa chọn khi yêu cầu viết lại',
    'text.workflow.field.revision_options_key': 'Mã',
    'text.workflow.field.revision_options_label_key': 'Tên hiển thị cho người duyệt',
    'text.workflow.field.revision_options_choices': 'Các giá trị để chọn',
    'text.workflow.field.choices_value': 'Giá trị',
    'text.workflow.field.choices_label_key': 'Tên hiển thị cho người duyệt',
    'text.workflow.field.revision': 'Góp ý của người duyệt để viết lại',
    'text.workflow.field.revision_hint': 'Ví dụ vars.approval để AI viết lại theo góp ý của người duyệt.',
    'text.workflow.field.model_choice': 'Model AI',
    'text.workflow.field.model_choice_hint': 'Chỉ hiện model của nhà cung cấp đã có khoá API trong AI Runtime.',
    'text.workflow.field.model_choice_default': 'Theo cài đặt AI Runtime',
    'text.workflow.field.model_choice_unavailable': 'Model này không còn dùng được.',
    'text.workflow.field.model_choice_load_failed': 'Chưa tải được danh sách model.',
    'text.workflow.field.reject_reasons': 'Lý do để chọn khi từ chối',
    'text.workflow.field.reject_reasons_key': 'Mã',
    'text.workflow.field.reject_reasons_label': 'Tên hiển thị cho người duyệt',
    'text.workflow.field.revision_model_choice': 'Cho người duyệt chọn model AI khi yêu cầu viết lại',
    'text.workflow.field.allow_revision': 'Cho người duyệt yêu cầu AI viết lại',
    'text.workflow.field.allow_revision_hint': 'Khi tắt, người duyệt chỉ còn tự sửa, duyệt hoặc từ chối.',
    'text.workflow.editor.agent_action.max_rounds_hint': 'Từ 1 đến 5. Để trống là 3 vòng.',
}

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string) => authored[key] ?? key, te: (key: string) => key in authored }),
}))
const api = vi.hoisted(() => ({ fetchSelectableModels: vi.fn() }))
vi.mock('@/services/workflowApi', () => ({ fetchNodeCatalog: vi.fn(), validateExpression: vi.fn(), fetchSelectableModels: api.fetchSelectableModels }))
vi.mock('@/mixins', () => ({ iAlert: vi.fn() }))

import SchemaFormEditor from '@/components/workflow/node-editors/SchemaFormEditor.vue'
import AgentActionEditor from '@/components/workflow/node-editors/AgentActionEditor.vue'
import { authoredFieldText } from '@/components/workflow/node-editors/schemaForm.js'
import { outputHandles } from '@/components/workflows/nodeCatalog'
import { antdStubs } from './antd-stubs'

const approvalSchema = {
    type: 'object',
    properties: {
        title: { type: ['string', 'null'] },
        max_rounds: { type: ['integer', 'string', 'null'] },
        // The server only says "a list of objects"; the designer knows the rows and shows a repeater, not raw JSON.
        revision_options: { type: ['array', 'null'], items: { type: 'object' } },
    },
}
const aiSchema = {
    type: 'object',
    properties: {
        prompt: { type: ['string', 'null'] },
        revision: { type: ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'], 'x-expr': true },
    },
}

const mountEditor = (schema: Record<string, unknown>, modelValue: Record<string, unknown> = {}) =>
    mount(SchemaFormEditor, { props: { schema, modelValue }, global: { stubs: antdStubs } })

const lastConfig = (wrapper: ReturnType<typeof mountEditor>) => {
    const events = wrapper.emitted('update:modelValue') || []
    return events[events.length - 1]?.[0] as Record<string, any>
}

describe('Workflow builder: rewrite on request', () => {
    it('gives the approval step a labelled "rewrite requested" output', () => {
        const handles = outputHandles({ outputs: ['approved', 'rejected', 'changes_requested', 'expired'] })
        expect(handles.map((handle) => [handle.id, handle.labelKey])).toEqual([
            ['approved', 'text.workflow.output.approved'],
            ['rejected', 'text.workflow.output.rejected'],
            ['changes_requested', 'text.workflow.output.changes_requested'],
            ['expired', 'text.workflow.output.expired'],
        ])
    })

    it('finds authored wording by the parent field first, and nothing when there is no row', () => {
        const t = (key: string) => authored[key] ?? key
        const te = (key: string) => key in authored
        expect(authoredFieldText('key', 'revision_options', t, te)).toBe('Mã')
        expect(authoredFieldText('max_rounds', '', t, te)).toBe('Số vòng viết lại tối đa')
        expect(authoredFieldText('max_rounds', '', t, te, '_hint')).toContain('Hết số vòng')
        expect(authoredFieldText('title', '', t, te)).toBe('')
        // A pack without `te` (older test setups) simply has no authored wording.
        expect(authoredFieldText('max_rounds', '', t, undefined)).toBe('')
    })

    it('shows the round cap and the rewrite choices of the approval step in plain words', async () => {
        const wrapper = mountEditor(approvalSchema, {
            max_rounds: 3,
            revision_options: [{ key: 'length', label_key: 'Độ dài', choices: [{ value: 'shorter', label_key: 'Ngắn hơn' }] }],
        })

        const rounds = wrapper.get('[data-field="max_rounds"]')
        expect(rounds.attributes('data-kind')).toBe('number')
        expect(rounds.text()).toContain('Số vòng viết lại tối đa')
        expect(rounds.get('[data-testid="schema-field-hint"]').text()).toContain('Hết số vòng')
        // A setting with no authored wording keeps the name read from the schema.
        expect(wrapper.get('[data-field="title"]').text()).toContain('Title')

        const options = wrapper.get('[data-field="revision_options"]')
        expect(options.attributes('data-kind')).toBe('array')
        expect(options.text()).toContain('Lựa chọn khi yêu cầu viết lại')
        const row = options.get('[data-field="0"]')
        expect(row.get('[data-field="key"]').text()).toContain('Mã')
        expect(row.get('[data-field="label_key"]').text()).toContain('Tên hiển thị cho người duyệt')
        expect(row.get('[data-field="choices"]').text()).toContain('Các giá trị để chọn')
        expect(row.get('[data-field="choices"] [data-field="value"]').text()).toContain('Giá trị')

        expect(row.attributes('data-kind')).toBe('object')
        expect(wrapper.find('textarea').exists()).toBe(false)

        await row.get('[data-field="choices"] [data-field="value"] input').setValue('longer')
        expect(lastConfig(wrapper).revision_options).toEqual([
            { key: 'length', label_key: 'Độ dài', choices: [{ value: 'longer', label_key: 'Ngắn hơn' }] },
        ])
        await rounds.get('input').setValue('2')
        expect(lastConfig(wrapper).max_rounds).toBe(2)
    })

    it('shows the reviewer feedback setting of an AI step with its hint', async () => {
        const wrapper = mountEditor(aiSchema, { prompt: 'Write', revision: '' })
        const field = wrapper.get('[data-field="revision"]')

        // The feedback is picked from the data of the review step, never typed.
        expect(field.attributes('data-kind')).toBe('path')
        expect(field.text()).toContain('Góp ý của người duyệt để viết lại')
        expect(field.get('[data-testid="schema-field-hint"]').text()).toContain('người duyệt')
        expect(field.find('textarea').exists()).toBe(false)

        await field.get('[data-testid="expression-pick"]').trigger('click')
        wrapper.getComponent({ name: 'DataPicker' }).vm.$emit('pick', 'vars.approval')
        expect(lastConfig(wrapper).revision).toBe('vars.approval')
    })
})

// GET ai-runtime/selectable-models: only providers that have an API key.
const selectable = [
    { value: 'anthropic:claude-sonnet-4-5', provider: 'anthropic', provider_label: 'Anthropic', model: 'claude-sonnet-4-5', price_known: true },
    { value: 'openai:gpt-5', provider: 'openai', provider_label: 'OpenAI', model: 'gpt-5', price_known: true },
]

describe('Workflow builder: model choice, reject reasons, rewrite settings', () => {
    beforeEach(() => {
        api.fetchSelectableModels.mockReset()
        api.fetchSelectableModels.mockResolvedValue(selectable)
    })

    it('lets an AI step pick a model from the selectable list, after "follow the AI Runtime setting"', async () => {
        const schema = { type: 'object', properties: { prompt: { type: ['string', 'null'] }, model_choice: { type: ['string', 'null'] } } }
        const wrapper = mountEditor(schema, { prompt: 'Write', model_choice: '' })
        await flushPromises()

        const field = wrapper.get('[data-field="model_choice"]')
        expect(field.attributes('data-kind')).toBe('model')
        expect(field.text()).toContain('Model AI')
        expect(field.get('[data-testid="schema-field-hint"]').text()).toContain('khoá API')
        expect(api.fetchSelectableModels).toHaveBeenCalledTimes(1)
        const options = field.findAll('option')
        expect(options.map((option) => option.text())).toEqual(['Theo cài đặt AI Runtime', 'Anthropic · claude-sonnet-4-5', 'OpenAI · gpt-5'])
        // The stored value never shows to the author.
        expect(field.text()).not.toContain('openai:gpt-5')
        expect(field.find('input').exists()).toBe(false)

        await field.get('select').setValue('openai:gpt-5')
        expect(lastConfig(wrapper).model_choice).toBe('openai:gpt-5')
        await field.get('select').setValue('')
        expect(lastConfig(wrapper).model_choice).toBe('')
    })

    it('keeps a saved model readable and warns when it is no longer selectable or the list did not load', async () => {
        const schema = { type: 'object', properties: { model_choice: { type: ['string', 'null'] } } }
        const stale = mountEditor(schema, { model_choice: 'deepseek:deepseek-chat' })
        await flushPromises()
        expect(stale.findAll('option').map((option) => option.text())).toContain('Deepseek · deepseek-chat')
        expect(stale.get('[data-testid="schema-field-model-missing"]').text()).toContain('không còn dùng được')

        api.fetchSelectableModels.mockRejectedValue(new Error('offline'))
        const failed = mountEditor(schema, { model_choice: 'openai:gpt-5' })
        await flushPromises()
        expect(failed.get('[data-testid="schema-field-model-failed"]').text()).toContain('Chưa tải được')
        expect(failed.findAll('option').map((option) => option.text())).toEqual(['Theo cài đặt AI Runtime', 'Openai · gpt-5'])
        expect(failed.find('[data-testid="schema-field-model-missing"]').exists()).toBe(false)
    })

    it('edits the reject reasons of an approval step as rows and the model switch as a switch', async () => {
        const schema = {
            type: 'object',
            properties: {
                // Lenient server schema: the designer knows the rows.
                reject_reasons: { type: ['array', 'null'], items: { type: 'object' } },
                revision_model_choice: { type: ['boolean', 'string', 'null'] },
            },
        }
        const wrapper = mountEditor(schema, { reject_reasons: [{ key: 'wrong_info', label: 'Sai thông tin' }] })

        const reasons = wrapper.get('[data-field="reject_reasons"]')
        expect(reasons.attributes('data-kind')).toBe('array')
        expect(reasons.text()).toContain('Lý do để chọn khi từ chối')
        const row = reasons.get('[data-field="0"]')
        expect(row.get('[data-field="key"]').text()).toContain('Mã')
        expect(row.get('[data-field="label"]').text()).toContain('Tên hiển thị cho người duyệt')
        expect(wrapper.find('textarea').exists()).toBe(false)

        await row.get('[data-field="label"] input').setValue('Thông tin chưa đúng')
        expect(lastConfig(wrapper).reject_reasons).toEqual([{ key: 'wrong_info', label: 'Thông tin chưa đúng' }])
        await reasons.findAll('button').at(-1)!.trigger('click')
        expect(lastConfig(wrapper).reject_reasons).toEqual([{ key: 'wrong_info', label: 'Thông tin chưa đúng' }, { key: '', label: '' }])

        const toggle = wrapper.get('[data-field="revision_model_choice"]')
        expect(toggle.attributes('data-kind')).toBe('boolean')
        expect(toggle.text()).toContain('Cho người duyệt chọn model AI khi yêu cầu viết lại')
        await toggle.get('input[type="checkbox"]').setValue(true)
        expect(lastConfig(wrapper).revision_model_choice).toBe(true)
    })

    it('stops offering a new reject reason at ten rows', () => {
        const schema = { type: 'object', properties: { reject_reasons: { type: ['array', 'null'], items: { type: 'object' } } } }
        const rows = Array.from({ length: 10 }, (_, index) => ({ key: `r${index}`, label: `Lý do ${index}` }))
        const wrapper = mountEditor(schema, { reject_reasons: rows })
        expect(wrapper.text()).not.toContain('text.workflow.schema_form.add_row')
        expect(mountEditor(schema, { reject_reasons: rows.slice(0, 9) }).text()).toContain('text.workflow.schema_form.add_row')
    })

    it('exposes the rewrite settings in the AI action editor', async () => {
        const wrapper = mount(AgentActionEditor, {
            props: { modelValue: { agentProfileSlug: 'story-writer', toolName: 'story.propose', toolInput: { place: 1 } } },
            global: { stubs: antdStubs },
        })
        const last = () => {
            const events = wrapper.emitted('update:modelValue') || []
            return events[events.length - 1]?.[0] as Record<string, any>
        }
        const section = wrapper.get('[data-testid="agent-action-rewrite"]')
        expect(section.text()).toContain('Cho người duyệt yêu cầu AI viết lại')
        expect(section.text()).toContain('Để trống là 3 vòng')
        // On by default, with an empty round cap (3 rounds).
        const allow = section.get('[data-testid="agent-action-allow-revision"]')
        expect((allow.element as HTMLInputElement).checked).toBe(true)
        expect((section.get('[data-testid="agent-action-max-rounds"]').element as HTMLInputElement).value).toBe('')

        await section.get('[data-testid="agent-action-max-rounds"]').setValue('2')
        expect(last()).toEqual({ agentProfileSlug: 'story-writer', toolName: 'story.propose', storeAs: 'vars.agent_result', max_rounds: 2, toolInput: { place: 1 } })
        // Out of range is brought back to 1..5.
        await section.get('[data-testid="agent-action-max-rounds"]').setValue('9')
        expect(last().max_rounds).toBe(5)

        await allow.setValue(false)
        expect(last().allow_revision).toBe(false)
        expect(last().max_rounds).toBeUndefined()
        expect(wrapper.find('[data-testid="agent-action-max-rounds"]').exists()).toBe(false)

        // A saved step shows what it stores.
        await wrapper.setProps({ modelValue: { toolName: 'story.propose', allow_revision: false, toolInput: {} } })
        expect((wrapper.get('[data-testid="agent-action-allow-revision"]').element as HTMLInputElement).checked).toBe(false)
        await wrapper.setProps({ modelValue: { toolName: 'story.propose', max_rounds: 4, toolInput: {} } })
        expect((wrapper.get('[data-testid="agent-action-max-rounds"]').element as HTMLInputElement).value).toBe('4')
    })

    it('labels the "rejected" output of an AI action next to its normal output', () => {
        const handles = outputHandles({ outputs: ['main', 'rejected'] })
        expect(handles.map((handle) => [handle.id, handle.labelKey])).toEqual([
            ['output', 'text.workflow.output.main'],
            ['rejected', 'text.workflow.output.rejected'],
        ])
    })
})
