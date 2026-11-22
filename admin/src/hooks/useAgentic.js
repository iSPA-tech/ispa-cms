/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { computed, unref } from 'vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import ajax from '@/ajax'

const appendParam = (params, key, value) => {
    if (value === null || value === undefined || value === '') {
        return
    }

    params.append(key, String(value))
}

const normalizeCatalogResponse = (response, fallback) => response?.data?.data || fallback

const normalizeMutationResponse = (response, fallback = {}) => response?.data || fallback

export const agenticCatalogQueryKey = (scope, filters = {}) => [scope, buildCatalogParams(filters)]

export const agenticEntityQueryKey = (scope, id) => [scope, unref(id) || null]

const buildCatalogParams = (filters = {}) => {
    const params = new URLSearchParams()

    appendParam(params, 'search', unref(filters.search))
    appendParam(params, 'family', unref(filters.family))
    appendParam(params, 'capability_class', unref(filters.capabilityClass))
    appendParam(params, 'status', unref(filters.status))
    appendParam(params, 'tool_name', unref(filters.toolName))
    appendParam(params, 'module_scope', unref(filters.moduleScope))
    appendParam(params, 'environment', unref(filters.environment))
    appendParam(params, 'enabled', unref(filters.enabled))
    appendParam(params, 'apply_outcome', unref(filters.applyOutcome))
    appendParam(params, 'artifact_type', unref(filters.artifactType))
    appendParam(params, 'profile', unref(filters.profile))
    appendParam(params, 'subject_type', unref(filters.subjectType))
    appendParam(params, 'workflow_id', unref(filters.workflowId))
    appendParam(params, 'page', unref(filters.page) || 1)
    appendParam(params, 'per_page', unref(filters.perPage) || 10)

    return params.toString()
}

export const useAgenticToolDefinitions = (filters = {}, options = {}) => {
    return useQuery({
        queryKey: () => agenticCatalogQueryKey('agentic-tool-definitions', filters),
        queryFn: async () => {
            const params = buildCatalogParams(filters)
            const res = await ajax.get(`agentic/tool-definitions${params ? `?${params}` : ''}`)
            return normalizeCatalogResponse(res, { data: [], meta: {} })
        },
        keepPreviousData: true,
        ...options,
    })
}

export const useAgenticHealth = (options = {}) => {
    return useQuery({
        queryKey: ['agentic-health'],
        queryFn: async () => {
            const res = await ajax.get('agentic/health')
            return res?.data?.data || null
        },
        refetchOnWindowFocus: false,
        ...options,
    })
}

export const useAgenticToolBindings = (filters = {}, options = {}) => {
    return useQuery({
        queryKey: () => agenticCatalogQueryKey('agentic-tool-bindings', filters),
        queryFn: async () => {
            const params = buildCatalogParams(filters)
            const res = await ajax.get(`agentic/tool-bindings${params ? `?${params}` : ''}`)
            return normalizeCatalogResponse(res, { data: [], meta: {} })
        },
        keepPreviousData: true,
        ...options,
    })
}

export const useAgenticProfiles = (filters = {}, options = {}) => {
    return useQuery({
        queryKey: () => agenticCatalogQueryKey('agentic-profiles', filters),
        queryFn: async () => {
            const params = buildCatalogParams(filters)
            const res = await ajax.get(`agentic/profiles${params ? `?${params}` : ''}`)
            return normalizeCatalogResponse(res, { data: [], meta: {} })
        },
        keepPreviousData: true,
        ...options,
    })
}

export const useAgenticRuns = (filters = {}, options = {}) => {
    return useQuery({
        queryKey: () => agenticCatalogQueryKey('agentic-runs', filters),
        queryFn: async () => {
            const params = buildCatalogParams(filters)
            const res = await ajax.get(`agentic/runs${params ? `?${params}` : ''}`)
            return normalizeCatalogResponse(res, { data: [], meta: {} })
        },
        keepPreviousData: true,
        ...options,
    })
}

export const useAgenticSchedules = (filters = {}, options = {}) => {
    return useQuery({
        queryKey: () => agenticCatalogQueryKey('agentic-schedules', filters),
        queryFn: async () => {
            const params = buildCatalogParams(filters)
            const res = await ajax.get(`agentic/schedules${params ? `?${params}` : ''}`)
            return normalizeCatalogResponse(res, { data: [], meta: {} })
        },
        keepPreviousData: true,
        ...options,
    })
}

export const useAgenticRun = (runId, options = {}) => {
    return useQuery({
        queryKey: () => agenticEntityQueryKey('agentic-run', runId),
        queryFn: async () => {
            const res = await ajax.get(`agentic/runs/${unref(runId)}`)
            return res?.data?.data || null
        },
        enabled: computed(() => Boolean(unref(runId))),
        keepPreviousData: true,
        ...options,
    })
}

export const useCancelAgenticRun = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ runId, note = null }) => {
            const res = await ajax.post(`agentic/runs/${runId}/cancel`, { note })
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-runs'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-run'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-approvals'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-approval'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-schedules'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useStopAgenticRun = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ runId, note = null }) => {
            const res = await ajax.post(`agentic/runs/${runId}/stop`, { note })
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-runs'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-run'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-approvals'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-approval'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-schedules'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useRerunAgenticRun = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ runId }) => {
            const res = await ajax.post(`agentic/runs/${runId}/rerun`)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-runs'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-run'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-schedules'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useAgenticProfileGrants = (profileKey, options = {}) => {
    return useQuery({
        queryKey: () => agenticEntityQueryKey('agentic-profile-grants', profileKey),
        queryFn: async () => {
            const res = await ajax.get(`agentic/profiles/${unref(profileKey)}/tool-definitions`)
            return res?.data?.data || { profile: null, grants: [] }
        },
        enabled: computed(() => Boolean(unref(profileKey))),
        keepPreviousData: true,
        ...options,
    })
}

