<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form-item
        :label="label"
        :name="name"
        :no-style="isEmpty(label)"
        :rules="
            required
                ? [
                      {
                          trigger: 'blur',
                          required: true,
                          message: $t('validation.required', { a: label }),
                      },
                  ]
                : undefined
        "
    >
        <a-select
            v-model:value="innerValue"
            :filter-option="false"
            :not-found-content="loadingState ? undefined : null"
            :options="
                (posts || []).map((e) => {
                    return {
                        label: e.name,
                        value: parseInt(e.id),
                    }
                })
            "
            :placeholder="$t('text.f.search')"
            allow-clear
            class="w-full md:min-w-[150px]"
            show-search
            @search="fetchPostDebounce"
            :disabled="disabled"
        />
    </a-form-item>
</template>

<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { debounce, isEmpty } from 'lodash-es'
import { searchFilterPosts } from '@/libs'
import { iAlert } from '@/mixins'

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: null,
    },
    name: {
        type: String,
        default: 'post_id',
    },
    label: {
        default: '',
    },
    searchable: {
        type: Boolean,
        default: true,
    },
    required: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    slug: {
        type: String,
        default: '',
    },
    type: {
        type: String,
        default: 'all',
    },
})
const emit = defineEmits(['update:modelValue', 'update:slug'])
defineComponent({
    name: 'SelectInputPost',
})

const posts = ref([])
const innerValue = ref(props.modelValue || null)
const loadingState = ref(false)
//  Process to update query and search
const fetchPost = async (value, type = 'keywords') => {
    const params = {
        [type]: value,
        category_id: 0,
        type: props.type,
    }

    loadingState.value = true
    //  Fetch user list
    await searchFilterPosts(params)
        .then((res) => {
            if (res.data) {
                posts.value = res.data
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingState.value = false
        })
}
const fetchPostDebounce = debounce(fetchPost, 500)
const isExistInList = (value) => {
    return posts.value.some((e) => e.id === value)
}
const onSelect = () => {
    //  Get post by id
    const post = posts.value.find((e) => e.id === innerValue.value)

    if (post) {
        emit('update:slug', post.slug)
    }
}

watch(
    () => innerValue.value,
    (val) => {
        emit('update:modelValue', val)

        if (val > 0) {
            onSelect()
        }
    }
)
watch(
    () => props.modelValue,
    (val) => {
        if (val > 0 && val !== innerValue && !isExistInList(val)) {
            fetchPost(val, 'id')
        }
        innerValue.value = val
    }
)
onMounted(() => {
    fetchPost(10, 'pageSize')
})
</script>
