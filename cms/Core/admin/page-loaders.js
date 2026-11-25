/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Lazy page loaders of the Core admin screens (Users group: users, roles, permissions; Languages group: languages, provinces, translations;
// Settings leaf pages for content, data and system).
export const User = () => import('./pages/user/user.vue').then((m) => m.default)
export const UserDashboard = () => import('./pages/user/dashboard.vue').then((m) => m.default)
export const UserManage = () => import('./pages/user/manage.vue').then((m) => m.default)
export const UserCreate = () => import('./pages/user/create.vue').then((m) => m.default)
export const UserUpdate = () => import('./pages/user/update.vue').then((m) => m.default)
export const UserRoles = () => import('./pages/user/roles.vue').then((m) => m.default)
export const UserPermission = () => import('./pages/user/permission.vue').then((m) => m.default)
export const UserDetail = () => import('./pages/user/detail.vue').then((m) => m.default)
export const Language = () => import('./pages/language/language.vue').then((m) => m.default)
export const LanguageCreate = () => import('./pages/language/create.vue').then((m) => m.default)
export const LanguageUpdate = () => import('./pages/language/update.vue').then((m) => m.default)
export const LanguageManage = () => import('./pages/language/manage.vue').then((m) => m.default)
export const LanguageProvince = () => import('./pages/language/province.vue').then((m) => m.default)
export const LanguageProvinceUpdate = () =>
    import('./pages/language/province_update.vue').then((m) => m.default)
export const LanguageTranslate = () =>
    import('./pages/language/translate.vue').then((m) => m.default)
export const LanguageTranslateWeb = () =>
    import('./pages/language/translate-web.vue').then((m) => m.default)
export const LanguageTranslateApp = () =>
    import('./pages/language/translate-app.vue').then((m) => m.default)
export const LanguageTranslateAdmin = () =>
    import('./pages/language/translate-admin.vue').then((m) => m.default)
export const LanguageTranslateEmail = () =>
    import('./pages/language/translate-email.vue').then((m) => m.default)
export const SettingDashboard = () => import('./pages/setting/dashboard.vue').then((m) => m.default)
export const SettingBasic = () => import('./pages/setting/basic.vue').then((m) => m.default)
export const SettingContact = () => import('./pages/setting/contact.vue').then((m) => m.default)
export const SettingSocial = () => import('./pages/setting/social.vue').then((m) => m.default)
export const SettingHomepage = () => import('./pages/setting/homepage.vue').then((m) => m.default)
export const SettingAds = () => import('./pages/setting/ads.vue').then((m) => m.default)
export const SettingAdvanced = () => import('./pages/setting/advanced.vue').then((m) => m.default)
export const SettingBackups = () => import('./pages/setting/backups.vue').then((m) => m.default)
export const SettingConfigPackages = () => import('./pages/setting/config-packages.vue').then((m) => m.default)
export const SettingEnvironment = () => import('./pages/setting/environment.vue').then((m) => m.default)
export const SettingStarterPacks = () => import('./pages/setting/starter-packs.vue').then((m) => m.default)
export const SettingSecurityTokens = () => import('./pages/setting/security-tokens.vue').then((m) => m.default)
export const SettingEmail = () => import('./pages/setting/email.vue').then((m) => m.default)
// Managed e-mail templates: list by group and language, mail class, edit and preview.
export const SettingEmailTemplates = () => import('./pages/setting/email-templates.vue').then((m) => m.default)
export const SettingMenu = () => import('./pages/setting/menu.vue').then((m) => m.default)
export const SettingUpload = () => import('./pages/setting/upload.vue').then((m) => m.default)
export const SettingEnumerate = () => import('./pages/setting/enumerate.vue').then((m) => m.default)
export const SettingEnumerateType = () =>
    import('./pages/setting/enumerate-type.vue').then((m) => m.default)
export const SettingMetaClass = () => import('./pages/setting/meta-class.vue').then((m) => m.default)
export const SettingMetaAttribute = () =>
    import('./pages/setting/meta-attribute.vue').then((m) => m.default)
export const SettingCustomFields = () => import('./pages/setting/custom-fields.vue').then((m) => m.default)
export const SettingMetaValue = () => import('./pages/setting/meta-value.vue').then((m) => m.default)
export const SettingModules = () => import('./pages/setting/modules.vue').then((m) => m.default)
// Where ispa.tech sends the browser back after the one-tap hub connect consent (`/iPanel/hub-connect/callback`).
export const HubConnectCallback = () => import('./pages/setting/hub-connect-callback.vue').then((m) => m.default)
export const SettingCache = () => import('./pages/setting/cache.vue').then((m) => m.default)
export const SettingApiLogs = () => import('./pages/setting/api-logs.vue').then((m) => m.default)
// Storage (warehouse) page: no Core route of its own; the shell's Product and Place groups load it as
// `product-storage` / `place-storage` through `Storage` in `base-admin/src/config/router/client.js`.
export const Storage = () => import('./pages/warehouse/storage.vue').then((m) => m.default)
// Automation category pages (workflows builder, agentic console, AI runtime). `SettingAgentic` (`agentic.vue`) is the legacy monolithic
// page: no route uses it, the file is moved only.
export const SettingWorkflow = () => import('./pages/setting/workflows.vue').then((m) => m.default)
export const SettingWorkflowManage = () =>
    import('./pages/setting/workflows-manage.vue').then((m) => m.default)
// Sample workflows downloaded from the iSPA hub (list, what a template may do, install).
export const SettingWorkflowLibrary = () => import('./pages/setting/workflow-library.vue').then((m) => m.default)
export const SettingWorkflowPackageImport = () =>
    import('./pages/setting/workflow-package-import.vue').then((m) => m.default)
// What a site must decide after a sample workflow is installed (who reviews, who is told, when it runs).
export const SettingWorkflowSetup = () => import('./pages/setting/workflow-setup.vue').then((m) => m.default)
export const SettingWorkflowCredentials = () =>
    import('./pages/setting/workflow-credentials.vue').then((m) => m.default)
export const AgenticDashboard = () =>
    import('./pages/setting/agentic/AgenticDashboard.vue').then((m) => m.default)
export const AgenticApprovalInbox = () =>
    import('./pages/setting/agentic/AgenticApprovalInbox.vue').then((m) => m.default)
export const AgenticApprovalPage = () =>
    import('./pages/setting/agentic/AgenticApprovalPage.vue').then((m) => m.default)
export const AgenticApprovalSla = () =>
    import('./pages/setting/agentic/AgenticApprovalSla.vue').then((m) => m.default)
// Group root of the top-level approvals routes: only an outlet for the list and the detail.
export const ApprovalsShell = () => import('vue-router').then((m) => m.RouterView)
export const AgenticAdminConfig = () =>
    import('./pages/setting/agentic/AgenticAdminConfig.vue').then((m) => m.default)
export const SettingAiRuntime = () => import('./pages/setting/ai-runtime.vue').then((m) => m.default)

// Categories parent page (`category.vue`, the router-view shell of the Categories group). `CategoryManage` is the shared
// category manager that stays in base-admin (also used by the Posts, Gallery and other groups).
export const Category = () => import('./pages/category/category.vue').then((m) => m.default)
export const CategoryManage = () => import('@/pages/category/manage.vue').then((m) => m.default)

// Widget library ("Thư viện widget"): the list of every widget of the site and its design editor. The group shell is the
// router view itself: the two pages bring their own layout.
export const WidgetLibraryShell = () => import('vue-router').then((m) => m.RouterView)
export const WidgetLibrary = () => import('./pages/widget-library/index.vue').then((m) => m.default)
export const WidgetLibrarySources = () => import('./pages/widget-library/sources.vue').then((m) => m.default)
