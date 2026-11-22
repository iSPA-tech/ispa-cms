/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Human label resolution for Slot catalog rows ({key, label_key, ...}).
 *
 * The backend contract sends `label_key` as an ADMIN-pack translation key
 * (widget_site_layout.slot.*). Older registrations (or a stale localStorage
 * i18n cache) can still surface unresolvable keys such as
 * `exampleproject::widget.slot.site_header_before`, which vue-i18n would render
 * verbatim. This helper tries, in order:
 *   1. the row's own label_key,
 *   2. the canonical `widget_site_layout.slot.<slot_key with [.:] -> _>` key,
 *   3. a humanized suffix of whatever identifier is left (never a raw
 *      namespaced key on screen).
 */
export function resolveSlotLabel(t, slot) {
    if (!slot) return ''

    const candidates = []
    if (slot.label_key) candidates.push(slot.label_key)
    if (slot.key) candidates.push(`widget_site_layout.slot.${String(slot.key).replace(/[.:]+/g, '_')}`)

    for (const key of candidates) {
        const translated = t(key)
        if (translated !== key) return translated
    }

    const raw = String(slot.label_key ?? slot.key ?? '')
    const suffix = raw.split(/[.:]/).filter(Boolean).pop() ?? raw
    return suffix.replace(/_/g, ' ')
}

/**
 * Region bucket for the structure tree / grouped canvas: maps a slot key to
 * one of `header` / `content` / `footer` / `other` (labels under
 * widget_site_layout.region.*). Keyed on the slot KEY (stable contract),
 * never the display label.
 */
export function regionForSlotKey(slotKey) {
    const key = String(slotKey ?? '')
    if (key.includes('header')) return 'header'
    if (key.includes('footer')) return 'footer'
    if (key.includes('content') || key.startsWith('page.')) return 'content'
    return 'other'
}

/** Region display order for grouped views (page order: top to bottom). */
export const REGION_ORDER = ['header', 'content', 'footer', 'other']

/** Stable sort into page order (header -> content -> footer -> other),
 * preserving registry order within a region — used so flat lane lists read
 * top-to-bottom like the real page. */
export function sortSlotsByPageOrder(slots) {
    return [...slots].sort(
        (a, b) => REGION_ORDER.indexOf(regionForSlotKey(a.key)) - REGION_ORDER.indexOf(regionForSlotKey(b.key))
    )
}

/** mdi icon per region, shared by every grouped-slot view. */
export const REGION_ICONS = {
    header: 'page-layout-header',
    content: 'page-layout-body',
    footer: 'page-layout-footer',
    other: 'shape-outline',
}

/**
 * Friendly scope badge label for a Placement (never the raw `global` /
 * `template` / `target` enum on screen): widget_site_layout.scope.<type>,
 * falling back to the raw type only if the pack misses the key.
 */
export function scopeBadgeLabel(t, placement) {
    const type = placement?.scope_type ?? 'global'
    const key = `widget_site_layout.scope.${type}`
    const translated = t(key)
    return translated === key ? type : translated
}

/** Longer scope description for tooltips/summaries: badge label plus the
 * concrete template key or target identity when one applies. */
export function scopeDetailLabel(t, placement) {
    const base = scopeBadgeLabel(t, placement)
    if (placement?.scope_type === 'template' && placement.scope_key) return `${base} · ${placement.scope_key}`
    if (placement?.scope_type === 'target' && placement.target_id) {
        return `${base} · ${placement.target_type}:${placement.target_id}`
    }
    return base
}
