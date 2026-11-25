/**
 * API calls and rules of the workflow template library: sample workflows downloaded from the iSPA hub
 * (`api/v2/admin/workflows/library`, WorkflowLibraryController).
 *
 * Requests are sent with `silent: true`: the page shows its own translated message instead of the raw server text.
 * The rules below are kept free of Vue so they can be tested on their own.
 */

import ajax from '@/ajax'

const quiet = { silent: true }
const base = 'workflows/library'
const path = (key) => `${base}/${encodeURIComponent(key)}`

const list = (value) => (Array.isArray(value) ? value : [])
const strings = (value) => list(value).filter((row) => typeof row === 'string' && row !== '')

/** One library row (list or detail) with every field the page reads present and typed. */
export const shapeTemplate = (row) => {
    const lines = row?.summary_lines || {}
    const installed = row?.installed && typeof row.installed === 'object' ? row.installed : null
    return {
        key: String(row?.key ?? ''),
        publisher: typeof row?.publisher === 'string' ? row.publisher : '',
        name: String(row?.name ?? ''),
        description: typeof row?.description === 'string' ? row.description : '',
        group: typeof row?.group === 'string' ? row.group : '',
        latest_version: Number(row?.latest_version) || 0,
        latest_version_label: String(row?.latest_version_label ?? ''),
        modules: strings(row?.requires?.modules).filter((name) => name.toLowerCase() !== 'core'),
        needs_account: strings(row?.summary?.credential_kinds).length > 0,
        steps: list(row?.summary?.steps).filter((step) => step?.step_code),
        notes: typeof row?.notes === 'string' ? row.notes : '',
        does: strings(lines.does),
        asks: strings(lines.asks),
        may: strings(lines.may),
        tags: list(lines.tags).filter((tag) => tag?.key && tag?.label),
        available: row?.available !== false,
        missing_modules: strings(row?.missing_modules),
        installed,
        update_available: installed !== null && row?.update_available === true,
        signature_verified: row?.signature?.verified === true,
        copy_reason: typeof row?.copy_reason === 'string' ? row.copy_reason : null,
        // The detail says it; a list row is judged from the install itself (not changed on this site + a newer version).
        update_in_place:
            installed !== null &&
            row?.update_available === true &&
            (typeof row?.update_in_place === 'boolean' ? row.update_in_place : installed.modified === false),
    }
}

/** Every template the hub shows this site. `refresh` skips the 10-minute server cache. */
export const fetchLibrary = async (refresh = false) =>
    list((await ajax.get(base, { ...quiet, params: refresh ? { refresh: 1 } : {} })).data?.data)
        .map(shapeTemplate)
        .filter((row) => row.key && row.name)

/** One template from its checked signed bundle: the list fields plus `signature_verified` and `copy_reason`. */
export const fetchTemplate = async (key) => shapeTemplate((await ajax.get(path(key), quiet)).data?.data)

/**
 * Install a template. A first install arrives switched off. A newer version of an install that was not changed on this
 * site updates it in place (draft only, setup answers kept, the running version stays until it is published); a
 * changed install gets the new version as a copy. Resolves with `{ project_id, slug, name, version_label, as_copy,
 * updated_in_place, ... }`.
 */
export const installTemplate = async (key, { asCopy = false } = {}) => (await ajax.post(`${path(key)}/install`, asCopy ? { as_copy: true } : {}, quiet)).data?.data

// ---------------------------------------------------------------------------------------------------------------------
// Rules
// ---------------------------------------------------------------------------------------------------------------------

/** Lower-case text without diacritics, so "nha tho" finds "Nhà thờ". */
export const fold = (value) =>
    String(value ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D')
        .toLowerCase()

/** Groups present in the list, in first-seen order (templates without a group add none). */
export const groupsOf = (items = []) => [...new Set(items.map((item) => item.group).filter(Boolean))]

/** "church-posts" → "Church posts", "BlogPosts" → "Blog Posts": the label when no translation exists. */
export const humanize = (value) => {
    const words = String(value ?? '')
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/[-_]+/g, ' ')
        .trim()
    return words.charAt(0).toUpperCase() + words.slice(1)
}

/** Translation key segment of a module name ("BlogPosts" → "blog-posts"), as the Modules page uses. */
export const moduleKey = (name) =>
    String(name ?? '')
        .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
        .toLowerCase()

