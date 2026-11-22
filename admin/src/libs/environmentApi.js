/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

const resource = 'environment-settings'

/** Errors are shown translated by the page, so the shared ajax alert (raw server text) stays off. */
const quiet = { silent: true }

/**
 * Path of one setting. Pure: it does not call the API.
 * @param {string} key Setting key such as `email.MAIL_HOST`.
 * @returns {string} Path relative to the API base.
 */
export const itemUrl = (key) => `${resource}/${encodeURIComponent(key)}`

/**
 * List the allowed environment settings. Secret values are never part of the response (`value` is null).
 * @returns {Promise<object>} Response body.
 */
export const fetchEnvironment = async () => await ajax.get(resource, quiet).then((res) => res.data || {})

/**
 * Replace the value of one environment setting.
 * @param {string} key Setting key.
 * @param {string} value New value (not empty; use clearEnvironment to empty it).
 * @returns {Promise<object>} Response body carrying the updated item, without a secret value.
 */
export const saveEnvironment = async (key, value) =>
    await ajax.put(itemUrl(key), { value }, quiet).then((res) => res.data || {})

/**
 * Empty one environment setting.
 * @param {string} key Setting key.
 * @returns {Promise<object>} Response body carrying the cleared item.
 */
export const clearEnvironment = async (key) => await ajax.delete(itemUrl(key), quiet).then((res) => res.data || {})
