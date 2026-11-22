/**
 * Shared admin status tag colour map: one colour per meaning across every table
 * (green = good, gold = waiting, orange = warning, red = problem).
 */
export const STATUS_COLORS = {
    active: 'green',
    completed: 'green',
    sent: 'green',
    pending: 'gold',
    expiring: 'orange',
    expired: 'red',
    failed: 'red',
    suspended: 'purple',
    refunded: 'geekblue',
    inactive: 'default',
    revoked: 'default',
}

/**
 * Shared admin status tag colour lookup; unknown statuses fall back to 'default'.
 *
 * @param {string} status Backend status value.
 * @returns {string} Ant Design tag colour.
 */
export const statusColor = (status) => STATUS_COLORS[status] || 'default'
