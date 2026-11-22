import { describe, expect, it } from 'vitest'
import { buildChartOptions, resolveChartColors } from '@/libs/chart-options'

const colors = {
    categorical: ['#1', '#2', '#3', '#4', '#5'],
    other: '#oo',
    status: { success: '#s', warning: '#w', danger: '#d', info: '#i' },
    surface: '#fff',
    grid: '#g',
    axis: '#a',
}

describe('resolveChartColors', () => {
    it('reads and trims the custom properties', () => {
        const map: Record<string, string> = {
            '--color-chart-1': ' #111 ', '--color-chart-2': '#222', '--color-chart-3': '#333',
            '--color-chart-4': '#444', '--color-chart-5': '#555', '--color-chart-other': '#999',
            '--color-chart-grid': '#eee', '--color-chart-axis': '#777', '--color-surface': '#fff',
            '--color-success': '#0a0', '--color-warning': '#fa0', '--color-danger': '#f00', '--color-info': '#00f',
        }
        const r = resolveChartColors({ getPropertyValue: (n: string) => map[n] ?? '' } as unknown as CSSStyleDeclaration)
        expect(r.categorical).toEqual(['#111', '#222', '#333', '#444', '#555'])
        expect(r.other).toBe('#999')
        expect(r.status).toEqual({ success: '#0a0', warning: '#fa0', danger: '#f00', info: '#00f' })
        expect([r.surface, r.grid, r.axis]).toEqual(['#fff', '#eee', '#777'])
    })
})

describe('buildChartOptions', () => {
    it('starts the categorical palette at firstSlot, so a one-series chart can take another slot', () => {
        expect(buildChartOptions({ type: 'spark', colors, firstSlot: 3 }).colors).toEqual(['#3', '#4', '#5', '#1', '#2'])
        expect(buildChartOptions({ type: 'spark', colors }).colors).toEqual(colors.categorical)
    })

    it('trend: smooth area, categorical colours in order, legend only from 2 series', () => {
        const o = buildChartOptions({ type: 'trend', colors, categories: ['a', 'b'], seriesCount: 1 })
        expect(o.chart.type).toBe('area')
        expect(o.chart.height).toBe(260)
        expect(o.colors).toEqual(colors.categorical)
        expect(o.stroke).toEqual({ curve: 'smooth', width: 2 })
        expect(o.fill.gradient.opacityFrom).toBe(0.25)
        expect(o.legend.show).toBe(false)
        expect(o.xaxis.categories).toEqual(['a', 'b'])
        expect(buildChartOptions({ type: 'trend', colors, seriesCount: 2 }).legend).toMatchObject({ show: true, position: 'top' })
        expect(Array.isArray(o.yaxis)).toBe(false)
    })

    it('status palette maps keys to status colours', () => {
        const o = buildChartOptions({ type: 'bars', colors, palette: 'status', statusKeys: ['danger', 'success'], seriesCount: 2 })
        expect(o.colors).toEqual(['#d', '#s'])
    })

    it('bars: rounded columns with a 2px surface gap', () => {
        const o = buildChartOptions({ type: 'bars', colors })
        expect(o.chart.type).toBe('bar')
        expect(o.plotOptions.bar).toMatchObject({ columnWidth: '70%', borderRadius: 2, borderRadiusApplication: 'end' })
        expect(o.stroke).toEqual({ width: 2, colors: ['#fff'] })
    })

    it('donut: no legend, surface gap, 70% hole, height 280', () => {
        const o = buildChartOptions({ type: 'donut', colors, seriesCount: 3 })
        expect(o.chart.type).toBe('donut')
        expect(o.chart.height).toBe(280)
        expect(o.legend.show).toBe(false)
        expect(o.stroke).toEqual({ width: 2, colors: ['#fff'] })
        expect(o.plotOptions.pie.donut.size).toBe('70%')
    })

    it('spark: sparkline area without tooltip or legend', () => {
        const o = buildChartOptions({ type: 'spark', colors, seriesCount: 3 })
        expect(o.chart.sparkline.enabled).toBe(true)
        expect(o.chart.height).toBe(32)
        expect(o.stroke.width).toBe(2)
        expect(o.tooltip.enabled).toBe(false)
        expect(o.legend.show).toBe(false)
    })

    it('honours height and formats numbers by locale', () => {
        const vi = buildChartOptions({ type: 'trend', colors, height: 300, locale: 'vi' })
        expect(vi.chart.height).toBe(300)
        expect(vi.yaxis.labels.formatter(1248)).toBe('1.248')
        expect(buildChartOptions({ type: 'trend', colors, locale: 'en' }).yaxis.labels.formatter(1248)).toBe('1,248')
    })
})
