import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import StarterPacks from '@core-admin/pages/setting/starter-packs.vue'
import { applyErrorKind, hasConfiguration, matchStepMessage, modulesNeedingConfirmation } from '@/libs/starter-packs'

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const session = vi.hoisted(() => ({ webmaster: true }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('@/mixins', () => ({ iAlert: vi.fn() }))
vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => session.webmaster }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        locale: { value: 'en' },
        t: (key: string, params?: Record<string, string>) => (params ? `${key}|${Object.values(params).join(',')}` : key),
        te: () => true,
    }),
}))

const stubs = {
    'a-alert': { props: ['message', 'description'], template: '<div role="alert">{{ message }} {{ description }}<slot name="description" /><slot name="action" /></div>' },
    'a-button': { emits: ['click'], template: '<button @click="$emit(\'click\', $event)"><slot /></button>' },
    'i-button': { props: ['disabled', 'loading', 'nativeType'], emits: ['click'], template: '<button :type="nativeType || \'button\'" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>' },
    'a-drawer': { props: ['open'], template: '<div v-if="open" data-testid="drawer"><slot /></div>' },
    'a-tag': { template: '<span><slot /></span>' },
    'a-skeleton': true,
    'a-empty': true,
}

const list = [{ key: 'help-center', name: 'Help center', description: 'Docs', version: '1.0.0', modules: ['Helpdesk'], applied: false }]
const plan = (over: Record<string, unknown> = {}) => ({
    pack_key: 'help-center', pack_version: '1.0.0', has_changes: true, blockers: [], warnings: [],
    modules: [{ name: 'Helpdesk', version: '1.0.0', state: 'to_install', irreversible: ['2026_create_tickets'] }],
    config: null, roles: { created: [] }, workflows: { created: [] }, content: [],
    ...over,
})
const detail = (p: unknown, canApply = true) => ({ data: { data: { key: 'help-center', version: '1.0.0', name: 'Help center', can_apply: canApply, plan: p } } })
let current: { plan: unknown; canApply: boolean }
let history: unknown[]

const id = (w: ReturnType<typeof mount>, x: string) => w.find(`[data-testid="${x}"]`)
const open = async (w: ReturnType<typeof mount>) => {
    await id(w, 'pack-card-help-center').trigger('click')
    await flushPromises()
}
const mountPage = async () => {
    const w = mount(StarterPacks, { global: { stubs } })
    await flushPromises()
    await open(w)
    return w
}
const err = (status: number, data: Record<string, unknown>) => Object.assign(new Error('x'), { response: { status, data } })

beforeEach(() => {
    session.webmaster = true
    current = { plan: plan(), canApply: true }
    history = []
    ajaxMock.get.mockReset().mockImplementation((url: string) => {
        if (url === 'starter-packs') return Promise.resolve({ data: { data: list } })
        if (url.endsWith('/history')) return Promise.resolve({ data: { data: history } })
        return Promise.resolve(detail(current.plan, current.canApply))
    })
    ajaxMock.post.mockReset().mockResolvedValue({ data: { data: { task_uuid: 't-1', application_uuid: 'a-1' } } })
})
afterEach(() => vi.useRealTimers())

describe('starter packs apply lib', () => {
    it('sorts apply errors', () => {
        expect(applyErrorKind(err(409, { pack_version: '2.0.0' })).kind).toBe('stale')
        expect(applyErrorKind(err(409, {})).kind).toBe('busy')
        expect(applyErrorKind(err(422, { blockers: ['x'] }))).toEqual({ kind: 'blocked', blockers: ['x'] })
        expect(applyErrorKind(err(422, { irreversible: [{ module: 'A' }] })).kind).toBe('irreversible')
        expect(applyErrorKind(err(403, { message: 'Only a Webmaster can apply the configuration of this pack.' })).kind).toBe('webmaster')
        expect(applyErrorKind(err(403, { message: 'Forbidden' })).kind).toBe('forbidden')
        expect(applyErrorKind(new Error('offline')).kind).toBe('network')
    })
    it('knows step messages and leaves unknown ones', () => {
        expect(matchStepMessage('backup failed, nothing changed')?.key).toBe('step_backup_failed')
        expect(matchStepMessage('Module Helpdesk could not be enabled.')?.params.name).toBe('Helpdesk')
        expect(matchStepMessage('weird')).toBeNull()
    })
    it('derives confirmation and configuration flags', () => {
        expect(modulesNeedingConfirmation(plan())).toEqual([{ name: 'Helpdesk', migrations: ['2026_create_tickets'] }])
        expect(modulesNeedingConfirmation(plan({ modules: [{ name: 'A', state: 'enabled', irreversible: ['m'] }] }))).toEqual([])
        expect(hasConfiguration(plan())).toBe(false)
        expect(hasConfiguration(plan({ config: { sections: {} } }))).toBe(true)
    })
})

