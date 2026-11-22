import { describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'

vi.mock('@/config', () => ({ apiUrl: 'https://example.test/' }))
vi.mock('@/mixins', () => ({
    removeLeftTrailingSlash: (value: string) => value.replace(/^\/+/, ''),
}))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string) => key }),
}))

import MediaSelection from '@/components/media-selection.vue'
import ModalMediaCenter from '@/components/modal-media-center.vue'

const ModalMediaCenterStub = defineComponent({
    name: 'ModalMediaCenter',
    props: ['modelValue', 'show', 'folder', 'limit', 'type', 'category', 'id'],
    emits: ['update:modelValue', 'update:show'],
    template: '<div data-test="modal-media-center"><slot name="children" /></div>',
})

const MediaCenterStub = defineComponent({
    name: 'MediaCenter',
    props: ['modelValue', 'folder', 'limit', 'show', 'type', 'category'],
    emits: ['update:modelValue'],
    template: '<div data-test="media-center" />',
})

const AModalStub = defineComponent({
    name: 'AModal',
    props: ['open'],
    emits: ['update:open', 'close'],
    template: '<div><slot name="title" /><slot /><slot name="footer" /></div>',
})

const images = () =>
    Array.from({ length: 5 }, (_, index) => ({
        id: index + 1,
        name: `proof-${index + 1}.png`,
        thumbnail_sm: `/proof-${index + 1}.png`,
        url: `/proof-${index + 1}.png`,
    }))

describe('media selection category and limit forwarding', () => {
    it('renders five selected thumbnails, forwards proof category, and removes one image', async () => {
        const selected = images()
        const wrapper = mount(MediaSelection, {
            props: {
                modelValue: selected,
                limit: 5,
                category: 'hat_cai_public_proof',
                type: 'image',
            },
            global: {
                components: {
                    ModalMediaCenter: ModalMediaCenterStub,
                },
                stubs: {
                    'i-image': { template: '<img />' },
                    'i-icon': { template: '<span />' },
                },
            },
        })

        const picker = wrapper.getComponent(ModalMediaCenterStub)
        expect(picker.props('limit')).toBe(5)
        expect(picker.props('category')).toBe('hat_cai_public_proof')
        expect(wrapper.findAll('figure')).toHaveLength(5)
        expect(wrapper.text()).toContain('(5/5)')

        await wrapper.get('span[role="button"]').trigger('click')

        expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toHaveLength(4)
        expect(wrapper.findAll('figure')).toHaveLength(4)
    })

    it('forwards the proof category from modal picker into media-center uploads', () => {
        const wrapper = mount(ModalMediaCenter, {
            props: {
                modelValue: [],
                show: true,
                limit: 5,
                category: 'hat_cai_public_proof',
                type: 'image',
            },
            global: {
                components: {
                    MediaCenter: MediaCenterStub,
                },
                stubs: {
                    'a-modal': AModalStub,
                    'a-button': { template: '<button><slot /></button>' },
                    'i-icon': { template: '<span />' },
                },
            },
        })

        const mediaCenter = wrapper.getComponent(MediaCenterStub)
        expect(mediaCenter.props('limit')).toBe(5)
        expect(mediaCenter.props('category')).toBe('hat_cai_public_proof')
    })
})
