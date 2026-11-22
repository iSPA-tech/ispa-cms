import { reactive, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { parseListState, serializeListState } from '@/utils/list-state'

/**
 * Keep a list's search, filters and page in the route query.
 *
 * `commit()` writes the state with `router.replace` (never `push`, so Back leaves the page); the state follows
 * external query changes (Back, saved view apply). The watcher stops with the owning scope.
 *
 * @param {Record<string, 'string'|'int'|'list'>} schema Allowed keys and types.
 * @param {object} [defaults] Default values; omitted from the URL.
 * @returns {{ state: object, commit: Function, reset: Function }}
 */
export function useListQueryState(schema, defaults = {}) {
    const route = useRoute()
    const router = useRouter()

    const emptyState = () => {
        const base = {}
        for (const [key, type] of Object.entries(schema)) {
            base[key] = type === 'list' ? [] : type === 'int' ? null : ''
        }

        return base
    }
    const fromQuery = (query) => ({
        ...emptyState(),
        ...(schema.page ? { page: 1 } : {}),
        ...defaults,
        ...parseListState(query, schema),
    })

    const state = reactive(fromQuery(route.query))

    const apply = (next) => {
        for (const key of Object.keys(schema)) {
            state[key] = next[key]
        }
    }

    const commit = () => {
        const kept = {}
        for (const [key, value] of Object.entries(route.query || {})) {
            if (!(key in schema)) {
                kept[key] = value
            }
        }

        return router.replace({ query: { ...kept, ...serializeListState(state, schema, defaults) } })
    }

    const reset = () => {
        apply({ ...emptyState(), ...(schema.page ? { page: 1 } : {}), ...defaults })

        return commit()
    }

    watch(
        () => route.query,
        (query) => apply(fromQuery(query))
    )

    return { state, commit, reset }
}
