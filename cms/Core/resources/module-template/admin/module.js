import { SkeletonRouteConfig } from './route-config'
import { SkeletonNavigationConfig } from './navigation-config'

// Picked up by base-admin (src/config/admin-modules.js) from cms/<Module>/admin/module.js; rebuild the admin after changes.
export const SkeletonAdminModule = {
    key: 'skeleton',
    routeConfig: SkeletonRouteConfig,
    navigationConfig: SkeletonNavigationConfig,
    // Required for a module base-admin does not name: without a slot it gets no routes and no menu entry. `after` is a
    // module key in MODULE_ORDER, `navAfter` a top-level menu name in NAV_ORDER (base-admin src/config/navigation.js).
    slot: { after: 'webmail', navAfter: 'site-layout' },
}
