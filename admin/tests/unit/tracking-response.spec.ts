import { describe, expect, it } from 'vitest'
import {
    normalizeTrackingChart,
    normalizeTrackingTotals,
    trackingQualityLabel,
} from '../../src/libs/tracking'

describe('tracking response compatibility', () => {
    it('keeps legacy nulls as chart gaps', () => {
        const chart = normalizeTrackingChart({
            label: ['21/07', '22/07'],
            series: [{ name: 'Views', data: [5, null] }],
        })

        expect(chart.series[0].data).toEqual([5, null])
        expect(chart.data_quality).toBe('legacy_best_effort')
        expect(chart.range_total).toBeNull()
    })

    it('preserves mixed multi-series ownership and exact metadata', () => {
        const chart = normalizeTrackingChart({
            label: ['21/07', '22/07', '23/07'],
            series: [
                { name: 'Views', data: [5, null, 8] },
                { name: 'Visits', data: [null, 2, 3] },
            ],
            data_quality: 'mixed',
            exact_from_date: '2026-07-23',
            timezone: 'Asia/Ho_Chi_Minh',
            known_range_total: 13,
            legacy_missing_dates: {
                views: ['2026-07-22'],
                visitors: ['2026-07-21'],
            },
        })

        expect(chart.series[0].data).toEqual([5, null, 8])
        expect(chart.series[1].data).toEqual([null, 2, 3])
        expect(chart.legacy_missing_dates.views).toEqual(['2026-07-22'])
        expect(chart.exact_from_date).toBe('2026-07-23')
    })

    it('normalizes old and new counter responses without changing field names', () => {
        expect(normalizeTrackingTotals({
            total_scan: '2',
            total_visit: '3',
            total_view: '4',
            data_quality: 'exact',
            total_scope: 'lifetime',
        })).toMatchObject({
            total_scan: 2,
            total_visit: 3,
            total_view: 4,
            data_quality: 'exact',
            total_scope: 'lifetime',
        })

        expect(normalizeTrackingTotals()).toMatchObject({
            total_scan: 0,
            total_visit: 0,
            total_view: 0,
            data_quality: 'legacy_best_effort',
        })
    })

    it('shows an explicit local-date boundary', () => {
        const t = (key, params = {}) => `${key}${Object.keys(params).length ? JSON.stringify(params) : ''}`

        expect(trackingQualityLabel('mixed', '2026-07-23', t))
            .toBe('text.tracking.quality.since{"label":"text.tracking.quality.mixed","date":"2026-07-23"}')
    })
})
