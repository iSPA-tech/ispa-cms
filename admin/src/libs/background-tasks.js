import ajax from '@/ajax'

/** Statuses during which a task still needs the fast poll. */
const ACTIVE_STATUSES = ['queued', 'running']

/**
 * Fetch the signed-in person's background tasks (last 24 h plus anything still queued or running, newest first).
 * The request is silent: a failed poll must never raise a toast, callers handle the rejection themselves.
 *
 * @returns {Promise<Array<object>>}
 */
export const getBackgroundTasks = async () => {
    const res = await ajax.get('background-tasks', { silent: true })
    const data = res?.data?.data
    return Array.isArray(data) ? data : []
}

/**
 * Whether a task is still waiting or working.
 *
 * @param {{status?: string}|null|undefined} task Task record from the API.
 * @returns {boolean}
 */
export const isTaskActive = (task) => ACTIVE_STATUSES.includes(task?.status)

/**
 * Find the translation key a module registered for a task type.
 *
 * @param {Array<{type: string, text: string}>} registry Registered task types (`adminBackgroundTaskTypes`).
 * @param {string} type Task type reported by the backend.
 * @returns {string|null} Translation key, or null when no module labelled the type.
 */
export const taskLabelKey = (registry, type) => (registry || []).find((entry) => entry.type === type)?.text || null
