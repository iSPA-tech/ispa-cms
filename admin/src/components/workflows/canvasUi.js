/**
 * State the workflow designer shares between its canvas, its step cards and the step settings.
 * One designer is open at a time, so one copy is enough (the palette's drag state in useDnd.js works the same way).
 */
import { reactive } from 'vue'

export const canvasUi = reactive({
    /** The toolbar switch "Error paths": shows every error output and error link. */
    showErrors: false,
    /** A workflow that cannot be edited: no output can be dragged, no "add step" is offered. */
    readOnly: false,
    /** `{ [stepId]: loopStepId }`: the loop that repeats each step (graphLayout.enclosingLoops). */
    loopOf: {},
    /**
     * The open step picker, or null: `{ source, handle, left, top, point }`.
     * `left` / `top` place it inside the canvas box; `point` is where the new card goes (null = under its source).
     */
    picker: null,
    /** `{ [stepId]: true }`: steps with a required choice still open (setupRules.stepsNeedingSetup). */
    needsSetup: {},
    /** The arrowhead being dragged to another step, or null: `{ edgeId, source, over }` (`over` = the step under it). */
    relink: null,
    /** Id of the workflow on the canvas, for what a step card asks the server about itself. */
    projectId: null,
    /** `{ [stepCode]: info }`: where auto-publish stands for each review step that reads a JEV verdict. */
    autoApproval: {},
    /** `{ ["project:stepCode"]: true }`: what was already asked (autoApprovalStore.js). */
    autoApprovalAsked: {},
})

/** Listeners told that the links were changed from outside the canvas (the step settings). */
const editListeners = new Set()

/** Run `listener()` after every such change. Returns the function that stops it. */
export const onGraphEdited = (listener) => {
    editListeners.add(listener)
    return () => editListeners.delete(listener)
}

export const notifyGraphEdited = () => editListeners.forEach((listener) => listener())

/** Ask for the step picker of one output. `event` is the click that asked, so the picker opens beside it. */
export const requestStepPicker = (source, handle, event = null) => {
    if (canvasUi.readOnly) return
    canvasUi.picker = { source, handle, client: event ? { x: event.clientX, y: event.clientY } : null, point: null }
}

/** Listeners told that an arrowhead was dropped on a step: `listener(edgeId, targetId)`. */
const relinkListeners = new Set()
export const onRelink = (listener) => {
    relinkListeners.add(listener)
    return () => relinkListeners.delete(listener)
}
export const requestRelink = (edgeId, targetId) => !canvasUi.readOnly && relinkListeners.forEach((listener) => listener(edgeId, targetId))

export const resetCanvasUi = () => {
    canvasUi.showErrors = false
    canvasUi.readOnly = false
    canvasUi.loopOf = {}
    canvasUi.picker = null
    canvasUi.needsSetup = {}
    canvasUi.relink = null
    canvasUi.projectId = null
    canvasUi.autoApproval = {}
    canvasUi.autoApprovalAsked = {}
}

/** Tailwind classes of an output by its tone (graphRules.outputTone): `[idle, linked]`. */
export const PILL_CLASSES = {
    green: ['!border-green-600 !bg-green-50 text-green-700', '!border-green-700 !bg-green-600 text-white'],
    red: ['!border-red-600 !bg-red-50 text-red-700', '!border-red-700 !bg-red-600 text-white'],
    amber: ['!border-amber-600 !bg-amber-50 text-amber-700', '!border-amber-700 !bg-amber-600 text-white'],
    grey: ['!border-slate-500 !bg-slate-50 text-slate-700', '!border-slate-600 !bg-slate-500 text-white'],
    slate: ['!border-slate-500 !bg-white text-slate-700', '!border-slate-600 !bg-slate-600 text-white'],
    error: ['!border-dashed !border-red-600 !bg-white text-red-700', '!border-red-700 !bg-red-600 text-white'],
}

/** Line colour of a link by the tone of the output it leaves. */
export const LINK_COLORS = {
    green: '#16a34a',
    red: '#dc2626',
    amber: '#d97706',
    grey: '#64748b',
    slate: '#64748b',
    error: '#dc2626',
    back: '#d97706',
}
