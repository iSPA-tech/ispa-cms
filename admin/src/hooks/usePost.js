/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useMutation, useQuery } from '@tanstack/vue-query'
import { unref } from 'vue'
import { getPosts, getPost, getPostDashboard, quickPost } from '@/libs'

export const usePostDashboard = (params = {}, options = {}) => {
    return useQuery({
        queryKey: () => ['post-dashboard', unref(params)],
        queryFn: async ({ meta }) => {
            const baseParams = unref(params) || {}
            const resolvedParams = {
                ...baseParams,
            }

            if (meta?.reload) {
                resolvedParams.reload = true
            }

            return await getPostDashboard(resolvedParams)
        },
        staleTime: 1000 * 60 * 5,
        cacheTime: 1000 * 60 * 30,
        ...options,
    })
}

export const usePosts = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['posts', params],
        queryFn: () => getPosts(params),
        ...options,
    })
}

export const usePost = (id, edit = false, options = {}) => {
    return useQuery({
        queryKey: ['post', id, edit],
        queryFn: () => getPost(id, edit),
        ...options,
    })
}

export const useQuickPost = (payload = {}, options = {}) => {
    return useMutation({
        mutationFn: (payload) => {
            return quickPost(payload.id, payload.status)
        },
        ...options,
    })
}
