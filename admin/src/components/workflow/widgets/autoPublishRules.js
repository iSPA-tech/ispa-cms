/**
 * Rules of "auto-publish when JEV is sure" on a review step, kept free of Vue so they can be tested on their own.
 *
 * A review step that reads a JEV verdict (`jev.verdict_from`) starts with a person deciding everything while the
 * verdicts are compared with the person's decisions ("learning"). Once enough decisions agree, auto-publish may be
 * turned on: the step's draft becomes `mode: jev` with `jev.min_confidence`. `info` is the answer of
 * `GET workflows/{project}/steps/{step_code}/auto-approval`; `config` is the step's draft settings in the designer.
 */
import { STRICTNESS, strictnessOf } from './widgetRules.js'

/** The levels auto-publish may be set to (the same three as the JEV judge): `[{ key, value }]`. */
export const AUTO_LEVELS = STRICTNESS

const DEFAULT_LEVEL = 0.8

const isObject = (value) => !!value && typeof value === 'object' && !Array.isArray(value)
const count = (value) => (Number.isFinite(Number(value)) && Number(value) > 0 ? Math.floor(Number(value)) : 0)

/** Whether the step reads a verdict another step stored: only such a step can publish on its own. */
export const readsVerdict = (config) => typeof config?.jev?.verdict_from === 'string' && config.jev.verdict_from.trim() !== ''

/**
 * What the section shows: '' (nothing), 'learning', 'ready', 'on' or 'paused'.
 * The draft decides on / off (the operator sees the choice they just made); the server says whether the calibration
 * is ready and whether auto-publish paused itself.
 */
export const autoPublishState = (info, config = {}) => {
    if (!isObject(info) || info.available !== true) return ''
    const mode = config?.mode ?? info.mode
    if (mode === 'jev') return info.paused === true ? 'paused' : 'on'
    return info.ready === true ? 'ready' : 'learning'
}

/** The level in the draft, else the one the server runs with, else "medium". */
export const levelValue = (config = {}, info = null) => {
    for (const value of [config?.jev?.min_confidence, info?.min_confidence]) {
        if (value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value))) return Number(value)
    }
    return DEFAULT_LEVEL
}

/** 'easy' | 'medium' | 'strict', or '' for a level typed by hand in advanced mode. */
export const levelKey = (config = {}, info = null) => strictnessOf(levelValue(config, info))

/** Decisions still missing before auto-publish can be turned on. */
export const remainingDecisions = (info) => Math.max(0, count(info?.required) - count(info?.decided))

/** Share of the bar that is filled, 0..100. */
export const progressPercent = (info) => {
    const required = count(info?.required)
    return required ? Math.min(100, Math.round((count(info?.decided) / required) * 100)) : 0
}

/** A rate stored as 0..1 as whole percent, or null while there is no decision to compare. */
export const ratePercent = (rate) => (rate === null || rate === undefined || rate === '' || !Number.isFinite(Number(rate)) ? null : Math.round(Number(rate) * 100))

/** Whether the agreement is at or above the mark. */
export const agreementMet = (info) => {
    const rate = ratePercent(info?.agreement_rate)
    const minimum = ratePercent(info?.minimum_agreement)
    return rate !== null && minimum !== null && rate >= minimum
}

/**
 * "Of the last N reviewed items, this level would publish about A on its own; R still wait for a person":
 * `{ auto, rest, of }`, or null when the server sent no estimate for the level.
 */
export const estimateFor = (info, level) => {
    const estimate = info?.estimate
    if (!isObject(estimate)) return null
    const of = count(estimate.of)
    const hit = Object.keys(estimate).find((key) => key !== 'of' && Math.abs(Number(key) - Number(level)) < 1e-9)
    if (!of || hit === undefined || !Number.isFinite(Number(estimate[hit]))) return null
    const auto = Math.min(of, count(estimate[hit]))
    return { auto, rest: of - auto, of }
}

/** A level as the operator reads it: "0,8" in Vietnamese, "0.8" in English. */
export const levelText = (value, locale = 'vi') => {
    const number = Number(value)
    if (!Number.isFinite(number)) return ''
    return new Intl.NumberFormat(String(locale || '').startsWith('vi') ? 'vi-VN' : 'en-US', { maximumFractionDigits: 2 }).format(number)
}

const jevOf = (config) => (isObject(config?.jev) ? config.jev : {})

/** Settings to store when auto-publish is turned on at a level. Every other `jev` setting is kept. */
export const turnOnPatch = (config = {}, level = null) => ({ mode: 'jev', jev: { ...jevOf(config), min_confidence: level ?? levelValue(config) } })

/** Settings to store when auto-publish is turned off: a person decides everything again; the level is remembered. */
export const turnOffPatch = () => ({ mode: 'human' })

