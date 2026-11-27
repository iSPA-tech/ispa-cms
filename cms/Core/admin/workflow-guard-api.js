/**
 * API calls of the workflow guardrails screens: versions (history, diff, publish, rollback), the sensitive changes that
 * wait before they go live, the pause of one workflow and the pause of all workflows.
 *
 * Workflow projects and their versions are addressed by their uuid (UUID-first), never by a row id.
 * Requests are sent with `silent: true`: every screen shows its own translated message instead of the raw server text.
 */

import ajax from '@/ajax'
import { errorCode, grantConfig } from '@/libs/securityTokens'

const quiet = { silent: true }
/** Request config with the step-up grant when there is one. */
const withGrant = (grant) => (grant ? grantConfig(grant) : quiet)
const data = (res) => res?.data?.data ?? {}
const versions = (projectUuid) => `workflows/${encodeURIComponent(projectUuid)}/versions`

/** Step-up purpose of a sensitive publish, rollback or approval. */
export const PUBLISH_PURPOSE = 'workflow_publish'
/** Step-up purpose of a credential create, change or delete. */
export const CREDENTIAL_PURPOSE = 'workflow_credential'

/** Versions of a workflow, newest first, with the draft status: `{ versions: [...], draft: { differs, published_number } }`. */
export const fetchVersions = async (projectUuid) => {
    const body = data(await ajax.get(versions(projectUuid), quiet))
    return { versions: Array.isArray(body.versions) ? body.versions : [], draft: body.draft || null }
}

/** Whether the draft differs from what runs: `{ differs, published_number, published_version }`. */
export const fetchDraftStatus = async (projectUuid) => data(await ajax.get(`${versions(projectUuid)}/draft-status`, quiet))

/**
 * What changed from one version to the draft (`against = 'draft'`) or to another version (its uuid).
 * @returns {Promise<object>} `{ steps: { added, removed, changed }, connections: { added, removed }, settings, identical }`.
 */
export const fetchVersionDiff = async (projectUuid, uuid, against = 'draft') =>
    data(await ajax.get(`${versions(projectUuid)}/${encodeURIComponent(uuid)}/diff`, { ...quiet, params: { against } })).diff || {}

/**
 * What a publish of the draft would do; nothing is written.
 * @returns {Promise<object>} `{ unchanged, sensitive, changes, path, cooling_off_minutes, step_up_required }`.
 */
export const previewPublish = async (projectUuid) => data(await ajax.get(`${versions(projectUuid)}/preview-publish`, quiet))

/**
 * Publish the draft. Resolves with the version summary and `path` (`published`, `unchanged`, `pending_review`, `cooling_off`).
 * Rejects with `step_up_required` (409) when the change is sensitive and no grant was sent, or `publish_refused` (422).
 * `releaseNow` skips the waiting time of a site with one publisher; a second publisher still decides.
 */
export const publishDraft = async (projectUuid, note = '', grant = null, releaseNow = false) =>
    data(await ajax.post(`${versions(projectUuid)}/publish`, { note: note || null, ...(releaseNow ? { release_now: true } : {}) }, withGrant(grant)))

/** Publish a new version equal to an earlier one; same answers as publishDraft. */
export const rollbackVersion = async (projectUuid, uuid, grant = null) =>
    data(await ajax.post(`${versions(projectUuid)}/${encodeURIComponent(uuid)}/rollback`, {}, withGrant(grant)))

/** Sensitive changes of every workflow that wait for a second person or for the end of their waiting time. */
export const fetchPendingChanges = async () => {
    const rows = (await ajax.get('workflow/versions/pending', quiet))?.data?.data
    return Array.isArray(rows) ? rows : []
}

/** Approve a waiting change (needs a step-up grant; never the author). */
export const approveChange = async (projectUuid, uuid, grant = null) =>
    data(await ajax.post(`${versions(projectUuid)}/${encodeURIComponent(uuid)}/approve`, {}, withGrant(grant)))

/** Put a change that waits out its waiting time live now (site with one publisher; needs a step-up grant). */
export const releaseChange = async (projectUuid, uuid, grant = null) =>
    data(await ajax.post(`${versions(projectUuid)}/${encodeURIComponent(uuid)}/release-now`, {}, withGrant(grant)))

/** Reject a waiting change, with an optional reason for its author. */
export const rejectChange = async (projectUuid, uuid, reason = '') =>
    data(await ajax.post(`${versions(projectUuid)}/${encodeURIComponent(uuid)}/reject`, { reason: reason || null }, quiet))

/** Cancel a waiting change (its author or a Webmaster). */
export const cancelChange = async (projectUuid, uuid) =>
    data(await ajax.post(`${versions(projectUuid)}/${encodeURIComponent(uuid)}/cancel`, {}, quiet))

/** Turn a workflow back on after a safety limit paused it. */
export const resumeWorkflow = async (projectUuid) => data(await ajax.post(`workflow/projects/${encodeURIComponent(projectUuid)}/resume`, {}, quiet))

/** Pause of all workflows: `{ paused, forced_by_server, setting }`. Webmaster only; other accounts get a 403. */
export const fetchGlobalPause = async () => data(await ajax.get('utilities/workflow/pause-all', quiet))

/** Pause or restart all workflows (Webmaster). */
export const setGlobalPause = async (paused) => data(await ajax.put('utilities/workflow/pause-all', { paused: Boolean(paused) }, quiet))

/** Who reviews workflow changes (Webmaster only): `{ named: [{ uuid, name, detail }], default }`. */
export const fetchChangeReviewers = async () => data(await ajax.get('utilities/workflow/change-reviewers', quiet))

/** Name the reviewers by uuid; an empty list asks every publisher and Webmaster again. */
export const setChangeReviewers = async (uuids = []) =>
    data(await ajax.put('utilities/workflow/change-reviewers', { users: (Array.isArray(uuids) ? uuids : []).filter((uuid) => typeof uuid === 'string' && uuid) }, quiet))

/** Codes the server answers when a workflow has no published version yet (activation, resume, run). */
export const NOT_PUBLISHED_CODES = ['not_published', 'project_not_published', 'workflow_not_published']

/** Machine code of a failed request (`step_up_required`, `publish_refused`, `not_published` ...), `forbidden` for a 403 without one. */
export const guardErrorCode = (error) => errorCode(error) || (error?.response?.status === 403 ? 'forbidden' : '')

/** Problems of a refused publish (`publish_refused`): `[{ code, message, step_code? }]`. */
export const refusalProblems = (error) => {
    const rows = error?.response?.data?.data?.problems
    return Array.isArray(rows) ? rows : []
}
