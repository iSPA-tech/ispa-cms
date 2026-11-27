import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const routeState = vi.hoisted(() => ({ value: {} as Record<string, unknown> }))
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRoute: () => routeState.value }))
const ruleState = vi.hoisted(() => ({ allowed: (() => true) as (name: unknown) => boolean }))
vi.mock('@/mixins', () => ({ rule: (name: unknown) => ruleState.allowed(name), perm: (name: string) => name }))
vi.mock('@/ajax', () => ({ default: { get: vi.fn() } }))

import { coreAdminModule } from '../../../../cms/Core/admin/module'
import { coreWorkflowsRouteConfig, coreSettingRouteContributions } from '../../../../cms/Core/admin/route-config'
import { coreWorkflowsNavigationConfig } from '../../../../cms/Core/admin/navigation-config'
import WorkflowsShell from '../../../../cms/Core/admin/pages/setting/workflows.vue'
import { NAV_ORDER } from '@/config/navigation'
import SettingHub from '@/pages/setting/setting-hub.vue'
import SettingCategoryLayout from '@/pages/setting/setting-category-layout.vue'

const VIEW = 'workflow_project.viewAny'
const CREATE = 'workflow_project.create'
const CREDENTIALS = 'workflow_credential.manage'
const core = coreAdminModule as any

const stubs = {
    'router-link': { props: ['to'], template: '<a :data-to="to && to.name"><slot /></a>' },
    'router-view': { template: '<div data-testid="content" />' },
    'no-permission-error': { template: '<div data-testid="no-permission" />' },
    'i-icon': true,
}
const mountPage = (component: any) => mount(component, { global: { mocks: { $t: (key: string) => key }, stubs } })

describe('workflows are one click away', () => {
    beforeEach(() => {
        ruleState.allowed = () => true
        routeState.value = { name: 'setting-agentic', path: '/iPanel/setting/automation/agentic', meta: {} }
    })

    it('adds a top-level sidebar group right under "Chờ duyệt", with one entry per workflow page', () => {
        expect(coreWorkflowsNavigationConfig).toMatchObject({ name: 'workflows', group: 'workflows', text: 'nav.a.workflows', rule: VIEW, icon: 'workflows' })
        expect(coreWorkflowsNavigationConfig.children.map((child: any) => [child.name, child.text, child.rule])).toEqual([
            ['workflows', ['nav.a.workflows-site'], VIEW],
            ['workflows-library', ['text.workflow.library.title'], VIEW],
            ['workflows-import', ['text.workflow.package.title'], CREATE],
            ['workflow-credentials', ['text.workflow_credential.title'], CREDENTIALS],
        ])
        expect(core.additionalNavigationConfigs).toContain(coreWorkflowsNavigationConfig)
        expect(NAV_ORDER.indexOf('workflows')).toBe(NAV_ORDER.indexOf('approvals') + 1)
    })

    it('serves the pages at /workflows, each with the rule of its page', () => {
        expect(core.additionalRouteConfigs).toContain(coreWorkflowsRouteConfig)
        expect(coreWorkflowsRouteConfig).toMatchObject({ path: 'workflows', group: 'workflows', name: 'workflows-group', redirect: { name: 'workflows' } })
        expect(coreWorkflowsRouteConfig.children.map((child: any) => [child.name, child.path, child.meta.rule])).toEqual([
            ['workflows', 'manage', VIEW],
            ['workflows-library', 'library', VIEW],
            ['workflows-import', 'import', CREATE],
            ['workflows-setup', 'setup/:project', VIEW],
            ['workflow-credentials', 'credentials', CREDENTIALS],
        ])
        // The setup page has no menu entry: the list stays highlighted while it is open.
        const setup = coreWorkflowsRouteConfig.children.find((child: any) => child.name === 'workflows-setup') as any
        expect(setup.meta.activeNav).toBe('workflows')
        expect(coreWorkflowsNavigationConfig.children.some((child: any) => child.name === 'workflows-setup')).toBe(false)
    })

    it('keeps the old addresses and route names working through redirects, with params and query', () => {
        const routes = coreSettingRouteContributions.find((entry: any) => entry.parent === 'setting-automation')!.routes as any[]
        const old = Object.fromEntries(routes.map((route) => [route.name, route]))
        const query = { open: 'church-story' }
        const cases: Array<[string, string, string]> = [
            ['setting-workflows', 'workflows', 'workflows'],
            ['setting-workflows-manage', 'workflows/manage', 'workflows'],
            ['setting-workflows-library', 'workflows/library', 'workflows-library'],
            ['setting-workflows-import', 'workflows/import', 'workflows-import'],
            ['setting-workflow-credentials', 'workflow-credentials', 'workflow-credentials'],
        ]
        for (const [name, path, target] of cases) {
            expect(old[name].path, name).toBe(path)
            expect(old[name].load, name).toBeUndefined()
            expect(old[name].redirect({ params: {}, query }), name).toEqual({ name: target, query })
        }
        expect(old['setting-workflows-setup'].path).toBe('workflows/setup/:project')
        expect(old['setting-workflows-setup'].redirect({ params: { project: 'church-story' }, query: { step: '2' } })).toEqual({
            name: 'workflows-setup',
            params: { project: 'church-story' },
            query: { step: '2' },
        })
    })

    it('opens each page of the group on the rule of that page', () => {
        const shown = (name: string, denied: string) => {
            routeState.value = { name, meta: {} }
            ruleState.allowed = (rule) => rule !== denied
            return mountPage(WorkflowsShell).find('[data-testid="content"]').exists()
        }
        expect(shown('workflows', CREDENTIALS)).toBe(true)
        expect(shown('workflows', VIEW)).toBe(false)
        expect(shown('workflows-library', VIEW)).toBe(false)
        expect(shown('workflow-credentials', VIEW)).toBe(true)
        expect(shown('workflow-credentials', CREDENTIALS)).toBe(false)
    })

    it('leaves Agentic CMS and AI Runtime on the settings card, with a line pointing to the sidebar group', () => {
        const card = mountPage(SettingHub).find('[data-testid="setting-hub-automation"]')
        expect(card.findAll('a').map((link) => link.attributes('data-to'))).toEqual(['setting-agentic', 'workflows'])
        expect(card.text()).toContain('2 setting.hub.items')
        expect(card.text()).toContain('setting.hub.workflows_moved')
        expect(card.find('a[data-to="workflows"]').text()).toContain('setting.hub.workflows_open')
        // A link never sits inside another link.
        expect(card.element.tagName).not.toBe('A')
        expect(card.find('a a').exists()).toBe(false)
    })

    it('shows the pointer line only to people allowed to open workflows', () => {
        ruleState.allowed = (rule) => rule !== VIEW
        const card = mountPage(SettingHub).find('[data-testid="setting-hub-automation"]')
        expect(card.attributes('data-to')).toBe('setting-agentic')
        expect(card.text()).not.toContain('setting.hub.workflows_moved')
        expect(card.find('a[data-to="workflows"]').exists()).toBe(false)

        // The legacy `workflow.viewAny` rule no longer shows anything of the workflow pages.
        ruleState.allowed = (rule) => rule === 'workflow.viewAny'
        expect(mountPage(SettingHub).find('[data-testid="setting-hub-automation"]').exists()).toBe(false)
    })

    it('drops workflows and credentials from the automation settings menu', () => {
        const links = mountPage(SettingCategoryLayout)
            .findAll('a')
            .map((link) => link.attributes('data-to'))
        expect(links).toEqual(['setting-hub', 'setting-agentic', 'setting-ai-runtime'])
    })
})
