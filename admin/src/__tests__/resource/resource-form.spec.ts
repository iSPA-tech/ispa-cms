import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ResourceForm from '@/components/resource/resource-form.vue'
import { registerActionHook, unregisterActionHook } from '@/utils/resource/action-hooks'

const ajax = vi.hoisted(() => ({ request: vi.fn() }))
const msg = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajax }))
vi.mock('ant-design-vue', () => ({ message: msg }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key) }),
}))

const stubs = {
    'i-button': {
        props: ['nativeType', 'disabled'],
        emits: ['click'],
        template: '<button :type="nativeType || \'button\'" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
    },
    'a-input': {
        props: ['value'],
        emits: ['update:value'],
        template: '<input class="inp" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
    },
    'a-select': { props: ['value'], template: '<select class="sel" />' },
    'a-switch': { template: '<span />' },
}

const fields = [
    { field: 'name', type: 'text', label: 'text.x.name', rules: { required: true, max: 5 } },
    { field: 'kind', type: 'text', label: 'text.x.kind', rules: {}, visible: { field: 'show', op: 'eq', value: 'yes' } },
    { field: 'show', type: 'text', label: 'text.x.show', rules: {} },
    { field: 'note', type: 'text', label: 'text.x.note', rules: {}, required_when: { field: 'show', op: 'eq', value: 'yes' } },
]
const endpoint = { path: 'helpdesk/items', method: 'POST' }
const mountForm = (props = {}) =>
    mount<any>(ResourceForm, { props: { fields, endpoint, ...props }, global: { stubs } })
const setByField = async (w: any, name: string, value: string) => w.find(`[data-field="${name}"] input`).setValue(value)

