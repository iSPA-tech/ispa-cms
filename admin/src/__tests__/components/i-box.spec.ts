import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import IBox from '@/components/common/i-box.vue'

describe('i-box', () => {
    it('renders the slot inside .i-box and merges extra classes', () => {
        const w = mount(IBox, { attrs: { class: 'space-y-3' }, slots: { default: '<p id="x">hi</p>' } })
        expect(w.classes()).toEqual(expect.arrayContaining(['i-box', 'space-y-3']))
        expect(w.get('.i-box #x').text()).toBe('hi')
    })
})
