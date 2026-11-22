import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { registerActionHook, unregisterActionHook } from '@/utils/resource/action-hooks'

const ajax = vi.hoisted(() => ({ get: vi.fn(), request: vi.fn() }))
const msg = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
const widget = vi.hoisted(() => ({ props: null as any }))
vi.mock('@/ajax', () => ({ default: ajax }))
vi.mock('ant-design-vue', () => ({ message: msg }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/components/resource/widgets', async () => {
    const { h } = await import('vue')
    const text = { props: ['value', 'field'], setup: (p: any) => () => h('span', { class: 'val', 'data-context': p.field?.context }, String(p.value)) }
    const tabWidget = {
        props: ['record', 'canManage', 'reload'],
        setup(p: any) {
            widget.props = p
            return () => h('div', { class: 'tabw' })
        },
    }
    return { resolveWidget: (name: string) => (name.includes('.') ? tabWidget : text) }
})
vi.mock('@/components/records/record-timeline.vue', () => ({
    default: { props: ['type', 'uuid'], template: '<div class="timeline" :data-type="type" :data-uuid="uuid" />' },
}))
vi.mock('@/components/resource/resource-form.vue', () => ({
    default: {
        props: ['fields', 'endpoint', 'mode', 'submitLabel', 'requiredLabel'],
        emits: ['saved', 'cancel'],
        template: '<form class="inline-form" :data-path="endpoint.path" :data-submit="submitLabel" :data-required="requiredLabel"><button class="emit-saved" type="button" @click="$emit(\'saved\', {})" /></form>',
    },
}))

const stubs = {
    'i-button': { emits: ['click'], template: '<button type="button" @click="$emit(\'click\', $event)"><slot /></button>' },
    'a-alert': { props: ['message'], template: '<div role="alert">{{ message }}<slot name="action" /></div>' },
    'a-skeleton': { template: '<div class="skeleton" />' },
    'a-popconfirm': { props: ['title', 'okText', 'cancelText'], emits: ['confirm'], template: '<div class="pop" :data-title="title" :data-ok="okText" :data-cancel="cancelText"><slot /><button class="pop-ok" @click="$emit(\'confirm\')" /></div>' },
    'a-tabs': { template: '<div><slot /></div>' },
    'a-tab-pane': { props: ['tab'], template: '<section :data-tab="tab"><slot /></section>' },
}

const makeSchema = (over: Record<string, unknown> = {}) => ({
    detail: {
        endpoint: { method: 'GET', path: '/api/v2/admin/helpdesk/items/:uuid' },
        sections: [
            { field: 'name', type: 'text', label: 'text.x.name' },
            { field: 'status', type: 'text', label: 'text.x.status' },
        ],
        tabs: [{ name: 'notes', label: 'text.x.notes', widget: 'helpdesk.notes' }],
    },
    actions: [
        { name: 'close', label: 'text.x.close', endpoint: { method: 'POST', path: '/api/v2/admin/helpdesk/items/:uuid/close' }, confirm: 'text.x.sure', visible: { field: 'status', op: 'eq', value: 'open' }, success_message: 'text.x.closed' },
        { name: 'reopen', label: 'text.x.reopen', endpoint: { method: 'POST', path: '/api/v2/admin/helpdesk/items/:uuid/reopen' }, visible: { field: 'status', op: 'eq', value: 'closed' } },
        { name: 'assign', label: 'text.x.assign', endpoint: { method: 'POST', path: '/api/v2/admin/helpdesk/items/:uuid/assign' }, fields: [{ field: 'user', type: 'text', label: 'text.x.user', rules: {} }] },
    ],
    ...over,
})
const rec = (status = 'open') => ({ data: { data: { uuid: 'u1', name: 'Item', status } } })
const mountDetail = async (schema: any = makeSchema(), uuid = 'u1') => {
    const w = mount<any>((await import('@/components/resource/resource-detail.vue')).default, { props: { schema, uuid }, global: { stubs } })
    await flushPromises()
    return w
}

