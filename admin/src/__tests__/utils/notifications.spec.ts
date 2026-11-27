import { describe, expect, it } from 'vitest'
import { resolveNotificationDetail, resolveNotificationTarget } from '../../utils/notifications.js'

const types = [
    { type: 'helpdesk.ticket.assigned', open: (p: { uuid: string }) => ({ name: 'helpdesk-ticket-detail', params: { uuid: p.uuid } }) },
    { type: 'broken', open: () => { throw new Error('boom') } },
    { type: 'empty', open: () => null },
    { type: 'noopen' },
]

describe('resolveNotificationTarget', () => {
    it('resolves the location through the registered open()', () => {
        expect(resolveNotificationTarget({ type: 'helpdesk.ticket.assigned', payload: { uuid: 'u1' } }, types)).toEqual({
            location: { name: 'helpdesk-ticket-detail', params: { uuid: 'u1' } },
        })
    })
    it('gives a null location for unknown types, throwing open, empty result or missing open', () => {
        for (const type of ['nope', 'broken', 'empty', 'noopen']) {
            expect(resolveNotificationTarget({ type, payload: {} }, types)).toEqual({ location: null })
        }
    })
    it('never throws on odd input', () => {
        expect(resolveNotificationTarget(null, types)).toEqual({ location: null })
        expect(resolveNotificationTarget({ type: 'helpdesk.ticket.assigned' }, undefined)).toEqual({ location: null })
    })
})

describe('resolveNotificationDetail', () => {
    const t = (key: string, values: Record<string, string> = {}) => `${key}(${Object.values(values).join(',')})`
    const detailTypes = [
        { type: 'security.new_device', detail: (p: { device?: string }, tr: typeof t) => tr('text.device', { device: String(p.device) }) },
        { type: 'throws', detail: () => { throw new Error('boom') } },
    ]
    it('uses the registered detail(), handing it the payload and t', () => {
        expect(resolveNotificationDetail({ type: 'security.new_device', payload: { device: 'Edge on OS X' } }, detailTypes, t)).toBe('text.device(Edge on OS X)')
    })
    it('falls back to the payload message (a workflow notice), trimmed and cut to 300 characters', () => {
        expect(resolveNotificationDetail({ type: 'workflow.notice', payload: { message: '  Liên hệ mới từ Lan  ' } }, detailTypes, t)).toBe('Liên hệ mới từ Lan')
        expect(resolveNotificationDetail({ type: 'workflow.notice', payload: { message: 'x'.repeat(400) } }, detailTypes, t)).toHaveLength(300)
    })
    it('gives an empty string for no message, a throwing detail, a non-string or odd input', () => {
        expect(resolveNotificationDetail({ type: 'nope', payload: {} }, detailTypes, t)).toBe('')
        expect(resolveNotificationDetail({ type: 'throws', payload: {} }, detailTypes, t)).toBe('')
        expect(resolveNotificationDetail({ type: 'nope', payload: { message: 42 } }, detailTypes, t)).toBe('')
        expect(resolveNotificationDetail(null, undefined, t)).toBe('')
    })
})
