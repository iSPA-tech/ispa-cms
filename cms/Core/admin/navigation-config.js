/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Users menu group. Core is always on, so the group carries no `module` tag: its routes never get `meta.module` and
 * the module activation gate never blocks them. Item rules are the permission gate of the matching routes.
 */
export const coreUserNavigationConfig = {
    name: 'user-dashboard',
    group: 'user',
    text: 'nav.a.users',
    rule: 'user.viewAny',
    icon: 'account-group',
    children: [
        {
            name: 'user-manage',
            text: ['nav.a.manage'],
            rule: 'user.viewAny',
            icon: 'format-list-bulleted',
            position: 0,
        },
        {
            name: 'user-roles',
            text: ['nav.a.roles'],
            rule: 'role.viewAny',
            icon: 'account-supervisor',
            position: 2,
        },
        {
            name: 'user-permission',
            text: ['nav.a.permission'],
            rule: 'permission.viewAny',
            icon: 'account-multiple-check',
            position: 3,
        },
    ],
}

/**
 * Languages menu group (languages, translations, provinces). Core is always on, so no `module` tag. The sidebar slot
 * comes from `NAV_ORDER` (`language-manage`).
 */
export const coreLanguageNavigationConfig = {
    name: 'language-manage',
    group: 'language',
    text: 'nav.a.languages',
    rule: 'contact.viewAny',
    icon: 'translate-variant',
    children: [
        {
            name: 'language-create',
            text: ['nav.b.add', 'nav.a.languages'],
            rule: 'language.create',
            icon: 'translate-variant',
        },
        {
            name: 'language-manage',
            text: ['nav.a.manage', 'nav.a.languages'],
            rule: 'language.viewAny',
            icon: 'translate-variant',
        },
        {
            name: 'language-translate-admin',
            text: ['nav.a.translates'],
            rule: 'translate.viewAny',
            group: 'language-translate',
            icon: 'translate-variant',
            children: [
                {
                    name: 'language-translate-web',
                    text: ['nav.a.translates', 'nav.a.trans-web'],
                    rule: '',
                    icon: 'translate-variant',
                },
                {
                    name: 'language-translate-app',
                    text: ['nav.a.translates', 'nav.a.trans-app'],
                    rule: '',
                    icon: 'translate-variant',
                },
            ],
        },
        {
            name: 'language-translate-email',
            text: ['nav.a.trans-email'],
            rule: 'email.viewAny',
            icon: 'translate-variant',
        },
        {
            name: 'province-manage',
            text: ['nav.a.provinces-manage'],
            rule: 'language.viewAny',
            icon: 'translate-variant',
        },
    ],
}

/**
 * Widget library menu entry (one item, no children). Core is always on, so no `module` tag: the entry and its screen
 * stay available with the Widget module off. The sidebar slot comes from `NAV_ORDER` (`widget-library`).
 */
export const coreWidgetLibraryNavigationConfig = {
    name: 'widget-library',
    group: 'widget-library',
    text: 'nav.a.widget-library',
    rule: 'widget.viewAny',
    icon: 'puzzle-outline',
}

/**
 * Approvals waiting for a person ("Chờ duyệt"): one sidebar entry right under the dashboard, with a counter of the
 * waiting items (see `navBadges` in module.js). Core is always on, so no `module` tag.
 */
export const coreApprovalsNavigationConfig = {
    name: 'approvals',
    group: 'approvals',
    text: 'nav.a.approvals',
    rule: ['agentic.approve', 'agentic.viewAny'],
    icon: 'clipboard-check-outline',
}

/**
 * Workflows ("Quy trình tự động hoá"): a sidebar group right under "Chờ duyệt". Its entries (the site's workflows, the
 * template library, the import from another site, the credentials) show while the group is open; each carries the rule
 * of its page. Core is always on, so no `module` tag.
 */
export const coreWorkflowsNavigationConfig = {
    name: 'workflows',
    group: 'workflows',
    text: 'nav.a.workflows',
    rule: 'workflow_project.viewAny',
    icon: 'workflows',
    children: [
        { name: 'workflows', text: ['nav.a.workflows-site'], rule: 'workflow_project.viewAny', icon: 'format-list-bulleted' },
        { name: 'workflows-library', text: ['text.workflow.library.title'], rule: 'workflow_project.viewAny', icon: 'library' },
        { name: 'workflows-import', text: ['text.workflow.package.title'], rule: 'workflow_project.create', icon: 'file-import-outline' },
        { name: 'workflow-credentials', text: ['text.workflow_credential.title'], rule: 'workflow_credential.manage', icon: 'shield-key' },
    ],
}
