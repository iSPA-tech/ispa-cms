<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <breadcrumbs
        :items="[
            {
                icon: 'file-tree',
                title: $t('nav.a.categories'),
                text: $t('nav.a.categories'),
            },
        ]"
    >
        <i-button @click="openForm" variant="primary">
            <template #icon>
                <i-icon class="size-3.5" icon="plus-thick" />
            </template>
            <span> {{ $t('nav.b.create') }} {{ $t('nav.a.categories') }} </span>
        </i-button>
        <template #description>
            <sub-title :title="getNavText(innerNav, route.name)" />
        </template>
    </breadcrumbs>

    <div class="i-box">
        <a-skeleton v-if="isLoading" active :rows="5" />
        <a-table
            v-else
            :columns="columns"
            :data-source="categoryRows"
            :pagination="false"
            row-key="id"
            size="small"
        >
            <template #headerCell="{ column }">
                <template v-if="column.key === 'name'">
                    {{ $t('validation.attributes.name') }} {{ $t('nav.a.categories') }}
                </template>
                <template v-if="column.key === 'language'">
                    {{ $t('nav.a.languages') }}
                </template>
                <template v-if="column.key === 'priority'">
                    <span>{{ $t('validation.attributes.priority') }}</span>
                    <a-tooltip :title="$t('text.priority.desc')">
                        <span class="text-blue-600 pl-2">
                            <i-icon class="size-4" icon="help-circle-outline" />
                        </span>
                    </a-tooltip>
                </template>
            </template>
            <template #bodyCell="{ column: col, record: row }">
                <template v-if="row && row.id > 0">
                    <template v-if="col.key === 'name'">
                        <div class="flex items-center space-x-3">
                            <image-v3
                                :alt="row.name"
                                :src="row.image"
                                class="w-[40px] border rounded-sm"
                            />
                            <div class="flex-1">
                                <p class="mb-0 inline-flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span
                                        :class="`${
                                            row.isDeleted
                                                ? 'line-through text-gray-500'
                                                : 'font-medium text-cyan-500 hover:text-cyan-700'
                                        }`"
                                        role="button"
                                        @click="editCate(row.id)"
                                        >{{ row.name }}
                                    </span>
                                    <span
                                        v-if="row.isPublished && !row.isDeleted"
                                        class="inline-flex items-center text-sm text-green-500"
                                    >
                                        <i-icon class="size-3" icon="check-circle" />
                                    </span>
                                    <span
                                        v-if="row.featured && !row.isDeleted"
                                        class="inline-flex items-center text-sm text-yellow-500"
                                    >
                                        <i-icon class="size-3" icon="star" />
                                    </span>
                                    <span
                                        v-if="row.isLocked"
                                        class="inline-flex items-center text-sm text-gray-500"
                                    >
                                        <i-icon class="size-3" icon="lock" />
                                    </span>
                                </p>
                                <p v-if="row.slug" class="text-green-600 text-sm mb-0">
                                    {{ row.slug }}
                                </p>
                            </div>
                        </div>
                    </template>
                    <template v-else-if="col.key === 'language'">
                        {{ row.language ? row.language.name : '' }}
                    </template>
                    <template v-else-if="col.key === 'priority'">
                        <a-input
                            v-model:value="priorityModel[row.id]"
                            :default-value="row.priority"
                            class="max-w-[100px]!"
                            type="number"
                            @change="updatePriority(row.id, priorityModel[row.id])"
                        />
                    </template>
                    <template v-else-if="col.key === 'action'">
                        <div class="inline-flex items-center justify-end gap-2">
                            <span
                                class="inline-flex items-center text-blue-500 cursor-pointer"
                                role="button"
                                @click="editCate(row.id)"
                            >
                                <i-icon class="size-4" icon="edit" />
                            </span>
                            <a-popconfirm
                                :cancel-text="$t('nav.b.cancel')"
                                :ok-text="$t('nav.b.delete')"
                                :title="$t('text.del-confirm')"
                                ok-type="danger"
                                @confirm="() => delCategory(row.id)"
                            >
                                <span
                                    class="inline-flex items-center text-rose-500 cursor-pointer"
                                    role="button"
                                >
                                    <i-icon class="size-4" icon="delete" />
                                </span>
                            </a-popconfirm>
                        </div>
                    </template>
                </template>
            </template>
        </a-table>

        <a-drawer
            v-model:open="formState"
            :title="`${$t(form.id > 0 ? 'nav.b.edit' : 'nav.b.create')} ${$t('nav.a.categories')}`"
            :width="$isMobile() ? '100%' : '50%'"
            @close="resetForm"
        >
            <a-form
                :model="form"
                class="cms-form"
                layout="vertical"
                @finish="submit"
                @finishFailed="onFinishFailed"
                id="category-form"
            >
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4">
                    <div>
                        <slug-v3
                            v-model="form"
                            :editable="form.config.locked"
                            :label="$t('nav.a.categories')"
                            module="category"
                            open
                        />
                    </div>
                    <div>
                        <a-form-item
                            :label="$t('validation.attributes.parent_id')"
                            name="parent_id"
                        >
                            <a-cascader
                                v-model:value="form.parent_id"
                                :field-names="{ label: 'name', value: 'id' }"
                                :options="currentOptions"
                                :placeholder="$t('text.select')"
                                change-on-select
                                class="w-full"
                                @change="setLanguage"
                            />
                        </a-form-item>

                        <select-input-language
                            v-model="form.language_id"
                            :disabled="form.parent_id && form.parent_id.length > 0"
                            :label="$t('validation.attributes.language_id')"
                            name="language_id"
                            required
                        />
                    </div>

                    <a-form-item
                        :label="$t('validation.attributes.description')"
                        class="md:col-span-2"
                        name="description"
                    >
                        <a-textarea
                            v-model:value="form.description"
                            :auto-size="{ minRows: 3, maxRows: 10 }"
                            :maxlength="2000"
                            :placeholder="$t('validation.attributes.description')"
                            show-count
                        />
                    </a-form-item>

                    <i-seo v-if="rule('category.seo')" v-model="form.seos" module="category" />

                    <template v-if="rule('category.api')">
                        <a-form-item
                            :label="$t('text.custom.showApi')"
                            :name="['config', 'showApi']"
                        >
                            <a-switch
                                v-model:checked="form.config.showApi"
                                :checked-children="$t('text.turn-on')"
                                :un-checked-children="$t('text.turn-off')"
                            />
                        </a-form-item>

                        <a-form-item
                            v-if="form.config.showApi"
                            :label="$t('text.custom.api')"
                            :name="['config', 'apiList']"
                        >
                            <a-checkbox-group v-model:value="form.config.apiList">
                                <a-checkbox
                                    v-for="api in [
                                        'universal',
                                        'mobile_customer',
                                        'mobile_business',
                                        'mobile_support',
                                    ]"
                                    :key="api"
                                    :value="api"
                                >
                                    {{ $t('text.api.' + api) }}
                                </a-checkbox>
                            </a-checkbox-group>
                            <p class="text-xs mt-1 text-slate-400">
                                {{ $t('text.e.custom-api-list') }}
                            </p>
                        </a-form-item>
                    </template>

                    <a-form-item :label="$t('text.custom.featured')" name="featured">
                        <a-switch v-model:checked="form.featured" />
                    </a-form-item>

                    <a-form-item :label="$t('validation.attributes.status')" name="status">
                        <a-select
                            v-model:value="form.status"
                            :options="
                                (statuses || [])
                                    .filter((s) => s.type === 'status')
                                    .map((s) => ({
                                        value: s.enum_key,
                                        label: `${s.name} (${s.enum_key})`,
                                    }))
                            "
                            :placeholder="$t('text.select')"
                            class="w-full"
                        >
                        </a-select>
                    </a-form-item>

                    <a-form-item
                        v-if="rule('category.locked')"
                        :label="$t('text.config.locked')"
                        :name="['config', 'locked']"
                    >
                        <a-switch v-model:checked="form.config.locked" />
                    </a-form-item>
                </div>

                <media-selection v-model="form.images" :limit="6" folder="Category" />
            </a-form>

            <template #footer>
                <div class="flex flex-nowrap items-center space-x-2 pt-4 md:pt-6">
                    <div class="flex-1" />
                    <a-button :icon="h(StopOutlined)" @click="closeForm">
                        {{ $t('nav.b.cancel') }}
                    </a-button>
                    <a-button
                        :icon="h(SaveOutlined)"
                        :loading="btnState"
                        :disabled="btnState"
                        danger
                        html-type="submit"
                        type="primary"
                        form="category-form"
                    >
                        {{ $t(form.id > 0 ? 'nav.b.save' : 'nav.b.create') }}
                    </a-button>
                </div>
            </template>
        </a-drawer>
    </div>
</template>

<script setup>
import { computed, defineComponent, nextTick, ref, h } from 'vue'
import { useRoute } from 'vue-router'
import { getNavText, iAlert, objectAssignOnlyByKeyHasValue, rule } from '@/mixins'
import { useCategories, useEnumerates } from '@/hooks'
import {
    CATEGORY_TYPE,
    CATEGORY_TYPE_STR_EVENT,
    CATEGORY_TYPE_STR_GALLERY,
    CATEGORY_TYPE_STR_PLACE,
    CATEGORY_TYPE_STR_PRODUCT,
    CATEGORY_TYPE_STR_UPLOAD,
} from '@/config'
import { deleteCategory, getCategory, storeCategory, updateCategory } from '@/libs'
import { SaveOutlined, StopOutlined } from '@ant-design/icons-vue'
import { useAppRuntime } from '@/runtime'
import { normalizeCategoryTreeRows } from './category-tree'

const props = defineProps({
    type: { type: Number, default: null },
    visible: { type: Boolean, default: false },
})
defineComponent({
    name: 'CategoryManage',
})
const columns = [
    { key: 'name', title: ['validation.attributes.name', 'nav.a.categories'] },
    { key: 'priority', title: ['validation.attributes.priority'] },
    { key: 'language', title: ['nav.a.languages'] },
    { key: 'action', align: 'right' },
]
const route = useRoute()
// Category types shown as the page subtitle, resolved from the route name.
const innerNav = [
    { name: 'post-category', text: [['nav.a.cate-post']] },
    { name: 'category-place', text: [['nav.a.cate-place']] },
    { name: 'category-item', text: [['nav.a.cate-item']] },
    { name: 'gallery-category', text: [['nav.a.cate-gallery']] },
]
const getTypeIdByNameString = () => {
    let type = 0
    if (route.name.indexOf(CATEGORY_TYPE_STR_PLACE) > -1) {
        type = CATEGORY_TYPE[CATEGORY_TYPE_STR_PLACE]
    } else if (route.name.indexOf(CATEGORY_TYPE_STR_PRODUCT) > -1) {
        type = CATEGORY_TYPE[CATEGORY_TYPE_STR_PRODUCT]
    } else if (route.name.indexOf(CATEGORY_TYPE_STR_GALLERY) > -1) {
        type = CATEGORY_TYPE[CATEGORY_TYPE_STR_GALLERY]
    } else if (route.name.indexOf(CATEGORY_TYPE_STR_EVENT) > -1) {
        type = CATEGORY_TYPE[CATEGORY_TYPE_STR_EVENT]
    } else if (route.name.indexOf(CATEGORY_TYPE_STR_UPLOAD) > -1) {
        type = CATEGORY_TYPE[CATEGORY_TYPE_STR_UPLOAD]
    }

    return type
}
const currentType = ref(getTypeIdByNameString())

const formState = ref(false)
const { data: statuses } = useEnumerates()
//  Languages
const { languages } = useAppRuntime()
const {
    isLoading,
    data: categories,
    refetch,
} = useCategories({
    type: props.type || getTypeIdByNameString(),
})
const categoryRows = computed(() => normalizeCategoryTreeRows(categories.value?.data || []))

//  Update priority of category
const updatePriority = (node, priority) => {
    updateCategory(`${node}/priority`, { priority })
        .then((res) => {
            if (res?.message) {
                iAlert(res)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}
const priorityModel = ref({})

/** Form */
const formDefault = () => {
    return {
        id: 0,
        uid: null,
        name: null,
        description: null,
        content: [{ type: 'text', value: null }],
        hashtag: null,
        parent_id: [],
        language_id: 0,
        priority: 0,
        type: 0,
        featured: false,
        status: 'status_published',
        config: {
            anonymous: true,
            requiredLogin: false,
            showApi: false,
            featured: false,
            locked: false,
        },
        image: [],
        slugs: { slug: null },
        seos: [],
        images: [],
    }
}
const btnState = ref(false)
const form = ref(formDefault())
const currentOptions = ref([])
/** Reset form */
const resetForm = () => {
    form.value = formDefault()

    form.value.type = currentType.value
}
const closeForm = () => {
    formState.value = false
    resetForm()
}
const onFinishFailed = () => {}
/** Submit the form */
const submit = (data = {}) => {
    const value = objectAssignOnlyByKeyHasValue(form.value, data)

    if (btnState.value) {
        return
    }
    btnState.value = true
    const call = value.id > 0 ? updateCategory(value.id, value) : storeCategory(value)

    call.then((res) => {
        if (res.status === 'success') {
            refetch()
            //  Clear form
            if (value.id < 1) {
                resetForm()
            }
        }
        if (res.message) {
            iAlert(res.message)
        }
    })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            btnState.value = false
        })
}
/** Set language via Parent ID */
const setLanguage = (ids = []) => {
    if (ids.length > 0) {
        //  Get last item in ids
        const id = ids[ids.length - 1]

        const cate = categories.value?.data.find((c) => id === c.id)
        if (cate) {
            form.value.language_id = cate.language_id
        }
    }
}

const delCategory = async (node) => {
    await deleteCategory(node)
        .then((res) => {
            if (res.status === 'success') {
                refetch()
            }
            if (res.message) {
                iAlert(res.message)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}
/** Edit category */
const editCate = async (id) => {
    await getCategory(id, true).then((res) => {
        if (res.data) {
            form.value = res.data
            currentOptions.value = res.data['all-categories'] || []
        }
    })

    await nextTick(() => {
        if (!form.value.config || Object.keys(form.value.config).length === 0) {
            form.value.config = {
                anonymous: true,
                requiredLogin: false,
                showApi: false,
                featured: false,
                locked: false,
            }
        }
        if (form.value.config && !form.value.config.hasOwnProperty('locked')) {
            form.value.config.locked = false
        }

        formState.value = true
    })
}

const openForm = async () => {
    formState.value = true
    resetForm()

    await nextTick(() => {
        form.value.type = currentType.value
        currentOptions.value = categories.value?.data || []
    })

    //  set default language into form from first item in language data
    if (languages.value.data && languages.value.data.length > 0) {
        form.value.language_id = languages.value.data[0].id
    }
}
//  Todo: this is an un-use function
const categoryMapping = (category = {}, disabledList = []) => {
    if (category.id) {
        category.disabled = disabledList.includes(category.id)

        if (category.children && category.children.length > 0) {
            category.children = category.children.map((child) => {
                return categoryMapping(child, disabledList)
            })
        }
    }

    return category
}
</script>