describe('ResourceForm', () => {
    beforeEach(() => vi.resetAllMocks())

    it('shows required and max messages without calling the API', async () => {
        const w = mountForm()
        await w.find('form').trigger('submit')
        expect(w.find('[data-field="name"]').text()).toContain('text.resource.required')
        await setByField(w, 'name', 'toolong')
        await w.find('form').trigger('submit')
        expect(w.find('[data-field="name"]').text()).toContain('text.resource.max_length:{"max":5}')
        expect(ajax.request).not.toHaveBeenCalled()
    })

    it('starts a create form with the schema default and keeps the stored value on edit', async () => {
        const withDefault = [{ field: 'sort', type: 'text', label: 'text.x.sort', rules: {}, default: 0 }]
        const create = mountForm({ fields: withDefault })
        expect((create.find('[data-field="sort"] input').element as HTMLInputElement).value).toBe('0')

        const edit = mountForm({ fields: withDefault, record: { sort: 7 } })
        expect((edit.find('[data-field="sort"] input').element as HTMLInputElement).value).toBe('7')

        const editEmpty = mountForm({ fields: withDefault, record: { sort: null } })
        expect((editEmpty.find('[data-field="sort"] input').element as HTMLInputElement).value).toBe('')
    })

    it('starts a create form on the status default and keeps the record status on edit', async () => {
        ajax.request.mockResolvedValue({ data: {} })
        const statusField = [
            { field: 'status', type: 'status', label: 'text.x.status', rules: {}, options: [{ value: 'draft', label: 'd' }, { value: 'published', label: 'p' }], default: 'draft' },
        ]
        const create = mountForm({ fields: statusField })
        await create.find('form').trigger('submit')
        await flushPromises()
        expect(ajax.request.mock.calls[0][0].data.status).toBe('draft')

        const edit = mountForm({ fields: statusField, record: { status: 'published' } })
        await edit.find('form').trigger('submit')
        await flushPromises()
        expect(ajax.request.mock.calls[1][0].data.status).toBe('published')
    })

    it('hides, skips and does not submit fields whose visible condition is false', async () => {
        ajax.request.mockResolvedValue({ data: {} })
        const w = mountForm()
        expect(w.find('[data-field="kind"]').exists()).toBe(false)
        await setByField(w, 'show', 'yes')
        expect(w.find('[data-field="kind"]').exists()).toBe(true)
        await setByField(w, 'show', 'no')
        expect(w.find('[data-field="kind"]').exists()).toBe(false)
        await setByField(w, 'show', '')
        await setByField(w, 'name', 'ok')
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(ajax.request.mock.calls[0][0].data).toEqual({ name: 'ok', show: '', note: null })
    })

    it('passes the form values to a relation whose endpoint is chosen by another field', async () => {
        const relationFields = [
            { field: 'scope_type', type: 'text', label: 'text.x.scope', rules: {} },
            {
                field: 'target',
                type: 'relation',
                label: 'text.x.target',
                rules: {},
                relation: { endpoint: 'x/lookup', endpoints_by: { field: 'scope_type', map: { book: 'books/lookup' } } },
            },
        ]
        const w = mount<any>(ResourceForm, {
            props: { fields: relationFields, endpoint },
            global: {
                stubs: {
                    ...stubs,
                    InputRelation: {
                        props: ['values', 'field'],
                        template: '<i class="rel" :data-parent="values ? values.scope_type : \'none\'" :data-parent-label="field.parent_label" />',
                    },
                },
            },
        })
        expect(w.find('.rel').attributes('data-parent-label')).toBe('text.x.scope')
        await setByField(w, 'scope_type', 'book')
        expect(w.find('.rel').attributes('data-parent')).toBe('book')
    })

    it('applies required_when live', async () => {
        const w = mountForm()
        await setByField(w, 'name', 'ok')
        await setByField(w, 'show', 'yes')
        await w.find('form').trigger('submit')
        expect(w.find('[data-field="note"]').text()).toContain('text.resource.required')
        expect(ajax.request).not.toHaveBeenCalled()
    })

    it('blocks a second submit while the first is pending', async () => {
        ajax.request.mockReturnValue(new Promise(() => {}))
        const w = mountForm()
        await setByField(w, 'name', 'ok')
        await w.find('form').trigger('submit')
        await w.find('form').trigger('submit')
        expect(ajax.request).toHaveBeenCalledTimes(1)
    })

    it('maps 422 errors to fields and resets loading', async () => {
        ajax.request.mockRejectedValue({ response: { status: 422, data: { errors: { name: ['Taken'] } } } })
        const w = mountForm()
        await setByField(w, 'name', 'ok')
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(w.find('[data-field="name"]').text()).toContain('Taken')
        expect(w.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
    })

    it('shows a 422 message whose key has no control on the form instead of dropping it', async () => {
        ajax.request.mockRejectedValue({ response: { status: 422, data: { message: 'Portal is off', errors: { email: ['Portal is off'], name: ['Taken'] } } } })
        const w = mountForm()
        await setByField(w, 'name', 'ok')
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(w.find('[data-field="name"]').text()).toContain('Taken')
        expect(msg.error).toHaveBeenCalledWith('Portal is off')
    })

    it('shows save_failed for other errors and resets loading', async () => {
        ajax.request.mockRejectedValue({ response: { status: 500 } })
        const w = mountForm()
        await setByField(w, 'name', 'ok')
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(msg.error).toHaveBeenCalledWith('text.resource.save_failed')
        expect(w.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
    })

    it('emits saved with the response and keeps loading on success', async () => {
        const res = { data: { id: 1 } }
        ajax.request.mockResolvedValue(res)
        const w = mountForm({ successMessage: 'text.x.done' })
        await setByField(w, 'name', 'ok')
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(w.emitted('saved')![0]).toEqual([res])
        expect(msg.success).toHaveBeenCalledWith('text.x.done')
        expect(w.find('button[type="submit"]').attributes('disabled')).toBeDefined()
    })

    it('resolves path params from the record and prefills values', async () => {
        ajax.request.mockResolvedValue({ data: {} })
        const w = mountForm({ endpoint: { path: 'helpdesk/items/:uuid', method: 'PUT' }, record: { uuid: 'u1', name: 'abc' } })
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(ajax.request.mock.calls[0][0]).toMatchObject({ url: 'helpdesk/items/u1', method: 'put' })
    })

    it('labels the submit button with submitLabel and falls back to the generic save text', async () => {
        expect(mountForm().find('button[type="submit"]').text()).toBe('text.resource.save')
        expect(mountForm({ submitLabel: 'text.x.create' }).find('button[type="submit"]').text()).toBe('text.x.create')
    })

    it('treats text of spaces only as missing and sends text trimmed', async () => {
        ajax.request.mockResolvedValue({ data: {} })
        const w = mountForm()
        await setByField(w, 'name', '   ')
        await w.find('form').trigger('submit')
        expect(w.find('[data-field="name"]').text()).toContain('text.resource.required')
        expect(ajax.request).not.toHaveBeenCalled()
        await setByField(w, 'name', ' ok ')
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(ajax.request.mock.calls[0][0].data.name).toBe('ok')
    })

    it('uses requiredLabel for the required message and falls back to the generic key', async () => {
        const w = mountForm({ requiredLabel: 'text.x.required' })
        await w.find('form').trigger('submit')
        expect(w.find('[data-field="name"]').text()).toContain('text.x.required')
        expect(w.find('[data-field="name"]').text()).not.toContain('text.resource.required')
        await setByField(w, 'name', 'toolong')
        await w.find('form').trigger('submit')
        expect(w.find('[data-field="name"]').text()).toContain('text.resource.max_length:{"max":5}')
    })

    it('shows the server message of a non-422 failure (409 guard) instead of the generic text', async () => {
        ajax.request.mockRejectedValue({ response: { status: 409, data: { message: 'Has children' } } })
        const w = mountForm()
        await setByField(w, 'name', 'ok')
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(msg.error).toHaveBeenCalledWith('Has children')
        expect(msg.error).not.toHaveBeenCalledWith('text.resource.save_failed')
    })

    it('update context: prefills from the record, sends PUT to the resolved endpoint and maps 422 onto fields', async () => {
        ajax.request.mockRejectedValueOnce({ response: { status: 422, data: { errors: { name: ['Taken'] } } } }).mockResolvedValueOnce({ data: {} })
        const w = mountForm({ endpoint: { path: '/api/v2/admin/x/items/:uuid', method: 'PUT' }, record: { uuid: 'u9', name: 'abc', show: 'no' } })
        expect(w.find('[data-field="name"] input').element.value).toBe('abc')
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(w.find('[data-field="name"]').text()).toContain('Taken')
        await w.find('form').trigger('submit')
        await flushPromises()
        const call = ajax.request.mock.calls[1][0]
        expect(call).toMatchObject({ url: 'x/items/u9', method: 'put' })
        expect(call.data.name).toBe('abc')
        expect(w.emitted('saved')).toHaveLength(1)
    })

    it('runs the after hook once after a successful save, before saved is emitted', async () => {
        let savedBeforeHook = true
        let w: any
        const hook = vi.fn(async () => { savedBeforeHook = w.emitted('saved') !== undefined })
        registerActionHook('x.after-save', hook)
        const res = { data: { id: 1 } }
        ajax.request.mockResolvedValue(res)
        w = mountForm({ afterHook: 'x.after-save' })
        await setByField(w, 'name', 'ok')
        await w.find('form').trigger('submit')
        await flushPromises()
        unregisterActionHook('x.after-save')
        expect(hook).toHaveBeenCalledTimes(1)
        expect(hook.mock.calls[0][0]).toMatchObject({ response: res })
        expect(w.emitted('saved')).toHaveLength(1)
        expect(savedBeforeHook).toBe(false)
    })

    it('does not run the after hook when the save fails', async () => {
        const hook = vi.fn()
        registerActionHook('x.after-save', hook)
        ajax.request.mockRejectedValue({ response: { status: 500 } })
        const w = mountForm({ afterHook: 'x.after-save' })
        await setByField(w, 'name', 'ok')
        await w.find('form').trigger('submit')
        await flushPromises()
        unregisterActionHook('x.after-save')
        expect(hook).not.toHaveBeenCalled()
        expect(w.emitted('saved')).toBeUndefined()
    })

    it('still emits saved when the after hook throws', async () => {
        registerActionHook('x.after-save', () => { throw new Error('boom') })
        ajax.request.mockResolvedValue({ data: {} })
        const w = mountForm({ afterHook: 'x.after-save' })
        await setByField(w, 'name', 'ok')
        await w.find('form').trigger('submit')
        await flushPromises()
        unregisterActionHook('x.after-save')
        expect(w.emitted('saved')).toHaveLength(1)
        expect(msg.error).toHaveBeenCalledWith('boom')
    })
})