describe('starter packs apply flow', () => {
    it('hides the apply control without the apply permission', async () => {
        current.canApply = false
        const w = await mountPage()
        expect(id(w, 'pack-apply').exists()).toBe(false)
        expect(id(w, 'pack-modules').exists()).toBe(true)
    })

    it('opens a confirmation that names the backup and each irreversible migration, and sends nothing yet', async () => {
        const w = await mountPage()
        await id(w, 'pack-apply').trigger('click')
        expect(id(w, 'apply-confirm-backup').text()).toContain('text.starter_pack.confirm_backup')
        expect(id(w, 'apply-confirm-steps').text()).toContain('confirm_step_backup')
        expect(id(w, 'apply-confirm-irreversible').text()).toContain('2026_create_tickets')
        expect(ajaxMock.post).not.toHaveBeenCalled()
    })

    it('needs the explicit confirmation of irreversible changes, then sends version and confirm flag once', async () => {
        const w = await mountPage()
        await id(w, 'pack-apply').trigger('click')
        expect(id(w, 'apply-start').attributes('disabled')).toBeDefined()
        await id(w, 'apply-confirm-understood').setValue(true)
        expect(id(w, 'apply-start').attributes('disabled')).toBeUndefined()
        const form = id(w, 'apply-confirm')
        void form.trigger('submit')
        void form.trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
        expect(ajaxMock.post).toHaveBeenCalledWith('starter-packs/help-center/apply', { version: '1.0.0', confirm_irreversible: true }, { silent: true })
        expect(id(w, 'drawer').exists()).toBe(false)
        expect(id(w, 'apply-started').exists()).toBe(true)
        w.unmount()
    })

    it('needs no extra confirmation when nothing is irreversible', async () => {
        current.plan = plan({ modules: [{ name: 'Helpdesk', state: 'to_enable', irreversible: [] }] })
        const w = await mountPage()
        await id(w, 'pack-apply').trigger('click')
        expect(id(w, 'apply-confirm-irreversible').exists()).toBe(false)
        await id(w, 'apply-confirm').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledWith('starter-packs/help-center/apply', { version: '1.0.0' }, { silent: true })
        w.unmount()
    })

    it('a configuration pack is disabled for a non-Webmaster with the reason shown', async () => {
        session.webmaster = false
        current.plan = plan({ config: { sections: { settings: { counts: { create: 1, update: 0 } } } } })
        const w = await mountPage()
        expect(id(w, 'pack-apply').attributes('disabled')).toBeDefined()
        expect(id(w, 'pack-apply-reason').text()).toContain('text.starter_pack.reason_webmaster')
    })

    it('a configuration pack is enabled for a Webmaster', async () => {
        current.plan = plan({ config: { sections: {} } })
        const w = await mountPage()
        expect(id(w, 'pack-apply').attributes('disabled')).toBeUndefined()
    })

    it('a blocked pack disables apply and says why', async () => {
        current.plan = plan({ blockers: ['Pack version 1.0.0 is older than version 2.0.0 already applied to this site.'] })
        const w = await mountPage()
        expect(id(w, 'pack-apply').attributes('disabled')).toBeDefined()
        expect(id(w, 'pack-apply-reason').text()).toContain('reason_blocked')
    })

    it.each([
        [409, { message: 'x', pack_version: '2.0.0' }, 'error_stale'],
        [409, { message: 'Another starter pack is being applied' }, 'error_busy'],
        [403, { message: 'Only a Webmaster can apply the configuration of this pack.' }, 'error_webmaster'],
        [404, { message: 'Starter pack not found.' }, 'error_missing'],
    ])('shows a plain message for %s', async (status, data, key) => {
        ajaxMock.post.mockRejectedValue(err(status as number, data as Record<string, unknown>))
        const w = await mountPage()
        await id(w, 'pack-apply').trigger('click')
        await id(w, 'apply-confirm-understood').setValue(true)
        await id(w, 'apply-confirm').trigger('submit')
        await flushPromises()
        expect(id(w, 'apply-error').text()).toContain(`text.starter_pack.${key}`)
        expect(id(w, 'apply-error').text()).not.toContain('Another starter pack is being applied')
        expect(id(w, 'drawer').exists()).toBe(true)
    })

    it('lists the 422 blockers in plain words and reloads the plan', async () => {
        ajaxMock.post.mockRejectedValue(err(422, { message: 'The starter pack cannot be applied.', blockers: ['Pack version 1.0.0 is older than version 2.0.0 already applied to this site.'] }))
        const w = await mountPage()
        const before = ajaxMock.get.mock.calls.length
        await id(w, 'pack-apply').trigger('click')
        await id(w, 'apply-confirm-understood').setValue(true)
        await id(w, 'apply-confirm').trigger('submit')
        await flushPromises()
        expect(id(w, 'apply-error').text()).toContain('text.starter_pack.error_blocked')
        expect(id(w, 'apply-error').text()).toContain('text.starter_pack.blocker_older')
        expect(ajaxMock.get.mock.calls.length).toBeGreaterThan(before)
        // A failed request lets the operator send again.
        expect(id(w, 'apply-start').exists()).toBe(true)
    })
})

