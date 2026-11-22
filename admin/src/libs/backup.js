/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

const resource = 'backups'

/** Errors are shown translated by the page, so the shared ajax alert (raw server text) stays off. */
const quiet = { silent: true }

/** Driver keys that connect to a cloud account through OAuth instead of a typed secret. */
export const OAUTH_DRIVERS = ['gdrive', 'onedrive']

/** Machine error codes the Backups API (and the backup task) can return; each has a `text.backup.error.*` label. */
export const BACKUP_ERROR_CODES = [
    'BACKUP_ALL_DESTINATIONS_FAILED',
    'BACKUP_ALREADY_RUNNING',
    'BACKUP_BUCKET_REQUIRED',
    'BACKUP_CREDENTIALS_REQUIRED',
    'BACKUP_DESTINATION_DISABLED',
    'BACKUP_DESTINATION_IN_USE',
    'BACKUP_DESTINATION_NOT_FOUND',
    'BACKUP_DRIVER_UNSUPPORTED',
    'BACKUP_DUMP_FAILED',
    'BACKUP_DUMP_INCOMPLETE',
    'BACKUP_ENDPOINT_NOT_HTTPS',
    'BACKUP_GDRIVE_NOT_FOUND',
    'BACKUP_GDRIVE_PATH_INVALID',
    'BACKUP_GDRIVE_READ_FAILED',
    'BACKUP_GDRIVE_RESUME_MISMATCH',
    'BACKUP_GDRIVE_UNSUPPORTED',
    'BACKUP_GDRIVE_UPLOAD_INCOMPLETE',
    'BACKUP_HOST_REQUIRED',
    'BACKUP_JOB_FAILED',
    'BACKUP_KEY_INVALID',
    'BACKUP_LOCAL_ROOT_FORBIDDEN',
    'BACKUP_LOCAL_ROOT_INVALID',
    'BACKUP_LOCAL_ROOT_REQUIRED',
    'BACKUP_NO_DESTINATION',
    'BACKUP_NO_SPACE',
    'BACKUP_OAUTH_CLIENT_MISSING',
    'BACKUP_OAUTH_DENIED',
    'BACKUP_OAUTH_DESTINATION_CHANGED',
    'BACKUP_OAUTH_DRIVER_UNSUPPORTED',
    'BACKUP_OAUTH_EXCHANGE_FAILED',
    'BACKUP_OAUTH_RECONNECT',
    'BACKUP_OAUTH_REFRESH_FAILED',
    'BACKUP_OAUTH_START_FAILED',
    'BACKUP_OAUTH_STATE_INVALID',
    'BACKUP_OAUTH_USER_NOT_ALLOWED',
    'BACKUP_OAUTH_WEBMASTER_ONLY',
    'BACKUP_ONEDRIVE_CONNECTION_FAILED',
    'BACKUP_ONEDRIVE_NOT_FOUND',
    'BACKUP_ONEDRIVE_PATH_INVALID',
    'BACKUP_ONEDRIVE_READ_FAILED',
    'BACKUP_ONEDRIVE_SESSION_INVALID',
    'BACKUP_ONEDRIVE_UNSUPPORTED',
    'BACKUP_ONEDRIVE_UPLOAD_INCOMPLETE',
    'BACKUP_ONEDRIVE_UPLOAD_MISMATCH',
    'BACKUP_PARTIAL',
    'BACKUP_PORT_INVALID',
    'BACKUP_PROBE_DELETE_FAILED',
    'BACKUP_PROBE_READ_MISMATCH',
    'BACKUP_PROBE_WRITE_FAILED',
    'BACKUP_PRUNE_FAILED',
    'BACKUP_PRUNE_LOCKED',
    'BACKUP_PRUNE_NOT_DELETED',
    'BACKUP_QUEUE_SYNC',
    'BACKUP_QUEUE_TIMEOUT_TOO_LOW',
    'BACKUP_REASON_INVALID',
    'BACKUP_REQUIRED_FAILED',
    'BACKUP_RETENTION_NOT_ABOVE_LOCK',
    'BACKUP_RETENTION_TOO_LONG',
    'BACKUP_SFTP_FINGERPRINT_REQUIRED',
    'BACKUP_TABLE_COUNT_MISMATCH',
    'BACKUP_UNEXPECTED',
    'BACKUP_UPLOAD_FAILED',
    'BACKUP_UPLOAD_SIZE_MISMATCH',
    'BACKUP_USERNAME_REQUIRED',
    'VALIDATION_FAILED',
    'WEBMASTER_ONLY',
]

