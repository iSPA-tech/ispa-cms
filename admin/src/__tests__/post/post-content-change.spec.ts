import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { cloneDeep, isEqual } from 'lodash-es'
import { nextTick, ref, toRaw } from 'vue'
import IContents from '@/components/i-contents.vue'
import { defaultMocks, defaultStubs } from './i-contents-test-stubs'

vi.mock('@/mixins', () => ({
    rule: () => true,
    can: () => true,
    legacy: () => true,
}))

// Block labels are translation keys (the pipeline bundle exports them; i-contents translates them with vue-i18n).
vi.mock('vue-i18n', async (importOriginal) => ({
    ...(await importOriginal<typeof import('vue-i18n')>()),
    useI18n: () => ({
        t: (key: string) => (key === 'text.pipeline.data_chart.label' ? 'Biểu đồ dữ liệu' : key),
        te: (key: string) => key === 'text.pipeline.data_chart.label',
        locale: ref('vi'),
    }),
}))

vi.mock('@/hooks/useActiveModules', () => ({
    useActiveModules: () => ({
        isModuleExtensionAllowed: () => true,
    }),
}))

describe('i-contents component and post content change detection', () => {
    it('emits update:modelValue when an image block is added', async () => {
        const initialContent = [{ type: 'text', value: '<p>Initial text</p>' }]
        const wrapper = mount(IContents, {
            props: {
                modelValue: initialContent,
                plugins: ['text', 'image'],
            },
            global: {
                mocks: defaultMocks,
                stubs: defaultStubs,
            },
        })

        // Click Add Image button
        const addImageBtn = wrapper.findAll('button').find((b) => b.text().includes('nav.b.insert-image'))
        expect(addImageBtn).toBeDefined()
        await addImageBtn?.trigger('click')
        await nextTick()

        const emitted = wrapper.emitted('update:modelValue')
        expect(emitted).toBeDefined()
        const lastEmitted = emitted![emitted!.length - 1][0] as any[]
        expect(lastEmitted.length).toBe(2)
        expect(lastEmitted[1].type).toBe('image')
        expect(lastEmitted[1].value).toEqual([])
    })

    it('does not offer the article chart widget in a non-post content editor', () => {
        const wrapper = mount(IContents, {
            props: { modelValue: [{ type: 'text', value: '' }], target: 'Product' },
            global: { mocks: defaultMocks, stubs: defaultStubs },
        })

        expect(wrapper.text()).not.toContain('Biểu đồ dữ liệu')
    })

    it('emits update:modelValue when an image is selected/changed in ModalMediaCenter', async () => {
        const initialContent = [
            {
                id: 'content_1',
                type: 'image',
                value: [],
                options: { caption: null, alt: null, title: null, description: null },
            },
        ]
        const wrapper = mount(IContents, {
            props: {
                modelValue: initialContent,
                plugins: ['text', 'image'],
            },
            global: {
                mocks: defaultMocks,
                stubs: defaultStubs,
            },
        })

        const selectBtn = wrapper.find('.select-img-btn')
        expect(selectBtn.exists()).toBe(true)
        await selectBtn.trigger('click')
        await nextTick()

        const emitted = wrapper.emitted('update:modelValue')
        expect(emitted).toBeDefined()
        const lastEmitted = emitted![emitted!.length - 1][0] as any[]
        expect(lastEmitted[0].value).toEqual([{ id: 99, thumbnail_sm: 'img99.jpg' }])
    })

    it('emits update:modelValue when image caption or alt is updated', async () => {
        const initialContent = [
            {
                id: 'content_1',
                type: 'image',
                value: [{ id: 99, thumbnail_sm: 'img99.jpg' }],
                options: { caption: '', alt: '', title: null, description: null },
            },
        ]
        const wrapper = mount(IContents, {
            props: {
                modelValue: initialContent,
                plugins: ['text', 'image'],
            },
            global: {
                mocks: defaultMocks,
                stubs: defaultStubs,
            },
        })

        // Modify form directly to simulate caption input
        ;(wrapper.vm as any).form[0].options.caption = 'New Caption'
        await nextTick()

        const emitted = wrapper.emitted('update:modelValue')
        expect(emitted).toBeDefined()
        const lastEmitted = emitted![emitted!.length - 1][0] as any[]
        expect(lastEmitted[0].options.caption).toBe('New Caption')
    })

    it('triggers post form change detection when content array is updated with image blocks', async () => {
        const form = ref({
            id: 1,
            name: 'Sample Post',
            content: [{ type: 'text', value: '<p>Initial text</p>' }],
        })
        const originalData = ref(cloneDeep(toRaw(form.value)))
        const isFormChanged = ref(false)

        const checkChange = () => {
            const currentSnapshot = cloneDeep(toRaw(form.value))
            const baselineSnapshot = cloneDeep(toRaw(originalData.value))
            isFormChanged.value = !isEqual(currentSnapshot, baselineSnapshot)
        }

        expect(isFormChanged.value).toBe(false)

        // Simulate i-contents adding an image block and selecting image
        form.value.content = [
            { id: 'content_1', type: 'text', value: '<p>Initial text</p>' },
            {
                id: 'content_2',
                type: 'image',
                value: [{ id: 99, thumbnail_sm: 'img99.jpg' }],
                options: { caption: 'Photo caption', alt: 'Alt text' },
            },
        ]
        checkChange()

        expect(isFormChanged.value).toBe(true)
    })
})
