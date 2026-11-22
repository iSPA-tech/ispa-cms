<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form
        ref="formRef"
        :model="innerForm"
        class="v-toolbar mb-3"
        name="filter_form cms-form"
        no-style
        @finish="submitFilter"
    >
        <div class="filter-group">
            <template v-if="isSchemaMode">
                <template v-for="field in schemaFields" :key="field.key">
                    <div
                        v-if="
                            field?.key &&
                            !disableFields.includes(field.key) &&
                            innerAllowedFields.includes(field.key)
                        "
                        :class="['relative', field.widthClass]"
                    >
                        <select-input-status
                            v-if="field.type === 'status'"
                            v-model="innerForm[field.key]"
                            :allow-clear="field.allowClear"
                            :change-on-select="field.changeOnSelect ?? true"
                            :name="field.key"
                            :placeholder="
                                field?.placeholder ||
                                (field?.placeholderKey ? t(field.placeholderKey) : '')
                            "
                            :type="field.statusType || 'status'"
                            class="text-xs placeholder:text-xs *:text-xs"
                            size="small"
                            v-bind="resolveProps(field)"
                            @change="() => handleImmediateChange(field)"
                        />
                        <a-form-item v-else :name="field.key" no-style>
                            <a-input
                                v-if="field.type === 'search' || field.type === 'text'"
                                v-model:value="innerForm[field.key]"
                                :allow-clear="field.allowClear !== false"
                                :placeholder="
                                    field?.placeholder ||
                                    (field?.placeholderKey ? t(field.placeholderKey) : '')
                                "
                                :type="field.type === 'search' ? 'search' : 'text'"
                                class="text-gray-900 shadow-xs placeholder:text-gray-400 text-xs leading-[22px]"
                                size="small"
                                v-bind="resolveProps(field)"
                            />
                            <a-select
                                v-else-if="field.type === 'select'"
                                v-model:value="innerForm[field.key]"
                                :allow-clear="field.allowClear"
                                :mode="field.mode"
                                :options="resolveOptions(field)"
                                :placeholder="
                                    field?.placeholder ||
                                    (field?.placeholderKey ? t(field.placeholderKey) : '')
                                "
                                class="text-xs placeholder:text-xs *:text-xs"
                                size="small"
                                v-bind="resolveProps(field)"
                                @change="() => handleImmediateChange(field)"
                            />
                            <a-cascader
                                v-else-if="field.type === 'cascader'"
                                v-model:value="innerForm[field.key]"
                                :allow-clear="field.allowClear"
                                :change-on-select="field.changeOnSelect ?? true"
                                :options="resolveOptions(field)"
                                :placeholder="
                                    field?.placeholder ||
                                    (field?.placeholderKey ? t(field.placeholderKey) : '')
                                "
                                class="text-xs placeholder:text-xs *:text-xs"
                                size="small"
                                v-bind="resolveProps(field)"
                                @change="() => handleImmediateChange(field)"
                            />
                            <slot
                                v-else-if="field.type === 'slot'"
                                :field="field"
                                :form="innerForm"
                                :name="field.slotName || field.key"
                                :setValue="(value) => setFieldValue(field.key, value)"
                                :value="innerForm[field.key]"
                            />
                        </a-form-item>
                    </div>
                </template>
            </template>

            <template v-else>
                <div
                    v-if="
                        !disableFields.includes('keywords') &&
                        innerAllowedFields.includes('keywords')
                    "
                    class="relative"
                >
                    <a-form-item name="keywords" no-style>
                        <a-input
                            v-model:value="innerForm.keywords"
                            :placeholder="$t('nav.b.search')"
                            allowClear
                            class="text-gray-900 shadow-xs placeholder:text-gray-400 text-xs leading-[22px]"
                            size="small"
                            type="search"
                        />
                    </a-form-item>
                </div>

                <div
                    v-if="
                        !disableFields.includes('status') && innerAllowedFields.includes('status')
                    "
                    class="relative"
                >
                    <a-form-item name="status" no-style>
                        <a-select
                            v-model:value="innerForm.status"
                            class="w-28 max-w-28 text-xs placeholder:text-xs *:text-xs"
                            size="small"
                        >
                            <a-select-option value="all">
                                {{ $t('text.filter.all') }}
                            </a-select-option>
                            <a-select-option
                                v-for="item in filterStatus(statuses || [])"
                                :key="item.enum_key"
                                :value="item.enum_key"
                            >
                                {{ isVi ? item.name : item.name_alias }}
                            </a-select-option>
                            <a-select-option v-if="showDeleteFilter" value="deleted">
                                {{ $t('text.product.filter_deleted') }}
                            </a-select-option>
                        </a-select>
                    </a-form-item>
                </div>

                <div
                    v-if="
                        !disableFields.includes('confirmed') &&
                        innerAllowedFields.includes('confirmed')
                    "
                    class="relative"
                >
                    <a-form-item name="confirmed" no-style>
                        <a-select
                            v-model:value="innerForm.confirmed"
                            class="w-28 max-w-28 text-xs placeholder:text-xs *:text-xs"
                            size="small"
                        >
                            <a-select-option value="all">
                                {{ $t('text.filter.all') }}
                            </a-select-option>
                            <a-select-option value="0">
                                {{ $t('text.confirm.no') }}
                            </a-select-option>
                            <a-select-option value="1">
                                {{ $t('text.confirm.yes') }}
                            </a-select-option>
                        </a-select>
                    </a-form-item>
                </div>

                <div
                    v-if="
                        !disableFields.includes('categoryId') &&
                        innerAllowedFields.includes('categoryId')
                    "
                    class="relative"
                >
                    <a-form-item name="categoryId" no-style>
                        <a-cascader
                            v-model:value="innerForm.categoryId"
                            :options="generateCategories(categories?.data || [])"
                            :placeholder="$t('text.select')"
                            change-on-select
                            class="w-28 max-w-28 text-xs placeholder:text-xs *:text-xs"
                            size="small"
                        />
                    </a-form-item>
                </div>

                <div
                    v-if="
                        !disableFields.includes('languageId') &&
                        innerAllowedFields.includes('languageId')
                    "
                    class="relative"
                >
                    <a-form-item name="languageId" no-style>
                        <a-select
                            v-model:value="innerForm.languageId"
                            :options="
                                (languages?.data || []).map((item) => ({
                                    label: item.name,
                                    value: parseInt(item.id),
                                }))
                            "
                            :placeholder="$t('text.select')"
                            allow-clear
                            class="w-28 max-w-28 text-xs placeholder:text-xs *:text-xs"
                            size="small"
                        />
                    </a-form-item>
                </div>

                <div
                    v-if="
                        !disableFields.includes('establishedBy') &&
                        innerAllowedFields.includes('establishedBy')
                    "
                    class="relative max-w-[180p]"
                >
                    <select-input-user
                        v-model="innerForm.establishedBy"
                        class="w-28 max-w-28 text-xs placeholder:text-xs *:text-xs"
                        name="establishedBy"
                        size="small"
                    />
                </div>

                <div
                    v-if="
                        !disableFields.includes('userId') && innerAllowedFields.includes('userId')
                    "
                    class="relative max-w-[180p]"
                >
                    <select-input-user
                        v-model="innerForm.userId"
                        class="w-28 max-w-28 text-xs placeholder:text-xs *:text-xs"
                        name="userId"
                        size="small"
                    />
                </div>

                <div
                    v-if="
                        placeSelect &&
                        rule('place.create') &&
                        !disableFields.includes('placeId') &&
                        innerAllowedFields.includes('placeId')
                    "
                    class="relative max-w-[180px]"
                >
                    <component
                        :is="placeSelect"
                        v-model="innerForm.placeId"
                        class="w-28 max-w-28 text-xs placeholder:text-xs *:text-xs"
                        name="placeId"
                        size="small"
                    />
                </div>

                <div
                    v-if="
                        !disableFields.includes('storageId') &&
                        innerAllowedFields.includes('storageId')
                    "
                    class="relative"
                >
                    <select-input-storage
                        v-model="innerForm.storageId"
                        :place-id="innerForm.placeId"
                        class="w-28 max-w-28 text-xs placeholder:text-xs *:text-xs"
                        name="storageId"
                        size="small"
                    />
                </div>
            </template>

            <button
                class="text-gray-800 rounded-sm text-xs bg-blk-200/60 hover:bg-blk-300/80 transition-all px-2 py-1 inline-flex items-center space-x-1"
                type="submit"
            >
                <i-icon class="size-4" icon="filter" />
                <span>{{ $t('nav.b.filter') }}</span>
            </button>

            <button
                v-if="isShowClear()"
                class="text-gray-800 rounded-sm text-xs bg-blk-200/60 hover:bg-blk-300/80 transition-all px-2 py-1 inline-flex items-center space-x-1"
                type="button"
                @click="resetFilter"
            >
                <i-icon class="size-4" icon="close" />
                <span>{{ $t('nav.b.clear') }}</span>
            </button>
        </div>
    </a-form>
