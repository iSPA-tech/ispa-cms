/**
 * API calls of the workflow guardrails screens: versions (history, diff, publish, rollback), the sensitive changes that
 * wait before they go live, the pause of one workflow and the pause of all workflows.
 *
 * Workflow projects have no uuid column, so they are addressed by their numeric id; versions by their uuid.
 * Requests are sent with `silent: true`: every screen shows its own translated message instead of the raw server text.
 */

import ajax from '@/ajax'
import { errorCode, grantConfig } from '@/libs/securityTokens'

const quiet = { silent: true }
/** Request config with the step-up grant when there is one. */
const withGrant = (grant) => (grant ? grantConfig(grant) : quiet)
const data = (res) => res?.data?.data ?? {}
const versions = (projectId) => `workflows/${encodeURIComponent(projectId)}/versions`

/** Step-up purpose of a sensitive publish, rollback or approval. */
export const PUBLISH_PURPOSE = 'workflow_publish'
/** Step-up purpose of a credential create, change or delete. */
export const CREDENTIAL_PURPOSE = 'workflow_credential'

/** Versions of a workflow, newest first, with the draft status: `{ versions: [...], draft: { differs, published_number } }`. */
export const fetchVersions = async (projectId) => {
    const body = data(await ajax.get(versions(projectId), quiet))
    return { versions: Array.isArray(body.versions) ? body.versions : [], draft: body.draft || null }
}

/** Whether the draft differs from what runs: `{ differs, published_number, published_version }`. */
export const fetchDraftStatus = async (projectId) => data(await ajax.get(`${versions(projectId)}/draft-status`, quiet))

/**
 * What changed from one version to the draft (`against = 'draft'`) or to another version (its uuid).
 * @returns {Promise<object>} `{ steps: { added, removed, changed }, connections: { added, removed }, settings, identical }`.
 */
export const fetchVersionDiff = async (projectId, uuid, against = 'draft') =>
    data(await ajax.get(`${versions(projectId)}/${encodeURIComponent(uuid)}/diff`, { ...quiet, params: { against } })).diff || {}

/**
 * What a publish of the draft would do; nothing is written.
 * @returns {Promise<object>} `{ unchanged, sensitive, changes, path, cooling_off_minutes, step_up_required }`.
 */
export const previewPublish = async (projectId) => data(await ajax.get(`${versions(projectId)}/preview-publish`, quiet))

/**
 * Publish the draft. Resolves with the version summary and `path` (`published`, `unchanged`, `pending_review`, `cooling_off`).
 * Rejects with `step_up_required` (409) when the change is sensitive and no grant was sent, or `publish_refused` (422).
 */
export const publishDraft = async (projectId, note = '', grant = null) =>
    data(await ajax.post(`${versions(projectId)}/publish`, { note: note || null }, withGrant(grant)))

/** Publish a new version equal to an earlier one; same answers as publishDraft. */
export const rollbackVersion = async (projectId, uuid, grant = null) =>
    data(await ajax.post(`${versions(projectId)}/${encodeURIComponent(uuid)}/rollback`, {}, withGrant(grant)))

/** Sensitive changes of every workflow that wait for a second person or for the end of their waiting time. */
export const fetchPendingChanges = async () => {
    const rows = (await ajax.get('workflow/versions/pending', quiet))?.data?.data
    return Array.isArray(rows) ? rows : []
}

/** Approve a waiting change (needs a step-up grant; never the author). */
export const approveChange = async (projectId, uuid, grant = null) =>
    data(await ajax.post(`${versions(projectId)}/${encodeURIComponent(uuid)}/approve`, {}, withGrant(grant)))

/** Reject a waiting change, with an optional reason for its author. */
export const rejectChange = async (projectId, uuid, reason = '') =>
    data(await ajax.post(`${versions(projectId)}/${encodeURIComponent(uuid)}/reject`, { reason: reason || null }, quiet))

/** Cancel a waiting change (its author or a Webmaster). */
export const cancelChange = async (projectId, uuid) =>
    data(await ajax.post(`${versions(projectId)}/${encodeURIComponent(uuid)}/cancel`, {}, quiet))

/** Turn a workflow back on after a safety limit paused it. */
export const resumeWorkflow = async (projectId) => data(await ajax.post(`workflow/projects/${encodeURIComponent(projectId)}/resume`, {}, quiet))

/** Pause of all workflows: `{ paused, forced_by_server, setting }`. Webmaster only; other accounts get a 403. */
export const fetchGlobalPause = async () => data(await ajax.get('utilities/workflow/pause-all', quiet))

/** Pause or restart all workflows (Webmaster). */
export const setGlobalPause = async (paused) => data(await ajax.put('utilities/workflow/pause-all', { paused: Boolean(paused) }, quiet))

/** Codes the server answers when a workflow has no published version yet (activation, resume, run). */
export const NOT_PUBLISHED_CODES = ['not_published', 'project_not_published', 'workflow_not_published']

/** Machine code of a failed request (`step_up_required`, `publish_refused`, `not_published` ...), `forbidden` for a 403 without one. */
export const guardErrorCode = (error) => errorCode(error) || (error?.response?.status === 403 ? 'forbidden' : '')

/** Problems of a refused publish (`publish_refused`): `[{ code, message, step_code? }]`. */
export const refusalProblems = (error) => {
    const rows = error?.response?.data?.data?.problems
    return Array.isArray(rows) ? rows : []
}
