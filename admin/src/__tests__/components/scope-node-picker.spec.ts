import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ScopeNodePicker from '@/components/data-scope/scope-node-picker.vue'

const searchMock = vi.hoisted(() => vi.fn())

vi.mock('@/libs/data-scope', () => ({ searchScopeNodes: searchMock }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const ASelect = {
    name: 'ASelect',
    props: ['value', 'options', 'filterOption', 'loading', 'notFoundContent', 'placeholder', 'showSearch'],
    emits: ['search', 'select', 'focus'],
    template: '<div class="a-select" />',
}
const AButton = { name: 'AButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' }

const mountPicker = () =>
    mount(ScopeNodePicker, {
        props: { dimension: 'organization' },
        global: { stubs: { 'a-select': ASelect, 'a-button': AButton } },
    })

const nodes = [{ key: 'org-1', label: 'Hà Nội', trail: ['Việt Nam', 'Hà Nội'] }]

describe('scope-node-picker', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        searchMock.mockReset()
        searchMock.mockResolvedValue(nodes)
    })
    afterEach(() => vi.useRealTimers())

    it('sends one request 300 ms after several keystrokes', async () => {
        const w = mountPicker()
        const select = w.findComponent({ name: 'ASelect' })
        select.vm.$emit('search', 'h')
        select.vm.$emit('search', 'hà')
        select.vm.$emit('search', 'hà n')
        vi.advanceTimersByTime(299)
        expect(searchMock).not.toHaveBeenCalled()
        vi.advanceTimersByTime(1)
        await flushPromises()
        expect(searchMock).toHaveBeenCalledTimes(1)
        expect(searchMock).toHaveBeenCalledWith('organization', 'hà n')
    })

    it('shows loading while the request is pending', async () => {
        let resolve: (v: unknown) => void = () => {}
        searchMock.mockReturnValue(new Promise((r) => (resolve = r)))
        const w = mountPicker()
        w.findComponent({ name: 'ASelect' }).vm.$emit('focus')
        await flushPromises()
        expect(w.find('[data-testid="picker-loading"]').exists()).toBe(true)
        resolve(nodes)
        await flushPromises()
        expect(w.find('[data-testid="picker-loading"]').exists()).toBe(false)
    })

    it('passes the results, with trail, as options', async () => {
        const w = mountPicker()
        w.findComponent({ name: 'ASelect' }).vm.$emit('focus')
        await flushPromises()
        expect(w.findComponent({ name: 'ASelect' }).props('options')).toEqual([
            { value: 'org-1', label: 'Hà Nội', trail: ['Việt Nam', 'Hà Nội'] },
        ])
    })

    it('shows an empty state when nothing matches', async () => {
        searchMock.mockResolvedValue([])
        const w = mountPicker()
        w.findComponent({ name: 'ASelect' }).vm.$emit('focus')
        await flushPromises()
        expect(w.find('[data-testid="picker-empty"]').text()).toContain('no_nodes_found')
    })

    it('shows an error with a retry that searches again', async () => {
        searchMock.mockRejectedValueOnce(new Error('boom'))
        const w = mountPicker()
        w.findComponent({ name: 'ASelect' }).vm.$emit('focus')
        await flushPromises()
        expect(w.find('[data-testid="picker-error"]').exists()).toBe(true)
        await w.find('[data-testid="picker-retry"]').trigger('click')
        await flushPromises()
        expect(searchMock).toHaveBeenCalledTimes(2)
        expect(w.find('[data-testid="picker-error"]').exists()).toBe(false)
    })

    it('emits { key, label, trail } for the chosen node', async () => {
        const w = mountPicker()
        const select = w.findComponent({ name: 'ASelect' })
        select.vm.$emit('focus')
        await flushPromises()
        select.vm.$emit('select', 'org-1')
        expect(w.emitted('select')?.[0]).toEqual([{ key: 'org-1', label: 'Hà Nội', trail: ['Việt Nam', 'Hà Nội'] }])
    })
})
