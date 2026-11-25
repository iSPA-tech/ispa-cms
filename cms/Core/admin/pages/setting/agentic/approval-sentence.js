/**
 * Plain-language helpers for the agentic approval detail page.
 * Pure functions: the translator `t` is always passed in (helpers must not import the i18n config).
 */
import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'
import timezone from 'dayjs/plugin/timezone'

dayjs.extend(utc)
dayjs.extend(timezone)

/** Friendly noun key per subject class basename. */
const SUBJECT_NOUN_KEYS = {
    HelpdeskTicket: 'text.agentic.approval.noun_ticket',
    Post: 'text.agentic.approval.noun_post',
    Product: 'text.agentic.approval.noun_product',
}

/** Field label key per payload field name. */
const FIELD_LABEL_KEYS = {
    priority: 'text.agentic.approval.field_priority',
    status: 'text.agentic.approval.field_status',
    title: 'text.agentic.approval.field_title',
    description: 'text.agentic.approval.field_description',
}

/**
 * Format a timestamp as local `DD/MM/YYYY HH:mm` (Asia/Ho_Chi_Minh, like other admin screens).
 *
 * @param {string|number|Date|null} value Raw timestamp from the API.
 * @returns {string} Formatted text, or an em dash when empty or unparsable.
 */
export function formatApprovalDate(value) {
    if (!value) return '—'
    const date = dayjs(value)
    return date.isValid() ? date.tz('Asia/Ho_Chi_Minh').format('DD/MM/YYYY HH:mm') : '—'
}

/**
 * Class basename of a PHP subject type (`Cms\Helpdesk\Models\HelpdeskTicket` -> `HelpdeskTicket`).
 *
 * @param {string|null|undefined} subjectType Fully qualified class name.
 * @returns {string} Basename, or an empty string.
 */
export function subjectBasename(subjectType) {
    return typeof subjectType === 'string' ? subjectType.split('\\').pop() || '' : ''
}

/**
 * Translate a key, returning null when no translation exists (vue-i18n echoes the key back).
 *
 * @param {Function} t Translator.
 * @param {string} key Translation key.
 * @param {Object} [params] Interpolation params.
 * @returns {string|null} Translated text or null.
 */
function tryTranslate(t, key, params) {
    const out = t(key, params)
    return typeof out === 'string' && out !== '' && out !== key ? out : null
}

/**
 * Human label for a field value; enum codes become translated labels.
 *
 * @param {string} field Field name.
 * @param {*} value Raw value.
 * @param {string} base Subject class basename.
 * @param {Function} t Translator.
 * @returns {string} Display text, never an empty string.
 */
function valueLabel(field, value, base, t) {
    if (value === null || value === undefined || value === '') return t('text.agentic.na')
    const raw = String(value)
    const keys = []
    if (base === 'HelpdeskTicket' && (field === 'priority' || field === 'status')) {
        keys.push(`text.helpdesk.tickets.${field}_${raw}`)
    }
    keys.push(`text.agentic.label.${raw}`)
    for (const key of keys) {
        const hit = tryTranslate(t, key)
        if (hit) return hit
    }
    return raw.replace(/_/g, ' ')
}

/**
 * Collect the changed scalar fields of an approval as [{ field, from, to }].
 * Uses `diff_data.before/after` first, then `original_data_snapshot` against `payload.proposed_change`.
 *
 * @param {Object} approval Approval record from the API.
 * @returns {Array<{field: string, from: *, to: *}>} Changed fields.
 */
function collectChanges(approval) {
    const isMap = (v) => v && typeof v === 'object' && !Array.isArray(v)
    const diff = approval?.diff_data
    const artifact = approval?.artifact
    let before = {}
    let after = null
    if (isMap(diff?.after)) {
        after = diff.after
        before = isMap(diff.before) ? diff.before : {}
    } else if (isMap(artifact?.payload?.proposed_change)) {
        after = artifact.payload.proposed_change
        before = isMap(artifact.original_data_snapshot) ? artifact.original_data_snapshot : {}
    }
    if (!after) return []
    const isScalar = (v) => v === null || ['string', 'number', 'boolean'].includes(typeof v)

    return Object.keys(after)
        .filter((field) => isScalar(after[field]) && isScalar(before[field] ?? null) && (before[field] ?? null) !== after[field])
        .map((field) => ({ field, from: before[field] ?? null, to: after[field] }))
}

/**
 * Build one plain sentence describing what approving this record will do.
 *
 * @param {Object} approval Approval record from the API.
 * @param {Function} t Translator (vue-i18n `t`).
 * @param {{artifactTypeLabel?: Function}} [options] `artifactTypeLabel` turns an artifact type into a label for the fallback.
 * @returns {string} Sentence without PHP class names or codes.
 */
