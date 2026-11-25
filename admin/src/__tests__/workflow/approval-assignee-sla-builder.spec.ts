import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

// Admin copy of the two settings (the sidecar ships it); every other key echoes back.
const authored: Record<string, string> = {
    'text.workflow.field.assignees': 'Người nhận duyệt',
    'text.workflow.field.assignees_hint': 'Tối đa 10 người.',
    'text.workflow.field.sla': 'Thời hạn duyệt (SLA)',
    'text.workflow.field.sla_hint': 'Để trống là không đo.',
}
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => authored[key] ?? (params?.names ? `${key}:${params.names}` : key), te: (key: string) => key in authored }) }))
const api = vi.hoisted(() => ({ searchReviewers: vi.fn(), resolveReviewers: vi.fn() }))
vi.mock('@/services/workflowApi', () => ({
    fetchNodeCatalog: vi.fn(),
    validateExpression: vi.fn(),
    fetchSelectableModels: vi.fn(),
    searchReviewers: api.searchReviewers,
    resolveReviewers: api.resolveReviewers,
}))
vi.mock('@/mixins', () => ({ iAlert: vi.fn() }))

import SchemaFormEditor from '@/components/workflow/node-editors/SchemaFormEditor.vue'
import AgentActionEditor from '@/components/workflow/node-editors/AgentActionEditor.vue'
import { fieldKind, formatSla, parseSla, refineFieldSchema, slaInRange } from '@/components/workflow/node-editors/schemaForm.js'
import { antdStubs } from './antd-stubs'

// A select that also takes several values, like the reviewer picker's; `pick` stands for the operator's choice.
const selectStub = defineComponent({
    name: 'ASelectStub',
    props: { value: { type: null, default: undefined }, options: { type: Array, default: () => [] }, mode: { type: String, default: '' }, disabled: Boolean },
    emits: ['update:value', 'search', 'focus'],
    setup(props, { emit }) {
        return () =>
            h(
                'div',
                { 'data-mode': props.mode, 'data-value': JSON.stringify(props.value ?? null), onFocusin: () => emit('focus') },
                (props.options as Array<{ value: string; label: string; disabled?: boolean }>).map((option) =>
                    h('span', { class: 'option', 'data-value': option.value, 'data-disabled': String(Boolean(option.disabled)) }, option.label)
                )
            )
    },
})
const stubs = { ...antdStubs, 'a-select': selectStub }

// The server schema of the approval step (cms/Core/config/workflow_nodes.php).
const approvalSchema = {
    type: 'object',
    properties: {
        title: { type: ['string', 'null'] },
        assignees: { type: ['array', 'null'], items: { type: 'string' }, maxItems: 10 },
        sla: { type: ['string', 'integer', 'null'] },
    },
}

const staff = [{ uuid: 'u-1', name: 'Nguyễn An', can_review: true }, { uuid: 'u-2', name: 'Trần Bình', can_review: true }]

const lastEmit = (wrapper: any) => {
    const events = wrapper.emitted('update:modelValue') || []
    return events[events.length - 1]?.[0] as Record<string, any>
}
const picker = (wrapper: any) => wrapper.findComponent('[data-testid="reviewer-picker-select"]')
const pick = (wrapper: any, value: unknown) => picker(wrapper).vm.$emit('update:value', value)

describe('review deadline (SLA) value', () => {
    it('is stored as a number with a unit, and empty means not measured', () => {
        expect(formatSla(30, 'm')).toBe('30m')
        expect(formatSla(4, 'h')).toBe('4h')
        expect(formatSla(2, 'd')).toBe('2d')
        expect(formatSla(null, 'h')).toBeNull()
        expect(formatSla('', 'd')).toBeNull()
        expect(formatSla(0, 'h')).toBeNull()
    })

    it('reads back a saved value, also one saved as seconds', () => {
        expect(parseSla('30m')).toEqual({ amount: 30, unit: 'm' })
        expect(parseSla('4h')).toEqual({ amount: 4, unit: 'h' })
        expect(parseSla('2d')).toEqual({ amount: 2, unit: 'd' })
        expect(parseSla(14400)).toEqual({ amount: 4, unit: 'h' })
        expect(parseSla(172800)).toEqual({ amount: 2, unit: 'd' })
        expect(parseSla(900)).toEqual({ amount: 15, unit: 'm' })
        expect(parseSla(null)).toEqual({ amount: null, unit: 'h' })
        expect(parseSla('soon')).toEqual({ amount: null, unit: 'h' })
    })

    it('knows the allowed range: 5 minutes to 30 days', () => {
        expect(slaInRange('5m')).toBe(true)
        expect(slaInRange('30d')).toBe(true)
        expect(slaInRange('4m')).toBe(false)
        expect(slaInRange('31d')).toBe(false)
        expect(slaInRange(null)).toBe(true)
    })

    it('turns the two settings into a people picker and a duration field, never raw JSON or free text', () => {
        expect(fieldKind(refineFieldSchema('assignees', approvalSchema.properties.assignees), null)).toBe('users')
        expect(fieldKind(refineFieldSchema('sla', approvalSchema.properties.sla), '4h')).toBe('duration')
    })
})

