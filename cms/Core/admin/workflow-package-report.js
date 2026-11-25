/**
 * Pure helpers of the workflow package page: they turn the server report of `workflow/packages/preview` into what an
 * operator reads (plain sentences, step names, pickers) and decide the copy name and the install button state.
 *
 * Nothing here calls the API or the DOM, so every rule is unit-tested.
 */

/** Largest package the server accepts (WorkflowPackageFormat::MAX_BYTES). */
export const MAX_PACKAGE_BYTES = 2 * 1024 * 1024

/** Report codes that have their own translated sentence (`text.workflow.package.problem.<code>`). */
export const PROBLEM_CODES = [
    'bad_envelope',
    'checksum_mismatch',
    'manifest_mismatch',
    'binding_undeclared',
    'unknown_node_type',
    'node_version_unsupported',
    'numeric_id',
    'secret_found',
    'personal_data',
    'workflow_invalid',
    'workflow_refused',
    'callable_not_allowed',
    'model_not_allowed',
    'config_invalid',
    'config_version_unsupported',
    'permission_denied',
    'taint_needs_approval',
    'slug_invalid',
    'slug_exists',
    'binding_missing',
    'binding_invalid',
    'assignee_unknown',
    'host_not_allowlisted',
    'host_from_expression',
]

/** Binding kinds the page has a picker for; any other kind falls back to the pasted-code field. */
export const BINDING_KINDS = ['credential', 'agent_profile', 'workflow', 'user', 'record']

/** Credential types a binding's `credential_type` accepts (WorkflowPackageBindings::CREDENTIAL_TYPES). */
const CREDENTIAL_TYPES = { http_auth: ['http_header', 'http_basic'] }

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/** Safety classes in the order the summary lists them, most consequential first. */
const SAFETY_ORDER = ['publish', 'ai_spend', 'external_call', 'internal_write', 'read']

const list = (value) => (Array.isArray(value) ? value : [])
const text = (value) => (typeof value === 'string' ? value.trim() : '')

/**
 * Parse the chosen file. A file that is not JSON gives `null`: the server is still asked, this only feeds step names.
 *
 * @param {string} raw File content.
 * @returns {object|null}
 */
export function parsePackage(raw) {
    try {
        const parsed = JSON.parse(String(raw ?? ''))
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null
    } catch (error) {
        return null
    }
}

/** True when the package carries its own sample record for the test run. */
export const hasSample = (pkg) => !!pkg?.sample && typeof pkg.sample === 'object' && !Array.isArray(pkg.sample)

/**
 * Step code → the name the author gave the step (the report only carries step codes).
 *
 * @param {object|null} pkg Parsed package.
 * @returns {Record<string, string>}
 */
export function stepNamesOf(pkg) {
    const names = {}
    list(pkg?.workflow?.steps).forEach((step) => {
        const code = text(step?.step_code)
        if (code) names[code] = text(step?.name) || code
    })
    return names
}

/** Step code → step type of the package (used to name the steps of an unavailable node type). */
function stepTypesOf(pkg) {
    const types = {}
    list(pkg?.workflow?.steps).forEach((step) => {
        const code = text(step?.step_code)
        if (code) types[code] = text(step?.step_type)
    })
    return types
}

/** Name of a step for the operator; an unknown code is shown as it is. */
export const stepName = (names, code) => names?.[code] || code || ''

/**
 * Why a file could not be read at all (the 422 of preview/install carries only an English sentence).
 *
 * @param {{size?: number}|null} file Chosen file.
 * @param {object|null} pkg Parsed package, or null when it is not JSON.
 * @returns {'too_large'|'not_a_package'|'unreadable'}
 */
export function fileRefusal(file, pkg) {
    if ((file?.size || 0) > MAX_PACKAGE_BYTES) return 'too_large'
    return pkg === null ? 'not_a_package' : 'unreadable'
}

/**
 * One report row as an operator sentence: `{ key, code, text, steps, detail }`.
 * A known code is translated; an unknown code falls back to the server's English message.
 *
 * @param {{code?: string, message?: string, step_code?: string, node_type?: string, host?: string}} problem Report row.
 * @param {{t: Function, names?: object, pkg?: object|null}} ctx Translator, step names, parsed package.
 */
export function describeProblem(problem, { t, names = {}, pkg = null } = {}) {
    const code = text(problem?.code)
    const message = text(problem?.message)
    const known = PROBLEM_CODES.includes(code)
    let steps = problem?.step_code ? [stepName(names, String(problem.step_code))] : []
    const params = {}

    if (code === 'unknown_node_type' || code === 'node_version_unsupported') {
        // The row names the node type (older servers only in the message); the operator needs the steps that use it.
        const type = text(problem?.node_type) || (/^Node type (\S+) /.exec(message) || [])[1]
        if (type && !steps.length) {
            const types = stepTypesOf(pkg)
            steps = Object.keys(types)
                .filter((stepCode) => types[stepCode] === type)
                .map((stepCode) => stepName(names, stepCode))
        }
    }
    if (code === 'host_not_allowlisted') {
        const host = text(problem?.host) || (/^Outbound host (\S+) is not/.exec(message) || [])[1]
        if (host) params.host = host
    }

    let sentence = message
    if (known) {
        const key = code === 'host_not_allowlisted' && !params.host ? 'host_not_allowlisted_plain' : code
        sentence = t(`text.workflow.package.problem.${key}`, params)
    }

    return { code, known, text: sentence, steps, detail: known ? '' : code }
}

