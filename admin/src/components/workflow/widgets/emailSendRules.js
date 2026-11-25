/**
 * Rules of the "send an e-mail" step form (`email_send`, node version 2), kept free of Vue so they can be tested on
 * their own. The step either sends text typed in the step (`mode: text`) or a managed e-mail template
 * (`mode: template`); owner e-mail policy B (server: EmailSendPolicy) lets a template step send without an approval
 * when it types no text of its own and writes to the trigger's own person or to staff.
 */

export const MODE_TEXT = 'text'
export const MODE_TEMPLATE = 'template'
export const RECIPIENT_ADDRESS = 'address'
export const RECIPIENT_SUBJECT = 'trigger_subject'
export const RECIPIENT_STAFF = 'staff'

/** Settings only the "typed text" mode uses; a template step must leave them empty to stay inside policy B. */
export const TEXT_KEYS = ['subject', 'body', 'reply_to']
/** Settings only the template mode uses. */
export const TEMPLATE_KEYS = ['template_key', 'language', 'variables', 'recipient_permission', 'business_enabled']

export const modeOf = (config = {}) => (config?.mode === MODE_TEMPLATE ? MODE_TEMPLATE : MODE_TEXT)

export const recipientOf = (config = {}) => ([RECIPIENT_SUBJECT, RECIPIENT_STAFF].includes(config?.recipient) ? config.recipient : RECIPIENT_ADDRESS)

const eventName = (value) => String(value ?? '').trim().replace(/^\\+/, '')

/**
 * Whether the workflow starts on an app event that names its own person (a sign-up, a contact message), so the step
 * may write to "the person who sent it". `subjectEvents` is the server's list; null (an older server) offers the
 * choice to every workflow that starts on an app event and leaves the check to the save.
 */
export const startsOnSubjectEvent = (nodes = [], subjectEvents = null) => {
    const events = (Array.isArray(nodes) ? nodes : [])
        .filter((node) => node?.data?.step_type === 'trigger_event')
        .map((node) => eventName(node.data?.step_config?.event))
        .filter(Boolean)
    if (!events.length) return false
    if (!Array.isArray(subjectEvents)) return true
    const known = subjectEvents.map(eventName)
    return events.some((event) => known.includes(event))
}

const isBlank = (value) => value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)

/**
 * The settings to change when the operator picks a mode; a key given as `undefined` is removed.
 * - to `template`: the typed subject, body and reply-to are dropped (the caller keeps them to offer them back), and
 *   the recipient becomes the trigger's own person when the workflow has one, else staff.
 * - to `text`: the template settings are dropped and the recipient is a typed address again.
 */
export const switchMode = (config = {}, mode, { hasSubject = false, kept = {} } = {}) => {
    if (mode === modeOf(config)) return {}
    if (mode === MODE_TEMPLATE) {
        const recipient = hasSubject ? RECIPIENT_SUBJECT : RECIPIENT_STAFF
        return {
            mode: MODE_TEMPLATE,
            ...Object.fromEntries(TEXT_KEYS.map((key) => [key, undefined])),
            to: undefined,
            recipient,
            ...(kept.template_key ? kept : {}),
        }
    }
    return {
        mode: MODE_TEXT,
        ...Object.fromEntries(TEMPLATE_KEYS.map((key) => [key, undefined])),
        recipient: RECIPIENT_ADDRESS,
        to: kept.to ?? config.to ?? '',
        subject: kept.subject ?? '',
        body: kept.body ?? '',
        ...(isBlank(kept.reply_to) ? {} : { reply_to: kept.reply_to }),
    }
}

/** The settings to change when the operator picks who receives a template mail. */
export const switchRecipient = (config = {}, recipient) => {
    if (recipient === RECIPIENT_ADDRESS) return { recipient, recipient_permission: undefined, to: config.to ?? '' }
    if (recipient === RECIPIENT_STAFF) return { recipient, to: undefined, recipient_permission: config.recipient_permission ?? '' }
    return { recipient: RECIPIENT_SUBJECT, to: undefined, recipient_permission: undefined }
}

/**
 * The settings to change when another template is picked: values typed for variables the new template also has are
 * kept, the others dropped; the business switch goes back to off.
 */
export const switchTemplate = (config = {}, template = null) => {
    const names = (template?.variables || []).filter((row) => !row.builtin).map((row) => row.key)
    const stored = config.variables && typeof config.variables === 'object' && !Array.isArray(config.variables) ? config.variables : {}
    const variables = Object.fromEntries(Object.entries(stored).filter(([name]) => names.includes(name)))
    return {
        template_key: template?.key_name || '',
        variables: Object.keys(variables).length ? variables : undefined,
        business_enabled: undefined,
    }
}

/**
 * How the stored `language` reads: `default` (empty: the site's language), `fixed` (one of the template's language
 * codes) or `data` (anything else: taken from the workflow's data).
 */
export const languageKind = (value, template = null) => {
    const text = String(value ?? '').trim()
    if (text === '') return 'default'
    return (template?.languages || []).some((row) => row.code && row.code === text) ? 'fixed' : 'data'
}

/** The variables the operator fills for a template: `[{ key, label, sample, value }]` (built-in ones are left out). */
export const variableRows = (template = null, stored = null) => {
    const values = stored && typeof stored === 'object' && !Array.isArray(stored) ? stored : {}
    return (template?.variables || [])
        .filter((row) => row?.key && !row.builtin)
        .map((row) => ({ key: row.key, label: row.description || row.key, sample: row.sample || '', value: typeof values[row.key] === 'string' ? values[row.key] : '' }))
}

/** `variables` with one value changed; an emptied value is removed, an empty map is removed (`undefined`). */
export const writeVariable = (stored, key, value) => {
    const next = { ...(stored && typeof stored === 'object' && !Array.isArray(stored) ? stored : {}) }
    if (isBlank(value)) delete next[key]
    else next[key] = value
    return Object.keys(next).length ? next : undefined
}

/** Whether the step meets policy B (client mirror of EmailSendPolicy::isManaged; the server stays the authority). */
export const isManaged = (config = {}) => {
    if (modeOf(config) !== MODE_TEMPLATE || !/^[A-Za-z0-9_.-]{1,255}$/.test(String(config.template_key ?? '').trim())) return false
    if (['to', 'subject', 'body', 'reply_to', 'cc', 'bcc'].some((key) => !isBlank(config[key]))) return false
    const recipient = recipientOf(config)
    if (recipient === RECIPIENT_SUBJECT) return true
    return recipient === RECIPIENT_STAFF && /^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)+$/.test(String(config.recipient_permission ?? '').trim())
}
