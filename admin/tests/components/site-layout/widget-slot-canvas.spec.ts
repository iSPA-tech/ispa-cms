/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// The pure placement helpers (placement-canvas-utils.js): scope identity, positions, add/remove/restore/move.
// The list canvas, its card and its catalog panel were removed with the "Khu vực chung" editor (Unified Widget
// Catalog Part 2, Task 9), which arranges widgets from the area list instead; the helpers stay in use there.
// Placements use the REQUEST shape (slot_key, scope_type, scope_key, target_type, target_id, widget_instance_uuid,
// widget_instance_version, position, enabled, rules): the local draft is sent as the Draft Save request body.

import { describe, expect, it, vi } from 'vitest'
import * as utils from '../../../../cms/Widget/admin/placement-canvas-utils'

// The helpers import nothing from api.js; the mock only keeps an accidental import from reading real config.
vi.mock('../../../../cms/Widget/admin/api', () => ({
    listTargetContexts: vi.fn().mockResolvedValue([]),
    searchWidgetTargets: vi.fn().mockResolvedValue({ data: [], meta: null, links: null }),
    getWidgetTargetDetail: vi.fn().mockResolvedValue(null),
}))


// =============================================================================
// PART A -- pure placement-canvas-utils.js (no mocks, no mount)
// =============================================================================

const slot = (overrides = {}) => ({
    key: 'site_header_before',
    label_key: 'nav.a.site-layout-slot-header-before',
    cardinality: 'stack',
    supported_scope_types: ['global', 'template', 'target'],
    fallback: 'empty',
    trust_lane: 'standard',
    availability: 'available',
    ...overrides,
})

const placement = (overrides = {}) => ({
    slot_key: 'site_header_before',
    scope_type: 'global',
    scope_key: null,
    target_type: null,
    target_id: null,
    widget_instance_uuid: 'instance-1',
    widget_instance_version: 1,
    position: 0,
    enabled: true,
    rules: {},
    ...overrides,
})

describe('exactScopeIdentity / groupKey', () => {
    it('global scope identity ignores scope_key/target fields entirely', () => {
        const a = utils.exactScopeIdentity(placement({ scope_type: 'global' }))
        const b = utils.exactScopeIdentity(placement({ scope_type: 'global', scope_key: 'ignored' }))
        expect(a).toBe(b)
    })

    it('template scope identity is keyed by scope_key', () => {
        const a = utils.exactScopeIdentity(placement({ scope_type: 'template', scope_key: 'page.static' }))
        const b = utils.exactScopeIdentity(placement({ scope_type: 'template', scope_key: 'site.shell' }))
        expect(a).not.toBe(b)
    })

    it('target scope identity is keyed by target_type + target_id, not an encoded string', () => {
        const a = utils.exactScopeIdentity(
            placement({ scope_type: 'target', target_type: 'page', target_id: 'uuid-a' })
        )
        const b = utils.exactScopeIdentity(
            placement({ scope_type: 'target', target_type: 'page', target_id: 'uuid-b' })
        )
        expect(a).not.toBe(b)
    })

    it('groupKey combines slot_key with the exact scope identity', () => {
        const p1 = placement({ slot_key: 'a' })
        const p2 = placement({ slot_key: 'b' })
        expect(utils.groupKey(p1)).not.toBe(utils.groupKey(p2))
    })
})

describe('normalizePositions', () => {
    it('renormalizes position to a contiguous 0..n-1 sequence per exact-scope group, preserving array order', () => {
        const placements = [
            placement({ widget_instance_uuid: 'i1', position: 5 }),
            placement({ widget_instance_uuid: 'i2', position: 9 }),
            placement({ slot_key: 'other', widget_instance_uuid: 'i3', position: 2 }),
        ]
        const result = utils.normalizePositions(placements)
        expect(result[0].position).toBe(0)
        expect(result[1].position).toBe(1)
        // Different group (different slot_key) starts its own sequence at 0.
        expect(result[2].position).toBe(0)
    })
})

