/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { computed, unref } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { getAccount, getUser, getUsers, getUsersByFilter } from '@/libs'

export const useUsers = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['users', params],
        queryFn: () => getUsers(params),
        ...options,
    })
}

/** Users read through `users/filter` (role / tag filters); enable it only while such a filter is set. */
export const useUsersByFilter = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['users-filter', params],
        queryFn: () => getUsersByFilter(unref(params)),
        ...options,
    })
}

export const useAccount = (options = {}) => {
    return useQuery({
        queryKey: ['account'],
        queryFn: () => getAccount(),
        staleTime: 1000 * 60 * 5, //  5 minutes
        ...options,
    })
}

export const useUserDetail = (uuid, params = {}, options = {}) => {
    return useQuery({
        queryKey: () => ['user-detail', unref(uuid), unref(params)],
        queryFn: async () => {
            const resolvedUuid = unref(uuid)
            if (!resolvedUuid) {
                return {}
            }

            const resolvedParams = unref(params) || {}

            return await getUser(resolvedUuid, { params: resolvedParams })
        },
        enabled: computed(() => Boolean(unref(uuid))),
        ...options,
    })
}
