import { describe, expect, it } from 'vitest'
import { resolveNotificationTarget } from '../../utils/notifications.js'

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