/**
 * The cards to show: free-text search over name, description and tags, one group (or '' for all), and "only what
 * works on this site" (templates whose modules are off are hidden).
 */
export const filterTemplates = (items = [], { search = '', group = '', onlyAvailable = true } = {}) => {
    const needle = fold(search).trim()
    return items.filter((item) => {
        if (onlyAvailable && !item.available) return false
        if (group && item.group !== group) return false
        if (!needle) return true
        return fold([item.name, item.description, ...item.tags.map((tag) => tag.label)].join(' ')).includes(needle)
    })
}

/** Ant tag colour of a card tag, by what it means (spend, a person decides, shown to the public, leaves the site). */
export const TAG_COLORS = { ai_spend: 'gold', needs_approval: 'purple', publish: 'red', external_call: 'blue' }
export const tagColor = (key) => TAG_COLORS[key] || 'default'

/**
 * What the drawer offers for a template:
 * `unavailable` (modules are off), `install` (first install), `update` (a newer version of an install not changed on
 * this site: updated in place, setup answers kept), `install_copy` (a newer version next to an install changed on this
 * site, which is never touched) or `open` (installed and up to date: go to its setup or the designer).
 */
export const mainAction = (item) => {
    if (!item) return null
    if (!item.available) return 'unavailable'
    if (!item.installed) return 'install'
    if (!item.update_available) return 'open'
    return item.update_in_place ? 'update' : 'install_copy'
}

/** Why a newer version installs as a copy (`modified`: changed on this site); null when it does not. */
export const copyNote = (item) => (mainAction(item) === 'install_copy' ? 'modified' : null)

/** The parts of "From iSPA · new version X · in use Y" that apply: `[{ key, value }]`. */
export const versionParts = (item) => {
    if (!item) return []
    const parts = [{ key: 'from', value: item.publisher || 'iSPA' }]
    if (!item.installed) {
        if (item.latest_version_label) parts.push({ key: 'version', value: item.latest_version_label })
        return parts
    }
    if (item.update_available && item.latest_version_label) parts.push({ key: 'version_new', value: item.latest_version_label })
    if (item.installed.version_label) parts.push({ key: 'version_in_use', value: item.installed.version_label })
    return parts
}

/** Step code → step name of a template, for the sentences of a refused install. */
export const stepNamesOf = (item) => Object.fromEntries(list(item?.steps).map((step) => [String(step.step_code), String(step.name || step.step_code)]))

const KINDS = {
    WORKFLOW_HUB_UNREACHABLE: 'unreachable',
    WORKFLOW_HUB_NOT_FOUND: 'not_found',
    WORKFLOW_BUNDLE_UNTRUSTED: 'untrusted',
    WORKFLOW_TEMPLATE_NOT_AVAILABLE: 'not_available',
    WORKFLOW_TEMPLATE_NOT_INSTALLABLE: 'not_installable',
    WORKFLOW_TEMPLATE_UP_TO_DATE: 'up_to_date',
    WORKFLOW_TEMPLATE_CHANGED: 'changed',
}

/**
 * A failed library request in the page's terms: `{ kind, retry, missingModules, report }`.
 * `kind`: unreachable (also a site that is not connected to the hub: the server answers the same), not_found,
 * untrusted, not_available, not_installable, up_to_date, changed (edited or updated while the update waited),
 * forbidden, busy (too many tries) or failed. `retry` says whether asking
 * again can help.
 */
export const libraryError = (error) => {
    const status = error?.response?.status
    const body = error?.response?.data
    let kind = KINDS[body?.error_code]
    if (!kind) kind = status === 403 ? 'forbidden' : status === 429 ? 'busy' : status === 404 ? 'not_found' : status >= 502 && status <= 504 ? 'unreachable' : 'failed'
    return {
        kind,
        retry: ['unreachable', 'busy', 'failed', 'changed'].includes(kind),
        missingModules: kind === 'not_available' ? strings(body?.data?.missing_modules) : [],
        report: kind === 'not_installable' && body?.data && typeof body.data === 'object' ? body.data : null,
    }
}

/** Every row of an inspector report that stops an install, in reading order. */
export const reportRows = (report) => [...list(report?.problems), ...list(report?.binding_problems), ...list(report?.slug_problems)]
