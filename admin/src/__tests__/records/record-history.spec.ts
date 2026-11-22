import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const api = vi.hoisted(() => ({ getRecordHistory: vi.fn() }))
vi.mock('@/libs/records', () => api)
const known = new Set(['text.history.field.name', 'text.history.action.created'])
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (k: string, p?: Record<string, string>) => (p ? `${k}|${Object.values(p).join('|')}` : k),
        te: (k: string) => known.has(k),
    }),
}))

const AlertStub = defineComponent({
    props: ['message'],
    setup(props, { slots }) {
        return () => h('div', { 'data-testid': 'error' }, [props.message, slots.action?.()])
    },
})
const IButton = defineComponent({
    props: ['loading'],
    emits: ['click'],
    setup(_, { emit, slots, attrs }) {
        return () => h('button', { ...attrs, onClick: () => emit('click') }, slots.default?.())
    },
})

const item = (id: number, over: Record<string, unknown> = {}) => ({
    id,
    action: 'action.created',
    label: 'text.history.action.created',
    at: '2026-11-09T03:00:00+00:00',
    actor: { uuid: 'u1', name: 'An' },
    changes: [],
    ...over,
})
const page = (ids: number[], nextBefore: number | null) => ({ items: ids.map((i) => item(i)), nextBefore })

const mountWidget = async (props = { type: 'helpdesk.organization', uuid: 'a' }) => {
    const Widget = (await import('@/components/records/record-history.vue')).default
    const w = mount(Widget, {
        props,
        global: { stubs: { 'a-skeleton': { template: '<div data-testid="skeleton" />' }, 'a-alert': AlertStub, 'i-button': IButton } },
    })
    await flushPromises()
    return w
}

describe('record history widget', () => {
    beforeEach(() => vi.clearAllMocks())

    it('loads the first page on mount and shows label, actor and Ho Chi Minh time', async () => {
        api.getRecordHistory.mockResolvedValue(page([3, 2], null))
        const w = await mountWidget()
        expect(api.getRecordHistory).toHaveBeenCalledWith('helpdesk.organization', 'a', expect.objectContaining({ before: null }))
        expect(w.findAll('[data-testid="history-row"]')).toHaveLength(2)
        expect(w.text()).toContain('text.history.action.created')
        expect(w.text()).toContain('text.history.by|An')
        expect(w.text()).toContain('09/11/2026 10:00')
        expect(w.find('[data-testid="history-more"]').exists()).toBe(false)
    })

    it('pages with the cursor and hides the button on the last page', async () => {
        api.getRecordHistory.mockResolvedValueOnce(page([5, 4], 4)).mockResolvedValueOnce(page([3], null))
        const w = await mountWidget()
        await w.find('[data-testid="history-more"]').trigger('click')
        await flushPromises()
        expect(api.getRecordHistory).toHaveBeenLastCalledWith('helpdesk.organization', 'a', expect.objectContaining({ before: 4 }))
        expect(w.findAll('[data-testid="history-row"]')).toHaveLength(3)
        expect(w.find('[data-testid="history-more"]').exists()).toBe(false)
    })

    it('stops at 200 rows with a note even when the server has more', async () => {
        const ids = (from: number) => Array.from({ length: 50 }, (_, i) => from - i)
        api.getRecordHistory
            .mockResolvedValueOnce(page(ids(1000), 951))
            .mockResolvedValueOnce(page(ids(950), 901))
            .mockResolvedValueOnce(page(ids(900), 851))
            .mockResolvedValueOnce(page(ids(850), 801))
        const w = await mountWidget()
        for (let i = 0; i < 3; i++) {
            await w.find('[data-testid="history-more"]').trigger('click')
            await flushPromises()
        }
        expect(w.findAll('[data-testid="history-row"]')).toHaveLength(200)
        expect(w.find('[data-testid="history-more"]').exists()).toBe(false)
        expect(w.find('[data-testid="history-limit"]').text()).toContain('text.history.limit_note')
    })

    it('shows the field label when a key exists, the raw name otherwise, and from/to values', async () => {
        api.getRecordHistory.mockResolvedValue({
            items: [item(1, { changes: [{ field: 'name', from: 'A', to: 'B' }, { field: 'tax_code', from: null, to: '123' }] })],
            nextBefore: null,
        })
        const w = await mountWidget()
        expect(w.text()).toContain('text.history.field.name|A|B')
        expect(w.text()).toContain('tax_code|')
        expect(w.text()).toContain('123')
    })

    it('falls back to the unknown label for a label key without a translation and tolerates a missing actor', async () => {
        api.getRecordHistory.mockResolvedValue({ items: [item(1, { label: 'text.nope', actor: null })], nextBefore: null })
        const w = await mountWidget()
        expect(w.text()).toContain('text.history.action.unknown')
        expect(w.text()).not.toContain('text.history.by')
    })

    it('shows the empty state', async () => {
        api.getRecordHistory.mockResolvedValue(page([], null))
        const w = await mountWidget()
        expect(w.find('[data-testid="history-empty"]').text()).toContain('text.history.empty')
    })

    it('shows an error with retry that reloads', async () => {
        api.getRecordHistory.mockRejectedValueOnce(new Error('x')).mockResolvedValueOnce(page([1], null))
        const w = await mountWidget()
        expect(w.find('[data-testid="error"]').text()).toContain('text.history.load_failed')
        await w.find('[data-testid="history-retry"]').trigger('click')
        await flushPromises()
        expect(w.find('[data-testid="error"]').exists()).toBe(false)
        expect(w.findAll('[data-testid="history-row"]')).toHaveLength(1)
    })

    it('keeps loaded rows and shows the error when load more fails', async () => {
        api.getRecordHistory.mockResolvedValueOnce(page([2], 1)).mockRejectedValueOnce(new Error('x'))
        const w = await mountWidget()
        await w.find('[data-testid="history-more"]').trigger('click')
        await flushPromises()
        expect(w.findAll('[data-testid="history-row"]')).toHaveLength(1)
        expect(w.find('[data-testid="error"]').exists()).toBe(true)
    })

    it('discards the response of the old record when type or uuid changes', async () => {
        let resolveOld: (v: unknown) => void = () => {}
        api.getRecordHistory
            .mockImplementationOnce(() => new Promise((r) => (resolveOld = r)))
            .mockResolvedValueOnce({ items: [item(9, { actor: { uuid: 'x', name: 'New' } })], nextBefore: null })
        const w = await mountWidget()
        await w.setProps({ uuid: 'b' })
        await flushPromises()
        resolveOld(page([1, 2, 3], null))
        await flushPromises()
        expect(w.findAll('[data-testid="history-row"]')).toHaveLength(1)
        expect(w.text()).toContain('New')
        expect(api.getRecordHistory).toHaveBeenLastCalledWith('helpdesk.organization', 'b', expect.objectContaining({ before: null }))
    })

    it('aborts the request on unmount', async () => {
        let signal: AbortSignal | undefined
        api.getRecordHistory.mockImplementation((_t: string, _u: string, o: { signal: AbortSignal }) => {
            signal = o.signal
            return new Promise(() => {})
        })
        const w = await mountWidget()
        expect(signal?.aborted).toBe(false)
        w.unmount()
        expect(signal?.aborted).toBe(true)
    })
})
