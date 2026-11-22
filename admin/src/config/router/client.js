/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

export const Dashboard = () => import('@/pages/dashboard.vue').then((m) => m.default)
export const Account = () => import('@/pages/account/account.vue').then((m) => m.default)
export const AccountActivity = () => import('@/pages/account/activity.vue').then((m) => m.default)
export const AccountDashboard = () => import('@/pages/account/dashboard.vue').then((m) => m.default)
export const AccountPassword = () => import('@/pages/account/password.vue').then((m) => m.default)
export const AccountSetting = () => import('@/pages/account/setting.vue').then((m) => m.default)
export const AccountUpdate = () => import('@/pages/account/update.vue').then((m) => m.default)