/**
 * All rows of one report list as sentences, without repeating the same sentence for the same steps.
 *
 * @param {Array} rows `problems`, `binding_problems`, `slug_problems` or `warnings` of the report.
 * @param {object} ctx See describeProblem.
 */
export function describeProblems(rows, ctx) {
    const seen = new Set()
    const described = list(rows).map((row) => describeProblem(row, ctx))
    // Two server checks can report the same thing; the row that names its steps is the one worth reading.
    const withSteps = new Set(described.filter((row) => row.steps.length).map((row) => row.text))
    return described
        .filter((row) => row.steps.length || !withSteps.has(row.text))
        .filter((row) => {
            const id = `${row.text}|${row.steps.join(',')}`
            if (seen.has(id)) return false
            seen.add(id)
            return true
        })
}

/** Binding problems that belong to no single binding (for example a reviewer who no longer exists). */
export const looseBindingProblems = (report) => list(report?.binding_problems).filter((row) => !row?.binding)

/** True when the only thing left on the slug is that a workflow with it already exists here. */
export const slugTaken = (report) => list(report?.slug_problems).some((row) => row?.code === 'slug_exists')

/**
 * Slug of the n-th copy of a workflow (`<slug>-copy`, `<slug>-copy-2`, …); the operator never types one.
 *
 * @param {string} sourceSlug Slug in the package.
 * @param {number} attempt 1-based attempt.
 * @returns {string}
 */
export function copySlug(sourceSlug, attempt = 1) {
    const base =
        text(sourceSlug)
            .toLowerCase()
            .replace(/[^a-z0-9_-]+/g, '-')
            .replace(/^[^a-z0-9]+/, '')
            .slice(0, 170)
            .replace(/-+$/, '') || 'workflow'
    return attempt <= 1 ? `${base}-copy` : `${base}-copy-${attempt}`
}

/**
 * Name of the copy installed under `slug`, so it does not look like the original in the list:
 * `<name> (copy)` for `<slug>-copy`, `<name> (copy 2)` for `<slug>-copy-2`.
 *
 * @param {string} name Workflow name in the package.
 * @param {string} slug Copy slug from copySlug().
 * @param {Function} t Translator.
 * @returns {string} '' when there is no name.
 */
export function copyName(name, slug, t) {
    const base = text(name)
    if (!base) return ''
    const n = Number((/-copy-(\d+)$/.exec(text(slug)) || [])[1] || 1)
    return n > 1 ? t('text.workflow.package.copy_name_n', { name: base, n }) : t('text.workflow.package.copy_name', { name: base })
}

/**
 * Find a free copy slug by asking the server (`preview` with `as_slug`), at most `max` times.
 *
 * @param {string} sourceSlug Slug in the package.
 * @param {(slug: string) => Promise<object>} preview Returns the report for that slug.
 * @param {number} [max] Attempts.
 * @returns {Promise<{slug: string, report: object}|null>} null when every candidate is taken.
 */
export async function findCopySlug(sourceSlug, preview, max = 5) {
    for (let attempt = 1; attempt <= max; attempt += 1) {
        const slug = copySlug(sourceSlug, attempt)
        const report = await preview(slug)
        if (!list(report?.slug_problems).length) return { slug, report }
    }
    return null
}

/**
 * Credentials a binding may use, as select options. `credentialType` narrows the list (`http_auth` accepts a header
 * key or a username/password); without a type every credential fits.
 *
 * @param {Array<{name: string, type: string, description?: string}>} credentials Credentials of this site.
 * @param {string|undefined} credentialType `credential_type` of the binding.
 * @param {(type: string) => string} [typeLabel] Translated type name.
 */
export function credentialOptions(credentials, credentialType, typeLabel = (type) => type) {
    const accepted = credentialType ? CREDENTIAL_TYPES[credentialType] || [credentialType] : null
    return list(credentials)
        .filter((row) => row?.name && (!accepted || accepted.includes(row.type)))
        .map((row) => ({ value: row.name, label: `${row.name} (${typeLabel(row.type)})` }))
}

/**
 * Values the page starts with: a credential of the same name that fits is pre-selected, which is what the server
 * does when no value is sent. Everything else starts empty.
 *
 * @param {Array} bindings `bindings` of the report.
 * @param {Array} credentials Credentials of this site.
 * @returns {Record<string, string>}
 */
