import { describe, expect, it } from 'vitest'
import groupedBarFixture from './fixtures-grouped-bar-chart.json?raw'
import columnFixture from './fixtures-column-chart.json?raw'
import { ChartImportError, parseChartJsSnippet } from '../../components/data-chart-import-parser'

// Failures carry an i18n key (and params) instead of text; the screen that shows them translates the key.

const chartImportFailure = (source: string) => {
    try {
        parseChartJsSnippet(source)
    } catch (error) {
        return error as ChartImportError
    }
    throw new Error('Expected the chart import to fail')
}

const charts = `
const ctxTCO = document.getElementById('chartTCO').getContext('2d');
new Chart(ctxTCO, { type: 'line', data: {
  labels: ['Năm 1', 'Năm 2', 'Năm 3'].map(wrapLabel),
  datasets: [
    { label: 'Xe Xăng', data: [29, 58, 87], borderColor: '#D97706', borderWidth: 3 },
    { label: 'Xe Hybrid', data: [17, 34, 51], borderColor: '#2563EB', borderDash: [5, 5] },
    { label: 'Xe Điện', data: [8.7, 17.4, 26.1], borderColor: '#10B981' },
    { label: 'Xe Điện Sạc Đêm', data: [4.2, 8.4, 12.6], borderColor: '#059669', borderDash: [3, 3] }
  ]}, options: { plugins: { tooltip: universalTooltipConfig }, scales: { y: { title: { text: 'Triệu VNĐ' } } } }
});
new Chart(ctxMaintenance, { type: 'bar', data: {
  labels: ['Dầu Nhớt', 'Phanh', 'Lốp'].map(wrapLabel),
  datasets: [
    { label: 'Xe Xăng', data: [16, 7, 12], backgroundColor: '#F59E0B' },
    { label: 'Xe Điện', data: [0, 3, 15], backgroundColor: '#10B981' }
  ]}, options: { indexAxis: 'y', scales: { x: { title: { text: 'Chi Phí 5 Năm' } } } }
});
new Chart(ctxBattery, { type: 'line', data: {
  labels: ['Năm 0', 'Năm 1', 'Năm 2'].map(wrapLabel),
  datasets: [
    { label: 'Pin LFP', data: [100, 97, 95], borderColor: '#10B981', fill: true },
    { label: 'Pin NMC', data: [100, 95, 92], borderColor: '#2563EB', backgroundColor: 'transparent', borderDash: [4, 4] }
  ]}, options: { scales: { y: { min: 60, max: 100, title: { text: 'SoH %' } } } }
});
new Chart(ctxDepreciation, { type: 'line', data: {
  labels: ['Mới', 'Năm 1', 'Năm 2'].map(wrapLabel),
  datasets: [
    { label: 'Xe Xăng', data: [100, 85, 76], borderColor: '#D97706' },
    { label: 'Xe Điện', data: [100, 83, 74], borderColor: '#10B981' },
    { label: 'Xe Hybrid', data: [100, 84, 75], borderColor: '#6366F1', borderDash: [3, 3] }
  ]}, options: { scales: { y: { min: 0, max: 100, title: { text: 'Giữ Giá (%)' } } } }
});`

const groupedBarSource: string = groupedBarFixture

