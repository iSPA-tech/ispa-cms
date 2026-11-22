import ajax from '@/ajax'

/**
 * Fetch the signed-in person's task inbox (groups of work waiting for them).
 * The request is silent: a failed poll must never raise a toast, callers handle the rejection themselves.
 *
 * @returns {Promise<{items: Array<object>, total: number}>}
 */
export const getMyTasks = async () => {
    const res = await ajax.get('my-tasks', { silent: true })
    const data = res?.data?.data || {}
    return { items: Array.isArray(data.items) ? data.items : [], total: Number(data.total) || 0 }
}
