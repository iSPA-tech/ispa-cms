/**
 * Pure helpers of the workflow guardrails screens: they turn what the versions API answers into what an operator reads
 * (plain sentences for sensitive changes, for a diff, for a paused workflow and for what a publish will do) and decide
 * which buttons an account gets.
 *
 * Nothing here calls the API or the DOM, so every rule is unit-tested.
 */

import dayjs from 'dayjs'
import { describeProblems } from './workflow-package-report'

const list = (value) => (Array.isArray(value) ? value : [])
const text = (value) => (typeof value === 'string' ? value.trim() : '')
const stepName = (names, code) => names?.[code] || ''

// ---------------------------------------------------------------------------------------------------------------------
// Sensitive changes (preview-publish, publish answers, the waiting list)
// ---------------------------------------------------------------------------------------------------------------------

/** Change kinds with their own sentence (`text.workflow.guard.change.<kind>`); any other kind reads as `other`. */
export const CHANGE_KINDS = [
    'trigger_changed',
    'sensitive_step_added',
    'sensitive_step_removed',
    'host_added',
    'credential_changed',
    'write_target_changed',
    'approval_removed',
    'approval_mode_changed',
    'budget_raised',
]

/** Kinds whose `detail` is a name worth reading (an address, the name of saved login details). */
const NAMED_DETAIL_KINDS = ['host_added', 'credential_changed']

/**
 * One sensitive change as an operator sentence: `{ kind, text, step, detail }`.
 * `step` is the step's name (never its code); `detail` only carries a short name, never a config value.
 *
 * @param {{kind?: string, step_code?: string, detail?: unknown}} change Row of `changes`.
 * @param {{t: Function, names?: Record<string, string>}} ctx Translator and step names by step code.
 */
export function describeChange(change, { t, names = {} } = {}) {
    const kind = CHANGE_KINDS.includes(change?.kind) ? change.kind : 'other'
    const raw = text(change?.detail)
    const detail = NAMED_DETAIL_KINDS.includes(kind) && raw && raw.length <= 120 && !/[{}\[\]]/.test(raw) ? raw : ''
    return {
        kind,
        text: t(`text.workflow.guard.change.${kind}`),
        step: change?.step_code ? stepName(names, String(change.step_code)) : '',
        detail,
    }
}

/** All sensitive changes as sentences, without repeating the same sentence for the same step and detail. */
export function describeChanges(changes, ctx) {
    const seen = new Set()
    return list(changes)
        .map((change) => describeChange(change, ctx))
        .filter((row) => {
            const id = `${row.kind}|${row.step}|${row.detail}`
            if (seen.has(id)) return false
            seen.add(id)
            return true
        })
}

// ---------------------------------------------------------------------------------------------------------------------
// What a publish does
// ---------------------------------------------------------------------------------------------------------------------

const PATHS = ['published', 'unchanged', 'pending_review', 'cooling_off']

/**
 * The path a publish takes, read from a preview or from a publish answer. An answer without `path` (a server that has
 * no change control) is read from the version status.
 */
export function publishPath(payload) {
    if (payload?.unchanged === true) return 'unchanged'
    if (PATHS.includes(payload?.path)) return payload.path
    if (['pending_review', 'cooling_off'].includes(payload?.status)) return payload.status
    return 'published'
}

/** Clock time (HH:mm) of an ISO time, with the date when it is not today. */
export function clockTime(iso, now = new Date()) {
    const at = dayjs(iso)
    if (!iso || !at.isValid()) return ''
    return at.isSame(dayjs(now), 'day') ? at.format('HH:mm') : at.format('HH:mm DD/MM')
}

/**
 * What will happen (before) or what happened (after) for a publish: `{ path, tone, text, time }`.
 *
 * @param {object} payload Preview (`path`, `cooling_off_minutes`) or publish answer (`path`, `effective_at`, `number`).
 * @param {{t: Function, done?: boolean, now?: Date}} ctx `done` picks the sentences of a finished publish.
 */
