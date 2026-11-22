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
            :mode="multiple ? 'multiple' : undefined"
            :not-found-content="loadingState ? undefined : null"
            :options="
                (users || []).map((e) => ({
                    label: e.name,
                    value: parseInt(e.id),
                }))
            "
            :placeholder="placeholder || $t('text.f.search')"
            :size="size"
            allow-clear
            class="w-full md:min-w-[150px]"
            show-search
            @search="fetchUserRoleDebounce"
        />
    </a-form-item>
</template>

<script>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { debounce, isEmpty } from 'lodash-es'
import { searchFilterUserRoles } from '@/libs'
import { iAlert } from '@/mixins'

export default defineComponent({
    name: 'SelectInputUserRole',
    props: {
        modelValue: {
            type: [String, Number, Array],
            default: null,
        },
        name: {
            type: [String, Array],
            default: 'roles',
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
        multiple: {
            type: Boolean,
            default: false,
        },
        placeholder: {
            type: String,
            default: '',
        },
        size: {
            type: String,
            default: undefined,
        },
    },
    methods: { isEmpty, iAlert },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        const users = ref([])
        const innerValue = ref(props.modelValue)
        const loadingState = ref(false)
        //  Process to update query and search
        const fetchUserRole = async (value, type = 'keywords') => {
            const params = {
                [type]: value,
            }
            loadingState.value = true
            //  Fetch user list
            await searchFilterUserRoles(params)
                .then((res) => {
                    users.value = res
                })
                .catch((err) => {
                    iAlert(err, true)
                })
                .finally(() => {
                    loadingState.value = false
                })
        }
        const fetchUserRoleDebounce = debounce(fetchUserRole, 500)
        const isExistInList = (value) => {
            return users.value.some((e) => e.id === value)
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
                if (val > 0 && val !== innerValue.value && !isExistInList(val)) {
                    fetchUserRole(val, 'id')
                }
                innerValue.value = val
            }
        )
        onMounted(() => {
            fetchUserRole(10, 'pageSize')
        })

        return {
            users,
            fetchUserRole,
            innerValue,
            loadingState,
            fetchUserRoleDebounce,
        }
    },
})
</script>
