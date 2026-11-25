/**
 * API calls and rules of the "Email templates" screen (Settings > General): the managed e-mail templates of the site
 * (`email_templates`), their mail class (system / business) and a preview filled with sample data.
 *
 * Requests are sent with `silent: true`: the screen shows its own translated message instead of the raw server text.
 * The rules below are kept free of Vue so they can be tested on their own.
 */

import ajax from '@/ajax'

const quiet = { silent: true }
const BASE = 'email-template'

export const MAIL_CLASSES = ['system', 'business']
export const WORKFLOW_GROUP = 'workflow'
/** Mail frames a template may use (server allowlist `core.mail_templates.layouts`); '' = no frame. */
export const LAYOUTS = [
    { value: 'mail.branded', key: 'branded' },
    { value: 'mail.order.layout', key: 'order' },
    { value: 'journal::mail.layout', key: 'journal' },
]
export const PER_PAGE = 100

/** One page of templates: `{ rows, total, page }`. Empty filters are not sent. */
export const fetchEmailTemplates = async ({ group = '', mailClass = '', page = 1 } = {}) => {
    const params = { limit: PER_PAGE, page }
    if (group) params.group = group
    if (mailClass) params.mail_class = mailClass
    const body = (await ajax.get(BASE, { params, ...quiet })).data || {}
    const rows = Array.isArray(body.data) ? body.data : Array.isArray(body.data?.data) ? body.data.data : []
    const meta = body.meta || body.data?.meta || {}
    return { rows, total: Number(meta.total ?? body.data?.total ?? rows.length), page: Number(meta.current_page ?? body.data?.current_page ?? page) }
}

/** One template with its language and `variable_list` (`[{ key, description, sample, declared, builtin }]`). */
export const fetchEmailTemplate = async (id) => (await ajax.get(`${BASE}/${id}`, quiet)).data?.data || null

/** Save the sent fields only (the server changes nothing else). */
export const saveEmailTemplate = async (id, fields) => (await ajax.put(`${BASE}/${id}`, fields, quiet)).data || {}

/** A preview of unsaved edits filled with sample data: `{ subject, body, html, variables }`. Never saves. */
export const previewEmailTemplate = async (id, payload) => (await ajax.post(`${BASE}/${id}/preview`, payload, quiet)).data?.data || {}

// ---------------------------------------------------------------------------------------------------------------------
// Rules
// ---------------------------------------------------------------------------------------------------------------------

const UNKNOWN_LANGUAGE = '__none__'

/**
 * Rows as the list shows them: sections by group, inside each by language, templates by key (so the language versions of one template line up).
 * `[{ group, count, languages: [{ id, name, rows }] }]`; a row without a language comes last (`name` null).
 */
export const groupTemplates = (rows = []) => {
    const groups = new Map()
    rows.forEach((row) => {
        const group = String(row?.group || '')
        if (!groups.has(group)) groups.set(group, new Map())
        const languageId = row?.language_id ?? UNKNOWN_LANGUAGE
        const languages = groups.get(group)
        if (!languages.has(languageId)) languages.set(languageId, { id: row?.language_id ?? null, name: row?.language?.name || null, rows: [] })
        languages.get(languageId).rows.push(row)
    })
    return [...groups.entries()]
        .sort(([a], [b]) => (a === WORKFLOW_GROUP ? -1 : b === WORKFLOW_GROUP ? 1 : a.localeCompare(b)))
        .map(([group, languages]) => {
            const list = [...languages.values()]
                .sort((a, b) => (a.id === null ? 1 : b.id === null ? -1 : a.id - b.id))
                .map((language) => ({ ...language, rows: [...language.rows].sort((a, b) => String(a.key_name || '').localeCompare(String(b.key_name || ''))) }))
            return { group, count: list.reduce((sum, language) => sum + language.rows.length, 0), languages: list }
        })
}

/** `order_status` → "Order status": the name of a group the screen has no wording for. */
export const humanizeGroup = (group) => {
    const words = String(group || '').replace(/[_.-]+/g, ' ').trim()
    return words ? words.charAt(0).toUpperCase() + words.slice(1) : ''
}

/** The fields a save sends: only what the operator changed on the form (the layout only where the form offers it). */
export const changedFields = (original = {}, form = {}, { withLayout = false } = {}) => {
    const names = ['name', 'content', 'mail_class', ...(withLayout ? ['blade_layout'] : [])]
    return Object.fromEntries(names.filter((name) => (form[name] ?? '') !== (original[name] ?? '')).map((name) => [name, form[name] ?? '']))
}

/** Sample values the preview starts with: `{ key: sample }` of every variable the server does not fill itself. */
export const sampleValues = (variables = []) => Object.fromEntries((variables || []).filter((row) => row?.key && !row.builtin).map((row) => [row.key, row.sample ?? '']))

/**
 * What a refused request says, as keys of this screen: `{ fields: { name: message }, code }`. `code` is `forbidden`
 * (no permission), `mail_class` / `blade_layout` (a value the server does not accept), `invalid` (other fields) or ''.
 */
export const refusal = (error) => {
    const status = error?.response?.status
    const raw = error?.response?.data?.errors
    const fields = raw && typeof raw === 'object' && !Array.isArray(raw) ? Object.fromEntries(Object.entries(raw).map(([key, value]) => [key, Array.isArray(value) ? String(value[0]) : String(value)])) : {}
    let code = ''
    if (status === 403) code = error?.response?.data?.error_code === 'mail_class_system_webmaster_only' ? 'system_webmaster_only' : 'forbidden'
    else if (fields.mail_class) code = 'mail_class'
    else if (fields.blade_layout) code = 'blade_layout'
    else if (Object.keys(fields).length) code = 'invalid'
    return { fields, code, message: typeof error?.response?.data?.message === 'string' ? error.response.data.message : '' }
}
