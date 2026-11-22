/**
 * Pure helpers for the configuration package import tab: grouping, counting, capping and safe display of plan items.
 * No i18n and no framework imports: the helpers return keys and data, the components translate them.
 */

/** Plan item actions, in display order. */
export const ACTIONS = ['create', 'update', 'unchanged', 'blocked', 'skipped']

/** Marker returned for a value that must not be shown. */
export const MASKED = '••••••'

/** Marker returned for an empty (null or undefined) value. */
export const EMPTY = '—'

/** Default maximum length of a displayed value. */
export const MAX_VALUE_LENGTH = 200

const SECRET_WORDS = /(password|passwd|secret|token|api[_-]?key|private|credential)/i
const SECRET_SUFFIX = /(^|[._-])key$/i

/**
 * Whether a key or key path looks like it names a secret.
 *
 * @param {string} key Item key or dotted path.
 * @returns {boolean}
 */
export function isSecretLike(key) {
    const text = String(key ?? '')
    return SECRET_WORDS.test(text) || SECRET_SUFFIX.test(text)
}

/**
 * Group plan items by their action; every known action is present in the result.
 *
 * @param {Array<{action?: string}>} items Plan items.
 * @returns {Record<string, Array<object>>}
 */
export function groupItemsByStatus(items) {
    const groups = Object.fromEntries(ACTIONS.map((action) => [action, []]))
    for (const item of Array.isArray(items) ? items : []) {
        const action = ACTIONS.includes(item?.action) ? item.action : 'unchanged'
        groups[action].push(item)
    }
    return groups
}

/**
 * Count plan items per action.
 *
 * @param {Array<{action?: string}>} items Plan items.
 * @returns {Record<string, number>}
 */
export function countByStatus(items) {
    const groups = groupItemsByStatus(items)
    return Object.fromEntries(ACTIONS.map((action) => [action, groups[action].length]))
}

/**
 * Summary rows from the plan overview: one row per section.
 *
 * @param {Record<string, {status?: string, counts?: object, total?: number, more?: number}>} sections Plan overview.
 * @returns {Array<{section: string, status: string, counts: Record<string, number>, total: number, more: number}>}
 */
export function summaryRows(sections) {
    return Object.entries(sections || {}).map(([section, value]) => ({
        section,
        status: value?.status || 'no_changes',
        counts: Object.fromEntries(ACTIONS.map((action) => [action, Number(value?.counts?.[action]) || 0])),
        total: Number(value?.total) || 0,
        more: Number(value?.more) || 0,
    }))
}

/**
 * Cap a list.
 *
 * @param {Array<object>} items Items.
 * @param {number} cap Maximum number of shown items.
 * @returns {{shown: Array<object>, more: number}}
 */
export function capItems(items, cap) {
    const list = Array.isArray(items) ? items : []
    const limit = Math.max(0, Number(cap) || 0)
    return { shown: list.slice(0, limit), more: Math.max(0, list.length - limit) }
}

/**
 * Readable text of a value without truncation: arrays as items joined by ", ", objects as "key: value" pairs joined by
 * "; ". Nested arrays and objects are wrapped in parentheses. Values under secret-like keys are masked.
 *
 * @param {*} value Any value.
 * @param {boolean} [nested] Whether the value sits inside another array or object.
 * @returns {string}
 */
function readable(value, nested = false) {
    if (value === null || value === undefined) return EMPTY
    if (Array.isArray(value)) {
        if (!value.length) return EMPTY
        const text = value.map((item) => readable(item, true)).join(', ')
        return nested ? `(${text})` : text
    }
    if (typeof value === 'object') {
        const entries = Object.entries(value)
        if (!entries.length) return EMPTY
        const text = entries.map(([key, item]) => `${key}: ${isSecretLike(key) && item != null ? MASKED : readable(item, true)}`).join('; ')
        return nested ? `(${text})` : text
    }
    return String(value)
}

/**
 * Display string of a value: scalars as text, empty as a dash marker, arrays and objects as readable text (never
 * JSON); long results are truncated with an ellipsis.
 *
 * @param {*} value Any value.
 * @param {number} [max] Maximum length.
 * @returns {string}
 */
export function formatValue(value, max = MAX_VALUE_LENGTH) {
    const text = readable(value)
    return text.length > max ? `${text.slice(0, Math.max(0, max - 1))}…` : text
}

