import { errorModeOf, isEndNode, isStartNode, mainTargetOf, planErrorMode, stepHandles } from '@/components/workflows/graphRules.js'

/**
 * "When this step fails" of the step form. The choice is a link of the diagram, never a step setting: the engine
 * reads it from the step's `error` link. The rules live with the other canvas rules (graphRules.js):
 * - `errorModeOf(nodeId, edges)` → `stop` (no error link) | `continue` (error link to the step the "next" output
 *   leads to) | `route` (error link to a step of its own)
 * - `planErrorMode(nodeId, mode, edges)` → `{ add, removedIds }` for the designer's graph store, null when the mode
 *   cannot be set
 * - `mainTargetOf(nodeId, edges)` → the step the "next" output leads to, or null
 */
export const ON_ERROR_STOP = 'stop'
export const ON_ERROR_SKIP = 'continue'
export const ON_ERROR_OWN = 'route'

export { errorModeOf, mainTargetOf, planErrorMode }

/** Whether the step can hand a failure to another step at all (a trigger and an end step cannot). */
export const offersOnError = (node, definition = null, byType = {}) => {
    if (!node) return false
    const handles = stepHandles(definition, node.data?.step_config || {}, [], { start: isStartNode(node, byType), end: isEndNode(node) })
    return handles.some((handle) => handle.kind === 'error')
}

/** The step an own error path leads to, or null. */
export const errorTargetOf = (nodeId, edges = []) =>
    (Array.isArray(edges) ? edges : []).find((edge) => edge.source === nodeId && edge.sourceHandle === 'error')?.target ?? null