</template>

<script setup>
import { computed, defineComponent, onMounted, reactive, ref, unref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Form } from 'ant-design-vue'
import { debounce } from 'lodash-es'
import {
    bannerFilter,
    brandFilter,
    CATEGORY_TYPE,
    CATEGORY_TYPE_STR_PLACE,
    CATEGORY_TYPE_STR_POST,
    commentItemFilter,
    commentPlaceFilter,
    commentPostFilter,
    contactFilter,
    contentItemFilter,
    contentPlaceFilter,
    galleryFilter,
    itemFilter,
    metaAttributeFilter,
    orderFilter,
    placeFilter,
    postFilter,
    productBundleFilter,
    productFilter,
    translateFilter,
    userFilter,
} from '@/config'
import { useCategories, useEnumerates } from '@/hooks'
import { rule } from '@/mixins'
import { useI18n } from 'vue-i18n'
import { areFilterValuesEqual, buildSchemaDefaults, normalizeFilterValues, } from '@/components/filter-utils'
import { useAppRuntime } from '@/runtime'
import { adminResourceWidgets } from '@/config/admin-modules'
import { resolveWidget } from '@/components/resource/widgets'

// The place picker belongs to the GeoPlaces module (its admin widget); without the module there is no place filter.
const placeSelect = adminResourceWidgets['geoplaces.place-select'] ? resolveWidget('geoplaces.place-select', { mode: 'input' }) : null

