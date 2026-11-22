/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

export const SiteLayoutIndex = () =>
    import('./pages/index.vue').then((m) => m.default)
export const SiteLayoutPlacements = () =>
    import('./pages/placements.vue').then((m) => m.default)
