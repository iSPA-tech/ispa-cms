import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const api = vi.hoisted(() => ({
    getRecordNotes: vi.fn(),
    addRecordNote: vi.fn(),
    updateRecordNote: vi.fn(),
    deleteRecordNote: vi.fn(),
    searchMentionable: vi.fn(),
}))
vi.mock('@/libs/records', () => api)
vi.mock('ant-design-vue', () => ({ message: { success: vi.fn(), error: vi.fn() } }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (k: string, p?: Record<string, string>) => (p ? `${k}|${Object.values(p).join('|')}` : k),
        te: () => true,
    }),
}))

const AlertStub = defineComponent({
    props: ['message'],
    setup(props, { slots }) {
        return () => h('div', { 'data-testid': 'alert' }, [props.message, slots.action?.()])
    },
})
const IButton = defineComponent({
    props: ['loading', 'disabled'],
    emits: ['click'],
    setup(props, { emit, slots, attrs }) {
        return () => h('button', { ...attrs, disabled: props.disabled || undefined, onClick: () => emit('click') }, slots.default?.())
    },
})
const TextArea = defineComponent({
    props: ['value', 'disabled'],
    emits: ['update:value'],
    setup(props, { emit, attrs }) {
        return () =>
            h('textarea', {
                ...attrs,
                value: props.value,
                disabled: props.disabled || undefined,
                onInput: (e: Event) => emit('update:value', (e.target as HTMLTextAreaElement).value),
            })
    },
})
const Input = defineComponent({
    props: ['value'],
    emits: ['update:value'],
    setup(props, { emit, attrs }) {
        return () => h('input', { ...attrs, value: props.value, onInput: (e: Event) => emit('update:value', (e.target as HTMLInputElement).value) })
    },
})
const Popconfirm = defineComponent({
    emits: ['confirm'],
    setup(_, { emit, slots }) {
        return () => h('span', [slots.default?.(), h('button', { 'data-testid': 'confirm-yes', onClick: () => emit('confirm') })])
    },
})

const note = (uuid: string, over: Record<string, unknown> = {}) => ({
    uuid,
    body: `Body ${uuid}`,
    author: { uuid: 'au', name: 'An' },
    mentions: [],
    created_at: '2026-11-09T03:00:00+00:00',
    edited_at: null,
    can_edit: false,
    can_delete: false,
    ...over,
})

const mountNotes = async (props: Record<string, unknown> = {}) => {
    const Widget = (await import('@/components/records/record-notes.vue')).default
    const w = mount(Widget, {
        props: { type: 'helpdesk.organization', uuid: 'a', ...props },
        attachTo: document.body,
        global: {
            stubs: {
                'a-skeleton': { template: '<div data-testid="skeleton" />' },
                'a-alert': AlertStub,
                'a-textarea': TextArea,
                'a-input': Input,
                'a-popconfirm': Popconfirm,
                'i-button': IButton,
            },
        },
    })
    await flushPromises()
    return w
}

const type = async (w: ReturnType<typeof mount>, text: string) => {
    await w.find('[data-testid="note-input"]').setValue(text)
}

