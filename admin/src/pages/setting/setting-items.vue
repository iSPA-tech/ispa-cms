<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="flex flex-col md:flex-row relative">
        <div class="w-full md:w-52 sm:text-right pr-3 leading-8">
            {{ settingKey ? $t(settingKey) : $t('text.not_applicable') }}
            <span v-if="required" class="text-rose-500 pl-1.5">*</span>
        </div>
        <div class="flex-1">
            <div
                v-for="(item, nameIndex) in items"
                :key="nameIndex"
                :class="{ 'mt-1': nameIndex !== 0 }"
            >
                <field-image v-if="item.type === 'image'" v-model="innerValue" />
                <field-text
                    v-else-if="item.type === 'textarea'"
                    v-model="innerValue[item.setting_key]"
                    :name="item.name"
                    :required="required"
                    :rows="item?.input_setting?.rows"
                    :setting="item"
                    no-style
                />
                <field-input
                    v-else-if="
                        ['text', 'email', 'password', 'number', 'url', 'tel'].includes(item.type) &&
                        item.input_setting.options.length < 1 &&
                        item.name !== 'VITE_TEMPLATE_FOLDER'
                    "
                    v-model="innerValue[item.setting_key]"
                    :name="item.name"
                    :required="required"
                    :setting="item"
                    no-style
                />
                <field-select
                    v-else-if="
                        item.input_setting.options.length > 0 ||
                        item.name === 'VITE_TEMPLATE_FOLDER'
                    "
                    v-model="innerValue[item.setting_key]"
                    :name="item.name"
                    :options="
                        item.name === 'VITE_TEMPLATE_FOLDER'
                            ? (settings?.template || []).map((t) => ({
                                  value: t,
                                  label: t,
                              }))
                            : item.input_setting.options
                    "
                    :required="required"
                    :setting="item"
                    no-style
                />
            </div>
            <p v-if="description" class="text-xs text-gray-500/90">{{ $t(description) }}</p>
        </div>

        <div v-if="rule('setting.update')" class="absolute -right-6 top-0 z-10">
            <span class="text-blue-500" role="button" @click="editSetting(settingKey)">
                <i-icon class="size-4" icon="edit" />
            </span>
        </div>
    </div>
</template>
<script setup>
import { rule } from '@/mixins'
import { defineComponent, ref, watch } from 'vue'
import { useSettings } from '@/hooks'

defineComponent({
    name: 'SettingItems',
})

const { data: settings } = useSettings({})
const { modelValue } = defineProps({
    modelValue: { default: null },
    settingKey: { type: String, default: '' },
    items: { type: Array, default: [] },
    description: { type: String, default: '' },
    required: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'edit'])

const innerValue = ref(modelValue ?? null)
const editSetting = (name) => {
    emit('edit', name)
}

watch(
    () => modelValue,
    (val) => {
        innerValue.value = val ?? null
    },
    { deep: true }
)

watch(
    () => innerValue.value,
    (val) => {
        emit('update:modelValue', val)
    },
    { deep: true }
)
</script>