/**
 * Before/after display pair of an item. Values of secret-like keys are masked and never formatted.
 *
 * @param {*} before Value before.
 * @param {*} after Value after.
 * @param {string} [key] Item key (used for the secret check).
 * @returns {{before: string, after: string, changed: boolean}}
 */
export function formatPair(before, after, key = '') {
    const changed = JSON.stringify(before ?? null) !== JSON.stringify(after ?? null)
    if (isSecretLike(key)) {
        const mask = (value) => (value === null || value === undefined ? EMPTY : MASKED)
        return { before: mask(before), after: mask(after), changed }
    }
    return { before: formatValue(before), after: formatValue(after), changed }
}

/**
 * Required secrets of a plan as two key lists plus the key => label map the server resolved from the settings table.
 *
 * @param {{already_set_here?: string[], missing_here?: string[], labels?: Object<string, string>}|null|undefined} requiredSecrets Plan block.
 * @returns {{alreadySet: string[], missing: string[], labels: Object<string, string>}}
 */
export function missingSecretsList(requiredSecrets) {
    const clean = (list) => (Array.isArray(list) ? list.map(String) : [])
    const raw = requiredSecrets?.labels
    const labels = {}
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
        for (const [key, value] of Object.entries(raw)) if (typeof value === 'string' && value.trim() !== '') labels[key] = value
    }
    return { alreadySet: clean(requiredSecrets?.already_set_here), missing: clean(requiredSecrets?.missing_here), labels }
}

/**
 * Operator-facing name of a required secret: the label from the settings table, the key only when there is none.
 *
 * @param {Object<string, string>|undefined} labels Map from missingSecretsList().
 * @param {string} key Setting key.
 * @returns {string}
 */
export function secretLabel(labels, key) {
    return labels?.[key] || key
}

/**
 * Known server messages (plan warnings and item reasons, English) as data: each entry has a pattern with optional
 * named groups, the translation key suffix and which params are counts. The components translate; nothing here
 * imports i18n.
 */
