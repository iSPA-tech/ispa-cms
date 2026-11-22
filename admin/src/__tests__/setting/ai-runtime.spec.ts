import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ajax from '@/ajax'
import { captureKeys, restoreKeys } from '../../../../cms/Core/admin/pages/setting/ai-runtime/useDrawerGuard'
import SettingAiRuntime from '../../../../cms/Core/admin/pages/setting/ai-runtime.vue'

// Renders a key with its params so assertions see both: "text.ai_runtime.x(a=1, b=2)".
const translate = vi.hoisted(
    () => (key: string, params?: Record<string, unknown>) =>
        params ? `${key}(${Object.entries(params).map(([name, value]) => `${name}=${value}`).join(', ')})` : key
)
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: translate }) }))
const k = (name: string, params?: Record<string, unknown>) => translate(`text.ai_runtime.${name}`, params)

const confirmMock = vi.hoisted(() => vi.fn())
const messageMock = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('ant-design-vue', async (importOriginal) => ({
    ...(await importOriginal<typeof import('ant-design-vue')>()),
    Modal: { confirm: confirmMock },
    message: messageMock,
}))

vi.mock('@/ajax', () => ({
    default: { get: vi.fn(), put: vi.fn(), post: vi.fn() },
}))

const TASK_A = 'vehicle_comparison_editorial_coach_draft'
const LABEL_A = 'So sánh xe – nháp'
const month = String(new Date().getUTCMonth() + 1)
const currentMonthKey = () => {
    const d = new Date()
    return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`
}

const overviewFixture = (over: Record<string, unknown> = {}) => ({
    budget: { monthly_usd: 50, kill_switch: false, warning_percent: 80 },
    drivers: [
        { key: 'openai', label: 'OpenAI' },
        { key: 'anthropic', label: 'Anthropic (Claude)' },
        { key: 'deepseek', label: 'DeepSeek' },
    ],
    default_provider: 'deepseek',
    configured_default_provider: '',
    env_default_provider: 'deepseek',
    global_default_model: 'deepseek-v4-pro',
    pricing: {
        'deepseek-v4-pro': { input_per_1m_usd: 0.5, cached_input_per_1m_usd: 0.1, output_per_1m_usd: 2 },
        'gpt-5-mini': { input_per_1m_usd: 0.25, cached_input_per_1m_usd: 0.03, output_per_1m_usd: 2 },
        'text-embedding-3-small': { input_per_1m_usd: 0.02, cached_input_per_1m_usd: 0, output_per_1m_usd: 0 },
        'claude-haiku-4': { input_per_1m_usd: 1, cached_input_per_1m_usd: 0.1, output_per_1m_usd: 5 },
    },
    providers: [
        { key: 'openai', label: 'OpenAI', docs_url: 'https://platform.openai.com/docs/models', model: '', base_uri: '', has_api_key: false, env_model: '', env_has_api_key: true },
        { key: 'anthropic', label: 'Anthropic (Claude)', model: 'claude-sonnet-5', base_uri: '', has_api_key: true, env_model: '', env_has_api_key: false },
        { key: 'deepseek', label: 'DeepSeek', model: '', base_uri: '', has_api_key: false, env_model: 'deepseek-v4-pro', env_has_api_key: false },
    ],
    tasks: [
        { task: TASK_A, label: LABEL_A, module: 'Acme', configurable: true, runs: 12, last_run_at: null, provider: '', model: '', temperature: null, reasoning_effort: '', fallback_models: 'gpt-4o-mini', effective: { provider: 'deepseek', model: 'deepseek-v4-pro', source: 'observed' } },
        { task: 'direct_task', label: 'Gọi trực tiếp', module: 'Core', configurable: false, runs: 1, provider: '', model: '', temperature: null, fallback_models: '', effective: { provider: '', model: '', source: 'unknown' } },
        { task: 'openai_task', label: 'Tác vụ OpenAI', module: 'Core', configurable: true, runs: 0, provider: 'openai', model: 'gpt-5', temperature: null, reasoning_effort: '', fallback_models: '', effective: { provider: 'openai', model: 'gpt-5', source: 'task' } },
    ],
    ...over,
})

const usageFixture = (over: Record<string, unknown> = {}) => ({
    month: currentMonthKey(),
    monthly_budget_usd: 50,
    estimated_spent_usd: 12.5,
    remaining_usd: 37.5,
    kill_switch: false,
    request_count: 7,
    failed_count: 2,
    fallback_count: 1,
    refused_count: 3,
    input_tokens: 700,
    cached_input_tokens: 100,
    output_tokens: 300,
    by_task: [
        { task_key: 'cheap_task', request_count: 1, failed_count: 0, fallback_count: 0, refused_count: 0, input_tokens: 1, cached_input_tokens: 0, output_tokens: 1, latency_ms_total: 100, estimated_cost_usd: 0.1 },
        { task_key: TASK_A, request_count: 4, failed_count: 1, fallback_count: 1, refused_count: 0, input_tokens: 500, cached_input_tokens: 0, output_tokens: 200, latency_ms_total: 43600, estimated_cost_usd: 9 },
    ],
    by_model: [
        { provider: 'deepseek', model: 'deepseek-x', priced: false, request_count: 2, failed_count: 0, fallback_count: 0, refused_count: 0, input_tokens: 10, cached_input_tokens: 0, output_tokens: 10, latency_ms_total: 10, estimated_cost_usd: 0 },
    ],
    ...over,
})

const mockGet = (overview = overviewFixture(), usage = usageFixture()) =>
    vi.mocked(ajax.get).mockImplementation((url: string) =>
        Promise.resolve({ data: { data: url === 'ai-runtime/usage' ? usage : overview } })
    )

const stubs = {
    'a-tabs': { template: '<div><slot /></div>' },
    'a-tab-pane': { template: '<div><slot /></div>' },
    'a-switch': { props: ['checked'], emits: ['update:checked'], inheritAttrs: false, template: '<input v-bind="$attrs" type="checkbox" :checked="checked" @change="$emit(\'update:checked\', $event.target.checked)" />' },
    'a-tag': { template: '<span><slot /></span>' },
    'a-tooltip': { props: ['title'], template: '<span :title="title"><slot /></span>' },
    'a-input': { props: ['value'], emits: ['update:value'], template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
    'a-auto-complete': {
        inheritAttrs: false,
        props: ['value', 'options'],
        emits: ['update:value'],
        template:
            '<div><input v-bind="$attrs" :value="value" @input="$emit(\'update:value\', $event.target.value)" />' +
            '<ul><li v-for="o in options" :key="o.value" class="model-option">{{ o.value }}</li></ul></div>',
    },
    'a-input-password': { props: ['value'], emits: ['update:value'], template: '<input type="password" :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
    'a-input-number': { props: ['value'], emits: ['update:value'], template: '<input type="number" :value="value" @input="$emit(\'update:value\', Number($event.target.value))" />' },
    'a-alert': { props: ['message'], template: '<div role="alert">{{ message }}</div>' },
    'a-select': {
        props: ['value', 'options', 'mode'],
        emits: ['update:value', 'change'],
        template:
            '<input v-if="mode === \'tags\'" data-tags :value="(value || []).join(\',\')" @input="$emit(\'update:value\', $event.target.value.split(\',\'))" />' +
            '<select v-else :value="value" @change="$emit(\'update:value\', $event.target.value); $emit(\'change\', $event.target.value)">' +
            '<option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>' +
            '</select>',
    },
    'i-button': { props: ['disabled', 'loading'], emits: ['click'], template: '<button :disabled="disabled" type="button" @click="$emit(\'click\')"><slot /></button>' },
    'a-button': { props: ['disabled', 'loading', 'htmlType'], emits: ['click'], template: '<button :disabled="disabled" :type="htmlType || \'button\'" @click="$emit(\'click\')"><slot /></button>' },
    'a-drawer': {
        props: ['open', 'title'],
        emits: ['close'],
        template:
            '<div v-if="open" role="dialog"><h3>{{ title }}</h3><button data-testid="drawer-x" type="button" @click="$emit(\'close\')">x</button><slot /><slot name="footer" /></div>',
    },
    'a-table': {
        props: ['columns', 'dataSource'],
        template:
            '<table :data-columns="columns.map((c) => c.key + (c.fixed ? \':\' + c.fixed : \'\')).join()"><tbody><tr v-for="(record, i) in dataSource" :key="i"><td v-for="column in columns" :key="column.key">' +
            '<slot name="bodyCell" :column="column" :record="record" /></td></tr></tbody></table>',
    },
}

const mountPage = async (overview = overviewFixture(), usage = usageFixture()) => {
    mockGet(overview, usage)
    const wrapper = mount(SettingAiRuntime, {
        global: { stubs, mocks: { $isMobile: () => false, $t: translate } },
    })
    await flushPromises()
    return wrapper
}

const button = (wrapper: ReturnType<typeof mount>, text: string, exact = false) =>
    wrapper.findAll('button').find((b) => (exact ? b.text() === text : b.text().includes(text)))!
const rowWith = (wrapper: ReturnType<typeof mount>, text: string) =>
    wrapper.findAll('tr').find((r) => r.text().includes(text))!
const providerRow = (wrapper: ReturnType<typeof mount>, label: string) =>
    wrapper.findAll('tr').find((r) => r.find(`button[aria-label="${k('providers.edit_aria', { label })}"]`).exists())!
const openTaskDrawer = async (wrapper: ReturnType<typeof mount>, label = LABEL_A) => {
    await wrapper.find(`button[aria-label="${k('tasks.edit_aria', { label })}"]`).trigger('click')
    await flushPromises()
}

describe('AI runtime settings page', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('shows the spend chip with a cap, warning and no-cap variants', async () => {
        let wrapper = await mountPage()
        expect(wrapper.find('[data-testid="spend-chip"]').text()).toContain(
            k('spend.capped', { month, spent: '$12.50', cap: '$50.00', percent: 25 })
        )

        wrapper = await mountPage(overviewFixture(), usageFixture({ estimated_spent_usd: 45 }))
        expect(wrapper.find('[data-testid="spend-chip"]').text()).toContain(
            k('spend.capped', { month, spent: '$45.00', cap: '$50.00', percent: 90 })
        )
        expect(wrapper.find('[data-testid="spend-chip"]').text()).toContain(k('spend.near'))

        wrapper = await mountPage(overviewFixture(), usageFixture({ monthly_budget_usd: 0, remaining_usd: null }))
        expect(wrapper.find('[data-testid="spend-chip"]').text()).toContain(k('spend.uncapped', { month, spent: '$12.50' }))
    })

    it('asks for confirmation, then immediately sends kill_switch true', async () => {
        vi.mocked(ajax.put).mockResolvedValue({
            data: { data: overviewFixture({ budget: { monthly_usd: 50, kill_switch: true, warning_percent: 80 } }) },
        })
        const wrapper = await mountPage()
        await button(wrapper, k('kill.pause')).trigger('click')
        expect(confirmMock).toHaveBeenCalledTimes(1)
        expect(confirmMock.mock.calls[0][0].title).toBe(k('kill.confirm_title'))
        expect(ajax.put).not.toHaveBeenCalled()

        await confirmMock.mock.calls[0][0].onOk()
        await flushPromises()
        expect(ajax.put).toHaveBeenCalledWith('ai-runtime/budget', { kill_switch: true })
        expect(button(wrapper, k('kill.resume'))).toBeTruthy()
        expect(messageMock.success).toHaveBeenCalledWith(k('kill.paused'))
    })

    it('resumes AI without confirmation and shows the paused banner on every tab', async () => {
        const paused = overviewFixture({ budget: { monthly_usd: 50, kill_switch: true, warning_percent: 80 } })
        vi.mocked(ajax.put).mockResolvedValue({ data: { data: overviewFixture() } })
        const wrapper = await mountPage(paused)
        // The banner lives above the tabs, so it is present whichever tab is active.
        expect(wrapper.text()).toContain(k('paused_banner'))
        ;(wrapper.vm as any).$.setupState.rt.activeTab.value = 'providers'
        await flushPromises()
        expect(wrapper.text()).toContain(k('paused_banner'))

        await button(wrapper, k('kill.resume')).trigger('click')
        await flushPromises()
        expect(confirmMock).not.toHaveBeenCalled()
        expect(ajax.put).toHaveBeenCalledWith('ai-runtime/budget', { kill_switch: false })
        expect(wrapper.text()).not.toContain(k('paused_banner'))
    })

    it('renders overview KPIs, top tasks, unpriced models and seconds latency', async () => {
        const wrapper = await mountPage()
        const text = wrapper.text()
        expect(ajax.get).toHaveBeenCalledWith('ai-runtime/usage', { params: { month: currentMonthKey() } })
        expect(text).toContain('$12.5000')
        expect(text).toContain(k('usage.remaining', { amount: '$37.5000' }))
        expect(text).toContain('29%')
        expect(text).toContain(k('usage.failed_of', { failed: 2, total: 7 }))
        expect(text).toContain(k('usage.spent_percent', { percent: 25 }))
        expect(text).toContain(`${k('usage.refused')}: 3`)
        expect(text).toContain(k('models.unpriced'))
        expect(rowWith(wrapper, LABEL_A).text()).toContain('10,9 s')
        // Top tasks sorted by cost: expensive task first.
        const rows = wrapper.findAll('tr').map((r) => r.text())
        expect(rows.findIndex((r) => r.includes(LABEL_A))).toBeLessThan(rows.findIndex((r) => r.includes('cheap_task')))
    })

    it('opens the task drawer from a top-task row', async () => {
        const wrapper = await mountPage()
        await wrapper.find(`button[aria-label="${k('top.open_task', { label: LABEL_A })}"]`).trigger('click')
        await flushPromises()
        expect((wrapper.vm as any).$.setupState.rt.activeTab.value).toBe('tasks')
        expect(wrapper.find('[role="dialog"]').text()).toContain(k('task_drawer.title_edit', { label: LABEL_A }))
    })

    it('joins usage into the tasks table and marks read-only direct tasks', async () => {
        const wrapper = await mountPage()
        const a = rowWith(wrapper, TASK_A)
        expect(a.text()).toContain('deepseek · deepseek-v4-pro')
        expect(a.text()).toContain(k('tasks.source_observed'))
        expect(a.text()).toContain(k('tasks.error_percent', { percent: 25 }))
        expect(a.text()).toContain('$9.0000')
        expect(a.text()).not.toContain(k('tasks.unpriced'))

        const open = rowWith(wrapper, 'openai_task')
        expect(open.text()).toContain(k('tasks.source_task'))
        expect(open.text()).toContain(k('tasks.unpriced'))

        const direct = rowWith(wrapper, 'direct_task')
        expect(direct.text()).toContain(k('tasks.source_none'))
        expect(direct.find(`button[aria-label="${k('tasks.view_aria', { label: 'Gọi trực tiếp' })}"]`).exists()).toBe(true)
        expect(direct.find(`button[aria-label^="${k('tasks.edit_aria')}"]`).exists()).toBe(false)
    })

    it('saves only the edited task row and updates it in place', async () => {
        const updated = overviewFixture()
        updated.tasks[0] = { ...updated.tasks[0], provider: 'anthropic', model: 'claude-sonnet-5', effective: { provider: 'anthropic', model: 'claude-sonnet-5', source: 'task' } } as never
        vi.mocked(ajax.put).mockResolvedValue({ data: { data: updated } })
        const wrapper = await mountPage()
        await openTaskDrawer(wrapper)
        expect(wrapper.find('[data-testid="task-summary"]').text()).toBe(
            k('task_drawer.summary', {
                month: currentMonthKey(),
                calls: 4,
                failed: 1,
                cost: '$9.0000',
                running: 'deepseek · deepseek-v4-pro',
            })
        )

        await wrapper.find('#task-provider').setValue('anthropic')
        await wrapper.find('#task-model').setValue('claude-sonnet-5')
        await button(wrapper, k('task_drawer.save')).trigger('click')
        await flushPromises()

        expect(ajax.put).toHaveBeenCalledTimes(1)
        expect(ajax.put).toHaveBeenCalledWith('ai-runtime/tasks', {
            tasks: [{
                task: TASK_A, provider: 'anthropic', model: 'claude-sonnet-5', temperature: null,
                reasoning_effort: '', fallback_models: 'gpt-4o-mini',
            }],
        })
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
        expect(messageMock.success).toHaveBeenCalledWith(k('tasks.saved'))
        expect(rowWith(wrapper, TASK_A).text()).toContain('anthropic · claude-sonnet-5')
    })

    it('keeps the drawer open and shows the error when saving a task fails', async () => {
        vi.mocked(ajax.put).mockRejectedValue({ response: { data: { message: 'Model không hợp lệ' } } })
        const wrapper = await mountPage()
        await openTaskDrawer(wrapper)
        await button(wrapper, k('task_drawer.save')).trigger('click')
        await flushPromises()
        expect(wrapper.find('[role="dialog"]').text()).toContain('Model không hợp lệ')
    })

    it('filters model suggestions by provider and typed text, default model first', async () => {
        const wrapper = await mountPage()
        await openTaskDrawer(wrapper)
        const options = () => wrapper.findAll('.model-option').map((o) => o.text())

        // The "Default" provider option falls back to the effective provider (deepseek).
        expect(options()).toEqual(['deepseek-v4-pro'])

        await wrapper.find('#task-provider').setValue('anthropic')
        expect(options()).toEqual(['claude-sonnet-5', 'claude-haiku-4'])
        await wrapper.find('#task-model').setValue('HAI')
        expect(options()).toEqual(['claude-haiku-4'])
        // Free text is still accepted and saved.
        await wrapper.find('#task-model').setValue('my-custom-model')
        expect(options()).toEqual([])
        expect((wrapper.find('#task-model').element as HTMLInputElement).value).toBe('my-custom-model')

        await wrapper.find('#task-provider').setValue('openai')
        await wrapper.find('#task-model').setValue('')
        expect(options()).toEqual(['gpt-5-mini', 'text-embedding-3-small'])
    })

    it('confirms before discarding a dirty drawer and closes a clean one at once', async () => {
        const wrapper = await mountPage()
        await openTaskDrawer(wrapper)
        await wrapper.find('[data-testid="drawer-x"]').trigger('click')
        expect(confirmMock).not.toHaveBeenCalled()
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)

        await openTaskDrawer(wrapper)
        await wrapper.find('#task-model').setValue('edited')
        for (const close of [() => wrapper.find('[data-testid="drawer-x"]').trigger('click'), () => button(wrapper, k('common.cancel')).trigger('click')]) {
            confirmMock.mockClear()
            await close()
            expect(confirmMock).toHaveBeenCalledTimes(1)
            expect(confirmMock.mock.calls[0][0]).toMatchObject({
                title: k('guard.title'),
                okText: k('guard.discard'),
                cancelText: k('guard.keep_editing'),
            })
            expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
        }
        confirmMock.mock.calls[0][0].onOk()
        await flushPromises()
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)

        // The provider drawer uses the same guard.
        await wrapper.find(`button[aria-label="${k('providers.edit_aria', { label: 'OpenAI' })}"]`).trigger('click')
        await wrapper.find('#provider-model').setValue('gpt-x')
        confirmMock.mockClear()
        await button(wrapper, k('common.cancel')).trigger('click')
        expect(confirmMock).toHaveBeenCalledTimes(1)
    })

    it('orders task columns with the action pinned to the right', async () => {
        const wrapper = await mountPage()
        const tables = wrapper.findAll('table').map((t) => t.attributes('data-columns'))
        expect(tables).toContain('task,cost,effective,action:right')
        const a = rowWith(wrapper, TASK_A)
        expect(a.text()).toContain(`${k('tasks.calls_count', { count: 4 })} · ${k('tasks.error_percent', { percent: 25 })}`)
        expect(a.text()).toContain(k('tasks.fallback_count', { count: 1 }))
        expect(a.findAll('span.whitespace-nowrap').map((n) => n.text())).toEqual(['deepseek', 'deepseek-v4-pro'])
    })

    it('shows the reasoning field only for OpenAI', async () => {
        const wrapper = await mountPage()
        await openTaskDrawer(wrapper)
        expect(wrapper.find('#task-reasoning').exists()).toBe(false)
        await button(wrapper, k('common.cancel')).trigger('click')
        await flushPromises()

        await openTaskDrawer(wrapper, 'Tác vụ OpenAI')
        expect(wrapper.find('#task-reasoning').exists()).toBe(true)
        expect(wrapper.text()).toContain(k('task_drawer.reasoning'))
        expect(wrapper.findAll('#task-reasoning option').map((o) => o.text())).toEqual(
            ['default', 'none', 'minimal', 'low', 'medium', 'high', 'xhigh'].map((level) => k(`reasoning.${level}`))
        )
    })

    it('caps fallback models at three and sends them as a comma string', async () => {
        vi.mocked(ajax.put).mockResolvedValue({ data: { data: overviewFixture() } })
        const wrapper = await mountPage()
        await openTaskDrawer(wrapper)
        const tags = wrapper.find('input[data-tags]')
        expect((tags.element as HTMLInputElement).value).toBe('gpt-4o-mini')
        await tags.setValue('a,b,c,d')
        expect(wrapper.text()).toContain(k('task_drawer.fallback_limit', { max: 3 }))
        await button(wrapper, k('task_drawer.save')).trigger('click')
        await flushPromises()
        expect(vi.mocked(ajax.put).mock.calls[0][1]).toMatchObject({ tasks: [{ fallback_models: 'a,b,c' }] })
    })

    it('saves the budget without touching the kill switch', async () => {
        vi.mocked(ajax.put).mockResolvedValue({ data: { data: overviewFixture() } })
        const wrapper = await mountPage()
        await wrapper.find('#budget-monthly').setValue('75')
        await wrapper.find('#budget-warning').setValue('90')
        await wrapper.find('form').trigger('submit')
        await flushPromises()
        expect(ajax.put).toHaveBeenCalledWith('ai-runtime/budget', { monthly_usd: 75, warning_percent: 90 })
        expect(messageMock.success).toHaveBeenCalledWith(k('budget.saved'))
    })

    it('renders spike fields from the overview and sends them with the budget', async () => {
        const spike = { enabled: true, multiplier: 2.5, min_usd: 5, baseline_days: 7 }
        vi.mocked(ajax.put).mockResolvedValue({ data: { data: overviewFixture() } })
        const wrapper = await mountPage(
            overviewFixture({ budget: { monthly_usd: 50, kill_switch: false, warning_percent: 80, spike } })
        )
        expect(wrapper.text()).toContain(k('spike.title'))
        expect(wrapper.text()).toContain(k('spike.on'))
        expect(wrapper.text()).toContain(k('spike.help', { days: 7 }))
        expect((wrapper.find('#spike-multiplier').element as HTMLInputElement).value).toBe('2.5')
        await wrapper.find('#spike-multiplier').setValue('4')
        await wrapper.find('#spike-min').setValue('8')
        await wrapper.find('#spike-enabled').setValue(false)
        await wrapper.find('form').trigger('submit')
        await flushPromises()
        expect(ajax.put).toHaveBeenCalledWith('ai-runtime/budget', {
            monthly_usd: 50,
            warning_percent: 80,
            spike_enabled: false,
            spike_multiplier: 4,
            spike_min_usd: 8,
        })
    })

    describe('recent calls', () => {
        const call = (over: Record<string, unknown> = {}) => ({
            called_at: '2026-10-24T03:04:05Z', provider: 'deepseek', model: 'deepseek-v4-pro', via: 'chat', attempt: 1,
            is_fallback: false, failed: false, refused_reason: null, latency_ms: 10900, input_tokens: 120,
            cached_input_tokens: 0, output_tokens: 30, cost_usd: 0.0012, request_id: 'req_abcdefghijklmnop', origin: 'http',
            ...over,
        })
        const withCalls = (payload: unknown | Error) => {
            const base = vi.mocked(ajax.get).getMockImplementation()!
            vi.mocked(ajax.get).mockImplementation((url: string, cfg?: any) =>
                url === 'ai-runtime/calls'
                    ? payload instanceof Error ? Promise.reject(payload) : Promise.resolve({ data: { data: payload } })
                    : base(url, cfg)
            )
        }
        const mountWith = async (payload: unknown | Error) => {
            const wrapper = await mountPage()
            withCalls(payload)
            return wrapper
        }

        it('loads calls with task and limit, renders text results, seconds and duplicate rate', async () => {
            const wrapper = await mountWith({
                task: TASK_A,
                calls: [
                    call(),
                    call({ failed: true, request_id: null, origin: 'queue' }),
                    call({ is_fallback: true, attempt: 2, origin: 'console' }),
                    call({ is_fallback: true, attempt: 3, failed: true }),
                    call({ refused_reason: 'budget' }),
                ],
                duplicate_prompt_7d: { calls_with_hash: 8, duplicate_calls: 2, rate: 0.25 },
            })
            await openTaskDrawer(wrapper)
            expect(ajax.get).toHaveBeenCalledWith('ai-runtime/calls', { params: { task: TASK_A, limit: 20 } })
            const text = wrapper.find('[role="dialog"]').text()
            expect(text).toContain(k('task_drawer.recent_calls'))
            const failed = k('task_drawer.result_error')
            const ok = k('task_drawer.result_ok')
            for (const result of [
                ok,
                failed,
                `${k('task_drawer.result_fallback', { attempt: 2 })} · ${ok}`,
                `${k('task_drawer.result_fallback', { attempt: 3 })} · ${failed}`,
                k('task_drawer.result_blocked', { reason: 'budget' }),
                k('task_drawer.origin_http'),
                translate('text.dashboard.queue'),
                k('task_drawer.origin_console'),
            ]) {
                expect(text).toContain(result)
            }
            expect(text).toContain('10,9 s')
            expect(text).toContain('$0.0012')
            expect(text).toContain(
                `${k('task_drawer.duplicate_label')}: 25% ${k('task_drawer.duplicate_detail', { duplicates: 2, total: 8 })}`
            )
            expect(wrapper.find(`button[aria-label="${k('task_drawer.copy_aria')}"]`).exists()).toBe(true)
        })

        it('hides the duplicate line when no call has a hash', async () => {
            const wrapper = await mountWith({ calls: [call()], duplicate_prompt_7d: { calls_with_hash: 0, duplicate_calls: 0, rate: 0 } })
            await openTaskDrawer(wrapper)
            expect(wrapper.find('[data-testid="duplicate-rate"]').exists()).toBe(false)
            expect(wrapper.text()).not.toContain(k('task_drawer.duplicate_label'))
        })

        it('shows the empty state', async () => {
            const wrapper = await mountWith({ calls: [] })
            await openTaskDrawer(wrapper)
            expect(wrapper.text()).toContain(k('task_drawer.no_calls'))
        })

        it('shows the error state and retries', async () => {
            const wrapper = await mountWith(new Error('boom'))
            await openTaskDrawer(wrapper)
            expect(wrapper.find('[role="dialog"] [role="alert"]').text()).toContain('boom')
            withCalls({ calls: [call()] })
            await button(wrapper, k('common.retry')).trigger('click')
            await flushPromises()
            expect(wrapper.find('[role="dialog"]').text()).toContain('10,9 s')
        })

        it('opens direct tasks read-only with history and no save button', async () => {
            const wrapper = await mountWith({ calls: [call()], duplicate_prompt_7d: { calls_with_hash: 1, duplicate_calls: 0, rate: 0 } })
            await wrapper.find(`button[aria-label="${k('tasks.view_aria', { label: 'Gọi trực tiếp' })}"]`).trigger('click')
            await flushPromises()
            const dialog = wrapper.find('[role="dialog"]')
            expect(dialog.text()).toContain(k('task_drawer.title_view', { label: 'Gọi trực tiếp' }))
            expect(dialog.text()).toContain(k('task_drawer.read_only'))
            expect(dialog.text()).toContain('10,9 s')
            expect(dialog.find('#task-provider').exists()).toBe(false)
            expect(button(wrapper, k('task_drawer.save'))).toBeUndefined()
            expect(ajax.get).toHaveBeenCalledWith('ai-runtime/calls', { params: { task: 'direct_task', limit: 20 } })
            await button(wrapper, k('common.close')).trigger('click')
            expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
            expect(confirmMock).not.toHaveBeenCalled()
        })
    })

    it('shows provider key status as text tags and the env model fallback', async () => {
        const wrapper = await mountPage()
        expect(providerRow(wrapper, 'OpenAI').text()).toContain(k('providers.key_source_env'))
        expect(providerRow(wrapper, 'Anthropic (Claude)').text()).toContain(k('providers.key_source_admin'))
        expect(providerRow(wrapper, 'Anthropic (Claude)').text()).toContain('claude-sonnet-5')
        expect(providerRow(wrapper, 'DeepSeek').text()).toContain(k('providers.key_missing'))
        expect(providerRow(wrapper, 'DeepSeek').text()).toContain(k('providers.env_model', { model: 'deepseek-v4-pro' }))
        expect(providerRow(wrapper, 'DeepSeek').text()).toContain(k('providers.system_default'))
    })

    it('saves one provider from its drawer without the default provider', async () => {
        vi.mocked(ajax.put).mockResolvedValue({ data: { data: overviewFixture() } })
        const wrapper = await mountPage()
        await wrapper.find(`button[aria-label="${k('providers.edit_aria', { label: 'OpenAI' })}"]`).trigger('click')
        expect(wrapper.find('[role="dialog"]').text()).toContain(k('provider_drawer.title', { label: 'OpenAI' }))
        await wrapper.find('#provider-api-key').setValue('sk-new')
        await button(wrapper, k('provider_drawer.save'), true).trigger('click')
        await flushPromises()
        expect(ajax.put).toHaveBeenCalledWith('ai-runtime/providers', {
            providers: [{ key: 'openai', model: null, base_uri: null, api_key: 'sk-new', clear_api_key: false }],
        })
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    })

    it('saves the default provider by echoing stored rows and tests a connection', async () => {
        vi.mocked(ajax.put).mockResolvedValue({ data: { data: overviewFixture() } })
        vi.mocked(ajax.post).mockResolvedValue({ data: { data: { ok: true, latency_ms: 420, errors: [] } } })
        const wrapper = await mountPage()
        await wrapper.find('#default-provider').setValue('anthropic')
        expect(wrapper.findAll('#default-provider option')[0].text()).toBe(k('providers.default_from_env', { provider: 'deepseek' }))
        await button(wrapper, k('providers.save_default')).trigger('click')
        await flushPromises()
        const body = vi.mocked(ajax.put).mock.calls[0][1] as { default_provider: string; providers: unknown[] }
        expect(body.default_provider).toBe('anthropic')
        expect(body.providers).toHaveLength(3)
        expect(body.providers[1]).toMatchObject({ key: 'anthropic', model: 'claude-sonnet-5', api_key: null })

        await wrapper.find(`button[aria-label="${k('providers.test_aria', { label: 'OpenAI' })}"]`).trigger('click')
        await flushPromises()
        expect(ajax.post).toHaveBeenCalledWith('ai-runtime/test', expect.objectContaining({ provider: 'openai' }))
        expect(wrapper.text()).toContain(k('providers.test_ok', { ms: 420 }))
    })

    it('shows the empty usage state and a retry on usage errors', async () => {
        const wrapper = await mountPage(
            overviewFixture(),
            usageFixture({ request_count: 0, refused_count: 0, by_task: [], by_model: [] })
        )
        expect(wrapper.text()).toContain(k('usage.empty_month'))

        vi.mocked(ajax.get).mockImplementation((url: string) =>
            url === 'ai-runtime/usage' ? Promise.reject(new Error('boom')) : Promise.resolve({ data: { data: overviewFixture() } })
        )
        await button(wrapper, k('common.reload')).trigger('click')
        await flushPromises()
        expect(wrapper.text()).toContain('boom')
        expect(wrapper.findAll('button').some((b) => b.text() === k('common.retry'))).toBe(true)
    })
})

describe('Esc restore helpers', () => {
    it('reverts only protected fields that were cleared', () => {
        const form = { model: 'gpt-5', fallback_list: ['a', 'b'], temperature: 1 }
        const saved = captureKeys(form, ['model', 'fallback_list'])
        form.model = ''
        form.fallback_list = []
        form.temperature = 2
        expect(restoreKeys(form, saved)).toBe(true)
        expect(form).toEqual({ model: 'gpt-5', fallback_list: ['a', 'b'], temperature: 2 })
        expect(restoreKeys(form, saved)).toBe(false)
    })
})
