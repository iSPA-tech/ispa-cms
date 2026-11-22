/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Plain-language wording helpers of the "Khu vực chung" editor: nothing here shows a key, an id or an ISO timestamp.

import { resolveSlotLabel, scopeBadgeLabel } from './slot-label'

/**
 * A server timestamp in the reader's own date and time, e.g. "21 thg 11, 2026, 14:05".
 *
 * @param {?string} iso ISO 8601 timestamp (UTC) from the API.
 * @param {string} [locale] BCP 47 locale; the browser's when omitted.
 * @returns {string} '' when the value is missing or not a date.
 */
export function formatLocalDateTime(iso, locale) {
    if (!iso) return ''
    const date = new Date(iso)
    if (Number.isNaN(date.getTime())) return ''
    try {
        return new Intl.DateTimeFormat(locale || undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
    } catch {
        return date.toLocaleString()
    }
}

/** Name shown for a placement: the widget's name in the admin language, else its original name, or a neutral word when it is unknown. */
export function widgetDisplayName(t, content) {
    return content?.widget_display_name || content?.widget_name || t('widget_site_layout.area.widget_unnamed')
}

/**
 * One row of the publish dialog in plain words.
 *
 * @param {(key: string, params?: object) => string} t
 * @param {{kind: string, slot_key?: string, slot_label?: ?string, slot_label_key?: ?string, widget_name?: ?string, scope_type?: string, scope_label?: ?string}} row
 *        A row of GET .../draft/changes; a null label is translated here from its key.
 * @returns {{kind: string, text: string, scope: string}} For example `{kind: 'added', text: 'Thêm “Banner” vào Đầu trang', scope: 'Toàn website'}`.
 */
export function describeChange(t, row) {
    const kind = ['added', 'removed', 'changed'].includes(row?.kind) ? row.kind : 'changed'
    const area = row?.slot_label || resolveSlotLabel(t, { key: row?.slot_key, label_key: row?.slot_label_key })
    const widget = row?.widget_name || t('widget_site_layout.area.widget_unnamed')

    return {
        kind,
        text: t(`widget_site_layout.area.change_${kind}`, { widget, area }),
        scope: row?.scope_label || scopeBadgeLabel(t, { scope_type: row?.scope_type ?? 'global' }),
    }
}

/** True when "where it shows" is fully chosen: a page type for 'template', a page for 'target'. */
export function isScopeComplete(scope) {
    if (scope?.scope_type === 'template') return Boolean(scope.scope_key)
    if (scope?.scope_type === 'target') return Boolean(scope.target_type) && Boolean(scope.target_id)
    return true
}
