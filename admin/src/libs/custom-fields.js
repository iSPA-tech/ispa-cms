/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

/**
 * Client of the custom fields screens. Every function resolves to the response body (`{ data, meta }`).
 * Failures are shown translated by the screen, so the shared ajax alert (raw server text) stays off (`silent`).
 */
const quiet = { silent: true }

/** Field types the API knows (same values as `CustomFieldType`), in the order a picker lists them. */
export const FIELD_TYPES = ['text', 'textarea', 'integer', 'boolean', 'date', 'select', 'multiselect']

/**
 * Base path of the field routes of one resource.
 * @param {string} module Module slug of the resource.
 * @param {string} resource Resource slug.
 * @returns {string} Path without a trailing slash.
 */
const base = (module, resource) =>
    `resources/${encodeURIComponent(module)}/${encodeURIComponent(resource)}/custom-fields`

/**
 * Resources whose fields the signed-in operator may see.
 * @returns {Promise<{data: Array<{key: string, module: string, resource: string, label: string}>, meta: {can_manage: boolean}}>} Body.
 */
export const fetchCustomFieldResources = async () => (await ajax.get('custom-field-resources', quiet)).data || {}

/**
 * Fields of a resource in display order, archived ones included.
 * @param {{module: string, resource: string}} target Resource.
 * @returns {Promise<object>} Body with `data` (fields) and `meta` (`limit`, `count`, `can_manage`).
 */
export const fetchCustomFields = async ({ module, resource }) =>
    (await ajax.get(`${base(module, resource)}`, quiet)).data || {}

/**
 * Save a new order: one request with every field uuid in the wanted order.
 * @param {{module: string, resource: string}} target Resource.
 * @param {string[]} uuids Field uuids in the new order.
 * @returns {Promise<object>} Body: the refreshed field list.
 */
export const reorderCustomFields = async ({ module, resource }, uuids) =>
    (await ajax.post(`${base(module, resource)}/reorder`, { uuids }, quiet)).data || {}

/**
 * Hide a field; its values stay and it can be restored.
 * @param {{module: string, resource: string}} target Resource.
 * @param {string} uuid Field uuid.
 * @returns {Promise<object>} Body: the field.
 */
export const archiveCustomField = async ({ module, resource }, uuid) =>
    (await ajax.post(`${base(module, resource)}/${encodeURIComponent(uuid)}/archive`, {}, quiet)).data || {}

/**
 * Bring an archived field back.
 * @param {{module: string, resource: string}} target Resource.
 * @param {string} uuid Field uuid.
 * @returns {Promise<object>} Body: the field.
 */
export const restoreCustomField = async ({ module, resource }, uuid) =>
    (await ajax.post(`${base(module, resource)}/${encodeURIComponent(uuid)}/restore`, {}, quiet)).data || {}

/**
 * Text of a field label in the current language, falling back to the other language, then to the key. Pure.
 * @param {{labels?: {vi?: string, en?: string}, key?: string}} field Field as the API presents it.
 * @param {string} locale Current language (`vi` or `en`).
 * @returns {string} Label.
 */
export const fieldLabel = (field, locale) => {
    const labels = field?.labels || {}
    const other = locale === 'en' ? 'vi' : 'en'
    return String(labels[locale] || labels[other] || field?.key || '')
}

/**
 * Move one entry of a list by one step and return the uuids in the new order. Pure; the input is not changed.
 * @param {Array<{uuid: string}>} fields Fields in display order.
 * @param {number} index Position of the entry to move.
 * @param {-1|1} step -1 to move it up, 1 to move it down.
 * @returns {string[]|null} New uuid order, or null when the move would leave the list.
 */
export const movedOrder = (fields, index, step) => {
    const target = index + step
    if (index < 0 || index >= fields.length || target < 0 || target >= fields.length) return null
    const uuids = fields.map((f) => f.uuid)
    ;[uuids[index], uuids[target]] = [uuids[target], uuids[index]]
    return uuids
}

/**
 * Create a field.
 * @param {{module: string, resource: string}} target Resource.
 * @param {object} payload Definition from {@link buildPayload}.
 * @returns {Promise<object>} Body: `{ data: field }`.
 */
