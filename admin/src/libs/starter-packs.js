import ajax from '@/ajax'

/**
 * Read-only client of the starter pack API. Packs are addressed by their key (the pack folder name);
 * the page never shows it. Requests are silent: the page shows its own error state with a retry button.
 */
const resource = 'starter-packs'
const quiet = { silent: true }

/**
 * Request options that ask the API for the admin's own language: without `X-Locale` the API answers in the site's
 * default language, which can differ from the language the operator reads the admin in.
 * @param {string} [locale] Admin locale such as `vi` or `en`.
 * @returns {object} Axios options.
 */
const options = (locale) => (locale ? { ...quiet, headers: { 'X-Locale': locale } } : quiet)

/**
 * Path of one pack. Pure: it does not call the API.
 * @param {string} key Pack key (`[a-z0-9-]+`).
 * @returns {string} Path relative to the API base.
 */
export const packUrl = (key) => `${resource}/${encodeURIComponent(key)}`

/**
 * List the packs with their localized name, description, version and applied state.
 * @param {string} [locale] Admin locale.
 * @returns {Promise<Array<object>>} Pack rows.
 */
export const fetchStarterPacks = async (locale) => {
    const res = await ajax.get(resource, options(locale))
    const rows = res?.data?.data
    return Array.isArray(rows) ? rows : []
}

/**
 * The plan of one pack on this site now: modules, configuration, roles, workflows, content, blockers, warnings.
 * @param {string} key Pack key.
 * @param {string} [locale] Admin locale.
 * @returns {Promise<object>} Pack detail (`plan` holds the lists, `can_apply` is the viewer's permission).
 */
export const fetchStarterPack = async (key, locale) => {
    const res = await ajax.get(packUrl(key), options(locale))
    return res?.data?.data || {}
}

/**
 * Known planner messages (English, sent by the API) as data: pattern with named groups and the key suffix under
 * `text.starter_pack.`. Components translate; nothing here imports i18n. Order matters: the first match wins.
 */
export const MESSAGE_TABLE = [
    {
        re: /^Module (?<name>\S+) (?<version>\S+) does not meet the pack's constraint "(?<constraint>.*)"\.$/,
        key: 'blocker_constraint',
    },
    {
        re: /^Module (?<module>\S+) requires (?<dep>\S+) (?<constraint>.+), but the pack brings (?<version>.+)\.$/,
        key: 'blocker_dep_pack_version',
    },
    {
        re: /^Module (?<module>\S+) requires (?<dep>\S+), which is not enabled on this site and is not part of the pack\.$/,
        key: 'blocker_dep_missing',
    },
    {
        re: /^Module (?<module>\S+) requires (?<dep>\S+) (?<constraint>.+), but this site has (?<version>.+)\.$/,
        key: 'blocker_dep_site_version',
    },
    {
        re: /^Module (?<name>\S+) is not installed on this site and is not published on the hub\.$/,
        key: 'blocker_not_published',
    },
    { re: /^Module (?<name>\S+) is not installed and .+$/, key: 'blocker_catalog_unreachable' },
    {
        re: /^No published version of module (?<name>\S+) meets the pack's constraint ".*"\.$/,
        key: 'blocker_no_version',
    },
    {
        re: /^Pack version \S+ is older than version \S+ already applied to this site\.$/,
        key: 'blocker_older',
    },
    {
        re: /^This site is flagged as production and already holds (?<module>\S+) content;.*$/,
        key: 'blocker_production',
    },
    {
        re: /^The pack configuration (file could not be read|could not be compared with this site)\.$/,
        key: 'blocker_config_unreadable',
    },
    { re: /^The pack configuration is not valid: .*$/, key: 'blocker_config_invalid' },
    {
        re: /^The pack (roles|workflows) file could not be read\.$/,
        key: 'blocker_files_unreadable',
    },
    { re: /^Content file .+ could not be read\.$/, key: 'blocker_files_unreadable' },
    {
        re: /^Content importer \S+ is not registered although module (?<module>\S+) is enabled\.$/,
        key: 'blocker_importer',
    },
    { re: /^Content file .+: .+$/, key: 'blocker_content_invalid' },
    { re: /^Module (?<name>\S+) \S+ has irreversible migrations\.$/, key: 'warning_irreversible' },
    {
        re: /^Cannot check whether this site holds (?<module>\S+) content; treated as holding content\.$/,
        key: 'warning_content_unknown',
    },
    {
        re: /^The configuration has items that need a choice; apply keeps existing values\.$/,
        key: 'warning_config_choice',
    },
    {
        re: /^Role (?<role>.+) will be created without (?<count>\d+) permission\(s\) missing on this site\.$/,
        key: 'warning_role_permissions',
    },
    {
        re: /^(?<count>\d+) setting\(s\) of the configuration cannot be added because the module's default settings are not installed on this site yet\.$/,
        key: 'warning_settings_missing',
    },
    {
        re: /^Content of (?<module>\S+) is counted after the module is enabled\.$/,
        key: 'warning_content_after_enable',
    },
]