export function publishOutcome(payload, { t, done = false, now = new Date() } = {}) {
    const path = publishPath(payload)
    const stage = done ? 'done' : 'will'
    const minutes = Number(payload?.cooling_off_minutes) > 0 ? Number(payload.cooling_off_minutes) : 60
    const effective = payload?.effective_at || (path === 'cooling_off' && !done ? dayjs(now).add(minutes, 'minute').toISOString() : '')
    const time = clockTime(effective, now)
    const tone = { published: 'success', unchanged: 'info', pending_review: 'warning', cooling_off: 'warning' }[path]
    const key = path === 'cooling_off' && !time ? 'cooling_off_no_time' : path
    return { path, tone, time, text: t(`text.workflow.guard.outcome.${stage}.${key}`, { time, minutes, number: payload?.number ?? '' }) }
}

/** Label key of the confirm button for each path. */
export const publishButtonKey = (path) =>
    ({ pending_review: 'text.workflow.guard.publish.send_for_review', cooling_off: 'text.workflow.guard.publish.confirm_wait' })[path] ||
    'text.workflow.guard.publish.publish_now'

/**
 * Problems of a refused publish as sentences `{ code, text, steps }`, the way the designer shows a refused save.
 * Codes the package page already words are reused; publish-only codes have their own sentence.
 */
export function publishProblems(problems, { t, names = {} } = {}) {
    const own = ['too_many_steps', 'loop_limit_exceeded', 'publish_needs_approval', 'host_not_allowlisted', 'auto_approval_not_ready']
    const alias = { unknown_type: 'unknown_node_type', class_not_allowed: 'callable_not_allowed' }
    const rows = list(problems).map((row) => {
        // The publish policy names the address only in its message; the sentence should name it too.
        const host = text(row?.host) || (/outbound host (\S+) is not/i.exec(text(row?.message)) || [])[1]
        return { ...row, code: alias[row?.code] || row?.code, ...(host ? { host } : {}) }
    })
    const shared = describeProblems(rows.filter((row) => !own.includes(row.code)), { t, names })
    const special = rows
        .filter((row) => own.includes(row.code))
        .map((row) => ({
            code: row.code,
            // An address the site may not contact: name it when the server did.
            text: t(`text.workflow.guard.problem.${row.code === 'host_not_allowlisted' && !row.host ? 'host_not_allowlisted_plain' : row.code}`, row.host ? { host: row.host } : {}),
            steps: row.step_code ? [stepName(names, String(row.step_code)) || String(row.step_code)] : [],
        }))
    return [...special, ...shared].map((row) => ({ code: row.code, text: row.text || t('text.workflow.guard.problem.other'), steps: row.steps }))
}

/** Rows for the designer's node cards (`recordSaveErrors`): the translated sentence on each step named by a problem. */
export function problemsForNodes(problems, ctx) {
    return list(problems)
        .filter((row) => row?.step_code)
        .map((row) => ({ step_code: String(row.step_code), code: row.code, message: publishProblems([row], ctx)[0]?.text || '' }))
}

// ---------------------------------------------------------------------------------------------------------------------
// Version history
// ---------------------------------------------------------------------------------------------------------------------

const STATUS_TONES = { published: 'green', superseded: 'default', pending_review: 'orange', cooling_off: 'orange', cancelled: 'default', rejected: 'red' }

/** Tag of a version: `{ color, key }`; the version that runs is always "running", an unknown status reads as "other". */
export function versionStatus(version) {
    if (version?.is_published) return { color: 'green', key: 'text.workflow.guard.status.running' }
    const status = Object.keys(STATUS_TONES).includes(version?.status) ? version.status : 'other'
    // A `published` row that no longer runs was replaced by a later one.
    const shown = status === 'published' ? 'superseded' : status
    return { color: STATUS_TONES[shown] || 'default', key: `text.workflow.guard.status.${shown}` }
}

/** A version an operator may return to: one that ran before (not the one that runs, not one that never went live). */
export const canRollbackTo = (version) => !version?.is_published && ['superseded', 'published'].includes(version?.status)

/** Step fields with their own wording; `position_x` / `position_y` are one "moved" line. */
const FIELD_KEYS = { name: 'renamed', step_type: 'type_changed', is_start: 'start_changed', input_schema: 'inputs_changed', output_schema: 'outputs_changed' }
const SETTING_KEYS = [
    'trigger_event',
    'target_entity_type',
    'schedule_cron',
    'schedule_timezone',
    'error_workflow_project_id',
    'budget_max_cost_per_run',
    'budget_max_runs_per_day',
]

