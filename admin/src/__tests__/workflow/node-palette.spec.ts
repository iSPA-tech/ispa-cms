import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const onDragStart = vi.fn()
vi.mock('@/components/workflows/useDnd.js', () => ({ default: () => ({ onDragStart }) }))
vi.mock('@/services/workflowApi', () => ({ fetchNodeCatalog: vi.fn() }))
vi.mock('@/config/i18n', () => ({ i18n: { global: { t: (key: string) => key } } }))
vi.mock('@vue-flow/core', () => ({
    useVueFlow: () => ({
        getNodes: ref([{ id: 'n1', data: { step_code: 'change' } }]),
        getEdges: ref([]),
    }),
}))

import CoreNodePalette from '@/components/workflow/CoreNodePalette.vue'
import VueFlowSidebar from '@/components/workflows/sidebar.vue'
import { fetchNodeCatalog } from '@/services/workflowApi'
import { applyNodeCatalog, buildPaletteGroups } from '@/components/workflows/nodeCatalog'
import { antdStubs } from './antd-stubs'
import { catalogFixture } from './node-catalog-fixture'

const t = (key: string) => key
const mountPalette = (props: Record<string, unknown> = {}) =>
    mount(CoreNodePalette, {
        props: { groups: buildPaletteGroups(catalogFixture, '', t), ...props },
        global: { stubs: antdStubs },
    })

describe('workflow node palette', () => {
    beforeEach(() => onDragStart.mockClear())

    it('shows every group with its nodes, descriptions, icons and safety badges', () => {
        const wrapper = mountPalette()

        const sections = wrapper.findAll('section')
        expect(sections).toHaveLength(4)
        expect(sections[0].text()).toContain('Flow control')
        expect(sections[2].text()).toContain('AI action')
        expect(sections[2].text()).toContain('Let an AI assistant do one job.')
        // Node icon from the catalog, group icon as the fallback.
        expect(wrapper.find('[data-node-type="ai_action"] [data-icon]').attributes('data-icon')).toBe('robot-outline')
        expect(wrapper.find('[data-node-type="change"] [data-icon]').attributes('data-icon')).toBe('database')
        expect(wrapper.find('[data-node-type="ai_action"] [data-safety="ai_spend"]').text()).toBe('text.workflow.safety.ai_spend')
        expect(wrapper.find('[data-node-type="change"] [data-safety="internal_write"]').exists()).toBe(true)
    })

    it('shows only the matches of a search and says so when there are none', async () => {
        const wrapper = mountPalette({ groups: buildPaletteGroups(catalogFixture, 'approve', t), search: 'approve' })

        expect(wrapper.findAll('[data-node-type]').map((node) => node.attributes('data-node-type'))).toEqual(['approval'])

        await wrapper.setProps({ groups: [], search: 'zzz' })
        expect(wrapper.findAll('[data-node-type]')).toHaveLength(0)
        expect(wrapper.text()).toContain('text.workflow.palette.no_match')
    })

    it('drags a catalog node as a custom step with its default config and an unused step code', async () => {
        const wrapper = mountPalette({ usedCodes: ['change'] })

        await wrapper.find('[data-node-type="change"]').trigger('dragstart')

        expect(onDragStart).toHaveBeenCalledTimes(1)
        expect(onDragStart.mock.calls[0][1]).toEqual({
            type: 'custom',
            data: {
                name: 'Change a record',
                display_name: 'Change a record',
                step_code: 'change_2',
                step_type: 'change',
                step_config: { targetExpr: 'vars.target', changes: [], save: true },
            },
        })
    })

    it('drags the decision step as the built-in decision card', async () => {
        const wrapper = mountPalette()

        await wrapper.find('[data-node-type="decision"]').trigger('dragstart')

        expect(onDragStart.mock.calls[0][1]).toBe('decision')
    })

    it('keeps the built-in list usable when it is passed as one plain group', () => {
        const wrapper = mountPalette({
            groups: [{ key: 'core', label: 'Steps', icon: 'puzzle-outline', nodes: [{ type: 'set_variable', title: 'Set Variable', summary: 'Assign values.', default_config: {}, safety: [] }] }],
        })

        expect(wrapper.find('[data-node-type="set_variable"]').text()).toContain('Set Variable')
        expect(wrapper.find('[data-testid="node-safety-badges"]').exists()).toBe(false)
    })
})

describe('workflow designer sidebar', () => {
    beforeEach(() => onDragStart.mockClear())

    it('loads the palette from the node catalog and filters it with the search box', async () => {
        ;(fetchNodeCatalog as any).mockResolvedValueOnce(catalogFixture)
        const wrapper = mount(VueFlowSidebar, { global: { stubs: antdStubs } })
        await flushPromises()

        expect(wrapper.findAll('[data-node-type]')).toHaveLength(4)
        // Start and finish stay as built-in cards; the decision step now comes from the catalog.
        expect(wrapper.findAll('.node-pill')).toHaveLength(2)

        await wrapper.find('input').setValue('assistant')
        expect(wrapper.findAll('[data-node-type]').map((node) => node.attributes('data-node-type'))).toEqual(['ai_action'])

        // Step codes already on the canvas are not reused.
        await wrapper.find('input').setValue('')
        await wrapper.find('[data-node-type="change"]').trigger('dragstart')
        expect(onDragStart.mock.calls[0][1].data.step_code).toBe('change_2')
    })

    it('falls back to the built-in steps when the catalog cannot be loaded', async () => {
        applyNodeCatalog({})
        ;(fetchNodeCatalog as any).mockRejectedValueOnce(new Error('404'))
        const wrapper = mount(VueFlowSidebar, { global: { stubs: antdStubs } })
        await flushPromises()

        expect(wrapper.find('[data-node-type="set_variable"]').exists()).toBe(true)
        expect(wrapper.find('[data-node-type="ai_action"]').exists()).toBe(true)
    })
})
