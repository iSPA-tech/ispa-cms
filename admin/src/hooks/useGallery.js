/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useMutation, useQuery } from '@tanstack/vue-query'
import { getGalleries, getGallery, quickGallery } from '@/libs'

export const useGalleries = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['galleries', params],
        queryFn: () => getGalleries(params),
        ...options,
    })
}

export const useGallery = (id, edit = false, options = {}) => {
    return useQuery({
        queryKey: ['post', id, edit],
        queryFn: () => getGallery(id, edit),
        ...options,
    })
}

export const useQuickGallery = (payload = {}, options = {}) => {
    return useMutation({
        mutationFn: (payload) => {
            return quickGallery(payload.id, payload.status)
        },
        ...options,
    })
}