/** Keys the designer writes into a step's settings for its own bookkeeping; an operator never set them. */
const BOOKKEEPING_KEYS = ['node_type']

/** A setting key as words ("max_items" → "max items"); only names are shown, never values. */
const settingName = (key) => String(key).replace(/([a-z0-9])([A-Z])/g, '$1 $2').replace(/[_.]+/g, ' ').trim().toLowerCase()

/**
 * A diff as operator sentences: `[{ key, tone, text, detail }]`, steps first, then links, then run settings.
 * Steps that only moved on the canvas are counted in one last line. Config changes list setting names only.
 *
 * @param {object} diff `fetchVersionDiff` answer (from the version to what it is compared with).
 * @param {{t: Function, names?: Record<string, string>}} ctx Translator and step names by step code.
 */
export function diffRows(diff, { t, names = {} } = {}) {
    const rows = []
    const label = (row) => text(row?.name) || stepName(names, String(row?.step_code ?? '')) || t('text.workflow.guard.diff.unnamed_step')

    list(diff?.steps?.added).forEach((row) => rows.push({ key: `a:${row.step_code}`, tone: 'added', text: t('text.workflow.guard.diff.step_added', { step: label(row) }), detail: '' }))
    list(diff?.steps?.removed).forEach((row) => rows.push({ key: `r:${row.step_code}`, tone: 'removed', text: t('text.workflow.guard.diff.step_removed', { step: label(row) }), detail: '' }))

    let moved = 0
    list(diff?.steps?.changed).forEach((row) => {
        const fields = list(row.fields)
        const parts = Object.keys(FIELD_KEYS)
            .filter((field) => fields.includes(field))
            .map((field) => t(`text.workflow.guard.diff.field.${FIELD_KEYS[field]}`))
        const keys = list(row.config_keys)
            .filter((key) => !BOOKKEEPING_KEYS.includes(key) && !String(key).startsWith('__'))
            .map(settingName)
            .filter(Boolean)
        if (!parts.length && !keys.length) {
            if (fields.some((field) => field.startsWith('position_'))) moved += 1
            return
        }
        if (keys.length) parts.push(t('text.workflow.guard.diff.field.settings', { count: keys.length }))
        rows.push({
            key: `c:${row.step_code}`,
            tone: 'changed',
            text: t('text.workflow.guard.diff.step_changed', { step: label(row), what: parts.join(', ') }),
            detail: keys.join(', '),
        })
    })

    const link = (row) => ({ from: stepName(names, String(row?.from ?? '')) || t('text.workflow.guard.diff.unnamed_step'), to: stepName(names, String(row?.to ?? '')) || t('text.workflow.guard.diff.unnamed_step') })
    // A link between the same two steps on both sides was not added and removed: only its condition changed.
    const pair = (row) => `${row?.from}>${row?.to}`
    const addedLinks = list(diff?.connections?.added)
    const removedLinks = list(diff?.connections?.removed)
    const addedPairs = new Set(addedLinks.map(pair))
    const removedPairs = new Set(removedLinks.map(pair))
    const changedPairs = new Set()
    addedLinks.forEach((row, i) => {
        if (!removedPairs.has(pair(row))) return rows.push({ key: `la:${i}`, tone: 'added', text: t('text.workflow.guard.diff.link_added', link(row)), detail: '' })
        if (changedPairs.has(pair(row))) return undefined
        changedPairs.add(pair(row))
        return rows.push({ key: `lc:${i}`, tone: 'changed', text: t('text.workflow.guard.diff.link_changed', link(row)), detail: '' })
    })
    removedLinks.forEach((row, i) => {
        if (!addedPairs.has(pair(row))) rows.push({ key: `lr:${i}`, tone: 'removed', text: t('text.workflow.guard.diff.link_removed', link(row)), detail: '' })
    })

    list(diff?.settings).forEach((row) => {
        const known = SETTING_KEYS.includes(row?.key)
        rows.push({ key: `s:${row?.key}`, tone: 'changed', text: t(`text.workflow.guard.diff.setting.${known ? row.key : 'other'}`), detail: '' })
    })

    if (moved) rows.push({ key: 'moved', tone: 'muted', text: t('text.workflow.guard.diff.moved_only', { count: moved }), detail: '' })
    return rows
}

