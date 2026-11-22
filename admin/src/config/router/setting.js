/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

export const Setting = () => import('@/pages/setting/setting.vue').then((m) => m.default)
export const SettingHub = () => import('@/pages/setting/setting-hub.vue').then((m) => m.default)
export const SettingCategoryLayout = () =>
    import('@/pages/setting/setting-category-layout.vue').then((m) => m.default)