const KNOWN_ERROR_CODES = new Set(BACKUP_ERROR_CODES)

/** Translation key for every error-code segment: the code without its `BACKUP_` prefix, lowercased. */
const ERROR_KEY_PREFIX = 'text.backup.error.'

/**
 * Translation key for a backup error code.
 *
 * Known codes map to `text.backup.error.<code without BACKUP_, lowercased>` (`WEBMASTER_ONLY` and `VALIDATION_FAILED`
 * keep their own name). The provider HTTP families (`BACKUP_GDRIVE_HTTP_<status>`, `BACKUP_ONEDRIVE_HTTP_<status>`)
 * share one key each, and any other code falls back to `text.backup.error.unknown`.
 *
 * @param {string|null|undefined} code Machine error code from the API.
 * @returns {string|null} i18n key, or null when there is no code.
 */
export const backupErrorKey = (code) => {
    if (typeof code !== 'string' || code === '') return null
    if (/^BACKUP_GDRIVE_HTTP_\d+$/.test(code)) return `${ERROR_KEY_PREFIX}gdrive_http`
    if (/^BACKUP_ONEDRIVE_HTTP_\d+$/.test(code)) return `${ERROR_KEY_PREFIX}onedrive_http`
    if (!KNOWN_ERROR_CODES.has(code)) return `${ERROR_KEY_PREFIX}unknown`
    return ERROR_KEY_PREFIX + code.replace(/^BACKUP_/, '').toLowerCase()
}

/**
 * Machine error code of a failed request (`error_code`, or `errorCode` as a fallback).
 *
 * @param {unknown} error Axios error.
 * @returns {string|null} Code, or null when the response carries none.
 */
export const backupErrorCodeOf = (error) => {
    const body = error?.response?.data || {}
    const code = body.error_code || body.errorCode || null
    return typeof code === 'string' && code !== '' ? code : null
}

/**
 * Parse a stored schedule (`daily@HH:MM`) into the `HH:mm` value of a time picker.
 *
 * @param {string|null|undefined} schedule Stored schedule.
 * @returns {string|null} Time, or null when there is no schedule.
 */
export const scheduleToTime = (schedule) => {
    const match = /^daily@([01]\d|2[0-3]):([0-5]\d)$/.exec(schedule || '')
    return match ? `${match[1]}:${match[2]}` : null
}

/**
 * Build the stored schedule from a time picker value.
 *
 * @param {string|null|undefined} time Time as `HH:mm`.
 * @returns {string|null} `daily@HH:MM`, or null for "no schedule".
 */
export const timeToSchedule = (time) => (/^([01]\d|2[0-3]):[0-5]\d$/.test(time || '') ? `daily@${time}` : null)

/**
 * Request body for creating or updating a destination.
 *
 * Config values come from the driver's declared fields only; a blank config text is sent as an empty string so the
 * server rules can reject it. Secrets are sent only when typed: a blank secret is never sent, so on update the
 * server keeps the stored one. On update the driver is not sent (it cannot change).
 *
 * @param {object} form Drawer state: name, driver, config, credentials, retention_days, lock_days, time,
 *   use_for_safety, enabled.
 * @param {object} driverDef Driver entry of the status API: `fields` (config) and `credential_fields`.
 * @param {boolean} editing True when updating an existing destination.
 * @returns {object} JSON body.
 */