describe('Chart.js quick paste parser', () => {
    it('extracts all four charts and normalizes series, colors and axes', () => {
        const result = parseChartJsSnippet(charts)

        expect(result).toHaveLength(4)
        expect(result[0].value).toMatchObject({
            version: 1,
            chart_type: 'line',
            labels: ['Năm 1', 'Năm 2', 'Năm 3'],
            axis_label: 'Triệu VNĐ',
            notes: [],
            series: [
                { name: 'Xe Xăng', color: 'orange', line_style: 'solid', values: [29, 58, 87] },
                { name: 'Xe Hybrid', color: 'blue', line_style: 'dashed', values: [17, 34, 51] },
                { name: 'Xe Điện', color: 'green', line_style: 'solid', values: [8.7, 17.4, 26.1] },
                {
                    name: 'Xe Điện Sạc Đêm',
                    color: 'teal',
                    line_style: 'dotted',
                    values: [4.2, 8.4, 12.6],
                },
            ],
        })
        expect(result[1]).toMatchObject({
            value: {
                chart_type: 'horizontal_bar',
                axis_label: 'Chi Phí 5 Năm',
                series: [{ values: [16, 7, 12] }, { values: [0, 3, 15] }],
            },
            options: { layout: 'wide' },
        })
        expect(result[2]).toMatchObject({
            value: {
                chart_type: 'line',
                axis_label: 'SoH %',
                series: [{ fill: true }, { fill: false }],
            },
            options: { axis_min: 60, axis_max: 100 },
        })
        expect(result[3]).toMatchObject({
            value: {
                chart_type: 'line',
                series: [{ color: 'orange' }, { color: 'green' }, { color: 'indigo' }],
            },
            options: { axis_min: 0, axis_max: 100 },
        })
    })

    it('maps stacked bars and unknown colors without storing raw CSS', () => {
        const result = parseChartJsSnippet(`new Chart(ctx, {
          type: 'bar', data: { labels: ['A', 'B'], datasets: [{ label: 'Series', data: [1, 2], backgroundColor: 'url(https://evil.test)' }] },
          options: { scales: { x: { stacked: true }, y: { stacked: true } } }
        });`)

        expect(result[0].value.chart_type).toBe('stacked_bar')
        expect(result[0].value.series[0].color).toBe('blue')
    })

    it('imports a stacked horizontal Chart.js bar as grouped bars with groups, gaps and emphasis', () => {
        const [chart] = parseChartJsSnippet(groupedBarSource)

        expect(chart.value).toMatchObject({
            chart_type: 'grouped_bar',
            title: 'Kể cả sạc giờ cao điểm, VF 6 chỉ tốn 279 nghìn đồng cho 400 km – bằng 60% chiếc xe xăng tiết kiệm nhất',
            subtitle: expect.stringContaining('hiệu suất sạc 90%'),
            axis_label: 'đồng / 400 km',
            labels: ['Sạc giờ thấp điểm (1.620 đ/kWh)', 'Sạc giờ bình thường (2.700 đ/kWh)', 'Trạm V-Green (3.858 đ/kWh)*', 'Sạc giờ cao điểm (4.212 đ/kWh)', 'Tiết kiệm 5,5 L/100 km', 'Trung bình 7,0 L/100 km', 'Tốn xăng 9,0 L/100 km'],
            row_groups: ['VF 6', 'VF 6', 'VF 6', 'VF 6', 'Xe xăng', 'Xe xăng', 'Xe xăng'],
        })
        expect(chart.value.series.map((item: { color: string }) => item.color)).toEqual(['blue', 'gray', 'gray_light'])
        expect(chart.value.series[0].values).toEqual([107280, 178800, 229937, 278928, null, null, null])
        expect(chart.value.series[2].values).toEqual([null, null, null, null, 132000, 168000, 216000])
        expect(chart.options).toEqual({ layout: 'wide', highlight_row: 3, value_scale: 1000 })
    })

    it('imports a vertical bar chart with line datasets as columns with reference lines', () => {
        const [chart] = parseChartJsSnippet(columnFixture as string)

        expect(chart.value).toMatchObject({
            chart_type: 'column',
            axis_label: 'đồng / 400 km',
            labels: ['Giá không đổi', '+10%', '+25%', '+50%'],
        })
        expect(chart.value.series.map((item: { name: string; color: string; as_line: boolean; line_style: string }) => [item.color, item.as_line, item.line_style])).toEqual([
            ['gray', false, 'solid'],
            ['gray_light', false, 'solid'],
            ['gray', true, 'dashed'],
            ['gray', true, 'dashed'],
        ])
        expect(chart.value.series[2].values).toEqual([278928, 278928, 278928, 278928])
        // The one bar coloured differently from the others marks the emphasised label.
        expect(chart.options).toEqual({ layout: 'wide', highlight_row: 3, value_scale: 1000 })
    })

    it('imports pie, doughnut and radar configurations', () => {
        const [pie] = parseChartJsSnippet(`{ type: 'doughnut', data: { labels: ['VinFast', 'Toyota', 'Khác'], datasets: [{ label: 'Thị phần', data: [40, 35, 25], backgroundColor: ['#2563eb', '#d97706', '#9ca3af'] }] } }`)
        expect(pie.value.chart_type).toBe('pie')
        expect(pie.value.series[0].values).toEqual([40, 35, 25])
        expect(pie.options).toEqual({ layout: 'wide', donut: true })

        const [radar] = parseChartJsSnippet(`{ type: 'radar', data: { labels: ['Tăng tốc', 'Tầm xa', 'Giá', 'Nội thất'], datasets: [{ label: 'VF 6', data: [8, 7, 9, 8] }, { label: 'Xe xăng', data: [6, 9, 7, 7], borderColor: '#d97706' }] } }`)
        expect(radar.value.chart_type).toBe('radar')
        expect(radar.value.series.map((item: { color: string }) => item.color)).toEqual(['blue', 'orange'])
        expect(radar.options).toEqual({ layout: 'wide' })

        expect(() => parseChartJsSnippet(`{ type: 'pie', data: { labels: ['A', 'B'], datasets: [{ label: 'S', data: [1, 2] }, { label: 'T', data: [3, 4] }] } }`)).toThrow(/item_count_series/)
        expect(() => parseChartJsSnippet(`{ type: 'radar', data: { labels: ['A', 'B'], datasets: [{ label: 'S', data: [1, 2] }] } }`)).toThrow(/item_count_labels/)
    })

    it('keeps rejecting null data outside grouped bars', () => {
        expect(() => parseChartJsSnippet(`{ type: 'line', data: { labels: ['A', 'B'], datasets: [{ label: 'S', data: [1, null] }] } }`)).toThrow()
    })

    it('accepts a bare data object with line default and a full config object', () => {
        const bare = parseChartJsSnippet(
            `{"labels":["A","B"],"datasets":[{"label":"S","data":[2,4]}]}`
        )
        expect(bare[0]).toMatchObject({
            value: {
                chart_type: 'line',
                labels: ['A', 'B'],
                series: [{ name: 'S', values: [2, 4] }],
            },
            options: { layout: 'wide' },
        })

        const config = parseChartJsSnippet(
            `{type:'bar', data:{labels:['A','B'], datasets:[{label:'S', data:[2,4]}]}, options:{indexAxis:'y'}}`
        )
        expect(config[0].value.chart_type).toBe('horizontal_bar')
    })

    it('never evaluates pasted expressions and rejects dynamic required data', () => {
        ;(globalThis as any).__chartImportExecuted = false
        expect(() =>
            parseChartJsSnippet(`new Chart(ctx, { type: 'line', data: {
          labels: ['A', 'B'], datasets: [{ label: 'S', data: [1, (globalThis.__chartImportExecuted = true)] }]
        } });`)
        ).toThrow(/chart-import\.error\.not_static_numbers/)
        expect((globalThis as any).__chartImportExecuted).toBe(false)

        expect(() =>
            parseChartJsSnippet(`new Chart(ctx, { type: 'line', data: {
          labels: getLabels(), datasets: [{ label: 'S', data: [1, 2] }]
        } });`)
        ).toThrow(/chart-import\.error\.item_count_labels/)
        expect((globalThis as any).__chartImportExecuted).toBe(false)
    })

    it('rejects malformed, absent and unsupported chart configurations', () => {
        expect(() => parseChartJsSnippet('new Chart(ctx, {')).toThrow(/chart-import\.error\.syntax/)
        expect(() => parseChartJsSnippet('const x = 1')).toThrow(/chart-import\.error\.no_chart/)
        expect(() =>
            parseChartJsSnippet(
                `new Chart(ctx, { type: 'bubble', data: { labels: ['A', 'B'], datasets: [{label: 'S', data: [1, 2]}] } });`
            )
        ).toThrow(/chart-import\.error\.unsupported_type/)
        expect(() =>
            parseChartJsSnippet(
                `new Chart(ctx, { type: 'line', data: { labels: ['A', 'B'], datasets: [{label: 'S', data: [1]}] } });`
            )
        ).toThrow(/chart-import\.error\.count_mismatch/)
    })

    it('caps source and chart count before making browser parse too much input', () => {
        expect(() => parseChartJsSnippet(' '.repeat(100_001))).toThrow(
            /chart-import\.error\.source_too_large/
        )
        const one = `new Chart(ctx,{type:'line',data:{labels:['A','B'],datasets:[{label:'S',data:[1,2]}]}});`
        expect(() => parseChartJsSnippet(one.repeat(21))).toThrow(/chart-import\.error\.too_many/)
    })

    it('throws errors that carry the translation key and params', () => {
        const dynamicLabels = chartImportFailure(`new Chart(ctx, { type: 'line', data: {
          labels: getLabels(), datasets: [{ label: 'S', data: [1, 2] }]
        } });`)

        expect(dynamicLabels).toBeInstanceOf(ChartImportError)
        expect(dynamicLabels.key).toBe('text.chart-import.error.item_count_labels')
        expect(dynamicLabels.params).toEqual({ min: 2, max: 20 })

        const duplicate = chartImportFailure(
            `{type:'line', type:'bar', data:{labels:['A','B'], datasets:[{label:'S', data:[2,4]}]}}`
        )
        expect(duplicate.key).toBe('text.chart-import.error.duplicate_property')
        expect(duplicate.params).toEqual({ name: 'type' })
    })
})
