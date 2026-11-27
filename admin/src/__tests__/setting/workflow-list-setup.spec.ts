import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

// once every setup question of a sample is answered, the setup screen must stay reachable from the
// workflow list (to change who is told, the schedule, ...), not only while "Needs setup (n)" shows.

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}${JSON.stringify(params)}` : key) }),
}))

import WorkflowList from '@core-admin/pages/setting/workflows-manage/components/workflow-list.vue'

const stubs = {
    'a-tooltip': { template: '<span><slot /></span>' },
    'a-tag': { template: '<span><slot /></span>' },
    'a-switch': true,
    'a-popconfirm': { template: '<span><slot /></span>' },
    'a-empty': true,
    'a-spin': { template: '<div><slot /></div>' },
    'a-button': { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>' },
}

const workflow = { id: 5, name: 'Báo cáo tuần', slug: 'weekly-report', is_active: true, system_managed: false, description: '' }

const mountList = (props: Record<string, unknown>) =>
    mount(WorkflowList, {
        props: { workflows: [workflow], canUpdate: true, ...props },
        global: { stubs, mocks: { $t: (key: string) => key } },
    })

describe('workflow list: setup screen', () => {
    it('offers "set up" on a workflow whose questions are all answered, and opens it', async () => {
        const wrapper = mountList({ setupTotals: { 5: 2 }, setupCounts: { 5: 0 } })

        expect(wrapper.find('[data-testid="list-needs-setup"]').exists()).toBe(false)
        await wrapper.get('[data-testid="workflow-setup-open"]').trigger('click')
        expect(wrapper.emitted('setup')?.[0]).toEqual([workflow])
    })

    it('shows no setup button for a workflow without setup questions, nor to someone who may not change it', () => {
        expect(mountList({ setupTotals: { 5: 0 } }).find('[data-testid="workflow-setup-open"]').exists()).toBe(false)
        expect(mountList({ setupTotals: { 5: 2 }, canUpdate: false }).find('[data-testid="workflow-setup-open"]').exists()).toBe(false)
    })
})
