/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import {
    AgenticAdminConfig,
    AgenticApprovalInbox,
    AgenticApprovalPage,
    AgenticApprovalSla,
    ApprovalsShell,
    AgenticDashboard,
    Category,
    CategoryManage,
    Language,
    LanguageCreate,
    LanguageManage,
    LanguageProvince,
    LanguageProvinceUpdate,
    LanguageTranslate,
    LanguageTranslateAdmin,
    LanguageTranslateEmail,
    LanguageTranslateWeb,
    LanguageUpdate,
    SettingAds,
    SettingAiRuntime,
    SettingAdvanced,
    SettingApiLogs,
    SettingBackups,
    SettingBasic,
    SettingConfigPackages,
    SettingStarterPacks,
    SettingSecurityTokens,
    SettingCache,
    SettingContact,
    SettingDashboard,
    SettingEmail,
    SettingEmailTemplates,
    SettingEnumerate,
    SettingEnumerateType,
    SettingHomepage,
    SettingMenu,
    SettingMetaAttribute,
    SettingMetaClass,
    SettingMetaValue,
    SettingCustomFields,
    SettingModules,
    SettingWorkflow,
    SettingWorkflowManage,
    SettingWorkflowLibrary,
    SettingWorkflowPackageImport,
    SettingWorkflowSetup,
    SettingWorkflowCredentials,
    SettingSocial,
    SettingUpload,
    User,
    UserCreate,
    UserDashboard,
    UserDetail,
    UserManage,
    UserPermission,
    UserRoles,
    UserUpdate,
    HubConnectCallback,
    WidgetLibrary,
    WidgetLibrarySources,
    WidgetLibraryShell,
} from './page-loaders'

/**
 * Users route group (`/user/*`). Names and paths are frozen by the route parity fixture: route rules come from the
 * Users menu (`navigation-config.js`) through the shell's `applyPermissionRules`, so a renamed route changes who can
 * open it. The detail, create and update pages carry their own `meta.rule` (`user.view`, `user.create`,
 * `user.update`) because the Users menu only names `user-manage`, and `navRuleOnRouteRoot: false` keeps the group
 * root rule-less.
 */
export const coreUserRouteConfig = {
    text: 'nav.a.users',
    path: 'user',
    redirect: { name: 'user-dashboard' },
    load: User,
    group: 'user',
    name: 'user',
    children: [
        {
            text: 'nav.dashboard',
            path: 'dashboard',
            name: 'user-dashboard',
            load: UserDashboard,
        },
        {
            text: 'nav.a.manage',
            path: 'manage',
            name: 'user-manage',
            load: UserManage,
        },
        {
            text: 'nav.b.view-detail',
            path: 'detail/:uuid',
            name: 'user-detail',
            load: UserDetail,
            meta: { rule: 'user.view' },
        },
        {
            text: 'nav.b.create',
            path: 'create',
            name: 'user-create',
            load: UserCreate,
            meta: { rule: 'user.create' },
        },
        {
            text: 'nav.b.update',
            path: 'update/:uuid',
            name: 'user-update',
            load: UserUpdate,
            meta: { rule: 'user.update' },
        },
        {
            text: 'nav.a.roles',
            path: 'roles',
            name: 'user-roles',
            load: UserRoles,
        },
        {
            text: 'nav.a.permission',
            path: 'permission',
            name: 'user-permission',
            load: UserPermission,
        },
    ],
}

/**
 * Languages route group (`/language/*`: languages, provinces, translations). Names and paths are frozen by the route
 * parity fixture; route rules come from the Languages menu (`navigation-config.js`).
 */
export const coreLanguageRouteConfig = {
    text: 'nav.a.languages',
    path: 'language',
    redirect: { name: 'language-manage' },
    load: Language,
    group: 'language',
    name: 'language',
    children: [
        {
            text: 'nav.a.manage',
            path: 'manage',
            name: 'language-manage',
            load: LanguageManage,
        },
        {
            text: 'nav.b.create',
            path: 'create',
            name: 'language-create',
            load: LanguageCreate,
        },
        {
            text: 'nav.b.update',
            path: 'update/:id',
            name: 'language-update',
            load: LanguageUpdate,
        },
        {
            text: 'nav.a.provinces',
            path: 'province',
            name: 'province-manage',
            load: LanguageProvince,
        },
        {
            text: 'nav.b.update',
            path: 'province/update/:id',
            name: 'province-update',
            load: LanguageProvinceUpdate,
        },
        {
            text: 'nav.a.translates',
            path: 'translate',
            name: 'language-translate',
            load: LanguageTranslate,
            subGroup: 'language-translate',
            children: [
                {
                    text: 'nav.a.trans-web',
                    path: 'website',
                    name: 'language-translate-web',
                    load: LanguageTranslateWeb,
                },
                {
                    text: 'nav.a.trans-app',
                    path: 'app',
                    name: 'language-translate-app',
                    load: LanguageTranslateAdmin,
                },
                {
                    text: 'nav.a.trans-adm',
                    path: 'admin',
                    name: 'language-translate-admin',
                    load: LanguageTranslateAdmin,
                },
            ],
        },
        {
            text: 'nav.a.trans-email',
            path: 'email',
            name: 'language-translate-email',
            load: LanguageTranslateEmail,
        },
    ],
}

