/**
 * API calls of the workflow package page (`workflow/packages`) and the lists its pickers need.
 *
 * Requests are sent with `silent: true`: the page shows its own translated message instead of the raw server text.
 * The package always travels as the chosen file, byte for byte: a JSON body would pass through the request
 * middleware (trimmed strings, empty strings turned to null) and no longer match the package checksum.
 */

import ajax from '@/ajax'
import { filenameFrom } from './config-package-download'

const resource = 'workflow/packages'
const quiet = { silent: true }

/** Form body shared by preview and install. */
function packageForm(file, { bindings = {}, asSlug = '', asName = '', blankSample = false } = {}) {
    const form = new FormData()
    form.append('file', file)
    Object.entries(bindings).forEach(([name, value]) => form.append(`bindings[${name}]`, value))
    if (asSlug) form.append('as_slug', asSlug)
    if (asName) form.append('as_name', asName)
    // A package without a sample record gets no test run; a blank record still shows what each step would do.
    if (blankSample) form.append('sample[root][blank_sample]', '1')
    return form
}

const multipart = { ...quiet, headers: { 'Content-Type': 'multipart/form-data' } }

/**
 * Check a package against this site. Nothing is written, sent or spent.
 *
 * @param {File|Blob} file Package file.
 * @param {{bindings?: Record<string, string>, asSlug?: string, blankSample?: boolean}} [options] Operator's choices;
 *     `blankSample` asks for the test run with a blank record (for a package that carries no sample).
 * @returns {Promise<object>} The report.
 */
export const previewWorkflowPackage = async (file, options = {}) =>
    (await ajax.post(`${resource}/preview`, packageForm(file, options), multipart)).data?.data || {}

/**
 * Install a package as a new workflow that is switched off (`asName` names a copy installed under `asSlug`).
 *
 * @returns {Promise<{slug: string, name: string, is_active: boolean}>}
 */
export const installWorkflowPackage = async (file, options = {}) =>
    (await ajax.post(`${resource}/install`, packageForm(file, options), multipart)).data?.data || {}

/**
 * Download the package of a workflow and hand it to the browser as a file (`<slug>.workflow.json`).
 * Rejects with `{ problems }` when the server refuses to share the workflow, or with the request error.
 *
 * @param {string} slug Workflow slug.
 * @returns {Promise<void>}
 */
export async function downloadWorkflowPackage(slug) {
    let res
    try {
        res = await ajax.get(`${resource}/export/${encodeURIComponent(slug)}`, { responseType: 'blob', ...quiet })
    } catch (error) {
        throw { problems: await refusalProblems(error), status: error?.response?.status }
    }
    const header = res.headers?.['content-disposition']
    const url = URL.createObjectURL(res.data)
    const link = document.createElement('a')
    link.href = url
    link.download = header ? filenameFrom(header) : `${slug}.workflow.json`
    link.style.visibility = 'hidden'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
}

/** Problems of a refused export; the error body arrives as a blob because the request asked for one. */
async function refusalProblems(error) {
    try {
        const body = error?.response?.data
        const parsed = typeof body?.text === 'function' ? JSON.parse(await body.text()) : body
        return Array.isArray(parsed?.data?.problems) ? parsed.data.problems : []
    } catch (e) {
        return []
    }
}

/** Credentials of this site: `[{ name, type, description }]`. Rejects when the account may not list them. */
export const fetchCredentials = async () => {
    const rows = (await ajax.get('workflow/credentials', quiet)).data?.data
    return Array.isArray(rows) ? rows : []
}

/** Agent profiles of this site: `[{ slug, name }]`. */
export const fetchAgentProfiles = async () => {
    const body = (await ajax.get('agentic/profiles', { params: { per_page: 100 }, ...quiet })).data || {}
    const rows = Array.isArray(body.data) ? body.data : Array.isArray(body.data?.data) ? body.data.data : []
    return rows.filter((row) => row?.slug).map((row) => ({ slug: row.slug, name: row.name || row.slug }))
}

/** Workflows of this site: `[{ slug, name }]`. */
export const fetchWorkflows = async () => {
    const rows = (await ajax.get('workflows', quiet)).data?.data
    return (Array.isArray(rows) ? rows : []).filter((row) => row?.slug).map((row) => ({ slug: row.slug, name: row.name || row.slug }))
}
