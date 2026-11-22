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
                (users || []).map((e) => ({
                    label: e.name,
                    value: parseInt(e.id),
                }))
            "
            :placeholder="$t('text.f.search')"
            allow-clear
            class="w-full md:min-w-[150px]"
            show-search
            @search="fetchUserDebounce"
            :size="size"
        />
    </a-form-item>
</template>

<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { debounce, isEmpty } from 'lodash-es'
import { searchFilterUsers } from '@/libs'
import { iAlert } from '@/mixins'

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: null,
    },
    name: {
        type: String,
        default: 'user_id',
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
    size: {
        type: String,
        default: 'default',
    },
})
const emit = defineEmits(['update:modelValue', 'change'])

defineComponent({
    name: 'SelectInputUser',
})

const users = ref([])
const innerValue = ref(props.modelValue)
const loadingState = ref(false)
//  Process to update query and search
const fetchUser = async (value, type = 'keywords') => {
    const params = {
        [type]: value,
    }
    loadingState.value = true
    //  Fetch user list
    await searchFilterUsers(params)
        .then((res) => {
            if (res.status === 'success') {
                users.value = res.data
            } else {
                iAlert(res.message, true)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingState.value = false
        })
}
const fetchUserDebounce = debounce(fetchUser, 500)
const isExistInList = (value) => {
    return users.value.some((e) => e.id === value)
}

watch(
    () => innerValue.value,
    (val) => {
        emit('update:modelValue', val)
        emit('change', val)
    }
)
watch(
    () => props.modelValue,
    (val) => {
        if (val > 0 && val !== innerValue.value && !isExistInList(val)) {
            fetchUser(val, 'id')
        }

        innerValue.value = val
    }
)

onMounted(() => {
    fetchUser(10, 'pageSize')
})
</script>
