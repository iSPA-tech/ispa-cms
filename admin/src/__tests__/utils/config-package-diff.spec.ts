import { describe, expect, it } from 'vitest'
import {
    capItems,
    countByStatus,
    formatPair,
    formatValue,
    groupItemsByStatus,
    isSecretLike,
    matchMessage,
    MASKED,
    missingSecretsList,
    secretLabel,
    summaryRows,
} from '@core-admin/config-package-diff.js'

const items = [
    { key: 'a', action: 'create' },
    { key: 'b', action: 'update' },
    { key: 'c', action: 'update' },
    { key: 'd', action: 'blocked' },
]

describe('config package diff helpers', () => {
    it('groups and counts items by action with every action present', () => {
        const groups = groupItemsByStatus(items)
        expect(groups.update.map((i: { key: string }) => i.key)).toEqual(['b', 'c'])
        expect(groups.skipped).toEqual([])
        expect(countByStatus(items)).toEqual({ create: 1, update: 2, unchanged: 0, blocked: 1, skipped: 0 })
    })

    it('builds summary rows from the plan overview', () => {
        const rows = summaryRows({ settings: { status: 'changes', counts: { create: 2 }, total: 5, more: 1 } })
        expect(rows).toEqual([
            { section: 'settings', status: 'changes', counts: { create: 2, update: 0, unchanged: 0, blocked: 0, skipped: 0 }, total: 5, more: 1 },
        ])
    })

    it('caps a list and reports how many are left', () => {
        expect(capItems(items, 3)).toEqual({ shown: items.slice(0, 3), more: 1 })
        expect(capItems(items, 10).more).toBe(0)
    })

    it('formats scalars, empty values, arrays, objects and long strings', () => {
        expect(formatValue('x')).toBe('x')
        expect(formatValue(5)).toBe('5')
        expect(formatValue(null)).toBe('—')
        expect(formatValue(undefined)).toBe('—')
        expect(formatValue([1, 2])).toBe('1, 2')
        expect(formatValue({ a: 1, b: 'x' })).toBe('a: 1; b: x')
        expect(formatValue({ custom: { badge: 'success' }, permissions: ['p.a', 'p.b'] })).toBe('custom: (badge: success); permissions: (p.a, p.b)')
        expect(formatValue({ custom: { badge: 'success' }, permissions: ['p.a'] })).not.toMatch(/[{}"\[\]]/)
        expect(formatValue([])).toBe('—')
        const long = formatValue('y'.repeat(500), 50)
        expect(long).toHaveLength(50)
        expect(long.endsWith('…')).toBe(true)
    })

    it('tells whether a pair changed', () => {
        expect(formatPair('a', 'b', 'site.name')).toEqual({ before: 'a', after: 'b', changed: true })
        expect(formatPair({ a: 1 }, { a: 1 }, 'site.x').changed).toBe(false)
    })

    it('masks values of secret-like keys', () => {
        for (const key of ['mail.password', 'x.api_key', 'app.secret', 'auth.token', 'stripe_key', 'private.cert']) {
            expect(isSecretLike(key)).toBe(true)
        }
        expect(isSecretLike('site.name')).toBe(false)
        expect(isSecretLike('keyboard.layout')).toBe(false)
        const pair = formatPair('old-secret', 'S3cr3t-planted', 'mail.password')
        expect(pair).toEqual({ before: MASKED, after: MASKED, changed: true })
        expect(JSON.stringify(pair)).not.toContain('S3cr3t')
    })

    it('masks secret-like keys nested inside objects', () => {
        expect(formatValue({ mail: { password: 'S3cr3t-planted', host: 'h' } })).toBe('mail: (password: ••••••; host: h)')
    })

    it('matches known server messages to keys and params, and returns null for unknown ones', () => {
        expect(matchMessage('settings: 1 item(s) are blocked and will not be applied.')).toEqual({
            key: 'warning_blocked',
            params: { section: 'settings', count: 1 },
            plural: true,
        })
        expect(matchMessage('3 item(s) are blocked and will not be applied.')?.key).toBe('warning_blocked_short')
        expect(matchMessage('Section menus is not supported by this site and was skipped.')?.params).toEqual({ section: 'menus' })
        expect(matchMessage('row does not exist on this site; enable the module or run seeders')?.key).toBe('reason_missing_row')
        expect(matchMessage('status draft does not exist on this site')?.params).toEqual({ status: 'draft' })
        expect(matchMessage('language en does not exist on this site')?.key).toBe('reason_language_path_missing')
        expect(matchMessage('language does not exist on this site')?.key).toBe('reason_language_missing')
        expect(matchMessage('ambiguous: this name matches 2 roles on this site')?.params).toEqual({ count: 2 })
        expect(matchMessage('permission x.y does not exist on this site; skipped')?.params).toEqual({ permission: 'x.y' })
        expect(matchMessage('something brand new')).toBeNull()
        expect(matchMessage(null)).toBeNull()
    })

    it('lists required secrets as keys only', () => {
        expect(missingSecretsList({ already_set_here: ['A'], missing_here: ['B'] })).toEqual({ alreadySet: ['A'], missing: ['B'], labels: {} })
        expect(missingSecretsList(null)).toEqual({ alreadySet: [], missing: [], labels: {} })
        expect(missingSecretsList({ missing_here: ['B'], labels: { B: 'Mail password', C: '' } }).labels).toEqual({ B: 'Mail password' })
        expect(secretLabel({ B: 'Mail password' }, 'B')).toBe('Mail password')
        expect(secretLabel({}, 'B')).toBe('B')
    })
})
