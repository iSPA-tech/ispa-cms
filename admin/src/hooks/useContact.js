/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { unref } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { getContact, getContactDashboard, getContacts, searchFilterContacts } from '@/libs'

export const useContacts = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['contacts', params],
        queryFn: () => getContacts(params),
        ...options,
    })
}

export const useSearchContacts = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['contacts', 'search', params],
        queryFn: () => searchFilterContacts(params),
        ...options,
    })
}

export const useContactDetail = (id, edit = false, options = {}) => {
    return useQuery({
        queryKey: ['contact', id, edit],
        queryFn: () => getContact(unref(id), edit),
        ...options,
    })
}

export const useContactDashboard = (params = {}, options = {}) => {
    return useQuery({
        queryKey: () => ['contact-dashboard', unref(params)],
        queryFn: async ({ meta }) => {
            const baseParams = unref(params) || {}
            const resolvedParams = { ...baseParams }

            if (meta?.reload) {
                resolvedParams.reload = 1
            }

            return await getContactDashboard(resolvedParams)
        },
        ...options,
    })
}
