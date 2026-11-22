/**
 * Linkage-condition language shared with PHP (`Cms\Core\Admin\Resources\Condition`).
 *
 * A condition is either a leaf `{ field, op, value? }` or a group `{ all: [...] }` / `{ any: [...] }`.
 * Operators: eq, ne, in, empty, not_empty. Evaluation fails closed: an invalid node is false.
 */

const LEAF_OPS = ['eq', 'ne', 'in', 'empty', 'not_empty']

const has = (obj, key) => Object.prototype.hasOwnProperty.call(obj, key)
const isNode = (v) => v !== null && typeof v === 'object' && !Array.isArray(v)

/**
 * Validate a condition tree.
 *
 * @param {object} c Condition node.
 * @throws {Error} When the node is malformed or uses an unknown operator.
 * @returns {void}
 */
export function validateCondition(c) {
    if (!isNode(c)) {
        throw new Error('A condition node must be an object.')
    }
    const isGroup = has(c, 'all') || has(c, 'any')
    const isLeaf = has(c, 'field') || has(c, 'op')

    if (isGroup === isLeaf) {
        throw new Error('A condition node must be either a leaf or a group.')
    }

    if (isGroup) {
        if (has(c, 'all') && has(c, 'any')) {
            throw new Error('A group must use only one of "all" or "any".')
        }
        const key = has(c, 'all') ? 'all' : 'any'
        if (!Array.isArray(c[key])) {
            throw new Error(`Group "${key}" must be a list.`)
        }
        for (const child of c[key]) {
            if (!isNode(child)) {
                throw new Error('Group members must be condition nodes.')
            }
            validateCondition(child)
        }
        return
    }

    if (typeof c.field !== 'string' || c.field === '') {
        throw new Error('A leaf requires a non-empty string "field".')
    }
    if (typeof c.op !== 'string' || !LEAF_OPS.includes(c.op)) {
        throw new Error('Unknown or missing operator.')
    }
    if ((c.op === 'eq' || c.op === 'ne') && !has(c, 'value')) {
        throw new Error(`Operator "${c.op}" requires a value.`)
    }
    if (c.op === 'in' && !Array.isArray(c.value)) {
        throw new Error('Operator "in" requires a list value.')
    }
}

/** Resolve a dotted path (like Laravel data_get); missing segments yield null. */
const dataGet = (values, path) => {
    let cur = values
    for (const seg of path.split('.')) {
        if (cur === null || typeof cur !== 'object' || !has(cur, seg)) {
            return null
        }
        cur = cur[seg]
    }
    return cur === undefined ? null : cur
}

/** Normalise a value for comparison: null stays null, booleans and scalars become strings. */
const normalise = (v) => {
    if (v === null || v === undefined) return null
    if (typeof v === 'boolean') return v ? 'true' : 'false'
    if (typeof v === 'number' || typeof v === 'string') return String(v)
    return JSON.stringify(v)
}

/** Whether a value counts as empty: null, empty string, empty list or empty object. */
const isEmpty = (v) =>
    v === null ||
    v === undefined ||
    v === '' ||
    (Array.isArray(v) && v.length === 0) ||
    (isNode(v) && Object.keys(v).length === 0)

const run = (c, values) => {
    if (has(c, 'all')) {
        return c.all.every((child) => run(child, values))
    }
    if (has(c, 'any')) {
        return c.any.some((child) => run(child, values))
    }
    const actual = dataGet(values, c.field)
    switch (c.op) {
        case 'eq':
            return normalise(actual) === normalise(c.value)
        case 'ne':
            return normalise(actual) !== normalise(c.value)
        case 'in':
            return c.value.map(normalise).includes(normalise(actual))
        case 'empty':
            return isEmpty(actual)
        case 'not_empty':
            return !isEmpty(actual)
        default:
            return false
    }
}

/**
 * Evaluate a condition against a map of form values. Fails closed: an invalid node is false.
 *
 * @param {object} condition Condition tree.
 * @param {object} values Form values (dotted fields are resolved).
 * @returns {boolean}
 */
export function evaluateCondition(condition, values) {
    try {
        validateCondition(condition)
    } catch {
        return false
    }
    return run(condition, values || {})
}
