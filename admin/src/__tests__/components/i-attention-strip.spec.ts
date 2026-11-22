import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import IAttentionStrip from '@/components/common/i-attention-strip.vue'

const RouterLinkStub = { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' }
const mountPanel = (items: unknown[]) =>
    mount(IAttentionStrip, {
        props: { items },
        global: { stubs: { 'router-link': RouterLinkStub }, mocks: { $t: (k: string) => k } },
    })
const item = (key: string, count: number | boolean, extra = {}) => ({ key, route: 'x', count, label: key, ...extra })
const rowLabels = (w) => w.findAll('[role="listitem"]').map((row) => row.find('.font-medium.text-ink').text())

describe('i-attention-strip (Cần xử lý panel)', () => {
    it('renders nothing when nothing needs attention', () => {
        expect(mountPanel([]).find('[data-testid="attention-panel"]').exists()).toBe(false)
        expect(mountPanel([item('a', 0), item('b', false)]).find('[data-testid="attention-panel"]').exists()).toBe(false)
    })

    it('shows the panel title with the number of rows', () => {
        const w = mountPanel([item('a', 2), item('b', 0), item('c', true)])
        expect(w.find('header').text()).toContain('text.dashboard.attention.title')
        expect(w.find('header').text()).toContain('2')
    })

    it('puts danger rows first, then by count descending, stable, capped at 6', () => {
        const w = mountPanel([
            item('a', 1), item('b', 2), item('c', 5), item('d', 2), item('e', 0), item('f', 1),
            item('g', 9), item('h', 3), item('z', true, { tone: 'danger' }),
        ])
        expect(rowLabels(w)).toEqual(['z', 'g', 'c', 'h', 'b', 'd'])
    })

    it('shows "!" for yes/no conditions and the number for counts', () => {
        const w = mountPanel([item('flag', true), item('count', 4)])
        expect(w.findAll('[data-testid="attention-count"]').map((c) => c.text())).toEqual(['4', '!'])
    })

    it('shows the description and a link to where the row is handled', () => {
        const w = mountPanel([
            item('topup', true, { description: 'Hộp thư Linh hoạt cần số dư', action: 'Nạp tiền', primary: true, query: { tab: 'balance' } }),
        ])
        expect(w.text()).toContain('Hộp thư Linh hoạt cần số dư')
        const action = w.get('[data-testid="attention-action"]')
        expect(action.text()).toBe('Nạp tiền')
        expect(action.classes()).toContain('bg-primary')
        expect(JSON.parse(action.attributes('data-to'))).toEqual({ name: 'x', query: { tab: 'balance' } })
    })

    it('falls back to the shared "Xử lý" action label', () => {
        expect(mountPanel([item('a', 1)]).get('[data-testid="attention-action"]').text()).toBe('text.dashboard.attention.open')
    })

    it('accepts a route object and merges its query', () => {
        const w = mountPanel([{ key: 'a', count: 1, route: { name: 'r', query: { a: 1 } }, query: { b: 2 } }])
        expect(JSON.parse(w.get('[data-testid="attention-action"]').attributes('data-to'))).toEqual({ name: 'r', query: { a: 1, b: 2 } })
    })
})
