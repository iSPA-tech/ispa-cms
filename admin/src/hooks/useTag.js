/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useQuery } from '@tanstack/vue-query'
import { getTags } from '@/libs/tag'

export const useTags = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['tags', params],
        queryFn: () => getTags(params),
        ...options,
    })
}
