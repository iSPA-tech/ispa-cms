import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { appRuntime } from '@/runtime'
import IStatTile from '@/components/common/i-stat-tile.vue'

const RouterLinkStub = {
    props: ['to'],
    template: '<a data-test="rl" :data-to="JSON.stringify(to)"><slot /></a>',
}

const mountTile = (props: Record<string, unknown> = {}) =>
    mount(IStatTile, {
        props: { label: 'Views', value: 1234, ...props },
        global: { stubs: { 'router-link': RouterLinkStub, 'i-icon': true } },
    })

describe('i-stat-tile', () => {
    it('renders label and formatted value', () => {
        const w = mountTile()
        expect(w.text()).toContain('Views')
        expect(w.get('[data-testid="stat-tile-value"]').text()).toBe('1,234')
    })

    it('colours the delta pill by sign', () => {
        expect(mountTile({ delta: 12.5 }).get('[data-testid="stat-tile-delta"]').classes()).toContain('bg-success-soft')
        const down = mountTile({ delta: -3 }).get('[data-testid="stat-tile-delta"]')
        expect(down.classes()).toContain('bg-danger-soft')
        expect(down.text()).toContain('-3%')
        expect(mountTile({ delta: null }).find('[data-testid="stat-tile-delta"]').exists()).toBe(false)
    })

    it('shows decimals when `fractionDigits` is set, and the delta still follows `previous`', () => {
        expect(mountTile({ value: 2.46 }).get('[data-testid="stat-tile-value"]').text()).toBe('2')
        const w = mountTile({ value: 2.46, previous: 2, fractionDigits: 1 })
        expect(w.get('[data-testid="stat-tile-value"]').text()).toBe('2.5')
        expect(w.get('[data-testid="stat-tile-delta"]').text()).toContain('23%')
    })

    it('renders a router-link when `to` is set', () => {
        const w = mountTile({ to: { name: 'post-post' } })
        expect(w.find('[data-test="rl"]').exists()).toBe(true)
        expect(w.get('[data-test="rl"]').attributes('data-to')).toContain('post-post')
        expect(mountTile().find('[data-test="rl"]').exists()).toBe(false)
    })

    it('shows a skeleton instead of the value while loading', () => {
        const w = mountTile({ loading: true })
        expect(w.find('[data-testid="stat-tile-skeleton"]').exists()).toBe(true)
        expect(w.find('[data-testid="stat-tile-value"]').exists()).toBe(false)
    })

    it('renders the description prop and slot', () => {
        expect(mountTile({ description: 'last 7 days' }).get('[data-testid="stat-tile-description"]').text()).toBe('last 7 days')
        const w = mount(IStatTile, {
            props: { label: 'L', value: 1 },
            slots: { description: '<em>slot text</em>' },
            global: { stubs: { 'i-icon': true } },
        })
        expect(w.get('[data-testid="stat-tile-description"] em').text()).toBe('slot text')
    })

    it('renders a native button and emits click when a click handler is attached', async () => {
        const onClick = vi.fn()
        const w = mountTile({ onClick })
        expect(w.element.tagName).toBe('BUTTON')
        expect(w.attributes('type')).toBe('button')
        await w.trigger('click')
        expect(onClick).toHaveBeenCalledOnce()
        expect(mountTile({ as: 'button' }).element.tagName).toBe('BUTTON')
        expect(mountTile().element.tagName).toBe('DIV')
    })

    it('derives the delta from `previous` with one decimal, and `delta` wins', () => {
        const w = mountTile({ value: 1234, previous: 1000 })
        const pill = w.get('[data-testid="stat-tile-delta"]')
        expect(pill.text()).toContain('+23.4%')
        expect(pill.classes()).toContain('delta-up')
        expect(mountTile({ value: 5, previous: 0 }).find('[data-testid="stat-tile-delta"]').exists()).toBe(false)
        expect(mountTile({ value: 120, previous: 100, delta: -2 }).get('[data-testid="stat-tile-delta"]').classes()).toContain('delta-down')
    })

    it('`reverse` flips the pill classes but not the arrow', () => {
        const up = mountTile({ delta: 10, reverse: true }).get('[data-testid="stat-tile-delta"]')
        expect(up.classes()).toContain('bg-danger-soft')
        expect(up.classes()).toContain('delta-down')
        const down = mountTile({ delta: -10, reverse: true }).get('[data-testid="stat-tile-delta"]')
        expect(down.classes()).toContain('bg-success-soft')
        const icon = mountTile({ delta: 10, reverse: true }).getComponent({ name: 'i-icon' })
        expect(icon.attributes('icon')).toBe('arrow-up')
    })

    it('formats currency and percent by admin locale', () => {
        const text = (props: Record<string, unknown>) => mountTile(props).get('[data-testid="stat-tile-value"]').text()
        const initial = appRuntime.locale.value
        try {
            appRuntime.locale.value = 'vi'
            expect(text({ value: 1500000, format: 'currency' })).toContain('₫')
            expect(text({ value: 1248 })).toBe('1.248')
            expect(text({ value: 12.34, format: 'percent' })).toBe('12,3%')
            appRuntime.locale.value = 'en'
            expect(text({ value: 1500000, format: 'currency', currency: 'USD' })).toContain('$')
            expect(text({ value: 1500000, format: 'currency' })).toContain('₫')
            expect(text({ value: 1248 })).toBe('1,248')
            expect(text({ value: 12.34, format: 'percent' })).toBe('12.3%')
        } finally {
            appRuntime.locale.value = initial
        }
    })

    it('compacts values from one million up and keeps the full value in the title', () => {
        const w = mountTile({ value: 192900000, format: 'currency' })
        const value = w.get('[data-testid="stat-tile-value"]')
        expect(value.text()).toMatch(/192\.9M|192,9 Tr/)
        expect(value.attributes('title')).toMatch(/192,900,000|192\.900\.000/)
        const plain = mountTile({ value: 1234, format: 'currency', compact: false })
        expect(plain.get('[data-testid="stat-tile-value"]').attributes('title')).toBeUndefined()
    })

    it('stays neutral by default and marks only warning/danger with a dot', () => {
        expect(mountTile({ tone: 'success' }).find('[data-testid="stat-tile-dot"]').exists()).toBe(false)
        expect(mountTile({ tone: 'warning' }).find('[data-testid="stat-tile-dot"]').classes()).toContain('bg-warning')
        expect(mountTile({ tone: 'danger' }).find('[data-testid="stat-tile-dot"]').classes()).toContain('bg-danger')
        expect(mountTile({}).classes().join(' ')).not.toMatch(/border-l-/)
    })

    it('shows a muted unit, a suffix, a meter and a badge', () => {
        const w = mountTile({ value: 4, suffix: '/ 1.000', meter: 0.4, badge: 'Cần nạp', tone: 'warning' })
        expect(w.get('[data-testid="stat-tile-suffix"]').text()).toBe('/ 1.000')
        expect(w.get('[data-testid="stat-tile-meter"] span').attributes('style')).toContain('width: 0.4%')
        expect(w.get('[data-testid="stat-tile-badge"]').classes()).toContain('bg-warning-soft')
        const percent = mountTile({ value: 42, format: 'percent' }).get('[data-testid="stat-tile-value"]')
        expect(percent.findAll('span').map((x) => x.text())).toEqual(['42', '%'])
    })

    it('never shows the attention dot on a zero count', () => {
        expect(mountTile({ value: 0, tone: 'warning' }).find('[data-testid="stat-tile-dot"]').exists()).toBe(false)
        expect(mountTile({ value: 3, tone: 'warning' }).find('[data-testid="stat-tile-dot"]').exists()).toBe(true)
    })

    describe('spark and value tone (dashboard hub band spec)', () => {
        const mountWithChart = (props: Record<string, unknown>) =>
            mount(IStatTile, {
                props: { label: 'Views', value: 4, ...props },
                global: { stubs: { 'router-link': RouterLinkStub, 'i-icon': true, 'i-chart': true } },
            })

        it('draws a spark when the series has values', () => {
            const spark = mountWithChart({ spark: [0, 2, 5], sparkSlot: 3 }).find('[data-testid="stat-tile-spark"]')
            expect(spark.exists()).toBe(true)
            expect(spark.attributes('type')).toBe('spark')
        })

        it('hands the colour slot to the chart, so the spark follows a theme switch', () => {
            const spark = mountWithChart({ spark: [0, 2, 5], sparkSlot: 3 }).find('[data-testid="stat-tile-spark"]')
            expect(spark.attributes('first-slot')).toBe('3')
            expect(spark.attributes('options')).toBeUndefined()
        })

        it('draws no spark for all-zero data', () => {
            for (const spark of [[0, 0, 0], [], null]) {
                expect(mountWithChart({ spark }).find('[data-testid="stat-tile-spark"]').exists()).toBe(false)
            }
        })

        it('colours the value by valueTone except zero', () => {
            const valueClass = (props: Record<string, unknown>) =>
                mountWithChart(props).find('[data-testid="stat-tile-value"] span').classes()
            expect(valueClass({ value: 4, valueTone: 'warning' })).toContain('text-warning-ink')
            expect(valueClass({ value: 4, valueTone: 'warning' })).not.toContain('text-ink')
            expect(valueClass({ value: 0, valueTone: 'warning' })).not.toContain('text-warning-ink')
            expect(valueClass({ value: 7 })).toContain('text-ink')
        })
    })
})

