/**
 * Route helper for schema-driven pages. Route configs take `load: () => Promise<Component>` (see `src/config/route.js`
 * and `cms/<Module>/admin/page-loaders.js`), so `resourcePage()` returns exactly that shape and the page code
 * stays lazy-loaded:
 *
 *     load: resourcePage({ resource: 'helpdesk/organizations', title: 'nav.a.helpdesk-organizations' })
 */
import { defineComponent, h } from 'vue'

/**
 * Build a route `load` function that resolves to a ResourcePage bound to the given props.
 *
 * @param {{ resource: string, title?: string, subtitle?: string, detailRoute?: string }} options Resource path `<module>/<resource>`, title, optional subtitle (keys or text) and optional route name of a profile page that replaces the detail drawer.
 * @returns {() => Promise<object>} Loader resolving to a component.
 */
export const resourcePage = ({ resource, title = '', subtitle = '', detailRoute = '' }) => () =>
    import('./resource-page.vue').then(({ default: ResourcePage }) =>
        defineComponent({
            name: 'ResourcePageRoute',
            render: () => h(ResourcePage, { resource, title, subtitle, detailRoute }),
        }),
    )
