import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// Upgrade-all (spec 2026-11-26, decision 6): progress while the run goes, the outcome after it.

const api = vi.hoisted(() => ({ fetchCurrent: vi.fn(), stopBatch: vi.fn(), dismissBatch: vi.fn() }))
vi.mock('@core-admin/update-batch-api', async (original) => ({ ...(await original<Record<string, unknown>>()), ...api }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}${JSON.stringify(params)}` : key) }),
}))

import UpdateBatchPanel from '@core-admin/components/update-batch/update-batch-panel.vue'

const stubs = {
    'a-tag': { template: '<span><slot /></span>' },
    'router-link': { props: ['to'], template: '<a :href="to"><slot /></a>' },
    'i-button': { props: ['disabled', 'loading'], emits: ['click'], template: '<button :disabled="disabled || loading" @click="$emit(\'click\')"><slot /></button>' },
}

const item = (kind: string, key: string, state: string, over: Record<string, unknown> = {}) => ({ kind, key, label: key, from: '1', to: '2', state, reason_code: null, ...over })
const batch = (over: Record<string, unknown> = {}) => ({
    uuid: 'u-1',
    status: 'running',
    stop_requested: false,
    stopped_reason: null,
    items: [item('module', 'Account', 'done'), item('module', 'SocialAuth', 'running', { step: 'backing_up' }), item('widget', 'ispa.news', 'pending')],
    left_out: [],
    counts: { done: 1, failed: 0, skipped: 0, not_started: 0, left_out: 0 },
    ...over,
})

const show = async () => {
    const wrapper = mount(UpdateBatchPanel, { global: { stubs } })
    await flushPromises()
    return wrapper
}

describe('update batch panel', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        Object.values(api).forEach((mock) => mock.mockReset())
    })
    afterEach(() => vi.useRealTimers())

    it('shows nothing when there is no run', async () => {
        api.fetchCurrent.mockResolvedValue(null)

        const wrapper = await show()

        expect(wrapper.find('[data-testid="update-batch-panel"]').exists()).toBe(false)
    })

    it('shows the progress, the running step and "Dừng" while the run goes', async () => {
        api.fetchCurrent.mockResolvedValue(batch())

        const wrapper = await show()

        expect(wrapper.text()).toContain('text.update_batch.running_title{"done":1,"total":3}')
        expect(wrapper.text()).toContain('text.modules.install.status.backing_up')
        expect(wrapper.find('[data-testid="update-batch-stop"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="update-batch-dismiss"]').exists()).toBe(false)
    })

    it('says the next item starts within about a minute while nothing runs yet', async () => {
        // The scheduled runner picks a run up within a minute; until then every line just says "chờ".
        api.fetchCurrent.mockResolvedValue(batch({ items: [item('widget', 'ispa.news', 'pending')], counts: { done: 0 } }))

        const wrapper = await show()

        expect(wrapper.find('[data-testid="update-batch-wait"]').text()).toBe('text.update_batch.wait_hint')
    })

    it('drops the wait line while an item runs, after "Dừng" and once the run is over', async () => {
        for (const value of [batch(), batch({ items: [item('widget', 'ispa.news', 'pending')], stop_requested: true }), batch({ status: 'succeeded', items: [item('widget', 'ispa.news', 'done')] })]) {
            api.fetchCurrent.mockResolvedValue(value)

            const wrapper = await show()

            expect(wrapper.find('[data-testid="update-batch-wait"]').exists()).toBe(false)
            wrapper.unmount()
        }
    })

    it('asks to stop once and then says it is stopping', async () => {
        api.fetchCurrent.mockResolvedValue(batch())
        api.stopBatch.mockResolvedValue(batch({ stop_requested: true }))
        const wrapper = await show()

        await wrapper.find('[data-testid="update-batch-stop"]').trigger('click')
        await flushPromises()

        expect(api.stopBatch).toHaveBeenCalledWith('u-1')
        expect(wrapper.find('[data-testid="update-batch-stop"]').exists()).toBe(false)
        expect(wrapper.text()).toContain('text.update_batch.stopping')
    })

    it('shows the outcome with reasons and links, offers "Ẩn", and stops polling', async () => {
        api.fetchCurrent.mockResolvedValue(
            batch({
                status: 'succeeded',
                items: [
                    item('module', 'Account', 'done'),
                    item('module', 'SocialAuth', 'skipped', { reason_code: 'MODULE_REQUIRES_UNMET' }),
                    item('workflow', 'ispa.church-story', 'done', { setup_url: '/setting/automation/workflows/setup/7' }),
                ],
                left_out: [{ kind: 'module', key: 'Forum', label: 'Forum', reason_code: 'NEEDS_NEWER_CORE' }],
                counts: { done: 2, failed: 0, skipped: 1, not_started: 0, left_out: 1 },
            }),
        )
        const wrapper = await show()

        expect(wrapper.text()).toContain('text.update_batch.finished_title{"done":2,"failed":0,"skipped":1,"left_out":1}')
        expect(wrapper.text()).toContain('text.update_batch.reason.module_requires_unmet')
        expect(wrapper.find('a[href="/setting/automation/workflows/setup/7"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="update-batch-dismiss"]').exists()).toBe(true)

        api.fetchCurrent.mockClear()
        await vi.advanceTimersByTimeAsync(15000)
        expect(api.fetchCurrent).not.toHaveBeenCalled()
    })

    it('polls while the run is open', async () => {
        api.fetchCurrent.mockResolvedValue(batch())
        await show()
        api.fetchCurrent.mockClear()

        await vi.advanceTimersByTimeAsync(5000)

        expect(api.fetchCurrent).toHaveBeenCalledTimes(1)
    })
})
