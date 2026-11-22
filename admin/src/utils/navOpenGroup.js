/**
 * Whether a nav item, or any of its nested children, targets the current route.
 *
 * @param {object} item Navigation entry with an optional `children` array.
 * @param {object} route Current route (name and meta).
 * @returns {boolean}
 */
const containsRoute = (item, route) => {
    const activeNav = route?.meta?.activeNav
    if (item?.name && (item.name === route?.name || (activeNav && item.name === activeNav))) {
        return true
    }
    return Array.isArray(item?.children) && item.children.some((child) => containsRoute(child, route))
}

/**
 * Resolve which top-level sidebar group should be open: the route's own `meta.group` when that group's
 * nav lists the route, otherwise the first group whose nav children contain the route, otherwise `meta.group`.
 *
 * @param {Array<object>} navs Top-level navigation entries (each with `group` and `children`).
 * @param {object} route Current route (name and meta).
 * @returns {string|null}
 */
export const resolveOpenNavGroup = (navs, route) => {
    const ownGroup = route?.meta?.group ?? null
    const owners = (navs || []).filter(
        (nav) => Array.isArray(nav?.children) && nav.children.some((child) => containsRoute(child, route))
    )
    if (ownGroup && owners.some((nav) => nav.group === ownGroup)) {
        return ownGroup
    }
    return owners[0]?.group ?? ownGroup
}
