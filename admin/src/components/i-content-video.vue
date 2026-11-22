<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<script lang="ts" setup>
import { defineComponent, ref, watch } from 'vue'

defineComponent({ name: 'IContentVideo' })

const props = defineProps<{
    modelValue: any
    index: number
}>()
const emits = defineEmits(['update:modelValue', 'change'])

const form = ref(props.modelValue)

const updateUrl = (value: any) => {
    //  Update value target to form
    form.value[props.index].value = value.target.value
    updateModelValue()
}
const updateOptionDesktopHeight = (value: any) => {
    //  Update value target to form
    form.value[props.index].options.desktopHeight = value.target.value
    updateModelValue()
}
const updateOptionMobileHeight = (value: any) => {
    //  Update value target to form
    form.value[props.index].options.mobileHeight = value.target.value
    updateModelValue()
}
const updateModelValue = () => {
    //  Update form
    emits('update:modelValue', form.value)

    emits('change', form.value[props.index], props.index)
}

watch(
    () => props.modelValue,
    (val) => (form.value = val)
)
</script>

<template>
    <div class="grid grid-cols-3 gap-y-4 md:gap-y-0 md:gap-x-4">
        <div class="col-span-3 md:col-span-1">
            <a-form-item
                :label="$t('validation.attributes.video_url')"
                :name="['content', index, 'value']"
                class="mb-0"
                @change="updateUrl"
            >
                <a-input
                    v-model="form[index].value"
                    :default-value="form[index].value"
                    :placeholder="$t('validation.attributes.video_url')"
                    type="url"
                />
            </a-form-item>
        </div>
        <div class="col-span-3 md:col-span-1">
            <a-form-item
                :label="$t('text.video-desktop-height')"
                :name="['content', index, 'options', 'desktopHeight']"
                class="mb-0"
                @change="updateOptionDesktopHeight"
            >
                <a-input
                    v-model="form[index].options.desktopHeight"
                    :default-value="form[index].options.desktopHeight"
                    :max="1000"
                    :min="100"
                    type="number"
                />
            </a-form-item>
        </div>
        <div class="col-span-3 md:col-span-1">
            <a-form-item
                :label="$t('text.video-mobile-height')"
                :name="['content', index, 'options', 'mobileHeight']"
                class="mb-0"
                @change="updateOptionMobileHeight"
            >
                <a-input
                    v-model:value="form[index].options.mobileHeight"
                    :default-value="form[index].options.mobileHeight"
                    :max="1000"
                    :min="100"
                    type="number"
                />
            </a-form-item>
        </div>
    </div>
</template>
