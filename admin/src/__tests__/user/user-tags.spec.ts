import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import UserTagApplyModal from '@/components/user-tag-apply-modal.vue'
import { attachUserTags } from '@/libs'

const messages = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn() }))
vi.mock('ant-design-vue', () => ({ message: messages }))
vi.mock('@/libs', () => ({ attachUserTags: vi.fn() }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
// The tag select has its own spec: a comma separated input stands in for it.
vi.mock('@/components/select-input-user-tag.vue', () => ({ default: { name: 'SelectInputUserTag', props: ['modelValue', 'allowCreate', 'placeholder'], emits: ['update:modelValue'], template: '<input :data-create="String(allowCreate)" :value="(modelValue || []).join(\',\')" @input="$emit(\'update:modelValue\', $event.target.value.split(\',\').filter(Boolean))">' } }))

const stubs = {
    'a-modal': { props: ['open', 'title', 'okButtonProps'], emits: ['ok'], template: '<div v-if="open"><h3 data-testid="title">{{ title }}</h3><slot /><button type="button" data-testid="apply-ok" :disabled="okButtonProps.disabled" @click="$emit(\'ok\')">ok</button></div>' },
}
const mountModal = (props: Record<string, any> = {}) => mount(UserTagApplyModal, { props: { open: true, mode: 'attach', count: 2, userUuids: ['u-1', 'u-2'], ...props }, global: { stubs } })

describe('UserTagApplyModal', () => {
    beforeEach(() => { vi.clearAllMocks(); (attachUserTags as any).mockResolvedValue({ status: 'success', data: { affected: 2 } }) })

    it('titles the dialog in Vietnamese with the count (no raw key) and keeps OK disabled until a tag is chosen', async () => {
        const wrapper = mountModal()
        expect(wrapper.get('[data-testid="title"]').text()).toBe('Gắn tag cho 2 người')
        expect(wrapper.get('[data-testid="apply-ok"]').attributes('disabled')).toBeDefined()
        await wrapper.get('[data-testid="apply-tags"]').setValue('t-1')
        expect(wrapper.get('[data-testid="apply-ok"]').attributes('disabled')).toBeUndefined()
        expect(attachUserTags).not.toHaveBeenCalled()
    })

    it('prefills the select from the filter tags and still lets the operator change them', async () => {
        const wrapper = mountModal({ initialTags: ['t-1', 't-2'] })
        const input = wrapper.get('[data-testid="apply-tags"]')
        expect((input.element as HTMLInputElement).value).toBe('t-1,t-2')
        expect(wrapper.get('[data-testid="apply-ok"]').attributes('disabled')).toBeUndefined()
        await input.setValue('t-1,t-2,t-3')
        await wrapper.get('[data-testid="apply-ok"]').trigger('click')
        await flushPromises()
        expect(attachUserTags).toHaveBeenCalledWith({ tag_uuids: ['t-1', 't-2', 't-3'], user_uuids: ['u-1', 'u-2'] })
    })

    it('attaches the chosen tags to the picked users, toasts the count and reports done', async () => {
        const wrapper = mountModal()
        await wrapper.get('[data-testid="apply-tags"]').setValue('t-1')
        await wrapper.get('[data-testid="apply-ok"]').trigger('click')
        await flushPromises()
        expect(attachUserTags).toHaveBeenCalledWith({ tag_uuids: ['t-1'], user_uuids: ['u-1', 'u-2'] })
        expect(wrapper.get('[data-testid="apply-tags"]').attributes('data-create')).toBe('true')
        expect(messages.success).toHaveBeenCalledWith('Đã gắn tag cho 2 người')
        expect(wrapper.emitted('update:open')!.at(-1)).toEqual([false])
        expect(wrapper.emitted('done')).toHaveLength(1)
    })

    it('sends the filter instead of ids with the total in the confirmation text', async () => {
        const wrapper = mountModal({ count: 137, filter: { q: 'lan', status: 'all', roles: [7] } })
        expect(wrapper.get('[data-testid="confirm-all"]').text()).toBe('Tag sẽ áp dụng cho cả 137 người khớp bộ lọc, không chỉ các dòng đang hiện. Tiếp tục?')
        await wrapper.get('[data-testid="apply-tags"]').setValue('t-1')
        await wrapper.get('[data-testid="apply-ok"]').trigger('click')
        await flushPromises()
        expect(attachUserTags).toHaveBeenCalledWith({ tag_uuids: ['t-1'], filter: { q: 'lan', status: 'all', roles: [7] } })
    })

    it('shows the server reason and keeps the dialog open on failure', async () => {
        ;(attachUserTags as any).mockRejectedValue({ response: { data: { errors: { filter: ['Bộ lọc khớp 5.001 người, tối đa 5.000; thu hẹp bộ lọc.'] } } } })
        const wrapper = mountModal()
        await wrapper.get('[data-testid="apply-tags"]').setValue('t-1')
        await wrapper.get('[data-testid="apply-ok"]').trigger('click')
        await flushPromises()
        expect(messages.error).toHaveBeenCalledWith('Bộ lọc khớp 5.001 người, tối đa 5.000; thu hẹp bộ lọc.')
        expect(wrapper.emitted('done')).toBeUndefined()
    })
})

describe('makeTagText', () => {
    it('falls back to Vietnamese when i18n returns the humanized key (te false or true)', async () => {
        const { makeTagText } = await import('@/components/user-tag-text')
        for (const te of [() => false, () => true]) {
            expect(makeTagText({ t: () => 'Title Attach', te })('text.user-tag.title_attach', { count: 2 })).toBe('Gắn tag cho 2 người')
        }
        expect(makeTagText({ t: () => 'Attach for people', te: () => true })('text.user-tag.title_attach', { count: 2 })).toBe('Attach for people')
    })
})
