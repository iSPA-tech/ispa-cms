<template>
    <div class="form-option-source-grid mt-3 border-t border-gray-200 pt-3">
        <a-form-item class="form-option-source-full" :label="$t('text.form_builder.option_mode')">
            <a-radio-group :value="mode" @change="setMode($event.target.value)">
                <a-radio value="static">{{ $t('text.form_builder.option_mode_static') }}</a-radio>
                <a-radio value="system">{{ $t('text.form_builder.option_mode_system') }}</a-radio>
            </a-radio-group>
        </a-form-item>

        <template v-if="mode === 'system'">
            <p v-if="sourcesLoading" class="form-option-source-full text-sm text-gray-500" role="status">
                {{ $t('text.form_builder.option_sources_loading') }}
            </p>
            <div v-else-if="sourcesError" class="form-option-source-full flex items-center gap-2 text-sm text-red-600" role="alert">
                <span>{{ $t('text.form_builder.option_sources_error') }}</span>
                <a-button size="small" @click="retrySources">
                    {{ $t('text.form_builder.retry') }}
                </a-button>
            </div>
            <p v-else-if="!sources.length" class="form-option-source-full text-sm text-gray-500" role="status">
                {{ $t('text.form_builder.option_sources_empty') }}
            </p>

            <a-form-item :label="$t('text.form_builder.option_source')" :html-for="'option-source-' + item.field_name">
                <a-select
                    :id="'option-source-' + item.field_name"
                    :value="sourceConfig.source"
                    :disabled="sourcesLoading"
                    :placeholder="$t('text.form_builder.option_source_choose')"
                    class="w-full"
                    @change="selectSource"
                >
                    <a-select-opt-group
                        v-if="selectedSourceUnavailable"
                        :label="$t('text.form_builder.option_source_unavailable_group')"
                    >
                        <a-select-option :value="sourceConfig.source">
                            {{ $t('text.form_builder.option_source_unavailable') }}
                        </a-select-option>
                    </a-select-opt-group>
                    <a-select-opt-group
                        v-for="group in groupedSources"
                        :key="group.label"
                        :label="group.label"
                    >
                        <a-select-option
                            v-for="source in group.sources"
                            :key="source.key"
                            :value="source.key"
                        >
                            {{ source.label }}
                        </a-select-option>
                    </a-select-opt-group>
                </a-select>
            </a-form-item>

            <p v-if="selectedSourceUnavailable" class="form-option-source-full mb-3 text-sm text-amber-700" role="alert">
                {{ $t('text.form_builder.option_source_unavailable_warning') }}
            </p>

            <div v-for="filter in selectedSource?.filters || []" :key="filter.key" class="mb-3">
                <template v-if="filter.type === 'reference'">
                    <a-form-item :label="filter.label">
                        <a-radio-group
                            :value="referenceMode(filter)"
                            @change="setReferenceMode(filter, $event.target.value)"
                        >
                            <a-radio value="fixed">{{ $t('text.form_builder.filter_fixed') }}</a-radio>
                            <a-radio value="field" :disabled="!earlierFields.length">
                                {{ $t('text.form_builder.filter_from_field') }}
                            </a-radio>
                        </a-radio-group>
                    </a-form-item>
                    <a-form-item v-if="referenceMode(filter) === 'field'" :label="$t('text.form_builder.filter_field')" :html-for="'option-dependency-' + item.field_name + '-' + filter.key">
                        <a-select
                            :id="'option-dependency-' + item.field_name + '-' + filter.key"
                            :value="sourceConfig.dependsOn[filter.key]"
                            :placeholder="$t('text.form_builder.filter_field_choose')"
                            @change="setDependency(filter, $event)"
                        >
                            <a-select-option
                                v-for="field in earlierFields"
                                :key="field.field_name"
                                :value="field.field_name"
                            >
                                {{ field.name || $t('text.form_builder.untitled_field') }}
                            </a-select-option>
                        </a-select>
                    </a-form-item>
                    <a-form-item v-else :label="filter.label" :html-for="'option-filter-' + item.field_name + '-' + filter.key">
                        <a-select
                            :id="'option-filter-' + item.field_name + '-' + filter.key"
                            :value="sourceConfig.filters[filter.key]"
                            show-search
                            allow-clear
                            :filter-option="false"
                            :loading="referenceLoading[filter.key]"
                            :placeholder="$t('text.form_builder.filter_value_choose')"
                            @change="setFilterValue(filter, $event)"
                            @search="loadReferenceOptions(filter, $event, 1)"
                        >
                            <a-select-option
                                v-for="option in referenceOptions[filter.key] || []"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </a-select-option>
                        </a-select>
                        <div class="mt-1 flex items-center gap-2 text-xs">
                            <span v-if="referenceError[filter.key]" class="text-red-600" role="alert">
                                {{ $t('text.form_builder.filter_options_error') }}
                            </span>
                            <a-button
                                v-if="referenceHasMore[filter.key]"
                                size="small"
                                @click="loadReferenceOptions(filter, referenceQuery[filter.key] || '', (referencePage[filter.key] || 1) + 1)"
                            >
                                {{ $t('text.form_builder.load_more') }}
                            </a-button>
                        </div>
                    </a-form-item>
                </template>

                <a-form-item v-else-if="filter.type === 'choice'" :label="filter.label" :html-for="'option-filter-' + item.field_name + '-' + filter.key">
                    <a-select
                        :id="'option-filter-' + item.field_name + '-' + filter.key"
                        :value="sourceConfig.filters[filter.key]"
                        allow-clear
                        @change="setFilterValue(filter, $event)"
                    >
                        <a-select-option
                            v-for="option in filter.options || []"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </a-select-option>
                    </a-select>
                </a-form-item>

                <a-form-item v-else :label="filter.label" :html-for="'option-filter-' + item.field_name + '-' + filter.key">
                    <a-input
                        :id="'option-filter-' + item.field_name + '-' + filter.key"
                        :value="sourceConfig.filters[filter.key]"
                        @change="setFilterValue(filter, $event.target.value)"
                    />
                </a-form-item>
            </div>

            <div v-if="selectedSource" class="form-option-source-full rounded border border-gray-200 bg-white p-3">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-medium">{{ $t('text.form_builder.option_sample') }}</span>
                    <a-button size="small" :loading="sampleLoading" @click="loadSample">
                        {{ $t('text.form_builder.option_sample_refresh') }}
                    </a-button>
                </div>
                <p v-if="sampleError" class="mt-2 text-sm text-red-600" role="alert">
                    {{ $t('text.form_builder.option_sample_error') }}
                </p>
                <p v-else-if="sampleLoaded && !sampleLoading && !sampleOptions.length" class="mt-2 text-sm text-gray-500">
                    {{ $t('text.form_builder.option_sample_empty') }}
                </p>
                <div v-else class="mt-2 flex flex-wrap gap-1">
                    <a-tag v-for="option in sampleOptions" :key="option.value">{{ option.label }}</a-tag>
                </div>
            </div>
        </template>
    </div>
