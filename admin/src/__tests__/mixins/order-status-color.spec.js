import { describe, expect, it, vi } from 'vitest'

vi.mock('ant-design-vue', () => ({ notification: {} }))
vi.mock('@vueuse/core', () => ({ useStorage: vi.fn((_key, value) => ({ value })) }))
vi.mock('@/ajax', () => ({ default: {} }))
vi.mock('@/assets/spinner.png', () => ({ default: '' }))
import { orderStatusColor } from '@/mixins/utils'

/**
 * order, payment and fulfillment tags read the stored (prefixed) enumerate keys, so every seeded key gets
 * its colour instead of the default grey.
 */
describe('orderStatusColor', () => {
    it.each([
        ['order_pending', 'default'],
        ['order_draft', 'default'],
        ['order_closed', 'default'],
        ['order_processing', 'gold'],
        ['order_completed', 'green'],
        ['order_canceled', 'red'],
    ])('colours the order key %s as %s', (key, color) => {
        expect(orderStatusColor(key)).toBe(color)
    })

    it.each([
        ['payment_unpaid', 'pink'],
        ['payment_authorized', 'cyan'],
        ['payment_paid', 'green'],
        ['payment_partially_paid', 'purple'],
        ['payment_partially_refunded', 'purple'],
        ['payment_refunded', 'red'],
        ['payment_failed', 'red'],
        ['payment_voided', 'red'],
    ])('colours the payment key %s as %s', (key, color) => {
        expect(orderStatusColor(key)).toBe(color)
    })

    it.each([
        ['fulfillment_unfulfilled', 'gold'],
        ['fulfillment_partially_fulfilled', 'gold'],
        ['fulfillment_fulfilled', 'green'],
        ['fulfillment_shipped', 'green'],
        ['fulfillment_out_for_delivery', 'green'],
        ['fulfillment_delivered', 'green'],
        ['fulfillment_partially_returned', 'purple'],
        ['fulfillment_returned', 'red'],
    ])('colours the fulfillment key %s as %s', (key, color) => {
        expect(orderStatusColor(key)).toBe(color)
    })

    it('keeps the default for an empty or unknown key', () => {
        expect(orderStatusColor('')).toBe('default')
        expect(orderStatusColor(null)).toBe('default')
        expect(orderStatusColor('something_else')).toBe('default')
    })

    it('ignores case like the stored keys from older rows', () => {
        expect(orderStatusColor('PAYMENT_PAID')).toBe('green')
    })
})
