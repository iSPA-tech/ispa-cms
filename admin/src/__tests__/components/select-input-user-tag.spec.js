import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SelectInputUserTag from '@/components/select-input-user-tag.vue'
import { createUserTag, searchUserTags } from '@/libs'

vi.mock('@/libs', () => ({ searchUserTags: vi.fn(), createUserTag: vi.fn() }))
vi.mock('@/mixins', () => ({ iAlert: vi.fn() }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key) => key }) }))

const mountSelect = (props = {}) =>
    mount(SelectInputUserTag, {
        props,
        global: {
            stubs: {
                'a-select': { name: 'ASelect', props: ['value', 'options', 'mode', 'placeholder', 'loading'], emits: ['update:value', 'search', 'change'], template: '<select />' },
            },
        },
    })

describe('SelectInputUserTag', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        searchUserTags.mockResolvedValue([{ uuid: 't-1', name: 'Triết', users_count: 3 }])
    })

    it('loads tags and offers them as uuid options in multiple mode', async () => {
        const wrapper = mountSelect({ modelValue: [] })
        await flushPromises()
        const select = wrapper.findComponent({ name: 'ASelect' })
        expect(select.props('mode')).toBe('multiple')
        expect(select.props('options')).toEqual([{ label: 'Triết', value: 't-1' }])
    })

    it('offers "create" for a new name only when creation is allowed', async () => {
        const wrapper = mountSelect({ modelValue: [], allowCreate: true })
        await flushPromises()
        wrapper.findComponent({ name: 'ASelect' }).vm.$emit('search', 'Niên khóa 2025')
        await flushPromises()
        const values = wrapper.findComponent({ name: 'ASelect' }).props('options').map((o) => o.value)
        expect(values).toContain('__create__:Niên khóa 2025')
        const plain = mountSelect({ modelValue: [] })
        await flushPromises()
        plain.findComponent({ name: 'ASelect' }).vm.$emit('search', 'Niên khóa 2025')
        await flushPromises()
        expect(plain.findComponent({ name: 'ASelect' }).props('options').map((o) => o.value)).not.toContain('__create__:Niên khóa 2025')
    })

    it('creates the picked new tag and emits its uuid', async () => {
        createUserTag.mockResolvedValue({ uuid: 't-new', name: 'Niên khóa 2025', users_count: 0 })
        const wrapper = mountSelect({ modelValue: ['t-1'], allowCreate: true })
        await flushPromises()
        wrapper.findComponent({ name: 'ASelect' }).vm.$emit('change', ['t-1', '__create__:Niên khóa 2025'])
        await flushPromises()
        expect(createUserTag).toHaveBeenCalledWith('Niên khóa 2025')
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([['t-1', 't-new']])
    })

    it('shows Vietnamese placeholders and create label instead of raw keys', async () => {
        const wrapper = mountSelect({ modelValue: [], allowCreate: true })
        await flushPromises()
        const select = wrapper.findComponent({ name: 'ASelect' })
        expect(select.props('placeholder')).toBe('Chọn hoặc gõ tên tag mới')
        select.vm.$emit('search', 'Lớp A')
        await flushPromises()
        expect(select.props('options').find((o) => o.value.startsWith('__create__:')).label).toBe('Tạo tag "Lớp A"')
        expect(mountSelect({ modelValue: [] }).findComponent({ name: 'ASelect' }).props('placeholder')).toBe('Tag')
    })

    it('never creates a tag without typed text', async () => {
        const wrapper = mountSelect({ modelValue: [], allowCreate: true })
        await flushPromises()
        const select = wrapper.findComponent({ name: 'ASelect' })
        select.vm.$emit('search', '   ')
        await flushPromises()
        expect(select.props('options').some((o) => o.value.startsWith('__create__:'))).toBe(false)
        select.vm.$emit('change', ['__create__:   ', '__create__:'])
        await flushPromises()
        expect(createUserTag).not.toHaveBeenCalled()
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([[]])
    })
})
