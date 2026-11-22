import { describe, expect, it } from 'vitest'
import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import WorkflowGraphHealthPanel from '../../../cms/Core/admin/pages/setting/workflows-manage/components/workflow-graph-health-panel.vue'

const ATagStub = defineComponent({
    name: 'ATagStub',
    template: '<span><slot /></span>',
})

describe('components:WorkflowGraphHealthPanel', () => {
    it('renders graph checks and emits the selected issue on click', async () => {
        const checks = [
            {
                key: 'single-input',
                label: 'Exactly one input',
                message: 'Found 2 input nodes',
                ok: false,
                nodeIds: ['input_a', 'input_b'],
            },
            {
                key: 'has-output',
                label: 'At least one output',
                message: '1 output node',
                ok: true,
                nodeIds: ['output_a'],
            },
        ]

        const wrapper = mount(WorkflowGraphHealthPanel, {
            props: {
                checks,
                summary: '1 issue to fix',
                tone: 'orange',
            },
            global: {
                stubs: {
                    'a-tag': ATagStub,
                },
            },
        })

        expect(wrapper.text()).toContain('text.workflow.builder.graph_health')
        expect(wrapper.text()).toContain('1 issue to fix')
        expect(wrapper.text()).toContain('Exactly one input')
        expect(wrapper.text()).toContain('text.workflow.builder.check_fix')
        expect(wrapper.text()).toContain('text.workflow.builder.check_ok')

        await wrapper.findAll('button')[0].trigger('click')

        expect(wrapper.emitted('focus-issue')).toEqual([[checks[0]]])
    })
})
