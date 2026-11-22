import { enableAutoUnmount, flushPromises, shallowMount } from '@vue/test-utils'
import { ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { onBeforeRouteLeave } from 'vue-router'
import FieldManage from '../../../cms/Content/admin/pages/contact/field.vue'
import { getField, updateField, storeField } from '@/libs'
import ajax from '@/ajax'

const fieldList = ref([{ id: 2, name: 'Tuyển sinh Ban Thần học', items: [] }])
const refetch = vi.fn()
enableAutoUnmount(afterEach)

vi.mock('@/hooks', () => ({
    useFields: () => ({ data: fieldList, refetch }),
    useAccount: () => ({ data: ref({ id: 1 }) }),
}))

vi.mock('@/libs', () => ({
    getField: vi.fn(),
    updateField: vi.fn(),
    storeField: vi.fn(),
    deleteField: vi.fn(),
}))

vi.mock('@/ajax', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('vue-router', () => ({ onBeforeRouteLeave: vi.fn() }))

vi.mock('@/mixins', () => ({
    iAlert: vi.fn(),
    rule: () => true,
    objectAssignOnlyByKeyHasValue: (target: object, source: object) => Object.assign(target, source),
}))

describe('Contact form editor for admission forms', () => {
    beforeEach(() => {
        vi.mocked(getField).mockReset()
        vi.mocked(updateField).mockReset()
        vi.mocked(storeField).mockReset()
        vi.mocked(ajax.post).mockReset()
        vi.mocked(ajax.get).mockReset()
        vi.mocked(ajax.get).mockResolvedValue({ data: { data: [] } })
        refetch.mockReset()
        vi.mocked(onBeforeRouteLeave).mockClear()
    })

    it('generates opaque keys and previews the current unsaved form', async () => {
        vi.mocked(ajax.post).mockResolvedValue({ data: '<html><body>Draft</body></html>' })
        const wrapper = shallowMount(FieldManage, {
            global: { mocks: { $t: (key: string) => key } },
        })

        wrapper.vm.openForm()
        expect(wrapper.vm.form.custom.layout.columns).toEqual({ mobile: 1, tablet: 2, desktop: 2 })
        wrapper.vm.form.name = 'Phiếu tuyển sinh'
        wrapper.vm.addField()
        const field = wrapper.vm.form.items[0]
        expect(wrapper.vm.selectedKey).toBe(field.field_name)
        expect(field.field_name).toMatch(/^field_[0-9a-f]{32}$/)
        expect(field.custom).toEqual(expect.objectContaining({
            placeholder: null,
            choiceLayout: 'auto',
            maxFileSizeMb: 10,
        }))

        field.custom.placeholder = 'Nhập họ tên'
        field.name = 'Họ tên'
        field.type = 'text'
        await wrapper.vm.previewForm()
        const { isShow, ...previewField } = field
        expect(ajax.post).toHaveBeenCalledWith('/field/preview', { ...wrapper.vm.form, items: [previewField] }, { responseType: 'text' })
        expect(wrapper.vm.previewHtml).toContain('Draft')
        expect(wrapper.vm.previewOpen).toBe(true)
    })

    it('protects unsaved changes on close and never treats selecting a field as an edit', async () => {
        vi.mocked(getField).mockResolvedValue({ id: 6, name: 'TEST', items: [
            { id: 10, name: 'Tỉnh', field_name: 'province', type: 'select', custom: { optionsSource: null } },
        ] })
        const wrapper = shallowMount(FieldManage, { global: { mocks: { $t: (key: string) => key } } })
        await wrapper.vm.editForm(6)
        expect(wrapper.vm.dirty).toBe(false)
        wrapper.vm.selectField('province')
        expect(wrapper.vm.dirty).toBe(false)
        wrapper.vm.form.items[0].name = 'Tỉnh/Thành phố'
        expect(wrapper.vm.dirty).toBe(true)
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
        const canceled = wrapper.vm.requestClose()
        expect(wrapper.vm.discardOpen).toBe(true)
        wrapper.vm.answerDiscard(false)
        await canceled
        expect(wrapper.vm.modalState).toBe(true)
        const accepted = wrapper.vm.requestClose()
        wrapper.vm.answerDiscard(true)
        await accepted
        expect(wrapper.vm.modalState).toBe(false)
        expect(confirm).not.toHaveBeenCalled()
        confirm.mockRestore()
        wrapper.unmount()
    })

    it('opens saved dynamic sources whose defaults are null', async () => {
        const optionsSource = { source: 'core.provinces', filters: {}, dependsOn: {} }
        vi.mocked(getField).mockResolvedValue({ id: 6, name: 'TEST', items: [
            { id: 10, name: 'Tỉnh', field_name: 'province', type: 'select', custom: { optionsSource } },
        ] })
        const wrapper = shallowMount(FieldManage, { global: { mocks: { $t: (key: string) => key } } })
        await wrapper.vm.editForm(6)
        expect(wrapper.vm.modalState).toBe(true)
        expect(wrapper.vm.form.items[0].custom.optionsSource).toEqual(optionsSource)
        expect(wrapper.vm.form.items[0].custom.visibleWhen).toEqual({ field: null, value: null })
        expect(wrapper.vm.form.custom).not.toHaveProperty('layout')
    })

    it('keeps a failed save dirty and routes a server error to the exact unselected field', async () => {
        vi.mocked(getField).mockResolvedValue({ id: 6, name: 'TEST', items: [
            { id: 10, name: 'Tên', field_name: 'name', type: 'text' },
            { id: 11, name: 'Tỉnh', field_name: 'city', type: 'select', custom: { optionsSource: { source: 'core.provinces', filters: {}, dependsOn: {} } } },
        ] })
        vi.mocked(updateField).mockRejectedValue({ response: { data: { errors: { 'items.1.custom.optionsSource': ['Unavailable'] } } } })
        const wrapper = shallowMount(FieldManage, { global: { mocks: { $t: (key: string) => key } } })
        await wrapper.vm.editForm(6)
        wrapper.vm.form.items[1].description = 'Changed guide'
        await wrapper.vm.submitForm()
        expect(wrapper.vm.modalState).toBe(true)
        expect(wrapper.vm.dirty).toBe(true)
        expect(wrapper.vm.issues[0]).toMatchObject({ key: 'city', index: 1, attribute: 'custom.optionsSource' })
        await wrapper.vm.showIssue(wrapper.vm.issues[0])
        expect(wrapper.vm.selectedKey).toBe('city')
        wrapper.unmount()
    })

    it('blocks invalid unselected fields before saving the whole draft', async () => {
        vi.mocked(getField).mockResolvedValue({ id: 6, name: 'TEST', items: [
            { id: 10, name: 'Tên', field_name: 'name', type: 'text' },
            { id: 11, name: '', field_name: 'unnamed', type: 'text' },
        ] })
        const wrapper = shallowMount(FieldManage, { global: { mocks: { $t: (key: string) => key } } })
        await wrapper.vm.editForm(6)
        await wrapper.vm.submitForm()
        expect(updateField).not.toHaveBeenCalled()
        expect(wrapper.vm.issues).toEqual([expect.objectContaining({ key: 'unnamed', index: 1, attribute: 'name' })])
        wrapper.unmount()
    })

    it('closes a successful create before a failed list refresh to prevent duplicate creation', async () => {
        vi.mocked(storeField).mockResolvedValue({ status: 'success' })
        refetch.mockRejectedValue(new Error('list unavailable'))
        const wrapper = shallowMount(FieldManage, { global: { mocks: { $t: (key: string) => key } } })
        wrapper.vm.openForm()
        wrapper.vm.form.name = 'TEST'
        await wrapper.vm.submitForm()
        expect(storeField).toHaveBeenCalledOnce()
        expect(wrapper.vm.modalState).toBe(false)
        expect(wrapper.vm.listError).toBe(true)
        wrapper.unmount()
    })

    it('guards route navigation and removes its browser unload listener on unmount', async () => {
        const wrapper = shallowMount(FieldManage, { global: { mocks: { $t: (key: string) => key } } })
        wrapper.vm.openForm()
        wrapper.vm.form.name = 'Unsaved TEST'
        const event = new Event('beforeunload', { cancelable: true })
        window.dispatchEvent(event)
        expect(event.defaultPrevented).toBe(true)
        const guard = vi.mocked(onBeforeRouteLeave).mock.calls.at(-1)?.[0] as () => Promise<boolean>
        const navigation = guard()
        expect(wrapper.vm.discardOpen).toBe(true)
        wrapper.vm.answerDiscard(false)
        expect(await navigation).toBe(false)
        wrapper.unmount()
        const clean = new Event('beforeunload', { cancelable: true })
        window.dispatchEvent(clean)
        expect(clean.defaultPrevented).toBe(false)
    })

    it('shows fetched forms and saves all fields when editing one collapsed field', async () => {
        vi.mocked(getField).mockResolvedValue({
            id: 2,
            name: 'Tuyển sinh Ban Thần học',
            form_id: 'school-admission-than',
            items: [
                { id: 1, name: 'Tên', field_name: 'givenName', type: 'text', description: 'Theo giấy tờ', custom: { placeholder: 'Họ và tên', section: 'Thông tin cá nhân' } },
                { id: 2, name: 'Anh ngữ', field_name: 'englishQualification', type: 'text' },
            ],
        })
        vi.mocked(updateField).mockResolvedValue({ status: 'success' })
        const wrapper = shallowMount(FieldManage, {
            global: {
                mocks: { $t: (key: string) => key },
                stubs: {
                    'a-form': { template: '<form><slot /></form>', methods: { resetFields() {} } },
                },
            },
        })

        expect(wrapper.vm.dataRef).toEqual(fieldList.value)
        await wrapper.vm.editForm(2)
        expect(wrapper.vm.form.items.every((item: { isShow: boolean }) => !item.isShow)).toBe(true)
        expect(wrapper.vm.form.items.every((item: { custom: { optionsSource: null } }) => item.custom.optionsSource === null)).toBe(true)

        wrapper.vm.form.items[1].name = 'Anh ngữ quốc tế'
        wrapper.vm.submitForm({ items: [null, { name: 'Anh ngữ quốc tế' }] })
        await flushPromises()

        expect(updateField).toHaveBeenCalledWith(2, expect.objectContaining({
            items: [
                expect.objectContaining({ name: 'Tên', field_name: 'givenName', type: 'text', description: 'Theo giấy tờ', custom: expect.objectContaining({ placeholder: 'Họ và tên', section: 'Thông tin cá nhân' }) }),
                expect.objectContaining({ name: 'Anh ngữ quốc tế', field_name: 'englishQualification', type: 'text' }),
            ],
        }))
        expect(refetch).toHaveBeenCalledOnce()
        expect(wrapper.vm.modalState).toBe(false)
    })
})