/**
 * Categories route group (`/category/*`: item categories; gallery categories live under Media). Always on, so it carries no catalog module.
 * Names and paths are frozen by the route parity fixture; route rules come from the shell's category menu.
 */
export const coreCategoryRouteConfig = {
    text: 'nav.a.categories',
    path: 'category',
    load: Category,
    redirect: { name: 'post-category' },
    group: 'category',
    name: 'category',
    children: [
        // The former `gallery-category` child (/category/gallery) is not declared here: the Media group registers the same
        // route name (/media/gallery/category) later in the router, and a duplicate name replaces the earlier route, so
        // the old entry was already shadowed. Appended after every other group, this one would now win the name.
        {
            text: 'nav.a.cate-item',
            path: 'item',
            name: 'category-item',
            load: CategoryManage,
        },
    ],
}

/**
 * Widget library route group (`/widget-library/*`): the list of every widget of the site and the design editor. Core
 * owns it, so it carries no catalog module and stays open with the Widget module off. `widget-library` takes its rule
 * from the menu entry (`navigation-config.js`); the design editor has no menu entry, so it names its rule itself.
 */
/** Who may open the approvals: the reviewers and everyone who may see the agentic screens. */
export const APPROVALS_RULE = ['agentic.approve', 'agentic.viewAny']

/**
 * Approvals waiting for a person ("Chờ duyệt"): a top-level group, so the list is one click from the sidebar. The list
 * and the detail are the agentic approval screens; `setting/automation/agentic/approvals[/:id]` redirect here.
 */
export const coreApprovalsRouteConfig = {
    text: 'nav.a.approvals',
    path: 'approvals',
    load: ApprovalsShell,
    group: 'approvals',
    name: 'approvals-group',
    // The rule sits on each page: the shell does not pass a group rule down to the pages of an extra Core group.
    children: [
        { text: 'nav.a.approvals', path: '', name: 'approvals', load: AgenticApprovalInbox, meta: { rule: APPROVALS_RULE } },
        { text: 'nav.a.approvals', path: ':id(\\d+)', name: 'approval-detail', load: AgenticApprovalPage, meta: { rule: APPROVALS_RULE } },
        // How fast the assigned reviewers answer; opened from the list header.
        { text: 'text.agentic.sla.summary_title', path: 'sla', name: 'approvals-sla', load: AgenticApprovalSla, meta: { rule: APPROVALS_RULE } },
    ],
}

/**
 * Workflows ("Quy trình tự động hoá"): a top-level group, so the list, the template library, the import and the
 * credentials are one click from the sidebar. `setting/automation/workflows[/...]` and
 * `setting/automation/workflow-credentials` redirect here.
 */
export const coreWorkflowsRouteConfig = {
    text: 'nav.a.workflows',
    path: 'workflows',
    redirect: { name: 'workflows' },
    load: SettingWorkflow,
    group: 'workflows',
    name: 'workflows-group',
    // The rule sits on each page: the shell does not pass a group rule down to the pages of an extra Core group.
    children: [
        { text: 'nav.a.workflows-site', path: 'manage', name: 'workflows', load: SettingWorkflowManage, meta: { rule: 'workflow_project.viewAny' } },
        // Sample workflows from the iSPA hub: what each one does and may do, then install (always switched off).
        {
            text: 'text.workflow.library.title',
            path: 'library',
            name: 'workflows-library',
            load: SettingWorkflowLibrary,
            meta: { rule: 'workflow_project.viewAny' },
        },
        // Review and install a workflow package from another site (always installed switched off).
        {
            text: 'text.workflow.package.title',
            path: 'import',
            name: 'workflows-import',
            load: SettingWorkflowPackageImport,
            meta: { rule: 'workflow_project.create' },
        },
        // The few choices a site makes after installing a sample workflow; `project` is its id or its slug. No menu
        // entry: the list stays highlighted.
        {
            text: 'text.workflow.setup.nav',
            path: 'setup/:project',
            name: 'workflows-setup',
            load: SettingWorkflowSetup,
            meta: { rule: 'workflow_project.viewAny', activeNav: 'workflows' },
        },
        {
            text: 'text.workflow_credential.title',
            path: 'credentials',
            name: 'workflow-credentials',
            load: SettingWorkflowCredentials,
            meta: { rule: 'workflow_credential.manage' },
        },
    ],
}