const useForm = Form.useForm

const props = defineProps({
    schema: {
        type: Array,
        default: undefined,
    },
    allowedFields: {
        type: Array,
        default: () => [],
    },
    title: {
        type: String,
        default: 'text.filter.title',
    },
    categoryType: {
        type: String,
        default: CATEGORY_TYPE_STR_POST,
    },
    statusGroup: {
        type: [String, Array],
        default: 'status',
    },
    module: {
        type: String,
        default: '',
    },
    disableFields: {
        type: Array,
        default: () => [],
    },
    showDeleteFilter: {
        type: Boolean,
        default: false,
    },
    syncRoute: {
        type: Boolean,
        default: true,
    },
    routeName: {
        type: String,
        default: '',
    },
    parseQuery: {
        type: Function,
        default: null,
    },
    serializeQuery: {
        type: Function,
        default: null,
    },
    defaults: {
        type: Object,
        default: null,
    },
    debounceDefault: {
        type: Number,
        default: 400,
    },
    autoSubmitOnChange: {
        type: Boolean,
        default: true,
    },
})

const emit = defineEmits(['submit', 'change'])

defineComponent({
    name: 'Filter',
})

const getDefaultFilterByModule = (module = '') => {
    let filter = {}
    if (module === CATEGORY_TYPE_STR_PLACE) {
        filter = { ...placeFilter }
    }
    if (module === 'product') {
        filter = { ...productFilter }
    }
    if (module === 'productBundle') {
        filter = { ...productBundleFilter }
    }
    if (module === 'banner') {
        filter = { ...bannerFilter }
    }
    if (module === 'brand') {
        filter = { ...brandFilter }
    }
    if (module === 'item') {
        filter = { ...itemFilter }
    }
    if (module === 'post') {
        filter = { ...postFilter }
    }
    if (module === 'place_content') {
        filter = { ...contentPlaceFilter }
    }
    if (module === 'item_content') {
        filter = { ...contentItemFilter }
    }
    if (module === 'place_comment') {
        filter = { ...commentPlaceFilter }
    }
    if (module === 'post_comment') {
        filter = { ...commentPostFilter }
    }
    if (module === 'item_comment') {
        filter = { ...commentItemFilter }
    }
    if (module === 'user') {
        filter = { ...userFilter }
    }
    if (module === 'translate') {
        filter = { ...translateFilter }
    }
    if (module === 'contact') {
        filter = { ...contactFilter }
    }
    if (module === 'gallery') {
        filter = { ...galleryFilter }
    }
    if (module === 'metaAttribute') {
        filter = { ...metaAttributeFilter }
    }
    if (module === 'order') {
        filter = { ...orderFilter }
    }

    return filter
}

const { t } = useI18n()

