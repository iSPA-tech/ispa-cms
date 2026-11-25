/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import {
    coreApprovalsRouteConfig,
    coreCategoryRouteConfig,
    coreHubConnectRouteConfig,
    coreLanguageRouteConfig,
    coreSettingRouteContributions,
    coreUserRouteConfig,
    coreWidgetLibraryRouteConfig,
} from './route-config'
import ajax from '@/ajax'
import {
    coreApprovalsNavigationConfig,
    coreLanguageNavigationConfig,
    coreUserNavigationConfig,
    coreWidgetLibraryNavigationConfig,
} from './navigation-config'

/**
 * Core admin registry (key `core`). Core is always on and cannot be disabled, so neither its route groups nor its menu
 * groups carry a catalog `module` name (no `meta.module` tag, never blocked by the module activation gate).
 *
 * - `routeConfig` / `navigationConfig`: the Users group (users, roles, permissions).
 * - `additionalRouteConfigs` / `additionalNavigationConfigs`: further Core groups: Languages (languages, provinces, translations); Categories (gallery and item categories); the widget library (list and design editor); the one-tap hub connect return address; the approvals waiting for a person ("Chờ duyệt", with its sidebar counter in `navBadges`); Settings as it moves in.
 * - `dashboardTiles`: headline KPI tiles of the Users dashboard (registry v2, data only; same rule as the Users menu).
 * - `routeContributions`: settings leaf pages (email, menu, upload, enumerates, meta classes/attributes/values, modules,
 *   cache, API logs) merged into the category groups the shell keeps (`setting-general`, `-content`, `-data`, `-system`); the workflows builder, agentic console and AI runtime pages join `setting-automation`.
 * - `navRuleOnRouteRoot: false`: the shell must not copy the menu root rule (`user.viewAny`) onto the route group root
 *   `user`. Before the move that root had no rule, so its rule-less children (`user-create`, `user-detail`,
 *   `user-update`) kept an empty rule; copying it would change who can open those screens.
 */
/** Approval detail route for a workflow notification payload, or null without a numeric `approval_id`. */
const workflowApprovalTarget = (payload) => {
    const id = payload?.approval_id
    return /^\d+$/.test(String(id ?? '')) ? { name: 'approval-detail', params: { id: String(id) } } : null
}

/** Number of approvals waiting for a decision (the list API's total; one row is asked for, quietly). */
export const pendingApprovalCount = async () => {
    const res = await ajax.get('agentic/approvals', { params: { status: 'pending', per_page: 1 }, silent: true })
    return Number(res?.data?.data?.meta?.total ?? res?.data?.meta?.total) || 0
}

export const coreAdminModule = {
    key: 'core',
    routeConfig: coreUserRouteConfig,
    navigationConfig: coreUserNavigationConfig,
    navRuleOnRouteRoot: false,
    additionalRouteConfigs: [
        coreLanguageRouteConfig,
        coreCategoryRouteConfig,
        coreWidgetLibraryRouteConfig,
        coreHubConnectRouteConfig,
        coreApprovalsRouteConfig,
    ],
    additionalNavigationConfigs: [coreLanguageNavigationConfig, coreWidgetLibraryNavigationConfig, coreApprovalsNavigationConfig],
    // Sidebar counter of the approvals waiting for a person, next to "Chờ duyệt".
    navBadges: [
        {
            name: 'approvals',
            rule: ['agentic.approve', 'agentic.viewAny'],
            label: 'text.agentic.inbox.badge_label',
            intervalSeconds: 60,
            load: pendingApprovalCount,
        },
    ],
    routeContributions: coreSettingRouteContributions,
    // "Sessions and tokens" tab of the user page: the account's API tokens and "revoke all of this user". The tab body checks
    // Webmaster itself; `role.viewAny` is a Webmaster-managed rule, so only the Webmaster (and role managers) see the tab.
    profileTabs: [
        {
            target: 'user',
            name: 'core.security-tokens',
            text: 'text.security_tokens.user_tab.title',
            rule: 'role.viewAny',
            load: () => import('./components/security/user-tokens-tab.vue'),
        },
    ],
    // Workflow human-node notifications (payload ids only: `approval_id`, `workflow_instance_id`, `title`): an approval
    // request or a question opens the approval detail; a notice opens its approval when it names one, else the workflows page.
    notifications: [
        { type: 'workflow.approval.requested', text: 'text.workflow.notifications.approval_requested', open: (payload) => workflowApprovalTarget(payload) },
        { type: 'workflow.approval.overdue', text: 'text.workflow.notifications.approval_overdue', open: (payload) => workflowApprovalTarget(payload) },
        { type: 'workflow.question.asked', text: 'text.workflow.notifications.question_asked', open: (payload) => workflowApprovalTarget(payload) },
        // Workflow guardrail alerts to publishers and Webmasters (payload: names, counts only): open the workflows page.
        { type: 'workflow.change.version_published', text: 'text.workflow.notifications.change_version_published', open: () => ({ name: 'setting-workflows-manage' }) },
        { type: 'workflow.change.version_pending', text: 'text.workflow.notifications.change_version_pending', open: () => ({ name: 'setting-workflows-manage' }) },
        { type: 'workflow.change.version_rolled_back', text: 'text.workflow.notifications.change_version_rolled_back', open: () => ({ name: 'setting-workflows-manage' }) },
        { type: 'workflow.change.activation_changed', text: 'text.workflow.notifications.change_activation_changed', open: () => ({ name: 'setting-workflows-manage' }) },
        { type: 'workflow.change.auto_paused', text: 'text.workflow.notifications.change_auto_paused', open: () => ({ name: 'setting-workflows-manage' }) },
        { type: 'workflow.change.integrity_violated', text: 'text.workflow.notifications.change_integrity_violated', open: () => ({ name: 'setting-workflows-manage' }) },
        { type: 'workflow.change.credential_changed', text: 'text.workflow.notifications.change_credential_changed', open: () => ({ name: 'setting-workflows-manage' }) },
        { type: 'workflow.change.global_pause_changed', text: 'text.workflow.notifications.change_global_pause_changed', open: () => ({ name: 'setting-workflows-manage' }) },
        {
            type: 'workflow.notice',
            text: 'text.workflow.notifications.notice',
            open: (payload) => workflowApprovalTarget(payload) || { name: 'setting-workflows-manage' },
        },
    ],
    // Users dashboard KPI tiles: totals of the shared dashboard summary snapshot (people block), same rule as the Users menu.
    // Settings and Languages declare no tiles: the summary snapshot has no source for them.
    dashboardTiles: [
        { name: 'core.users-total', rule: 'user.viewAny', source: 'summary' },
        { name: 'core.users-new', rule: 'user.viewAny', source: 'summary' },
    ],
}
