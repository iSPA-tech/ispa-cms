import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// Upgrade-all (spec 2026-11-26, decision 5): one confirmation that lists what will be upgraded and what is left out.

const api = vi.hoisted(() => ({ fetchPlan: vi.fn(), startBatch: vi.fn() }))
vi.mock('@core-admin/update-batch-api', async (original) => ({ ...(await original<Record<string, unknown>>()), ...api }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}${JSON.stringify(params)}` : key) }),
}))

import UpdateBatchDialog from '@core-admin/components/update-batch/update-batch-dialog.vue'

const stubs = {
    'a-drawer': { props: ['open', 'title'], emits: ['close'], template: '<aside v-if="open" role="dialog"><h2>{{ title }}</h2><slot /><slot name="footer" /></aside>' },
    'a-skeleton': { template: '<div data-testid="skeleton" />' },
    'a-alert': { props: ['message', 'description'], template: '<div role="alert">{{ message }} {{ description }}</div>' },
    'a-switch': { props: ['checked'], emits: ['update:checked'], template: '<input type="checkbox" data-testid="switch" :checked="checked" @change="$emit(\'update:checked\', $event.target.checked)" />' },
    'i-button': { props: ['disabled', 'loading'], emits: ['click'], template: '<button :disabled="disabled || loading" @click="$emit(\'click\')"><slot /></button>' },
}

const item = (kind: string, key: string, over: Record<string, unknown> = {}) => ({ kind, key, label: key, from: '1', to: '2', state: 'pending', ...over })
const plan = (over: Record<string, unknown> = {}) => ({
    items: [item('module', 'Account', { from: '2026.11.04', to: '2026.11.05' }), item('widget', 'ispa.news', { label: 'Tin tức', pages: 3 }), item('workflow', 'ispa.church-story')],
    left_out: [{ kind: 'module', key: 'Forum', label: 'Forum', from: '2026.11.01', to: '2026.12.01', reason_code: 'NEEDS_NEWER_CORE' }],
    runner_ready: true,
    ...over,
})
const refused = (code: string) => ({ response: { status: 409, data: { error_code: code } } })

const open = async (props: Record<string, unknown> = {}) => {
    const wrapper = mount(UpdateBatchDialog, { props: { open: true, kinds: ['module', 'widget', 'workflow'], ...props }, global: { stubs } })
    await flushPromises()
    return wrapper
}
const startButton = (wrapper: any) => wrapper.find('[data-testid="update-batch-start"]')

describe('update batch dialog', () => {
    beforeEach(() => {
        api.fetchPlan.mockReset()
        api.startBatch.mockReset()
    })

    it('lists what will be upgraded and what is left out, with the reason', async () => {
        api.fetchPlan.mockResolvedValue(plan())

        const wrapper = await open()

        expect(api.fetchPlan).toHaveBeenCalledWith(['module', 'widget', 'workflow'])
        expect(wrapper.findAll('[data-testid="update-batch-item"]').map((row: any) => row.attributes('data-key'))).toEqual(['Account', 'ispa.news', 'ispa.church-story'])
        expect(wrapper.text()).toContain('text.update_batch.pages{"count":3}')
        expect(wrapper.text()).toContain('text.update_batch.draft_note')
        expect(wrapper.find('[data-testid="update-batch-left-out"]').text()).toContain('text.update_batch.reason.needs_newer_core')
    })

    it('starts with the confirmed items and the backup choice, once', async () => {
        api.fetchPlan.mockResolvedValue(plan())
        let finish: (value: unknown) => void = () => {}
        api.startBatch.mockReturnValue(new Promise((resolve) => (finish = resolve)))
        const wrapper = await open()

        await wrapper.find('[data-testid="switch"]').setValue(false)
        await startButton(wrapper).trigger('click')
        await startButton(wrapper).trigger('click')
        finish({ uuid: 'u-1', status: 'queued' })
        await flushPromises()

        expect(api.startBatch).toHaveBeenCalledTimes(1)
        expect(api.startBatch).toHaveBeenCalledWith({ kinds: ['module', 'widget', 'workflow'], items: plan().items, backup: false })
        expect(wrapper.emitted('started')?.[0]).toEqual([{ uuid: 'u-1', status: 'queued' }])
    })

    it('reloads the plan when the list changed meanwhile', async () => {
        api.fetchPlan.mockResolvedValue(plan())
        api.startBatch.mockRejectedValue(refused('UPDATE_BATCH_PLAN_CHANGED'))
        const wrapper = await open()

        await startButton(wrapper).trigger('click')
        await flushPromises()

        expect(api.fetchPlan).toHaveBeenCalledTimes(2)
        expect(wrapper.text()).toContain('text.update_batch.error.changed')
        expect(startButton(wrapper).attributes('disabled')).toBeUndefined()
    })

    it('cannot start without a runner or with nothing to upgrade', async () => {
        api.fetchPlan.mockResolvedValue(plan({ runner_ready: false }))
        const wrapper = await open()
        expect(wrapper.text()).toContain('text.update_batch.error.no_runner')
        expect(startButton(wrapper).attributes('disabled')).toBeDefined()

        api.fetchPlan.mockResolvedValue(plan({ items: [] }))
        const empty = await open()
        expect(startButton(empty).attributes('disabled')).toBeDefined()
    })

    it('offers the backup switch only when modules are in the run', async () => {
        api.fetchPlan.mockResolvedValue(plan({ items: [item('widget', 'ispa.news')] }))

        const wrapper = await open({ kinds: ['widget'] })

        expect(wrapper.find('[data-testid="switch"]').exists()).toBe(false)
    })
})
