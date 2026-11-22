import ajax from '@/ajax'

/**
 * Client of the record timeline endpoints of Core (history, internal notes, mentions). Every call is silent: a failed
 * request never raises a toast, the calling widget shows its own error state with a retry.
 */

const base = (type, uuid) => `records/${encodeURIComponent(type)}/${encodeURIComponent(uuid)}`

/**
 * One page of a record's change history, newest first.
 *
 * @param {string} type Record type key, for example `helpdesk.organization`.
 * @param {string} uuid Record uuid.
 * @param {{before?: number|string|null, perPage?: number, signal?: AbortSignal}} [options] Cursor (`meta.next_before` of the previous page), page size and abort signal.
 * @returns {Promise<{items: Array<object>, nextBefore: number|string|null}>}
 */
export const getRecordHistory = async (type, uuid, { before = null, perPage, signal } = {}) => {
    const params = {}
    if (before !== null && before !== undefined && before !== '') params.before = before
    if (perPage) params.per_page = perPage
    const res = await ajax.get(`${base(type, uuid)}/history`, { params, silent: true, signal })
    const body = res?.data || {}
    return { items: Array.isArray(body.data) ? body.data : [], nextBefore: body.meta?.next_before ?? null }
}

/**
 * One page of a record's internal notes, newest first.
 *
 * @param {string} type Record type key.
 * @param {string} uuid Record uuid.
 * @param {{before?: string|null, signal?: AbortSignal}} [options] Cursor (note uuid) and abort signal.
 * @returns {Promise<{items: Array<object>, nextBefore: string|null}>}
 */
export const getRecordNotes = async (type, uuid, { before = null, signal } = {}) => {
    const params = before ? { before } : {}
    const res = await ajax.get(`${base(type, uuid)}/notes`, { params, silent: true, signal })
    const body = res?.data || {}
    return { items: Array.isArray(body.data) ? body.data : [], nextBefore: body.meta?.next_before ?? null }
}

/**
 * Add a note to a record.
 *
 * @param {string} type Record type key.
 * @param {string} uuid Record uuid.
 * @param {{body: string, mentions?: string[]}} payload Note text and mentioned user uuids.
 * @returns {Promise<{note: object, ignored: number}>} The stored note and how many mentions were dropped.
 */
export const addRecordNote = async (type, uuid, { body, mentions = [] }) => {
    const res = await ajax.post(`${base(type, uuid)}/notes`, { body, mentions }, { silent: true })
    return { note: res?.data?.data || {}, ignored: Number(res?.data?.meta?.ignored) || 0 }
}

/**
 * Edit an own note.
 *
 * @param {string} type Record type key.
 * @param {string} uuid Record uuid.
 * @param {string} noteUuid Note uuid.
 * @param {{body: string, mentions?: string[]}} payload New text and mentioned user uuids.
 * @returns {Promise<{note: object, ignored: number}>}
 */
export const updateRecordNote = async (type, uuid, noteUuid, { body, mentions = [] }) => {
    const res = await ajax.put(`${base(type, uuid)}/notes/${encodeURIComponent(noteUuid)}`, { body, mentions }, { silent: true })
    return { note: res?.data?.data || {}, ignored: Number(res?.data?.meta?.ignored) || 0 }
}

/**
 * Delete a note (the author, or a holder of the record type's manage ability).
 *
 * @param {string} type Record type key.
 * @param {string} uuid Record uuid.
 * @param {string} noteUuid Note uuid.
 * @returns {Promise<void>}
 */
export const deleteRecordNote = async (type, uuid, noteUuid) => {
    await ajax.delete(`${base(type, uuid)}/notes/${encodeURIComponent(noteUuid)}`, { silent: true })
}

/**
 * People who may be mentioned on a record (at most 10, search text of at least 2 characters).
 *
 * @param {string} type Record type key.
 * @param {string} uuid Record uuid.
 * @param {string} q Search text.
 * @param {{signal?: AbortSignal}} [options] Abort signal.
 * @returns {Promise<Array<{uuid: string, name: string}>>}
 */
export const searchMentionable = async (type, uuid, q, { signal } = {}) => {
    const res = await ajax.get(`${base(type, uuid)}/mentionable`, { params: { q }, silent: true, signal })
    return Array.isArray(res?.data?.data) ? res.data.data : []
}
