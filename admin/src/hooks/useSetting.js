/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useQuery } from '@tanstack/vue-query'
import { unref } from 'vue'
import {
    getFunctionsList,
    getSchedulers,
    getSettings,
    getWidgets,
    getWorkflows,
    getWorkflow,
} from '@/libs'

const resolveSerializableValue = (value) => {
    const resolved = unref(value)

    if (Array.isArray(resolved)) {
        return resolved.map(resolveSerializableValue)
    }

    if (resolved && typeof resolved === 'object') {
        return Object.keys(resolved)
            .sort()
            .reduce((accumulator, key) => {
                accumulator[key] = resolveSerializableValue(resolved[key])
                return accumulator
            }, {})
    }

    return resolved ?? null
}

export const settingQueryKey = (scope, payload = null) => [scope, resolveSerializableValue(payload)]

export const useSettings = (params = {}, options = {}) => {
    return useQuery({
        queryKey: () => settingQueryKey('settings', params),
        queryFn: () => getSettings(params),
        ...options,
    })
}

export const useSchedulers = (params = {}, options = {}) => {
    return useQuery({
        queryKey: () => settingQueryKey('schedulers', params),
        queryFn: () => getSchedulers(params),
        ...options,
    })
}

export const useWorkflows = (params = {}, options = {}) => {
    return useQuery({
        queryKey: () => settingQueryKey('workflows', params),
        queryFn: () => getWorkflows(params),
        ...options,
    })
}

export const useWorkflow = (id, options = {}) => {
    return useQuery({
        queryKey: () => settingQueryKey('workflow', id),
        queryFn: () => getWorkflow(unref(id)),
        enabled: () => !!unref(id),
        ...options,
    })
}

export const useWorkflowFunctions = (options = {}) => {
    return useQuery({
        queryKey: ['workflow-function-list'],
        queryFn: () => getFunctionsList(),
        staleTime: 1000 * 60 * 10,
        ...options,
    })
}