describe('approval step: reviewers and review deadline', () => {
    beforeEach(() => {
        api.searchReviewers.mockReset().mockResolvedValue(staff)
        api.resolveReviewers.mockReset().mockImplementation(async (uuids: string[]) => staff.filter((row) => uuids.includes(row.uuid)))
    })

    it('labels both settings from the admin copy', () => {
        const w = mount(SchemaFormEditor, { props: { schema: approvalSchema, modelValue: {} }, global: { stubs } })
        expect(w.get('[data-field="assignees"]').text()).toContain('Người nhận duyệt')
        expect(w.get('[data-field="assignees"] [data-testid="schema-field-hint"]').text()).toBe('Tối đa 10 người.')
        expect(w.get('[data-field="sla"]').text()).toContain('Thời hạn duyệt (SLA)')
        expect(w.get('[data-field="sla"] [data-testid="schema-field-hint"]').text()).toBe('Để trống là không đo.')
        expect(w.get('[data-field="sla"]').attributes('data-kind')).toBe('duration')
    })

    it('searches people by name and stores their uuids', async () => {
        const w = mount(SchemaFormEditor, { props: { schema: approvalSchema, modelValue: { title: 'Duyệt' } }, global: { stubs } })
        await picker(w).trigger('focusin')
        await flushPromises()
        expect(api.searchReviewers).toHaveBeenCalledWith('')
        expect(w.findAll('[data-testid="reviewer-picker-select"] .option').map((option) => option.text())).toEqual(['Nguyễn An', 'Trần Bình'])
        pick(w, ['u-2', 'u-1'])
        expect(lastEmit(w)).toEqual({ title: 'Duyệt', assignees: ['u-2', 'u-1'] })
    })

    it('shows saved reviewers by name, never by uuid', async () => {
        const w = mount(SchemaFormEditor, { props: { schema: approvalSchema, modelValue: { assignees: ['u-2', 'gone'] } }, global: { stubs } })
        await flushPromises()
        expect(api.resolveReviewers).toHaveBeenCalledTimes(1)
        expect(api.resolveReviewers).toHaveBeenCalledWith(['u-2', 'gone'])
        const labels = w.findAll('[data-testid="reviewer-picker-select"] .option').map((option) => option.text())
        expect(labels).toEqual(['Trần Bình', 'text.workflow.field.assignees_unknown'])
        expect(w.get('[data-field="assignees"]').text()).not.toMatch(/u-2|gone/)
    })

    it('warns about a saved reviewer who may no longer approve', async () => {
        api.resolveReviewers.mockResolvedValue([{ uuid: 'u-2', name: 'Trần Bình', can_review: false }, staff[0]])
        const w = mount(SchemaFormEditor, { props: { schema: approvalSchema, modelValue: { assignees: ['u-1', 'u-2'] } }, global: { stubs } })
        expect(w.find('[data-testid="reviewer-picker-cannot-review"]').exists()).toBe(false)
        await flushPromises()
        expect(w.get('[data-testid="reviewer-picker-cannot-review"]').text()).toBe('text.workflow.field.assignees_cannot_review:Trần Bình')
        expect(w.findAll('[data-testid="reviewer-picker-select"] .option').map((option) => option.text())).toEqual(['Nguyễn An', 'Trần Bình'])
    })

    it('keeps at most 10 reviewers and says so', async () => {
        const ten = Array.from({ length: 10 }, (_, index) => `p-${index}`)
        const w = mount(SchemaFormEditor, { props: { schema: approvalSchema, modelValue: { assignees: ten } }, global: { stubs } })
        await picker(w).trigger('focusin')
        await flushPromises()
        expect(w.get('[data-testid="reviewer-picker-full"]').text()).toBe('text.workflow.field.assignees_full')
        expect(w.get('[data-testid="reviewer-picker-select"] .option[data-value="u-1"]').attributes('data-disabled')).toBe('true')
        pick(w, [...ten, 'u-1'])
        expect(lastEmit(w).assignees).toEqual(ten)
    })

    it('says so when the list of people cannot be loaded', async () => {
        api.searchReviewers.mockRejectedValue(new Error('offline'))
        const w = mount(SchemaFormEditor, { props: { schema: approvalSchema, modelValue: {} }, global: { stubs } })
        await picker(w).trigger('focusin')
        await flushPromises()
        expect(w.get('[data-testid="reviewer-picker-failed"]').text()).toBe('text.workflow.field.assignees_load_failed')
    })

    it('stores the deadline as 30m / 4h / 2d and nothing when the number is emptied', async () => {
        const w = mount(SchemaFormEditor, { props: { schema: approvalSchema, modelValue: {} }, global: { stubs } })
        const amount = () => w.get('[data-testid="sla-amount"]')
        const unit = () => ({ pick: (value: string) => w.findComponent('[data-testid="sla-unit"]').vm.$emit('update:value', value) })

        await amount().setValue('4')
        expect(lastEmit(w).sla).toBe('4h')
        await w.setProps({ modelValue: lastEmit(w) })
        unit().pick('d')
        expect(lastEmit(w).sla).toBe('4d')
        await w.setProps({ modelValue: lastEmit(w) })
        unit().pick('m')
        await w.setProps({ modelValue: lastEmit(w) })
        await amount().setValue('30')
        expect(lastEmit(w).sla).toBe('30m')
        await w.setProps({ modelValue: lastEmit(w) })
        await amount().setValue('')
        expect(lastEmit(w).sla).toBeNull()
    })

    it('reads a saved deadline back and warns outside 5 minutes to 30 days', async () => {
        const w = mount(SchemaFormEditor, { props: { schema: approvalSchema, modelValue: { sla: '2d' } }, global: { stubs } })
        expect((w.get('[data-testid="sla-amount"]').element as HTMLInputElement).value).toBe('2')
        expect(w.get('[data-testid="sla-unit"]').attributes('data-value')).toBe('"d"')
        expect(w.find('[data-testid="sla-out-of-range"]').exists()).toBe(false)
        await w.setProps({ modelValue: { sla: '45d' } })
        expect(w.get('[data-testid="sla-out-of-range"]').text()).toBe('text.workflow.field.sla_out_of_range')
    })
})