const { locale } = useAppRuntime()
const isVi = computed(() => locale.value === 'vi')
const route = useRoute()
const router = useRouter()
const { data: statuses } = useEnumerates()
const shouldSyncRoute = computed(() => props.syncRoute !== false)
const hasLegacyProps = computed(
    () =>
        Boolean(props.module) ||
        (Array.isArray(props.allowedFields) && props.allowedFields.length > 0)
)

//  Filter status list by statusGroup
const filterStatus = (items = []) => {
    let newStatusGroup = props.statusGroup
    if (typeof props.statusGroup === 'string') {
        newStatusGroup = [props.statusGroup]
    }
    return items.filter((item) => newStatusGroup.includes(item.type))
}

const fallbackStatusOptions = computed(() => {
    const source = statuses?.value ?? []
    const list = Array.isArray(source) ? source : []
    const filtered = filterStatus(list)
    const currentLocale = unref(locale)

    return [
        { label: t('text.filter.all'), value: 'all' },
        ...filtered.map((item) => ({
            label:
                currentLocale === 'vi' ? item.name : item.name_alias || item.name || item.enum_key,
            value: item.enum_key,
        })),
    ]
})

const fallbackSchema = computed(() => [
    {
        key: 'keywords',
        type: 'search',
        placeholderKey: 'nav.b.search',
        valueType: 'string',
        widthClass: 'w-56',
        defaultValue: '',
    },
    {
        key: 'status',
        type: 'status',
        placeholderKey: 'validation.attributes.status',
        options: fallbackStatusOptions,
        allowClear: true,
        valueType: 'string',
        widthClass: 'w-28',
        defaultValue: 'all',
        statusType: 'status',
    },
])

const rawSchemaFields = computed(() => (Array.isArray(props.schema) ? props.schema : []))
const shouldUseFallbackSchema = computed(
    () => shouldSyncRoute.value && rawSchemaFields.value.length === 0 && !hasLegacyProps.value
)
const schemaFields = computed(() =>
    shouldUseFallbackSchema.value ? fallbackSchema.value : rawSchemaFields.value
)
const isSchemaMode = computed(() => schemaFields.value.length > 0)
const schemaFieldKeys = computed(() =>
    schemaFields.value.map((field) => field?.key).filter(Boolean)
)
const schemaDefaults = computed(() => {
    const baseDefaults = buildSchemaDefaults(schemaFields.value)
    if (props.defaults && typeof props.defaults === 'object') {
        return { ...baseDefaults, ...props.defaults }
    }
    return baseDefaults
})

const defaultFilter = computed(() =>
    isSchemaMode.value ? schemaDefaults.value : getDefaultFilterByModule(props.module)
)
const innerAllowedFields = computed(() => {
    if (isSchemaMode.value) {
        return schemaFieldKeys.value
    }
    if (props.allowedFields && props.allowedFields.length) {
        return props.allowedFields
    }
    return Object.keys(defaultFilter.value)
})
const innerForm = reactive({ ...defaultFilter.value })
const innerRules = reactive({})
const { resetFields } = useForm(innerForm, innerRules)
const resolvedRouteName = computed(() => props.routeName || route.name)
const isSchemaFieldActive = (field) =>
    field?.key &&
    !props.disableFields.includes(field.key) &&
    innerAllowedFields.value.includes(field.key)

//  Categories generate list
const generateCategories = (categories = []) => {
    const categoriesMapped = categoriesMapping(categories)

    //  Add first item for "No category"
    categoriesMapped.unshift({
        label: t('text.filter.no_category'),
        value: 0,
    })

    return categoriesMapped
}

const categoriesMapping = (categories = []) => {
    return categories.map((item) => ({
        label: item.id > 0 ? item.name : t('text.filter.all'),
        value: item.id > 0 ? parseInt(item.id) : 'all',
        children: categoriesMapping(item.children),
    }))
}

// Schema mode helpers: keep parsing/serializing consistent across routes.
const isSchemaEmptyValue = (value) => {
    if (value === '' || value === null || value === undefined || value === 'all') {
        return true
    }
    return Array.isArray(value) ? value.length === 0 : false
}

const resolveSchemaQueryKey = (field) => field?.queryKey || field?.key

const resolveSchemaValueType = (field) => field?.valueType || 'string'

const normalizeSchemaArray = (value, field) => {
    const delimiter = field?.arrayDelimiter || ','
    const itemType = field?.arrayItemType || 'string'
    let items = []

    if (Array.isArray(value)) {
        items = value
    } else if (typeof value === 'string') {
        items = value.split(delimiter)
    } else if (!isSchemaEmptyValue(value)) {
        items = [value]
    }

    const filtered = items
        .map((item) => (typeof item === 'string' ? item.trim() : item))
        .filter((item) => !isSchemaEmptyValue(item))

    if (itemType === 'number') {
        return filtered.map((item) => parseInt(item, 10)).filter((item) => Number.isFinite(item))
    }

    return filtered.map((item) => String(item))
}

