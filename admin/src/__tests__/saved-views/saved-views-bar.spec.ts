import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const api = vi.hoisted(() => ({
    getSavedViews: vi.fn(),
    createSavedView: vi.fn(),
    updateSavedView: vi.fn(),
    deleteSavedView: vi.fn(),
}))
vi.mock('@/libs/saved-views', () => api)
vi.mock('ant-design-vue', () => ({ message: { success: vi.fn(), error: vi.fn() } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (k: string) => k }) }))
const router = vi.hoisted(() => ({ replace: vi.fn() }))
const route = vi.hoisted(() => ({ query: {} as Record<string, unknown> }))
vi.mock('vue-router', () => ({ useRouter: () => router, useRoute: () => route }))

import SavedViewsBar from '../../components/saved-views-bar.vue'

const IButton = defineComponent({
    props: ['loading', 'disabled', 'variant'],
    emits: ['click'],
    setup(props, { emit, slots, attrs }) {
        return () => h('button', { ...attrs, 'data-variant': props.variant, disabled: props.disabled || undefined, onClick: () => emit('click') }, slots.default?.())
    },
})
const Input = defineComponent({
    props: ['value'],
    emits: ['update:value', 'pressEnter'],
    setup(props, { emit, attrs }) {
        return () => h('input', { ...attrs, value: props.value, onInput: (e: Event) => emit('update:value', (e.target as HTMLInputElement).value) })
    },
})
const Popover = defineComponent({
    props: ['open'],
    setup(_, { slots }) {
        return () => h('div', [slots.default?.(), slots.content?.()])
    },
})
const Popconfirm = defineComponent({
    emits: ['confirm'],
    setup(_, { emit, slots }) {
        return () => h('span', [slots.default?.(), h('button', { 'data-testid': 'confirm-yes', onClick: () => emit('confirm') })])
    },
})
const Switch = defineComponent({
    props: ['checked'],
    emits: ['change', 'update:checked'],
    setup(props, { emit, attrs }) {
        return () => h('button', { ...attrs, onClick: () => emit('change', !props.checked) })
    },
})
const AlertStub = defineComponent({
    props: ['message'],
    setup(props, { slots }) {
        return () => h('div', { 'data-testid': 'alert' }, [props.message, slots.action?.()])
    },
})

const schema = { q: 'string', page: 'int', status: 'string' } as const
const mk = (extra = {}) => ({ uuid: 'v1', name: 'Open', state: { status: 'open' }, is_shared: false, is_mine: true, can_manage: true, can_delete: true, ...extra })

const mountBar = (props = {}) =>
    mount(SavedViewsBar, {
        props: { listKey: 'helpdesk/organizations', schema, state: {}, canShare: false, ...props },
        global: {
            stubs: { 'i-button': IButton, 'a-input': Input, 'a-popover': Popover, 'a-popconfirm': Popconfirm, 'a-switch': Switch, 'a-alert': AlertStub, 'a-skeleton-button': true },
        },
    })

beforeEach(() => {
    vi.clearAllMocks()
    route.query = { page: '3', status: 'closed', tab: 'x' }
    api.getSavedViews.mockResolvedValue([mk()])
})

