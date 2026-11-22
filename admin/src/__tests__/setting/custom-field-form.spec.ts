import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import CustomFieldForm from '@/components/custom-fields/custom-field-form.vue'
import CustomFieldPurge from '@/components/custom-fields/custom-field-purge.vue'
import { buildPayload, emptyForm, fieldErrorCodes, formFromField, keyFromLabel, optionValueFromLabel } from '@/libs/custom-fields'

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params?: Record<string, unknown>) => (params ? `${key}|${JSON.stringify(params)}` : key),
        locale: { value: 'vi' },
    }),
}))

const target = { module: 'content', resource: 'posts' }
const UUID = '11111111-1111-4111-8111-111111111111'

const input = (tag = 'input') =>
    defineComponent({
        props: ['value', 'disabled', 'type'],
        emits: ['update:value'],
        setup: (props, { attrs, emit }) => () =>
            h(tag, {
                id: attrs.id, 'data-testid': attrs['data-testid'], disabled: props.disabled,
                value: props.value ?? '',
                onInput: (e: Event) => emit('update:value', (e.target as HTMLInputElement).value),
            }),
    })
const stubs = {
    'a-drawer': defineComponent({ props: ['open'], setup: (p, { slots }) => () => (p.open ? h('div', slots.default?.()) : null) }),
    'a-alert': defineComponent({ props: ['message'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }, p.message) }),
    'a-input': input(),
    'a-textarea': input('textarea'),
    'a-input-number': defineComponent({
        props: ['value'],
        emits: ['update:value'],
        setup: (p, { attrs, emit }) => () =>
            h('input', { 'data-testid': attrs['data-testid'], value: p.value ?? '', onInput: (e: Event) => emit('update:value', Number((e.target as HTMLInputElement).value)) }),
    }),
    'a-select': defineComponent({ props: ['value', 'options'], setup: (_p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }) }),
    'a-switch': defineComponent({
        props: ['checked', 'disabled'],
        emits: ['update:checked'],
        setup: (p, { attrs, emit }) => () => h('button', { type: 'button', 'data-testid': attrs['data-testid'], onClick: () => emit('update:checked', !p.checked) }),
    }),
    'i-button': defineComponent({
        props: ['disabled', 'nativeType'],
        emits: ['click'],
        setup: (p, { slots, attrs, emit }) => () =>
            h('button', { type: p.nativeType || 'button', disabled: p.disabled, 'data-testid': attrs['data-testid'], 'aria-label': attrs['aria-label'], onClick: () => emit('click') }, slots.default?.()),
    }),
}

const previewSchema = { field: 'custom_fields.x', type: 'text', label_text: { vi: 'Mã', en: 'Code' }, help_text: { vi: 'Gợi ý', en: 'Hint' }, rules: { required: true } }
const savedField = (over: Record<string, unknown> = {}) => ({
    uuid: UUID, key: 'industry', type: 'select',
    labels: { vi: 'Ngành', en: 'Industry' }, help: null, rules: {}, is_required: false, show_in_list: true, is_filterable: false, is_searchable: false,
    options: [{ value: 'gold', labels: { vi: 'Vàng', en: 'Gold' } }], ...over,
})

const mountForm = async (props: Record<string, unknown> = {}, records = 0) => {
    ajaxMock.get.mockResolvedValue({ data: { data: { records, key: 'industry' } } })
    ajaxMock.post.mockImplementation((url: string) =>
        Promise.resolve(url.endsWith('/preview') ? { data: { data: { field: previewSchema } } } : { data: { data: {} } }))
    ajaxMock.put.mockResolvedValue({ data: { data: {} } })
    const wrapper = mount(CustomFieldForm, { props: { open: true, target, ...props }, global: { stubs } })
    await flushPromises()
    return wrapper
}
const fill = async (w: Awaited<ReturnType<typeof mountForm>>, id: string, value: string) => {
    await w.get(`[data-testid="${id}"]`).setValue(value)
}

