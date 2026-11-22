import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/hooks', () => ({
    useEnumerates: () => ({
        data: ref([
            { type: 'order', enum_key: 'order_paid', name: 'Đã thanh toán', name_alias: 'Paid' },
            { type: 'order', enum_key: 'order_cancelled', name: 'Đã hủy', name_alias: 'Cancelled' },
            { type: 'other', enum_key: 'x', name: 'X' },
        ]),
        isLoading: ref(false),
    }),
}))

vi.mock('@/runtime', () => ({ useAppRuntime: () => ({ locale: ref('en') }) }))

import SelectInputStatus from '@/components/select-input-status.vue'

const ASelectStub = {
    props: ['options', 'value'],
    template: '<div data-test="sel">{{ (options || []).map((o) => o.value + ":" + o.label).join("|") }}</div>',
}

const mountSelect = (props: Record<string, unknown> = {}) =>
    mount(SelectInputStatus, {
        props: { type: 'order', showEnumKey: false, ...props },
        global: {
            mocks: { $t: (k: string) => k },
            stubs: { 'a-form-item': { template: '<div><slot /></div>' }, 'a-select': ASelectStub },
        },
    })

describe('select-input-status', () => {
    it('lists only the enumerates of its type', () => {
        const text = mountSelect().get('[data-test="sel"]').text()
        expect(text).toBe('order_paid:Paid|order_cancelled:Cancelled')
    })

    it('prepends a translated "all" option when allValue is set, so the select never shows the raw word', () => {
        const text = mountSelect({ allValue: 'all', allLabel: 'All statuses' })
            .get('[data-test="sel"]')
            .text()
        expect(text.startsWith('all:All statuses|order_paid:Paid')).toBe(true)
    })
})