</template>

<script>
import { computed, defineComponent, reactive, ref, watch } from 'vue'
import { getOptionSourceOptions } from '@/libs/form-option-sources'

export default defineComponent({
    name: 'FormOptionSource',
    props: {
        item: { type: Object, required: true },
        earlierFields: { type: Array, default: () => [] },
        sources: { type: Array, default: () => [] },
        sourcesLoading: { type: Boolean, default: false },
        sourcesError: { type: Boolean, default: false },
    },
    emits: ['retry-sources'],
    setup(props, { emit }) {
        if (!props.item.custom) props.item.custom = {}

        const emptyConfig = () => ({ source: null, filters: {}, dependsOn: {} })
        const sourceConfig = computed(() => props.item.custom.optionsSource || emptyConfig())
        const mode = computed(() => props.item.custom.optionsSource ? 'system' : 'static')
        const selectedSource = computed(() =>
            props.sources.find((source) => source.key === sourceConfig.value.source)
        )
        const selectedSourceUnavailable = computed(() =>
            Boolean(sourceConfig.value.source && !selectedSource.value && !props.sourcesLoading)
        )
        const groupedSources = computed(() => {
            const groups = new Map()
            props.sources.forEach((source) => {
                const label = source.group || '—'
                if (!groups.has(label)) groups.set(label, [])
                groups.get(label).push(source)
            })
            return Array.from(groups, ([label, sources]) => ({ label, sources }))
        })

        const referenceOptions = reactive({})
        const referenceLoading = reactive({})
        const referenceError = reactive({})
        const referenceHasMore = reactive({})
        const referencePage = reactive({})
        const referenceQuery = reactive({})
        const sampleOptions = ref([])
        const sampleLoading = ref(false)
        const sampleError = ref(false)
        const sampleLoaded = ref(false)

        const ensureConfig = () => {
            if (!props.item.custom.optionsSource) props.item.custom.optionsSource = emptyConfig()
            props.item.custom.optionsSource.filters ||= {}
            props.item.custom.optionsSource.dependsOn ||= {}
            return props.item.custom.optionsSource
        }
        const setMode = (nextMode) => {
            props.item.custom.optionsSource = nextMode === 'system' ? emptyConfig() : null
        }
        const selectSource = (source) => {
            const config = ensureConfig()
            config.source = source
            const definition = props.sources.find((item) => item.key === source)
            config.filters = Object.fromEntries(
                (definition?.filters || [])
                    .filter((filter) => filter.default !== undefined)
                    .map((filter) => [filter.key, filter.default])
            )
            config.dependsOn = {}
            Object.keys(referenceOptions).forEach((key) => delete referenceOptions[key])
            sampleOptions.value = []
            sampleLoaded.value = false
        }
        const retrySources = () => emit('retry-sources')
        const referenceMode = (filter) => Object.keys(sourceConfig.value.dependsOn || {}).includes(filter.key) ? 'field' : 'fixed'
        const setReferenceMode = (filter, nextMode) => {
            const config = ensureConfig()
            if (nextMode === 'field') {
                delete config.filters[filter.key]
                config.dependsOn[filter.key] ||= null
            } else {
                delete config.dependsOn[filter.key]
                config.filters[filter.key] = filter.default ?? null
                loadReferenceOptions(filter, '', 1)
            }
        }
        const setDependency = (filter, fieldName) => {
            const config = ensureConfig()
            config.dependsOn[filter.key] = fieldName
            delete config.filters[filter.key]
        }
        const setFilterValue = (filter, value) => {
            const config = ensureConfig()
            config.filters[filter.key] = value ?? null
            delete config.dependsOn[filter.key]
        }
        const mergeOptions = (current, incoming) => {
            const options = [...current, ...incoming]
            return Array.from(new Map(options.map((option) => [option.value, option])).values())
        }
        const loadReferenceOptions = async (filter, q = '', page = 1) => {
            if (!filter.source) return
            referenceLoading[filter.key] = true
            referenceError[filter.key] = false
            referenceQuery[filter.key] = q
            try {
                const result = await getOptionSourceOptions(filter.source, {
                    q,
                    page,
                    filters: {},
                    selected: sourceConfig.value.filters?.[filter.key] ?? null,
                })
                const incoming = mergeOptions(result.options || [], result.selected || [])
                referenceOptions[filter.key] = page > 1
                    ? mergeOptions(referenceOptions[filter.key] || [], incoming)
                    : incoming
                referenceHasMore[filter.key] = Boolean(result.hasMore)
                referencePage[filter.key] = result.page || page
            } catch (error) {
                referenceError[filter.key] = true
            } finally {
                referenceLoading[filter.key] = false
            }
        }
        const loadSample = async () => {
            if (!selectedSource.value) return
            sampleLoading.value = true
            sampleError.value = false
            sampleLoaded.value = true
            try {
                const result = await getOptionSourceOptions(selectedSource.value.key, {
                    q: '',
                    page: 1,
                    filters: sourceConfig.value.filters,
                    selected: null,
                })
                sampleOptions.value = result.options || []
            } catch (error) {
                sampleError.value = true
            } finally {
                sampleLoading.value = false
            }
        }

        watch(
            () => selectedSource.value?.key,
            () => {
                selectedSource.value?.filters
                    ?.filter((filter) => filter.type === 'reference' && referenceMode(filter) === 'fixed')
                    .forEach((filter) => loadReferenceOptions(filter, '', 1))
            },
            { immediate: true }
        )

        return {
            mode,
            sourceConfig,
            selectedSource,
            selectedSourceUnavailable,
            groupedSources,
            referenceOptions,
            referenceLoading,
            referenceError,
            referenceHasMore,
            referencePage,
            referenceQuery,
            sampleOptions,
            sampleLoading,
            sampleError,
            sampleLoaded,
            setMode,
            selectSource,
            retrySources,
            referenceMode,
            setReferenceMode,
            setDependency,
            setFilterValue,
            loadReferenceOptions,
            loadSample,
        }
    },
})
</script>
