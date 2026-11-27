import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import CoreUpdateCard from '@core-admin/pages/setting/modules/core-update-card.vue'

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
const reloadMock = vi.hoisted(() => ({ reloadAdminBuild: vi.fn(() => Promise.resolve()) }))
vi.mock('@core-admin/pages/setting/modules/admin-reload', () => reloadMock)
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k) }),
}))

const status = (over: Record<string, unknown> = {}) => ({
    installed: '2026.11.0',
    latest: '2026.12.0',
    notes_url: 'https://example.test/notes',
    can_update: true,
    preflight: { ok: true, issues: [] },
    running: null,
    command: './ispa upgrade',
    ...over,
})
const answer = (body: Record<string, unknown>) => ajaxMock.get.mockResolvedValue({ data: { data: body } })

const passthrough = (tag = 'div') => defineComponent({ setup: (_p, { slots, attrs }) => () => h(tag, attrs, slots.default?.()) })
const stubs = {
    'a-skeleton': true,
    'a-alert': defineComponent({ props: ['message'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }, p.message) }),
    'a-progress': defineComponent({ props: ['percent'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }, String(p.percent)) }),
    'a-drawer': defineComponent({ props: ['open'], setup: (p, { slots }) => () => (p.open ? h('div', slots.default?.()) : null) }),
    'i-button': defineComponent({
        props: ['disabled', 'loading', 'nativeType'],
        emits: ['click'],
        setup: (p, { slots, emit, attrs }) => () =>
            h('button', { type: p.nativeType || 'button', disabled: p.disabled || p.loading, 'data-testid': attrs['data-testid'], onClick: () => emit('click') }, slots.default?.()),
    }),
}
const mountCard = async () => {
    const wrapper = mount(CoreUpdateCard, { global: { mocks: { $t: (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k) }, stubs } })
    await flushPromises()
    return wrapper
}

