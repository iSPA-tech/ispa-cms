import { effectScope, nextTick, reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const route = reactive<{ query: Record<string, any> }>({ query: {} })
const router = { replace: vi.fn(), push: vi.fn() }

vi.mock('vue-router', () => ({ useRoute: () => route, useRouter: () => router }))

import { useListQueryState } from '../../hooks/useListQueryState.js'

const schema = { q: 'string', page: 'int', status: 'string' } as const

describe('useListQueryState', () => {
    beforeEach(() => {
        route.query = {}
        router.replace.mockReset()
        router.push.mockReset()
    })

    it('initialises from the route query', () => {
        route.query = { q: 'abc', page: '2', junk: 'x' }
        const scope = effectScope()
        const { state } = scope.run(() => useListQueryState(schema, { status: 'all' }))!
        expect(state).toMatchObject({ q: 'abc', page: 2, status: 'all' })
        scope.stop()
    })

    it('commits with replace, never push, and keeps foreign query keys', () => {
        route.query = { from: '/x' }
        const scope = effectScope()
        const { state, commit } = scope.run(() => useListQueryState(schema, { status: 'all' }))!
        state.q = 'hello'
        state.page = 1
        commit()
        expect(router.replace).toHaveBeenCalledWith({ query: { from: '/x', q: 'hello' } })
        expect(router.push).not.toHaveBeenCalled()
        scope.stop()
    })

    it('reset restores defaults and commits', () => {
        route.query = { q: 'a', page: '4' }
        const scope = effectScope()
        const { state, reset } = scope.run(() => useListQueryState(schema))!
        reset()
        expect(state).toMatchObject({ q: '', page: 1 })
        expect(router.replace).toHaveBeenCalledWith({ query: {} })
        scope.stop()
    })

    it('reacts to an external query change and stops with the scope', async () => {
        const scope = effectScope()
        const { state } = scope.run(() => useListQueryState(schema))!
        route.query = { q: 'back', page: '5' }
        await nextTick()
        expect(state).toMatchObject({ q: 'back', page: 5 })
        scope.stop()
        route.query = { q: 'after' }
        await nextTick()
        expect(state.q).toBe('back')
    })
})
