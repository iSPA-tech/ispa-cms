import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (k: string) => k }) }))

const Tabs = defineComponent({
    props: ['activeKey'],
    emits: ['update:activeKey'],
    setup(props, { emit, slots }) {
        return () =>
            h('div', [
                h('button', { 'data-testid': 'tab-notes', onClick: () => emit('update:activeKey', 'notes') }),
                h('button', { 'data-testid': 'tab-history', onClick: () => emit('update:activeKey', 'history') }),
                slots.default?.(),
            ])
    },
})
const Notes = defineComponent({ props: ['type', 'uuid', 'canNote'], setup: () => () => h('div', { 'data-testid': 'notes' }) })
const History = defineComponent({ props: ['type', 'uuid'], setup: () => () => h('div', { 'data-testid': 'history' }) })

const mountTimeline = async (props: Record<string, unknown> = {}) => {
    const Widget = (await import('@/components/records/record-timeline.vue')).default
    return mount(Widget, {
        props: { type: 'helpdesk.organization', uuid: 'a', ...props },
        global: { stubs: { 'a-tabs': Tabs, 'a-tab-pane': true, 'record-notes': Notes, 'record-history': History } },
    })
}

describe('record timeline wrapper', () => {
    it('mounts only the active tab and unmounts the other', async () => {
        const w = await mountTimeline()
        expect(w.find('[data-testid="notes"]').exists()).toBe(true)
        expect(w.find('[data-testid="history"]').exists()).toBe(false)
        await w.find('[data-testid="tab-history"]').trigger('click')
        expect(w.find('[data-testid="notes"]').exists()).toBe(false)
        expect(w.find('[data-testid="history"]').exists()).toBe(true)
        await w.find('[data-testid="tab-notes"]').trigger('click')
        expect(w.find('[data-testid="history"]').exists()).toBe(false)
    })

    it('passes type and uuid down and canNote defaults to true', async () => {
        const w = await mountTimeline()
        const notes = w.findComponent(Notes)
        expect(notes.props()).toMatchObject({ type: 'helpdesk.organization', uuid: 'a', canNote: true })
        const w2 = await mountTimeline({ canNote: false })
        expect(w2.findComponent(Notes).props('canNote')).toBe(false)
    })
})
