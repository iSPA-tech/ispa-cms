/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useQuery } from '@tanstack/vue-query'
import { getPermissions, getPermission, getRoles } from '@/libs'

export const useRoles = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['role', params],
        queryFn: () => getRoles(params),
        ...options,
    })
}

export const usePermissions = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['permission', params],
        queryFn: () => getPermissions(params),
        ...options,
    })
}

export const usePermission = (id, edit = false, options = {}) => {
    return useQuery({
        queryKey: ['permission', id, edit],
        queryFn: () => getPermission(id, edit),
        ...options,
    })
}