export function buildApprovalSentence(approval, t, options = {}) {
    const artifact = approval?.artifact || {}
    const base = subjectBasename(artifact.subject_type)
    const noun = SUBJECT_NOUN_KEYS[base] ? tryTranslate(t, SUBJECT_NOUN_KEYS[base]) : null
    const hasId = artifact.subject_id !== null && artifact.subject_id !== undefined && artifact.subject_id !== ''
    const subject = noun ? (hasId ? `${noun} #${artifact.subject_id}` : noun) : ''

    const changes = subject ? collectChanges(approval).filter((c) => FIELD_LABEL_KEYS[c.field]) : []
    const label = (c) => t(FIELD_LABEL_KEYS[c.field])
    if (changes.length === 1) {
        const [c] = changes
        const params = { field: label(c), subject, to: valueLabel(c.field, c.to, base, t) }
        if (c.from === null) return t('text.agentic.approval.sentence_change_to', params)
        return t('text.agentic.approval.sentence_change_from_to', { ...params, from: valueLabel(c.field, c.from, base, t) })
    }
    if (changes.length > 1) {
        return t('text.agentic.approval.sentence_change_many', { fields: changes.map(label).join(', '), subject })
    }

    const typeLabel = options.artifactTypeLabel ? options.artifactTypeLabel(artifact.artifact_type) : ''
    const safeLabel = typeLabel && typeLabel !== artifact.artifact_type ? typeLabel : t('text.agentic.approval.sentence_unknown')
    return subject ? t('text.agentic.approval.sentence_generic', { label: safeLabel, subject }) : safeLabel
}

/**
 * A length of time in plain words, at most two units: "2 giờ 15 phút", "3 ngày 4 giờ", "45 phút".
 * Under a minute reads "dưới 1 phút". `short` keeps the largest unit only ("Còn 2 giờ").
 *
 * @param {number|null|undefined} seconds Length in seconds.
 * @param {Function} t Translator.
 * @param {{short?: boolean}} [options]
 * @returns {string} Display text, or an em dash when there is no number.
 */
export function formatDuration(seconds, t, { short = false } = {}) {
    const total = Number(seconds)
    if (seconds === null || seconds === undefined || seconds === '' || !Number.isFinite(total) || total < 0) return '—'
    if (total < 60) return t('text.agentic.sla.under_minute')
    const minutes = Math.floor(total / 60)
    const days = Math.floor(minutes / 1440)
    const hours = Math.floor((minutes % 1440) / 60)
    const mins = minutes % 60
    const parts = []
    if (days) parts.push(t('text.agentic.sla.days', { n: days }))
    if (hours) parts.push(t('text.agentic.sla.hours', { n: hours }))
    if (mins && !days) parts.push(t('text.agentic.sla.minutes', { n: mins }))
    return (short ? parts.slice(0, 1) : parts.slice(0, 2)).join(' ')
}

/**
 * The due chip of an approval: how long is left, how late it is, or how the decision compares to the target time.
 * Always a text, so "overdue" never depends on the colour alone. Null when nothing is measured.
 *
 * @param {Object|null} approval Approval resource (with its optional `assignment`).
 * @param {Function} t Translator.
 * @param {number} [now] Current time in milliseconds (injected in tests).
 * @returns {{text: string, tone: 'ok'|'late'|'neutral', color: string}|null}
 */
export function dueChip(approval, t, now = Date.now()) {
    const assignment = approval?.assignment
    if (!assignment) return null
    if (approval.status === 'pending') {
        const due = assignment.due_at ? dayjs(assignment.due_at) : null
        if (!due || !due.isValid()) return null
        const left = Math.round((due.valueOf() - now) / 1000)
        if (left <= 0 || assignment.overdue) {
            return { text: t('text.agentic.sla.overdue_by', { time: formatDuration(Math.abs(Math.min(left, 0)), t, { short: true }) }), tone: 'late', color: 'red' }
        }
        return { text: t('text.agentic.sla.time_left', { time: formatDuration(left, t, { short: true }) }), tone: 'neutral', color: 'default' }
    }
    if (assignment.sla_met === true) return { text: t('text.agentic.sla.on_time'), tone: 'ok', color: 'green' }
    if (assignment.sla_met === false) return { text: t('text.agentic.sla.late'), tone: 'late', color: 'red' }
    return null
}

/** Names of the people an approval was given to, as one line; empty when there are none. */
export function assigneeNames(approval) {
    const list = approval?.assignment?.assignees
    return Array.isArray(list) ? list.map((person) => person?.name).filter(Boolean).join(', ') : ''
}
