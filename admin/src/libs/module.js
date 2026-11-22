/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

const resource = 'utilities/modules'

export const getModules = async () => {
    return await ajax.get(resource).then((res) => res.data.data || [])
}

export const updateModuleStatus = async (moduleName, payload = {}) => {
    return await ajax.put(`${resource}/${moduleName}/status`, payload).then((res) => res.data || {})
}

/** Errors are shown translated by the page, so the shared ajax alert (raw server text) stays off. */
const quiet = { silent: true }

/** Largest module package zip the backend accepts (config `core.module_packages.max_zip_bytes`, 50 MiB). */
export const MODULE_PACKAGE_MAX_BYTES = 50 * 1024 * 1024

/** Largest signature file the backend accepts (2 MiB). */
export const MODULE_SIGNATURE_MAX_BYTES = 2 * 1024 * 1024

/**
 * Machine codes of the install flow (catalog, preflight, install, upload, install job and package checks).
 * Every code has a `text.modules.error.<code without MODULE_, lowercased>` label.
 */
export const MODULE_ERROR_CODES = [
    'MODULE_ADMIN_BUILD_FAILED',
    'MODULE_ADMIN_BUILD_NOT_DEPLOYED',
    'MODULE_ADMIN_BUILD_PENDING',
    'MODULE_ADMIN_PACKAGE_MISSING',
    'MODULE_ALREADY_PRESENT',
    'MODULE_CATALOG_UNAVAILABLE',
    'MODULE_ASSETS_PUBLISH_FAILED',
    'MODULE_CONFIRMATION_REQUIRED',
    'MODULE_CORE_PROTECTED',
    'MODULE_DEPENDENT_PINS_VERSION',
    'MODULE_DOWNGRADE',
    'MODULE_DOWNLOAD_FAILED',
    'MODULE_DOWNLOAD_HOST_REFUSED',
    'MODULE_DOWNLOAD_TOO_LARGE',
    'MODULE_EXTENSION_MISSING',
    'MODULE_EXTRACT_ENTRY_DUPLICATE',
    'MODULE_EXTRACT_ENTRY_PATH',
    'MODULE_EXTRACT_ENTRY_TYPE',
    'MODULE_EXTRACT_FILE_HASH',
    'MODULE_EXTRACT_FILE_SET',
    'MODULE_EXTRACT_FILE_TOO_LARGE',
    'MODULE_EXTRACT_MODULE_JSON',
    'MODULE_EXTRACT_OPEN',
    'MODULE_EXTRACT_RATIO',
    'MODULE_EXTRACT_STAGING',
    'MODULE_EXTRACT_TOO_MANY_FILES',
    'MODULE_EXTRACT_UNPACKED_TOO_LARGE',
    'MODULE_EXTRACT_WRITE',
    'MODULE_EXTRACT_ZIP_HASH',
    'MODULE_EXTRACT_ZIP_SIZE',
    'MODULE_HAS_DATA',
    'MODULE_HAS_DEPENDENTS',
    'MODULE_INSTALLED_VERSION_UNKNOWN',
    'MODULE_INSTALL_ACTIVATION_FAILED',
    'MODULE_INSTALL_ACTIVATOR_MARKER_FAILED',
    'MODULE_INSTALL_COMMAND_FAILED',
    'MODULE_INSTALL_CONFIG_CACHE_FAILED',
    'MODULE_INSTALL_DISABLED',
    'MODULE_INSTALL_ENABLE_FAILED',
    'MODULE_INSTALL_FAILED',
    'MODULE_INSTALL_FORBIDDEN_BY_HUB',
    'MODULE_INSTALL_FORBIDDEN_BY_SERVER',
    'MODULE_INSTALL_HUB_UNAVAILABLE',
    'MODULE_INSTALL_JOB_FAILED',
    'MODULE_INSTALL_MANAGED_BY_SERVER',
    'MODULE_INSTALL_MIGRATE_FAILED',
    'MODULE_INSTALL_NEEDS_DB_STATUS',
    'MODULE_INSTALL_NO_RUNNER',
    'MODULE_INSTALL_NO_RUNTIME_FOLDER',
    'MODULE_INSTALL_NO_TRUSTED_KEY',
    'MODULE_INSTALL_QUEUE_SYNC',
    'MODULE_INSTALL_QUEUE_TIMEOUT',
    'MODULE_INSTALL_RUNNING',
    'MODULE_INSTALL_RUNTIME_FOLDER_FAILED',
    'MODULE_INSTALL_STAGING',
    'MODULE_INSTALL_TICKET_INVALID',
    'MODULE_NAME_CONFLICT',
    'MODULE_NOT_INSTALLED',
    'MODULE_NOT_IN_CATALOG',
    'MODULE_NOT_RUNTIME',
    'MODULE_PACKAGE_ENTRY_TOO_LARGE',
    'MODULE_PACKAGE_ENVELOPE',
    'MODULE_PACKAGE_FORMAT',
    'MODULE_PACKAGE_KEY_INVALID',
    'MODULE_PACKAGE_KEY_MISMATCH',
    'MODULE_PACKAGE_KEY_REVOKED',
    'MODULE_PACKAGE_KEY_UNKNOWN',
    'MODULE_PACKAGE_MISMATCH',
    'MODULE_PACKAGE_SCHEMA',
    'MODULE_PACKAGE_SIGNATURE',
    'MODULE_PACKAGE_SIZE_MISMATCH',
    'MODULE_PENDING_MIGRATIONS',
    'MODULE_PHP_TOO_OLD',
    'MODULE_PLACE_CROSS_DEVICE',
    'MODULE_PLACE_IO',
    'MODULE_PLACE_LINK_EXISTS',
    'MODULE_PLACE_STAGED_INVALID',
    'MODULE_PLACE_TARGET_EXISTS',
    'MODULE_REQUIRES_UNMET',
    'MODULE_REVOKED',
    'MODULE_RUNTIME_INVALID',
    'MODULE_SAME_VERSION',
    'MODULE_SHADOWS_RELEASE',
    'MODULE_TABLE_EXISTS',
    'MODULE_TABLE_NOT_OWNED',
    'MODULE_THEME_COUPLED',
    'MODULE_THIRD_PARTY_CONFIRMATION_REQUIRED',
    'MODULE_UNINSTALL_DISABLE_FAILED',
    'MODULE_UNINSTALL_FAILED',
    'MODULE_UNINSTALL_MIGRATE_FAILED',
    'MODULE_UNINSTALL_NO_TABLES',
    'MODULE_UNINSTALL_REMOVE_FAILED',
    'MODULE_UPGRADE_NOT_INSTALLED',
    'MODULE_UPGRADE_PLACE_FAILED',
    'MODULE_UPLOAD_LIMIT_TOO_LOW',
    'MODULE_UPLOAD_TOO_LARGE',
    'MODULE_WARN_CUSTOM_CODE',
    'MODULE_WARN_IRREVERSIBLE_MIGRATIONS',
    'MODULE_WARN_RESOURCE_ONLY',
    'MODULE_WARN_THIRD_PARTY_FIRST_INSTALL',
    'WEBMASTER_ONLY',
]