describe('ResourceDetail', () => {
    beforeEach(() => {
        vi.resetAllMocks()
        widget.props = null
    })

    it('loads the record from the detail endpoint and renders sections', async () => {
        ajax.get.mockResolvedValue(rec())
        const w = await mountDetail()
        expect(ajax.get.mock.calls[0][0]).toBe('helpdesk/items/u1')
        expect(w.find('[data-section="name"]').text()).toContain('text.x.name')
        expect(w.find('[data-section="name"] .val').text()).toBe('Item')
    })

    it('shows an error with retry and recovers', async () => {
        ajax.get.mockRejectedValueOnce(new Error('x')).mockResolvedValueOnce(rec())
        const w = await mountDetail()
        expect(w.find('[role="alert"]').text()).toContain('text.resource.load_failed')
        await w.find('[role="alert"] button').trigger('click')
        await flushPromises()
        expect(w.find('[data-section="name"]').exists()).toBe(true)
    })

    it('shows actions by their visible condition', async () => {
        ajax.get.mockResolvedValue(rec('open'))
        const w = await mountDetail()
        expect(w.find('[data-action="close"]').exists()).toBe(true)
        expect(w.find('[data-action="reopen"]').exists()).toBe(false)
        expect(w.find('[data-action="assign"]').exists()).toBe(true)
    })

    it('runs a confirm action on popconfirm confirm, reloads and emits changed', async () => {
        ajax.get.mockResolvedValueOnce(rec('open')).mockResolvedValueOnce(rec('closed'))
        ajax.request.mockResolvedValue({ data: {} })
        const w = await mountDetail()
        expect(w.find('.pop').attributes('data-title')).toBe('text.x.sure')
        expect(ajax.request).not.toHaveBeenCalled()
        await w.find('.pop-ok').trigger('click')
        await flushPromises()
        expect(ajax.request).toHaveBeenCalledTimes(1)
        expect(ajax.request.mock.calls[0][0]).toMatchObject({ url: 'helpdesk/items/u1/close', method: 'post' })
        expect(msg.success).toHaveBeenCalledWith('text.x.closed')
        expect(ajax.get).toHaveBeenCalledTimes(2)
        expect(w.emitted('changed')).toHaveLength(1)
        expect(w.find('[data-action="close"]').exists()).toBe(false)
        expect(w.find('[data-action="reopen"]').exists()).toBe(true)
    })

    it('reports a failed action, keeps the record and does not emit changed', async () => {
        ajax.get.mockResolvedValue(rec('open'))
        ajax.request.mockRejectedValue(new Error('x'))
        const w = await mountDetail()
        await w.find('.pop-ok').trigger('click')
        await flushPromises()
        expect(msg.error).toHaveBeenCalledWith('text.resource.save_failed')
        expect(w.emitted('changed')).toBeUndefined()
        expect(ajax.get).toHaveBeenCalledTimes(1)
        expect(w.find('[data-section="name"]').exists()).toBe(true)
    })

    it('opens an inline form for actions with fields; saved reloads, emits changed and closes it', async () => {
        ajax.get.mockResolvedValue(rec('open'))
        const w = await mountDetail()
        expect(w.find('.inline-form').exists()).toBe(false)
        await w.find('[data-action="assign"]').trigger('click')
        expect(w.find('.inline-form').attributes('data-path')).toBe('/api/v2/admin/helpdesk/items/u1/assign')
        await w.find('.emit-saved').trigger('click')
        await flushPromises()
        expect(ajax.get).toHaveBeenCalledTimes(2)
        expect(w.emitted('changed')).toHaveLength(1)
        expect(w.find('.inline-form').exists()).toBe(false)
    })

    it('passes record, canManage and a working reload to tab widgets', async () => {
        ajax.get.mockResolvedValue(rec())
        const w = await mountDetail()
        expect(w.find('[data-tab="text.x.notes"] .tabw').exists()).toBe(true)
        expect(widget.props.record.uuid).toBe('u1')
        expect(widget.props.canManage).toBe(true)
        expect(typeof widget.props.reload).toBe('function')
        await widget.props.reload()
        expect(ajax.get).toHaveBeenCalledTimes(2)
    })

    it('shows the timeline as the last tab only when the schema carries detail.timeline', async () => {
        ajax.get.mockResolvedValue(rec())
        const base = makeSchema()
        const withTimeline = await mountDetail(makeSchema({ detail: { ...base.detail, timeline: { record_type: 'helpdesk.organization' } } }))
        const panes = withTimeline.findAll('section[data-tab]')
        expect(panes.map((p: any) => p.attributes('data-tab'))).toEqual(['text.x.notes', 'text.timeline.title'])
        expect(withTimeline.find('.timeline').attributes()).toMatchObject({ 'data-type': 'helpdesk.organization', 'data-uuid': 'u1' })

        const without = await mountDetail()
        expect(without.find('.timeline').exists()).toBe(false)
    })

    it('sets canManage false when the schema has no actions', async () => {
        ajax.get.mockResolvedValue(rec())
        await mountDetail(makeSchema({ actions: [] }))
        expect(widget.props.canManage).toBe(false)
    })

    it('ignores a stale response when the uuid changed mid-flight', async () => {
        let resolveFirst: (v: unknown) => void = () => {}
        ajax.get
            .mockImplementationOnce(() => new Promise((r) => (resolveFirst = r)))
            .mockResolvedValueOnce({ data: { data: { uuid: 'u2', name: 'Second', status: 'open' } } })
        const w = await mountDetail()
        await w.setProps({ uuid: 'u2' })
        await flushPromises()
        resolveFirst({ data: { data: { uuid: 'u1', name: 'First', status: 'open' } } })
        await flushPromises()
        expect(w.find('[data-section="name"] .val').text()).toBe('Second')
        expect(ajax.get.mock.calls[1][0]).toBe('helpdesk/items/u2')
    })

    it('emits loaded with the record each time it is read', async () => {
        ajax.get.mockResolvedValue(rec())
        const w = await mountDetail()
        expect(w.emitted('loaded')![0]).toEqual([{ uuid: 'u1', name: 'Item', status: 'open' }])
    })

    it('labels the popconfirm buttons with the action and the generic cancel text', async () => {
        ajax.get.mockResolvedValue(rec('open'))
        const w = await mountDetail()
        expect(w.find('.pop').attributes('data-ok')).toBe('text.x.close')
        expect(w.find('.pop').attributes('data-cancel')).toBe('text.resource.cancel')
    })

    it('labels the submit button of an inline action form with the action label', async () => {
        ajax.get.mockResolvedValue(rec('open'))
        const w = await mountDetail()
        await w.find('[data-action="assign"]').trigger('click')
        expect(w.find('.inline-form').attributes('data-submit')).toBe('text.x.assign')
    })

    it('uses the action submitLabel for the inline form button when the schema gives one', async () => {
        ajax.get.mockResolvedValue(rec('open'))
        const schema = makeSchema()
        schema.actions[2] = { ...schema.actions[2], submitLabel: 'text.x.send' } as any
        const w = await mountDetail(schema)
        await w.find('[data-action="assign"]').trigger('click')
        expect(w.find('.inline-form').attributes('data-submit')).toBe('text.x.send')
    })

    it('hands the form required label to inline action forms and marks sections as detail context', async () => {
        ajax.get.mockResolvedValue(rec('open'))
        const w = await mountDetail(makeSchema({ form: { labels: { required: 'text.x.required' } } }))
        expect(w.find('[data-section="name"] .val').attributes('data-context')).toBe('detail')
        await w.find('[data-action="assign"]').trigger('click')
        expect(w.find('.inline-form').attributes('data-required')).toBe('text.x.required')
    })

    describe('edit, danger, refresh and hooks', () => {
        const del = (over: Record<string, unknown> = {}) => ({
            name: 'delete',
            label: 'action.delete',
            danger: true,
            refresh: 'close',
            endpoint: { method: 'DELETE', path: '/api/v2/admin/helpdesk/items/:uuid' },
            ...over,
        })
        const withActions = (...actions: unknown[]) => makeSchema({ actions })
        afterEach(() => unregisterActionHook('afterIt'))

        it('shows an edit button only when the schema has an update form and emits edit with the record', async () => {
            ajax.get.mockResolvedValue(rec())
            const none = await mountDetail()
            expect(none.find('[data-test="edit"]').exists()).toBe(false)
            const w = await mountDetail(makeSchema({ form: { update: { endpoint: { method: 'PUT', path: '/x/:uuid' }, fields: [] } } }))
            await w.find('[data-test="edit"]').trigger('click')
            expect(w.emitted('edit')![0]).toEqual([{ uuid: 'u1', name: 'Item', status: 'open' }])
        })

        it('delete: danger confirms first, then closes the drawer without reloading the record', async () => {
            ajax.get.mockResolvedValue(rec())
            ajax.request.mockResolvedValue({ data: {} })
            const w = await mountDetail(withActions(del()))
            expect(w.find('[data-action="delete"]').attributes('variant')).toBe('danger')
            expect(w.find('.pop').attributes('data-title')).toBe('action.delete')
            expect(ajax.request).not.toHaveBeenCalled()
            await w.find('.pop-ok').trigger('click')
            await flushPromises()
            expect(ajax.request.mock.calls[0][0]).toMatchObject({ url: 'helpdesk/items/u1', method: 'delete' })
            expect(w.emitted('close')).toHaveLength(1)
            expect(w.emitted('changed')).toHaveLength(1)
            expect(ajax.get).toHaveBeenCalledTimes(1)
        })

        it('refresh list emits changed without reloading the record; refresh record reloads it', async () => {
            ajax.get.mockResolvedValue(rec())
            ajax.request.mockResolvedValue({ data: {} })
            const list = await mountDetail(withActions(del({ name: 'a', danger: false, refresh: 'list' })))
            await list.find('[data-action="a"]').trigger('click')
            await flushPromises()
            expect(list.emitted('changed')).toHaveLength(1)
            expect(list.emitted('close')).toBeUndefined()
            expect(ajax.get).toHaveBeenCalledTimes(1)
            const record = await mountDetail(withActions(del({ name: 'b', danger: false, refresh: 'record' })))
            await record.find('[data-action="b"]').trigger('click')
            await flushPromises()
            expect(ajax.get).toHaveBeenCalledTimes(3)
            expect(record.emitted('changed')).toHaveLength(1)
        })

        it('shows the server message of a 409 guard and keeps the drawer open', async () => {
            ajax.get.mockResolvedValue(rec())
            ajax.request.mockRejectedValue({ response: { status: 409, data: { message: 'Has children' } } })
            const w = await mountDetail(withActions(del()))
            await w.find('.pop-ok').trigger('click')
            await flushPromises()
            expect(msg.error).toHaveBeenCalledWith('Has children')
            expect(w.emitted('close')).toBeUndefined()
            expect(w.emitted('changed')).toBeUndefined()
        })

        it('runs the after hook once after success with the action and record, never after failure', async () => {
            const hook = vi.fn()
            registerActionHook('afterIt', hook)
            ajax.get.mockResolvedValue(rec())
            ajax.request.mockRejectedValueOnce(new Error('x')).mockResolvedValueOnce({ data: { ok: 1 } })
            const w = await mountDetail(withActions(del({ danger: false, refresh: 'list', after: 'afterIt' })))
            await w.find('[data-action="delete"]').trigger('click')
            await flushPromises()
            expect(hook).not.toHaveBeenCalled()
            await w.find('[data-action="delete"]').trigger('click')
            await flushPromises()
            expect(hook).toHaveBeenCalledTimes(1)
            expect(hook.mock.calls[0][0]).toMatchObject({ action: { name: 'delete' }, record: { uuid: 'u1' }, response: { data: { ok: 1 } } })
        })

        it('a failing hook shows a message but the action still counts as succeeded', async () => {
            registerActionHook('afterIt', () => {
                throw new Error('Hook broke')
            })
            ajax.get.mockResolvedValue(rec())
            ajax.request.mockResolvedValue({ data: {} })
            const w = await mountDetail(withActions(del({ danger: false, after: 'afterIt' })))
            await w.find('[data-action="delete"]').trigger('click')
            await flushPromises()
            expect(msg.error).toHaveBeenCalledWith('Hook broke')
            expect(msg.success).toHaveBeenCalled()
            expect(w.emitted('close')).toHaveLength(1)
        })

        it('does not offer bulk actions on a single record', async () => {
            ajax.get.mockResolvedValue(rec())
            const w = await mountDetail(withActions(del({ name: 'bulkdel', bulk: true })))
            expect(w.find('[data-action="bulkdel"]').exists()).toBe(false)
        })
    })
})