export const coreWidgetLibraryRouteConfig = {
    text: 'nav.a.widget-library',
    path: 'widget-library',
    redirect: { name: 'widget-library' },
    load: WidgetLibraryShell,
    group: 'widget-library',
    name: 'widget-library-group',
    children: [
        {
            text: 'nav.a.widget-library',
            path: 'manage',
            name: 'widget-library',
            load: WidgetLibrary,
        },
        {
            text: 'text.widget_library.sources_title',
            path: 'sources',
            name: 'widget-library-sources',
            load: WidgetLibrarySources,
            meta: { rule: 'widget.viewAny' },
        },
    ],
}

/**
 * One-tap hub connect return address (`/hub-connect/callback`, fixed by Core's `HubOneTapConnect::CALLBACK_PATH`): where
 * ispa.tech sends the browser back with the single-use code. No menu entry and no route rule: the site backend checks
 * `hub.connect` when the screen finishes the attempt, and a signed-out visitor signs in on this same address first.
 */
export const coreHubConnectRouteConfig = {
    text: 'text.site_connect.title',
    path: 'hub-connect',
    redirect: { name: 'setting-modules' },
    name: 'hub-connect-group',
    children: [
        {
            text: 'text.site_connect.title',
            path: 'callback',
            name: 'hub-connect-callback',
            load: HubConnectCallback,
        },
    ],
}

/**
 * Settings leaf pages Core contributes into the category groups owned by the shell (`setting-general`,
 * `setting-content`, `setting-data`, `setting-system`). Each entry is a `routeContributions` item: `parent` is the
 * category route name, `after` the sibling the pages land behind so the child order stays as before the move. Names,
 * paths and `meta` are frozen by the route parity fixture; `meta.settingCategory` is inherited from the category parent.
 */
