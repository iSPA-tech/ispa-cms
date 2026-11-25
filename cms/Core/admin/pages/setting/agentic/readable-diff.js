/**
 * Turns an approval's proposed change into rows an operator can read: one row per field with the current and the
 * proposed value, never ids, class names or raw JSON. Pure functions; the translator `t` is passed in.
 */

/** Fields that only matter to the system, never shown to the operator. */
const HIDDEN_FIELDS = new Set([
    'id',
    'uuid',
    'version',
    'checksum',
    'cost_usd',
    'replace_existing',
    'image_candidate',
    'jev_verdict',
    'generated_at',
    'updated_at',
    'created_at',
    'source_snapshot',
    'proposed_change',
    'changes',
    'evidence',
    'citations',
])

/** Supporting lists a proposal may carry beside the change itself (shown after the fields). */
const SUPPORTING_FIELDS = ['sources', 'image_candidates', 'quality_flags']

/** Keys of a list entry that hold its link, its name and its leading label (a year, a date). */
const URL_KEYS = ['url', 'link', 'href', 'source_url', 'page_url']
const NAME_KEYS = ['title', 'name', 'label', 'publisher', 'description', 'text', 'event', 'note']
const LEAD_KEYS = ['year', 'date', 'period', 'time']

const isMap = (value) => value !== null && typeof value === 'object' && !Array.isArray(value)
const isBlank = (value) => value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)
const isUrl = (value) => typeof value === 'string' && /^https?:\/\/\S+$/i.test(value.trim())

/**
 * Authored text of a key, or null when there is none. `te` (vue-i18n's "has this key") decides when it is given: the
 * admin's missing-key handler answers a made-up label, so comparing the answer with the key is not enough there.
 */
function authored(key, t, te) {
    if (typeof te === 'function') return te(key) ? t(key) : null
    const hit = t(key)
    return typeof hit === 'string' && hit !== '' && hit !== key ? hit : null
}

/** True for a field name the operator should not see (ids, uuids, system values). */
export const isHiddenField = (field) => HIDDEN_FIELDS.has(field) || /(^|_)(id|uuid)$/.test(String(field))

/** Short readable form of a web address: host plus the start of the path. */
export function shortUrl(url) {
    try {
        const parsed = new URL(url)
        let path = parsed.pathname === '/' ? '' : parsed.pathname
        try {
            path = decodeURIComponent(path)
        } catch {
            // Keep the encoded path.
        }
        if (path.length > 36) path = `${path.slice(0, 36)}…`
        return `${parsed.hostname.replace(/^www\./, '')}${path}`
    } catch {
        return String(url)
    }
}

/**
 * Label of a field: an authored translation when there is one, else the field name in plain words.
 *
 * @param {string} field Field name or dot path.
 * @param {Function} t Translator.
 * @param {Function} [te] vue-i18n `te`.
 * @returns {string} Label.
 */
export function fieldLabel(field, t, te) {
    const name = String(field).split('.').pop()
    const words = authored(`text.agentic.approval.field_${name}`, t, te) ?? name.replace(/_/g, ' ').trim()
    return words.charAt(0).toUpperCase() + words.slice(1)
}

/** Text of a yes/no or plain value. */
function scalarText(value, t) {
    if (typeof value === 'boolean') return t(value ? 'text.agentic.approval.value_yes' : 'text.agentic.approval.value_no')
    return String(value)
}

/** One list entry as { lead, text, url }. */
function listEntry(item, t, te) {
    if (!isMap(item)) {
        return isUrl(item) ? { lead: '', text: shortUrl(item), url: item.trim() } : { lead: '', text: scalarText(item, t), url: '' }
    }
    const pick = (keys) => keys.map((key) => item[key]).find((value) => typeof value === 'string' || typeof value === 'number')
    const url = URL_KEYS.map((key) => item[key]).find(isUrl) || ''
    const lead = pick(LEAD_KEYS)
    let text = pick(NAME_KEYS)
    if (text === undefined || text === '') {
        text = url
            ? shortUrl(url)
            : Object.entries(item)
                  .filter(([key, value]) => !isHiddenField(key) && !isBlank(value) && !isMap(value) && !Array.isArray(value))
                  .map(([key, value]) => `${fieldLabel(key, t, te)}: ${scalarText(value, t)}`)
                  .join(' · ')
    }
    return { lead: lead === undefined ? '' : String(lead), text: String(text), url: url ? url.trim() : '' }
}

/**
 * Display form of one value: `{ kind: 'empty' }`, `{ kind: 'text', text }`, `{ kind: 'link', text, url }` or
 * `{ kind: 'list', items: [{ lead, text, url }] }`.
 */
