import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/ajax', () => ({ default: http }))

import { coreAdminModule, pendingApprovalCount } from '../../../../cms/Core/admin/module'
import { coreApprovalsRouteConfig, coreSettingRouteContributions } from '../../../../cms/Core/admin/route-config'
import { coreApprovalsNavigationConfig } from '../../../../cms/Core/admin/navigation-config'
import { NAV_ORDER } from '@/config/navigation'

const RULE = ['agentic.approve', 'agentic.viewAny']
const core = coreAdminModule as any

describe('approvals are one click away', () => {
    beforeEach(() => http.get.mockReset())

    it('adds a top-level "Chờ duyệt" sidebar entry for reviewers, right under the dashboard', () => {
        expect(coreApprovalsNavigationConfig).toMatchObject({ name: 'approvals', text: 'nav.a.approvals', rule: RULE })
        expect(coreApprovalsNavigationConfig).not.toHaveProperty('children')
        expect(core.additionalNavigationConfigs).toContain(coreApprovalsNavigationConfig)
        expect(NAV_ORDER.indexOf('approvals')).toBe(NAV_ORDER.indexOf('dashboard') + 1)
    })

    it('serves the list and the detail at /approvals with the same rule on each page', () => {
        expect(core.additionalRouteConfigs).toContain(coreApprovalsRouteConfig)
        expect(coreApprovalsRouteConfig.path).toBe('approvals')
        const pages = Object.fromEntries(coreApprovalsRouteConfig.children.map((child: any) => [child.name, child]))
        expect(pages.approvals.path).toBe('')
        expect(pages['approval-detail'].path).toBe(':id(\\d+)')
        for (const page of Object.values(pages) as any[]) expect(page.meta.rule).toEqual(RULE)
    })

    it('keeps the old addresses and route names working through redirects', () => {
        const routes = coreSettingRouteContributions.flatMap((entry: any) => entry.routes || [])
        const inbox = routes.find((route: any) => route.name === 'setting-agentic-approvals')
        const detail = routes.find((route: any) => route.name === 'setting-agentic-approval-detail')
        expect(inbox.path).toBe('agentic/approvals')
        expect(detail.path).toBe('agentic/approvals/:id')
        expect(inbox.redirect({ query: { status: 'pending' } })).toEqual({ name: 'approvals', query: { status: 'pending' } })
        // The old list opened an item through `?approval=<id>`.
        expect(inbox.redirect({ query: { approval: '9201' } })).toEqual({ name: 'approval-detail', params: { id: '9201' } })
        expect(detail.redirect({ params: { id: '12' } })).toEqual({ name: 'approval-detail', params: { id: '12' } })
    })

    it('shows the number of waiting approvals next to the entry, asked quietly', async () => {
        const badge = core.navBadges.find((entry: any) => entry.name === 'approvals')
        expect(badge).toMatchObject({ rule: RULE, label: 'text.agentic.inbox.badge_label' })
        expect(badge.load).toBe(pendingApprovalCount)

        http.get.mockResolvedValue({ data: { data: { data: [{}], meta: { total: 8 } } } })
        expect(await pendingApprovalCount()).toBe(8)
        expect(http.get).toHaveBeenCalledWith('agentic/approvals', { params: { status: 'pending', per_page: 1 }, silent: true })

        http.get.mockResolvedValue({ data: {} })
        expect(await pendingApprovalCount()).toBe(0)
    })
})