export const MESSAGE_TABLE = [
    // Plan and section warnings.
    { re: /^Section (?<section>\S+) is not supported by this site and was skipped\.$/, key: 'warning_section_unsupported' },
    { re: /^Section is not supported by this site\.$/, key: 'warning_section_unsupported_short' },
    { re: /^(?<section>[^:\s]+): (?<count>\d+) item\(s\) are blocked and will not be applied\.$/, key: 'warning_blocked', plural: true },
    { re: /^(?<count>\d+) item\(s\) are blocked and will not be applied\.$/, key: 'warning_blocked_short', plural: true },
    { re: /^permission (?<permission>.+) does not exist on this site; skipped$/, key: 'warning_permission_missing' },
    // Settings, tables, translations.
    { re: /^row does not exist on this site; enable the module or run seeders$/, key: 'reason_missing_row' },
    { re: /^media$/, key: 'reason_media' },
    { re: /^env$/, key: 'reason_env' },
    { re: /^non_public$/, key: 'reason_non_public' },
    { re: /^(secret|contains_secret)$/, key: 'reason_secret' },
    { re: /^language does not match the row on this site$/, key: 'reason_language_mismatch' },
    { re: /^table is not registered for configuration export$/, key: 'reason_table_unregistered' },
    { re: /^row has no value for natural key column (?<column>.+)$/, key: 'reason_key_missing' },
    { re: /^row carries unknown key columns$/, key: 'reason_unknown_keys' },
    { re: /^column is not part of the exported set: (?<column>.+)$/, key: 'reason_column_not_exported' },
    { re: /^language does not exist on this site$/, key: 'reason_language_missing' },
    { re: /^row is soft deleted on this site$/, key: 'reason_soft_deleted' },
    { re: /^name is empty or invalid$/, key: 'reason_name_invalid' },
    { re: /^name is longer than (?<max>\d+) characters$/, key: 'reason_name_too_long' },
    { re: /^text must be a non-empty object keyed by language$/, key: 'reason_text_invalid' },
    { re: /^soft-deleted row with the same name will be restored$/, key: 'reason_restore' },
    // Menus.
    { re: /^menu group key or name is missing$/, key: 'reason_menu_key_missing' },
    { re: /^ambiguous: the package lists this menu more than once$/, key: 'reason_menu_duplicate' },
    { re: /^menu status must be text$/, key: 'reason_menu_status_text' },
    { re: /^status (?<status>.+) does not exist on this site$/, key: 'reason_status_missing' },
    { re: /^language (?<language>.+) does not exist on this site$/, key: 'reason_language_path_missing' },
    { re: /^menu items are malformed or an item has no name$/, key: 'reason_menu_items_malformed' },
    { re: /^ambiguous: this menu matches (?<count>\d+) menus on this site$/, key: 'reason_menu_ambiguous' },
    { re: /^parent_skipped$/, key: 'reason_parent_skipped' },
    { re: /^points_at_record$/, key: 'reason_points_at_record' },
    // Roles.
    { re: /^role name is missing$/, key: 'reason_role_name_missing' },
    { re: /^the Webmaster role is never changed by an import$/, key: 'reason_role_webmaster' },
    { re: /^role custom data is not an object$/, key: 'reason_role_custom_invalid' },
    { re: /^role level must be a whole number$/, key: 'reason_role_level_int' },
    { re: /^level 0 is reserved for the Webmaster role$/, key: 'reason_role_level_zero' },
    { re: /^the importing user is unknown$/, key: 'reason_role_user_unknown' },
    { re: /^role level is more privileged than your own$/, key: 'reason_role_level_high' },
    { re: /^ambiguous: the package lists this role name more than once$/, key: 'reason_role_duplicate' },
    { re: /^ambiguous: this name matches (?<count>\d+) roles on this site$/, key: 'reason_role_ambiguous' },
    { re: /^the role on this site is more privileged than your own$/, key: 'reason_role_target_high' },
    // Workflows.
    { re: /^workflow slug is missing$/, key: 'reason_workflow_slug_missing' },
    { re: /^ambiguous: the package lists this slug more than once$/, key: 'reason_workflow_duplicate' },
    { re: /^name and target_entity_type are required$/, key: 'reason_workflow_fields' },
    { re: /^trigger_event, description or tags have the wrong type$/, key: 'reason_workflow_types' },
    { re: /^steps and connections must be lists$/, key: 'reason_workflow_lists' },
    { re: /^a connection points at a step code that is not in this workflow$/, key: 'reason_workflow_connection_step' },
    { re: /^a connection condition must be an object$/, key: 'reason_workflow_connection_condition' },
    { re: /^a step has no step_code$/, key: 'reason_step_code_missing' },
    { re: /^duplicate step_code (?<code>.+)$/, key: 'reason_step_code_duplicate' },
    { re: /^step (?<code>.+) has an unknown step_type$/, key: 'reason_step_type_unknown' },
    { re: /^step (?<code>.+) has a field of the wrong type$/, key: 'reason_step_field_type' },
    { re: /^a deleted workflow with this slug exists on this site$/, key: 'reason_workflow_deleted' },
    // Choices.
    { re: /^(deselected|not chosen)$/, key: 'reason_not_chosen' },
    { re: /^graph replace not chosen$/, key: 'reason_graph_not_chosen' },
]

/**
 * Match a server message against the known table.
 *
 * @param {string|null|undefined} text Server message (English).
 * @returns {{key: string, params: Record<string, string|number>, plural: boolean}|null} Key suffix under
 *   `text.config_package.` with params, or null for an unknown message (callers show the raw text).
 */
export function matchMessage(text) {
    const source = String(text ?? '').trim()
    if (!source) return null
    for (const entry of MESSAGE_TABLE) {
        const found = entry.re.exec(source)
        if (!found) continue
        const params = { ...(found.groups || {}) }
        if (params.count !== undefined) params.count = Number(params.count)
        return { key: entry.key, params, plural: !!entry.plural }
    }
    return null
}

/** Marker key that skips a whole section on the server: `{ section: { '*': false } }`. */
export const SECTION_SKIP_KEY = '*'

/**
 * Default choice of every section of a plan overview: apply, except for sections this site cannot compare and
 * sections where every item is blocked (nothing there could be applied).
 *
 * @param {Record<string, {status?: string, counts?: object, total?: number}>} sections Plan overview.
 * @returns {Record<string, 'apply'|'skip'>}
 */
export function defaultSectionChoices(sections) {
    return Object.fromEntries(
        summaryRows(sections).map((row) => {
            const allBlocked = row.total > 0 && row.counts.blocked >= row.total
            return [row.section, row.status === 'skipped' || allBlocked ? 'skip' : 'apply']
        }),
    )
}