export function displayValue(value, t, te) {
    if (isBlank(value)) return { kind: 'empty' }
    if (Array.isArray(value)) {
        const items = value.filter((item) => !isBlank(item)).map((item) => listEntry(item, t, te))
        return items.length ? { kind: 'list', items } : { kind: 'empty' }
    }
    if (isMap(value)) {
        const items = Object.entries(value)
            .filter(([key, inner]) => !isHiddenField(key) && !isBlank(inner))
            .map(([key, inner]) => ({ lead: fieldLabel(key, t, te), text: isMap(inner) || Array.isArray(inner) ? compactText(inner, t, te) : scalarText(inner, t), url: '' }))
        return items.length ? { kind: 'list', items } : { kind: 'empty' }
    }
    if (isUrl(value)) return { kind: 'link', text: shortUrl(value), url: value.trim() }
    return { kind: 'text', text: scalarText(value, t) }
}

/** A nested value on one line. */
function compactText(value, t, te) {
    const shown = displayValue(value, t, te)
    if (shown.kind === 'list') return shown.items.map((item) => [item.lead, item.text].filter(Boolean).join(': ')).join(', ')
    return shown.kind === 'empty' ? '' : shown.text
}

const sameValue = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null)

/**
 * Rows of an approval's change.
 *
 * @param {Object} approval Approval record from the API.
 * @param {Function} t Translator.
 * @param {Function} [te] vue-i18n `te`; without it a key that comes back unchanged counts as missing.
 * @returns {{rows: Array<{field: string, label: string, before: Object, after: Object, changed: boolean}>,
 *   supporting: Array<{field: string, label: string, value: Object}>, hasBefore: boolean}}
 *   `hasBefore` is false when nothing has a current value (new content): the screen then shows one column.
 */
export function buildReadableDiff(approval, t, te) {
    const diff = approval?.diff_data || {}
    const artifact = approval?.artifact || {}
    const payload = isMap(artifact.payload) ? artifact.payload : {}

    let after = diff.after ?? payload.proposed_change ?? payload.changes ?? null
    let before = diff.before ?? artifact.original_data_snapshot ?? null
    // A single text change (`before`/`after` are plain values): one row named after the changed field.
    if (!isMap(after) && !isBlank(after)) {
        const field = typeof diff.field === 'string' && diff.field !== '' ? diff.field : 'value'
        after = { [field]: after }
        before = isMap(before) ? before : { [field]: before }
    }
    after = isMap(after) ? after : {}
    before = isMap(before) ? before : {}

    const rows = Object.keys(after)
        .filter((field) => !isHiddenField(field) && !SUPPORTING_FIELDS.includes(field))
        .filter((field) => !(isBlank(after[field]) && isBlank(before[field])))
        .map((field) => ({
            field,
            label: fieldLabel(field, t, te),
            before: displayValue(before[field], t, te),
            after: displayValue(after[field], t, te),
            changed: !sameValue(before[field], after[field]),
        }))

    const supporting = SUPPORTING_FIELDS.map((field) => {
        const value = after[field] ?? payload[field]
        // Quality flags are codes: use their authored wording when there is one.
        const shown =
            field === 'quality_flags' && Array.isArray(value)
                ? displayValue(value.map((flag) => flagLabel(flag, t, te)), t, te)
                : displayValue(value, t, te)
        return { field, label: fieldLabel(field, t, te), value: shown }
    }).filter((entry) => entry.value.kind !== 'empty')

    return { rows, supporting, hasBefore: rows.some((row) => row.before.kind !== 'empty') }
}

/** Readable wording of a quality flag code. */
function flagLabel(flag, t, te) {
    if (typeof flag !== 'string') return flag
    const words = authored(`text.agentic.approval.flag_${flag}`, t, te) ?? flag.replace(/_/g, ' ')
    return words.charAt(0).toUpperCase() + words.slice(1)
}

/**
 * Name of the record an approval is about (a church, a post), or '' when the proposal carries none. Never an id.
 *
 * @param {Object} approval Approval record from the API.
 * @returns {string} Record name.
 */
export function approvalSubjectName(approval) {
    const payload = approval?.artifact?.payload || {}
    const snapshot = approval?.artifact?.original_data_snapshot || {}
    const candidates = [payload.place_name, payload.subject_name, payload.post_name, payload.product_name, payload.name, snapshot.name, payload.source_snapshot?.name]
    const hit = candidates.find((value) => typeof value === 'string' && value.trim() !== '')
    return hit ? hit.trim() : ''
}