describe('saved views bar', () => {
    it('applies a view by replacing the schema keys of the query and dropping the page', async () => {
        const w = mountBar()
        await flushPromises()
        await w.get('[data-testid="view-tab"]').trigger('click')
        expect(router.replace).toHaveBeenCalledWith({ query: { tab: 'x', status: 'open' } })
    })

    it('marks the tab that equals the current state as active and follows state changes', async () => {
        const w = mountBar({ state: { status: 'open', page: 2 } })
        await flushPromises()
        expect(w.get('[data-testid="view-tab"]').attributes('aria-pressed')).toBe('true')
        expect(w.get('[data-testid="view-all"]').attributes('aria-pressed')).toBe('false')
        await w.setProps({ state: { status: 'closed' } })
        expect(w.get('[data-testid="view-tab"]').attributes('aria-pressed')).toBe('false')
        await w.setProps({ state: {} })
        expect(w.get('[data-testid="view-all"]').attributes('aria-pressed')).toBe('true')
    })

    it('sends the normalised state and name once on a double click', async () => {
        let resolve: (v: object) => void = () => {}
        api.createSavedView.mockReturnValue(new Promise((r) => (resolve = r)))
        const w = mountBar({ state: { q: ' vip ', status: 'open', page: 4 } })
        await flushPromises()
        await w.get('[data-testid="view-name"]').setValue('  My view ')
        await w.get('[data-testid="view-submit"]').trigger('click')
        await w.get('[data-testid="view-submit"]').trigger('click')
        expect(api.createSavedView).toHaveBeenCalledTimes(1)
        expect(api.createSavedView).toHaveBeenCalledWith('helpdesk/organizations', { name: 'My view', state: { q: 'vip', status: 'open' }, is_shared: false })
        resolve(mk({ uuid: 'v2', name: 'My view' }))
        await flushPromises()
        expect(w.findAll('[data-testid="view-tab"]')).toHaveLength(2)
    })

    it('hides the share control without canShare and shows it with it', async () => {
        const w = mountBar({ state: { status: 'open' } })
        await flushPromises()
        expect(w.find('[data-testid="view-share"]').exists()).toBe(false)
        expect(w.find('[data-testid="view-share-new"]').exists()).toBe(false)
        const s = mountBar({ state: { status: 'open' }, canShare: true })
        await flushPromises()
        expect(s.find('[data-testid="view-share"]').exists()).toBe(true)
        await s.get('[data-testid="view-share"]').trigger('click')
        expect(api.updateSavedView).toHaveBeenCalledWith('v1', { is_shared: true })
    })

    it('follows the server flags: delete only with can_delete, rename and share only with can_manage', async () => {
        // The owner of a shared view who lost the share ability may still delete it but not edit it.
        api.getSavedViews.mockResolvedValue([mk({ is_shared: true, can_manage: false, can_delete: true })])
        const w = mountBar({ state: { status: 'open' }, canShare: true })
        await flushPromises()
        expect(w.find('[data-testid="view-actions"]').exists()).toBe(true)
        expect(w.find('[data-testid="view-delete"]').exists()).toBe(true)
        expect(w.find('[data-testid="view-rename"]').exists()).toBe(false)
        expect(w.find('[data-testid="view-share"]').exists()).toBe(false)

        // Someone else's shared view with neither flag shows no action row at all.
        api.getSavedViews.mockResolvedValue([mk({ is_shared: true, is_mine: false, can_manage: false, can_delete: false })])
        const other = mountBar({ state: { status: 'open' }, canShare: true })
        await flushPromises()
        expect(other.find('[data-testid="view-actions"]').exists()).toBe(false)
    })

    it('shows limit_reached on a 422', async () => {
        api.createSavedView.mockRejectedValue({ response: { status: 422 } })
        const w = mountBar()
        await flushPromises()
        await w.get('[data-testid="view-name"]').setValue('x')
        await w.get('[data-testid="view-submit"]').trigger('click')
        await flushPromises()
        expect(w.get('[data-testid="view-error"]').text()).toBe('text.saved_views.limit_reached')
    })

    it('hints at a duplicate name and deletes with confirmation', async () => {
        api.deleteSavedView.mockResolvedValue(undefined)
        const w = mountBar({ state: { status: 'open' } })
        await flushPromises()
        await w.get('[data-testid="view-name"]').setValue('open')
        expect(w.find('[data-testid="view-duplicate"]').exists()).toBe(true)
        await w.get('[data-testid="confirm-yes"]').trigger('click')
        await flushPromises()
        expect(api.deleteSavedView).toHaveBeenCalledWith('v1')
        expect(w.findAll('[data-testid="view-tab"]')).toHaveLength(0)
    })

    it('hides the whole bar on a 404 and shows a retry on other failures', async () => {
        api.getSavedViews.mockRejectedValueOnce({ response: { status: 404 } })
        const w = mountBar()
        await flushPromises()
        expect(w.find('[data-testid="saved-views-bar"]').exists()).toBe(false)
        api.getSavedViews.mockRejectedValueOnce({ response: { status: 500 } })
        const e = mountBar()
        await flushPromises()
        expect(e.get('[data-testid="alert"]').text()).toContain('text.saved_views.load_failed')
        await e.get('[data-testid="views-retry"]').trigger('click')
        await flushPromises()
        expect(e.findAll('[data-testid="view-tab"]')).toHaveLength(1)
    })

    it('aborts the pending request on unmount', async () => {
        let signal: AbortSignal | undefined
        api.getSavedViews.mockImplementation((_k: string, o: { signal: AbortSignal }) => {
            signal = o.signal
            return new Promise(() => {})
        })
        const w = mountBar()
        w.unmount()
        expect(signal?.aborted).toBe(true)
    })
})