const castSchemaValue = (value, field) => {
    const valueType = resolveSchemaValueType(field)

    if (valueType === 'array') {
        const items = normalizeSchemaArray(value, field)
        return items.length ? items : null
    }

    if (valueType === 'number') {
        if (isSchemaEmptyValue(value)) {
            return null
        }
        const parsed = parseInt(value, 10)
        return Number.isFinite(parsed) ? parsed : null
    }

    if (valueType === 'boolean') {
        if (isSchemaEmptyValue(value)) {
            return null
        }
        if (value === true || value === false) {
            return value
        }
        const normalized = String(value).toLowerCase()
        if (normalized === '1' || normalized === 'true') {
            return true
        }
        if (normalized === '0' || normalized === 'false') {
            return false
        }
        return null
    }

    if (isSchemaEmptyValue(value)) {
        return null
    }

    return String(value)
}

const normalizeSchemaValue = (value, field) => {
    const casted = castSchemaValue(value, field)
    if (typeof field?.normalize === 'function') {
        return field.normalize(casted)
    }
    return casted
}

const areSchemaValuesEqual = (currentValue, defaultValue) => {
    if (Array.isArray(currentValue) || Array.isArray(defaultValue)) {
        const currentArray = Array.isArray(currentValue) ? currentValue : []
        const defaultArray = Array.isArray(defaultValue) ? defaultValue : []

        if (currentArray.length !== defaultArray.length) {
            return false
        }

        return currentArray.every((value, index) => value === defaultArray[index])
    }

    return currentValue === defaultValue
}

const cleanupSchemaQuery = (query = {}) => {
    const cleaned = {}

    Object.entries(query).forEach(([key, value]) => {
        if (isSchemaEmptyValue(value)) {
            return
        }
        cleaned[key] = value
    })

    return cleaned
}

const parseBySchema = (query = {}, schema = [], defaults = {}) => {
    const parsed = {}
    const customParsed =
        typeof props.parseQuery === 'function' ? props.parseQuery(query) || {} : null

    schema.forEach((field) => {
        if (!field?.key) {
            return
        }

        if (isSchemaMode.value && !isSchemaFieldActive(field)) {
            return
        }

        const key = field.key

        if (customParsed) {
            if (!Object.prototype.hasOwnProperty.call(customParsed, key)) {
                parsed[key] = defaults[key]
                return
            }

            const normalized = normalizeSchemaValue(customParsed[key], field)
            parsed[key] = isSchemaEmptyValue(normalized) ? defaults[key] : normalized
            return
        }

        const queryKey = resolveSchemaQueryKey(field)
        const rawValue = query?.[queryKey]
        const normalized = normalizeSchemaValue(rawValue, field)
        parsed[key] = isSchemaEmptyValue(normalized) ? defaults[key] : normalized
    })

    return parsed
}

const serializeBySchema = (
    form = {},
    schema = [],
    defaults = {},
    keepUnknownQuery = false,
    currentRouteQuery = {}
) => {
    if (typeof props.serializeQuery === 'function') {
        const customSerialized = props.serializeQuery(form) || {}
        const merged = { ...customSerialized }

        if (keepUnknownQuery) {
            Object.entries(currentRouteQuery || {}).forEach(([key, value]) => {
                if (Object.prototype.hasOwnProperty.call(merged, key)) {
                    return
                }
                merged[key] = value
            })
        }

        return cleanupSchemaQuery(merged)
    }

    const serialized = {}
    const schemaQueryKeys = new Set()

    schema.forEach((field) => {
        if (!field?.key) {
            return
        }

        const queryKey = resolveSchemaQueryKey(field)
        schemaQueryKeys.add(queryKey)

        if (isSchemaMode.value && !isSchemaFieldActive(field)) {
            return
        }

        const key = field.key

        const normalizedValue = normalizeSchemaValue(form?.[key], field)
        const normalizedDefault = normalizeSchemaValue(defaults?.[key], field)

        if (isSchemaEmptyValue(normalizedValue)) {
            return
        }

        if (areSchemaValuesEqual(normalizedValue, normalizedDefault)) {
            return
        }

        const valueType = resolveSchemaValueType(field)

        if (valueType === 'array') {
            if (Array.isArray(normalizedValue) && normalizedValue.length) {
                const delimiter = field?.arrayDelimiter || ','
                serialized[queryKey] = normalizedValue.join(delimiter)
            }
            return
        }

        if (valueType === 'boolean') {
            serialized[queryKey] = normalizedValue ? '1' : '0'
            return
        }

        if (valueType === 'number') {
            serialized[queryKey] = String(normalizedValue)
            return
        }

        serialized[queryKey] = String(normalizedValue)
    })

    if (keepUnknownQuery) {
        Object.entries(currentRouteQuery || {}).forEach(([key, value]) => {
            if (schemaQueryKeys.has(key) || Object.prototype.hasOwnProperty.call(serialized, key)) {
                return
            }
            serialized[key] = value
        })
    }

    return cleanupSchemaQuery(serialized)
}

