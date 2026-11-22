/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useQuery } from '@tanstack/vue-query'
import { getFields, getField } from '@/libs'

export const useFields = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['fields', params],
        queryFn: () => getFields(params),
        ...options,
    })
}

export const useField = (id, edit = false, options = {}) => {
    return useQuery({
        queryKey: ['field', id, edit],
        queryFn: () => getField(id, edit),
        ...options,
    })
}
