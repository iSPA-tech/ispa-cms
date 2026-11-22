/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useMutation, useQuery } from '@tanstack/vue-query'
import { getLanguage, getLanguageFlags, getLanguages } from '@/libs'
import { deleteLanguage, restoreLanguage, switchLanguage } from '../libs'

export const useLanguages = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['language', params],
        queryFn: () => getLanguages(params),
        staleTime: 1000 * 60 * 60 * 24, // 24 hours
        cacheTime: 1000 * 60 * 60 * 25,
        ...options,
    })
}

/** Flag image files available for a language (cached for an hour). */
export const useLanguageFlags = (options = {}) => {
    return useQuery({
        queryKey: ['language', 'flags'],
        queryFn: getLanguageFlags,
        staleTime: 1000 * 60 * 60,
        ...options,
    })
}

export const useLanguage = (id, edit = false, options = {}) => {
    return useQuery({
        queryKey: ['language', id, edit],
        queryFn: () => getLanguage(id, edit),
        ...options,
    })
}

export const useSwitchLanguage = (payload, options = {}) => {
    return useMutation({
        mutationFn: (payload) => {
            return switchLanguage(payload.id, payload.col, payload.value)
        },
        ...options,
    })
}

export const useDeleteLanguage = (id, options = {}) => {
    return useMutation({
        mutationFn: (id) => {
            return deleteLanguage(id)
        },
        ...options,
    })
}
export const useRestoreLanguage = (id, options = {}) => {
    return useMutation({
        mutationFn: (id) => {
            return restoreLanguage(id)
        },
        ...options,
    })
}
