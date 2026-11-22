import { describe, expect, it } from 'vitest'
import {
    ADMIN_LOCALE_GROUPS,
    I18N_MESSAGES_SCHEMA_VERSION,
    humanizeI18nKey,
    mergeLocaleMessageTree,
    normalizeLocaleManifest,
    normalizeLocaleShardPayload,
} from '../../config/i18n-message-utils'

describe('i18n message utils', () => {
    it('exposes the canonical group schema version', () => {
        expect(I18N_MESSAGES_SCHEMA_VERSION).toMatch(/^canonical-group-v[1-9]\d*$/)
    })

    it('normalizes canonical manifest arrays and falls back when needed', () => {
        expect(normalizeLocaleManifest(['nav', 'text', 'validation', 'text'])).toEqual([
            'nav',
            'text',
            'validation',
        ])

        expect(normalizeLocaleManifest({}, ADMIN_LOCALE_GROUPS)).toEqual(ADMIN_LOCALE_GROUPS)
    })

    it('accepts canonical group payloads that expose canonical roots only', () => {
        const normalized = normalizeLocaleShardPayload('validation', {
            validation: {
                attributes: {
                    email: 'Email',
                },
            },
        })

        expect(normalized.validation.attributes.email).toBe('Email')
        expect(normalized).toEqual({
            validation: {
                attributes: {
                    email: 'Email',
                },
            },
        })
    })

    it('wraps canonical group-only payloads under their group name', () => {
        const normalized = normalizeLocaleShardPayload('nav', {
            dashboard: 'Dashboard',
        })

        expect(normalized).toEqual({
            nav: {
                dashboard: 'Dashboard',
            },
        })
    })

    it('keeps supporting legacy bridge shard payloads during rollout', () => {
        const normalized = normalizeLocaleShardPayload('validation', {
            a: { email: 'Email' },
            __groups: {
                validation: {
                    attributes: {
                        email: 'Email',
                    },
                },
            },
        })

        expect(normalized.validation.attributes.email).toBe('Email')
    })

    it('wraps legacy alias-only shard payloads under the shard root', () => {
        const normalized = normalizeLocaleShardPayload('a', {
            failed: 'These credentials do not match our records.',
        })

        expect(normalized).toEqual({
            a: {
                failed: 'These credentials do not match our records.',
            },
        })
    })

    it('deep merges canonical roots and legacy aliases into an existing locale tree', () => {
        const tree = {
            validation: {
                attributes: {
                    email: 'Email',
                },
            },
            a: {
                failed: 'These credentials do not match our records.',
            },
        }

        mergeLocaleMessageTree(tree, {
            validation: {
                between: {
                    numeric: 'Between',
                },
            },
            a: {
                throttled: 'Too many attempts.',
            },
        })

        expect(tree.validation.attributes.email).toBe('Email')
        expect(tree.validation.between.numeric).toBe('Between')
        expect(tree.a.failed).toBe('These credentials do not match our records.')
        expect(tree.a.throttled).toBe('Too many attempts.')
    })
})

describe('humanizeI18nKey', () => {
    it('title-cases the key tail across separators and camelCase', () => {
        expect(humanizeI18nKey('VehicleModel')).toBe('Vehicle Model')
        expect(humanizeI18nKey('text.inventory.transaction_type.manual_adjust')).toBe(
            'Manual Adjust'
        )
        expect(humanizeI18nKey('text.rp.ctype-vehiclemodel')).toBe('Ctype Vehiclemodel')
        expect(humanizeI18nKey('text.tb.sync_status_1')).toBe('Sync Status 1')
    })

    it('returns the input unchanged when nothing is humanizable', () => {
        expect(humanizeI18nKey('')).toBe('')
    })
})
