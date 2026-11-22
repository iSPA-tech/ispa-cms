import ajax from '@/ajax'

/**
 * Fetch the signed-in person's latest notifications (newest first). Silent: a failed request never raises a toast,
 * the caller shows its own error state.
 *
 * @param {{unread?: boolean, perPage?: number, page?: number, signal?: AbortSignal}} [options] Filters, page and an abort signal.
 * @returns {Promise<{items: Array<object>, unreadCount: number, total: number}>}
 */
export const getNotifications = async ({ unread = false, perPage = 20, page = 1, signal } = {}) => {
    const params = { per_page: perPage }
    if (page > 1) params.page = page
    if (unread) params.unread = 1
    const res = await ajax.get('notifications', { params, silent: true, signal })
    const data = res?.data?.data || {}
    return {
        items: Array.isArray(data.data) ? data.data : [],
        unreadCount: Math.max(0, Number(data.meta?.unread_count) || 0),
        total: Math.max(0, Number(data.meta?.total) || 0),
    }
}

/**
 * Number of unread notifications of the signed-in person (header badge poll).
 *
 * @param {{signal?: AbortSignal}} [options] Abort signal.
 * @returns {Promise<number>}
 */
export const getUnreadCount = async ({ signal } = {}) => {
    const res = await ajax.get('notifications/unread-count', { silent: true, signal })
    return Math.max(0, Number(res?.data?.data?.unread_count) || 0)
}

/**
 * Mark one own notification as read.
 *
 * @param {string} id Notification uuid.
 * @returns {Promise<void>}
 */
export const markNotificationRead = async (id) => {
    await ajax.post(`notifications/${encodeURIComponent(id)}/read`, null, { silent: true })
}

/**
 * Mark every own notification as read.
 *
 * @returns {Promise<void>}
 */
export const markAllNotificationsRead = async () => {
    await ajax.post('notifications/read-all', null, { silent: true })
}
