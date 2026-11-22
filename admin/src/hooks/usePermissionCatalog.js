/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useQuery, useMutation } from '@tanstack/vue-query'
import { computed } from 'vue'
import ajax from '@/ajax'

/**
 * Fetch list of permission modules with counts.
 * Used to populate the left sidebar in the permission editor.
 */
export const usePermissionModules = (options = {}) => {
    return useQuery({
        queryKey: ['permission-modules'],
        queryFn: async () => {
            const res = await ajax.get('permissions/modules')
            return res?.data?.data || []
        },
        staleTime: 5 * 60 * 1000, // Cache for 5 minutes
        ...options,
    })
}

/**
 * Fetch paginated permission catalog for a specific module.
 * @param {Ref<string>} module - reactive module name
 * @param {Ref<string>} search - reactive search term
 * @param {Ref<number>} page - reactive page number
 */
export const usePermissionCatalog = (module, search = '', page = 1, options = {}) => {
    return useQuery({
        queryKey: ['permission-catalog', module, search, page],
        queryFn: async () => {
            const params = new URLSearchParams()
            if (module.value) params.append('module', module.value)
            if (search.value) params.append('search', search.value)
            if (page.value) params.append('page', page.value)
            params.append('per_page', '200')

            const res = await ajax.get(`permissions/catalog?${params.toString()}`)
            return res?.data || { data: [], meta: {} }
        },
        enabled: computed(() => Boolean(module.value)),
        keepPreviousData: true,
        staleTime: 30 * 1000, // 30 seconds
        ...options,
    })
}

/**
 * Fetch current permissions for a role.
 * @param {Ref<number|null>} roleId - reactive role ID
 */
export const useRolePermissions = (roleId, options = {}) => {
    return useQuery({
        queryKey: ['role-permissions', roleId],
        queryFn: async () => {
            if (!roleId.value) return { permission_ids: [], permission_keys: [] }
            const res = await ajax.get(`role/${roleId.value}/permissions`)
            return res?.data?.data || { permission_ids: [], permission_keys: [] }
        },
        enabled: computed(() => Boolean(roleId.value)),
        ...options,
    })
}

/**
 * Mutation to sync role permissions.
 * @returns {UseMutationReturnType}
 */
export const useSyncRolePermissions = () => {
    return useMutation({
        mutationFn: async ({ roleId, permissionIds }) => {
            const res = await ajax.put(`role/${roleId}/permissions`, {
                permission_ids: permissionIds,
            })
            return res?.data
        },
    })
}
