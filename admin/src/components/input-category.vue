<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div>
        <a-form-item
            :name="name"
            :rules="[
                {
                    required: required,
                    trigger: 'blur',
                    message: $t('validation.required', {
                        a: $t('validation.attributes.category_id'),
                    }),
                },
            ]"
            class="more_tools mb-0"
        >
            <template #label>
                <div class="flex items-center flex-1">
                    <span class="flex-1">{{ $t('validation.attributes.category_id') }}</span>
                    <a-button
                        v-if="rule('category.create') && showCreate"
                        class="btn-success plain text-xs"
                        size="small"
                        @click="openCategoriesModal"
                    >
                        {{ $t('nav.b.add') }} {{ $t('nav.a.categories') }}
                    </a-button>
                </div>
            </template>

            <a-cascader
                v-model:value="innerForm[innerName]"
                :field-names="{ label: 'name', value: 'id' }"
                :multiple="multiple"
                :options="category?.data || []"
                :placeholder="$t('text.select')"
                change-on-select
                class="w-full"
                @change="handleChange"
            />
        </a-form-item>

        <a-modal
            v-model:open="categoryModalState"
            :title="$t('nav.a.categories')"
            class="modal-fullscreen"
            fullscreen
            width="100%"
            @cancel="closeCategoryModal"
        >
            <category-manage :type="type" :visible="categoryModalState"></category-manage>
            <template #footer>
                <app-modal-footer @cancel="closeCategoryModal" @submit="closeCategoryModal" />
            </template>
        </a-modal>
    </div>
</template>

<script setup>
import { defineComponent, ref, watch, inject } from 'vue'
import { rule } from '@/mixins'
import { useCategories } from '@/hooks'
import AppModalFooter from '@/components/common/AppModalFooter.vue'

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => {},
    },
    type: { type: Number, default: null },
    multiple: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    showCreate: { type: Boolean, default: true },
    name: { type: [String, Array], default: 'category_id' },
})
const categoriesList = inject('categoriesList', [])
const emit = defineEmits(['update:modelValue', 'change-last'])

defineComponent({
    name: 'InputCategory',
})

const { data: category, refetch } = useCategories({ type: props.type })
const categoryModalState = ref(false)
const innerForm = ref(props.modelValue)
const innerName = ref(null)
//  Open modal
const openCategoriesModal = () => {
    categoryModalState.value = true
}

const closeCategoryModal = () => {
    categoryModalState.value = false
}
//  Search item by node in reservation category list
const searchItem = (data = [], node = 0) => {
    let item = {}
    //  Find item in data by node, if found any => return immediate
    //  if not found, find in the children list
    data.some((i) => {
        if (i.id === node) {
            item = i
            return true
        }
        if (i.children && i.children.length) {
            item = searchItem(i.children, node)
            if (item.id) {
                return true
            }
        }
    })

    return item
}

//  Handle change event
const handleChange = (val) => {
    if (!val || !val.length) {
        return
    }
    //  Get last item in val then find it in category list, send this item to parent
    const item = searchItem(category.value?.data, val[val.length - 1])

    if (item) {
        emit('change-last', item)
    }
}

watch(
    () => categoryModalState.value,
    (val) => {
        if (!val) {
            refetch()
        }
    }
)

watch(
    [() => props.modelValue, () => props.name],
    ([val, name]) => {
        if (val !== innerForm.value) {
            innerForm.value = val
        }
        if (Array.isArray(name)) {
            innerName.value = name
        } else {
            innerName.value = name
        }
    },
    { immediate: true, deep: true }
)

watch(
    () => innerForm.value,
    (val) => {
        if (val !== props.modelValue) {
            emit('update:modelValue', val)
        }
    }
)

watch(
    () => category.value,
    (value) => {
        if (value && value.data) {
            categoriesList.value = value.data
        }
    },
    { deep: true }
)
</script>
