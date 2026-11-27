/**
 * Resolve where a notification row leads, through the registry entry a module declared for its type
 * (`notifications: [{ type, open(payload) }]`). Never throws: an unknown type, a missing `open` or an `open`
 * that throws or returns nothing gives `{ location: null }`, and the row is shown as plain text.
 *
 * @param {{type?: string, payload?: object}|null|undefined} row Notification row from the API.
 * @param {Array<{type: string, open?: Function}>} types Registered notification types (`adminNotificationTypes`).
 * @returns {{location: (object|string|null)}}
 */
export const resolveNotificationTarget = (row, types) => {
    const entry = (types || []).find((item) => item?.type === row?.type)
    if (!entry || typeof entry.open !== 'function') return { location: null }
    try {
        const location = entry.open(row.payload || {})
        return { location: location || null }
    } catch (error) {
        return { location: null }
    }
}

/**
 * The detail line under a notification row's label: the registry entry's `detail(payload, t)` when it declares one,
 * else the payload's own `message` (a workflow notice). Plain text, rendered escaped, at most 300 characters. Never
 * throws: '' on any doubt, and the row shows its label only.
 *
 * @param {{type?: string, payload?: object}|null|undefined} row Notification row from the API.
 * @param {Array<{type: string, detail?: Function}>} types Registered notification types (`adminNotificationTypes`).
 * @param {Function} t vue-i18n translate function, handed to `detail`.
 * @returns {string}
 */
export const resolveNotificationDetail = (row, types, t) => {
    const entry = (types || []).find((item) => item?.type === row?.type)
    const payload = row?.payload && typeof row.payload === 'object' ? row.payload : {}
    try {
        const text = typeof entry?.detail === 'function' ? entry.detail(payload, t) : payload.message
        return typeof text === 'string' ? text.trim().slice(0, 300) : ''
    } catch (error) {
        return ''
    }
}

/** Window event a screen fires after it changed read marks, so the header bell refreshes its count at once. */
export const NOTIFICATIONS_CHANGED_EVENT = 'ispa:notifications-changed'

/**
 * Tell the header bell that read marks changed somewhere else (the notifications page). Safe without a window.
 *
 * @returns {void}
 */
export const announceNotificationsChanged = () => {
    if (typeof window === 'undefined') return
    window.dispatchEvent(new Event(NOTIFICATIONS_CHANGED_EVENT))
}
