/**
 * "When this step fails" for the step settings form. The choice is stored as links only, so the canvas and the
 * form always agree:
 * - `stop`: no error link (the run fails)
 * - `continue`: the error link goes to the same step as the next output
 * - `route`: the error link goes to a step of its own; with no link yet the error output is shown on the card for
 *   the operator to connect, and the mode reads `stop` until they do
 *
 * Use inside the designer (the canvas store is injected):
 *   const { modeOf, canContinue, setMode } = useStepErrorPath()
 */
import { useVueFlow } from '@vue-flow/core'
import { canvasUi, notifyGraphEdited } from './canvasUi'
import { canvasEdge, errorModeOf, mainTargetOf, planErrorMode } from './graphRules'

export const useStepErrorPath = () => {
    const { getEdges, addEdges, removeEdges } = useVueFlow()
    const edges = () => getEdges.value || []

    /** `stop | continue | route` of the step, read from its links (reactive when used in a computed). */
    const modeOf = (nodeId) => errorModeOf(nodeId, edges())

    /** Whether "skip and carry on" can be chosen: the step's next output leads to a step. */
    const canContinue = (nodeId) => !!mainTargetOf(nodeId, edges())

    /**
     * Set the mode. Returns false when nothing was changed because the mode cannot be set (see `canContinue`).
     * The change is one undo step and marks the draft as unsaved, like any canvas edit.
     */
    const setMode = (nodeId, mode) => {
        if (canvasUi.readOnly) return false
        const plan = planErrorMode(nodeId, mode, edges())
        if (!plan) return false
        if (plan.removedIds.length) removeEdges(plan.removedIds)
        if (plan.add.length) addEdges(plan.add.map(canvasEdge))
        // The operator has to see the error output to connect it.
        if (mode === 'route') canvasUi.showErrors = true
        if (plan.removedIds.length || plan.add.length) notifyGraphEdited()
        return true
    }

    return { modeOf, canContinue, setMode }
}