beforeEach(() => {
    vi.useFakeTimers()
    Object.values(ajaxMock).forEach((m) => m.mockReset())
})
afterEach(() => vi.useRealTimers())

describe('custom field form helpers', () => {
    it('builds a key from the English name', () => {
        expect(keyFromLabel('Tax code')).toBe('tax_code')
        expect(keyFromLabel('Mã số thuế đ')).toBe('ma_so_thue_d')
        expect(keyFromLabel('2nd line')).toBe('f_2nd_line')
        expect(keyFromLabel('!!')).toBe('')
        expect(keyFromLabel('x'.repeat(60)).length).toBeLessThanOrEqual(40)
        expect(optionValueFromLabel('Gold', ['gold'])).toBe('gold_2')
    })

    it('sends only the rules the type allows and expands presets', () => {
        const text = { ...emptyForm('text'), labelVi: 'Mã', labelEn: 'Code', max: 10, format: 'digits', dateMin: '2026-01-01' }
        expect(buildPayload(text, 'code').rules).toEqual({ max: 10, pattern: '[0-9]+' })
        expect(buildPayload({ ...text, pattern: 'A+' }, 'code').rules.pattern).toBe('A+')
        expect(buildPayload({ ...emptyForm('date'), dateMin: '2026-01-01', max: 5 }, 'd').rules).toEqual({ date_min: '2026-01-01' })
        expect(buildPayload({ ...emptyForm('boolean'), max: 3 }, 'b').rules).toEqual({})
        expect(buildPayload(emptyForm('text'), 'k').help).toBeNull()
    })

    it('reads a saved field back, flagging saved choices and presets', () => {
        const form = formFromField(savedField({ type: 'text', rules: { pattern: '[0-9]+', max: 8 } }))
        expect(form.format).toBe('digits')
        expect(form.pattern).toBe('')
        expect(formFromField(savedField()).options[0]).toMatchObject({ value: 'gold', saved: true })
    })

    it('maps a 422 body to one code per control', () => {
        const codes = fieldErrorCodes({ reason: 'invalid_definition', errors: { definition: ['labels need both vi and en texts', 'max must be between', 'pattern: does not compile', 'option #1 needs a value'] } })
        expect(codes).toEqual({ labels: true, limits: true, pattern: true, options: true })
        expect(fieldErrorCodes({ reason: 'duplicate_key', errors: { definition: ['x'] } }).duplicate).toBe(true)
        expect(fieldErrorCodes({ reason: 'type_locked' }).typeLocked).toBe(true)
    })
})

