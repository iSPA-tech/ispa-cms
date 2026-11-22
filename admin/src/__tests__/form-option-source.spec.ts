import { flushPromises, shallowMount } from '@vue/test-utils'
import { nextTick, reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FormOptionSource from '@/components/form-option-source.vue'
import { getOptionSourceOptions } from '@/libs/form-option-sources'

vi.mock('@/libs/form-option-sources', () => ({
    getOptionSourceOptions: vi.fn(),
}))

const sources = [
    {
        key: 'core.provinces',
        label: 'Tỉnh/Thành phố',
        group: 'Địa lý',
        filters: [
            {
                key: 'country',
                label: 'Quốc gia',
                type: 'reference',
                source: 'core.countries',
            },
        ],
    },
]

const mountEditor = (item: any, props = {}) => shallowMount(FormOptionSource, {
    props: {
        item,
        earlierFields: [{ field_name: 'country', name: 'Quốc gia', type: 'select' }],
        sources,
        sourcesLoading: false,
        sourcesError: false,
        ...props,
    },
    global: { mocks: { $t: (key: string) => key } },
})

describe('system option source editor', () => {
    beforeEach(() => {
        vi.mocked(getOptionSourceOptions).mockReset()
    })

    it('opens dependent-field selection before a field has been chosen', () => {
        const item = { options: [], custom: { optionsSource: { source: 'core.provinces', filters: {}, dependsOn: {} } } }
        const wrapper = mountEditor(item)
        wrapper.vm.setReferenceMode(sources[0].filters[0], 'field')
        expect(wrapper.vm.referenceMode(sources[0].filters[0])).toBe('field')
        wrapper.vm.setDependency(sources[0].filters[0], 'country')
        expect(item.custom.optionsSource.dependsOn.country).toBe('country')
    })

    it('updates the rendered dependency selector immediately when its placeholder key is added', async () => {
        vi.mocked(getOptionSourceOptions).mockResolvedValue({ options: [], selected: [], page: 1, hasMore: false })
        const item = reactive({ options: [], custom: { optionsSource: { source: 'core.provinces', filters: {}, dependsOn: {} } } })
        const wrapper = mountEditor(item)
        await flushPromises()
        expect(wrapper.find('[label="text.form_builder.filter_field"]').exists()).toBe(false)
        wrapper.vm.setReferenceMode(sources[0].filters[0], 'field')
        await nextTick()
        expect(wrapper.find('[label="text.form_builder.filter_field"]').exists()).toBe(true)
    })

    it('keeps manual options while switching modes and resets only source configuration on source change', async () => {
        const item = {
            options: [{ value: 'kept-key', label: 'Giữ nguyên' }],
            custom: { optionsSource: null },
        }
        const wrapper = mountEditor(item)

        wrapper.vm.setMode('system')
        wrapper.vm.selectSource('core.provinces')
        item.custom.optionsSource.filters.country = 'vn'
        item.custom.optionsSource.dependsOn.country = 'country'
        wrapper.vm.selectSource('another.source')

        expect(item.options).toEqual([{ value: 'kept-key', label: 'Giữ nguyên' }])
        expect(item.custom.optionsSource).toEqual({
            source: 'another.source',
            filters: {},
            dependsOn: {},
        })

        wrapper.vm.setMode('static')
        expect(item.custom.optionsSource).toBeNull()
        expect(item.options[0].value).toBe('kept-key')
    })

    it('preserves an unavailable stored source and exposes retry state without showing a raw source input', () => {
        const retry = vi.fn()
        const item = {
            options: [],
            custom: {
                optionsSource: { source: 'removed.private.source', filters: {}, dependsOn: {} },
            },
        }
        const wrapper = mountEditor(item, { sources: [], sourcesError: true, onRetrySources: retry })

        expect(wrapper.vm.selectedSourceUnavailable).toBe(true)
        expect(item.custom.optionsSource.source).toBe('removed.private.source')
        expect(wrapper.find('input[name="raw-source"]').exists()).toBe(false)

        wrapper.vm.retrySources()
        expect(retry).toHaveBeenCalledOnce()
    })

    it('renders explicit catalogue loading and error states', () => {
        const item = { options: [], custom: { optionsSource: { source: null, filters: {}, dependsOn: {} } } }
        const loading = mountEditor(item, { sources: [], sourcesLoading: true })
        expect(loading.text()).toContain('text.form_builder.option_sources_loading')

        const failed = mountEditor(item, { sources: [], sourcesError: true })
        expect(failed.text()).toContain('text.form_builder.option_sources_error')
    })

    it('loads friendly reference values through the registered catalogue source', async () => {
        vi.mocked(getOptionSourceOptions).mockResolvedValue({
            options: [{ value: 'vn', label: 'Việt Nam' }],
            selected: [{ value: 'vn', label: 'Việt Nam' }],
            page: 1,
            hasMore: false,
        })
        const item = {
            options: [],
            custom: {
                optionsSource: {
                    source: 'core.provinces',
                    filters: { country: 'vn' },
                    dependsOn: {},
                },
            },
        }
        const wrapper = mountEditor(item)

        await wrapper.vm.loadReferenceOptions(sources[0].filters[0], 'Việt', 1)
        await flushPromises()

        expect(getOptionSourceOptions).toHaveBeenCalledWith('core.countries', {
            q: 'Việt',
            page: 1,
            filters: {},
            selected: 'vn',
        })
        expect(wrapper.vm.referenceOptions.country).toEqual([{ value: 'vn', label: 'Việt Nam' }])
        expect(wrapper.vm.referenceLoading.country).toBe(false)
    })

    it('keeps reference lookup failures visible and retryable', async () => {
        vi.mocked(getOptionSourceOptions).mockRejectedValue(new Error('unavailable'))
        const item = {
            options: [],
            custom: {
                optionsSource: {
                    source: 'core.provinces',
                    filters: {},
                    dependsOn: {},
                },
            },
        }
        const wrapper = mountEditor(item)

        await wrapper.vm.loadReferenceOptions(sources[0].filters[0], '', 1)

        expect(wrapper.vm.referenceError.country).toBe(true)
        expect(wrapper.vm.referenceLoading.country).toBe(false)
    })
})
