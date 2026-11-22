/**
 * Data-scope API helpers (dimensions, node search, role and user scope).
 *
 * Every call lives under `data-scope/` of the v2 admin API and needs the `data_scope.manage` permission.
 * Nodes are identified by their stable `key`, never by id.
 */

import ajax from '@/ajax'

/**
 * Unwrap the `{ data }` envelope of an API response.
 *
 * @param {{ data?: { data?: unknown } }} res Axios response.
 * @param {unknown} fallback Value when the body has no data.
 * @returns {unknown}
 */
const unwrap = (res, fallback) => res?.data?.data ?? fallback

/**
 * Enabled scope dimensions.
 *
 * @returns {Promise<Array<{ name: string, label: string, module: string, hierarchy: string|null }>>}
 */
export const getScopeDimensions = async () => unwrap(await ajax.get('/data-scope/dimensions'), [])

/**
 * Search the nodes of a dimension by label (the API returns at most 20).
 *
 * @param {string} dimension Dimension name.
 * @param {string} q Search text.
 * @returns {Promise<Array<{ key: string, label: string, trail: string[] }>>}
 */
export const searchScopeNodes = async (dimension, q = '') =>
    unwrap(await ajax.get(`/data-scope/dimensions/${encodeURIComponent(dimension)}/nodes`, { params: { q } }), [])

/**
 * Scoped permissions and node assignments of a role.
 *
 * @param {number|string} roleId Role id.
 * @returns {Promise<{ grants: Array<object>, assignments: Array<object> }>}
 */
export const getRoleDataScope = async (roleId) =>
    unwrap(await ajax.get(`/data-scope/roles/${roleId}`), { grants: [], assignments: [] })

/**
 * Replace the grant levels and node assignments of a role.
 *
 * @param {number|string} roleId Role id.
 * @param {{ grants: Array<{ permission_key: string, level: string }>, assignments: Array<{ dimension: string, node_key: string, expansion: string }> }} payload Replacement body.
 * @returns {Promise<{ grants: Array<object>, assignments: Array<object> }>}
 */
export const updateRoleDataScope = async (roleId, payload) =>
    unwrap(await ajax.put(`/data-scope/roles/${roleId}`, payload), { grants: [], assignments: [] })

/**
 * Node assignments and effective scope summary of a user.
 *
 * @param {string} uuid User uuid.
 * @returns {Promise<{ assignments: Array<object>, effective: Array<object> }>}
 */
export const getUserDataScope = async (uuid) =>
    unwrap(await ajax.get(`/data-scope/users/${uuid}`), { assignments: [], effective: [] })

/**
 * Replace the node assignments of a user.
 *
 * @param {string} uuid User uuid.
 * @param {{ assignments: Array<{ dimension: string, node_key: string, expansion: string }> }} payload Replacement body.
 * @returns {Promise<{ assignments: Array<object>, effective: Array<object> }>}
 */
export const updateUserDataScope = async (uuid, payload) =>
    unwrap(await ajax.put(`/data-scope/users/${uuid}`, payload), { assignments: [], effective: [] })

/**
 * Pick the side of a bilingual "en | vi" text for a locale (Vietnamese is the second side).
 *
 * @param {string} text Text that may hold both languages joined by " | ".
 * @param {string} locale Active locale code.
 * @returns {string}
 */
const pickSide = (text, locale) => {
    const parts = String(text).split(' | ')
    if (parts.length < 2) return text
    return String(locale).startsWith('vi') ? parts[parts.length - 1] : parts[0]
}

/**
 * Display label of a scope dimension or permission in the viewer locale.
 *
 * Translates `label_key` through the passed `t` (this module stays i18n-free); when the key has no translation
 * (`t` returns the key) it falls back to `label`, then `title`, then `name`, never to the raw key while another
 * value exists and never to a bilingual "en | vi" text.
 *
 * @param {{ label_key?: string|null, label?: string, title?: string, name?: string, permission_key?: string }} item Dimension or permission payload.
 * @param {(key: string) => string} t Translate function of the caller.
 * @param {string} [locale] Active locale, used to pick one side of a bilingual fallback.
 * @returns {string}
 */
export const scopeLabel = (item, t, locale = 'vi') => {
    if (!item) return ''
    const key = item.label_key
    if (key) {
        const translated = t(key)
        if (translated && translated !== key) return translated
    }
    const fallback = [item.label, item.title, item.name, item.permission_key].find((v) => typeof v === 'string' && v !== '' && v !== key)
    return fallback !== undefined ? pickSide(fallback, locale) : key || ''
}