const legacyParseQuery = (query) => {
    const filter = defaultFilter.value
    const newQuery = {
        ...filter,
        ...query,
    }
    const checkOrRemove = ['userId', 'languageId', 'placeId', 'storageId', 'establishedBy']
    checkOrRemove.forEach((item) => {
        if (!query[item]) {
            newQuery[item] = filter[item]
        } else {
            newQuery[item] = parseInt(query[item])
        }
    })

    if (!query.status) {
        newQuery.status = filter.status
    }
    if (!query.confirmed) {
        newQuery.confirmed = filter.confirmed
    }
    if (query.categoryId && query.categoryId.length > 0) {
        const newCategoryId = []
        if (query.categoryId.indexOf(',') >= 0) {
            query.categoryId.split(',').forEach((item) => {
                if (item !== 'all') {
                    newCategoryId.push(parseInt(item))
                }
            })
            newQuery.categoryId = newCategoryId
        } else {
            newQuery.categoryId = query.categoryId !== 'all' ? [parseInt(query.categoryId)] : []
        }
    }
    if (!query.page) {
        newQuery.page = filter.page
    }
    if (!query.perPage) {
        newQuery.perPage = filter.perPage
    }
    // delete all undefined value or null value
    Object.keys(newQuery).forEach((key) => {
        if (newQuery[key] === undefined || newQuery[key] === null) {
            delete newQuery[key]
        }
    })

    return newQuery
}

const parseRouteQuery = (query) => {
    if (isSchemaMode.value) {
        return parseBySchema(query, schemaFields.value, defaultFilter.value)
    }

    if (typeof props.parseQuery === 'function') {
        return props.parseQuery(query)
    }

    return legacyParseQuery(query)
}

const initFilter = () => {
    return {
        ...defaultFilter.value,
        ...parseRouteQuery(route.query),
    }
}

//  Categories data
const { data: categories } = useCategories(
    {
        type: CATEGORY_TYPE[props.categoryType],
    },
    {
        enabled: computed(() => innerAllowedFields.value.includes('categoryId')),
    }
)
//  Languages
const { languages } = useAppRuntime()
//  Clear state
const isShowClear = () => {
    if (isSchemaMode.value) {
        return schemaFields.value.some((field) => {
            if (!isSchemaFieldActive(field)) {
                return false
            }

            const currentValue = normalizeSchemaValue(innerForm[field.key], field)
            const defaultValue = normalizeSchemaValue(defaultFilter.value[field.key], field)

            return !areSchemaValuesEqual(currentValue, defaultValue)
        })
    }

    const originalFilter = { ...defaultFilter.value }
    //  Check if any field in innerForm is not empty, show clear button
    return innerAllowedFields.value.some((key) => {
        if (!areFilterValuesEqual(innerForm[key], originalFilter[key])) {
            return true
        }

        return false
    })
}

const buildAllowedQueryKeys = () => {
    const reservedKeys = ['page', 'pageSize', 'page_size', 'slug']
    return [...new Set([...innerAllowedFields.value, ...reservedKeys])]
}

const serializeRouteQuery = (query) => {
    if (isSchemaMode.value) {
        return serializeBySchema(query, schemaFields.value, defaultFilter.value, true, route.query)
    }

    if (typeof props.serializeQuery === 'function') {
        return props.serializeQuery(query)
    }

    const legacyQuery = { ...query }
    if (legacyQuery.categoryId) {
        legacyQuery.categoryId = legacyQuery.categoryId.join(',')
    }
    return legacyQuery
}

const resolveOptions = (field) => {
    if (!field) {
        return []
    }
    const source = typeof field.options === 'function' ? field.options(innerForm) : field.options
    const resolved = unref(source)

    return Array.isArray(resolved) ? resolved : []
}

