/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

const TRACKING_QUALITY_KEYS = ['exact', 'mixed', 'legacy_best_effort', 'exact_since_boundary', 'unavailable']

/**
 * Label for a tracking data-quality code. Pass the caller's `t` (`$t` in templates): this module stays free of
 * the i18n instance so specs that mock vue-i18n can still import it. Without `t` the keys come back as-is.
 */
export const trackingQualityLabel = (quality, exactFromDate = null, t = (key) => key) => {
    const key = TRACKING_QUALITY_KEYS.includes(quality) ? quality : 'legacy_best_effort'
    const label = t(`text.tracking.quality.${key}`)

    return exactFromDate ? t('text.tracking.quality.since', { label, date: exactFromDate }) : label
}

export const normalizeTrackingChart = (payload = {}) => ({
    ...payload,
    label: Array.isArray(payload.label) ? payload.label : [],
    series: Array.isArray(payload.series)
        ? payload.series.map((series) => ({
            ...series,
            data: Array.isArray(series.data)
                ? series.data.map((value) => value === null ? null : Number(value ?? 0))
                : [],
        }))
        : [],
    data_quality: payload.data_quality || 'legacy_best_effort',
    exact_from_date: payload.exact_from_date || null,
    timezone: payload.timezone || null,
    range_total: payload.range_total ?? null,
    known_range_total: Number(payload.known_range_total ?? 0),
    legacy_missing_dates: payload.legacy_missing_dates || {},
})

export const normalizeTrackingTotals = (payload = {}) => ({
    ...payload,
    total_scan: Number(payload.total_scan ?? 0),
    total_visit: Number(payload.total_visit ?? 0),
    total_view: Number(payload.total_view ?? 0),
    data_quality: payload.data_quality || 'legacy_best_effort',
    total_scope: payload.total_scope || 'best_effort_lifetime',
})

export const getRelationTracking = async (params) => {
    const path = `${params.type}/${params.id}/track`

    return await ajax.get(path).then((res) => {
        if (res.data.data) {
            return normalizeTrackingTotals(res.data.data)
        }
        return normalizeTrackingTotals()
    })
}
