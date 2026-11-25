import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import WorkflowRunPanel from '../../../../cms/Core/admin/pages/setting/workflows-manage/components/workflow-run-panel.vue'
import WorkflowRunStepsDrawer from '../../../../cms/Core/admin/pages/setting/workflows-manage/components/workflow-run-steps-drawer.vue'

const api = vi.hoisted(() => ({
    getWorkflowRuns: vi.fn(),
    runWorkflowProject: vi.fn(),
    saveWorkflowSchedule: vi.fn(),
    getWorkflowRunSteps: vi.fn(),
    getPendingApprovalsByRun: vi.fn(),
}))
const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }))

vi.mock('../../../../cms/Core/admin/pages/setting/workflows-manage/workflow-run-api', () => ({
    ...api,
    workflowErrorCode: (error: any) => (error?.response?.status === 403 ? 'forbidden' : error?.response?.data?.error_code || ''),
}))
vi.mock('ant-design-vue', () => ({ message: toast }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params: Record<string, unknown> = {}) =>
            key.replace(/^text\.workflow\./, '') + Object.values(params).map((value) => `:${value}`).join(''),
        te: (key: string) => !key.endsWith('.made_up'),
    }),
}))

const stubs = {
    'a-card': { template: '<section><slot /></section>' },
    'a-button': { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading"><slot /></button>' },
    'a-tag': { props: ['color'], template: '<span data-testid="tag" :data-color="color"><slot /></span>' },
    'a-alert': { props: ['message', 'description'], template: '<div role="alert">{{ message }} {{ description }}</div>' },
    'a-input': { props: ['value'], emits: ['update:value'], template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
    'a-time-picker': { props: ['value'], emits: ['update:value'], template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
    'a-select': {
        props: ['value', 'options'],
        emits: ['update:value'],
        template:
            '<select :value="String(value)" @change="$emit(\'update:value\', options.find((o) => String(o.value) === $event.target.value).value)">' +
            '<option v-for="o in options" :key="o.value" :value="String(o.value)">{{ o.label }}</option></select>',
    },
    'a-table': {
        props: ['columns', 'dataSource', 'locale'],
        template:
            '<div><p v-if="!dataSource.length" data-testid="runs-empty">{{ locale.emptyText }}</p>' +
            '<div v-for="record in dataSource" :key="record.id" data-testid="run-row">' +
            '<span v-for="column in columns" :key="column.key" :data-col="column.key"><slot name="bodyCell" :column="column" :record="record" /></span>' +
            '</div></div>',
    },
    'a-drawer': { props: ['open', 'title'], template: '<aside v-if="open" :data-title="title"><slot /></aside>' },
    'a-spin': true,
    'a-empty': { props: ['description'], template: '<p data-testid="steps-empty">{{ description }}</p>' },
}

const schedule = (over: Record<string, unknown> = {}) => ({
    cron: null, timezone: null, scheduled_by: null, last_scheduled_run_at: null, is_active: true, ...over,
})
const run = (over: Record<string, unknown> = {}) => ({
    id: 41, project_id: 7, status: 'completed', source: 'manual',
    started_at: '2026-11-23T02:00:00Z', finished_at: '2026-11-23T02:01:05Z', duration_ms: 65000, created_at: '2026-11-23T02:00:00Z', ...over,
})
const page = (runs: unknown[] = [], sched = schedule()) => ({ runs, meta: { page: 1, per_page: 10, total: runs.length, last_page: 1 }, schedule: sched })

const workflow = (over: Record<string, unknown> = {}) => ({ id: 7, name: 'Weekly story', system_managed: false, nodes: [], ...over })

const mountPanel = async (props: Record<string, unknown> = {}) => {
    if (!api.getPendingApprovalsByRun.getMockImplementation()) api.getPendingApprovalsByRun.mockResolvedValue({})
    const wrapper = mount(WorkflowRunPanel, {
        props: { workflow: workflow(), isActive: true, canUpdate: true, canRun: true, ...props },
        global: { stubs: { ...stubs, 'router-link': { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
    })
    await flushPromises()
    return wrapper
}

const choose = async (wrapper: any, testid: string, value: string) => {
    await wrapper.get(`[data-testid="${testid}"]`).setValue(value)
}

describe('Workflow run panel', () => {
    beforeEach(() => {
        Object.values(api).forEach((mock) => mock.mockReset())
        Object.values(toast).forEach((mock) => mock.mockReset())
        api.getWorkflowRuns.mockResolvedValue(page())
    })

    it('starts a run and reloads the list', async () => {
        api.runWorkflowProject.mockResolvedValue(run({ status: 'pending' }))
        const wrapper = await mountPanel()
        expect(wrapper.find('[data-testid="run-blocked-reason"]').exists()).toBe(false)

        await wrapper.get('[data-testid="run-now"]').trigger('click')
        await flushPromises()

        expect(api.runWorkflowProject).toHaveBeenCalledWith(7)
        expect(toast.success).toHaveBeenCalledWith('runs.started')
        expect(api.getWorkflowRuns).toHaveBeenCalledTimes(2)
    })

    it('disables Run now with a reason while the workflow is off', async () => {
        const wrapper = await mountPanel({ isActive: false })
        const button = wrapper.get('[data-testid="run-now"]')
        expect(button.attributes('disabled')).toBeDefined()
        expect(wrapper.get('[data-testid="run-blocked-reason"]').text()).toBe('runs.blocked_inactive')
        await button.trigger('click')
        expect(api.runWorkflowProject).not.toHaveBeenCalled()
    })

    it('explains a system-managed workflow and hides its schedule', async () => {
        const wrapper = await mountPanel({ workflow: workflow({ system_managed: true }) })
        expect(wrapper.get('[data-testid="run-blocked-reason"]').text()).toBe('runs.blocked_system')
        expect(wrapper.find('[data-testid="schedule-control"]').exists()).toBe(false)
    })

    it('says in plain words that a run is already going', async () => {
        api.runWorkflowProject.mockRejectedValue({ response: { status: 409, data: { error_code: 'workflow_already_running', message: 'raw server text' } } })
        const wrapper = await mountPanel()
        await wrapper.get('[data-testid="run-now"]').trigger('click')
        await flushPromises()
        expect(toast.error).toHaveBeenCalledWith('runs.error.already_running')
    })

    it('hides the run and save buttons from a person who may only look', async () => {
        const wrapper = await mountPanel({ canUpdate: false, canRun: false })
        expect(wrapper.find('[data-testid="run-now"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="schedule-save"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="runs-table"]').exists()).toBe(true)
    })

    it('run-by-hand follows its own right: an editor without it gets no run button, a runner without edit gets one', async () => {
        const editor = await mountPanel({ canUpdate: true, canRun: false })
        expect(editor.find('[data-testid="run-now"]').exists()).toBe(false)
        expect(editor.find('[data-testid="schedule-save"]').exists()).toBe(true)

        const runner = await mountPanel({ canUpdate: false, canRun: true })
        expect(runner.find('[data-testid="run-now"]').exists()).toBe(true)
        expect(runner.find('[data-testid="schedule-save"]').exists()).toBe(false)
    })

    it('says why a run cannot start: never published, all workflows paused, paused by a safety limit', async () => {
        const fresh = await mountPanel({ neverPublished: true })
        expect(fresh.get('[data-testid="run-now"]').attributes('disabled')).toBeDefined()
        expect(fresh.get('[data-testid="run-blocked-reason"]').text()).toBe('guard.run.blocked_unpublished')

        const all = await mountPanel({ pausedAll: true })
        expect(all.get('[data-testid="run-blocked-reason"]').text()).toBe('guard.run.blocked_paused_all')

        const paused = await mountPanel({ isActive: false, workflow: workflow({ paused_reason: 'breaker:cost_per_hour' }) })
        expect(paused.get('[data-testid="run-blocked-reason"]').text()).toBe('guard.run.blocked_paused')
    })

    it('saves a daily schedule as a cron with the chosen time zone', async () => {
        api.saveWorkflowSchedule.mockResolvedValue(schedule({ cron: '30 6 * * *', timezone: null, scheduled_by: { uuid: 'u', name: 'Lan' } }))
        const wrapper = await mountPanel()
        expect(wrapper.get('[data-testid="schedule-save"]').attributes('disabled')).toBeDefined()

        await choose(wrapper, 'schedule-mode', 'daily')
        await choose(wrapper, 'schedule-time', '06:30')
        await wrapper.get('[data-testid="schedule-save"]').trigger('click')
        await flushPromises()

        expect(api.saveWorkflowSchedule).toHaveBeenCalledWith(7, { cron: '30 6 * * *', timezone: '' })
        expect(toast.success).toHaveBeenCalledWith('schedule.saved')
        expect(wrapper.get('[data-testid="schedule-note"]').text()).toBe('schedule.set_by:Lan')
        expect(wrapper.get('[data-testid="schedule-save"]').attributes('disabled')).toBeDefined()
    })

    it('saves a weekly schedule and turns a schedule off', async () => {
        api.getWorkflowRuns.mockResolvedValue(page([], schedule({ cron: '0 * * * *', last_scheduled_run_at: '2026-11-23T01:00:00Z' })))
        api.saveWorkflowSchedule.mockImplementation(async (_id: number, body: any) => schedule({ cron: body.cron }))
        const wrapper = await mountPanel()
        expect(wrapper.get('[data-testid="schedule-mode"]').element.value).toBe('hourly')
        expect(wrapper.get('[data-testid="schedule-last-run"]').text()).toContain('schedule.last_run:')

        await choose(wrapper, 'schedule-mode', 'weekly')
        await choose(wrapper, 'schedule-day', '5')
        await choose(wrapper, 'schedule-time', '17:00')
        await wrapper.get('[data-testid="schedule-save"]').trigger('click')
        await flushPromises()
        expect(api.saveWorkflowSchedule).toHaveBeenLastCalledWith(7, { cron: '0 17 * * 5', timezone: '' })

        await choose(wrapper, 'schedule-mode', 'off')
        await wrapper.get('[data-testid="schedule-save"]').trigger('click')
        await flushPromises()
        expect(api.saveWorkflowSchedule).toHaveBeenLastCalledWith(7, { cron: null, timezone: null })
        expect(toast.success).toHaveBeenLastCalledWith('schedule.turned_off')
    })

    it('shows a stored custom cron and a plain message when the server refuses it', async () => {
        api.getWorkflowRuns.mockResolvedValue(page([], schedule({ cron: '*/15 * * * *', timezone: 'Asia/Ho_Chi_Minh' })))
        api.saveWorkflowSchedule.mockRejectedValue({ response: { status: 422, data: { error_code: 'validation_failed', message: 'The cron expression is not valid.' } } })
        const wrapper = await mountPanel()
        expect(wrapper.get('[data-testid="schedule-mode"]').element.value).toBe('custom')
        expect(wrapper.get('[data-testid="schedule-custom"]').element.value).toBe('*/15 * * * *')
        expect(wrapper.get('[data-testid="schedule-timezone"]').element.value).toBe('Asia/Ho_Chi_Minh')

        await choose(wrapper, 'schedule-custom', 'not a cron')
        await wrapper.get('[data-testid="schedule-save"]').trigger('click')
        await flushPromises()
        expect(wrapper.get('[data-testid="schedule-error"]').text()).toContain('schedule.error.invalid')
        expect(wrapper.text()).not.toContain('The cron expression is not valid.')
    })

    it('asks for a cron before saving an empty custom schedule', async () => {
        const wrapper = await mountPanel()
        await choose(wrapper, 'schedule-mode', 'custom')
        await wrapper.get('[data-testid="schedule-save"]').trigger('click')
        expect(api.saveWorkflowSchedule).not.toHaveBeenCalled()
        expect(wrapper.get('[data-testid="schedule-error"]').text()).toContain('schedule.error.custom_required')
    })

    it('lists recent runs with a status badge, who started it, start time and duration', async () => {
        api.getWorkflowRuns.mockResolvedValue(page([run(), run({ id: 42, status: 'failed', source: 'schedule', duration_ms: 4000 }), run({ id: 43, status: 'weird', source: null, duration_ms: null })]))
        const wrapper = await mountPanel()
        const rows = wrapper.findAll('[data-testid="run-row"]')
        expect(rows).toHaveLength(3)
        expect(rows[0].get('[data-testid="tag"]').attributes('data-color')).toBe('green')
        expect(rows[0].get('[data-col="status"]').text()).toBe('runs.status.completed')
        expect(rows[0].get('[data-col="source"]').text()).toBe('runs.source.manual')
        expect(rows[0].get('[data-col="started"]').text()).toMatch(/^23\/11\/2026 \d{2}:00$/)
        expect(rows[0].get('[data-col="duration"]').text()).toBe('runs.duration_minutes:1:5')
        expect(rows[1].get('[data-testid="tag"]').attributes('data-color')).toBe('red')
        expect(rows[1].get('[data-col="source"]').text()).toBe('runs.source.schedule')
        expect(rows[1].get('[data-col="duration"]').text()).toBe('runs.duration_seconds:4')
        // Unknown codes never reach the operator as raw text.
        expect(rows[2].get('[data-col="status"]').text()).toBe('runs.status.other')
        expect(rows[2].get('[data-col="source"]').text()).toBe('runs.source.other')
        expect(rows[2].get('[data-col="duration"]').text()).toBe('—')
        // The run id is not shown.
        expect(rows[0].text()).not.toContain('41')
    })

    it('shows the empty text and a load error in plain words', async () => {
        const wrapper = await mountPanel()
        expect(wrapper.get('[data-testid="runs-empty"]').text()).toBe('runs.empty')

        api.getWorkflowRuns.mockRejectedValue({ response: { status: 403, data: {} } })
        const denied = await mountPanel()
        expect(denied.get('[data-testid="runs-error"]').text()).toContain('runs.error.forbidden')
    })

    it('opens the step log of a run with step names instead of ids', async () => {
        api.getWorkflowRuns.mockResolvedValue(page([run()]))
        api.getWorkflowRunSteps.mockResolvedValue([
            { step_id: 901, status: 'completed', started_at: '2026-11-23T02:00:00Z', duration_ms: 1000, error_message: null },
            { step_id: 902, status: 'failed', started_at: '2026-11-23T02:00:01Z', duration_ms: 2000, error_message: 'Class not allowed' },
        ])
        const wrapper = await mountPanel({ workflow: workflow({ nodes: [{ id: 'n1', data: { id: 901, label: 'Find the saint of the week' } }] }) })
        await wrapper.get('[data-col="steps"] button').trigger('click')
        await flushPromises()

        expect(api.getWorkflowRunSteps).toHaveBeenCalledWith(41)
        const drawer = wrapper.getComponent(WorkflowRunStepsDrawer)
        const items = drawer.findAll('[data-testid="steps-list"] li')
        expect(items).toHaveLength(2)
        expect(items[0].text()).toContain('Find the saint of the week')
        expect(items[1].text()).toContain('runs.step_number:2')
        expect(items[1].text()).toContain('Class not allowed')
        expect(drawer.text()).not.toContain('901')
        expect(drawer.text()).not.toContain('902')
    })

    it('says in words why a step sent nothing (an e-mail step that skipped on purpose)', async () => {
        api.getWorkflowRuns.mockResolvedValue(page([run()]))
        api.getWorkflowRunSteps.mockResolvedValue([
            { step_id: 901, status: 'completed', started_at: '2026-11-23T02:00:00Z', duration_ms: 10, error_message: null, result_data: { sent: false, skipped: true, skip_reason: 'business_mail_off', mail_class: 'business' } },
            { step_id: 902, status: 'completed', started_at: '2026-11-23T02:00:01Z', duration_ms: 10, error_message: null, result_data: { sent: false, skipped: true, skip_reason: 'made_up' } },
            { step_id: 903, status: 'completed', started_at: '2026-11-23T02:00:02Z', duration_ms: 10, error_message: null, result_data: { sent: true, skipped: false, recipients: 1 } },
        ])
        const wrapper = await mountPanel()
        await wrapper.get('[data-col="steps"] button').trigger('click')
        await flushPromises()

        const items = wrapper.getComponent(WorkflowRunStepsDrawer).findAll('[data-testid="steps-list"] li')
        expect(items[0].get('[data-testid="step-skipped"]').text()).toContain('runs.step_skipped:value.business_mail_off')
        // A reason the screen has no wording for is never shown as its raw code.
        expect(items[1].get('[data-testid="step-skipped"]').text()).toContain('runs.step_skipped:runs.skip_unknown')
        expect(items[1].text()).not.toContain('made_up')
        expect(items[2].find('[data-testid="step-skipped"]').exists()).toBe(false)
    })

    it('links a run that waits for a person to its approval, and asks nothing when every run has finished', async () => {
        api.getWorkflowRuns.mockResolvedValue(page([run({ id: 41, status: 'paused' }), run({ id: 40 })]))
        api.getPendingApprovalsByRun.mockResolvedValue({ 41: 9201 })
        const wrapper = await mountPanel()
        const links = wrapper.findAll('[data-testid="run-open-approval"]')
        expect(links).toHaveLength(1)
        expect(JSON.parse(links[0].attributes('data-to') as string)).toEqual({ name: 'approval-detail', params: { id: '9201' } })
        expect(links[0].text()).toBe('runs.open_approval')

        api.getPendingApprovalsByRun.mockClear()
        api.getWorkflowRuns.mockResolvedValue(page([run({ id: 40 })]))
        const finished = await mountPanel()
        expect(api.getPendingApprovalsByRun).not.toHaveBeenCalled()
        expect(finished.find('[data-testid="run-open-approval"]').exists()).toBe(false)
    })

    it('tells the person when a run has no steps yet', async () => {
        api.getWorkflowRuns.mockResolvedValue(page([run({ status: 'pending' })]))
        api.getWorkflowRunSteps.mockResolvedValue([])
        vi.useFakeTimers()
        const wrapper = await mountPanel()
        await wrapper.get('[data-col="steps"] button').trigger('click')
        await flushPromises()
        expect(wrapper.get('[data-testid="steps-empty"]').text()).toBe('runs.steps_empty')
        wrapper.unmount()
        vi.useRealTimers()
    })
})
