import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// "Người duyệt thay đổi quy trình": a Webmaster names who reviews workflow changes; empty means
// everyone who may publish. Other accounts see nothing (the server answers it to Webmasters only).

const access = vi.hoisted(() => ({ webmaster: true }))
const api = vi.hoisted(() => ({ fetchChangeReviewers: vi.fn(), setChangeReviewers: vi.fn() }))

vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => access.webmaster }))
vi.mock('@core-admin/workflow-guard-api', () => api)
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/components/workflow/inputs/ReviewerPicker.vue', () => ({
    default: {
        name: 'ReviewerPicker',
        props: ['modelValue', 'audience'],
        emits: ['update:modelValue'],
        template: '<div data-testid="reviewer-picker" :data-audience="audience" :data-value="JSON.stringify(modelValue)" />',
    },
}))

import Reviewers from '@core-admin/pages/setting/workflows-manage/components/workflow-change-reviewers.vue'

const stubs = { 'a-button': { props: ['loading', 'disabled'], emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }
const open = async () => {
    const wrapper = mount(Reviewers, { global: { stubs, mocks: { $t: (key: string) => key } } })
    await flushPromises()
    return wrapper
}

describe('workflow change reviewers', () => {
    beforeEach(() => {
        access.webmaster = true
        api.fetchChangeReviewers.mockReset().mockResolvedValue({ named: [], default: true })
        api.setChangeReviewers.mockReset()
    })

    it('says everyone who may publish reviews while nobody is named', async () => {
        const wrapper = await open()
        expect(wrapper.get('[data-testid="change-reviewers-summary"]').text()).toBe('text.workflow.guard.reviewers.default')
    })

    it('names the reviewers with their username, picks from every admin account and saves the uuids', async () => {
        api.fetchChangeReviewers.mockResolvedValue({ named: [{ uuid: 'u-7', name: 'IT_CAFIT', detail: 'nvdofm' }], default: false })
        api.setChangeReviewers.mockResolvedValue({ named: [{ uuid: 'u-7', name: 'IT_CAFIT', detail: 'nvdofm' }, { uuid: 'u-9', name: 'An', detail: null }], default: false })
        const wrapper = await open()
        expect(wrapper.get('[data-testid="change-reviewers-summary"]').text()).toBe('IT_CAFIT - nvdofm')

        await wrapper.get('[data-testid="change-reviewers-edit"]').trigger('click')
        const picker = wrapper.getComponent({ name: 'ReviewerPicker' })
        expect(picker.attributes('data-audience')).toBe('notice')
        expect(picker.attributes('data-value')).toBe('["u-7"]')
        picker.vm.$emit('update:modelValue', ['u-7', 'u-9'])
        await wrapper.get('[data-testid="change-reviewers-save"]').trigger('click')
        await flushPromises()

        expect(api.setChangeReviewers).toHaveBeenCalledWith(['u-7', 'u-9'])
        expect(wrapper.get('[data-testid="change-reviewers-summary"]').text()).toBe('IT_CAFIT - nvdofm, An')
        expect(wrapper.find('[data-testid="reviewer-picker"]').exists()).toBe(false)
    })

    it('shows nothing to an account that is not a Webmaster and asks the server nothing', async () => {
        access.webmaster = false
        const wrapper = await open()
        expect(wrapper.find('[data-testid="change-reviewers"]').exists()).toBe(false)
        expect(api.fetchChangeReviewers).not.toHaveBeenCalled()
    })
})
