import ajax from '@/ajax'

/**
 * Client of the Core saved views endpoints (`/v2/admin/saved-views`): named filter states of an admin list, per user,
 * optionally shared. Every call is silent: a failed request never raises a toast, the bar shows its own state. Errors
 * keep `response.status` so the caller can tell a 404 (list not accepted) and a 422 (limit reached) from the rest.
 */

/**
 * The caller's views of a list, then shared views of other users (at most 40 rows).
 *
 * @param {string} listKey List key, for example `helpdesk/organizations`.
 * @param {{signal?: AbortSignal}} [options] Abort signal.
 * @returns {Promise<Array<{uuid: string, name: string, state: object, is_shared: boolean, is_mine: boolean, can_manage: boolean}>>}
 */
export const getSavedViews = async (listKey, { signal } = {}) => {
    const res = await ajax.get('saved-views', { params: { list: listKey }, silent: true, signal })
    const body = res?.data || {}

    return Array.isArray(body.data) ? body.data : []
}

/**
 * Save a view for the caller.
 *
 * @param {string} listKey List key.
 * @param {{name: string, state: object, is_shared?: boolean}} payload View name (1-80 characters), filter state, share flag.
 * @returns {Promise<object>} The created item.
 */
export const createSavedView = async (listKey, { name, state, is_shared = false }) => {
    const res = await ajax.post('saved-views', { list: listKey, name, state, is_shared }, { silent: true })

    return res?.data?.data || {}
}

/**
 * Rename a view, replace its state, or flip sharing. Only the fields given are sent.
 *
 * @param {string} uuid View uuid.
 * @param {{name?: string, state?: object, is_shared?: boolean, position?: number}} patch Fields to change.
 * @returns {Promise<object>} The updated item.
 */
export const updateSavedView = async (uuid, patch) => {
    const res = await ajax.put(`saved-views/${encodeURIComponent(uuid)}`, patch, { silent: true })

    return res?.data?.data || {}
}

/**
 * Delete a view.
 *
 * @param {string} uuid View uuid.
 * @returns {Promise<void>}
 */
export const deleteSavedView = async (uuid) => {
    await ajax.delete(`saved-views/${encodeURIComponent(uuid)}`, { silent: true })
}
