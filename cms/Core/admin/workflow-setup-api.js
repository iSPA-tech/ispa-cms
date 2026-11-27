/**
 * API calls and rules of the workflow setup screen: the few things a site must decide after a sample workflow is
 * installed (who reviews, who is told, when it runs, which connected account a step uses). A workflow is named by its uuid.
 *
 * Requests are sent with `silent: true`: the screen shows its own translated message instead of the raw server text.
 * The rules below are kept free of Vue so they can be tested on their own.
 */

import ajax from '@/ajax'

const quiet = { silent: true }
const path = (projectUuid) => `workflows/${encodeURIComponent(projectUuid)}/setup`

const shape = (body) => ({
    complete: body?.complete === true,
    questions: Array.isArray(body?.questions) ? body.questions.filter((row) => row?.key && row?.kind) : [],
    preset: Array.isArray(body?.preset) ? body.preset.filter((row) => row?.label) : [],
})

/** Setup questions of a workflow: `{ complete, questions: [{ key, kind, step_code?, field?, label, help, required, answered, current }], preset: [{ label }] }`. */
export const fetchWorkflowSetup = async (projectUuid) => shape((await ajax.get(path(projectUuid), quiet)).data?.data)

/**
 * Save answers (`{ <question key>: value }`) into the draft. Resolves with the new setup state; rejects with the
 * request error (`setupProblems(error)` lists what the server refused, per question).
 */
export const saveWorkflowSetup = async (projectUuid, answers) => shape((await ajax.put(path(projectUuid), { answers: wireAnswers(answers) }, quiet)).data?.data)

/**
 * Who a notice step would reach with an answer on screen (`{ permission, users }`): `{ count, people: [{ uuid, name }], more }`.
 */
export const fetchNoticeRecipients = async (projectUuid, answer = {}) => {
    const params = { ...(answer?.permission ? { permission: answer.permission } : {}), ...(answer?.users?.length ? { users: answer.users } : {}) }
    const body = (await ajax.get(`${path(projectUuid)}/recipients`, { params, silent: true })).data?.data
    return { count: Number(body?.count) || 0, people: Array.isArray(body?.people) ? body.people : [], more: Number(body?.more) || 0 }
}

/** Whether a question is "who is told" of a notice step (answered with people and/or everyone who may see a kind of record). */
export const isNoticeQuestion = (question) => question?.kind === 'notify_channel' && question?.field === 'permission'

/** Answers as the server takes them: "only when someone presses run" is a schedule without a cron. */
export const wireAnswers = (answers = {}) =>
    Object.fromEntries(
        Object.entries(answers).map(([key, value]) => [key, value && typeof value === 'object' && !Array.isArray(value) && 'cron' in value ? { ...value, cron: value.cron || null } : value])
    )

/** What a refused save names: `[{ key, code, message, step_code? }]`. */
export const setupProblems = (error) => {
    const rows = error?.response?.data?.data?.errors
    return Array.isArray(rows) ? rows : []
}

/** The workflow a setup screen is for, found by its slug (the screen address) or its uuid: the row of the workflows list, or null. */
export const findWorkflow = async (key) => {
    const rows = (await ajax.get('workflows', quiet)).data?.data
    const wanted = String(key ?? '')
    return (Array.isArray(rows) ? rows : []).find((row) => row?.slug === wanted || (wanted !== '' && row?.uuid === wanted)) || null
}

/**
 * A safe test run of the workflow's draft on this site, with its sample data (or an empty one): the same walk-through as
 * before installing a package. Nothing is sent, published or spent. Resolves with the report; rejects with the request
 * error (`setupIncomplete(error)` says the questions must be answered first).
 */
export const safeTestRun = async (projectUuid) => (await ajax.post(`${path(projectUuid)}/test-run`, {}, quiet)).data?.data

/** True when a test run was refused because a setup question is still unanswered. */
export const setupIncomplete = (error) => error?.response?.data?.error_code === 'setup_incomplete'

// ---------------------------------------------------------------------------------------------------------------------
// Rules
// ---------------------------------------------------------------------------------------------------------------------

/** Schedule choices of the setup screen. `cron: ''` = only when someone presses run. */
export const SCHEDULE_CHOICES = [
    { key: 'daily', cron: '0 6 * * *' },
    { key: 'weekly', cron: '0 6 * * 1' },
    { key: 'manual', cron: '' },
]

const isEmpty = (value) =>
    value === null || value === undefined || (typeof value === 'string' && value.trim() === '') || (Array.isArray(value) && value.length === 0)

/** The answer a question starts with, in the shape the server takes back. */
export const initialAnswer = (question) => {
    const current = question?.current
    switch (question?.kind) {
        case 'reviewers':
            return { permission: current?.permission || '', assignees: Array.isArray(current?.assignees) ? [...current.assignees] : [] }
        case 'schedule':
            // Not answered yet: nothing is chosen (neither a time nor "only by hand").
            if (!question.answered) return { cron: null, timezone: current?.timezone || null }
            return { cron: current?.by_hand ? '' : current?.cron || '', timezone: current?.timezone || null }
        case 'credential':
            return typeof current === 'string' ? current : ''
        case 'choice':
            // A yes/no of the sample (an optional branch): true or false, never empty.
            return current === true
        case 'notify_channel':
            if (isNoticeQuestion(question)) {
                // The suggestion is not filled in silently: the screen shows it as people's names to accept with one click.
                const permission = typeof current === 'string' ? current : current?.permission || ''
                return { permission, users: Array.isArray(current?.users) ? [...current.users] : [] }
            }
            return current ?? ''
        default:
            return current ?? ''
    }
}

/** Whether an answer is enough to count the question as done. */
export const isAnswered = (question, answer) => {
    switch (question?.kind) {
        case 'reviewers':
            return !isEmpty(answer?.permission) || !isEmpty(answer?.assignees)
        case 'schedule':
            return typeof answer?.cron === 'string'
        case 'notify_channel':
            // A bare string is a permission (the shape before people could be named).
            if (isNoticeQuestion(question)) return typeof answer === 'string' ? !isEmpty(answer) : !isEmpty(answer?.permission) || !isEmpty(answer?.users)
            return !isEmpty(answer)
        default:
            return !isEmpty(answer)
    }
}

/** `{ done, total }` over the questions that must be answered. */
export const setupProgress = (questions = [], answers = {}) => {
    const required = questions.filter((question) => question.required)
    return { done: required.filter((question) => isAnswered(question, answers[question.key])).length, total: required.length }
}

/** Number of required questions the server still counts as open (for the badge on the workflows list). */
export const openQuestionCount = (setup) => (setup?.questions || []).filter((question) => question.required && !question.answered).length

/** The answers that differ from what is stored: only those are sent. */
export const changedAnswers = (questions = [], answers = {}) =>
    Object.fromEntries(
        questions
            .filter((question) => isAnswered(question, answers[question.key]) && JSON.stringify(answers[question.key]) !== JSON.stringify(question.answered ? initialAnswer(question) : null))
            .map((question) => [question.key, answers[question.key]])
    )