export const createCustomField = async ({ module, resource }, payload) =>
    (await ajax.post(base(module, resource), payload, quiet)).data || {}

/**
 * Change a field. Absent keys keep their value on the server.
 * @param {{module: string, resource: string}} target Resource.
 * @param {string} uuid Field uuid.
 * @param {object} payload Definition from {@link buildPayload}.
 * @returns {Promise<object>} Body: `{ data: field }`.
 */
export const updateCustomField = async ({ module, resource }, uuid, payload) =>
    (await ajax.put(`${base(module, resource)}/${encodeURIComponent(uuid)}`, payload, quiet)).data || {}

/**
 * Build the schema field of an unsaved definition (nothing is written).
 * @param {{module: string, resource: string}} target Resource.
 * @param {object} payload Definition from {@link buildPayload}.
 * @returns {Promise<{data: {field: object}}>} Body: the schema field the form will show.
 */
export const previewCustomField = async ({ module, resource }, payload) =>
    (await ajax.post(`${base(module, resource)}/preview`, payload, quiet)).data || {}

/**
 * Number of records that hold an answer for the field.
 * @param {{module: string, resource: string}} target Resource.
 * @param {string} uuid Field uuid.
 * @returns {Promise<{data: {records: number, key: string}}>} Body.
 */
export const fetchCustomFieldUsage = async ({ module, resource }, uuid) =>
    (await ajax.get(`${base(module, resource)}/${encodeURIComponent(uuid)}/usage`, quiet)).data || {}

/**
 * Delete the answers of a field for good and retire the field.
 * @param {{module: string, resource: string}} target Resource.
 * @param {string} uuid Field uuid.
 * @param {string} confirmKey The field key, retyped by the operator.
 * @returns {Promise<{data: {removed_values: number}}>} Body.
 */
export const purgeCustomField = async ({ module, resource }, uuid, confirmKey) =>
    (await ajax.post(`${base(module, resource)}/${encodeURIComponent(uuid)}/purge`, { confirm_key: confirmKey }, quiet)).data || {}

/** "Allowed characters" presets for text fields: preset name => safe pattern body (anchored by the server). */
export const FORMAT_PRESETS = { digits: '[0-9]+', alnum: '[A-Za-z0-9]+' }

/**
 * Field key made from the English name: lower case, no accents, letters, digits and underscores, starts with a
 * letter, 2 to 40 characters. Pure.
 * @param {string} english English name typed by the operator.
 * @returns {string} Key; empty when the name has no letter or digit.
 */
export const keyFromLabel = (english) => {
    let key = String(english || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/đ/gi, 'd')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
    if (key === '') return ''
    if (!/^[a-z]/.test(key)) key = `f_${key}`
    key = key.slice(0, 40).replace(/_+$/, '')
    return key.length < 2 ? `${key}_x`.slice(0, 40) : key
}

/**
 * Value of a select choice made from its English text, unique among `taken`. Pure.
 * @param {string} english English text of the choice.
 * @param {string[]} taken Values already in the list.
 * @returns {string} Value.
 */
export const optionValueFromLabel = (english, taken) => {
    const base = keyFromLabel(english) || 'choice'
    let value = base
    let n = 2
    while (taken.includes(value)) value = `${base}_${n++}`
    return value
}

/**
 * Empty form state for a type. Pure.
 * @param {string} type Field type.
 * @returns {object} Form state.
 */
export const emptyForm = (type = '') => ({
    type,
    labelVi: '',
    labelEn: '',
    helpVi: '',
    helpEn: '',
    isRequired: false,
    showInList: false,
    isFilterable: false,
    isSearchable: false,
    min: null,
    max: null,
    dateMin: '',
    dateMax: '',
    format: '',
    pattern: '',
    options: [],
})

/**
 * Form state of an existing field. Pure.
 * @param {object} field Field as the API presents it.
 * @returns {object} Form state; saved choices are flagged `saved`.
 */
