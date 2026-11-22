/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

const resource = 'config-packages'

/** Errors are shown translated by the page, so the shared ajax alert (raw server text) stays off. */
const quiet = { silent: true }

/**
 * List the exportable configuration sections.
 * @returns {Promise<object>} Response body.
 */
export const fetchSections = async () => await ajax.get(`${resource}/sections`, quiet).then((res) => res.data || {})

/**
 * Start an export run.
 * @param {{sections: string[], translation_scope?: string}} payload Sections to export and translation scope.
 * @returns {Promise<object>} Response body.
 */
export const startExport = async (payload) =>
    await ajax.post(`${resource}/export`, payload, quiet).then((res) => res.data || {})

/**
 * Relative API path of a finished export's download. Pure: it does not call the API.
 * @param {string} runUuid Export run uuid.
 * @returns {string} Path relative to the API base.
 */
export const downloadUrl = (runUuid) => `${resource}/${runUuid}/download`

/**
 * Upload a package file; the server validates it and returns a dry run.
 * @param {File|Blob} file Package file.
 * @returns {Promise<object>} Response body.
 */
export const uploadPackage = async (file) => {
    const formData = new FormData()
    formData.append('file', file)
    return await ajax
        .post(`${resource}/upload`, formData, { ...quiet, headers: { 'Content-Type': 'multipart/form-data' } })
        .then((res) => res.data || {})
}

/**
 * Fetch one run (export, dry run or apply).
 * @param {string} runUuid Run uuid.
 * @param {object} [params] Query params such as plan paging.
 * @returns {Promise<object>} Response body.
 */
export const fetchRun = async (runUuid, params = {}) =>
    await ajax.get(`${resource}/runs/${runUuid}`, { params, ...quiet }).then((res) => res.data || {})

/**
 * Apply a dry run with the operator's choices.
 * @param {string} runUuid Dry run uuid.
 * @param {object} choices Operator choices keyed by plan item.
 * @returns {Promise<object>} Response body.
 */
export const applyRun = async (runUuid, choices) =>
    await ajax.post(`${resource}/${runUuid}/apply`, { choices }, quiet).then((res) => res.data || {})

/**
 * List the caller's runs (history).
 * @param {object} [params] Paging and filter params.
 * @returns {Promise<object>} Response body.
 */
export const fetchRuns = async (params = {}) =>
    await ajax.get(`${resource}/runs`, { params, ...quiet }).then((res) => res.data || {})
