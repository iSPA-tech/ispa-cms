import { mount } from '@vue/test-utils'
import { mdiChurch, mdiLinkVariant, mdiRadar } from '@mdi/js'
import { describe, expect, it } from 'vitest'

import IIcon from '../../components/i-icon.vue'

const mountIcon = (icon: string) =>
    mount(IIcon, {
        props: { icon },
    })

describe('module menu icons', () => {
    it.each([
        ['Gợi ý Affiliate', 'link-variant', mdiLinkVariant],
        ['Handbook', 'church', mdiChurch],
        ['AutoDiscover', 'radar', mdiRadar],
    ])('maps %s sidebar icon key', (_label, icon, expectedPath) => {
        const wrapper = mountIcon(icon)

        const svg = wrapper.find('svg')
        expect(svg.exists()).toBe(true)
        expect(svg.attributes('viewBox')).toBe('0 0 24 24')
        expect(wrapper.find('path').attributes('d')).toBe(expectedPath)
    })
})
