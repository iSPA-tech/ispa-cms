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
                          required: true,
                          message: $t('validation.required', { a: label }),
                          trigger: 'change',
                      },
                  ]
                : undefined
        "
    >
        <a-select
            v-model:value="innerValue"
            :allow-clear="allowClear"
            :change-on-select="changeOnSelect"
            :class="clsx('w-full', $attrs.class)"
            :disabled="disabled"
            :loading="isLoading"
            :options="options"
            :placeholder="placeholder || $t('text.select')"
            :size="size"
        />
    </a-form-item>
</template>

<script setup>
import { computed, defineComponent, ref, watch } from 'vue'
import { isEmpty } from 'lodash-es'
import { useEnumerates } from '@/hooks'
import { useAppRuntime } from '@/runtime'
import clsx from 'clsx'

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: null,
    },
    name: {
        type: [String, Array],
        default: 'status',
    },
    label: {
        type: String,
        default: '',
    },
    type: {
        type: String,
        default: 'status',
    },
    required: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    allowClear: {
        type: Boolean,
        default: true,
    },
    placeholder: {
        type: String,
        default: '',
    },
    showEnumKey: {
        type: Boolean,
        default: true,
    },
    changeOnSelect: {
        type: Boolean,
        default: true,
    },
    size: {
        type: String,
        default: 'normal',
    },
    /** Value that means "no status filter" (for example `all`); shown as its own option instead of the raw word. */
    allValue: {
        type: String,
        default: '',
    },
    /** Translated label for the `allValue` option. */
    allLabel: {
        type: String,
        default: '',
    },
})

const emit = defineEmits(['update:modelValue', 'change'])

defineComponent({
    name: 'SelectInputStatus',
})

const { locale } = useAppRuntime()

const innerValue = ref(props.modelValue)

const { data: enumerateData, isLoading } = useEnumerates()

const statusList = computed(() => (enumerateData?.value || []).filter((s) => s.type === props.type))

const options = computed(() => {
    const list = statusList.value.map((s) => {
        const displayName = locale.value === 'vi' ? s.name : s.name_alias || s.name

        return {
            value: s.enum_key,
            label: props.showEnumKey ? `${displayName} (${s.enum_key})` : displayName,
        }
    })

    return props.allValue && props.allLabel
        ? [{ value: props.allValue, label: props.allLabel }, ...list]
        : list
})

watch(
    () => innerValue.value,
    (val) => {
        emit('update:modelValue', val)
        const currentItem = statusList.value.find((s) => s.enum_key === val)
        if (currentItem) {
            emit('change', currentItem)
        } else {
            // Emit change with null when cleared to trigger filter update
            emit('change', null)
        }
    }
)

watch(
    () => props.modelValue,
    (val) => {
        innerValue.value = val
    }
)
</script>