export const useAgenticApprovals = (filters = {}, options = {}) => {
    return useQuery({
        queryKey: () => agenticCatalogQueryKey('agentic-approvals', filters),
        queryFn: async () => {
            const params = buildCatalogParams(filters)
            const res = await ajax.get(`agentic/approvals${params ? `?${params}` : ''}`)
            return normalizeCatalogResponse(res, { data: [], meta: {} })
        },
        keepPreviousData: true,
        ...options,
    })
}

export const useAgenticApproval = (approvalId, options = {}) => {
    return useQuery({
        queryKey: () => agenticEntityQueryKey('agentic-approval', approvalId),
        queryFn: async () => {
            const res = await ajax.get(`agentic/approvals/${unref(approvalId)}`)
            return res?.data?.data || null
        },
        enabled: computed(() => Boolean(unref(approvalId))),
        keepPreviousData: true,
        ...options,
    })
}

export const useAgenticSyncTools = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async (payload = {}) => {
            const res = await ajax.post('agentic/tool-definitions/sync', payload)
            return normalizeMutationResponse(res)
        },
        onSuccess: (...args) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-tool-definitions'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-tool-bindings'] })
            if (typeof onSuccess === 'function') {
                onSuccess(...args)
            }
        },
        ...rest,
    })
}

export const useAgenticUpdateToolBinding = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ bindingId, payload }) => {
            const res = await ajax.patch(`agentic/tool-bindings/${bindingId}`, payload)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-tool-bindings'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useAgenticCreateProfile = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async (payload) => {
            const res = await ajax.post('agentic/profiles', payload)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-profiles'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useAgenticSeedProfileTemplates = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async () => {
            const res = await ajax.post('agentic/profiles/seed-templates')
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-tool-definitions'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-tool-bindings'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-profiles'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-profile-grants'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-runs'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-schedules'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useAgenticUpdateProfile = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ profileKey, payload }) => {
            const res = await ajax.patch(`agentic/profiles/${profileKey}`, payload)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-profiles'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-profile-grants'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-runs'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-schedules'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useAgenticGrantProfileTools = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ profileKey, toolNames }) => {
            const res = await ajax.post(`agentic/profiles/${profileKey}/tool-definitions/grant`, {
                tool_names: toolNames,
            })
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-tool-definitions'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-profiles'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-profile-grants'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useAgenticUpdateProfileToolGrant = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ profileKey, toolDefinitionKey, payload }) => {
            const res = await ajax.patch(`agentic/profiles/${profileKey}/tool-definitions/${toolDefinitionKey}`, payload)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-tool-definitions'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-profiles'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-profile-grants'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useAgenticRevokeProfileToolGrant = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ profileKey, toolDefinitionKey }) => {
            const res = await ajax.delete(`agentic/profiles/${profileKey}/tool-definitions/${toolDefinitionKey}`)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-tool-definitions'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-profiles'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-profile-grants'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useApproveAgenticApproval = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ approvalId, reviewerNote = null }) => {
            const res = await ajax.post(`agentic/approvals/${approvalId}/approve`, {
                reviewer_note: reviewerNote,
            })
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-approvals'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-approval'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-runs'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-run'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useRejectAgenticApproval = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ approvalId, reviewerNote = null }) => {
            const res = await ajax.post(`agentic/approvals/${approvalId}/reject`, {
                reviewer_note: reviewerNote,
            })
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-approvals'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-approval'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-runs'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-run'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useApplyAgenticApproval = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ approvalId }) => {
            const res = await ajax.post(`agentic/approvals/${approvalId}/apply`)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-approvals'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-approval'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-runs'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-run'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}


export const useCreateAgenticSchedule = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async (payload) => {
            const res = await ajax.post('agentic/schedules', payload)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-schedules'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useUpdateAgenticSchedule = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ scheduleId, payload }) => {
            const res = await ajax.patch(`agentic/schedules/${scheduleId}`, payload)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-schedules'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useDispatchAgenticSchedule = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ scheduleId }) => {
            const res = await ajax.post(`agentic/schedules/${scheduleId}/dispatch`)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-schedules'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-runs'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useRunOnceAgenticSchedule = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ scheduleId }) => {
            const res = await ajax.post(`agentic/schedules/${scheduleId}/run-once`)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-schedules'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-runs'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

export const useResumeDispatchAgenticSchedule = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async ({ scheduleId }) => {
            const res = await ajax.post(`agentic/schedules/${scheduleId}/resume-dispatch`)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-schedules'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-runs'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}

/**
 * Launch an ad-hoc agentic task (for Task Launcher + Inline Assist).
 * POST /v2/admin/agentic/runs/launch
 */
export const useLaunchAgenticTask = (options = {}) => {
    const queryClient = useQueryClient()
    const { onSuccess, ...rest } = options

    return useMutation({
        mutationFn: async (payload) => {
            const res = await ajax.post('agentic/runs/launch', payload)
            return normalizeMutationResponse(res)
        },
        onSuccess: (data, variables, ...restArgs) => {
            queryClient.invalidateQueries({ queryKey: ['agentic-runs'] })
            queryClient.invalidateQueries({ queryKey: ['agentic-approvals'] })
            if (typeof onSuccess === 'function') {
                onSuccess(data, variables, ...restArgs)
            }
        },
        ...rest,
    })
}
