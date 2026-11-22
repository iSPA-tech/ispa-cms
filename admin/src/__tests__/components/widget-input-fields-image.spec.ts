import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref, watch } from 'vue'
import { mount } from '@vue/test-utils'
import WidgetInputFields from '@/components/widget-input-fields.vue'

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (k: string) => k, te: () => false }),
}))

const MediaSelection = defineComponent({
    name: 'MediaSelection',
    props: ['modelValue', 'limit', 'type'],
    emits: ['update:modelValue'],
    setup(props) {
        return () => h('div', { 'data-test': 'media-picker', 'data-type': props.type })
    },
})
const AFormItem = defineComponent({
    props: ['label'],
    setup(props, { slots }) {
        return () => h('label', {}, [h('span', { class: 'lbl' }, props.label), slots.default?.()])
    },
})
const AInput = defineComponent({
    props: ['value'],
    setup() {
        return () => h('input', { 'data-test': 'text-input' })
    },
})

const mountFields = (inputs: any[], modelValue: Record<string, any>) =>
    mount(WidgetInputFields, {
        props: { inputs, modelValue },
        global: {
            mocks: { $t: (k: string) => k },
            stubs: {
                'media-selection': MediaSelection,
                'a-form-item': AFormItem,
                'a-input': AInput,
                'i-icon': true,
                'i-content-textarea-editor': true,
                'widget-repeater-field': true,
            },
        },
    })

describe('widget-input-fields image input', () => {
    it('image input renders the media picker without options.src', () => {
        const wrapper = mountFields([{ name: 'cover', type: 'image', options: { label: 'Ảnh bìa' } }], { cover: [] })

        expect(wrapper.find('[data-test="media-picker"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="media-picker"]').attributes('data-type')).toBe('image')
        expect(wrapper.find('[data-test="text-input"]').exists()).toBe(false)
        expect(wrapper.text()).toContain('Ảnh bìa')
    })

    it.each(['unknown', 'upload', 'config'])('image input with options.src "%s" renders the media picker', (src) => {
        const wrapper = mountFields([{ name: 'cover', type: 'image', options: { src } }], { cover: [] })

        expect(wrapper.find('[data-test="media-picker"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="text-input"]').exists()).toBe(false)
    })

    it('picking an image emits the merged values', async () => {
        const wrapper = mountFields([{ name: 'cover', type: 'image' }], { cover: [], title: 'x' })
        const picked = [{ id: 7 }]
        wrapper.findComponent(MediaSelection).vm.$emit('update:modelValue', picked)

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([{ cover: picked, title: 'x' }])
    })
})

// The real picker copies its value and echoes every change of that copy back (media-selection.vue's two
// watchers), so a new list on each render would come back as an "edit". This stub keeps that behaviour.
const EchoMediaSelection = defineComponent({
    name: 'MediaSelection',
    props: ['modelValue', 'limit', 'type'],
    emits: ['update:modelValue', 'change'],
    setup(props, { emit }) {
        const inner = ref(props.modelValue)
        watch(
            () => props.modelValue,
            (val) => (inner.value = val)
        )
        watch(
            () => inner.value,
            (val) => emit('update:modelValue', val)
        )
        return () => h('div', { 'data-test': 'media-picker' }, (inner.value || []).map((f: any) => h('img', { src: f?.url })))
    },
})

// A page block keeps the form values and hands every change straight back, like i-content-widget-v2.vue.
const mountBlock = (inputs: any[], initial: Record<string, any>) => {
    const changes: Record<string, any>[] = []
    const Host = defineComponent({
        setup(_, { expose }) {
            const values = ref(initial)
            expose({ values })
            return () =>
                h(WidgetInputFields, {
                    inputs,
                    modelValue: values.value,
                    'onUpdate:modelValue': (next: Record<string, any>) => {
                        changes.push(next)
                        values.value = next
                    },
                })
        },
    })
    const wrapper = mount(Host, {
        global: {
            mocks: { $t: (k: string) => k },
            stubs: {
                'media-selection': EchoMediaSelection,
                'a-form-item': AFormItem,
                'a-input': defineComponent({
                    props: ['value'],
                    emits: ['change'],
                    setup(props, { emit }) {
                        return () =>
                            h('input', { 'data-test': 'text-input', value: props.value, onInput: (e: any) => emit('change', e) })
                    },
                }),
                'i-icon': true,
            },
        },
    })
    return { wrapper, changes, values: () => (wrapper.vm as any).values }
}

const blockInputs = [
    { name: 'title', type: 'text' },
    { name: 'cover', type: 'image', options: { src: 'upload' } },
]

describe('widget-input-fields stored image value that is not a list', () => {
    it.each([['local'], ['/storage/banner.jpg'], [null], [{ url: '/storage/one.jpg' }]])(
        'editing another field in a page block keeps %j unchanged',
        async (stored) => {
            const { wrapper, changes, values } = mountBlock(blockInputs, { title: 'Cũ', cover: stored })
            await wrapper.vm.$nextTick()
            expect(changes).toEqual([])

            const text = wrapper.find('[data-test="text-input"]')
            ;(text.element as HTMLInputElement).value = 'Mới'
            await text.trigger('input')
            await wrapper.vm.$nextTick()
            await wrapper.vm.$nextTick()

            expect(changes).toEqual([{ title: 'Mới', cover: stored }])
            expect(values().cover).toEqual(stored)
        }
    )

    it('a stored address shows as the current image, the same list on every render', async () => {
        const { wrapper, changes } = mountBlock(blockInputs, { title: 'Cũ', cover: '/storage/banner.jpg' })
        const shown = wrapper.findComponent(EchoMediaSelection).props('modelValue')
        expect(shown).toEqual([{ url: '/storage/banner.jpg', name: 'banner.jpg' }])
        expect(wrapper.find('img').attributes('src')).toBe('/storage/banner.jpg')

        const text = wrapper.find('[data-test="text-input"]')
        ;(text.element as HTMLInputElement).value = 'Mới'
        await text.trigger('input')
        await wrapper.vm.$nextTick()

        expect(wrapper.findComponent(EchoMediaSelection).props('modelValue')).toBe(shown)
        expect(changes).toHaveLength(1)
    })

    it('a stored word that is not an address shows as nothing picked', () => {
        const { wrapper } = mountBlock(blockInputs, { title: '', cover: 'local' })

        expect(wrapper.findComponent(EchoMediaSelection).props('modelValue')).toEqual([])
    })

    it('picking writes the list the site renderer reads', async () => {
        const { wrapper, changes, values } = mountBlock(blockInputs, { title: 'Cũ', cover: '/storage/banner.jpg' })
        const picked = [{ url: '/storage/new.jpg', name: 'new.jpg' }]
        wrapper.findComponent(EchoMediaSelection).vm.$emit('update:modelValue', picked)
        await wrapper.vm.$nextTick()
        await wrapper.vm.$nextTick()

        expect(changes).toEqual([{ title: 'Cũ', cover: picked }])
        expect(values().cover).toEqual(picked)
    })

    it('removing the shown image clears the stored value', async () => {
        const { wrapper, changes } = mountBlock(blockInputs, { title: 'Cũ', cover: '/storage/banner.jpg' })
        wrapper.findComponent(EchoMediaSelection).vm.$emit('update:modelValue', [])
        await wrapper.vm.$nextTick()

        expect(changes).toEqual([{ title: 'Cũ', cover: [] }])
    })
})