/** Settings to store when only the level changes. */
export const levelPatch = (config = {}, level) => ({ jev: { ...jevOf(config), min_confidence: level } })

/** Whether the draft differs from what runs now, so the choice takes effect only after publishing. */
export const waitsForPublish = (info, config = {}) => {
    if (!isObject(info) || info.available !== true) return false
    const running = info.mode === 'jev'
    const drafted = (config?.mode ?? info.mode) === 'jev'
    if (running !== drafted) return true
    return drafted && Math.abs(levelValue(config, info) - levelValue({}, info)) > 1e-9
}

/**
 * Second line of the step card: `{ key, params }`, or null for a step that cannot publish on its own.
 * `info` may still be unknown (not loaded, a new step): the card then says who decides without the counts.
 */
export const cardSubtitle = (config = {}, info = null, locale = 'vi') => {
    if (!readsVerdict(config)) return null
    if (isObject(info) && info.available === false) return null
    const known = isObject(info) && info.available === true
    if ((config?.mode ?? (known ? info.mode : 'human')) === 'jev') {
        if (known && info.paused === true) return { key: 'text.workflow.auto_publish.card_paused', params: {} }
        return { key: 'text.workflow.auto_publish.card_on', params: { level: levelText(levelValue(config, info), locale) } }
    }
    if (!known) return { key: 'text.workflow.auto_publish.card_learning_plain', params: {} }
    return { key: 'text.workflow.auto_publish.card_learning', params: { decided: count(info.decided), required: count(info.required) } }
}

/** Whether a refused save / publish says auto-publish was turned on before the calibration was ready. */
export const isNotReadyRefusal = (input) => {
    const body = input?.response?.data ?? input
    if (!isObject(body)) return false
    const CODE = 'auto_approval_not_ready'
    const rows = [...(Array.isArray(body.data?.errors) ? body.data.errors : []), ...(Array.isArray(body.data?.problems) ? body.data.problems : [])]
    return [body.code, body.error, body.data?.code, body.data?.error, body.message].includes(CODE) || rows.some((row) => row?.code === CODE)
}

// ---------------------------------------------------------------------------------------------------------------------
// Approvals inbox
// ---------------------------------------------------------------------------------------------------------------------

const VERDICTS = ['pass', 'fail', 'unsure']
const HELD_REASONS = ['learning', 'below_threshold', 'flagged', 'jev_unsure', 'jev_missing', 'paused']

/** The JEV part of an approval row: the API field first, else what the step stored on the row. */
export const approvalJev = (approval) => {
    const jev = isObject(approval?.jev) ? approval.jev : approval?.diff_data?.workflow_node?.jev
    return isObject(jev) ? jev : null
}

/** Why the row waits for a person, or '' when nothing known says so. */
export const approvalHeldReason = (approval) => {
    const reason = approval?.held_reason ?? approval?.diff_data?.workflow_node?.held_reason
    return HELD_REASONS.includes(reason) ? reason : ''
}

/** Whether JEV, not a person, approved the row. */
export const decidedByJev = (approval) => approval?.diff_data?.workflow_node?.decided_by === 'jev'

/**
 * The JEV chip of a row: `{ verdict, verdictKey, level, color }`, or null without a known verdict.
 * `level` is the probability as text ('' when JEV gave none, or was not sure: "Unsure · sure 0.55" reads wrong); the
 * colour follows the verdict.
 */
export const jevChip = (approval, locale = 'vi') => {
    const jev = approvalJev(approval)
    if (!jev || !VERDICTS.includes(jev.verdict)) return null
    const known = jev.verdict !== 'unsure' && jev.probability !== null && jev.probability !== undefined && jev.probability !== '' && Number.isFinite(Number(jev.probability))
    return {
        verdict: jev.verdict,
        verdictKey: `text.agentic.workflow_node.jev_verdict_${jev.verdict}`,
        level: known ? levelText(Number(jev.probability), locale) : '',
        color: { pass: 'green', fail: 'red', unsure: 'gold' }[jev.verdict],
    }
}

/** Why the row waits, in the operator's words: `{ key, params }`, or null. Only a row still waiting says it. */
export const heldReasonLine = (approval, locale = 'vi') => {
    if (approval?.status !== 'pending') return null
    const reason = approvalHeldReason(approval)
    if (!reason) return null
    const jev = approvalJev(approval)
    const minimum = jev?.min_confidence
    if (reason === 'below_threshold' && (minimum === null || minimum === undefined || !Number.isFinite(Number(minimum)))) {
        return { key: 'text.agentic.auto_publish.held.below_threshold_plain', params: {} }
    }
    return { key: `text.agentic.auto_publish.held.${reason}`, params: reason === 'below_threshold' ? { level: levelText(minimum, locale) } : {} }
}
