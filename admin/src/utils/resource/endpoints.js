/**
 * Replace `:param` segments in an endpoint path with URL-encoded values from a record.
 *
 * @param {string} path Path such as `items/:uuid/lock`.
 * @param {object} record Source of the param values.
 * @returns {string}
 * @throws {Error} When a param is missing, null or an empty string in the record.
 */
export function resolvePath(path, record) {
    return String(path).replace(/:([A-Za-z_][A-Za-z0-9_]*)/g, (_, param) => {
        const value = record?.[param]
        if (value === undefined || value === null || value === '') {
            throw new Error(`Missing value for path param ":${param}" in "${path}".`)
        }
        return encodeURIComponent(value)
    })
}

/**
 * Convert an absolute admin API path from a resource schema (`/api/v2/admin/helpdesk/items`) into a path relative to
 * the admin ajax instance base (`helpdesk/items`). Paths without the admin prefix only lose their leading slash.
 *
 * @param {string} url Endpoint URL from a schema.
 * @returns {string}
 */
export function toAjaxPath(url) {
    return String(url ?? '').replace(/^\/?api\/v\d+\/admin\//, '').replace(/^\/+/, '')
}