const resolveProps = (field) => {
    if (!field) {
        return {}
    }
    const source = typeof field.props === 'function' ? field.props(innerForm) : field.props
    const resolved = unref(source)

    return resolved && typeof resolved === 'object' ? resolved : {}
}

const setFieldValue = (key, value) => {
    if (!key) {
        return
    }
    innerForm[key] = value
}

const normalizeSchemaFormValues = (form = {}) => {
    const normalized = {}

    schemaFields.value.forEach((field) => {
        if (!isSchemaFieldActive(field)) {
            return
        }

        const value = normalizeSchemaValue(form[field.key], field)
        if (isSchemaEmptyValue(value)) {
            return
        }

        normalized[field.key] = value
    })

    return normalized
}

const resolveFieldDebounce = (field) => {
    if (!field || !field.key) {
        return null
    }

    if (typeof field.debounce === 'number') {
        return field.debounce
    }

    if (field.type === 'search') {
        return props.debounceDefault
    }

    return null
}

const handleImmediateChange = (field) => {
    if (field?.emitChange === false) {
        return
    }

    if (isSchemaMode.value && resolveFieldDebounce(field) !== null) {
        return
    }

    if (isSchemaMode.value && resolveFieldDebounce(field) === null && props.autoSubmitOnChange) {
        submitFilter(innerForm)
        return
    }

    if (isSchemaMode.value) {
        emit('change', normalizeSchemaFormValues(innerForm))
        return
    }

    emit('change', normalizeFilterValues(innerForm, defaultFilter.value))
}

const resolveSchemaField = (key) =>
    schemaFields.value.find((field) => field?.key === key || field?.queryKey === key)

const resolvePageSizeField = () => resolveSchemaField('pageSize') || resolveSchemaField('page_size')

const applySchemaPaging = (query = {}, formValues = {}, { resetPage = true } = {}) => {
    const nextQuery = { ...query }
    const currentQuery = route.query || {}
    const pageField = resolveSchemaField('page')

    if (!pageField) {
        if (resetPage) {
            nextQuery.page = 1
        } else if (!Object.prototype.hasOwnProperty.call(nextQuery, 'page')) {
            const currentPage = !isSchemaEmptyValue(formValues?.page)
                ? formValues.page
                : currentQuery.page
            if (!isSchemaEmptyValue(currentPage)) {
                nextQuery.page = String(currentPage)
            }
        }
    }

    const pageSizeField = resolvePageSizeField()
    const currentPageSizeKey = Object.prototype.hasOwnProperty.call(currentQuery, 'pageSize')
        ? 'pageSize'
        : Object.prototype.hasOwnProperty.call(currentQuery, 'page_size')
        ? 'page_size'
        : null

    if (pageSizeField) {
        const queryKey = resolveSchemaQueryKey(pageSizeField)
        const alternateKey =
            queryKey === 'pageSize' ? 'page_size' : queryKey === 'page_size' ? 'pageSize' : null
        if (alternateKey && Object.prototype.hasOwnProperty.call(nextQuery, alternateKey)) {
            delete nextQuery[alternateKey]
        }
        if (!Object.prototype.hasOwnProperty.call(nextQuery, queryKey)) {
            if (currentPageSizeKey && !isSchemaEmptyValue(currentQuery[currentPageSizeKey])) {
                nextQuery[queryKey] = String(currentQuery[currentPageSizeKey])
            } else {
                const formValue = normalizeSchemaValue(
                    formValues?.[pageSizeField.key],
                    pageSizeField
                )
                if (!isSchemaEmptyValue(formValue)) {
                    nextQuery[queryKey] = String(formValue)
                } else {
                    const defaultValue = normalizeSchemaValue(
                        defaultFilter.value[pageSizeField.key],
                        pageSizeField
                    )
                    if (!isSchemaEmptyValue(defaultValue)) {
                        nextQuery[queryKey] = String(defaultValue)
                    }
                }
            }
        }
    } else if (!Object.prototype.hasOwnProperty.call(nextQuery, currentPageSizeKey || 'pageSize')) {
        const formPageSize = !isSchemaEmptyValue(formValues?.pageSize)
            ? formValues.pageSize
            : formValues?.page_size
        const fallbackPageSize = !isSchemaEmptyValue(formPageSize)
            ? formPageSize
            : currentPageSizeKey
            ? currentQuery[currentPageSizeKey]
            : null

        if (!isSchemaEmptyValue(fallbackPageSize)) {
            const targetKey = currentPageSizeKey || 'pageSize'
            nextQuery[targetKey] = String(fallbackPageSize)
        }
    }

    return cleanupSchemaQuery(nextQuery)
}