const KNOWN_MODULE_ERROR_CODES = new Set(MODULE_ERROR_CODES)

/**
 * Translation key for a module install error or warning code.
 *
 * Known codes map to `text.modules.error.<code without MODULE_, lowercased>` (`WEBMASTER_ONLY` keeps its own name,
 * lowercased), the same convention the Backups page uses. Any other code falls back to `text.modules.error.unknown`.
 *
 * @param {string|null|undefined} code Machine code from the API.
 * @returns {string|null} i18n key, or null when there is no code.
 */
export const moduleErrorKey = (code) => {
    if (typeof code !== 'string' || code === '') return null
    if (!KNOWN_MODULE_ERROR_CODES.has(code)) return 'text.modules.error.unknown'
    return `text.modules.error.${code.replace(/^MODULE_/, '').toLowerCase()}`
}

/**
 * Machine error code of a failed request (`errorCode`, or `error_code` as a fallback).
 *
 * @param {unknown} error Axios error.
 * @returns {string|null} Code, or null when the response carries none.
 */
export const moduleErrorCodeOf = (error) => {
    const body = error?.response?.data || {}
    const code = body.errorCode || body.error_code || null
    return typeof code === 'string' && code !== '' ? code : null
}

/**
 * Preflight codes that block every install on this website (the switch, the server, the hub, or background work),
 * most decisive first. The install drawer shows the first one alone instead of a list.
 */
export const MODULE_SITE_BLOCK_CODES = [
    'MODULE_INSTALL_FORBIDDEN_BY_SERVER',
    'MODULE_INSTALL_FORBIDDEN_BY_HUB',
    'MODULE_INSTALL_DISABLED',
    'MODULE_INSTALL_NO_RUNNER',
    'MODULE_INSTALL_NEEDS_DB_STATUS',
    'MODULE_INSTALL_NO_RUNTIME_FOLDER',
    'MODULE_INSTALL_QUEUE_SYNC',
    'MODULE_INSTALL_QUEUE_TIMEOUT',
    'MODULE_INSTALL_NO_TRUSTED_KEY',
]

