import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// Upgrade-all (spec 2026-11-26): the "Bản cập nhật" band of the Modules page.

const api = vi.hoisted(() => ({ fetchPlan: vi.fn() }))
vi.mock('@core-admin/update-batch-api', async (original) => ({ ...(await original<Record<string, unknown>>()), ...api }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}${JSON.stringify(params)}` : key) }),
}))

import UpdateBatchBand from '@core-admin/components/update-batch/update-batch-band.vue'

const stubs = {
    'update-batch-dialog': { props: ['open', 'kinds'], template: '<div v-if="open" data-testid="dialog" :data-kinds="kinds.join(\',\')" />' },
    'i-button': { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
}
const item = (kind: string, key: string) => ({ kind, key, label: key, state: 'pending' })

const show = async (props: Record<string, unknown> = {}) => {
    const wrapper = mount(UpdateBatchBand, { props, global: { stubs } })
    await flushPromises()
    return wrapper
}

describe('update batch band', () => {
    beforeEach(() => api.fetchPlan.mockReset())

    it('stays hidden when nothing can be upgraded in one go', async () => {
        api.fetchPlan.mockResolvedValue({ items: [], left_out: [{ kind: 'module', key: 'Forum' }], runner_ready: true })

        const wrapper = await show()

        expect(wrapper.find('[data-testid="update-batch-band"]').exists()).toBe(false)
    })

    it('counts the new versions by kind and opens the dialog for every kind', async () => {
        api.fetchPlan.mockResolvedValue({ items: [item('module', 'Account'), item('module', 'SocialAuth'), item('widget', 'ispa.news')], left_out: [], runner_ready: true })

        const wrapper = await show({ coreUpdateAvailable: true })

        expect(wrapper.text()).toContain('text.update_batch.counts{"module":2,"widget":1,"workflow":0}')
        expect(wrapper.text()).toContain('text.update_batch.core_first')
        await wrapper.find('button').trigger('click')
        expect(wrapper.find('[data-testid="dialog"]').attributes('data-kinds')).toBe('module,widget,workflow')
    })

    it('holds the button while a run is open', async () => {
        api.fetchPlan.mockResolvedValue({ items: [item('module', 'Account')], left_out: [], runner_ready: true })

        const wrapper = await show({ busy: true })

        expect(wrapper.find('button').attributes('disabled')).toBeDefined()
    })
})
