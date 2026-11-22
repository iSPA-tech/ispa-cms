import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ getBackgroundTasks: vi.fn() }))
vi.mock('@/libs/background-tasks', async (importOriginal) => ({ ...(await importOriginal<object>()), ...api }))
vi.mock('@/config/admin-modules', () => ({
    adminBackgroundTaskTypes: [
        {
            type: 'helpdesk.reindex',
            text: 'text.helpdesk.reindex_task',
            moduleKey: 'helpdesk',
            failedLink: { name: 'helpdesk-settings', trail: ['nav.a.helpdesk', 'nav.a.helpdesk-settings'] },
        },
        { type: 'plain.task', text: 'text.plain.task', moduleKey: 'plain' },
    ],
}))
const router = vi.hoisted(() => ({ resolve: vi.fn() }))
vi.mock('vue-router', () => ({ useRouter: () => router }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params: Record<string, unknown> = {}) => {
            if (key === 'text.background_tasks.stale') return 'Stopped: stale'
            if (key === 'text.helpdesk.reindex_progress') return `${params.done} of ${params.total} articles`
            if (key === 'text.background_tasks.step.config_export_section') return `Đã xuất ${params.done}/${params.total} phần cấu hình`
            return key
        },
        te: (key: string) => key.startsWith('text.'),
    }),
}))

const setHidden = (hidden: boolean) =>
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden })

const task = (over: Record<string, unknown> = {}) => ({
    uuid: 'u1',
    type: 'helpdesk.reindex',
    module: 'Helpdesk',
    status: 'succeeded',
    progress: 100,
    message: null,
    finished_at: '2026-11-05T10:00:00+07:00',
    ...over,
})