/**
 * Match a planner message against the known table.
 * @param {string|null|undefined} text Message as sent by the API (English).
 * @returns {{key: string, params: Record<string, string>}|null} Key suffix and params, or null when unknown (the
 *   caller then shows the text as sent).
 */
export const matchMessage = (text) => {
    const value = String(text ?? '')
    for (const row of MESSAGE_TABLE) {
        const m = row.re.exec(value)
        if (m) return { key: row.key, params: { ...(m.groups || {}) } }
    }
    return null
}

/**
 * Whether applying the pack would change nothing on this site (the API's own flag).
 * @param {object|null|undefined} plan Plan of one pack.
 * @returns {boolean}
 */
export const hasNothingToDo = (plan) =>
    !!plan && plan.has_changes === false && (plan.blockers || []).length === 0

/**
 * Modules of the plan that bring database changes that cannot be undone.
 * @param {object|null|undefined} plan Plan of one pack.
 * @returns {Array<{name: string, migrations: string[]}>}
 */
export const irreversibleModules = (plan) =>
    (plan?.modules || [])
        .filter((m) => Array.isArray(m.irreversible) && m.irreversible.length > 0)
        .map((m) => ({ name: m.name, migrations: m.irreversible }))

/** Task type the apply is recorded under; the task bar labels it through `text.starter_pack.task`. */
export const APPLY_TASK_TYPE = 'core.starter_pack.apply'

/**
 * Queue the apply of a pack (202). The request is silent: the page shows the failure in its own words.
 * @param {string} key Pack key.
 * @param {{version: string, confirm_irreversible?: boolean, confirm_third_party?: boolean}} payload Reviewed version and the explicit confirmation.
 * @returns {Promise<{task_uuid: string|null, application_uuid: string|null}>} Ids of the background task and ledger row.
 */
export const applyStarterPack = async (key, payload) => {
    const res = await ajax.post(`${packUrl(key)}/apply`, payload, quiet)
    const data = res?.data?.data || {}
    return { task_uuid: data.task_uuid || null, application_uuid: data.application_uuid || null }
}

/**
 * Ledger rows of one pack, newest first: status and the step results of each apply.
 * @param {string} key Pack key.
 * @returns {Promise<Array<object>>} Application rows.
 */
export const fetchStarterPackHistory = async (key) => {
    const res = await ajax.get(`${packUrl(key)}/history`, quiet)
    const rows = res?.data?.data
    return Array.isArray(rows) ? rows : []
}

/**
 * Whether an application row is still being worked on.
 * @param {{status?: string}|null|undefined} row Ledger row.
 * @returns {boolean}
 */
export const isApplicationRunning = (row) => row?.status === 'started'

/**
 * Whether a pack brings site configuration (the API then needs a Webmaster).
 * @param {object|null|undefined} plan Plan of one pack.
 * @returns {boolean}
 */
export const hasConfiguration = (plan) => !!plan && plan.config !== null && plan.config !== undefined