export const buildDestinationPayload = (form, driverDef, editing = false) => {
    const config = {}
    for (const [key, def] of Object.entries(driverDef?.fields || {})) {
        const value = form.config?.[key]
        if (def?.type === 'bool') {
            config[key] = Boolean(value)
        } else if (def?.type === 'int') {
            if (value !== null && value !== undefined && value !== '') config[key] = Number(value)
        } else if (typeof value === 'string') {
            config[key] = value.trim()
        } else if (value !== null && value !== undefined) {
            config[key] = value
        }
    }

    const credentials = {}
    for (const key of driverDef?.credential_fields || []) {
        const value = form.credentials?.[key]
        if (typeof value === 'string' && value.trim() !== '') credentials[key] = value
    }

    const payload = {
        name: String(form.name || '').trim(),
        config,
        credentials,
        retention_days: Number(form.retention_days),
        lock_days: Number(form.lock_days || 0),
        schedule: timeToSchedule(form.time),
        use_for_safety: Boolean(form.use_for_safety),
        enabled: Boolean(form.enabled),
    }
    if (!editing) payload.driver = form.driver
    return payload
}

/**
 * Backup status overview: nightly server backup, last app backup per destination, schedules and drivers.
 *
 * @returns {Promise<object>} Status data.
 */
export const getBackupStatus = async () => {
    return await ajax.get(`${resource}/status`, quiet).then((res) => res.data.data || {})
}

/**
 * Destinations (credentials only as `credentials_set` flags).
 *
 * @returns {Promise<object[]>} Destinations.
 */
export const getBackupDestinations = async () => {
    return await ajax.get(`${resource}/destinations`, quiet).then((res) => res.data.data || [])
}

/**
 * Create a destination.
 *
 * @param {object} payload Body from {@link buildDestinationPayload}.
 * @returns {Promise<object>} Response body.
 */
export const createBackupDestination = async (payload) => {
    return await ajax.post(`${resource}/destinations`, payload, quiet).then((res) => res.data || {})
}

/**
 * Update a destination; a blank secret keeps the stored one.
 *
 * @param {string} uuid Destination uuid.
 * @param {object} payload Body from {@link buildDestinationPayload}.
 * @returns {Promise<object>} Response body.
 */
export const updateBackupDestination = async (uuid, payload) => {
    return await ajax.put(`${resource}/destinations/${uuid}`, payload, quiet).then((res) => res.data || {})
}

/**
 * Probe a destination (write, read back, delete) and report whether an object lock is active.
 *
 * @param {string} uuid Destination uuid.
 * @returns {Promise<object>} Probe result: ok, write, read, delete, lock_active, error_code.
 */
export const testBackupDestination = async (uuid) => {
    return await ajax.post(`${resource}/destinations/${uuid}/test`, {}, quiet).then((res) => res.data.data || {})
}

/**
 * Delete a destination (409 BACKUP_DESTINATION_IN_USE while it still holds copies).
 *
 * @param {string} uuid Destination uuid.
 * @returns {Promise<object>} Response body.
 */
export const deleteBackupDestination = async (uuid) => {
    return await ajax.delete(`${resource}/destinations/${uuid}`, quiet).then((res) => res.data || {})
}

/**
 * Backup history, newest first.
 *
 * @param {{page?: number, per_page?: number, task_uuid?: string}} params Query.
 * @returns {Promise<{data: object[], meta: object}>} Page of backups with their copies.
 */
export const getBackups = async (params = {}) => {
    return await ajax.get(resource, { params, ...quiet }).then((res) => res.data.data || { data: [], meta: {} })
}

/**
 * Queue a backup as a background task.
 *
 * @param {string[]} destinations Optional destination uuids; empty means every enabled destination.
 * @returns {Promise<{task_uuid: string, backup_uuid: string|null}>} The queued task.
 */
export const runBackup = async (destinations = []) => {
    const body = destinations.length ? { destinations } : {}
    return await ajax.post(`${resource}/run`, body, quiet).then((res) => res.data.data || {})
}

/**
 * Begin connecting a Google Drive or OneDrive destination.
 *
 * @param {string} uuid Destination uuid.
 * @returns {Promise<string>} Provider authorize URL to open in the browser.
 */
export const startBackupOAuth = async (uuid) => {
    return await ajax
        .post(`${resource}/destinations/${uuid}/oauth/start`, {}, quiet)
        .then((res) => res.data?.data?.authorize_url || '')
}
