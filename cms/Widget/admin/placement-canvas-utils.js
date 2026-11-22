/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Pure placement-array mutation helpers of the "Khu vực chung" editor (pages/placements.vue: reorder, remove, change
// where a widget shows). Every export is a PURE function -- no Vue reactivity, no I/O -- mirroring
// site-layout-state.js's style so cardinality/scope behavior stays directly unit-testable without mounting
// anything. A caller only transforms the placements array and hands the result to the composable's
// updateLocalDraft(); the helpers never call the API.
//
// Placement objects here use the Draft Save REQUEST shape exactly
// (UpdateWidgetLayoutDraftRequest::rules() / cms/Widget/app/Models/WidgetPlacement.php):
// { slot_key, scope_type, scope_key, target_type, target_id,
//   widget_instance_uuid, widget_instance_version, position, enabled, rules }
// -- because localDraft.placements is sent verbatim as the wire request body
// once a later task wires Save Draft. No client-only fields are ever added
// to a placement object for this reason.
//
// Scope and cardinality rules mirror the plan's frozen "Scope and
// cardinality" contract and architecture.md's "Slot and Scope Contract":
// global uses scope_key = null; template uses one registered template key;
// target uses target_type + target_id (never an encoded free-form string);
// at most one ACTIVE Placement is allowed per EXACT scope identity for
// single/replace Slots, but global and target overrides may coexist (DoD
// 1415) because they are different exact scope identities.

export const SCOPE_GLOBAL = 'global'
export const SCOPE_TEMPLATE = 'template'
export const SCOPE_TARGET = 'target'

export const CARDINALITY_STACK = 'stack'
export const CARDINALITY_SINGLE = 'single'
export const CARDINALITY_REPLACE = 'replace'

/**
 * The exact scope identity a Placement belongs to (plan "Scope and
 * cardinality": "stack merges global, then template, then target; positions
 * are normalized inside each exact scope"). Two placements share an exact
 * scope identity only when scope_type matches AND (for template) scope_key
 * matches, or (for target) BOTH target_type and target_id match.
 */
export function exactScopeIdentity(p) {
    if (p.scope_type === SCOPE_TEMPLATE) return `${SCOPE_TEMPLATE}:${p.scope_key}`
    if (p.scope_type === SCOPE_TARGET) return `${SCOPE_TARGET}:${p.target_type}:${p.target_id}`
    return SCOPE_GLOBAL
}

/** Groups placements for position-normalization and cardinality purposes:
 * same Slot AND same exact scope identity. */
export function groupKey(p) {
    return `${p.slot_key}::${exactScopeIdentity(p)}`
}

/**
 * Renormalizes `position` to a contiguous 0..n-1 sequence PER exact-scope
 * group, preserving the array's existing relative order within each group
 * (the array order itself already reflects drag/reorder/add/delete intent;
 * this only fixes up the `position` field values to match).
 */
export function normalizePositions(placements) {
    const counters = new Map()
    return placements.map((p) => {
        const key = groupKey(p)
        const next = counters.get(key) ?? 0
        counters.set(key, next + 1)
        return { ...p, position: next }
    })
}

/**
 * SHAPE ONLY validation of whether `candidate` may be added to `placements`
 * given `slot`'s cardinality/supported_scope_types -- does not mutate
 * anything. Mirrors the "Effective resolution" rules in architecture.md's
 * "Slot and Scope Contract":
 *  - a scope_type the Slot does not support is always invalid;
 *  - 'stack' Slots always accept another placement in the same exact scope;
 *  - 'single'/'replace' Slots accept at most one ACTIVE (enabled) placement
 *    per exact scope identity -- 'single' rejects a second one outright,
 *    'replace' accepts it and reports which existing placement it replaces;
 *  - a disabled placement never counts against the single/replace limit;
 *  - placing the SAME widget instance twice into the same exact scope is
 *    always rejected (duplicate), regardless of cardinality.
 *
 * @returns {{valid: boolean, reason?: string, replaces?: object}}
 */
export function canAddPlacement(placements, candidate, slot) {
    if (!slot || !slot.supported_scope_types?.includes(candidate.scope_type)) {
        return { valid: false, reason: 'unsupported_scope' }
    }

    const sameGroup = placements.filter((p) => groupKey(p) === groupKey(candidate))

    const duplicateInstance = sameGroup.some((p) => p.widget_instance_uuid === candidate.widget_instance_uuid)
    if (duplicateInstance) {
        return { valid: false, reason: 'duplicate_instance' }
    }

    if (slot.cardinality === CARDINALITY_STACK) {
        return { valid: true }
    }

    const existingActive = sameGroup.find((p) => p.enabled)
    if (!existingActive) {
        return { valid: true }
    }

    if (slot.cardinality === CARDINALITY_REPLACE) {
        return { valid: true, replaces: existingActive }
    }

    return { valid: false, reason: 'cardinality_single' }
}

/**
 * Appends `candidate` (or replaces the existing placement `canAddPlacement`
 * flags for 'replace' cardinality Slots) and renormalizes positions. Returns
 * the ORIGINAL array reference (untouched) plus an error reason when invalid
 * -- callers use `error === null` to detect success without a try/catch.
 */
export function addPlacement(placements, candidate, slot) {
    const check = canAddPlacement(placements, candidate, slot)
    if (!check.valid) {
        return { placements, error: check.reason }
    }

    const base = check.replaces ? placements.filter((p) => p !== check.replaces) : placements
    return { placements: normalizePositions([...base, candidate]), error: null }
}

/** Removes `target` (by reference) and renormalizes remaining positions.
 * `removed` captures enough to reverse the removal via restorePlacement. */
export function removePlacement(placements, target) {
    const index = placements.indexOf(target)
    if (index === -1) {
        return { placements, removed: null }
    }

    const next = placements.filter((_, i) => i !== index)
    return { placements: normalizePositions(next), removed: { item: target, index } }
}

/** Reinserts a `removePlacement` result's `removed` entry at its original
 * index and renormalizes -- the single-level Undo affordance. */
export function restorePlacement(placements, removed) {
    if (!removed) return placements
    const next = [...placements]
    next.splice(Math.min(removed.index, next.length), 0, removed.item)
    return normalizePositions(next)
}

/**
 * Swaps `target` with its adjacent sibling WITHIN THE SAME exact-scope group
 * only (architecture.md: "positions are normalized inside each exact
 * scope"). A no-op (returns the SAME array reference) at either boundary of
 * the group, so callers can cheaply detect "nothing changed" via `===`.
 *
 * @param {'up'|'down'} direction
 */
export function movePlacement(placements, target, direction) {
    const key = groupKey(target)
    const groupIndices = []
    placements.forEach((p, i) => {
        if (groupKey(p) === key) groupIndices.push(i)
    })

    const targetArrayIndex = placements.indexOf(target)
    const posInGroup = groupIndices.indexOf(targetArrayIndex)
    const swapWith = direction === 'up' ? posInGroup - 1 : posInGroup + 1

    if (posInGroup === -1 || swapWith < 0 || swapWith >= groupIndices.length) {
        return placements
    }

    const otherArrayIndex = groupIndices[swapWith]
    const next = [...placements]
    ;[next[targetArrayIndex], next[otherArrayIndex]] = [next[otherArrayIndex], next[targetArrayIndex]]
    return normalizePositions(next)
}

/**
 * Move-to-Slot chooser mutation: rewrites `target`'s slot_key/scope onto a
 * new destination, validated by `canAddPlacement` against the DESTINATION
 * slot's cardinality/supported scopes (the source placement is excluded from
 * that check first, since it is being relocated, not duplicated). Returns
 * the ORIGINAL array untouched plus an error reason when the destination is
 * invalid.
 *
 * @param {{slotKey: string, scopeType: string, scopeKey?: string|null, targetType?: string|null, targetId?: string|null}} destination
 */
export function moveToSlot(placements, target, destination, destinationSlot) {
    const moved = {
        ...target,
        slot_key: destination.slotKey,
        scope_type: destination.scopeType,
        scope_key: destination.scopeType === SCOPE_TEMPLATE ? (destination.scopeKey ?? null) : null,
        target_type: destination.scopeType === SCOPE_TARGET ? (destination.targetType ?? null) : null,
        target_id: destination.scopeType === SCOPE_TARGET ? (destination.targetId ?? null) : null,
    }

    const withoutTarget = placements.filter((p) => p !== target)
    const check = canAddPlacement(withoutTarget, moved, destinationSlot)
    if (!check.valid) {
        return { placements, error: check.reason }
    }

    const base = check.replaces ? withoutTarget.filter((p) => p !== check.replaces) : withoutTarget
    return { placements: normalizePositions([...base, moved]), error: null }
}
