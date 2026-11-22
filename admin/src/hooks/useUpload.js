/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useQuery } from '@tanstack/vue-query'
import { unref } from 'vue'
import ajax from '@/ajax'
import { getUploadDashboard, getUploads } from '@/libs/upload'

// `endpoint` (optional) switches the list route; the default call and cache key stay exactly as before.
export const useUploads = (params = {}, options = {}, endpoint = null) => {
    return useQuery({
        queryKey: endpoint ? ['uploads', endpoint, params] : ['uploads', params],
        queryFn: async () => (endpoint ? await getUploads(params, endpoint) : await getUploads(params)),
        ...options,
    })
}

export const useUploadDashboard = (params = {}, options = {}) => {
    return useQuery({
        queryKey: () => ['upload-dashboard', unref(params)],
        queryFn: async (opt) => {
            const baseParams = unref(params) || {}
            const resolvedParams = {
                ...baseParams,
            }
            if (opt?.meta?.reload) {
                resolvedParams.reload = true
            }

            return await getUploadDashboard(resolvedParams)
        },
        ...options,
    })
}

export const useUploadFolders = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['upload-folders', params],
        queryFn: async () => await fetchFolders(params),
        ...options,
    })
}

export const fetchFolders = async (params = {}) => {
    try {
        const { data } = await ajax.get('upload/folder', {
            params,
        })

        return data
    } catch (e) {
        return e
    }
}