/**
 * Whether this website may install modules, and who decides (Webmaster only).
 *
 * @returns {Promise<{enabled: boolean, state: 'allowed'|'disabled'|'forbidden_by_server'|'forbidden_by_hub', setting: boolean, locked: boolean, server: 'on'|'off'|'unset'}>}
 */
export const getModuleInstallSetting = async () => {
    return await ajax.get(`${resource}/install-setting`, quiet).then((res) => res.data.data || {})
}

/**
 * Switch "Cho phép cài module" on or off (Webmaster only). Refused with a machine code when the server or the hub decides.
 *
 * @param {boolean} enabled New value of the switch.
 * @returns {Promise<object>} The same object as `getModuleInstallSetting`.
 */
export const updateModuleInstallSetting = async (enabled) => {
    return await ajax.put(`${resource}/install-setting`, { enabled }, quiet).then((res) => res.data.data || {})
}

/**
 * Modules published on the platform hub with this site's installed state (Webmaster only).
 *
 * @returns {Promise<object[]>} Catalog entries.
 */
export const getModuleCatalog = async () => {
    return await ajax.get(`${resource}/catalog`, quiet).then((res) => res.data.data || [])
}

/**
 * The store page of one module: listing, versions with technical sheets and the release notes above the installed
 * version (Webmaster only). `available` is false when the hub has no store page for it.
 *
 * @param {string} name Module name.
 * @returns {Promise<object>} Listing answer.
 */
export const getModuleListing = async (name) => {
    return await ajax.get(`${resource}/catalog/${encodeURIComponent(name)}/listing`, quiet).then((res) => res.data.data || {})
}

/**
 * One store image from the site's local cache, as a Blob (the request carries the admin token, so it cannot be a plain img src).
 *
 * @param {string} hash Local image hash (64 hex characters) from a listing.
 * @returns {Promise<Blob>} Image bytes.
 */
export const getModuleMedia = async (hash) => {
    return await ajax
        .get(`${resource}/catalog/media/${encodeURIComponent(hash)}`, { ...quiet, responseType: 'blob' })
        .then((res) => res.data)
}

/**
 * What an install of one module version would do and what blocks it (Webmaster only).
 *
 * @param {string} name Module name.
 * @param {string} version Version as `x.y.z`.
 * @returns {Promise<object>} Preflight result: `can_install`, `blocking`, `warnings`, `changes`.
 */
export const getModulePreflight = async (name, version) => {
    return await ajax
        .get(`${resource}/catalog/${encodeURIComponent(name)}/${encodeURIComponent(version)}/preflight`, quiet)
        .then((res) => res.data.data || {})
}

/**
 * Start an install from the hub.
 *
 * @param {{name: string, version: string, confirm_name?: string, confirm_irreversible?: boolean, confirm_third_party?: boolean}} payload Request body.
 * @returns {Promise<object>} Response body (202 with the install record).
 */
export const installModule = async (payload) => {
    return await ajax.post(`${resource}/install`, payload, quiet).then((res) => res.data || {})
}

/**
 * Start an install from an uploaded package and its signature file.
 *
 * @param {FormData} formData Fields `package`, `signature`, optional `confirm_name`, `confirm_irreversible`, `confirm_third_party`.
 * @returns {Promise<object>} Response body (202 with the install record).
 */
export const uploadModulePackage = async (formData) => {
    return await ajax.post(`${resource}/upload`, formData, quiet).then((res) => res.data || {})
}

/**
 * The latest installs, newest first (Webmaster only).
 *
 * @returns {Promise<object[]>} Install records.
 */
export const getModuleInstalls = async () => {
    return await ajax.get(`${resource}/installs`, quiet).then((res) => res.data.data || [])
}

/**
 * What an uninstall of one runtime module would do (Webmaster only).
 *
 * @param {string} name Module name.
 * @returns {Promise<{name: string, version: string|null, enabled: boolean, uninstall_tables: string[], dependents: string[]}>}
 *   Facts: the data tables the module declares (empty: "also delete its data" is not offered) and the enabled
 *   modules that need it (they block the uninstall).
 */
export const getModuleUninstallInfo = async (name) => {
    return await ajax.get(`${resource}/runtime/${encodeURIComponent(name)}/uninstall`, quiet).then((res) => res.data.data || {})
}

/**
 * Start the removal of a runtime module (Webmaster only).
 *
 * @param {string} name Module name.
 * @param {{confirm_name: string, drop_data?: boolean}} payload The typed module name and whether to delete its data tables.
 * @returns {Promise<object>} Response body (202 with the install record, `kind` = `uninstall`).
 */
export const uninstallModule = async (name, payload) => {
    return await ajax.delete(`${resource}/runtime/${encodeURIComponent(name)}`, { ...quiet, data: payload }).then((res) => res.data || {})
}
