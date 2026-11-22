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
        <a-cascader
            v-model:value="innerValue"
            :disabled="placeId < 1 || !rule('place.create')"
            :filter-option="false"
            :not-found-content="loadingState ? undefined : null"
            :options="
                (storages || []).map((e) => {
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
            @search="fetchStorageDebounce"
            :size="size"
        />
    </a-form-item>
</template>

<script>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { debounce, isEmpty } from 'lodash-es'
import { searchFilterStorages } from '@/libs'
import { iAlert, rule } from '@/mixins'

export default defineComponent({
    name: 'SelectInputStorage',
    props: {
        modelValue: {
            type: [String, Number],
            default: null,
        },
        placeId: {
            type: Number,
            default: 0,
        },
        name: {
            type: String,
            default: 'storage_id',
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
    },
    methods: { isEmpty, iAlert, rule },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        const storages = ref([])
        const innerValue = ref(props.modelValue || null)
        const loadingState = ref(false)
        //  Process to update query and search
        const fetchStorage = async (value, type = 'keywords') => {
            const params = {
                [type]: value,
            }
            loadingState.value = true
            //  Fetch user list
            await searchFilterStorages(params)
                .then((res) => {
                    storages.value = res
                })
                .catch((err) => {
                    iAlert(err, true)
                })
                .finally(() => {
                    loadingState.value = false
                })
        }
        const fetchStorageDebounce = debounce(fetchStorage, 500)
        const isExistInList = (value) => {
            return storages.value.some((e) => e.id === value)
        }

        watch(
            () => innerValue.value,
            (val) => {
                emit('update:modelValue', val)
            }
        )
        watch(
            () => props.modelValue,
            (val) => {
                if (val > 0 && val !== innerValue && !isExistInList(val)) {
                    fetchStorage(val, 'id')
                }
                innerValue.value = val
            }
        )
        onMounted(() => {
            fetchStorage(10, 'pageSize')
        })

        return {
            storages,
            fetchStorage,
            innerValue,
            loadingState,
            fetchStorageDebounce,
        }
    },
})
</script>
