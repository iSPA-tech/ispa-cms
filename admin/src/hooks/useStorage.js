/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useQuery } from '@tanstack/vue-query'
import { getStorage, getStorages } from '@/libs'

export const useStorages = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['storages', params],
        queryFn: () => getStorages(params),
        // enabled: false,
        ...options,
    })
}

export const useStorage = (id, edit = false, options = {}) => {
    return useQuery({
        queryKey: ['storages', id, edit],
        queryFn: () => getStorage(id, edit),
        // enabled: false,
        ...options,
    })
}
