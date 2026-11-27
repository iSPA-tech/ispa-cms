/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * API calls and rules of "Nâng cấp tất cả" (upgrade-all spec 2026-11-26; UpdateBatchController,
 * `api/v2/admin/utilities/updates/batch`). Requests are `silent`: the dialog and panel show their own translated text.
 * The rules are free of Vue so they can be tested on their own.
 */

import ajax from '@/ajax'

const quiet = { silent: true }
const base = 'utilities/updates/batch'

/** The three kinds an upgrade-all run touches, in run order. */
export const KINDS = ['module', 'widget', 'workflow']

const list = (value) => (Array.isArray(value) ? value : [])

/** What a run would do now: `{ items, left_out, runner_ready }`. */
export const fetchPlan = async (kinds = KINDS) => (await ajax.get(`${base}/plan`, { ...quiet, params: { kinds } })).data?.data

/** The confirmed items as the server checks them: kind, key and target version. */
export const confirmItems = (items) => list(items).map((item) => ({ kind: item.kind, key: item.key, to: item.to ?? null }))

/** Start a run with the confirmed plan items; resolves with the run. */
export const startBatch = async ({ kinds = KINDS, items = [], backup = true } = {}) =>
    (await ajax.post(base, { kinds, items: confirmItems(items), backup: Boolean(backup) }, quiet)).data?.data

/** The open run, else the last finished one not hidden, else null. */
export const fetchCurrent = async () => (await ajax.get(`${base}/current`, quiet)).data?.data ?? null

/** "Dừng": the running item finishes, nothing more starts. */
export const stopBatch = async (uuid) => (await ajax.post(`${base}/${encodeURIComponent(uuid)}/stop`, {}, quiet)).data?.data

/** "Ẩn": hide a finished run's outcome. */
export const dismissBatch = async (uuid) => (await ajax.post(`${base}/${encodeURIComponent(uuid)}/dismiss`, {}, quiet)).data?.data

// ---------------------------------------------------------------------------------------------------------------------
// Rules
// ---------------------------------------------------------------------------------------------------------------------

/** Items per kind: `{ module, widget, workflow }`. */
export const countsByKind = (items) => {
    const counts = { module: 0, widget: 0, workflow: 0 }
    for (const item of list(items)) {
        if (item?.kind in counts) counts[item.kind] += 1
    }
    return counts
}

/** Whether a run is still going (queued or running). */
export const isOpen = (batch) => batch?.status === 'queued' || batch?.status === 'running'

const ERRORS = {
    UPDATE_BATCH_RUNNING: 'running',
    UPDATE_BATCH_PLAN_CHANGED: 'changed',
    MODULE_INSTALL_NO_RUNNER: 'no_runner',
    CORE_UPGRADE_RUNNING: 'core_running',
    MODULE_INSTALL_RUNNING: 'install_running',
    UPDATE_BATCH_EMPTY: 'empty',
    MODULE_CATALOG_UNAVAILABLE: 'unreachable',
}

/**
 * A failed request in the page's terms: `{ kind }`, one of running, changed, no_runner, core_running, install_running,
 * empty, unreachable, forbidden, busy or failed. The copy is `text.update_batch.error.<kind>`.
 */
export const batchError = (error) => {
    const status = error?.response?.status
    let kind = ERRORS[error?.response?.data?.error_code]
    if (!kind) kind = status === 403 ? 'forbidden' : status === 429 ? 'busy' : status >= 502 && status <= 504 ? 'unreachable' : 'failed'
    return { kind }
}

/** Reason codes with a sentence of their own; any other code reads "Không thực hiện được, xem lịch sử". */
const REASONS = new Set([
    'UPDATES_UNAVAILABLE',
    'IRREVERSIBLE',
    'NEEDS_NEWER_CORE',
    'REQUIREMENT_CYCLE',
    'INSTALL_NOT_ALLOWED',
    'NO_PERMISSION',
    'WIDGET_UPDATE_DESIGN_IN_USE',
    'WIDGET_AUTHORING_COPY',
    'WORKFLOW_MODIFIED',
    'WORKFLOW_TEMPLATE_NOT_AVAILABLE',
    'MODULE_REQUIRES_UNMET',
])

/** Translation key of the reason an item was left out, skipped or failed. */
export const reasonKey = (code) =>
    REASONS.has(code) ? `text.update_batch.reason.${String(code).toLowerCase()}` : 'text.update_batch.reason.other'