describe('starter packs apply progress', () => {
    const step = (name: string, status: string, message: string | null = null, counts: Record<string, number> = {}) => ({ name, status, message, counts })
    const startApply = async (w: ReturnType<typeof mount>) => {
        await id(w, 'pack-apply').trigger('click')
        await id(w, 'apply-confirm-understood').setValue(true)
        await id(w, 'apply-confirm').trigger('submit')
        await flushPromises()
    }

    it('polls only while the apply runs, shows the result and leaves no timer behind', async () => {
        vi.useFakeTimers()
        const w = await mountPage()
        history = [{ uuid: 'a-1', status: 'started', steps: [step('preflight', 'succeeded'), step('backup', 'pending')] }]
        await startApply(w)
        await vi.advanceTimersByTimeAsync(1600)
        expect(id(w, 'apply-started').exists()).toBe(true)
        expect(id(w, 'apply-step-preflight').exists()).toBe(true)
        expect(id(w, 'apply-step-backup').exists()).toBe(false)
        history = [{ uuid: 'a-1', status: 'succeeded', steps: [step('preflight', 'succeeded'), step('backup', 'succeeded', 'Safety backup taken.'), step('modules', 'succeeded', null, { installed: 1 })] }]
        await vi.advanceTimersByTimeAsync(4100)
        expect(id(w, 'apply-result-ok').text()).toContain('text.starter_pack.result_backup_taken')
        expect(id(w, 'apply-step-modules').text()).toContain('text.starter_pack.count_installed')
        expect(id(w, 'apply-step-backup').text()).not.toContain('Safety backup taken.')
        expect(vi.getTimerCount()).toBe(0)
        w.unmount()
    })

    it('explains settings that were not added instead of a bare blocked count', async () => {
        vi.useFakeTimers()
        const w = await mountPage()
        history = [{ uuid: 'a-1', status: 'started', steps: [step('preflight', 'succeeded')] }]
        await startApply(w)
        await vi.advanceTimersByTimeAsync(1600)
        history = [{ uuid: 'a-1', status: 'succeeded', steps: [step('config', 'succeeded', null, { created: 2, blocked: 45 }), step('modules', 'succeeded', null, { installed: 1 })] }]
        await vi.advanceTimersByTimeAsync(4100)
        expect(id(w, 'apply-step-config').text()).toContain('text.starter_pack.count_blocked')
        expect(id(w, 'apply-step-config-blocked').text()).toContain('text.starter_pack.step_blocked_hint')
        expect(id(w, 'apply-step-modules').find('[data-testid="apply-step-modules-blocked"]').exists()).toBe(false)
        w.unmount()
    })

    it('never leaves a timer after unmount while still running', async () => {
        vi.useFakeTimers()
        const w = await mountPage()
        history = [{ uuid: 'a-1', status: 'started', steps: [] }]
        await startApply(w)
        await vi.advanceTimersByTimeAsync(1600)
        expect(vi.getTimerCount()).toBeGreaterThan(0)
        w.unmount()
        expect(vi.getTimerCount()).toBe(0)
    })

    it('a failed backup says nothing was changed and never shows the English message', async () => {
        vi.useFakeTimers()
        const w = await mountPage()
        history = [{ uuid: 'a-1', status: 'failed', steps: [step('preflight', 'succeeded'), step('backup', 'failed', 'backup failed, nothing changed'), step('modules', 'not_run')] }]
        await startApply(w)
        await vi.advanceTimersByTimeAsync(1600)
        const failed = id(w, 'apply-result-failed')
        expect(failed.text()).toContain('result_nothing_changed')
        expect(id(w, 'apply-step-backup').text()).toContain('text.starter_pack.step_backup_failed')
        expect(w.text()).not.toContain('backup failed, nothing changed')
        w.unmount()
    })

    it('an unknown failure message becomes a generic sentence', async () => {
        vi.useFakeTimers()
        const w = await mountPage()
        history = [{ uuid: 'a-1', status: 'failed', steps: [step('backup', 'succeeded'), step('config', 'failed', 'Some SQL exploded')] }]
        await startApply(w)
        await vi.advanceTimersByTimeAsync(1600)
        expect(id(w, 'apply-step-config').text()).toContain('step_failed_generic')
        expect(w.text()).not.toContain('Some SQL exploded')
        expect(id(w, 'apply-result-failed').text()).toContain('result_failed_backup')
        w.unmount()
    })

    it('stops watching after repeated read failures and points at the task bar', async () => {
        vi.useFakeTimers()
        const w = await mountPage()
        await startApply(w)
        ajaxMock.get.mockImplementation((url: string) => (url.endsWith('/history') ? Promise.reject(new Error('x')) : Promise.resolve(detail(current.plan))))
        await vi.advanceTimersByTimeAsync(20000)
        expect(id(w, 'apply-result-unknown').exists()).toBe(true)
        expect(vi.getTimerCount()).toBe(0)
        w.unmount()
    })
})