/** step_code => name of a version graph or of the designer's nodes (`step_code` / `data.step_code`, `name` / `data.name`). */
export function stepNames(steps) {
    const names = {}
    list(steps).forEach((step) => {
        const code = step?.step_code ?? step?.data?.step_code
        const name = text(step?.name) || text(step?.data?.name) || text(step?.data?.label)
        if (code !== undefined && code !== null && name) names[String(code)] = name
    })
    return names
}

// ---------------------------------------------------------------------------------------------------------------------
// Waiting changes
// ---------------------------------------------------------------------------------------------------------------------

/**
 * When a waiting change goes live, for its row: `{ key, params }`.
 * A change in its waiting time shows the clock time and the minutes left; one that waits for a person says so.
 */
export function pendingWhen(row, now = new Date()) {
    if (row?.status !== 'cooling_off') return { key: 'text.workflow.guard.pending.waits_for_person', params: {} }
    const time = clockTime(row.effective_at, now)
    if (!time) return { key: 'text.workflow.guard.pending.waits_for_time_unknown', params: {} }
    const minutes = Math.ceil(dayjs(row.effective_at).diff(dayjs(now), 'second') / 60)
    if (minutes <= 0) return { key: 'text.workflow.guard.pending.goes_live_soon', params: { time } }
    return { key: 'text.workflow.guard.pending.goes_live_at', params: { time, minutes } }
}

// ---------------------------------------------------------------------------------------------------------------------
// Paused workflows
// ---------------------------------------------------------------------------------------------------------------------

const BREAKERS = ['runs_per_hour', 'actions_per_hour', 'cost_per_hour', 'error_rate']

/**
 * Why a workflow is paused: `null` when it is not, else `{ kind: 'breaker' | 'integrity' | 'other', reasonKey, canResume }`.
 * Only a pause by a safety limit can be lifted with "turn back on"; a changed stored version must be published again.
 */
export function pausedInfo(workflow) {
    const reason = text(workflow?.paused_reason)
    if (!reason) return null
    if (reason === 'integrity') return { kind: 'integrity', reasonKey: 'text.workflow.guard.paused.reason.integrity', canResume: false }
    if (reason.startsWith('breaker:')) {
        const limit = reason.slice('breaker:'.length)
        return { kind: 'breaker', reasonKey: `text.workflow.guard.paused.reason.${BREAKERS.includes(limit) ? limit : 'breaker_other'}`, canResume: true }
    }
    return { kind: 'other', reasonKey: 'text.workflow.guard.paused.reason.other', canResume: false }
}

// ---------------------------------------------------------------------------------------------------------------------
// Abilities and states of one workflow row
// ---------------------------------------------------------------------------------------------------------------------

/**
 * What the signed-in account may do with a workflow. The row's own answers (`can_publish`, `can_run`) win; a row
 * without them (an older server) falls back to the account's permissions.
 *
 * @param {object|null} workflow Project row.
 * @param {(key: string) => boolean} rule Permission check.
 */
export function workflowAbilities(workflow, rule = () => false) {
    const managed = Boolean(workflow?.system_managed)
    const pick = (flag, key) => (typeof workflow?.[flag] === 'boolean' ? workflow[flag] : Boolean(rule(key)))
    return {
        canEdit: !managed && Boolean(rule('workflow_project.update')),
        canPublish: !managed && pick('can_publish', 'workflow_project.publish'),
        canRun: pick('can_run', 'workflow_project.run'),
    }
}

/**
 * Version state of a row for its tags: `{ known, published, number, draftDiffers, pending }`.
 * `known` is false when the server did not say (the tags are then left out instead of guessed).
 */
export function versionState(workflow) {
    const known = workflow ? Object.prototype.hasOwnProperty.call(workflow, 'published_version_id') : false
    const published = known && workflow.published_version_id !== null && workflow.published_version_id !== undefined
    return {
        known,
        published,
        number: published ? workflow.published_version_number ?? null : null,
        draftDiffers: published && workflow.draft_differs === true,
        pending: Number(workflow?.pending_versions) > 0 ? Number(workflow.pending_versions) : 0,
    }
}

/** True when turning the workflow on must be refused with the "publish it first" explanation. */
export const needsPublishBeforeOn = (workflow) => {
    const state = versionState(workflow)
    return state.known && !state.published
}
