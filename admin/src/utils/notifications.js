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
