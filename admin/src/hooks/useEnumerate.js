/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useQuery } from '@tanstack/vue-query'
import { getEnumerate, getEnumerates, getEnumerateTypes } from '@/libs'

export const useEnumerateTypes = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['enumerate-type', params],
        queryFn: () => getEnumerateTypes(params),
        staleTime: 1000 * 60 * 60 * 24,
        cacheTime: 1000 * 60 * 60 * 25,
        ...options,
    })
}
export const useEnumerates = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['enumerate', params],
        queryFn: () => getEnumerates(params),
        staleTime: 1000 * 60 * 60 * 24,
        cacheTime: 1000 * 60 * 60 * 25,
        ...options,
    })
}

export const useEnumerate = (id, edit = false, options = {}) => {
    return useQuery({
        queryKey: ['enumerate', id, edit],
        queryFn: () => getEnumerate(id, edit),
        ...options,
    })
}
