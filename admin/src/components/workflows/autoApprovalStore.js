/**
 * Where "auto-publish when JEV is sure" stands for the review steps on the canvas, asked once per step and shared by
 * the step card (its second line) and the step form (its section). The answers live in `canvasUi.autoApproval`.
 */
import { fetchAutoApproval } from '@/services/workflowApi'
import { canvasUi } from './canvasUi.js'

const keyOf = (projectId, stepCode) => `${projectId}:${stepCode}`

/**
 * Ask once where auto-publish stands for a step; `force` asks again.
 * A failed request (a step never saved, an older server) is remembered as "not available".
 */
export const loadAutoApproval = async (projectId, stepCode, { force = false } = {}) => {
    if (!projectId || !stepCode) return null
    const key = keyOf(projectId, stepCode)
    if (!force && canvasUi.autoApprovalAsked[key]) return canvasUi.autoApproval[stepCode] ?? null
    canvasUi.autoApprovalAsked[key] = true
    let info
    try {
        info = await fetchAutoApproval(projectId, stepCode)
    } catch (error) {
        info = { available: false, failed: true }
    }
    // The canvas may have been closed, or opened on another workflow, while the answer was on its way.
    if (canvasUi.autoApprovalAsked[key]) canvasUi.autoApproval = { ...canvasUi.autoApproval, [stepCode]: info }
    return info
}

/** Ask again for every step already asked about (after a save or a publish). */
export const refreshAutoApproval = () => {
    const projectId = canvasUi.projectId
    if (!projectId) return
    Object.keys(canvasUi.autoApproval).forEach((stepCode) => loadAutoApproval(projectId, stepCode, { force: true }))
}
