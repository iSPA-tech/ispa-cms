import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ fetchWorkflowSetup: vi.fn(), saveWorkflowSetup: vi.fn(), findWorkflow: vi.fn(), safeTestRun: vi.fn(), fetchNoticeRecipients: vi.fn() }))
const guard = vi.hoisted(() => ({ previewPublish: vi.fn() }))
const libs = vi.hoisted(() => ({ updateWorkflow: vi.fn() }))
const access = vi.hoisted(() => ({ rules: new Set<string>() }))
const router = vi.hoisted(() => ({ push: vi.fn() }))
const route = vi.hoisted(() => ({ params: { project: 'church-story' } }))

vi.mock('@core-admin/workflow-setup-api', async (original) => ({ ...(await original<Record<string, unknown>>()), ...api }))
vi.mock('@core-admin/workflow-guard-api', () => guard)
vi.mock('@core-admin/workflow-package-api', () => ({ fetchCredentials: vi.fn().mockResolvedValue([{ name: 'news-bot', type: 'telegram_bot', description: 'Bot tin tức' }]), previewWorkflowPackage: vi.fn() }))
vi.mock('@/libs', () => libs)
vi.mock('@/ajax', () => ({ default: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }))
vi.mock('@/mixins', () => ({ rule: (key: string) => access.rules.has(key) }))
vi.mock('@/services/workflowApi', () => ({
    fetchPermissionOptions: vi.fn().mockResolvedValue([{ key: 'sample-module.stories.review', label: 'Duyệt bài viết', module_label: 'Mô-đun mẫu' }]),
    resolveReviewers: vi.fn().mockResolvedValue([]),
}))
vi.mock('vue-router', () => ({ useRouter: () => router, useRoute: () => route }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}${JSON.stringify(params)}` : key), te: () => true }),
}))
vi.mock('@/components/workflow/inputs/ReviewerPicker.vue', () => ({ default: { name: 'ReviewerPicker', props: ['modelValue'], template: '<div data-testid="reviewer-picker" />' } }))
vi.mock('@/components/workflow/widgets/PermissionPicker.vue', () => ({
    default: {
        name: 'PermissionPicker',
        props: ['modelValue', 'readOnly'],
        emits: ['update:modelValue'],
        template: '<input data-testid="permission-picker" :value="modelValue" :disabled="readOnly" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
}))
vi.mock('@core-admin/pages/setting/workflows-manage/components/workflow-publish-dialog.vue', () => ({
    default: { name: 'WorkflowPublishDialog', props: ['open', 'workflow'], emits: ['close', 'done', 'refused'], template: '<div data-testid="publish-dialog" :data-open="open ? \'yes\' : \'no\'" />' },
}))

import SetupPage from '@core-admin/pages/setting/workflow-setup.vue'
import { fetchPermissionOptions } from '@/services/workflowApi'
import { changedAnswers, initialAnswer, isAnswered, openQuestionCount, setupProgress, wireAnswers } from '@core-admin/workflow-setup-api'

const stubs = {
    'no-permission-error': { template: '<div data-testid="no-permission" />' },
    'router-link': { props: ['to'], template: '<a :data-to="to.name"><slot /></a>' },
    'a-button': { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading"><slot /></button>' },
    'a-alert': { props: ['message', 'description'], template: '<div role="alert"><b>{{ message }}</b><i>{{ description }}</i><slot name="action" /></div>' },
    'a-spin': true,
    'a-tag': { template: '<span><slot /></span>' },
    'a-input': { props: ['value'], emits: ['update:value'], template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
    'a-select': {
        props: ['value', 'options'],
        emits: ['update:value'],
        template: '<select :value="value" @change="$emit(\'update:value\', $event.target.value)"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
    },
    'a-radio-group': {
        props: ['value', 'disabled'],
        emits: ['update:value'],
        provide() {
            return { pick: (value: string) => (this as any).$emit('update:value', value) }
        },
        template: '<div role="radiogroup" :data-value="value"><slot /></div>',
    },
    'a-radio': { props: ['value'], inject: ['pick'], template: '<button type="button" :data-radio="value" @click="pick(value)"><slot /></button>' },
}

const workflow = { id: 7, uuid: 'wf-7', slug: 'church-story', name: 'Bài về nhà thờ', is_active: false }
const reviewers = (over: Record<string, unknown> = {}) => ({ key: 'review.reviewer_permission', kind: 'reviewers', step_code: 'review', field: 'reviewer_permission', label: 'Ai duyệt ở bước “Duyệt bài”?', help: 'h', required: true, answered: false, current: { permission: null, assignees: [] }, ...over })
const notify = (over: Record<string, unknown> = {}) => ({ key: 'notify.permission', kind: 'notify_channel', step_code: 'notify', field: 'permission', label: 'Báo cho ai?', help: 'h', required: true, answered: true, current: { permission: 'sample-module.stories.review', users: [], suggested: null }, ...over })
const schedule = (over: Record<string, unknown> = {}) => ({ key: 'schedule', kind: 'schedule', label: 'Chạy khi nào?', help: 'h', required: true, answered: false, current: { cron: null, timezone: null, by_hand: false }, ...over })
const setupOf = (questions: unknown[], complete = false) => ({ complete, questions, preset: [{ label: 'Bài chỉ được đăng sau khi có người duyệt' }] })

const open = async () => {
    const wrapper = mount(SetupPage, { global: { stubs } })
    await flushPromises()
    return wrapper
}

beforeEach(() => {
    Object.values(api).forEach((mock) => mock.mockReset())
    guard.previewPublish.mockReset().mockResolvedValue({ unchanged: false })
    libs.updateWorkflow.mockReset().mockResolvedValue({ status: 'success' })
    router.push.mockReset()
    access.rules = new Set(['workflow_project.viewAny', 'workflow_project.update'])
    api.findWorkflow.mockResolvedValue(workflow)
    api.fetchWorkflowSetup.mockResolvedValue(setupOf([reviewers(), notify(), schedule()]))
})

describe('setup rules', () => {
    it('starts every question from what is stored and knows when it is answered', () => {
        expect(initialAnswer(reviewers({ current: { permission: 'a.b', assignees: ['u'] } }))).toEqual({ permission: 'a.b', assignees: ['u'] })
        expect(initialAnswer(schedule())).toEqual({ cron: null, timezone: null })
        expect(initialAnswer(schedule({ answered: true, current: { cron: null, timezone: null, by_hand: true } }))).toEqual({ cron: '', timezone: null })
        expect(initialAnswer(schedule({ answered: true, current: { cron: '0 6 * * *', timezone: 'Asia/Ho_Chi_Minh', by_hand: false } }))).toEqual({ cron: '0 6 * * *', timezone: 'Asia/Ho_Chi_Minh' })
        expect(initialAnswer({ kind: 'credential', current: 'news-bot' })).toBe('news-bot')

        expect(isAnswered(reviewers(), { permission: '', assignees: [] })).toBe(false)
        expect(isAnswered(reviewers(), { permission: '', assignees: ['u'] })).toBe(true)
        // "Only when someone presses run" is an answer; nothing chosen is not.
        expect(isAnswered(schedule(), { cron: '' })).toBe(true)
        expect(isAnswered(schedule(), { cron: null })).toBe(false)
    })

    it('counts progress, the open questions of the list badge, and sends only what changed', () => {
        const questions = [reviewers(), notify(), schedule()]
        const answers = { 'review.reviewer_permission': { permission: 'a.b', assignees: [] }, 'notify.permission': { permission: 'sample-module.stories.review', users: [] }, schedule: { cron: null, timezone: null } }
        expect(setupProgress(questions, answers)).toEqual({ done: 2, total: 3 })
        expect(openQuestionCount(setupOf(questions))).toBe(2)
        expect(changedAnswers(questions, answers)).toEqual({ 'review.reviewer_permission': { permission: 'a.b', assignees: [] } })
        // "Only when someone presses run" is sent as a schedule without a cron.
        expect(wireAnswers({ schedule: { cron: '', timezone: null }, 'review.reviewer_permission': { permission: 'a.b', assignees: [] }, 'notify.permission': 'a.b' })).toEqual({
            schedule: { cron: null, timezone: null },
            'review.reviewer_permission': { permission: 'a.b', assignees: [] },
            'notify.permission': 'a.b',
        })
    })
})

describe('setup screen', () => {
    it('refuses an account that may not see workflows', async () => {
        access.rules = new Set()
        const wrapper = await open()
        expect(wrapper.find('[data-testid="workflow-setup-not-allowed"]').exists()).toBe(true)
    })

    it('says so when the workflow is gone, and offers a retry when loading failed', async () => {
        api.findWorkflow.mockResolvedValueOnce(null)
        let wrapper = await open()
        expect(wrapper.get('[data-testid="setup-load-error"]').text()).toContain('text.workflow.setup.missing_title')

        api.findWorkflow.mockRejectedValueOnce(new Error('network'))
        wrapper = await open()
        expect(wrapper.get('[data-testid="setup-load-error"]').text()).toContain('text.workflow.setup.load_failed_title')
        await wrapper.get('[data-testid="setup-retry"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid="setup-question"]').exists()).toBe(true)
    })

    it('shows the questions, what the sample already set, and keeps "save and turn on" closed until all are answered', async () => {
        const wrapper = await open()
        expect(api.fetchWorkflowSetup).toHaveBeenCalledWith('wf-7')
        expect(wrapper.get('[data-testid="setup-progress"]').text()).toContain('"done":1,"total":3')
        expect(wrapper.findAll('[data-testid="setup-question"]').map((row) => row.attributes('data-answered'))).toEqual(['no', 'yes', 'no'])
        // The first open question is the one being asked; an answered one shows its choice by name.
        expect(wrapper.findAll('[data-testid="setup-question-body"]')).toHaveLength(1)
        expect(wrapper.get('[data-key="notify.permission"] [data-testid="setup-answer-summary"]').text()).toContain('Duyệt bài viết')
        expect(wrapper.get('[data-testid="setup-preset"]').text()).toContain('Bài chỉ được đăng sau khi có người duyệt')
        expect(wrapper.get('[data-testid="setup-save-enable"]').attributes('disabled')).toBeDefined()
        expect(wrapper.get('[data-testid="setup-test-run"]').attributes('disabled')).toBeUndefined()
        // Nothing is written by looking.
        expect(api.saveWorkflowSetup).not.toHaveBeenCalled()
    })

    it('answers, saves only the changed answers, publishes through the dialog and turns the workflow on', async () => {
        const wrapper = await open()
        await wrapper.get('[data-key="review.reviewer_permission"] [data-testid="permission-picker"]').setValue('sample-module.stories.review')
        await wrapper.get('[data-key="review.reviewer_permission"] [data-testid="setup-question-next"]').trigger('click')
        // The next open question opens by itself.
        expect(wrapper.get('[data-key="schedule"]').find('[data-testid="setup-question-body"]').exists()).toBe(true)
        await wrapper.get('[data-radio="manual"]').trigger('click')
        expect(wrapper.get('[data-testid="setup-progress"]').text()).toContain('"done":3,"total":3')
        expect(api.saveWorkflowSetup).not.toHaveBeenCalled()

        api.saveWorkflowSetup.mockResolvedValue(
            setupOf([reviewers({ answered: true, current: { permission: 'sample-module.stories.review', assignees: [] } }), notify(), schedule({ answered: true, current: { cron: null, timezone: null, by_hand: true } })], true)
        )
        await wrapper.get('[data-testid="setup-save-enable"]').trigger('click')
        await flushPromises()
        expect(api.saveWorkflowSetup).toHaveBeenCalledWith('wf-7', {
            'review.reviewer_permission': { permission: 'sample-module.stories.review', assignees: [] },
            schedule: { cron: '', timezone: null },
        })
        const dialog = wrapper.getComponent({ name: 'WorkflowPublishDialog' })
        expect(dialog.props('open')).toBe(true)
        expect(libs.updateWorkflow).not.toHaveBeenCalled()

        dialog.vm.$emit('done', { path: 'published' })
        dialog.vm.$emit('close')
        await flushPromises()
        expect(libs.updateWorkflow).toHaveBeenCalledWith('wf-7', { is_active: true })
        expect(wrapper.get('[data-testid="setup-outcome"]').attributes('data-outcome')).toBe('enabled')
        expect(wrapper.find('[data-testid="setup-actions"]').exists()).toBe(false)
    })

    it('a change that waits for a second person is saved but not switched on', async () => {
        api.fetchWorkflowSetup.mockResolvedValue(setupOf([notify()], true))
        const wrapper = await open()
        await wrapper.get('[data-testid="setup-save-enable"]').trigger('click')
        await flushPromises()
        // Nothing changed: no save request.
        expect(api.saveWorkflowSetup).not.toHaveBeenCalled()
        const dialog = wrapper.getComponent({ name: 'WorkflowPublishDialog' })
        dialog.vm.$emit('done', { path: 'pending_review' })
        dialog.vm.$emit('close')
        await flushPromises()
        expect(libs.updateWorkflow).not.toHaveBeenCalled()
        expect(wrapper.get('[data-testid="setup-outcome"]').attributes('data-outcome')).toBe('waiting')
    })

    it('a draft that already runs is switched on without a publish dialog', async () => {
        api.fetchWorkflowSetup.mockResolvedValue(setupOf([notify()], true))
        guard.previewPublish.mockResolvedValue({ unchanged: true })
        const wrapper = await open()
        await wrapper.get('[data-testid="setup-save-enable"]').trigger('click')
        await flushPromises()
        expect(wrapper.getComponent({ name: 'WorkflowPublishDialog' }).props('open')).toBe(false)
        expect(libs.updateWorkflow).toHaveBeenCalledWith('wf-7', { is_active: true })
    })

    it('marks the question whose answer the server refused and switches nothing on', async () => {
        const wrapper = await open()
        await wrapper.get('[data-key="review.reviewer_permission"] [data-testid="permission-picker"]').setValue('gone.permission')
        await wrapper.get('[data-key="review.reviewer_permission"] [data-testid="setup-question-next"]').trigger('click')
        await wrapper.get('[data-radio="daily"]').trigger('click')
        api.saveWorkflowSetup.mockRejectedValue({ response: { status: 422, data: { data: { errors: [{ key: 'review.reviewer_permission', code: 'answer_invalid', message: 'x' }] } } } })
        await wrapper.get('[data-testid="setup-save-enable"]').trigger('click')
        await flushPromises()
        expect(wrapper.get('[data-key="review.reviewer_permission"] [data-testid="setup-question-problem"]').text()).toBe('text.workflow.setup.answer_refused')
        expect(wrapper.get('[data-testid="setup-save-error"]').text()).toContain('text.workflow.setup.save_refused')
        expect(guard.previewPublish).not.toHaveBeenCalled()
        expect(libs.updateWorkflow).not.toHaveBeenCalled()
    })

    it('offers named people only on a step that takes them (an approval)', async () => {
        const wrapper = await open()
        expect(wrapper.find('[data-key="review.reviewer_permission"] [data-testid="reviewer-picker"]').exists()).toBe(true)

        api.fetchWorkflowSetup.mockResolvedValue(setupOf([reviewers({ assignees_allowed: false }), schedule()]))
        const other = await open()
        expect(other.find('[data-key="review.reviewer_permission"] [data-testid="setup-question-body"]').exists()).toBe(true)
        expect(other.find('[data-key="review.reviewer_permission"] [data-testid="reviewer-picker"]').exists()).toBe(false)
    })

    it('the safe test run shows what each step did and writes nothing', async () => {
        api.safeTestRun.mockResolvedValue({ dry_run: { completed: true, steps: [{ step_code: 'select', step_type: 'sample_module.select_places', safety: ['read'], mode: 'stubbed', outcome: 'would_run' }] } })
        const wrapper = await open()
        await wrapper.get('[data-testid="setup-test-run"]').trigger('click')
        await flushPromises()
        expect(api.safeTestRun).toHaveBeenCalledWith('wf-7')
        expect(wrapper.get('[data-testid="setup-run"]').attributes('data-state')).toBe('completed')
        expect(api.saveWorkflowSetup).not.toHaveBeenCalled()

        api.safeTestRun.mockRejectedValue(new Error('refused'))
        await wrapper.get('[data-testid="setup-test-run"]').trigger('click')
        await flushPromises()
        expect(wrapper.get('[data-testid="setup-run"]').attributes('data-state')).toBe('failed')

        // Refused because a question is still open: ask for the answers instead of saying it failed.
        api.safeTestRun.mockRejectedValue({ response: { data: { error_code: 'setup_incomplete' } } })
        await wrapper.get('[data-testid="setup-test-run"]').trigger('click')
        await flushPromises()
        expect(wrapper.get('[data-testid="setup-run"]').attributes('data-state')).toBe('incomplete')
        expect(wrapper.get('[data-testid="setup-run"]').text()).toContain('text.workflow.setup.run_incomplete')
    })

    it('an account that may only view sees the answers and no actions that write', async () => {
        access.rules = new Set(['workflow_project.viewAny'])
        const wrapper = await open()
        expect(wrapper.find('[data-testid="setup-read-only"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="setup-save-enable"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="setup-question-next"]').exists()).toBe(false)
    })
})

describe('who is told by a notice', () => {
    it('asks for people, shows the suggestion as names to accept in one click, keeps permissions behind "advanced"', async () => {
        access.rules = new Set(['workflow_project.viewAny', 'workflow_project.update'])
        api.findWorkflow.mockResolvedValue(workflow)
        api.fetchWorkflowSetup.mockResolvedValue(setupOf([notify({ answered: false, current: { permission: null, users: [], suggested: 'email.viewAny' } })]))
        api.fetchNoticeRecipients.mockImplementation(async (_id: number, answer: { permission?: string; users?: string[] }) =>
            answer.users?.length
                ? { count: 2, people: [{ uuid: 'u1', name: 'An', detail: 'an01' }, { uuid: 'u2', name: 'Bình', detail: null }], more: 0 }
                : { count: 2, people: [{ uuid: 'u1', name: 'An', detail: 'an01' }, { uuid: 'u2', name: 'Bình', detail: null }], more: 0 }
        )

        const wrapper = await open()
        await new Promise((resolve) => setTimeout(resolve, 350))
        await flushPromises()

        const body = () => wrapper.get('[data-key="notify.permission"]')
        expect(body().find('[data-testid="setup-notice-people"] [data-testid="reviewer-picker"]').exists()).toBe(true)
        // Nothing filled in silently; no list of permissions on the screen.
        expect(body().find('[data-testid="setup-notice-group"]').exists()).toBe(false)
        expect(api.fetchNoticeRecipients).toHaveBeenCalledWith('wf-7', { permission: 'email.viewAny', users: [] })
        expect(body().get('[data-testid="setup-notice-suggestion"]').text()).toContain('An - an01, Bình')

        await body().get('[data-testid="setup-notice-use-suggestion"]').trigger('click')
        await new Promise((resolve) => setTimeout(resolve, 350))
        await flushPromises()
        expect(body().find('[data-testid="setup-notice-suggestion"]').exists()).toBe(false)
        expect(api.fetchNoticeRecipients).toHaveBeenLastCalledWith('wf-7', { permission: '', users: ['u1', 'u2'] })
        expect(body().get('[data-testid="setup-notice-preview"]').text()).toBe('text.workflow.setup.notify_preview{"count":2,"names":"An - an01, Bình"}')

        await body().get('[data-testid="setup-notice-advanced"]').trigger('click')
        expect(body().find('[data-testid="setup-notice-group"] [data-testid="permission-picker"]').exists()).toBe(true)
    })
})
