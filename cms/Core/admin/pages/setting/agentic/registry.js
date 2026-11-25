import { markRaw, shallowReactive } from 'vue'

/**
 * What modules contribute to the agentic screens, so open Core names no module of its own here.
 *
 * A module calls registerAgenticModule() when its admin bundle loads (module.js). It never imports `@/config/i18n`:
 * labels are translation keys, translated by the Core screens.
 * - `module`: { key, labelKey, aliases?: string[], inboxFilter?: string, scope?: boolean } module badge and filters;
 * - `tasks`: { [toolName]: card meta } task launcher cards ({ icon, titleKey, descKey, module, group, targetEntity });
 * - `artifactTypes`: { [artifactType]: labelKey } readable names of the module's proposal types;
 * - `diffs`: { [artifactType or diff type]: component } the approval diff of the module's artifacts;
 * - `starterTools`: [{ tool, after }] tools placed in the starter tool order right after an existing one;
 * - `autoApprovedLinks`: { [artifactType]: (approval) => route | null } where an item JEV approved on its own can be
 *   taken back (the module's own review screen); the inbox shows the link on such rows.
 */
const state = shallowReactive({ modules: [], tasks: {}, artifactTypes: {}, diffs: {}, starterTools: [], autoApprovedLinks: {} })

/**
 * Register what a module adds to the agentic screens (a later registration of the same module key replaces it).
 *
 * @param {{module?: object, tasks?: object, artifactTypes?: object, diffs?: object, starterTools?: Array<{tool: string, after?: string}>}} contribution
 */
export function registerAgenticModule(contribution = {}) {
    if (contribution.module?.key) {
        state.modules = [...state.modules.filter((m) => m.key !== contribution.module.key), contribution.module]
    }
    state.tasks = { ...state.tasks, ...(contribution.tasks || {}) }
    state.artifactTypes = { ...state.artifactTypes, ...(contribution.artifactTypes || {}) }
    const diffs = {}
    for (const [type, component] of Object.entries(contribution.diffs || {})) {
        diffs[type] = markRaw(component)
    }
    state.diffs = { ...state.diffs, ...diffs }
    state.starterTools = [...state.starterTools, ...(contribution.starterTools || [])]
    state.autoApprovedLinks = { ...state.autoApprovedLinks, ...(contribution.autoApprovedLinks || {}) }
}

/** Registered modules: [{ key, labelKey, aliases, inboxFilter, scope }]. */
export const agenticModules = () => state.modules

/** Task card meta contributed by modules, keyed by tool name. */
export const agenticTasks = () => state.tasks

/** Translation key of a contributed artifact type, or null. */
export const agenticArtifactTypeLabelKey = (artifactType) => state.artifactTypes[artifactType] ?? null

/** The contributed diff component for an artifact type, else for a diff type, else null (Core's generic diffs). */
export const agenticDiffComponent = (artifactType, diffType) => state.diffs[artifactType] ?? state.diffs[diffType] ?? null

/** Route to where an automatically approved item of this row's type can be taken back, or null. */
export function agenticAutoApprovedLink(approval) {
    const build = state.autoApprovedLinks[approval?.artifact?.artifact_type]
    return typeof build === 'function' ? build(approval) || null : null
}

/** Insert the contributed starter tools into a priority list (after their anchor, else at the end). */
export function withAgenticStarterTools(list) {
    const out = [...list]
    for (const { tool, after } of state.starterTools) {
        if (out.includes(tool)) continue
        const index = after ? out.indexOf(after) : -1
        index === -1 ? out.push(tool) : out.splice(index + 1, 0, tool)
    }
    return out
}

/** Forget every contribution (tests). */
export function resetAgenticRegistry() {
    state.modules = []
    state.tasks = {}
    state.artifactTypes = {}
    state.diffs = {}
    state.starterTools = []
    state.autoApprovedLinks = {}
}