describe('core update card', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        vi.useRealTimers()
    })

    const stateOf = (w: Awaited<ReturnType<typeof mountCard>>) => w.get('[data-testid="core-update-card"]').attributes('data-state')

    it('a new version stands out: both versions, the "new version" badge, the notes link and the update button', async () => {
        answer(status())
        const w = await mountCard()
        expect(ajaxMock.get).toHaveBeenCalledWith('core-update', expect.anything())
        expect(stateOf(w)).toBe('available')
        expect(w.get('[data-testid="core-update-title"]').text()).toBe('text.modules.core_update.title')
        // Each version has its own label, so the two numbers are never read as one.
        const versions = w.get('[data-testid="core-update-versions"]').text()
        expect(versions).toContain('text.modules.core_update.installed')
        expect(versions).toContain('text.modules.core_update.latest')
        expect(w.get('[data-testid="core-update-installed"]').text()).toBe('2026.11.0')
        expect(w.get('[data-testid="core-update-latest"]').text()).toBe('2026.12.0')
        expect(w.get('[data-testid="core-update-newer"]').text()).toBe('text.modules.core_update.newer|{"version":"2026.12.0"}')
        // The state is told by an icon and words too, never by colour alone.
        expect(w.get('[data-testid="core-update-icon"]').attributes('aria-hidden')).toBe('true')
        expect(w.find('[data-testid="core-update-up-to-date"]').exists()).toBe(false)
        expect(w.get('[data-testid="core-update-notes"]').attributes('href')).toBe('https://example.test/notes')
        expect(w.get('[data-testid="core-update-open"]').attributes('disabled')).toBeUndefined()
        expect(w.find('[data-testid="core-update-blocked"]').exists()).toBe(false)
        // Nothing to report under the header.
        expect(w.find('[data-testid="core-update-body"]').exists()).toBe(false)
    })

    it('calm when up to date: one version, the "up to date" badge, the button off', async () => {
        answer(status({ installed: '2026.12.0', can_update: false }))
        const w = await mountCard()
        expect(stateOf(w)).toBe('neutral')
        expect(w.get('[data-testid="core-update-installed"]').text()).toBe('2026.12.0')
        expect(w.get('[data-testid="core-update-up-to-date"]').text()).toBe('text.modules.core_update.up_to_date')
        expect(w.find('[data-testid="core-update-newer"]').exists()).toBe(false)
        expect(w.find('[data-testid="core-update-latest"]').exists()).toBe(false)
        expect(w.get('[data-testid="core-update-open"]').attributes('disabled')).toBeDefined()
        expect(w.find('[data-testid="core-update-body"]').exists()).toBe(false)
    })

    it('the version alone when the hub does not know the newest one', async () => {
        answer(status({ latest: null, notes_url: null, can_update: false }))
        const w = await mountCard()
        expect(stateOf(w)).toBe('neutral')
        expect(w.get('[data-testid="core-update-installed"]').text()).toBe('2026.11.0')
        for (const id of ['newer', 'up-to-date', 'latest', 'notes', 'state']) {
            expect(w.find(`[data-testid="core-update-${id}"]`).exists()).toBe(false)
        }
        expect(w.text()).not.toContain('latest_unknown')
    })

    it('queued: the in-progress state, a live waiting line and the note that the page updates by itself', async () => {
        answer(status({ can_update: false, running: { uuid: 'u1', progress: 0, status: 'queued' } }))
        const w = await mountCard()
        expect(stateOf(w)).toBe('progress')
        expect(w.get('[data-testid="core-update-state"]').text()).toBe('text.modules.core_update.badge_progress')
        const line = w.get('[data-testid="core-update-waiting"]')
        expect(line.text()).toBe('text.modules.core_update.waiting')
        expect(line.attributes('role')).toBe('status')
        expect(line.attributes('aria-live')).toBe('polite')
        expect(w.find('[data-testid="core-update-waiting-bar"]').exists()).toBe(true)
        expect(w.find('[data-testid="core-update-progress"]').exists()).toBe(false)
        expect(w.get('[data-testid="core-update-auto-refresh"]').text()).toBe('text.modules.core_update.auto_refresh')
        // The version it is going to stays in view while it waits.
        expect(w.get('[data-testid="core-update-latest"]').text()).toBe('2026.12.0')
        expect(w.find('[data-testid="core-update-open"]').exists()).toBe(false)
    })

    it('a refused update with a new version waiting is raised; with nothing to update it stays calm', async () => {
        const preflight = { ok: false, issues: [{ code: 'CORE_DISK', message: 'raw' }] }
        answer(status({ can_update: false, preflight }))
        const refused = await mountCard()
        expect(stateOf(refused)).toBe('warning')
        expect(refused.get('[data-testid="core-update-state"]').text()).toBe('text.modules.core_update.badge_blocked')
        expect(refused.findAll('[data-testid="core-update-issue"]')).toHaveLength(1)

        answer(status({ installed: '2026.12.0', can_update: false, preflight }))
        const calm = await mountCard()
        expect(stateOf(calm)).toBe('neutral')
        expect(calm.get('[data-testid="core-update-up-to-date"]').text()).toBe('text.modules.core_update.up_to_date')
        // The reason is still listed.
        expect(calm.findAll('[data-testid="core-update-issue"]')).toHaveLength(1)

        // Old files waiting to be restored are raised even when the version is the newest.
        answer(status({ installed: '2026.12.0', can_update: false, can_restore: true, preflight: { ok: false, issues: [{ code: 'CORE_UPGRADE_INTERRUPTED', message: 'raw' }] } }))
        expect(stateOf(await mountCard())).toBe('warning')
    })

    it('a status that cannot be read is an error with a retry', async () => {
        ajaxMock.get.mockRejectedValue(new Error('down'))
        const w = await mountCard()
        expect(stateOf(w)).toBe('danger')
        const error = w.get('[data-testid="core-update-error"]')
        expect(error.attributes('role')).toBe('alert')
        expect(error.text()).toContain('text.modules.core_update.load_failed')
        expect(w.find('[data-testid="core-update-retry"]').exists()).toBe(true)
        expect(w.find('[data-testid="core-update-open"]').exists()).toBe(false)
    })

    it('refresh() asks the hub for the newest version; the mount and the poll never do', async () => {
        vi.useFakeTimers()
        answer(status({ can_update: false, running: { uuid: 'u1', progress: 10, status: 'running' } }))
        const w = await mountCard()
        await vi.advanceTimersByTimeAsync(5000)
        await flushPromises()
        // Mount + one poll: neither carries the refresh flag.
        expect(ajaxMock.get.mock.calls.filter(([url]) => url === 'core-update')).toHaveLength(2)
        for (const [, config] of ajaxMock.get.mock.calls) expect(config?.params).toBeUndefined()
        w.unmount()
        vi.useRealTimers()

        vi.clearAllMocks()
        answer(status({ latest: '2026.12.0' }))
        const card = await mountCard()
        expect(card.get('[data-testid="core-update-latest"]').text()).toBe('2026.12.0')
        answer(status({ latest: '2026.12.1' }))
        await (card.vm as unknown as { refresh: () => Promise<void> }).refresh()
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenLastCalledWith('core-update', expect.objectContaining({ silent: true, params: { refresh: 1 } }))
        expect(card.get('[data-testid="core-update-latest"]').text()).toBe('2026.12.1')
        expect(card.get('[data-testid="core-update-newer"]').text()).toBe('text.modules.core_update.newer|{"version":"2026.12.1"}')
        // What is on screen never gives way to a skeleton during the refresh, and a failed one keeps it.
        ajaxMock.get.mockRejectedValue(new Error('down'))
        await (card.vm as unknown as { refresh: () => Promise<void> }).refresh()
        await flushPromises()
        expect(card.get('[data-testid="core-update-latest"]').text()).toBe('2026.12.1')
        expect(card.find('[data-testid="core-update-error"]').exists()).toBe(false)
    })

    /** Mount on a running task, then let the poll find it gone and the task read as `task`. */
    const finishWith = async (task: Record<string, unknown>, after: Record<string, unknown> = { installed: '2026.12.0', can_update: false }) => {
        vi.useFakeTimers()
        answer(status({ can_update: false, running: { uuid: 'u9', progress: 90, status: 'running' } }))
        const w = await mountCard()
        ajaxMock.get.mockImplementation((url: string) =>
            Promise.resolve({ data: { data: url === 'background-tasks/u9' ? { uuid: 'u9', ...task } : status(after) } }),
        )
        await vi.advanceTimersByTimeAsync(5000)
        await flushPromises()
        return w
    }

    it('after an update this page watched succeed, the admin reloads itself after a 5 s countdown', async () => {
        const w = await finishWith({ status: 'succeeded', result: { to: '2026.12.0' } })
        expect(w.get('[data-testid="core-update-reload-text"]').text()).toBe('text.modules.core_update.reload_countdown|{"seconds":5}')
        // Said once to a screen reader: the live sentence keeps its first number while the visible one counts down.
        const live = w.get('[data-testid="core-update-reload-live"]')
        expect(live.attributes('role')).toBe('status')
        expect(live.text()).toBe('text.modules.core_update.reload_countdown|{"seconds":5}')
        expect(w.get('[data-testid="core-update-reload-text"]').attributes('aria-hidden')).toBe('true')
        expect(w.find('[data-testid="core-update-reload-now"]').exists()).toBe(true)
        expect(w.find('[data-testid="core-update-reload-later"]').exists()).toBe(true)

        await vi.advanceTimersByTimeAsync(2000)
        expect(w.get('[data-testid="core-update-reload-text"]').text()).toBe('text.modules.core_update.reload_countdown|{"seconds":3}')
        expect(live.text()).toBe('text.modules.core_update.reload_countdown|{"seconds":5}')
        expect(reloadMock.reloadAdminBuild).not.toHaveBeenCalled()

        await vi.advanceTimersByTimeAsync(3000)
        expect(reloadMock.reloadAdminBuild).toHaveBeenCalledTimes(1)
        // Nothing fires twice while the reload is on its way.
        await vi.advanceTimersByTimeAsync(5000)
        expect(reloadMock.reloadAdminBuild).toHaveBeenCalledTimes(1)
    })

    it('"reload now" reloads at once and only once', async () => {
        const w = await finishWith({ status: 'succeeded', result: { to: '2026.12.0' } })
        await w.get('[data-testid="core-update-reload-now"]').trigger('click')
        await w.get('[data-testid="core-update-reload-now"]').trigger('click')
        await vi.advanceTimersByTimeAsync(10000)
        expect(reloadMock.reloadAdminBuild).toHaveBeenCalledTimes(1)
    })

    it('"later" stops the countdown and leaves a plain reload button', async () => {
        const w = await finishWith({ status: 'succeeded', result: { to: '2026.12.0' } })
        await w.get('[data-testid="core-update-reload-later"]').trigger('click')
        await vi.advanceTimersByTimeAsync(20000)
        expect(reloadMock.reloadAdminBuild).not.toHaveBeenCalled()
        expect(w.find('[data-testid="core-update-reload-text"]').exists()).toBe(false)
        expect(w.find('[data-testid="core-update-reload-now"]').exists()).toBe(false)
        expect(w.get('[data-testid="core-update-reload-hint"]').text()).toBe('text.modules.core_update.reload_hint')
        await w.get('[data-testid="core-update-reload-page"]').trigger('click')
        expect(reloadMock.reloadAdminBuild).toHaveBeenCalledTimes(1)
    })

    it('never reloads for a failed task, a restore, or a page that only loads an already finished update', async () => {
        const failed = await finishWith({ status: 'failed', message_params: { code: 'CORE_RUNNER_DIED' }, result: null }, { can_update: true })
        await vi.advanceTimersByTimeAsync(20000)
        expect(failed.find('[data-testid="core-update-reload"]').exists()).toBe(false)
        failed.unmount()

        // Old files put back: the outcome shows, the admin is not reloaded.
        const restored = await finishWith({ status: 'succeeded', result: { restored: true } }, { can_update: true })
        await vi.advanceTimersByTimeAsync(20000)
        expect(restored.get('[data-testid="core-update-result"]').text()).toBe('text.modules.core_update.result.restored')
        expect(restored.find('[data-testid="core-update-reload"]').exists()).toBe(false)
        restored.unmount()

        // A fresh page on an up-to-date site: no task was watched here.
        vi.clearAllMocks()
        answer(status({ installed: '2026.12.0', can_update: false }))
        const fresh = await mountCard()
        await vi.advanceTimersByTimeAsync(20000)
        expect(fresh.find('[data-testid="core-update-reload"]').exists()).toBe(false)
        expect(ajaxMock.get.mock.calls.every(([url]) => url === 'core-update')).toBe(true)
        expect(reloadMock.reloadAdminBuild).not.toHaveBeenCalled()
    })

    it('leaving the page stops the countdown', async () => {
        const w = await finishWith({ status: 'succeeded', result: { to: '2026.12.0' } })
        w.unmount()
        await vi.advanceTimersByTimeAsync(20000)
        expect(reloadMock.reloadAdminBuild).not.toHaveBeenCalled()
    })

    it('a finished update is a success state', async () => {
        vi.useFakeTimers()
        answer(status({ can_update: false, running: { uuid: 'u3', progress: 90, status: 'running' } }))
        const w = await mountCard()
        ajaxMock.get.mockImplementation((url: string) =>
            Promise.resolve({
                data: {
                    data: url === 'background-tasks/u3'
                        ? { uuid: 'u3', status: 'succeeded', result: { to: '2026.12.0' } }
                        : status({ installed: '2026.12.0', can_update: false }),
                },
            }),
        )
        await vi.advanceTimersByTimeAsync(5000)
        await flushPromises()
        expect(stateOf(w)).toBe('success')
        // The new installed version shows without a page reload, and nothing newer is offered any more.
        expect(w.get('[data-testid="core-update-installed"]').text()).toBe('2026.12.0')
        expect(w.find('[data-testid="core-update-latest"]').exists()).toBe(false)
        expect(w.get('[data-testid="core-update-state"]').text()).toBe('text.modules.core_update.badge_done')
        expect(w.get('[data-testid="core-update-result"]').text()).toBe('text.modules.core_update.result.updated|{"version":"2026.12.0"}')
        expect(w.find('[data-testid="core-update-activity"]').exists()).toBe(false)
    })

    it('a managed site shows only the title and the managed sentence', async () => {
        answer(status({
            managed: true,
            installed: '2026.3.0',
            latest: '2026.12.0',
            can_update: false,
            preflight: { ok: false, issues: [{ code: 'CORE_NO_MANIFEST', message: 'raw' }] },
        }))
        const w = await mountCard()
        expect(w.get('[data-testid="core-update-title"]').text()).toBe('text.modules.core_update.title')
        expect(w.get('[data-testid="core-update-managed"]').text()).toBe('text.modules.core_update.managed')
        expect(w.get('[data-testid="core-update-card"]').attributes('data-state')).toBe('neutral')
        for (const id of ['installed', 'latest', 'versions', 'newer', 'up-to-date', 'state', 'notes', 'open', 'blocked', 'command', 'issue', 'body']) {
            expect(w.find(`[data-testid="core-update-${id}"]`).exists()).toBe(false)
        }
        expect(w.text()).not.toContain('2026.3.0')
    })

    it('a refusal for modules updated on their own names them', async () => {
        vi.useFakeTimers()
        answer(status({ can_update: false, running: { uuid: 'u2', progress: 20, status: 'running' } }))
        const w = await mountCard()
        ajaxMock.get.mockImplementation((url: string) =>
            Promise.resolve({
                data: {
                    data: url === 'background-tasks/u2'
                        ? { uuid: 'u2', status: 'failed', message: 'text.modules.core_update.needs_module_update', message_params: { code: 'CORE_MODULE_NEEDS_UPDATE', modules: 'Content' }, result: null }
                        : status({ can_update: true }),
                },
            }),
        )
        await vi.advanceTimersByTimeAsync(5000)
        await flushPromises()
        expect(w.get('[data-testid="core-update-result"]').text()).toBe('text.modules.core_update.needs_module_update|{"modules":"Content"}')
    })

    it('disables the button, lists the issues and shows the command when the update is blocked', async () => {
        answer(status({
            can_update: false,
            preflight: { ok: false, issues: [{ code: 'CORE_MODIFIED', message: 'raw', files: ['a.php'] }, { code: 'CORE_DISK', message: 'raw' }] },
        }))
        const w = await mountCard()
        expect(w.get('[data-testid="core-update-open"]').attributes('disabled')).toBeDefined()
        const issues = w.findAll('[data-testid="core-update-issue"]')
        expect(issues).toHaveLength(2)
        expect(issues[0].text()).toContain('text.modules.core_update.issue.CORE_MODIFIED')
        expect(issues[0].text()).toContain('a.php')
        expect(issues[0].text()).not.toContain('CORE_MODIFIED|')
        expect(w.get('[data-testid="core-update-command"]').text()).toBe('./ispa upgrade')
    })

    it('needs the backup checkbox before the upgrade is queued', async () => {
        answer(status())
        ajaxMock.post.mockImplementation(() => {
            answer(status({ can_update: false, running: { uuid: 'u1', progress: 0, status: 'queued' } }))
            return Promise.resolve({ data: { data: { background_task_uuid: 'u1' } } })
        })
        const w = await mountCard()
        await w.get('[data-testid="core-update-open"]').trigger('click')
        expect(w.get('[data-testid="core-update-start"]').attributes('disabled')).toBeDefined()
        await w.get('[data-testid="core-update-form"]').trigger('submit')
        expect(ajaxMock.post).not.toHaveBeenCalled()

        await w.get('[data-testid="core-update-backup"]').setValue(true)
        expect(w.get('[data-testid="core-update-start"]').attributes('disabled')).toBeUndefined()
        await w.get('[data-testid="core-update-form"]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
        expect(ajaxMock.post).toHaveBeenCalledWith('core-update', { version: '2026.12.0', confirm_backup: true }, expect.anything())
        // A queued request waits for the runner: the waiting text, not the progress bar.
        expect(w.find('[data-testid="core-update-waiting"]').exists()).toBe(true)
        expect(w.find('[data-testid="core-update-progress"]').exists()).toBe(false)
    })

    it('shows the runner setup line with a copy button instead of the command hint', async () => {
        const line = '* * * * * cd /srv/site && php ispa upgrade --run-requested >/dev/null 2>&1'
        answer(status({
            can_update: false,
            preflight: { ok: false, issues: [{ code: 'CORE_NO_RUNNER', message: 'raw /srv/site' }] },
            runner: null,
            runner_setup: { user: 'deploy', line },
        }))
        const writeText = vi.fn().mockResolvedValue(undefined)
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
        const w = await mountCard()
        const setup = w.get('[data-testid="core-update-runner-setup"]')
        expect(setup.text()).toContain('"user":"deploy"')
        expect(w.get('[data-testid="core-update-setup-line"]').text()).toBe(line)
        expect(w.find('[data-testid="core-update-command"]').exists()).toBe(false)
        expect(w.text()).not.toContain('raw /srv/site')
        await w.get('[data-testid="core-update-copy"]').trigger('click')
        await flushPromises()
        expect(writeText).toHaveBeenCalledWith(line)
        expect(w.get('[data-testid="core-update-copy"]').text()).toContain('action.copied')
    })

    const noRunner = (issues: Array<Record<string, unknown>> = []) =>
        status({
            can_update: false,
            preflight: { ok: false, issues: [{ code: 'CORE_NO_RUNNER', message: 'raw' }, ...issues] },
            runner: null,
            runner_setup: { user: 'deploy', line: "* * * * * cd '/srv/site' && php ispa upgrade --run-requested >/dev/null 2>&1", base: '/srv/site' },
        })
    const promptParams = (text: string, key: string) => {
        const part = text.split(`text.modules.core_update.agent_prompt.${key}|`)[1]
        return part === undefined ? null : JSON.parse(part.split('\n\n')[0])
    }

    it('the agent prompt is collapsed and carries base, user, line and the limits', async () => {
        answer(noRunner())
        const w = await mountCard()
        const block = w.get('[data-testid="core-update-agent-prompt"]')
        expect(block.element.tagName).toBe('DETAILS')
        expect(block.attributes('open')).toBeUndefined()
        expect(block.get('summary').text()).toContain('text.modules.core_update.agent_prompt.title')
        const text = w.get('[data-testid="core-update-prompt-text"]').text()
        expect(promptParams(text, 'body')).toEqual({
            base: '/srv/site',
            user: 'deploy',
            line: "* * * * * cd '/srv/site' && php ispa upgrade --run-requested >/dev/null 2>&1",
        })
        expect(promptParams(text, 'intro')).toEqual({ base: '/srv/site', user: 'deploy' })
        expect(text).toContain('text.modules.core_update.agent_prompt.limits')
        expect(text).not.toContain('agent_prompt.modified')
        expect(text).not.toContain('agent_prompt.code_dir_shared')
    })

    it('a code owner of root gets the plain reason instead of the setup line and no prompt', async () => {
        answer(status({
            can_update: false,
            preflight: { ok: false, issues: [{ code: 'CORE_NO_RUNNER', message: 'raw' }] },
            runner: null,
            runner_setup: { user: 'root', line: '* * * * * cd /srv/site && php ispa upgrade --run-requested', base: '/srv/site', root_owner: true },
        }))
        const w = await mountCard()
        expect(w.get('[data-testid="core-update-root-owner"]').text()).toBe('text.modules.core_update.runner_setup_root')
        expect(w.find('[data-testid="core-update-runner-setup"]').exists()).toBe(false)
        expect(w.find('[data-testid="core-update-setup-line"]').exists()).toBe(false)
        expect(w.find('[data-testid="core-update-agent-prompt"]').exists()).toBe(false)
        expect(w.find('[data-testid="core-update-command"]').exists()).toBe(false)
    })

    it('a code owner of root gets one chown command for the web account, copyable, and a check-again button', async () => {
        answer(status({
            can_update: false,
            preflight: { ok: false, issues: [{ code: 'CORE_NO_RUNNER', message: 'raw' }] },
            runner: null,
            runner_setup: { user: 'root', line: 'x', base: '/srv/my site', root_owner: true },
            web_user: 'www-data',
        }))
        const writeText = vi.fn().mockResolvedValue(undefined)
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
        const w = await mountCard()
        const command = "sudo chown -R www-data:www-data '/srv/my site'"
        expect(w.get('[data-testid="core-update-owner-fix"]').text()).toContain('text.modules.core_update.fix_owner|{"user":"www-data"}')
        expect(w.get('[data-testid="core-update-owner-command"]').text()).toBe(command)
        await w.get('[data-testid="core-update-copy-owner"]').trigger('click')
        await flushPromises()
        expect(writeText).toHaveBeenCalledWith(command)
        expect(w.get('[data-testid="core-update-copy-owner"]').text()).toContain('action.copied')

        // Fixed on the server: "Check again" reads the card once more.
        answer(status({ can_update: true, runner_setup: { user: 'www-data', line: 'x', base: '/srv/my site', root_owner: false }, web_user: 'www-data' }))
        await w.get('[data-testid="core-update-recheck"]').trigger('click')
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenCalledTimes(2)
        expect(w.find('[data-testid="core-update-blocked"]').exists()).toBe(false)
        expect(w.get('[data-testid="core-update-open"]').attributes('disabled')).toBeUndefined()
    })

    it('another code owner with no runner gets the chown command and still the crontab line', async () => {
        answer({ ...noRunner(), web_user: 'www-data' })
        const w = await mountCard()
        expect(w.get('[data-testid="core-update-owner-command"]').text()).toBe('sudo chown -R www-data:www-data /srv/site')
        expect(w.find('[data-testid="core-update-setup-line"]').exists()).toBe(true)
    })

    it('no chown command while a runner of a separate code owner works, or without the web account', async () => {
        answer(status({
            can_update: false,
            preflight: { ok: false, issues: [{ code: 'CORE_CODE_DIR_SHARED', message: 'raw' }] },
            runner: { seen_at: '2026-11-22T10:00:00+07:00', user: 'deploy' },
            runner_setup: { user: 'deploy', line: 'x', base: '/srv/site', root_owner: false },
            web_user: 'www-data',
        }))
        let w = await mountCard()
        expect(w.find('[data-testid="core-update-owner-fix"]').exists()).toBe(false)
        expect(w.find('[data-testid="core-update-recheck"]').exists()).toBe(true)
        answer(noRunner())
        w = await mountCard()
        expect(w.find('[data-testid="core-update-owner-fix"]').exists()).toBe(false)
    })

    it('a shared code folder gets its own text, its prompt section and no command hint', async () => {
        answer(status({
            can_update: false,
            preflight: { ok: false, issues: [{ code: 'CORE_CODE_DIR_SHARED', message: 'raw' }, { code: 'CORE_DISK', message: 'raw' }] },
            runner: { seen_at: '2026-11-22T10:00:00+07:00', user: 'deploy' },
            runner_setup: { user: 'deploy', line: 'x', base: '/srv/site', root_owner: false },
        }))
        const w = await mountCard()
        expect(w.findAll('[data-testid="core-update-issue"]')[0].text()).toContain('text.modules.core_update.issue.CORE_CODE_DIR_SHARED')
        expect(w.find('[data-testid="core-update-command"]').exists()).toBe(false)
        const text = w.get('[data-testid="core-update-prompt-text"]').text()
        expect(promptParams(text, 'code_dir_shared')).toEqual({ base: '/srv/site' })
        // The runner already works: no crontab section.
        expect(text).not.toContain('agent_prompt.body')
    })

    it('the prompt adds the modified-files section only with CORE_MODIFIED', async () => {
        const files = ['app/A.php', 'app/B.php', 'app/C.php', 'app/D.php', 'app/E.php', 'app/F.php']
        answer(noRunner([{ code: 'CORE_MODIFIED', message: 'raw', files }]))
        const w = await mountCard()
        const text = w.get('[data-testid="core-update-prompt-text"]').text()
        expect(promptParams(text, 'modified')).toEqual({ base: '/srv/site', files: files.join('\n') })
    })

    it('no command hint while the runner setup is shown, even with CORE_MODIFIED', async () => {
        answer(noRunner([{ code: 'CORE_MODIFIED', message: 'raw', files: ['a.php'] }, { code: 'CORE_DISK', message: 'raw' }]))
        const w = await mountCard()
        expect(w.find('[data-testid="core-update-runner-setup"]').exists()).toBe(true)
        expect(w.find('[data-testid="core-update-command"]').exists()).toBe(false)
    })

    it('no command hint while a module install runs', async () => {
        answer(status({
            can_update: false,
            preflight: { ok: false, issues: [{ code: 'CORE_MODULE_INSTALL_RUNNING', message: 'raw' }, { code: 'CORE_DISK', message: 'raw' }] },
        }))
        const w = await mountCard()
        expect(w.findAll('[data-testid="core-update-issue"]')).toHaveLength(2)
        expect(w.find('[data-testid="core-update-command"]').exists()).toBe(false)
    })

    it('copying the prompt copies the full text', async () => {
        answer(noRunner([{ code: 'CORE_MODIFIED', message: 'raw', files: ['a.php'] }]))
        const writeText = vi.fn().mockResolvedValue(undefined)
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
        const w = await mountCard()
        await w.get('[data-testid="core-update-copy-prompt"]').trigger('click')
        await flushPromises()
        expect(writeText).toHaveBeenCalledTimes(1)
        const copied = writeText.mock.calls[0][0] as string
        expect(copied).toContain('text.modules.core_update.agent_prompt.intro|')
        expect(copied).toContain('text.modules.core_update.agent_prompt.body|')
        expect(copied).toContain('text.modules.core_update.agent_prompt.limits')
        expect(copied).toContain('text.modules.core_update.agent_prompt.modified|')
        expect(copied).toBe(w.get('[data-testid="core-update-prompt-text"]').element.textContent)
        expect(w.get('[data-testid="core-update-copy-prompt"]').text()).toContain('action.copied')
        // Only the button that copied says so.
        expect(w.get('[data-testid="core-update-copy"]').text()).not.toContain('action.copied')
    })

    it('asks for the old files back only after the checkbox', async () => {
        answer(status({
            can_update: false,
            can_restore: true,
            preflight: { ok: false, issues: [{ code: 'CORE_UPGRADE_INTERRUPTED', message: 'raw' }] },
        }))
        ajaxMock.post.mockImplementation(() => {
            answer(status({ can_update: false, running: { uuid: 'r1', progress: 0, status: 'queued' } }))
            return Promise.resolve({ data: { data: { background_task_uuid: 'r1' } } })
        })
        const w = await mountCard()
        expect(w.find('[data-testid="core-update-command"]').exists()).toBe(false)
        await w.get('[data-testid="core-update-restore-open"]').trigger('click')
        await w.get('[data-testid="core-update-restore-form"]').trigger('submit')
        expect(ajaxMock.post).not.toHaveBeenCalled()
        await w.get('[data-testid="core-update-restore-check"]').setValue(true)
        await w.get('[data-testid="core-update-restore-form"]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
        expect(ajaxMock.post).toHaveBeenCalledWith('core-update/restore', { confirm: true }, expect.anything())
        expect(w.find('[data-testid="core-update-waiting"]').exists()).toBe(true)
    })

    it('shows how the followed task ended, in plain text only', async () => {
        vi.useFakeTimers()
        answer(status({ can_update: false, running: { uuid: 'u1', progress: 40, status: 'running' } }))
        const w = await mountCard()
        ajaxMock.get.mockImplementation((url: string) =>
            Promise.resolve({
                data: {
                    data: url === 'background-tasks/u1'
                        ? { uuid: 'u1', status: 'failed', message: 'CORE_RUNNER_DIED: /srv/secret', message_params: { code: 'CORE_RUNNER_DIED' }, result: null }
                        : status({ can_update: true }),
                },
            }),
        )
        await vi.advanceTimersByTimeAsync(5000)
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenCalledWith('background-tasks/u1', expect.anything())
        const result = w.get('[data-testid="core-update-result"]')
        expect(result.text()).toBe('text.modules.core_update.result.CORE_RUNNER_DIED')
        expect(w.get('[data-testid="core-update-card"]').attributes('data-state')).toBe('danger')
        expect(w.get('[data-testid="core-update-state"]').text()).toBe('text.modules.core_update.badge_failed')
        expect(w.text()).not.toContain('/srv/secret')
        expect(w.emitted('finished')).toHaveLength(1)
        // The outcome goes away with the next action.
        await w.get('[data-testid="core-update-open"]').trigger('click')
        expect(w.find('[data-testid="core-update-result"]').exists()).toBe(false)
    })

    it('shows the progress of a running upgrade and no update button', async () => {
        answer(status({ can_update: false, running: { uuid: 'u1', progress: 40, status: 'running' } }))
        const w = await mountCard()
        expect(w.get('[data-testid="core-update-progress"]').text()).toBe('40')
        expect(w.get('[data-testid="core-update-card"]').attributes('data-state')).toBe('progress')
        const line = w.get('[data-testid="core-update-running"]')
        expect(line.text()).toBe('text.modules.core_update.running')
        expect(line.attributes('role')).toBe('status')
        expect(w.find('[data-testid="core-update-waiting-bar"]').exists()).toBe(false)
        expect(w.get('[data-testid="core-update-auto-refresh"]').text()).toBe('text.modules.core_update.auto_refresh')
        expect(w.find('[data-testid="core-update-open"]').exists()).toBe(false)
    })

    it('keeps the form usable after a failed start', async () => {
        answer(status())
        ajaxMock.post.mockRejectedValue({ response: { status: 500, data: {} } })
        const w = await mountCard()
        await w.get('[data-testid="core-update-open"]').trigger('click')
        await w.get('[data-testid="core-update-backup"]').setValue(true)
        await w.get('[data-testid="core-update-form"]').trigger('submit')
        await flushPromises()
        expect(w.get('[data-testid="core-update-submit-error"]').text()).toContain('error.generic')
        expect(w.get('[data-testid="core-update-start"]').attributes('disabled')).toBeUndefined()
    })
})
