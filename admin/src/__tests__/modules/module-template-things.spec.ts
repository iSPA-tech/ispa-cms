import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import ajax from '@/ajax'
import { renderWithApp } from '@/test-utils/renderWithApp'
// The admin page of the module template used by `php artisan cms:make-module` (stored with placeholder names).
import ThingsPage from '../../../../cms/Core/resources/module-template/admin/pages/things.vue'

vi.mock('ant-design-vue', () => ({
    notification: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
    message: { error: vi.fn(), success: vi.fn(), warning: vi.fn() },
}))

const mockedAjax = vi.mocked(ajax) as any

const row = { uuid: '0d92d46e-f403-4b6c-ad87-275c11a85623', name: 'First', description: null, status: 'draft', updated_at: '2026-11-20 10:00:00' }
const listBody = (rows = [row]) => ({ data: { status: 'success', data: { data: rows, total: rows.length, perPage: 20, currentPage: 1 } } })

const render = (validate: () => Promise<unknown> = () => Promise.resolve()) =>
    renderWithApp(ThingsPage, {
        route: { name: 'skeleton-things', path: '/skeleton/things' },
        permissions: [
            'skeleton.skeleton_thing.viewAny',
            'skeleton.skeleton_thing.create',
            'skeleton.skeleton_thing.update',
            'skeleton.skeleton_thing.delete',
        ],
        globalStubs: {
            breadcrumbs: { template: '<div><slot /></div>' },
            'i-button': { template: '<button type="button" v-bind="$attrs"><slot name="icon" /><slot /></button>' },
            'i-icon': { props: ['icon'], template: '<i :data-icon="icon" />' },
            'no-permission-error': { template: '<div class="no-permission" />' },
            'a-input-search': {
                props: ['value'],
                emits: ['update:value', 'search'],
                template: '<input data-test="search" :value="value" @input="$emit(\'update:value\', $event.target.value)" @keyup.enter="$emit(\'search\')" />',
            },
            'a-table': {
                props: ['columns', 'dataSource'],
                template: `<table><tbody><tr v-for="row in dataSource" :key="row.uuid" :data-row="row.uuid">
                    <td v-for="column in columns" :key="column.key"><slot name="bodyCell" :column="column" :record="row">{{ row[column.dataIndex] }}</slot></td>
                </tr></tbody></table>`,
            },
            'a-popconfirm': {
                props: ['title'],
                emits: ['confirm'],
                template: '<span><slot /><button class="popconfirm-ok" type="button" @click="$emit(\'confirm\')">{{ title }}</button></span>',
            },
            'a-modal': { props: ['open'], template: '<section v-if="open" class="modal"><slot /></section>' },
            'a-form': {
                methods: { validate },
                template: '<form><slot /></form>',
            },
            'a-form-item': { template: '<label><slot /></label>' },
            'a-input': {
                props: ['value'],
                emits: ['update:value'],
                template: '<input :value="value" v-bind="$attrs" @input="$emit(\'update:value\', $event.target.value)" />',
            },
            'a-textarea': { props: ['value'], template: '<textarea :value="value" />' },
            'a-checkbox': { props: ['checked'], template: '<input type="checkbox" :checked="checked" />' },
            AppModalFooter: { emits: ['submit', 'cancel'], template: '<button class="modal-submit" type="button" @click="$emit(\'submit\')">save</button>' },
        },
    })

describe('module template: things admin page', () => {
    beforeEach(() => {
        mockedAjax.get.mockReset().mockResolvedValue(listBody())
        mockedAjax.post.mockReset().mockResolvedValue({ data: { status: 'success', data: row } })
        mockedAjax.put.mockReset().mockResolvedValue({ data: { status: 'success', data: row } })
        mockedAjax.delete = vi.fn().mockResolvedValue({ data: { status: 'success' } })
    })

    it('lists rows from the admin API', async () => {
        const { wrapper } = await render()
        await flushPromises()

        expect(mockedAjax.get).toHaveBeenCalledWith('skeleton/things', { params: { page: 1, pageSize: 20, search: undefined } })
        expect(wrapper.find(`[data-row="${row.uuid}"]`).text()).toContain('First')
    })

    it('searches by name', async () => {
        const { wrapper } = await render()
        await flushPromises()

        const input = wrapper.find('[data-test="search"]')
        await input.setValue('Fir')
        await input.trigger('keyup.enter')
        await flushPromises()

        expect(mockedAjax.get).toHaveBeenLastCalledWith('skeleton/things', { params: { page: 1, pageSize: 20, search: 'Fir' } })
    })

    it('creates a row from the form', async () => {
        const { wrapper } = await render()
        await flushPromises()

        await wrapper.find('[data-test="create"]').trigger('click')
        await wrapper.find('[data-test="name"]').setValue('  New one ')
        await wrapper.find('.modal-submit').trigger('click')
        await flushPromises()

        expect(mockedAjax.post).toHaveBeenCalledWith('skeleton/things', { name: 'New one', description: null, status: 'draft' })
        expect(wrapper.find('.modal').exists()).toBe(false)
    })

    it('keeps the form open without a request or an unhandled error when validation fails', async () => {
        const { wrapper } = await render(() => Promise.reject({ errorFields: [{ name: ['name'] }] }))
        await flushPromises()

        await wrapper.find('[data-test="create"]').trigger('click')
        await wrapper.find('.modal-submit').trigger('click')
        await flushPromises()

        expect(mockedAjax.post).not.toHaveBeenCalled()
        expect(wrapper.find('.modal').exists()).toBe(true)
    })

    it('updates a row by uuid', async () => {
        const { wrapper } = await render()
        await flushPromises()

        await wrapper.find(`[data-test="edit-${row.uuid}"]`).trigger('click')
        await wrapper.find('[data-test="name"]').setValue('Renamed')
        await wrapper.find('.modal-submit').trigger('click')
        await flushPromises()

        expect(mockedAjax.put).toHaveBeenCalledWith(`skeleton/things/${row.uuid}`, { name: 'Renamed', description: null, status: 'draft' })
    })

    it('deletes a row after the confirm', async () => {
        const { wrapper } = await render()
        await flushPromises()

        await wrapper.find('.popconfirm-ok').trigger('click')
        await flushPromises()

        expect(mockedAjax.delete).toHaveBeenCalledWith(`skeleton/things/${row.uuid}`)
    })
})
