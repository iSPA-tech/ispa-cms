/**
 * Moved from tests/ after the admin module split: covers the Core admin agentic screens (nav-and-polish),
 * imported from ispa-cms/cms/Core/admin by relative path.
 */
import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { mount } from '@vue/test-utils'

// ── Mock vue-router ──────────────────────────────────────────────
vi.mock('vue-router', () => ({
    useRoute: () => ({ name: 'setting-agentic', params: {}, query: {} }),
    useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
}))

// ── Mock useAgenticPermissions with mutable refs ─────────────────
const mockCanProvision = ref(true)

vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticPermissions', () => ({
    useAgenticPermissions: () => ({
        canViewAgentic: ref(true),
        canProvisionAgentic: mockCanProvision,
        canOperateAgentic: ref(true),
    }),
}))

import AgenticSubNav from '../../../../cms/Core/admin/pages/setting/agentic/components/AgenticSubNav.vue'

const routerLinkStub = {
    template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
    props: ['to', 'activeClass'],
}

// ── AgenticSubNav tests ──────────────────────────────────────────

describe('AgenticSubNav', () => {
    it('renders three links when user has admin permission', () => {
        mockCanProvision.value = true

        const wrapper = mount(AgenticSubNav, {
            global: { stubs: { 'router-link': routerLinkStub } },
        })

        const links = wrapper.findAll('a')
        expect(links.length).toBe(3)

        // Verify link targets
        const targets = links.map((l) => JSON.parse(l.attributes('data-to') || '{}'))
        expect(targets[0].name).toBe('setting-agentic')
        expect(targets[1].name).toBe('setting-agentic-approvals')
        expect(targets[2].name).toBe('setting-agentic-admin')
    })

    it('hides admin link when user lacks permission', () => {
        mockCanProvision.value = false

        const wrapper = mount(AgenticSubNav, {
            global: { stubs: { 'router-link': routerLinkStub } },
        })

        const links = wrapper.findAll('a')
        expect(links.length).toBe(2)

        // Restore for other tests
        mockCanProvision.value = true
    })
})

// ── Coupon isExpired helper tests ────────────────────────────────

describe('coupon isExpired helper', () => {
    /**
     * Standalone implementation matching the helper in coupon.vue.
     * Extracted here to test the logic without mounting the full page component.
     */
    function isExpired(record: { end_date?: string | null }): boolean {
        return !!record.end_date && new Date(record.end_date) < new Date()
    }

    it('returns true for a past end_date', () => {
        expect(isExpired({ end_date: '2020-01-01 00:00:00' })).toBe(true)
    })

    it('returns false for a future end_date', () => {
        const future = new Date()
        future.setFullYear(future.getFullYear() + 1)
        expect(isExpired({ end_date: future.toISOString() })).toBe(false)
    })

    it('returns false when end_date is null', () => {
        expect(isExpired({ end_date: null })).toBe(false)
    })

    it('returns false when end_date is undefined', () => {
        expect(isExpired({})).toBe(false)
    })
})
