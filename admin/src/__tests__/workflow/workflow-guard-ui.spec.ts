import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { dropGrant, useStepUpRunner } from '@/libs/securityTokens'
import PublishDialog from '@core-admin/pages/setting/workflows-manage/components/workflow-publish-dialog.vue'
import PausedAlert from '@core-admin/pages/setting/workflows-manage/components/workflow-paused-alert.vue'
import PendingChanges from '@core-admin/pages/setting/workflows-manage/components/workflow-pending-changes.vue'
import VersionDrawer from '@core-admin/pages/setting/workflows-manage/components/workflow-version-drawer.vue'
import DetailCard from '@core-admin/pages/setting/workflows-manage/components/workflow-detail-card.vue'
import WorkflowList from '@core-admin/pages/setting/workflows-manage/components/workflow-list.vue'
import BuilderHeader from '@core-admin/pages/setting/workflows-manage/components/workflow-builder-header.vue'

const ajax = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))
const access = vi.hoisted(() => ({ rules: new Set<string>() }))
const confirm = vi.hoisted(() => ({ last: null as null | { onOk: () => unknown } }))

vi.mock('@/ajax', () => ({ default: ajax }))
vi.mock('@/mixins', () => ({ rule: (key: string) => access.rules.has(key), iAlert: vi.fn() }))
vi.mock('ant-design-vue', () => ({ Modal: { confirm: (options: { onOk: () => unknown }) => (confirm.last = options) }, message: { success: vi.fn(), error: vi.fn(), warning: vi.fn() } }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params?: Record<string, unknown>) => (params && Object.values(params).some((v) => v !== undefined && v !== '') ? `${key}${JSON.stringify(params)}` : key),
    }),
}))
// The card's graph summary pulls in the app i18n instance; the guardrail rows do not need it.
vi.mock('@core-admin/pages/setting/workflows-manage/workflow-project-utils', () => ({
    summarizeWorkflowProject: () => ({ nodeCount: 0, edgeCount: 0, decisionCount: 0, inputCount: 0, outputCount: 0, validationState: '', validationTone: 'default' }),
}))
// The confirmation form itself has its own specs; here it only has to hand over a grant or be closed.
vi.mock('@core-admin/components/security/step-up-dialog.vue', () => ({
    default: {
        name: 'StepUpDialog',
        props: ['open', 'purpose', 'intro'],
        emits: ['confirmed', 'cancel'],
        template:
            '<div v-if="open" data-testid="stepup" :data-purpose="purpose"><button data-testid="stepup-ok" @click="$emit(\'confirmed\', { grant: \'g\'.repeat(40), expires_in: 300 })" /><button data-testid="stepup-close" @click="$emit(\'cancel\')" /></div>',
    },
}))

