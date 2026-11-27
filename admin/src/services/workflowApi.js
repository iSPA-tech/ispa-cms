import ajax from '@/ajax'

export const validateExpression = async (expression, payload = {}) => {
    const body = { expression, ...payload }
    const res = await ajax.post('workflow/expression/validate', body)

    return res?.data
}

export const previewTemplate = async (template, payload = {}) => {
    const body = { template, ...payload }
    const res = await ajax.post('workflow/template/preview', body)

    return res?.data
}

export const fetchModelSchema = async (model) => {
    const res = await ajax.get('workflow/schema', {
        params: { model },
    })

    return res?.data?.data || res?.data
}

export const fetchRelationSchema = async (model, relation) => {
    const res = await ajax.get('workflow/schema/relation', {
        params: { model, relation },
    })

    return res?.data?.data || res?.data
}

export const fetchModelList = async () => {
    const res = await ajax.get('workflow/models')

    return res?.data?.data || res?.data || []
}

let selectableModelsRequest = null

/**
 * AI models a workflow step may pick: `[{ value: "provider:model", provider, provider_label, model, price_known }]`,
 * only for providers that have an API key. Loaded once per page; a failed request is retried on the next call.
 */
export const fetchSelectableModels = () => {
    if (!selectableModelsRequest) {
        selectableModelsRequest = ajax
            .get('ai-runtime/selectable-models', { silent: true })
            .then((res) => (Array.isArray(res?.data?.data) ? res.data.data : []))
            .catch((error) => {
                selectableModelsRequest = null
                throw error
            })
    }
    return selectableModelsRequest
}

const reviewerRows = (res) =>
    (Array.isArray(res?.data?.data) ? res.data.data : [])
        .filter((row) => row?.uuid)
        .map((row) => ({ uuid: row.uuid, name: row.name || '', detail: row.detail || '', can_review: row.can_review !== false }))

/**
 * People a workflow step may name as reviewers, found by name: `[{ uuid, name, detail, can_review }]` (at most 20, only
 * people who may approve; `detail` = username, or the email of an account without one). Silent: the picker shows its
 * own "could not load" line.
 */
export const searchReviewers = async (keywords = '', audience = 'review') => {
    const q = String(keywords ?? '').trim()
    const params = { ...(q !== '' ? { q } : {}), ...(audience === 'notice' ? { for: 'notice' } : {}) }
    const res = await ajax.get('workflows/reviewers', { params, silent: true })
    return reviewerRows(res)
}

/**
 * Saved reviewers (user uuids) as `[{ uuid, name, can_review }]`. An account that no longer exists is not returned;
 * `can_review: false` marks a person who lost the right to approve.
 */
export const resolveReviewers = async (uuids = [], audience = 'review') => {
    const list = (Array.isArray(uuids) ? uuids : []).filter((uuid) => typeof uuid === 'string' && uuid !== '')
    if (!list.length) return []
    const res = await ajax.get('workflows/reviewers', { params: { uuids: list, ...(audience === 'notice' ? { for: 'notice' } : {}) }, silent: true })
    return reviewerRows(res)
}

/**
 * Node catalog for the workflow designer: groups and node definitions the signed-in user may use.
 * Silent: the designer falls back to its built-in list when the request fails.
 */
export const fetchNodeCatalog = async () => {
    const res = await ajax.get('workflow/node-catalog', { silent: true })

    return res?.data?.data || {}
}

/**
 * Data of the workflow's most recent run, used as sample values in the step form: `{ vars, trigger }`, or null when
 * the workflow (named by its uuid) never ran or the account may not see runs. Silent: the form then shows data types instead of samples.
 */
export const fetchLastRunContext = async (projectUuid) => {
    if (!projectUuid) return null
    try {
        const list = await ajax.get(`workflow/projects/${encodeURIComponent(projectUuid)}/instances`, { params: { page: 1, per_page: 1 }, silent: true })
        const run = list?.data?.data?.data?.[0]
        if (!run?.id) return null
        const detail = await ajax.get(`workflow/instances/${run.id}`, { silent: true })
        const snapshot = detail?.data?.data?.instance?.context_snapshot
        if (!snapshot || typeof snapshot !== 'object') return null
        const vars = snapshot.vars && typeof snapshot.vars === 'object' && !Array.isArray(snapshot.vars) ? snapshot.vars : {}
        return { vars, trigger: snapshot.trigger && typeof snapshot.trigger === 'object' ? snapshot.trigger : {} }
    } catch (error) {
        return null
    }
}

const presetRequests = {}

/**
 * Ready-made sets a step setting is picked from: `[{ key, label, description, module, source?, items: [...] }]`, with
 * labels in the operator's language. Kinds: `jev_criteria` (items `{ key, label, text, default }`), `score_rubric`
 * (items `{ key, label, text, weight }`) and `classify_labels` (items `{ key, label }`; a set with `source` lists
 * things of this site, such as the post categories). Loaded once per kind; a failed request is retried on the next call.
 */
export const fetchWorkflowPresets = (kind) => {
    if (!presetRequests[kind]) {
        presetRequests[kind] = ajax
            .get('workflows/presets', { params: { kind }, silent: true })
            .then((res) => (Array.isArray(res?.data?.data) ? res.data.data : []).filter((row) => row?.key))
            .catch((error) => {
                delete presetRequests[kind]
                throw error
            })
    }
    return presetRequests[kind]
}

let permissionOptionsRequest = null

/**
 * Permissions a step may name (who reviews, who is told): `[{ key, label, module, module_label }]`. Loaded once per
 * page; a failed request is retried on the next call.
 */
export const fetchPermissionOptions = () => {
    if (!permissionOptionsRequest) {
        permissionOptionsRequest = ajax
            .get('workflows/permission-options', { silent: true })
            .then((res) => (Array.isArray(res?.data?.data) ? res.data.data : []).filter((row) => row?.key))
            .catch((error) => {
                permissionOptionsRequest = null
                throw error
            })
    }
    return permissionOptionsRequest
}

/**
 * Where "auto-publish when JEV is sure" stands for one review step: `{ available, mode, min_confidence, source,
 * decided, required, agreed, disagreed, agreement_rate, minimum_agreement, approved_after_edit, excluded, ready,
 * paused, estimate: { "0.6": n, "0.8": n, "0.9": n, of: n }, last_30_days: { auto, human, withdrawn } }`.
 * Silent: a step the server does not know yet (never saved) simply has no such section.
 */
export const fetchAutoApproval = async (projectUuid, stepCode) => {
    const res = await ajax.get(`workflows/${encodeURIComponent(projectUuid)}/steps/${encodeURIComponent(stepCode)}/auto-approval`, { silent: true })
    const data = res?.data?.data
    return data && typeof data === 'object' && !Array.isArray(data) ? data : { available: false }
}

/**
 * Managed e-mail templates a workflow `email_send` step may send (group `workflow`):
 * `[{ key_name, mail_class, name, languages: [{ id, language_id, code, name, mail_class }], variables: [{ key,
 * description, sample, declared, builtin }] }]`. Silent: the step form words a failure itself.
 */
export const fetchEmailTemplateCatalog = async () => {
    const res = await ajax.get('email-template/catalog', { params: { group: 'workflow' }, silent: true })
    return (Array.isArray(res?.data?.data) ? res.data.data : []).filter((row) => row?.key_name)
}
