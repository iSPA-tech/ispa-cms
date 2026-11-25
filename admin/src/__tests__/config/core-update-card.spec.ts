import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import CoreUpdateCard from '@core-admin/pages/setting/modules/core-update-card.vue'

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
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

    it('one line when newer: iSPA CMS, the installed version, the new version and the notes link', async () => {
        answer(status())
        const w = await mountCard()
        expect(ajaxMock.get).toHaveBeenCalledWith('core-update', expect.anything())
        expect(w.get('[data-testid="core-update-title"]').text()).toBe('text.modules.core_update.title 2026.11.0')
        expect(w.get('[data-testid="core-update-installed"]').text()).toBe('2026.11.0')
        expect(w.get('[data-testid="core-update-newer"]').text()).toBe('text.modules.core_update.newer|{"version":"2026.12.0"}')
        // The dots are their own items, hidden below sm, so a wrapped line never starts with one.
        const dots = w.findAll('[data-testid="core-update-dot"]')
        expect(dots).toHaveLength(2)
        for (const dot of dots) {
            expect(dot.text()).toBe('·')
            expect(dot.classes()).toEqual(expect.arrayContaining(['hidden', 'sm:inline']))
            expect(dot.attributes('aria-hidden')).toBe('true')
        }
        expect(w.find('[data-testid="core-update-up-to-date"]').exists()).toBe(false)
        expect(w.get('[data-testid="core-update-notes"]').attributes('href')).toBe('https://example.test/notes')
        expect(w.get('[data-testid="core-update-open"]').attributes('disabled')).toBeUndefined()
        expect(w.find('[data-testid="core-update-blocked"]').exists()).toBe(false)
        // The old two-column labels are gone (keys kept, no longer shown).
        expect(w.text()).not.toContain('core_update.installed')
        expect(w.text()).not.toContain('core_update.latest')
    })

    it('one line when up to date', async () => {
        answer(status({ installed: '2026.12.0', can_update: false }))
        const w = await mountCard()
        expect(w.get('[data-testid="core-update-installed"]').text()).toBe('2026.12.0')
        expect(w.get('[data-testid="core-update-up-to-date"]').text()).toBe('text.modules.core_update.up_to_date')
        expect(w.find('[data-testid="core-update-newer"]').exists()).toBe(false)
        expect(w.get('[data-testid="core-update-open"]').attributes('disabled')).toBeDefined()
    })

    it('the version alone when the hub does not know the newest one', async () => {
        answer(status({ latest: null, notes_url: null, can_update: false }))
        const w = await mountCard()
        expect(w.get('[data-testid="core-update-title"]').text()).toBe('text.modules.core_update.title 2026.11.0')
        expect(w.find('[data-testid="core-update-newer"]').exists()).toBe(false)
        expect(w.find('[data-testid="core-update-up-to-date"]').exists()).toBe(false)
        expect(w.find('[data-testid="core-update-notes"]').exists()).toBe(false)
        expect(w.text()).not.toContain('latest_unknown')
        expect(w.find('[data-testid="core-update-dot"]').exists()).toBe(false)
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
        for (const id of ['installed', 'newer', 'up-to-date', 'notes', 'open', 'blocked', 'command', 'issue']) {
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