const submitFilter = (values) => {
    const formValues = values || innerForm
    const normalized = isSchemaMode.value
        ? normalizeSchemaFormValues(formValues)
        : normalizeFilterValues(formValues, defaultFilter.value)

    emit('submit', normalized)

    if (!shouldSyncRoute.value) {
        return
    }

    if (isSchemaMode.value) {
        const serializedQuery = serializeRouteQuery(formValues)
        const nextQuery = applySchemaPaging(serializedQuery, formValues, { resetPage: true })

        router.push({
            name: resolvedRouteName.value,
            query: nextQuery,
        })
        return
    }

    //  Reset page to 1 when filter
    const newQuery = { ...route.query, ...formValues, page: 1 }
    const allowedKeys = buildAllowedQueryKeys()

    //  Remove not allowed fields
    Object.keys(newQuery).forEach((key) => {
        if (!allowedKeys.includes(key)) {
            delete newQuery[key]
        }
    })

    const serializedQuery = serializeRouteQuery(newQuery)

    router.push({
        name: resolvedRouteName.value,
        query: serializedQuery,
    })
}

const applyRoute = (values = innerForm, { replace = true, resetPage = false } = {}) => {
    if (!shouldSyncRoute.value) {
        return
    }

    if (isSchemaMode.value) {
        const serializedQuery = serializeRouteQuery(values)
        const nextQuery = applySchemaPaging(serializedQuery, values, { resetPage })
        const action = replace ? router.replace : router.push

        action({
            name: resolvedRouteName.value,
            query: nextQuery,
        })
        return
    }

    const newQuery = { ...route.query, ...values }
    const allowedKeys = buildAllowedQueryKeys()

    Object.keys(newQuery).forEach((key) => {
        if (!allowedKeys.includes(key)) {
            delete newQuery[key]
        }
    })

    const serializedQuery = serializeRouteQuery(newQuery)
    const action = replace ? router.replace : router.push

    action({
        name: resolvedRouteName.value,
        query: serializedQuery,
    })
}

const getValues = () => {
    if (isSchemaMode.value) {
        return normalizeSchemaFormValues(innerForm)
    }

    return normalizeFilterValues(innerForm, defaultFilter.value)
}

//  Reset filter
const resetFilter = () => {
    resetFields()
    Object.assign(innerForm, defaultFilter.value)

    if (isSchemaMode.value) {
        submitFilter(innerForm)
        return
    }

    if (shouldSyncRoute.value) {
        router.push({
            name: resolvedRouteName.value,
            query: {},
        })
    }
}

const searchFields = computed(() => {
    if (isSchemaMode.value) {
        return schemaFields.value.filter(
            (field) => field?.key && resolveFieldDebounce(field) !== null
        )
    }
    return [
        {
            key: 'keywords',
            debounce: props.debounceDefault,
        },
    ]
})

const searchWatchStops = ref([])

const setupSearchWatchers = () => {
    searchWatchStops.value.forEach((stop) => stop())
    searchWatchStops.value = []

    searchFields.value.forEach((field) => {
        if (
            !field?.key ||
            (isSchemaMode.value && !isSchemaFieldActive(field)) ||
            (!isSchemaMode.value &&
                (props.disableFields.includes(field.key) ||
                    !innerAllowedFields.value.includes(field.key)))
        ) {
            return
        }

        const delay = resolveFieldDebounce(field) ?? props.debounceDefault
        const debounced = debounce(() => {
            if (isSchemaMode.value) {
                submitFilter(innerForm)
                return
            }

            emit('change', normalizeFilterValues(innerForm, defaultFilter.value))
        }, delay)

        const stop = watch(
            () => innerForm[field.key],
            () => {
                debounced()
            }
        )

        searchWatchStops.value.push(() => {
            debounced.cancel()
            stop()
        })
    })
}

watch(schemaFields, setupSearchWatchers, { deep: true, immediate: true })
watch(innerAllowedFields, setupSearchWatchers, { deep: true })
watch(
    () => props.disableFields,
    () => {
        setupSearchWatchers()
    },
    { deep: true }
)

onMounted(() => {
    Object.assign(innerForm, initFilter())
})

defineExpose({
    applyRoute,
    getValues,
})
</script>