describe('record notes widget', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        api.getRecordNotes.mockResolvedValue({ items: [], nextBefore: null })
        api.searchMentionable.mockResolvedValue([])
    })
    afterEach(() => {
        vi.useRealTimers()
    })

    it('lists notes, with edit and delete only on the ones the server allows', async () => {
        api.getRecordNotes.mockResolvedValue({
            items: [note('n1', { can_edit: true, can_delete: true }), note('n2'), note('n3', { can_delete: true, edited_at: '2026-11-09T04:00:00+00:00' })],
            nextBefore: null,
        })
        const w = await mountNotes()
        const rows = w.findAll('[data-testid="note-row"]')
        expect(rows).toHaveLength(3)
        expect(rows[0].find('[data-testid="note-edit"]').exists()).toBe(true)
        expect(rows[0].find('[data-testid="note-delete"]').exists()).toBe(true)
        expect(rows[1].find('[data-testid="note-edit"]').exists()).toBe(false)
        expect(rows[1].find('[data-testid="note-delete"]').exists()).toBe(false)
        expect(rows[2].find('[data-testid="note-edit"]').exists()).toBe(false)
        expect(rows[2].find('[data-testid="note-delete"]').exists()).toBe(true)
        expect(rows[2].text()).toContain('text.notes.edited')
        expect(rows[0].text()).toContain('09/11/2026 10:00')
        expect(rows[0].text()).not.toContain('text.notes.edited')
    })

    it('shows the empty state and an error with retry', async () => {
        const w = await mountNotes()
        expect(w.find('[data-testid="notes-empty"]').text()).toContain('text.notes.empty')
        api.getRecordNotes.mockRejectedValueOnce(new Error('x')).mockResolvedValueOnce({ items: [note('n1')], nextBefore: null })
        const w2 = await mountNotes({ uuid: 'b' })
        expect(w2.find('[data-testid="alert"]').text()).toContain('text.notes.load_failed')
        await w2.find('[data-testid="notes-retry"]').trigger('click')
        await flushPromises()
        expect(w2.findAll('[data-testid="note-row"]')).toHaveLength(1)
    })

    it('pages with the note cursor and stops at 200 rows', async () => {
        const mk = (from: number) => Array.from({ length: 50 }, (_, i) => note(`n${from - i}`))
        api.getRecordNotes
            .mockResolvedValueOnce({ items: mk(1000), nextBefore: 'c1' })
            .mockResolvedValueOnce({ items: mk(900), nextBefore: 'c2' })
            .mockResolvedValueOnce({ items: mk(800), nextBefore: 'c3' })
            .mockResolvedValueOnce({ items: mk(700), nextBefore: 'c4' })
        const w = await mountNotes()
        await w.find('[data-testid="notes-more"]').trigger('click')
        await flushPromises()
        expect(api.getRecordNotes).toHaveBeenLastCalledWith('helpdesk.organization', 'a', expect.objectContaining({ before: 'c1' }))
        for (let i = 0; i < 2; i++) {
            await w.find('[data-testid="notes-more"]').trigger('click')
            await flushPromises()
        }
        expect(w.findAll('[data-testid="note-row"]')).toHaveLength(200)
        expect(w.find('[data-testid="notes-more"]').exists()).toBe(false)
        expect(w.find('[data-testid="notes-limit"]').exists()).toBe(true)
    })

    it('a double submit sends one request and the body carries the mention uuids', async () => {
        let resolve: (v: unknown) => void = () => {}
        api.addRecordNote.mockImplementation(() => new Promise((r) => (resolve = r)))
        api.searchMentionable.mockResolvedValue([{ uuid: 'u-bao', name: 'Bao' }])
        vi.useFakeTimers()
        const w = await mountNotes()
        await type(w, 'Hello')
        await w.find('[data-testid="mention-button"]').trigger('click')
        await w.find('[data-testid="mention-search"]').setValue('ba')
        await vi.advanceTimersByTimeAsync(260)
        await w.find('[data-testid="mention-option"]').trigger('click')
        expect((w.find('[data-testid="note-input"]').element as HTMLTextAreaElement).value).toContain('@Bao')
        expect(w.findAll('[data-testid="mention-chip"]')).toHaveLength(1)

        await w.find('[data-testid="note-submit"]').trigger('click')
        await w.find('[data-testid="note-submit"]').trigger('click')
        expect(api.addRecordNote).toHaveBeenCalledTimes(1)
        expect(api.addRecordNote).toHaveBeenCalledWith('helpdesk.organization', 'a', { body: expect.stringContaining('@Bao'), mentions: ['u-bao'] })
        resolve({ note: note('new', { body: 'Hello @Bao' }), ignored: 0 })
        await flushPromises()
        expect(w.findAll('[data-testid="note-row"]')).toHaveLength(1)
        expect((w.find('[data-testid="note-input"]').element as HTMLTextAreaElement).value).toBe('')
        expect(w.findAll('[data-testid="mention-chip"]')).toHaveLength(0)
    })

    it('does not search below 2 characters and debounces the search by 250 ms', async () => {
        vi.useFakeTimers()
        const w = await mountNotes()
        await w.find('[data-testid="mention-button"]').trigger('click')
        await w.find('[data-testid="mention-search"]').setValue('a')
        await vi.advanceTimersByTimeAsync(500)
        expect(api.searchMentionable).not.toHaveBeenCalled()
        await w.find('[data-testid="mention-search"]').setValue('an')
        await vi.advanceTimersByTimeAsync(200)
        expect(api.searchMentionable).not.toHaveBeenCalled()
        await vi.advanceTimersByTimeAsync(60)
        expect(api.searchMentionable).toHaveBeenCalledTimes(1)
        expect(api.searchMentionable).toHaveBeenCalledWith('helpdesk.organization', 'a', 'an', expect.any(Object))
    })

    it('clears the pending search timer on unmount', async () => {
        vi.useFakeTimers()
        const w = await mountNotes()
        await w.find('[data-testid="mention-button"]').trigger('click')
        await w.find('[data-testid="mention-search"]').setValue('an')
        expect(vi.getTimerCount()).toBeGreaterThan(0)
        w.unmount()
        expect(vi.getTimerCount()).toBe(0)
        await vi.advanceTimersByTimeAsync(500)
        expect(api.searchMentionable).not.toHaveBeenCalled()
    })

    it('picks a mention with the keyboard (arrows, Enter) and closes with Escape', async () => {
        api.searchMentionable.mockResolvedValue([
            { uuid: 'u1', name: 'Anh' },
            { uuid: 'u2', name: 'Bao' },
        ])
        vi.useFakeTimers()
        const w = await mountNotes()
        await type(w, 'Hi ')
        await w.find('[data-testid="mention-button"]').trigger('click')
        const search = w.find('[data-testid="mention-search"]')
        await search.setValue('an')
        await vi.advanceTimersByTimeAsync(260)
        expect(w.find('[role="listbox"]').exists()).toBe(true)
        await search.trigger('keydown', { key: 'ArrowDown' })
        await search.trigger('keydown', { key: 'ArrowDown' })
        await search.trigger('keydown', { key: 'ArrowUp' })
        await search.trigger('keydown', { key: 'Enter' })
        expect((w.find('[data-testid="note-input"]').element as HTMLTextAreaElement).value).toBe('Hi @Bao ')
        expect(w.find('[role="listbox"]').exists()).toBe(false)

        await w.find('[data-testid="mention-button"]').trigger('click')
        expect(w.find('[data-testid="mention-search"]').exists()).toBe(true)
        await w.find('[data-testid="mention-search"]').trigger('keydown', { key: 'Escape' })
        expect(w.find('[data-testid="mention-search"]').exists()).toBe(false)
    })

    it('opens the picker when @ is typed and replaces it with the chosen name', async () => {
        api.searchMentionable.mockResolvedValue([{ uuid: 'u1', name: 'Anh' }])
        vi.useFakeTimers()
        const w = await mountNotes()
        await type(w, 'Hi @')
        expect(w.find('[data-testid="mention-search"]').exists()).toBe(true)
        await w.find('[data-testid="mention-search"]').setValue('an')
        await vi.advanceTimersByTimeAsync(260)
        await w.find('[data-testid="mention-option"]').trigger('click')
        expect((w.find('[data-testid="note-input"]').element as HTMLTextAreaElement).value).toBe('Hi @Anh ')
    })

    it('removes a chip and drops its uuid from the posted body', async () => {
        api.searchMentionable.mockResolvedValue([{ uuid: 'u1', name: 'Anh' }])
        api.addRecordNote.mockResolvedValue({ note: note('n'), ignored: 0 })
        vi.useFakeTimers()
        const w = await mountNotes()
        await type(w, 'x')
        await w.find('[data-testid="mention-button"]').trigger('click')
        await w.find('[data-testid="mention-search"]').setValue('an')
        await vi.advanceTimersByTimeAsync(260)
        await w.find('[data-testid="mention-option"]').trigger('click')
        await w.find('[data-testid="mention-chip-remove"]').trigger('click')
        await w.find('[data-testid="note-submit"]').trigger('click')
        await flushPromises()
        expect(api.addRecordNote).toHaveBeenCalledWith('helpdesk.organization', 'a', expect.objectContaining({ mentions: [] }))
    })

    it('shows a soft warning with the ignored count', async () => {
        api.addRecordNote.mockResolvedValue({ note: note('n'), ignored: 2 })
        const w = await mountNotes()
        await type(w, 'Hello')
        await w.find('[data-testid="note-submit"]').trigger('click')
        await flushPromises()
        expect(w.find('[data-testid="notes-ignored"]').text()).toContain('text.notes.ignored_mentions|2')
    })

    it('shows the 422 message under the box and keeps the text', async () => {
        api.addRecordNote.mockRejectedValue({ response: { status: 422, data: { errors: { body: ['The body is too long.'] } } } })
        const w = await mountNotes()
        await type(w, 'Hello')
        await w.find('[data-testid="note-submit"]').trigger('click')
        await flushPromises()
        expect(w.find('[data-testid="note-error"]').text()).toContain('The body is too long.')
        expect((w.find('[data-testid="note-input"]').element as HTMLTextAreaElement).value).toBe('Hello')
        expect(w.find('[data-testid="note-submit"]').attributes('disabled')).toBeUndefined()
    })

    it('falls back to the too-long key on a 422 without a message', async () => {
        api.addRecordNote.mockRejectedValue({ response: { status: 422, data: {} } })
        const w = await mountNotes()
        await type(w, 'Hello')
        await w.find('[data-testid="note-submit"]').trigger('click')
        await flushPromises()
        expect(w.find('[data-testid="note-error"]').text()).toContain('text.notes.too_long')
    })

    it('does not submit an empty note', async () => {
        const w = await mountNotes()
        await type(w, '   ')
        await w.find('[data-testid="note-submit"]').trigger('click')
        expect(api.addRecordNote).not.toHaveBeenCalled()
    })

    it('edits an own note in place and deletes after confirmation', async () => {
        api.getRecordNotes.mockResolvedValue({ items: [note('n1', { can_edit: true, can_delete: true, body: 'Old' })], nextBefore: null })
        api.updateRecordNote.mockResolvedValue({ note: note('n1', { body: 'New', edited_at: '2026-11-09T05:00:00+00:00', can_edit: true, can_delete: true }), ignored: 0 })
        api.deleteRecordNote.mockResolvedValue(undefined)
        const w = await mountNotes()
        await w.find('[data-testid="note-edit"]').trigger('click')
        await w.find('[data-testid="note-edit-input"]').setValue('New')
        await w.find('[data-testid="note-edit-save"]').trigger('click')
        await flushPromises()
        expect(api.updateRecordNote).toHaveBeenCalledWith('helpdesk.organization', 'a', 'n1', { body: 'New', mentions: [] })
        expect(w.find('[data-testid="note-row"]').text()).toContain('New')
        expect(w.find('[data-testid="note-row"]').text()).toContain('text.notes.edited')

        await w.find('[data-testid="confirm-yes"]').trigger('click')
        await flushPromises()
        expect(api.deleteRecordNote).toHaveBeenCalledWith('helpdesk.organization', 'a', 'n1')
        expect(w.findAll('[data-testid="note-row"]')).toHaveLength(0)
    })

    it('hides the composer when canNote is false', async () => {
        const w = await mountNotes({ canNote: false })
        expect(w.find('[data-testid="note-input"]').exists()).toBe(false)
    })

    it('aborts the list request on unmount', async () => {
        let signal: AbortSignal | undefined
        api.getRecordNotes.mockImplementation((_t: string, _u: string, o: { signal: AbortSignal }) => {
            signal = o.signal
            return new Promise(() => {})
        })
        const w = await mountNotes()
        w.unmount()
        expect(signal?.aborted).toBe(true)
    })
})
