/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

/**
 * Fetch the aggregate dashboard summary.
 *
 * @param {{range?: string, from?: string, to?: string, reload?: number}} params
 */
export const getDashboardSummary = async (params = {}) => {
    return await ajax.get('dashboard/summary', { params }).then((res) => {
        return res?.data?.data || res?.data || {}
    })
}