const mounted: Array<{ unmount: () => void }> = []
const mountBar = async () => {
    const Bar = (await import('@/components/background-task-bar.vue')).default
    const w = mount(Bar, {
        global: { stubs: { 'router-link': { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' }, 'i-icon': true, 'a-tag': { template: '<span><slot /></span>' }, 'a-progress': true } },
    })
    mounted.push(w)
    await flushPromises()
    return w
}

describe('background task bar', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        api.getBackgroundTasks.mockReset()
        api.getBackgroundTasks.mockResolvedValue([task()])
        setHidden(false)
        router.resolve.mockReset()
        router.resolve.mockReturnValue({ matched: [{}] })
    })
    afterEach(() => {
        mounted.splice(0).forEach((w) => w.unmount())
        vi.useRealTimers()
        setHidden(false)
    })

    it('polls every 60 seconds while nothing runs', async () => {
        await mountBar()
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(1)
        await vi.advanceTimersByTimeAsync(5_000)
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(1)
        await vi.advanceTimersByTimeAsync(55_000)
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(2)
    })

    it('polls every 5 seconds while a task is queued or running and slows down after it finishes', async () => {
        api.getBackgroundTasks.mockResolvedValue([task({ status: 'running', progress: 40 })])
        await mountBar()
        await vi.advanceTimersByTimeAsync(5_000)
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(2)
        api.getBackgroundTasks.mockResolvedValue([task()])
        await vi.advanceTimersByTimeAsync(5_000)
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(3)
        await vi.advanceTimersByTimeAsync(30_000)
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(3)
        await vi.advanceTimersByTimeAsync(30_000)
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(4)
    })

    it('shows the running count and an accessible label on the trigger', async () => {
        api.getBackgroundTasks.mockResolvedValue([task({ status: 'running' }), task({ uuid: 'u2', status: 'queued' }), task({ uuid: 'u3' })])
        const w = await mountBar()
        const button = w.find('button')
        expect(button.attributes('aria-label')).toBe('text.background_tasks.title: 2')
        expect(button.attributes('title')).toBe('text.background_tasks.title: 2')
        expect(button.text()).toContain('2')
    })

    it('labels a task from the registry and falls back to the raw type', async () => {
        api.getBackgroundTasks.mockResolvedValue([task(), task({ uuid: 'u2', type: 'other.thing' })])
        const w = await mountBar()
        await w.find('button').trigger('click')
        const text = w.text()
        expect(text).toContain('text.helpdesk.reindex_task')
        expect(text).toContain('other.thing')
    })

    it('refreshes the list when the panel opens so a just-started task shows at once', async () => {
        api.getBackgroundTasks.mockResolvedValueOnce([])
        const w = await mountBar()
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(1)
        api.getBackgroundTasks.mockResolvedValue([task({ status: 'running', progress: 10 })])
        await w.find('button').trigger('click')
        await flushPromises()
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(2)
        expect(w.text()).toContain('text.helpdesk.reindex_task')
        // The running task moves the poll to the 5 s cadence right away.
        await vi.advanceTimersByTimeAsync(5_000)
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(3)
    })

    it('interpolates message params into a translated message', async () => {
        api.getBackgroundTasks.mockResolvedValue([
            task({ status: 'running', progress: 40, message: 'text.helpdesk.reindex_progress', message_params: { done: 2, total: 5 } }),
        ])
        const w = await mountBar()
        await w.find('button').trigger('click')
        expect(w.text()).toContain('2 of 5 articles')
    })

    it('translates a config export step key with done and total counts', async () => {
        api.getBackgroundTasks.mockResolvedValue([
            task({ status: 'running', progress: 36, message: 'text.background_tasks.step.config_export_section', message_params: { done: 2, total: 5 } }),
        ])
        const w = await mountBar()
        await w.find('button').trigger('click')
        expect(w.text()).toContain('Đã xuất 2/5 phần cấu hình')
    })

    it('renders a plain text message raw', async () => {
        api.getBackgroundTasks.mockResolvedValue([task({ status: 'failed', message: 'Legacy plain failure', message_params: null })])
        const w = await mountBar()
        await w.find('button').trigger('click')
        expect(w.text()).toContain('Legacy plain failure')
    })

    it('shows the empty state when there are no tasks', async () => {
        api.getBackgroundTasks.mockResolvedValue([])
        const w = await mountBar()
        await w.find('button').trigger('click')
        expect(w.text()).toContain('text.background_tasks.empty')
    })

    it('does not call while the tab is hidden and refreshes when it is visible again', async () => {
        await mountBar()
        setHidden(true)
        await vi.advanceTimersByTimeAsync(180_000)
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(1)
        setHidden(false)
        document.dispatchEvent(new Event('visibilitychange'))
        await flushPromises()
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(2)
    })

    it('stops polling and removes the listener on unmount', async () => {
        api.getBackgroundTasks.mockResolvedValue([task({ status: 'running' })])
        const remove = vi.spyOn(document, 'removeEventListener')
        const w = await mountBar()
        w.unmount()
        expect(remove).toHaveBeenCalledWith('visibilitychange', expect.any(Function))
        await vi.advanceTimersByTimeAsync(180_000)
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(1)
        remove.mockRestore()
    })

    it('keeps the last list when a poll fails', async () => {
        const w = await mountBar()
        api.getBackgroundTasks.mockRejectedValue(new Error('x'))
        await vi.advanceTimersByTimeAsync(60_000)
        await w.find('button').trigger('click')
        expect(w.text()).toContain('text.helpdesk.reindex_task')
    })

    it('polls every 60 seconds when the only task is a stale one shown as failed and translates its message key', async () => {
        api.getBackgroundTasks.mockResolvedValue([
            task({ status: 'failed', progress: 30, message: 'text.background_tasks.stale' }),
        ])
        const w = await mountBar()
        await vi.advanceTimersByTimeAsync(5_000)
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(1)
        await vi.advanceTimersByTimeAsync(55_000)
        expect(api.getBackgroundTasks).toHaveBeenCalledTimes(2)
        await w.find('button').trigger('click')
        expect(w.text()).toContain('Stopped: stale')
        expect(w.text()).not.toContain('text.background_tasks.stale')
    })

    it('shows a plain message as is', async () => {
        api.getBackgroundTasks.mockResolvedValue([task({ status: 'failed', message: 'Disk full' })])
        const w = await mountBar()
        await w.find('button').trigger('click')
        expect(w.text()).toContain('Disk full')
    })

    it('uses the bare title as the label when nothing runs', async () => {
        const w = await mountBar()
        expect(w.find('button').attributes('aria-label')).toBe('text.background_tasks.title')
        expect(w.find('button').attributes('title')).toBe('text.background_tasks.title')
    })

    describe('failed task link', () => {
        const openBar = async (items: Array<Record<string, unknown>>) => {
            api.getBackgroundTasks.mockResolvedValue(items)
            const w = await mountBar()
            await w.find('button').trigger('click')
            return w
        }

        it('links a failed task to its next step with a breadcrumb trail', async () => {
            const w = await openBar([task({ status: 'failed', message: 'Disk full' })])
            const link = w.find('[data-testid="background-task-link"]')
            expect(link.exists()).toBe(true)
            expect(link.text()).toBe('nav.a.helpdesk › nav.a.helpdesk-settings')
            expect(JSON.parse(link.attributes('data-to') as string)).toEqual({ name: 'helpdesk-settings' })
        })

        it('shows no link on a succeeded or running row', async () => {
            const w = await openBar([task(), task({ uuid: 'u2', status: 'running' })])
            expect(w.find('[data-testid="background-task-link"]').exists()).toBe(false)
        })

        it('shows no link on a failed row whose type has no failedLink', async () => {
            const w = await openBar([task({ type: 'plain.task', status: 'failed' }), task({ uuid: 'u2', type: 'unknown.type', status: 'failed' })])
            expect(w.find('[data-testid="background-task-link"]').exists()).toBe(false)
        })

        it('shows no link when the route cannot be resolved', async () => {
            router.resolve.mockImplementation(() => {
                throw new Error('No match')
            })
            const w = await openBar([task({ status: 'failed' })])
            expect(w.find('[data-testid="background-task-link"]').exists()).toBe(false)
            router.resolve.mockReturnValue({ matched: [] })
            const w2 = await openBar([task({ status: 'failed' })])
            expect(w2.find('[data-testid="background-task-link"]').exists()).toBe(false)
        })

        it('closes the panel when the link is clicked', async () => {
            const w = await openBar([task({ status: 'failed' })])
            await w.find('[data-testid="background-task-link"]').trigger('click')
            expect(w.find('[data-testid="background-task-link"]').exists()).toBe(false)
            expect(w.find('button').attributes('aria-expanded')).toBe('false')
        })
    })
})
