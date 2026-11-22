import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ScopeSummary from '@/components/data-scope/scope-summary.vue'

vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        locale: { value: 'vi' },
        t: (key: string, p?: { n?: number }) => (key === 'text.data_scope.more' ? `và ${p?.n} mục khác` : key),
    }),
}))

const nodes = (n: number) => Array.from({ length: n }, (_, i) => ({ key: `k-${i}`, label: `Đơn vị ${i + 1}`, trail: [] }))
const make = (count: number, more = 0) =>
    mount(ScopeSummary, {
        props: { summary: { level: 'nodes', dimensions: [{ name: 'organization', label: 'Tổ chức', nodes: nodes(count), more }] } },
    })

describe('scope summary expander', () => {
    it('shows 5 nodes and an "and 2 more" button that reveals all 7', async () => {
        const w = make(7)
        expect(w.findAll('li')).toHaveLength(5)
        const btn = w.get('[data-testid="scope-more"]')
        expect(btn.text()).toBe('và 2 mục khác')
        expect(btn.attributes('aria-expanded')).toBe('false')
        await btn.trigger('click')
        expect(w.findAll('li')).toHaveLength(7)
        expect(w.find('[data-testid="scope-more"]').exists()).toBe(false)
        expect(w.text()).not.toContain('k-0')
    })

    it('keeps a non-clickable remainder for server-truncated nodes after expanding', async () => {
        const w = make(7, 3)
        expect(w.get('[data-testid="scope-more"]').text()).toBe('và 5 mục khác')
        await w.get('[data-testid="scope-more"]').trigger('click')
        expect(w.findAll('li')).toHaveLength(7)
        const rest = w.get('[data-testid="scope-more-static"]')
        expect(rest.text()).toBe('và 3 mục khác')
        expect(rest.element.tagName).not.toBe('BUTTON')
    })

    it('shows only a static note when nothing more is present on the client', () => {
        const w = make(3, 4)
        expect(w.find('[data-testid="scope-more"]').exists()).toBe(false)
        expect(w.get('[data-testid="scope-more-static"]').text()).toBe('và 4 mục khác')
    })
})

describe('scope summary compare', () => {
    const dim = (keys: string[]) => ({ name: 'organization', label: 'Tổ chức', more: 0, nodes: keys.map((k) => ({ key: k, label: `Đơn vị ${k}`, trail: [] })) })
    const withCompare = (now: string[], start: string[] | null) =>
        mount(ScopeSummary, {
            props: { summary: { level: 'nodes', dimensions: [dim(now)] }, ...(start ? { compareTo: { level: 'nodes', dimensions: [dim(start)] } } : {}) },
            global: { stubs: { 'a-tag': { props: ['color'], template: '<b :data-color="color"><slot /></b>' } } },
        })

    it('marks added and removed nodes with words and colours', () => {
        const w = withCompare(['a', 'c'], ['a', 'b'])
        expect(w.get('[data-testid="scope-added"]').text()).toBe('text.data_scope.added')
        expect(w.get('[data-testid="scope-added"]').attributes('data-color')).toBe('success')
        const removed = w.get('[data-testid="scope-removed"]')
        expect(removed.text()).toContain('Đơn vị b')
        expect(removed.text()).toContain('text.data_scope.removed')
        expect(removed.find('b').attributes('data-color')).toBe('error')
        expect(w.findAll('[data-testid="scope-added"]')).toHaveLength(1)
    })

    it('lists removed nodes of a dimension that is gone entirely', () => {
        const w = mount(ScopeSummary, {
            props: { summary: { level: 'nodes', dimensions: [] }, compareTo: { level: 'nodes', dimensions: [dim(['x'])] } },
            global: { stubs: { 'a-tag': true } },
        })
        expect(w.get('[data-testid="scope-removed"]').text()).toContain('Đơn vị x')
    })

    it('renders no marks without compareTo', () => {
        const w = withCompare(['a'], null)
        expect(w.find('[data-testid="scope-added"]').exists()).toBe(false)
        expect(w.find('[data-testid="scope-removed"]').exists()).toBe(false)
    })

    it('counts added nodes hidden behind "and N more" before expanding, and drops the count once expanded', async () => {
        const w = withCompare(['a', 'b', 'c', 'd', 'e', 'f', 'g'], ['a', 'b', 'c', 'd', 'e', 'f'])
        // Only "g" is new and it sits in the collapsed tail.
        expect(w.findAll('[data-testid="scope-added"]')).toHaveLength(0)
        expect(w.get('[data-testid="scope-more-added"]').text()).toBe('text.data_scope.more_added')
        await w.get('[data-testid="scope-more"]').trigger('click')
        expect(w.find('[data-testid="scope-more-added"]').exists()).toBe(false)
        expect(w.findAll('[data-testid="scope-added"]')).toHaveLength(1)
    })

    it('shows no hidden-added count when the collapsed nodes are not new', () => {
        const w = withCompare(['a', 'b', 'c', 'd', 'e', 'f', 'g'], ['a', 'b', 'c', 'd', 'e', 'f', 'g'])
        expect(w.find('[data-testid="scope-more-added"]').exists()).toBe(false)
        // The expander and the static "and N more" line never show together.
        expect(w.find('[data-testid="scope-more"]').exists()).toBe(true)
        expect(w.find('[data-testid="scope-more-static"]').exists()).toBe(false)
    })
})
