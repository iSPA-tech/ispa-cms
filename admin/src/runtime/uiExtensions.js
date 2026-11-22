import { shallowReactive } from 'vue'

/**
 * Registry of UI that a module plugs into an open screen.
 *
 * An open screen asks for a named extension point; a module registers what it offers when its admin bundle loads. A point
 * nobody registered is `null`, so the screen simply shows nothing there. Points in use:
 * - `product.variant.merge`: a component `{ row, variants }` that emits `merged`, shown in the variant action column;
 * - `product.merge`: a component `{ row }` that emits `merged`, shown in the product list action column;
 * - `product.variant.spec-import`: `{ preview(uuid, body), apply(uuid, payload) }` for the brochure import panel.
 */
const extensions = shallowReactive({})

/**
 * Register what a module offers for an extension point (a later registration replaces an earlier one).
 *
 * @param {string} point Extension point name.
 * @param {object|Function} value A component or a service object.
 */
export const registerUiExtension = (point, value) => {
    extensions[point] = value
}

/**
 * What is registered for a point, or null.
 *
 * @param {string} point Extension point name.
 * @returns {object|Function|null}
 */
export const getUiExtension = (point) => extensions[point] ?? null

/** Forget every registration (tests). */
export const resetUiExtensions = () => {
    for (const key of Object.keys(extensions)) delete extensions[key]
}