describe('AI action step: reviewers and review deadline', () => {
    beforeEach(() => {
        api.searchReviewers.mockReset().mockResolvedValue(staff)
        api.resolveReviewers.mockReset().mockResolvedValue([staff[0]])
    })

    const base = { agentProfileSlug: 'content-editor', toolName: 'content.propose', toolInput: {} }

    it('stores nothing until the operator names someone or sets a deadline', async () => {
        const w = mount(AgentActionEditor, { props: { modelValue: base }, global: { stubs } })
        await w.get('[data-testid="agent-action-max-rounds"]').setValue('2')
        expect(lastEmit(w)).not.toHaveProperty('assignees')
        expect(lastEmit(w)).not.toHaveProperty('sla')
    })

    it('stores reviewer uuids and the deadline as a number with a unit', async () => {
        const w = mount(AgentActionEditor, { props: { modelValue: base }, global: { stubs } })
        const review = w.get('[data-testid="agent-action-review"]')
        expect(review.text()).toContain('Người nhận duyệt')
        expect(review.text()).toContain('Thời hạn duyệt (SLA)')
        pick(w, ['u-1'])
        expect(lastEmit(w).assignees).toEqual(['u-1'])
        await w.setProps({ modelValue: lastEmit(w) })
        await w.get('[data-testid="sla-amount"]').setValue('2')
        expect(lastEmit(w)).toMatchObject({ assignees: ['u-1'], sla: '2h' })
        await w.setProps({ modelValue: lastEmit(w) })
        await w.get('[data-testid="sla-amount"]').setValue('')
        expect(lastEmit(w)).not.toHaveProperty('sla')
        expect(lastEmit(w).assignees).toEqual(['u-1'])
    })

    it('reads saved reviewers and deadline back', async () => {
        const w = mount(AgentActionEditor, { props: { modelValue: { ...base, assignees: ['u-1'], sla: '30m' } }, global: { stubs } })
        await flushPromises()
        expect(w.get('[data-testid="reviewer-picker-select"]').attributes('data-value')).toBe('["u-1"]')
        expect(w.get('[data-testid="reviewer-picker-select"] .option').text()).toBe('Nguyễn An')
        expect((w.get('[data-testid="sla-amount"]').element as HTMLInputElement).value).toBe('30')
        expect(w.get('[data-testid="sla-unit"]').attributes('data-value')).toBe('"m"')
    })
})