export const formFromField = (field) => {
    const rules = field.rules || {}
    const form = emptyForm(field.type)
    const preset = Object.keys(FORMAT_PRESETS).find((name) => FORMAT_PRESETS[name] === rules.pattern)
    return Object.assign(form, {
        labelVi: field.labels?.vi || '',
        labelEn: field.labels?.en || '',
        helpVi: field.help?.vi || '',
        helpEn: field.help?.en || '',
        isRequired: Boolean(field.is_required),
        showInList: Boolean(field.show_in_list),
        isFilterable: Boolean(field.is_filterable),
        isSearchable: Boolean(field.is_searchable),
        min: rules.min ?? null,
        max: rules.max ?? null,
        dateMin: rules.date_min || '',
        dateMax: rules.date_max || '',
        format: preset || '',
        pattern: rules.pattern && !preset ? rules.pattern : '',
        options: (field.options || []).map((o) => ({ value: o.value, vi: o.labels?.vi || '', en: o.labels?.en || '', saved: true })),
    })
}

/**
 * API payload of a form: only the rules the type allows, options for select types, help only when both languages are
 * filled (a half-filled help is sent as typed so the server says so). Pure.
 * @param {object} form Form state.
 * @param {string} key Field key.
 * @returns {object} Payload for create, update and preview.
 */
export const buildPayload = (form, key) => {
    const rules = {}
    const number = (value) => (value === null || value === undefined || value === '' ? null : Number(value))
    const put = (name, value) => {
        if (value !== null && value !== '' && !Number.isNaN(value)) rules[name] = value
    }
    if (['text', 'textarea', 'integer'].includes(form.type)) {
        put('min', number(form.min))
        put('max', number(form.max))
    }
    if (form.type === 'multiselect') put('max', number(form.max))
    if (form.type === 'date') {
        put('date_min', form.dateMin || null)
        put('date_max', form.dateMax || null)
    }
    if (form.type === 'text') {
        const pattern = String(form.pattern || '').trim() || FORMAT_PRESETS[form.format] || ''
        put('pattern', pattern || null)
    }
    const helpVi = String(form.helpVi || '').trim()
    const helpEn = String(form.helpEn || '').trim()
    const payload = {
        key,
        type: form.type,
        labels: { vi: String(form.labelVi || '').trim(), en: String(form.labelEn || '').trim() },
        help: helpVi || helpEn ? { vi: helpVi, en: helpEn } : null,
        rules,
        is_required: Boolean(form.isRequired),
        show_in_list: Boolean(form.showInList),
        is_filterable: Boolean(form.isFilterable),
        is_searchable: Boolean(form.isSearchable),
    }
    if (['select', 'multiselect'].includes(form.type)) {
        payload.options = form.options.map((o) => ({ value: o.value, labels: { vi: String(o.vi || '').trim(), en: String(o.en || '').trim() } }))
    }
    return payload
}

/**
 * Turn a 422 body into one error code per control, so the screen shows its own plain text under the right box (the
 * server's English sentences are never shown). Pure.
 * @param {{reason?: string, errors?: {definition?: string[]}}|undefined} body Response body of a failed save or preview.
 * @returns {Record<string, boolean>} Set of control codes: `type`, `labels`, `help`, `key`, `duplicate`, `options`,
 *   `optionInUse`, `limits`, `pattern`, `typeLocked`, `tooMany`, `general`.
 */
export const fieldErrorCodes = (body) => {
    const codes = {}
    const reason = body?.reason
    if (reason === 'duplicate_key') codes.duplicate = true
    else if (reason === 'too_many_fields') codes.tooMany = true
    else if (reason === 'type_locked') codes.typeLocked = true
    else if (reason === 'option_in_use') codes.optionInUse = true
    else if (reason !== 'invalid_definition') codes.general = true
    for (const line of body?.errors?.definition || []) {
        const text = String(line)
        if (/^key /.test(text)) codes.key = true
        else if (/^type /.test(text)) codes.type = true
        else if (/^labels /.test(text)) codes.labels = true
        else if (/^help /.test(text)) codes.help = true
        else if (/^pattern/.test(text)) codes.pattern = true
        else if (/^option|^options/.test(text)) codes.options = true
        else if (/^(min|max|date_min|date_max|unknown rule)/.test(text)) codes.limits = true
        else codes.general = true
    }
    return codes
}