/**
 * Modules the apply would install whose database changes cannot be undone: these need the explicit confirmation.
 * @param {object|null|undefined} plan Plan of one pack.
 * @returns {Array<{name: string, migrations: string[]}>}
 */
export const modulesNeedingConfirmation = (plan) =>
    (plan?.modules || [])
        .filter((m) => m.state === 'to_install' && Array.isArray(m.irreversible) && m.irreversible.length > 0)
        .map((m) => ({ name: m.name, migrations: m.irreversible }))

/**
 * Modules the apply would install that a partner publishes and this site never confirmed: each needs the
 * same confirmation box as the single install. `details` is `{publisher, impact}` as the third-party box reads it.
 * @param {object|null|undefined} plan Plan of one pack.
 * @returns {Array<{name: string, details: {publisher: object|null, impact: object|null}}>}
 */
export const modulesNeedingThirdParty = (plan) =>
    (plan?.modules || [])
        .filter((m) => m.state === 'to_install' && m.third_party && typeof m.third_party === 'object')
        .map((m) => ({ name: m.name, details: { publisher: m.third_party.publisher ?? null, impact: m.third_party.impact ?? null } }))

/**
 * Sort an apply error into a kind the page can say in plain words.
 * @param {any} error Axios error (or anything thrown).
 * @returns {{kind: string, blockers: string[]}} `kind` is one of: stale, busy, blocked, irreversible, third_party, webmaster, forbidden,
 *   missing, invalid, network, failed. `blockers` holds the planner messages of a 422 (English, as sent).
 */
export const applyErrorKind = (error) => {
    const status = error?.response?.status
    const data = error?.response?.data || {}
    const blockers = Array.isArray(data.blockers) ? data.blockers.map(String) : []
    if (!error?.response) return { kind: 'network', blockers }
    if (status === 409) return { kind: data.pack_version ? 'stale' : 'busy', blockers }
    if (status === 422) {
        if (blockers.length) return { kind: 'blocked', blockers }
        if (Array.isArray(data.irreversible) && data.irreversible.length) return { kind: 'irreversible', blockers }
        if (Array.isArray(data.third_party) && data.third_party.length) return { kind: 'third_party', blockers }
        return { kind: 'invalid', blockers }
    }
    if (status === 403) return { kind: /Webmaster/.test(String(data.message || '')) ? 'webmaster' : 'forbidden', blockers }
    if (status === 404) return { kind: 'missing', blockers }
    return { kind: 'failed', blockers }
}

/**
 * Known step failure messages (English, written by the applier) as data: pattern and key suffix under
 * `text.starter_pack.`. An unknown message is never shown; the step gets a generic sentence instead.
 */
export const STEP_MESSAGE_TABLE = [
    { re: /^backup failed, nothing changed$/, key: 'step_backup_failed' },
    { re: /^Another starter pack is being applied/, key: 'step_busy' },
    { re: /^The pack cannot be applied: /, key: 'step_blocked' },
    { re: /^Module \S+ has database changes that cannot be undone/, key: 'step_irreversible_unconfirmed' },
    { re: /^Only a Webmaster can apply the configuration/, key: 'step_webmaster' },
    { re: /^The modules changed while the pack was being applied/, key: 'step_modules_changed' },
    { re: /^Module (?<name>\S+) could not be enabled\.$/, key: 'step_module_enable' },
    { re: /^The sample content of module (?<module>\S+) is not available yet/, key: 'step_content_not_ready' },
    { re: /^The final step \S+ failed/, key: 'step_final_failed' },
    { re: /^The \S+ step stopped unexpectedly/, key: 'step_unexpected' },
]

/**
 * Match a step message against the known table.
 * @param {string|null|undefined} text Message of a failed step.
 * @returns {{key: string, params: Record<string, string>}|null} Key suffix and params, or null when unknown.
 */
export const matchStepMessage = (text) => {
    const value = String(text ?? '')
    for (const row of STEP_MESSAGE_TABLE) {
        const m = row.re.exec(value)
        if (m) return { key: row.key, params: { ...(m.groups || {}) } }
    }
    return null
}
