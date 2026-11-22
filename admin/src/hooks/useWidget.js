/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */
import { useQuery } from '@tanstack/vue-query'
import { getInputs, getWidgets, getTemplates } from '@/libs'

export const widgetTemplateDefault = () => {
    return {
        id: 0,
        widget_id: 0,
        name: null,
        // `{vi?, en?}`: the design's name per language; null = the original name everywhere.
        name_translations: null,
        content: '',
        css: '',
        query: [],
        project_name: [],
    }
}
export const widgetInputDefault = () => {
    return {
        id: 0,
        widget_id: 0,
        name: null,
        type: 'text',
        default_value: null,
        options: [],
    }
}

export const useWidgets = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['widgets', params],
        queryFn: () => getWidgets(params),
        ...options,
    })
}

export const useInputs = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['inputs', params],
        queryFn: () => getInputs(params),
        ...options,
    })
}

export const useTemplates = (params = {}, options = {}) => {
    return useQuery({
        queryKey: ['templates', params],
        queryFn: () => getTemplates(params),
        ...options,
    })
}