const GRANT = 'g'.repeat(40)
const slot = (name: string) => ({ template: `<div data-stub="${name}"><slot /></div>` })
const stubs = {
    'a-modal': { props: ['open', 'title'], template: '<div v-if="open" data-stub="modal"><h3>{{ title }}</h3><slot /><slot name="footer" /></div>' },
    'a-drawer': { props: ['open', 'title'], template: '<div v-if="open" data-stub="drawer"><h3>{{ title }}</h3><slot /></div>' },
    'a-card': { template: '<div data-stub="card"><slot name="title" /><slot /></div>' },
    'a-alert': {
        props: ['message', 'type'],
        template: '<div role="alert" :data-type="type"><b>{{ message }}</b><slot name="description" /><slot name="action" /></div>',
    },
    'a-button': { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading"><slot /></button>' },
    'a-tag': { template: '<span><slot /></span>' },
    'a-textarea': { props: ['value'], emits: ['update:value'], template: '<textarea :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
    'a-select': {
        props: ['value', 'options'],
        emits: ['update:value', 'change'],
        template:
            '<select :value="value" @change="$emit(\'update:value\', $event.target.value); $emit(\'change\', $event.target.value)"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
    },
    'a-skeleton': true,
    'a-spin': slot('spin'),
    'a-empty': { props: ['description'], template: '<div data-stub="empty">{{ description }}</div>' },
    'a-switch': { props: ['checked', 'disabled'], template: '<input type="checkbox" data-stub="switch" :checked="checked" :disabled="disabled" />' },
    'a-tooltip': slot('tooltip'),
    'a-popconfirm': slot('popconfirm'),
    'a-badge': slot('badge'),
}

const find = (wrapper: ReturnType<typeof mount>, id: string) => wrapper.find(`[data-testid="${id}"]`)
const body = (data: unknown, status = 200) => ({ status, data: { status: 'success', data } })
const refusal = (status: number, code: string, data: unknown = null) => ({ response: { status, data: { status: 'error', error_code: code, data } } })
const workflow = { id: 7, name: 'Daily post', slug: 'daily-post', nodes: [], tags: [] }
const names = { fetch: 'Call the CRM', post: 'Post to the site' }

beforeEach(() => {
    Object.values(ajax).forEach((fn) => fn.mockReset())
    access.rules.clear()
    confirm.last = null
    dropGrant('workflow_publish')
})

// ---------------------------------------------------------------------------------------------------------------------
// Publish dialog: what will happen, per path
// ---------------------------------------------------------------------------------------------------------------------

describe('publish dialog', () => {
    const open = async (preview: Record<string, unknown>) => {
        ajax.get.mockResolvedValueOnce(body({ unchanged: false, sensitive: false, changes: [], path: 'published', cooling_off_minutes: 60, step_up_required: false, ...preview }))
        const wrapper = mount(PublishDialog, { props: { open: true, workflow, names }, global: { stubs } })
        await flushPromises()
        return wrapper
    }

    it('asks the server what the publish would do before anything is sent', async () => {
        const wrapper = await open({})
        expect(ajax.get).toHaveBeenCalledWith('workflows/7/versions/preview-publish', expect.objectContaining({ silent: true }))
        expect(ajax.post).not.toHaveBeenCalled()
        expect(find(wrapper, 'publish-ready').exists()).toBe(true)
    })

    it('a change that is not sensitive: goes live at once, no extra check, no confirmation note', async () => {
        const wrapper = await open({})
        expect(find(wrapper, 'publish-outcome').attributes('data-path')).toBe('published')
        expect(find(wrapper, 'publish-outcome').text()).toContain('text.workflow.guard.outcome.will.published')
        expect(find(wrapper, 'publish-changes').exists()).toBe(false)
        expect(find(wrapper, 'publish-stepup-note').exists()).toBe(false)
        expect(find(wrapper, 'publish-confirm').text()).toBe('text.workflow.guard.publish.publish_now')

        ajax.post.mockResolvedValueOnce(body({ number: 4, status: 'published', path: 'published' }, 201))
        await wrapper.find('textarea').setValue('  Shorter prompt ')
        await find(wrapper, 'publish-confirm').trigger('click')
        await flushPromises()

        expect(ajax.post).toHaveBeenCalledTimes(1)
        expect(ajax.post.mock.calls[0][0]).toBe('workflows/7/versions/publish')
        expect(ajax.post.mock.calls[0][1]).toEqual({ note: 'Shorter prompt' })
        expect(find(wrapper, 'stepup').exists()).toBe(false)
        expect(find(wrapper, 'publish-result').attributes('data-path')).toBe('published')
        expect(find(wrapper, 'publish-result').text()).toContain('text.workflow.guard.outcome.done.published')
        expect(find(wrapper, 'publish-result').text()).toContain('"number":4')
        expect(wrapper.emitted('done')?.[0]?.[0]).toMatchObject({ number: 4 })
    })

    it('a sensitive change with a second publisher: lists the changes in plain words and says it waits for another person', async () => {
        const wrapper = await open({
            sensitive: true,
            path: 'pending_review',
            step_up_required: true,
            changes: [
                { kind: 'sensitive_step_added', step_code: 'fetch', detail: 'http_request' },
                { kind: 'host_added', detail: 'api.example.com' },
            ],
        })
        const rows = wrapper.findAll('[data-testid="publish-change"]')
        expect(rows).toHaveLength(2)
        expect(rows[0].text()).toContain('text.workflow.guard.change.sensitive_step_added')
        expect(rows[0].text()).toContain('Call the CRM')
        expect(rows[0].text()).not.toContain('http_request')
        expect(rows[1].text()).toContain('api.example.com')
        expect(find(wrapper, 'publish-outcome').attributes('data-path')).toBe('pending_review')
        expect(find(wrapper, 'publish-outcome').text()).toContain('text.workflow.guard.outcome.will.pending_review')
        expect(find(wrapper, 'publish-stepup-note').exists()).toBe(true)
        expect(find(wrapper, 'publish-confirm').text()).toBe('text.workflow.guard.publish.send_for_review')
    })

    it('a sensitive change on a site with one publisher: says the time it goes live unless cancelled', async () => {
        const wrapper = await open({ sensitive: true, path: 'cooling_off', cooling_off_minutes: 60, step_up_required: true, changes: [{ kind: 'budget_raised' }] })
        expect(find(wrapper, 'publish-outcome').attributes('data-path')).toBe('cooling_off')
        expect(find(wrapper, 'publish-outcome').text()).toMatch(/outcome\.will\.cooling_off\{"time":"\d\d:\d\d( \d\d\/\d\d)?","minutes":60/)
    })

    it('a draft equal to the running version: nothing to publish, no confirm button', async () => {
        const wrapper = await open({ unchanged: true })
        expect(find(wrapper, 'publish-unchanged').text()).toContain('text.workflow.guard.outcome.will.unchanged')
        expect(find(wrapper, 'publish-confirm').exists()).toBe(false)
    })

    it('step-up: when the preview says a confirmation is needed, it is asked before anything is sent', async () => {
        const wrapper = await open({ sensitive: true, path: 'pending_review', step_up_required: true, changes: [{ kind: 'host_added', detail: 'api.example.com' }] })
        ajax.post.mockResolvedValueOnce(body({ number: 5, status: 'pending_review', path: 'pending_review', changes: [] }, 202))

        await find(wrapper, 'publish-confirm').trigger('click')
        await flushPromises()
        expect(find(wrapper, 'stepup').attributes('data-purpose')).toBe('workflow_publish')
        expect(ajax.post).not.toHaveBeenCalled()

        await find(wrapper, 'stepup-ok').trigger('click')
        await flushPromises()
        expect(ajax.post).toHaveBeenCalledTimes(1)
        expect(ajax.post.mock.calls[0][2].headers).toEqual({ 'X-Step-Up-Grant': GRANT })
        expect(find(wrapper, 'publish-result').attributes('data-path')).toBe('pending_review')
    })

    it('step-up: a 409 opens the confirmation, then the publish is sent once more with the grant', async () => {
        // The preview did not expect it (a grant that ran out meanwhile): the server's 409 decides.
        const wrapper = await open({ sensitive: true, path: 'pending_review', step_up_required: false, changes: [{ kind: 'host_added', detail: 'api.example.com' }] })
        ajax.post
            .mockRejectedValueOnce(refusal(409, 'step_up_required', { purpose: 'workflow_publish' }))
            .mockResolvedValueOnce(body({ number: 5, status: 'pending_review', path: 'pending_review', changes: [] }, 202))

        await find(wrapper, 'publish-confirm').trigger('click')
        await flushPromises()
        expect(find(wrapper, 'stepup').attributes('data-purpose')).toBe('workflow_publish')
        expect(find(wrapper, 'publish-done').exists()).toBe(false)

        await find(wrapper, 'stepup-ok').trigger('click')
        await flushPromises()

        expect(ajax.post).toHaveBeenCalledTimes(2)
        expect(ajax.post.mock.calls[0][2].headers).toBeUndefined()
        expect(ajax.post.mock.calls[1][2].headers).toEqual({ 'X-Step-Up-Grant': GRANT })
        expect(find(wrapper, 'stepup').exists()).toBe(false)
        expect(find(wrapper, 'publish-result').attributes('data-path')).toBe('pending_review')
        expect(find(wrapper, 'publish-result').text()).toContain('text.workflow.guard.outcome.done.pending_review')
    })

    it('step-up: a second "required" answer is not retried again; the dialog says nothing was changed', async () => {
        const wrapper = await open({ sensitive: true, path: 'pending_review', step_up_required: false })
        ajax.post.mockRejectedValue(refusal(409, 'step_up_required'))
        await find(wrapper, 'publish-confirm').trigger('click')
        await flushPromises()
        await find(wrapper, 'stepup-ok').trigger('click')
        await flushPromises()

        expect(ajax.post).toHaveBeenCalledTimes(2)
        expect(find(wrapper, 'stepup').exists()).toBe(false)
        expect(find(wrapper, 'publish-error').text()).toContain('text.workflow.guard.error.stepup_needed')
        expect(wrapper.emitted('done')).toBeUndefined()
    })

    it('step-up: closing the confirmation publishes nothing and leaves the dialog ready', async () => {
        const wrapper = await open({ sensitive: true, path: 'cooling_off', step_up_required: true })
        await find(wrapper, 'publish-confirm').trigger('click')
        await flushPromises()
        await find(wrapper, 'stepup-close').trigger('click')
        await flushPromises()

        expect(ajax.post).not.toHaveBeenCalled()
        expect(find(wrapper, 'publish-ready').exists()).toBe(true)
        expect(find(wrapper, 'publish-confirm').attributes('disabled')).toBeUndefined()
        expect(wrapper.emitted('done')).toBeUndefined()
    })

    it('a refused publish lists its problems with step names and tells the designer', async () => {
        const wrapper = await open({})
        ajax.post.mockRejectedValueOnce(
            refusal(422, 'publish_refused', { problems: [{ code: 'publish_needs_approval', step_code: 'post', message: 'post: publishes without an approval step before it.' }] })
        )
        await find(wrapper, 'publish-confirm').trigger('click')
        await flushPromises()

        const rows = wrapper.findAll('[data-testid="publish-problem"]')
        expect(rows).toHaveLength(1)
        expect(rows[0].text()).toContain('text.workflow.guard.problem.publish_needs_approval')
        expect(rows[0].text()).toContain('Post to the site')
        expect(rows[0].text()).not.toContain('post:')
        expect(wrapper.emitted('refused')).toHaveLength(1)
        expect(find(wrapper, 'publish-done').exists()).toBe(false)
    })

    it('a preview that fails offers a retry instead of a blind publish', async () => {
        ajax.get.mockRejectedValueOnce({ response: { status: 500, data: {} } })
        const wrapper = mount(PublishDialog, { props: { open: true, workflow, names }, global: { stubs } })
        await flushPromises()
        expect(find(wrapper, 'publish-load-error').exists()).toBe(true)
        expect(find(wrapper, 'publish-confirm').exists()).toBe(false)

        ajax.get.mockResolvedValueOnce(body({ unchanged: false, path: 'published', changes: [] }))
        await find(wrapper, 'publish-retry').trigger('click')
        await flushPromises()
        expect(find(wrapper, 'publish-ready').exists()).toBe(true)
    })
})

describe('step-up runner', () => {
    it('lazy: tries without a grant first and never opens the dialog when the server does not ask', async () => {
        const runner = useStepUpRunner({ purpose: 'workflow_publish', lazy: true })
        const action = vi.fn().mockResolvedValue('ok')
        await expect(runner.run(action)).resolves.toBe('ok')
        expect(action).toHaveBeenCalledWith(null)
        expect(runner.open.value).toBe(false)
    })

    it('keeps a grant per purpose: a publish grant is never sent for a credential change', async () => {
        const publish = useStepUpRunner({ purpose: 'workflow_publish', lazy: true })
        const action = vi.fn().mockRejectedValueOnce(refusal(409, 'step_up_required')).mockResolvedValue('ok')
        const done = publish.run(action)
        await flushPromises()
        await publish.confirmed({ grant: GRANT, expires_in: 300 })
        await done

        const credential = useStepUpRunner({ purpose: 'workflow_credential', wait: true })
        const change = vi.fn().mockResolvedValue('saved')
        const pending = credential.run(change)
        expect(credential.open.value).toBe(true)
        expect(change).not.toHaveBeenCalled()
        await credential.confirmed({ grant: 'c'.repeat(40), expires_in: 300 })
        await expect(pending).resolves.toBe('saved')
        expect(change).toHaveBeenCalledWith('c'.repeat(40))
        dropGrant('workflow_credential')
    })
})

// ---------------------------------------------------------------------------------------------------------------------
// Paused workflows
// ---------------------------------------------------------------------------------------------------------------------

describe('paused workflow', () => {
    const paused = (reason: string, canPublish: boolean) =>
        mount(PausedAlert, { props: { workflow: { ...workflow, paused_reason: reason, paused_at: '2026-11-25T08:00:00' }, canPublish }, global: { stubs } })

    it('shows nothing for a workflow that is not paused', () => {
        const wrapper = mount(PausedAlert, { props: { workflow, canPublish: true }, global: { stubs } })
        expect(find(wrapper, 'workflow-paused').exists()).toBe(false)
    })

    it('a safety limit: plain reason and "turn back on" for an account that may publish', async () => {
        const wrapper = paused('breaker:cost_per_hour', true)
        expect(find(wrapper, 'workflow-paused-reason').text()).toBe('text.workflow.guard.paused.reason.cost_per_hour')
        expect(wrapper.text()).not.toContain('breaker:')

        ajax.post.mockResolvedValueOnce(body({ is_active: true, paused_reason: null }))
        await find(wrapper, 'workflow-resume').trigger('click')
        await flushPromises()
        expect(ajax.post).toHaveBeenCalledWith('workflow/projects/7/resume', {}, expect.objectContaining({ silent: true }))
        expect(wrapper.emitted('resumed')).toHaveLength(1)
    })

    it('a safety limit: no button without the right to publish, and a line that says who can', () => {
        const wrapper = paused('breaker:error_rate', false)
        expect(find(wrapper, 'workflow-resume').exists()).toBe(false)
        expect(find(wrapper, 'workflow-resume-denied').exists()).toBe(true)
    })

    it('an integrity pause: says the stored version was changed outside the admin, offers the designer, never "turn back on"', async () => {
        const wrapper = paused('integrity', true)
        expect(find(wrapper, 'workflow-paused').attributes('data-kind')).toBe('integrity')
        expect(find(wrapper, 'workflow-paused').attributes('data-type')).toBe('error')
        expect(find(wrapper, 'workflow-paused-reason').text()).toBe('text.workflow.guard.paused.reason.integrity')
        expect(find(wrapper, 'workflow-resume').exists()).toBe(false)
        await find(wrapper, 'workflow-paused-open').trigger('click')
        expect(wrapper.emitted('open-builder')).toHaveLength(1)
    })

    it('a refused resume is explained and the button comes back', async () => {
        const wrapper = paused('breaker:runs_per_hour', true)
        ajax.post.mockRejectedValueOnce(refusal(403, ''))
        await find(wrapper, 'workflow-resume').trigger('click')
        await flushPromises()
        expect(find(wrapper, 'workflow-paused-error').text()).toBe('text.workflow.guard.error.forbidden')
        expect(find(wrapper, 'workflow-resume').attributes('disabled')).toBeUndefined()
        expect(wrapper.emitted('resumed')).toBeUndefined()
    })
})

// ---------------------------------------------------------------------------------------------------------------------
// Buttons follow abilities
// ---------------------------------------------------------------------------------------------------------------------

describe('buttons follow abilities', () => {
    const versions = [
        { uuid: 'v3', number: 3, status: 'published', is_published: true, created_by_name: 'An', created_at: '2026-11-25T08:00:00', steps: 4, note: 'Third' },
        { uuid: 'v2', number: 2, status: 'superseded', is_published: false, created_by_name: 'Binh', created_at: '2026-11-24T08:00:00', steps: 3, note: null },
        { uuid: 'v4', number: 4, status: 'pending_review', is_published: false, created_by_name: 'An', created_at: '2026-11-25T08:30:00', steps: 5, note: null },
    ]
    const history = async (canPublish: boolean) => {
        ajax.get.mockResolvedValueOnce(body({ versions, draft: { differs: true, published_number: 3 } }))
        const wrapper = mount(VersionDrawer, { props: { open: true, workflow, names, canPublish }, global: { stubs } })
        await flushPromises()
        return wrapper
    }

    it('history: a viewer reads versions and differences but gets no "return to this version"', async () => {
        const wrapper = await history(false)
        expect(wrapper.findAll('[data-testid="version-row"]')).toHaveLength(3)
        expect(find(wrapper, 'version-draft-state').text()).toContain('text.workflow.guard.draft.differs_long{"number":3}')
        expect(find(wrapper, 'version-compare-2').exists()).toBe(true)
        expect(wrapper.find('[data-testid^="version-rollback-"]').exists()).toBe(false)
    })

    it('history: a publisher may return only to a version that ran before', async () => {
        const wrapper = await history(true)
        expect(find(wrapper, 'version-rollback-2').exists()).toBe(true)
        expect(find(wrapper, 'version-rollback-3').exists()).toBe(false)
        expect(find(wrapper, 'version-rollback-4').exists()).toBe(false)
    })

    it('history: the comparison is written in sentences and can be switched to another version', async () => {
        const wrapper = await history(true)
        ajax.get.mockResolvedValueOnce(body({ diff: { steps: { added: [{ step_code: 'fetch', name: 'Call the CRM' }], removed: [], changed: [] }, connections: { added: [], removed: [] }, settings: [] } }))
        await find(wrapper, 'version-compare-2').trigger('click')
        await flushPromises()
        expect(ajax.get).toHaveBeenLastCalledWith('workflows/7/versions/v2/diff', expect.objectContaining({ params: { against: 'draft' } }))
        expect(wrapper.findAll('[data-testid="version-diff-row"]').map((row) => row.text())).toEqual(['text.workflow.guard.diff.step_added{"step":"Call the CRM"}'])

        ajax.get.mockResolvedValueOnce(body({ diff: { steps: { added: [], removed: [], changed: [] }, connections: { added: [], removed: [] }, settings: [], identical: true } }))
        await find(wrapper, 'version-against').setValue('v3')
        await flushPromises()
        expect(ajax.get).toHaveBeenLastCalledWith('workflows/7/versions/v2/diff', expect.objectContaining({ params: { against: 'v3' } }))
        expect(find(wrapper, 'version-diff-same').exists()).toBe(true)
    })

    it('history: a rollback is confirmed first, a sensitive one asks for step-up, then says which path it took', async () => {
        const wrapper = await history(true)
        await find(wrapper, 'version-rollback-2').trigger('click')
        expect(ajax.post).not.toHaveBeenCalled()
        expect(confirm.last).not.toBeNull()

        ajax.post.mockRejectedValueOnce(refusal(409, 'step_up_required')).mockResolvedValueOnce(body({ number: 5, status: 'cooling_off', path: 'cooling_off', effective_at: '2026-11-25T10:30:00' }, 202))
        ajax.get.mockResolvedValue(body({ versions, draft: { differs: true, published_number: 3 } }))
        confirm.last?.onOk()
        await flushPromises()
        await find(wrapper, 'stepup-ok').trigger('click')
        await flushPromises()

        expect(ajax.post.mock.calls.map((call) => call[0])).toEqual(['workflows/7/versions/v2/rollback', 'workflows/7/versions/v2/rollback'])
        expect(ajax.post.mock.calls[1][2].headers).toEqual({ 'X-Step-Up-Grant': GRANT })
        expect(find(wrapper, 'version-notice').text()).toContain('text.workflow.guard.outcome.done.cooling_off')
        expect(wrapper.emitted('changed')).toHaveLength(1)
    })

    const pendingRows = [
        { uuid: 'p1', project_id: 7, project_name: 'Daily post', number: 4, status: 'pending_review', created_by_name: 'An', created_at: '2026-11-25T08:30:00', effective_at: null, changes: [{ kind: 'host_added', detail: 'api.example.com' }], can_approve: true, can_cancel: false },
        { uuid: 'p2', project_id: 8, project_name: 'Weekly digest', number: 2, status: 'pending_review', created_by_name: 'Me', created_at: '2026-11-25T08:40:00', effective_at: null, changes: [], can_approve: false, can_cancel: true },
        { uuid: 'p3', project_id: 9, project_name: 'Solo site', number: 6, status: 'cooling_off', created_by_name: 'Me', created_at: '2026-11-25T08:50:00', effective_at: '2099-01-01T10:00:00', changes: [{ kind: 'budget_raised' }], can_approve: false, can_cancel: true },
    ]
    const pendingDrawer = () => mount(PendingChanges, { props: { open: true, rows: pendingRows }, global: { stubs } })

    it('waiting changes: approve and reject only where the server allows, the author only cancels', () => {
        const rows = pendingDrawer().findAll('[data-testid="pending-row"]')
        expect(rows[0].find('[data-testid="pending-approve"]').exists()).toBe(true)
        expect(rows[0].find('[data-testid="pending-reject"]').exists()).toBe(true)
        expect(rows[0].find('[data-testid="pending-cancel"]').exists()).toBe(false)
        expect(rows[0].text()).toContain('api.example.com')

        expect(rows[1].find('[data-testid="pending-approve"]').exists()).toBe(false)
        expect(rows[1].find('[data-testid="pending-cancel"]').exists()).toBe(true)
        expect(rows[1].find('[data-testid="pending-own"]').text()).toBe('text.workflow.guard.pending.own_change')

        expect(rows[2].find('[data-testid="pending-when"]').text()).toContain('text.workflow.guard.pending.goes_live_at')
        expect(rows[2].find('[data-testid="pending-cancel"]').exists()).toBe(true)
    })

    it('waiting changes: approving asks for step-up first, then sends the approval with the grant', async () => {
        const wrapper = pendingDrawer()
        ajax.post.mockResolvedValueOnce(body({ number: 4, status: 'published', path: 'published' }))
        await wrapper.findAll('[data-testid="pending-approve"]')[0].trigger('click')
        await flushPromises()
        expect(find(wrapper, 'stepup').attributes('data-purpose')).toBe('workflow_publish')
        expect(ajax.post).not.toHaveBeenCalled()
        await find(wrapper, 'stepup-ok').trigger('click')
        await flushPromises()

        expect(ajax.post.mock.calls.map((call) => call[0])).toEqual(['workflows/7/versions/p1/approve'])
        expect(ajax.post.mock.calls[0][2].headers).toEqual({ 'X-Step-Up-Grant': GRANT })
        expect(find(wrapper, 'pending-notice').text()).toContain('text.workflow.guard.pending.approved')
        expect(wrapper.emitted('changed')).toHaveLength(1)
    })

    it('waiting changes: a rejection sends the optional reason', async () => {
        const wrapper = pendingDrawer()
        await wrapper.findAll('[data-testid="pending-reject"]')[0].trigger('click')
        await find(wrapper, 'pending-reason').setValue(' Unknown address ')
        ajax.post.mockResolvedValueOnce(body({ status: 'rejected' }))
        await find(wrapper, 'pending-reject-confirm').trigger('click')
        await flushPromises()
        expect(ajax.post).toHaveBeenCalledWith('workflows/7/versions/p1/reject', { reason: 'Unknown address' }, expect.objectContaining({ silent: true }))
        expect(find(wrapper, 'pending-notice').text()).toContain('text.workflow.guard.pending.rejected')
    })

    it('waiting changes: cancelling is confirmed first', async () => {
        const wrapper = pendingDrawer()
        await wrapper.findAll('[data-testid="pending-cancel"]')[0].trigger('click')
        expect(ajax.post).not.toHaveBeenCalled()
        ajax.post.mockResolvedValueOnce(body({ status: 'cancelled' }))
        await confirm.last?.onOk()
        await flushPromises()
        expect(ajax.post).toHaveBeenCalledWith('workflows/8/versions/p2/cancel', {}, expect.objectContaining({ silent: true }))
    })

    const row = (over: Record<string, unknown>) => ({ ...workflow, description: '', summary: {}, updated_at: null, created_at: null, ...over })

    it('detail card: "turn back on" follows can_publish, not the right to edit', () => {
        access.rules.add('workflow_project.update')
        const viewer = mount(DetailCard, {
            props: { workflow: row({ paused_reason: 'breaker:runs_per_hour', can_publish: false, can_run: false, published_version_id: 3, published_version_number: 2 }), canUpdate: true },
            global: { stubs },
        })
        expect(find(viewer, 'workflow-paused').exists()).toBe(true)
        expect(find(viewer, 'workflow-resume').exists()).toBe(false)
        expect(find(viewer, 'detail-version-text').text()).toBe('text.workflow.guard.draft.running_version{"number":2}')

        const publisher = mount(DetailCard, {
            props: { workflow: row({ paused_reason: 'breaker:runs_per_hour', can_publish: true, published_version_id: 3, published_version_number: 2 }) },
            global: { stubs },
        })
        expect(find(publisher, 'workflow-resume').exists()).toBe(true)
    })

    it('detail card: a workflow that was never published says so and how to publish it', () => {
        const wrapper = mount(DetailCard, { props: { workflow: row({ published_version_id: null, draft_differs: true }) }, global: { stubs } })
        expect(find(wrapper, 'detail-version-text').text()).toBe('text.workflow.guard.draft.never_published')
        expect(wrapper.text()).toContain('text.workflow.guard.draft.never_published_hint')
        expect(find(wrapper, 'detail-draft-differs').exists()).toBe(false)
    })

    it('list: tags for paused, never published, draft ahead and waiting changes', () => {
        const wrapper = mount(WorkflowList, {
            props: {
                workflows: [
                    row({ id: 1, published_version_id: 5, draft_differs: true, pending_versions: 2 }),
                    row({ id: 2, published_version_id: null }),
                    row({ id: 3, published_version_id: 6, draft_differs: false, paused_reason: 'integrity' }),
                    row({ id: 4 }),
                ],
                activationState: {},
            },
            global: { stubs },
        })
        const cards = wrapper.findAll('[data-stub="card"]')
        expect(cards[0].find('[data-testid="list-draft-differs"]').exists()).toBe(true)
        expect(cards[0].find('[data-testid="list-pending"]').text()).toContain('"count":2')
        expect(cards[1].find('[data-testid="list-never-published"]').exists()).toBe(true)
        expect(cards[2].find('[data-testid="list-paused-reason"]').text()).toBe('text.workflow.guard.paused.reason.integrity')
        expect(cards[2].find('[data-testid="list-draft-differs"]').exists()).toBe(false)
        // A server that does not send the version fields gets no guessed tag.
        expect(cards[3].find('[data-testid="list-never-published"]').exists()).toBe(false)
    })

    it('designer header: one status chip, the running version beside the actions, and waiting changes', () => {
        const state = (wrapper: any) => find(wrapper, 'builder-status').attributes('data-state')

        // A saved draft that is ahead of the running version.
        const differs = mount(BuilderHeader, { props: { draftStatus: { differs: true, published_number: 3 }, pendingCount: 1 }, global: { stubs } })
        expect(state(differs)).toBe('saved')
        expect(find(differs, 'builder-status').text()).toBe('text.workflow.canvas.status.saved')
        expect(find(differs, 'builder-running-version').text()).toContain('"number":3')
        expect(find(differs, 'builder-pending').exists()).toBe(true)

        // Edits on screen that are not stored yet win over everything else.
        const unsaved = mount(BuilderHeader, { props: { hasUnsavedChanges: true, draftStatus: { differs: false, published_number: 3 } }, global: { stubs } })
        expect(state(unsaved)).toBe('unsaved')
        expect(find(unsaved, 'builder-running-version').exists()).toBe(true)

        // The draft is what runs: the chip says so, and the version is not repeated.
        const same = mount(BuilderHeader, { props: { draftStatus: { differs: false, published_number: 3 } }, global: { stubs } })
        expect(state(same)).toBe('running')
        expect(find(same, 'builder-status').text()).toContain('"number":3')
        expect(find(same, 'builder-running-version').exists()).toBe(false)

        const fresh = mount(BuilderHeader, { props: { draftStatus: { differs: true, published_number: null } }, global: { stubs } })
        expect(state(fresh)).toBe('saved')
        expect(find(fresh, 'builder-never-published').exists()).toBe(true)
        expect(find(fresh, 'builder-running-version').exists()).toBe(false)

        const locked = mount(BuilderHeader, { props: { isReadOnly: true, hasUnsavedChanges: true }, global: { stubs } })
        expect(state(locked)).toBe('read_only')
    })

    it('designer header: save and publish follow the abilities and keep their place beside the status', async () => {
        const viewer = mount(BuilderHeader, { props: { hasSteps: true }, global: { stubs } })
        expect(find(viewer, 'builder-save').exists()).toBe(false)
        expect(find(viewer, 'builder-publish').exists()).toBe(false)

        const publisher = mount(BuilderHeader, { props: { canEdit: true, canPublish: true, hasSteps: true }, global: { stubs } })
        await find(publisher, 'builder-save').trigger('click')
        await find(publisher, 'builder-publish').trigger('click')
        await find(publisher, 'builder-review').trigger('click')
        await find(publisher, 'builder-history').trigger('click')
        expect(Object.keys(publisher.emitted())).toEqual(expect.arrayContaining(['save', 'publish', 'review', 'history']))
    })
})
