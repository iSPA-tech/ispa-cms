/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

/**
 * API calls for running and scheduling an authored workflow project and reading its runs.
 * Workflow projects have no uuid column, so they are addressed by their numeric id.
 *
 * Requests are sent with `silent: true`: the panel shows its own translated message for each
 * known error code instead of the raw server text.
 */
const quiet = { silent: true }

/** Start a run now. Resolves with the new run ({id, status, source, ...}). */
export const runWorkflowProject = async (projectId) => {
    const res = await ajax.post(`workflow/projects/${projectId}/run`, {}, quiet)
    return res.data?.data?.instance || null
}

/** Save the schedule; an empty cron turns it off. Resolves with the stored schedule. */
export const saveWorkflowSchedule = async (projectId, { cron = null, timezone = null } = {}) => {
    const res = await ajax.put(`workflow/projects/${projectId}/schedule`, { cron: cron || null, timezone: timezone || null }, quiet)
    return res.data?.data?.schedule || null
}

/** One page of the project's runs, newest first, with the current schedule. */
export const getWorkflowRuns = async (projectId, { page = 1, perPage = 10 } = {}) => {
    const res = await ajax.get(`workflow/projects/${projectId}/instances`, { params: { page, per_page: perPage }, ...quiet })
    const payload = res.data?.data || {}
    return {
        runs: Array.isArray(payload.data) ? payload.data : [],
        meta: payload.meta || { page, per_page: perPage, total: 0, last_page: 1 },
        schedule: payload.schedule || null,
    }
}

/**
 * The approvals waiting for a person, keyed by the run they belong to: `{ [runId]: approvalId }`. A run that waits at an
 * approval or question step is found through the approval's workflow link. Empty for people who may not see approvals.
 */
export const getPendingApprovalsByRun = async () => {
    const res = await ajax.get('agentic/approvals', { params: { status: 'pending', per_page: 100 }, ...quiet })
    const rows = res.data?.data?.data
    const map = {}
    ;(Array.isArray(rows) ? rows : []).forEach((row) => {
        const runId = row?.workflow?.id ?? row?.diff_data?.workflow_node?.instance_id
        if (runId !== null && runId !== undefined && map[runId] === undefined) map[runId] = row.id
    })
    return map
}

/** The step log of one run. */
export const getWorkflowRunSteps = async (runId) => {
    const res = await ajax.get(`workflow/instances/${runId}/steps`, quiet)
    const steps = res.data?.data
    return Array.isArray(steps) ? steps : []
}

/** The server's error code of a failed request (`workflow_already_running`, `project_inactive`, ...), or ''. */
export const workflowErrorCode = (error) => {
    if (error?.response?.status === 403) return 'forbidden'
    return error?.response?.data?.error_code || ''
}