export function defaultBindingValues(bindings, credentials) {
    const values = {}
    list(bindings).forEach((binding) => {
        if (binding?.kind !== 'credential') return
        const fits = credentialOptions(credentials, binding.credential_type).some((option) => option.value === binding.name)
        if (fits) values[binding.name] = binding.name
    })
    return values
}

/** Only the values worth sending: non-empty strings, and a record code only once it is a full code. */
export function bindingPayload(bindings, values) {
    const payload = {}
    list(bindings).forEach((binding) => {
        const value = text(values?.[binding?.name])
        if (!value) return
        if (binding.kind === 'record' && !UUID.test(value)) return
        payload[binding.name] = value
    })
    return payload
}

/**
 * The bindings as rows for the page: `{ name, kind, steps, credentialType, value, resolved, state }`.
 * state: `ok` (the server accepted the value, or its own default), `invalid` (a value was sent and refused, or a record code is not a
 * code), `empty` (nothing chosen yet).
 *
 * @param {object|null} report Preview report.
 * @param {Record<string, string>} values Operator's choices.
 * @param {Record<string, string>} names Step names.
 */
export function bindingRows(report, values = {}, names = {}) {
    const refused = new Set(
        list(report?.binding_problems)
            .filter((row) => row?.code === 'binding_invalid' && row.binding)
            .map((row) => row.binding)
    )
    return list(report?.bindings)
        .filter((binding) => binding?.name)
        .map((binding) => {
            const value = text(values[binding.name])
            const kind = BINDING_KINDS.includes(binding.kind) ? binding.kind : 'record'
            let state = 'empty'
            // The server also accepts a credential of the same name when nothing is sent.
            if (binding.resolved) state = 'ok'
            else if (value && (refused.has(binding.name) || (kind === 'record' && !UUID.test(value)))) state = 'invalid'
            return {
                name: binding.name,
                kind,
                credentialType: binding.credential_type || '',
                steps: list(binding.steps).map((code) => stepName(names, String(code))),
                value,
                resolved: !!binding.resolved,
                state,
            }
        })
}

/**
 * Whether the install button is on, and what is still in the way.
 * blockers: `checking`, `problems`, `bindings` (with `count`), `slug`, `loose`.
 *
 * @param {object|null} report Preview report.
 * @param {{busy?: boolean, rows?: Array}} state Page state: a request is running; the binding rows.
 */
export function installState(report, { busy = false, rows = [] } = {}) {
    const blockers = []
    if (!report || busy) blockers.push({ code: 'checking' })
    if (report && list(report.problems).length) {
        // A broken package offers no choices, so nothing else is worth listing.
        blockers.push({ code: 'problems' })
    } else if (report) {
        const open = rows.filter((row) => row.state !== 'ok').length
        if (open) blockers.push({ code: 'bindings', count: open })
        else if (looseBindingProblems(report).length) blockers.push({ code: 'loose' })
        if (list(report.slug_problems).length) blockers.push({ code: slugTaken(report) ? 'slug' : 'slug_invalid' })
    }
    return { enabled: !!report && !busy && report.installable === true && blockers.length === 0, blockers }
}

/**
 * What the workflow does, from the manifest: safety classes (most consequential first), outside hosts, the modules
 * it needs besides Core, and whether it waits for a person.
 *
 * @param {object|null} report Preview report.
 */
export function packageSummary(report) {
    const manifest = report?.manifest || {}
    const classes = list(manifest.safety_classes)
    const steps = list(manifest.steps)
    return {
        stepCount: steps.length,
        safety: SAFETY_ORDER.filter((name) => classes.includes(name)),
        hosts: list(manifest.outbound_hosts).filter((host) => typeof host === 'string' && host !== '(expression)'),
        hostFromData: list(manifest.outbound_hosts).includes('(expression)'),
        modules: list(manifest.modules).filter((name) => typeof name === 'string' && name !== 'Core'),
        needsApproval: steps.some((step) => step?.step_type === 'approval'),
    }
}

/**
 * The safe test run as rows: `{ code, name, mode, safety, failed, error }`.
 * mode: `ran` (really computed in memory), `would` (only described), `approval` (would wait for a person).
 *
 * @param {object|null} report Preview report.
 * @param {Record<string, string>} names Step names.
 */
export function dryRunRows(report, names = {}) {
    return list(report?.dry_run?.steps).map((step) => {
        const stubbed = step?.mode === 'stubbed'
        return {
            code: String(step?.step_code ?? ''),
            name: stepName(names, String(step?.step_code ?? '')),
            mode: !stubbed ? 'ran' : step.step_type === 'approval' ? 'approval' : 'would',
            safety: SAFETY_ORDER.filter((name) => list(step?.safety).includes(name)),
            failed: !stubbed && step?.outcome !== 'success' && step?.outcome !== 'skipped',
            error: text(step?.error),
        }
    })
}

/** State of the test run: `none` (no sample in the package), `completed`, or `stopped`. */
export function dryRunState(report) {
    if (!report?.dry_run) return 'none'
    return report.dry_run.completed ? 'completed' : 'stopped'
}