export const coreSettingRouteContributions = [
    {
        parent: 'setting',
        after: 'setting-hub',
        routes: [{ text: 'nav.a.settings', path: 'dashboard', name: 'setting-dashboard', load: SettingDashboard }],
        navigation: [],
    },
    {
        parent: 'setting-general',
        routes: [
            { text: 'nav.a.settings', path: 'basic', name: 'setting-basic', load: SettingBasic },
            { text: 'nav.a.setting-contact', path: 'contact', name: 'setting-contact', load: SettingContact },
            { text: 'nav.a.setting-social', path: 'social', name: 'setting-social', load: SettingSocial },
            { text: 'nav.a.setting-email', path: 'email', name: 'setting-email', load: SettingEmail },
            // Managed e-mail templates (subject, content, mail class, preview); the menu entry is in the shell's category list.
            {
                text: 'nav.a.email_templates',
                path: 'email-templates',
                name: 'setting-email-templates',
                load: SettingEmailTemplates,
                meta: { rule: 'email_template.viewAny' },
            },
            { text: 'nav.a.setting-homepage', path: 'homepage', name: 'setting-homepage', load: SettingHomepage },
            { text: 'nav.a.setting-ads', path: 'ads', name: 'setting-ads', load: SettingAds },
        ],
        navigation: [],
    },
    {
        parent: 'setting-content',
        routes: [
            { text: 'nav.a.menus', path: 'menu', name: 'setting-menu', load: SettingMenu },
            { text: 'nav.a.setting-upload', path: 'upload', name: 'setting-upload', load: SettingUpload },
        ],
        navigation: [],
    },
    {
        parent: 'setting-data',
        routes: [
            { text: 'nav.a.enumerates', path: 'enumerate', name: 'setting-enumerate', load: SettingEnumerate },
            {
                text: 'nav.a.enumerates_types',
                path: 'enumerate-type',
                name: 'setting-enumerate-type',
                load: SettingEnumerateType,
            },
            { text: 'nav.a.meta_classes', path: 'meta-class', name: 'setting-meta-class', load: SettingMetaClass },
            {
                text: 'nav.a.meta_attributes',
                path: 'meta-attribute',
                name: 'setting-meta-attribute',
                load: SettingMetaAttribute,
            },
            { text: 'nav.a.meta_values', path: 'meta-value', name: 'setting-meta-value', load: SettingMetaValue },
            {
                text: 'nav.a.custom_fields',
                path: 'custom-fields',
                name: 'setting-custom-fields',
                load: SettingCustomFields,
                meta: { rule: 'custom_field.view' },
            },
        ],
        navigation: [],
    },
    {
        parent: 'setting-system',
        routes: [
            { text: 'nav.a.setting-advanced', path: 'advanced', name: 'setting-advanced', load: SettingAdvanced },
            { text: 'nav.a.setting-modules', path: 'modules', name: 'setting-modules', load: SettingModules },
            {
                text: 'nav.a.setting-backups',
                path: 'backups',
                name: 'setting-backups',
                load: SettingBackups,
                // Webmaster only (enforced by the router guard in base-admin and by the page itself).
                meta: { webmasterOnly: true },
            },
            {
                text: 'nav.a.setting-config-packages',
                path: 'config-packages',
                name: 'setting-config-packages',
                load: SettingConfigPackages,
                // Webmaster only (enforced by the router guard in base-admin and by the page itself).
                meta: { webmasterOnly: true },
            },
            {
                text: 'nav.a.starter_packs',
                path: 'starter-packs',
                name: 'setting-starter-packs',
                load: SettingStarterPacks,
                meta: { rule: 'starter_pack.view' },
            },
            {
                text: 'nav.a.setting-security-tokens',
                path: 'security-tokens',
                name: 'setting-security-tokens',
                load: SettingSecurityTokens,
                // Open to every operator: "My devices" lists the tokens of their own account; the other tabs check Webmaster.
            },
            { text: 'nav.a.setting-cache', path: 'cache', name: 'setting-cache', load: SettingCache },
            { text: 'nav.a.api_logs', path: 'api-logs', name: 'setting-api-logs', load: SettingApiLogs },
        ],
        navigation: [],
    },
    {
        parent: 'setting-automation',
        routes: [
            // The workflows moved to the sidebar ("Quy trình tự động hoá"); the old addresses and route names keep working.
            { path: 'workflows', name: 'setting-workflows', redirect: (to) => ({ name: 'workflows', query: to.query }) },
            { path: 'workflows/manage', name: 'setting-workflows-manage', redirect: (to) => ({ name: 'workflows', query: to.query }) },
            {
                path: 'workflows/library',
                name: 'setting-workflows-library',
                redirect: (to) => ({ name: 'workflows-library', query: to.query }),
            },
            {
                path: 'workflows/import',
                name: 'setting-workflows-import',
                redirect: (to) => ({ name: 'workflows-import', query: to.query }),
            },
            {
                path: 'workflows/setup/:project',
                name: 'setting-workflows-setup',
                redirect: (to) => ({ name: 'workflows-setup', params: { project: to.params.project }, query: to.query }),
            },
            {
                path: 'workflow-credentials',
                name: 'setting-workflow-credentials',
                redirect: (to) => ({ name: 'workflow-credentials', query: to.query }),
            },
            { text: 'text.agentic.cms', path: 'agentic', name: 'setting-agentic', load: AgenticDashboard },
            {
                text: 'text.ai_runtime.title',
                path: 'ai-runtime',
                name: 'setting-ai-runtime',
                load: SettingAiRuntime,
                meta: { settingWide: true },
            },
            // The approvals moved to the sidebar ("Chờ duyệt"); the old addresses and route names keep working.
            {
                path: 'agentic/approvals',
                name: 'setting-agentic-approvals',
                redirect: (to) =>
                    /^\d+$/.test(String(to.query?.approval ?? ''))
                        ? { name: 'approval-detail', params: { id: String(to.query.approval) } }
                        : { name: 'approvals', query: to.query },
            },
            {
                path: 'agentic/approvals/:id',
                name: 'setting-agentic-approval-detail',
                redirect: (to) => ({ name: 'approval-detail', params: { id: to.params.id } }),
            },
            { path: 'agentic/admin', name: 'setting-agentic-admin', load: AgenticAdminConfig },
        ],
        navigation: [],
    },
]