describe('canAddPlacement -- cardinality + scope validation (invalid drops)', () => {
    it('rejects a scope_type the slot does not support', () => {
        const s = slot({ supported_scope_types: ['global'] })
        const candidate = placement({ scope_type: 'target', target_type: 'page', target_id: 'x' })
        const result = utils.canAddPlacement([], candidate, s)
        expect(result.valid).toBe(false)
        expect(result.reason).toBe('unsupported_scope')
    })

    it('stack cardinality always allows another placement in the same exact scope', () => {
        const s = slot({ cardinality: 'stack' })
        const existing = [placement({ widget_instance_uuid: 'i1' })]
        const candidate = placement({ widget_instance_uuid: 'i2' })
        expect(utils.canAddPlacement(existing, candidate, s).valid).toBe(true)
    })

    it('single cardinality rejects a second ACTIVE placement in the SAME exact scope', () => {
        const s = slot({ cardinality: 'single' })
        const existing = [placement({ widget_instance_uuid: 'i1', scope_type: 'global' })]
        const candidate = placement({ widget_instance_uuid: 'i2', scope_type: 'global' })
        const result = utils.canAddPlacement(existing, candidate, s)
        expect(result.valid).toBe(false)
        expect(result.reason).toBe('cardinality_single')
    })

    it('single cardinality does NOT count a disabled placement against the limit', () => {
        const s = slot({ cardinality: 'single' })
        const existing = [placement({ widget_instance_uuid: 'i1', scope_type: 'global', enabled: false })]
        const candidate = placement({ widget_instance_uuid: 'i2', scope_type: 'global' })
        expect(utils.canAddPlacement(existing, candidate, s).valid).toBe(true)
    })

    it('replace cardinality allows the add and flags the existing placement it replaces', () => {
        const s = slot({ cardinality: 'replace' })
        const existing = [placement({ widget_instance_uuid: 'i1', scope_type: 'global' })]
        const candidate = placement({ widget_instance_uuid: 'i2', scope_type: 'global' })
        const result = utils.canAddPlacement(existing, candidate, s)
        expect(result.valid).toBe(true)
        expect(result.replaces).toBe(existing[0])
    })

    it('DoD 1415: a target-scope override is NOT rejected merely because a global single Placement exists', () => {
        const s = slot({ cardinality: 'single', supported_scope_types: ['global', 'target'] })
        const existing = [placement({ widget_instance_uuid: 'i1', scope_type: 'global' })]
        const candidate = placement({
            widget_instance_uuid: 'i2',
            scope_type: 'target',
            target_type: 'page',
            target_id: 'uuid-a',
        })
        const result = utils.canAddPlacement(existing, candidate, s)
        expect(result.valid).toBe(true)
        expect(result.replaces).toBeUndefined()
    })

    it('rejects placing the exact same widget instance twice into the same exact scope', () => {
        const s = slot({ cardinality: 'stack' })
        const existing = [placement({ widget_instance_uuid: 'same' })]
        const candidate = placement({ widget_instance_uuid: 'same' })
        const result = utils.canAddPlacement(existing, candidate, s)
        expect(result.valid).toBe(false)
        expect(result.reason).toBe('duplicate_instance')
    })
})

describe('addPlacement', () => {
    it('appends and normalizes positions on a valid add', () => {
        const s = slot({ cardinality: 'stack' })
        const existing = [placement({ widget_instance_uuid: 'i1', position: 0 })]
        const candidate = placement({ widget_instance_uuid: 'i2' })
        const { placements: result, error } = utils.addPlacement(existing, candidate, s)
        expect(error).toBeNull()
        expect(result.map((p) => p.widget_instance_uuid)).toEqual(['i1', 'i2'])
        expect(result[1].position).toBe(1)
    })

    it('returns the unchanged array plus an error reason on an invalid add', () => {
        const s = slot({ cardinality: 'single' })
        const existing = [placement({ widget_instance_uuid: 'i1' })]
        const candidate = placement({ widget_instance_uuid: 'i2' })
        const { placements: result, error } = utils.addPlacement(existing, candidate, s)
        expect(error).toBe('cardinality_single')
        expect(result).toBe(existing)
    })

    it('replace cardinality drops the old placement it replaces', () => {
        const s = slot({ cardinality: 'replace' })
        const existing = [placement({ widget_instance_uuid: 'i1' })]
        const candidate = placement({ widget_instance_uuid: 'i2' })
        const { placements: result } = utils.addPlacement(existing, candidate, s)
        expect(result.map((p) => p.widget_instance_uuid)).toEqual(['i2'])
    })
})