describe('custom field form', () => {
    it('starts on the kind step with an example per kind and no controls of other kinds', async () => {
        const w = await mountForm()
        expect(w.findAll('[data-testid^="cf-type-"]').length).toBe(7)
        expect(w.get('[data-testid="cf-type-select"]').text()).toContain('text.custom_fields.type_select_example')
        expect(w.get('[data-testid="cf-next"]').attributes('disabled')).toBeDefined()
    })

    it('shows only the controls of the picked kind', async () => {
        const w = await mountForm()
        const has = (id: string) => w.find(`[data-testid="${id}"]`).exists()
        await w.get('[data-testid="cf-type-text"]').trigger('click')
        expect([has('cf-controls-length'), has('cf-controls-format'), has('cf-controls-integer'), has('cf-controls-date'), has('cf-controls-options')]).toEqual([true, true, false, false, false])
        await w.get('[data-testid="cf-type-integer"]').trigger('click')
        expect([has('cf-controls-length'), has('cf-controls-format'), has('cf-controls-integer')]).toEqual([false, false, true])
        await w.get('[data-testid="cf-type-date"]').trigger('click')
        expect([has('cf-controls-integer'), has('cf-controls-date')]).toEqual([false, true])
        await w.get('[data-testid="cf-type-multiselect"]').trigger('click')
        expect([has('cf-controls-date'), has('cf-controls-options'), has('cf-controls-multi')]).toEqual([false, true, true])
        await w.get('[data-testid="cf-type-boolean"]').trigger('click')
        expect([has('cf-controls-options'), has('cf-controls-multi'), has('cf-controls-length')]).toEqual([false, false, false])
    })

    it('keeps the raw pattern inside a collapsed advanced section', async () => {
        const w = await mountForm()
        await w.get('[data-testid="cf-type-text"]').trigger('click')
        expect(w.get('[data-testid="cf-advanced"]').attributes('open')).toBeUndefined()
    })

    it('creates a field with a key from the English name and both languages', async () => {
        const w = await mountForm()
        await w.get('[data-testid="cf-type-text"]').trigger('click')
        await w.get('[data-testid="cf-next"]').trigger('click')
        await fill(w, 'cf-label-vi', 'Mã số thuế')
        await fill(w, 'cf-label-en', 'Tax code')
        await w.get('[data-testid="cf-next"]').trigger('click')
        await w.get('[data-testid="cf-filterable"]').trigger('click')
        await w.get('[data-testid="cf-form"]').trigger('submit')
        await flushPromises()
        const call = ajaxMock.post.mock.calls.find((c) => c[0] === 'resources/content/posts/custom-fields')
        expect(call?.[1]).toMatchObject({ key: 'tax_code', type: 'text', labels: { vi: 'Mã số thuế', en: 'Tax code' }, is_filterable: true, help: null })
        expect(w.emitted('saved')).toHaveLength(1)
    })

    it.each(['select', 'multiselect', 'boolean', 'integer', 'date'])('hides the filterable switch for %s and says the list filters it by itself', async (kind) => {
        const w = await mountForm()
        await w.get(`[data-testid="cf-type-${kind}"]`).trigger('click')
        await w.get('[data-testid="cf-next"]').trigger('click')
        await fill(w, 'cf-label-vi', 'Trường')
        await fill(w, 'cf-label-en', 'Field')
        if (['select', 'multiselect'].includes(kind)) {
            await w.get('[data-testid="cf-option-vi-0"]').setValue('Một')
            await w.get('[data-testid="cf-option-en-0"]').setValue('One')
        }
        await w.get('[data-testid="cf-next"]').trigger('click')
        expect(w.find('[data-testid="cf-filterable"]').exists()).toBe(false)
        expect(w.get('[data-testid="cf-filter-auto"]').text()).toBe('text.custom_fields.form_filter_auto')
    })

    it('shows the filterable switch for text and neither switch nor note for textarea', async () => {
        const w = await mountForm()
        await w.get('[data-testid="cf-type-text"]').trigger('click')
        await w.get('[data-testid="cf-next"]').trigger('click')
        await w.get('[data-testid="cf-next"]').trigger('click')
        expect(w.find('[data-testid="cf-filterable"]').exists()).toBe(true)
        expect(w.find('[data-testid="cf-filter-auto"]').exists()).toBe(false)
    })

    it('shows the live preview from the preview endpoint after the form settles', async () => {
        const w = await mountForm()
        await w.get('[data-testid="cf-type-text"]').trigger('click')
        await w.get('[data-testid="cf-next"]').trigger('click')
        await fill(w, 'cf-label-en', 'Code')
        await vi.advanceTimersByTimeAsync(600)
        await flushPromises()
        expect(ajaxMock.post.mock.calls.some((c) => c[0] === 'resources/content/posts/custom-fields/preview')).toBe(true)
        expect(w.get('[data-testid="cf-preview-field"]').text()).toContain('Mã')
        expect(w.get('[data-testid="cf-preview-help"]').text()).toBe('Gợi ý')
    })

    it('puts each 422 message under its own control and re-enables the button', async () => {
        const w = await mountForm()
        await w.get('[data-testid="cf-type-text"]').trigger('click')
        await w.get('[data-testid="cf-next"]').trigger('click')
        ajaxMock.post.mockRejectedValueOnce({ response: { status: 422, data: { reason: 'invalid_definition', errors: { definition: ['labels need both vi and en texts', 'max must be at least 1'] } } } })
        await w.get('[data-testid="cf-form"]').trigger('submit')
        await flushPromises()
        expect(w.find('[data-testid="cf-err-labels"]').exists()).toBe(true)
        expect(w.find('[data-testid="cf-err-limits"]').exists()).toBe(true)
        expect(w.find('[data-testid="cf-err-pattern"]').exists()).toBe(false)
        expect(w.get('[data-testid="cf-form-error"]').text()).toBe('text.custom_fields.form_save_failed')
        expect(w.text()).not.toContain('need both vi and en')
    })

    it('ignores a second submit while the first runs and resets loading only on error', async () => {
        const w = await mountForm()
        await w.get('[data-testid="cf-type-boolean"]').trigger('click')
        await w.get('[data-testid="cf-next"]').trigger('click')
        let release: (v: unknown) => void = () => {}
        ajaxMock.post.mockImplementation((url: string) => (url.endsWith('/preview') ? Promise.resolve({ data: { data: { field: previewSchema } } }) : new Promise((r) => { release = r })))
        await w.get('[data-testid="cf-form"]').trigger('submit')
        await w.get('[data-testid="cf-form"]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post.mock.calls.filter((c) => c[0] === 'resources/content/posts/custom-fields')).toHaveLength(1)
        await w.get('[data-testid="cf-next"]').trigger('click').catch(() => {})
        expect(w.get('[data-testid="cf-save"]').attributes('disabled')).toBeDefined()
        release({ data: { data: {} } })
        await flushPromises()
        // Success hands over to the parent: the button stays disabled until the drawer reopens.
        expect(w.get('[data-testid="cf-save"]').attributes('disabled')).toBeDefined()
    })

    it('edits in place: key read-only, type locked and saved choices kept when records use the field', async () => {
        const w = await mountForm({ field: savedField() }, 4)
        expect(w.get('[data-testid="cf-key-note"]').text()).toContain('industry')
        await w.get('[data-testid="cf-step-1"]').trigger('click')
        expect(w.get('[data-testid="cf-type-locked"]').exists()).toBe(true)
        expect(w.get('[data-testid="cf-type-text"]').attributes('disabled')).toBeDefined()
        expect(w.get('[data-testid="cf-type-select"]').attributes('disabled')).toBeUndefined()
        expect(w.get('[data-testid="cf-option-remove-0"]').attributes('disabled')).toBeDefined()
        expect(w.get('[data-testid="cf-options-hint"]').exists()).toBe(true)
        await w.get('[data-testid="cf-option-add"]').trigger('click')
        expect(w.get('[data-testid="cf-option-remove-1"]').attributes('disabled')).toBeUndefined()
        await w.get('[data-testid="cf-option-remove-1"]').trigger('click')
        expect(w.find('[data-testid="cf-option-1"]').exists()).toBe(false)
    })

    it('allows type change and option removal when no record uses the field', async () => {
        const w = await mountForm({ field: savedField() }, 0)
        expect(w.find('[data-testid="cf-type-locked"]').exists()).toBe(false)
        expect(w.get('[data-testid="cf-type-text"]').attributes('disabled')).toBeUndefined()
        expect(w.get('[data-testid="cf-option-remove-0"]').attributes('disabled')).toBeUndefined()
    })

    it('saves an edit with PUT and keeps the saved choice value', async () => {
        const w = await mountForm({ field: savedField() }, 0)
        await w.get('[data-testid="cf-form"]').trigger('submit')
        await flushPromises()
        const call = ajaxMock.put.mock.calls[0]
        expect(call[0]).toBe(`resources/content/posts/custom-fields/${UUID}`)
        expect(call[1].key).toBe('industry')
        expect(call[1].options[0].value).toBe('gold')
    })

    it('has no fixed widths wider than a phone (class check)', async () => {
        const w = await mountForm()
        expect(w.html()).not.toMatch(/min-w-\[?\d{3,}|w-\[\d{3,}px/)
    })
})

describe('custom field purge', () => {
    const mountPurge = async (backupReady: boolean, records = 3) => {
        ajaxMock.get.mockResolvedValue({ data: { data: { records } } })
        const w = mount(CustomFieldPurge, { props: { target, field: { uuid: UUID, key: 'industry' }, backupReady }, global: { stubs } })
        await flushPromises()
        return w
    }

    it('shows how many records hold an answer', async () => {
        const w = await mountPurge(true)
        expect(w.get('[data-testid="cf-purge-count"]').text()).toContain('"count":3')
    })

    it('stays disabled until the typed key matches', async () => {
        const w = await mountPurge(true)
        const button = () => w.get('[data-testid="cf-purge-button"]')
        expect(button().attributes('disabled')).toBeDefined()
        await w.get('[data-testid="cf-purge-input"]').setValue('Industry')
        expect(button().attributes('disabled')).toBeDefined()
        await w.get('[data-testid="cf-purge-input"]').setValue('industry')
        expect(button().attributes('disabled')).toBeUndefined()
        ajaxMock.post.mockResolvedValue({ data: { data: { removed_values: 3 } } })
        await button().trigger('click')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledWith(`resources/content/posts/custom-fields/${UUID}/purge`, { confirm_key: 'industry' }, { silent: true })
        expect(w.emitted('purged')?.[0]).toEqual([3])
    })

    it('says a backup is taken first and hides the unavailable note once the backup is connected', async () => {
        const w = await mountPurge(true)
        expect(w.text()).toContain('text.custom_fields.purge_backup_note')
        expect(w.find('[data-testid="cf-purge-unavailable"]').exists()).toBe(false)
        expect(w.get('[data-testid="cf-purge-input"]').attributes('disabled')).toBeUndefined()
    })

    it('is disabled with a plain explanation while the backup is not connected', async () => {
        const w = await mountPurge(false)
        expect(w.get('[data-testid="cf-purge-unavailable"]').exists()).toBe(true)
        expect(w.get('[data-testid="cf-purge-input"]').attributes('disabled')).toBeDefined()
        expect(w.get('[data-testid="cf-purge-button"]').attributes('disabled')).toBeDefined()
    })

    it('says nothing was deleted when the server refuses', async () => {
        const w = await mountPurge(true)
        await w.get('[data-testid="cf-purge-input"]').setValue('industry')
        ajaxMock.post.mockRejectedValue({ response: { status: 422 } })
        await w.get('[data-testid="cf-purge-button"]').trigger('click')
        await flushPromises()
        expect(w.get('[data-testid="cf-purge-error"]').text()).toBe('text.custom_fields.purge_failed')
        expect(w.emitted('purged')).toBeUndefined()
    })

    it('says the operator tried too often when the server answers 429', async () => {
        const w = await mountPurge(true)
        await w.get('[data-testid="cf-purge-input"]').setValue('industry')
        ajaxMock.post.mockRejectedValue({ response: { status: 429 } })
        await w.get('[data-testid="cf-purge-button"]').trigger('click')
        await flushPromises()
        expect(w.get('[data-testid="cf-purge-error"]').text()).toBe('text.custom_fields.purge_too_many')
        expect(w.emitted('purged')).toBeUndefined()
    })

    it('says the backup failed, not the typed name, when the server answers backup_required', async () => {
        const w = await mountPurge(true)
        await w.get('[data-testid="cf-purge-input"]').setValue('industry')
        ajaxMock.post.mockRejectedValue({ response: { status: 422, data: { reason: 'backup_required' } } })
        await w.get('[data-testid="cf-purge-button"]').trigger('click')
        await flushPromises()
        expect(w.get('[data-testid="cf-purge-error"]').text()).toBe('text.custom_fields.purge_backup_failed')
        expect(w.emitted('purged')).toBeUndefined()
    })
})