/**
 * Choices payload of the apply request: a skipped section gets the section-level marker and `false` for every item
 * key the operator has seen; an applied section sends nothing (a missing choice means apply).
 *
 * @param {Record<string, 'apply'|'skip'>} sectionChoices Operator choice per section.
 * @param {Record<string, string[]>} itemKeys Item keys known per section.
 * @returns {Record<string, Record<string, boolean>>}
 */
export function buildApplyChoices(sectionChoices, itemKeys = {}) {
    const choices = {}
    for (const [section, choice] of Object.entries(sectionChoices || {})) {
        if (choice !== 'skip') continue
        choices[section] = { [SECTION_SKIP_KEY]: false, ...Object.fromEntries((itemKeys[section] || []).map((key) => [key, false])) }
    }
    return choices
}

/**
 * How many items the choices apply and how many they leave out (new and changed items only; blocked and same items
 * are never written).
 *
 * @param {Record<string, object>} sections Plan overview.
 * @param {Record<string, 'apply'|'skip'>} sectionChoices Operator choice per section.
 * @returns {{apply: number, skip: number}}
 */
export function applyCounts(sections, sectionChoices) {
    const total = { apply: 0, skip: 0 }
    for (const row of summaryRows(sections)) {
        const changing = row.counts.create + row.counts.update
        if (row.status === 'skipped') continue
        total[sectionChoices?.[row.section] === 'skip' ? 'skip' : 'apply'] += changing
    }
    return total
}

/**
 * Known server messages of a failed apply run (English) as key suffixes under `text.config_package.`.
 */
export const APPLY_ERROR_TABLE = [
    { re: /^The backup before import failed/, key: 'apply_error_backup' },
    { re: /older than 1 hour|changed since the dry run|no longer exists|no longer holds its package|checksum does not match|Only a successful dry run/, key: 'apply_error_stale' },
    { re: /^Only a Webmaster/, key: 'apply_error_forbidden' },
]

/**
 * Key suffix of an apply error message; unknown messages map to the generic one so raw server text is never shown.
 *
 * @param {string|null|undefined} text Server message (English).
 * @returns {string} Key suffix under `text.config_package.`.
 */
export function matchApplyError(text) {
    const source = String(text ?? '')
    return APPLY_ERROR_TABLE.find((entry) => entry.re.test(source))?.key || 'apply_error_failed'
}

/**
 * Result rows of an apply run: one row per section with its outcome counts.
 *
 * @param {{sections?: Record<string, object>}|null|undefined} summary Summary of the apply run.
 * @returns {Array<{section: string, status: string, applied: number, unchanged: number, skipped: number, blocked: number}>}
 */
export function applyResultRows(summary) {
    return Object.entries(summary?.sections || {}).map(([section, value]) => ({
        section,
        status: ['succeeded', 'failed', 'skipped'].includes(value?.status) ? value.status : 'skipped',
        applied: Number(value?.updated) || 0,
        unchanged: Number(value?.unchanged) || 0,
        skipped: Number(value?.skipped) || 0,
        blocked: Number(value?.blocked) || 0,
    }))
}

/**
 * Readable description of a run from the history list: the layout of its result table and its rows.
 *
 * @param {{kind?: string, summary?: object|null}} run Run record from the list API.
 * @returns {{layout: 'export'|'check'|'apply', rows: object[], headline: Record<string, number>}}
 */
export function describeRun(run) {
    const summary = run?.summary || {}
    if (run?.kind === 'apply') {
        const rows = applyResultRows(summary)
        return { layout: 'apply', rows, headline: { applied: rows.reduce((n, r) => n + r.applied, 0), blocked: rows.reduce((n, r) => n + r.blocked, 0) } }
    }
    if (run?.kind === 'dry_run') {
        const rows = summaryRows(summary.sections)
        return { layout: 'check', rows, headline: { changes: rows.reduce((n, r) => n + r.counts.create + r.counts.update, 0), blocked: rows.reduce((n, r) => n + r.counts.blocked, 0) } }
    }
    const rows = Object.entries(summary.sections || {}).map(([section, count]) => ({ section, count: Number(count) || 0 }))
    return { layout: 'export', rows, headline: { count: rows.reduce((n, r) => n + r.count, 0) } }
}