describe('removePlacement / restorePlacement (delete + undo)', () => {
    it('removes the target placement and normalizes remaining positions', () => {
        const existing = [
            placement({ widget_instance_uuid: 'i1', position: 0 }),
            placement({ widget_instance_uuid: 'i2', position: 1 }),
        ]
        const { placements: result, removed } = utils.removePlacement(existing, existing[0])
        expect(result.map((p) => p.widget_instance_uuid)).toEqual(['i2'])
        expect(result[0].position).toBe(0)
        expect(removed.item).toBe(existing[0])
    })

    it('restorePlacement reinserts the removed item at its original index and renormalizes', () => {
        const existing = [
            placement({ widget_instance_uuid: 'i1', position: 0 }),
            placement({ widget_instance_uuid: 'i2', position: 1 }),
        ]
        const { placements: afterDelete, removed } = utils.removePlacement(existing, existing[0])
        const restored = utils.restorePlacement(afterDelete, removed)
        expect(restored.map((p) => p.widget_instance_uuid)).toEqual(['i1', 'i2'])
        expect(restored[0].position).toBe(0)
        expect(restored[1].position).toBe(1)
    })
})

describe('movePlacement (move up/down within the same exact scope)', () => {
    it('moving the second item up swaps it with the first, within the same group only', () => {
        const existing = [
            placement({ widget_instance_uuid: 'i1', position: 0 }),
            placement({ widget_instance_uuid: 'i2', position: 1 }),
        ]
        const result = utils.movePlacement(existing, existing[1], 'up')
        expect(result.map((p) => p.widget_instance_uuid)).toEqual(['i2', 'i1'])
    })

    it('is a no-op at the top boundary (cannot move the first item up)', () => {
        const existing = [
            placement({ widget_instance_uuid: 'i1', position: 0 }),
            placement({ widget_instance_uuid: 'i2', position: 1 }),
        ]
        const result = utils.movePlacement(existing, existing[0], 'up')
        expect(result).toBe(existing)
    })

    it('never swaps across different exact-scope groups', () => {
        const existing = [
            placement({ widget_instance_uuid: 'i1', slot_key: 'a', position: 0 }),
            placement({ widget_instance_uuid: 'i2', slot_key: 'b', position: 0 }),
        ]
        const result = utils.movePlacement(existing, existing[1], 'up')
        expect(result).toBe(existing)
    })
})

describe('moveToSlot (Move-to-Slot chooser target mutation)', () => {
    it('changes slot_key/scope on the target placement when the destination accepts it', () => {
        const s = slot({ key: 'site_footer_before', cardinality: 'stack' })
        const existing = [placement({ widget_instance_uuid: 'i1', slot_key: 'site_header_before' })]
        const { placements: result, error } = utils.moveToSlot(
            existing,
            existing[0],
            { slotKey: 'site_footer_before', scopeType: 'global' },
            s
        )
        expect(error).toBeNull()
        expect(result[0].slot_key).toBe('site_footer_before')
    })

    it('rejects the move (leaving placements untouched) when the destination is invalid', () => {
        const s = slot({ key: 'site_footer_before', cardinality: 'single' })
        const existing = [
            placement({ widget_instance_uuid: 'i1', slot_key: 'site_header_before' }),
            placement({ widget_instance_uuid: 'i2', slot_key: 'site_footer_before' }),
        ]
        const { placements: result, error } = utils.moveToSlot(
            existing,
            existing[0],
            { slotKey: 'site_footer_before', scopeType: 'global' },
            s
        )
        expect(error).toBe('cardinality_single')
        expect(result).toBe(existing)
    })
})
