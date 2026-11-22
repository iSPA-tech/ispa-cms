/**
 * Route gate for screens that belong to an inactive CMS module.
 *
 * Kept free of i18n imports on purpose (specs mock vue-i18n); the message is translated where it is shown (App.vue).
 */

import { ref } from 'vue'

/**
 * Collect which CMS module owns each named route from the navigation tree.
 * A child without its own `module` inherits the parent's, like permission rules do.
 *
 * @param {Array<object>} items - Navigation items ({ name, module, children }).
 * @param {string} inheritedModule - Module name inherited from the parent item.
 * @returns {Map<string, string>} Route name -> catalog module name (only entries that have a module).
 */
export const collectNavModules = (items = [], inheritedModule = '') => {
    const modules = new Map()

    items.forEach((item = {}) => {
        const ownModule = item.module || inheritedModule || ''

        if (item.name && ownModule) {
            modules.set(item.name, ownModule)
        }

        if (Array.isArray(item.children) && item.children.length > 0) {
            collectNavModules(item.children, ownModule).forEach((value, key) => modules.set(key, value))
        }
    })

    return modules
}

/**
 * Decide whether a route must be blocked because its module is off.
 * Fails open (false) for routes without a module and while the module catalog has not loaded yet.
 *
 * @param {string|undefined} moduleName - Catalog module name from `route.meta.module`.
 * @param {boolean} catalogLoaded - Whether the module catalog has been loaded.
 * @param {(name: string) => boolean} isActive - Module status lookup.
 * @returns {boolean} True when the route must redirect to the dashboard.
 */
export const isRouteModuleBlocked = (moduleName, catalogLoaded, isActive) =>
    Boolean(moduleName) && catalogLoaded === true && !isActive(moduleName)

/** Bumped each time a screen is turned away because its module is off; App.vue shows the notice. */
export const moduleOffNotice = ref(0)

/**
 * Ask the shell to show the "module is off" message once.
 *
 * @returns {void}
 */
export const announceModuleOff = () => {
    moduleOffNotice.value += 1
}
