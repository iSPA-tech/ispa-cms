/**
 * Which steps still wait for a choice only this site can make, kept free of Vue so it can be tested on its own.
 *
 * Node schemas mark such settings: `x-required` (a run cannot do without it) and `x-setup: <kind>` (`reviewers`,
 * `notify_channel`, `credential`, `required_field`); `x-setup-when: { setting: [values] }` limits a mark to some
 * values of another setting (a credential only when the step signs in). The server lists the same as setup questions
 * (`GET workflows/{project}/setup`); the designer checks the settings as they are on screen, so the mark on a step
 * card clears as soon as the choice is made.
 */

export const isEmptySetting = (value) =>
    value === null ||
    value === undefined ||
    (typeof value === 'string' && value.trim() === '') ||
    (Array.isArray(value) && value.length === 0) ||
    (typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0)

const applies = (schema, config) => {
    const when = schema?.['x-setup-when']
    if (!when || typeof when !== 'object') return true
    return Object.entries(when).every(([name, allowed]) => (Array.isArray(allowed) ? allowed : [allowed]).includes(config?.[name]))
}

/** Names of the settings of a step that must be chosen and are still empty. */
export const missingSettings = (definition, config = {}) => {
    const properties = definition?.config_schema?.properties
    if (!properties || typeof properties !== 'object' || Array.isArray(properties)) return []
    return Object.entries(properties)
        .filter(([name, schema]) => {
            if (!schema || typeof schema !== 'object' || !applies(schema, config)) return false
            const kind = schema['x-setup']
            const must = schema['x-required'] === true || kind === 'credential' || kind === 'required_field' || kind === 'reviewers'
            if (!must || !isEmptySetting(config[name])) return false
            // Who reviews is answered by a permission or by named people.
            if (kind === 'reviewers') return isEmptySetting(config.reviewer_permission) && isEmptySetting(config.assignees)
            return true
        })
        .map(([name]) => name)
}

/**
 * `{ [stepId]: true }` for the steps with a required choice still open.
 * - `nodes`: designer nodes; `byType`: node catalog by step type
 * - `questions`: setup questions of the server (`[{ step_code, field, required, answered }]`), for requirements the
 *   schema does not carry; one that names a setting is re-checked against the settings on screen
 */
export const stepsNeedingSetup = (nodes = [], byType = {}, questions = []) => {
    const open = {}
    const byCode = {}
    ;(Array.isArray(questions) ? questions : []).forEach((question) => {
        if (question?.required && question.step_code) (byCode[question.step_code] ||= []).push(question)
    })
    nodes.forEach((node) => {
        const config = node?.data?.step_config && typeof node.data.step_config === 'object' ? node.data.step_config : {}
        if (missingSettings(byType?.[node?.data?.step_type], config).length) {
            open[node.id] = true
            return
        }
        const asked = byCode[node?.data?.step_code || node?.id] || []
        if (asked.some((question) => (question.field ? isEmptySetting(config[question.field]) && !question.answered : !question.answered))) open[node.id] = true
    })
    return open
}
