<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="flex flex-wrap gap-4 *:mb-0 form-config">
        <template v-if="type === 'product'">
            <a-form-item :label="$t('text.custom.newProduct')" :name="[name, 'newProduct']">
                <a-switch
                    v-model:checked="innerForm.newProduct"
                    :checked-value="true"
                    :un-checked-value="false"
                    :un-checked-children="$t('text.turn-off')"
                    :checked-children="$t('text.turn-on')"
                />
            </a-form-item>

            <a-form-item :label="$t('text.custom.selling')" :name="[name, 'selling']">
                <a-switch
                    v-model:checked="innerForm.selling"
                    :checked-value="true"
                    :un-checked-value="false"
                    :un-checked-children="$t('text.turn-off')"
                    :checked-children="$t('text.turn-on')"
                />
            </a-form-item>

            <a-form-item :label="$t('text.custom.goodPrice')" :name="[name, 'goodPrice']">
                <a-switch
                    v-model:checked="innerForm.goodPrice"
                    :checked-value="true"
                    :un-checked-value="false"
                    :un-checked-children="$t('text.turn-off')"
                    :checked-children="$t('text.turn-on')"
                />
            </a-form-item>

            <a-form-item :label="$t('text.custom.favourite')" :name="[name, 'favourite']">
                <a-switch
                    v-model:checked="innerForm.favourite"
                    :checked-value="true"
                    :un-checked-value="false"
                    :un-checked-children="$t('text.turn-off')"
                    :checked-children="$t('text.turn-on')"
                />
            </a-form-item>
        </template>

        <a-form-item v-if="type === 'post'" :label="$t('text.post.full_page_article')" :name="[name, 'is_full_page']">
            <a-switch
                v-model:checked="innerForm.is_full_page"
                :checked-value="true"
                :un-checked-value="false"
                :un-checked-children="$t('text.turn-off')"
                :checked-children="$t('text.turn-on')"
            />
        </a-form-item>

        <a-form-item :label="$t('text.custom.featured')" :name="[name, 'featured']">
            <a-switch
                v-model:checked="innerForm.featured"
                :checked-value="true"
                :un-checked-value="false"
                :un-checked-children="$t('text.turn-off')"
                :checked-children="$t('text.turn-on')"
            />
        </a-form-item>
        <a-form-item :label="$t('text.custom.recommend')" :name="[name, 'recommended']">
            <a-switch
                v-model:checked="innerForm.recommended"
                :checked-value="true"
                :un-checked-value="false"
                :un-checked-children="$t('text.turn-off')"
                :checked-children="$t('text.turn-on')"
            />
        </a-form-item>

        <a-form-item :label="$t('text.custom.allowComment')" :name="[name, 'allowComment']">
            <a-switch
                v-model:checked="innerForm.allowComment"
                :checked-value="true"
                :un-checked-value="false"
                :un-checked-children="$t('text.turn-off')"
                :checked-children="$t('text.turn-on')"
            />
        </a-form-item>

        <a-form-item :label="$t('text.custom.requiredLogin')" :name="[name, 'requiredLogin']">
            <a-switch
                v-model:checked="innerForm.requiredLogin"
                :checked-value="true"
                :un-checked-value="false"
                :un-checked-children="$t('text.turn-off')"
                :checked-children="$t('text.turn-on')"
            />
        </a-form-item>

        <select-input-user-role
            v-if="innerForm.requiredLogin"
            v-model="innerForm.roles"
            :label="$t('text.custom.roles')"
            :multiple="true"
            :name="[name, 'roles']"
        />

        <a-form-item :label="$t('text.custom.showApi')" :name="[name, 'showApi']">
            <a-switch
                v-model:checked="innerForm.showApi"
                :checked-value="true"
                :un-checked-value="false"
                :un-checked-children="$t('text.turn-off')"
                :checked-children="$t('text.turn-on')"
            />
        </a-form-item>

        <a-form-item v-if="innerForm.showApi" :label="$t('text.custom.api')" :name="[name, 'apiList']">
            <a-checkbox-group
                v-model:value="innerForm.apiList"
                :options="
                    ['universal', 'mobile_customer', 'mobile_business', 'mobile_support'].map(
                        (api) => ({
                            label: $t('text.api.' + api),
                            value: api,
                        })
                    )
                "
            />
            <p class="text-xs mt-1 text-slate-400">
                {{ $t('text.e.custom-api-list') }}
            </p>
        </a-form-item>
    </div>
</template>
<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => {
            return {
                featured: false,
                requiredLogin: false,
                roles: [],
                showApi: false,
                apiList: ['universal'],
                allowComment: false,
                translation_on_queue: false,
                translation_total_try: 0,
                is_translated: false,
            }
        },
    },
    type: {
        type: String,
        default: 'post',
    },
    name: {
        type: String,
        default: 'config',
    },
})
const emit = defineEmits(['update:modelValue'])

defineComponent({
    name: 'IFormConfig',
})

const innerForm = ref({})
const defaultConfig = () => {
    return {
        featured: false,
        requiredLogin: false,
        roles: [],
        showApi: false,
        apiList: ['universal'],
        allowComment: false,
        bookAuthor: null,
        bookReleaseDate: null,
        recommended: false,
        ...(props.type === 'post' ? { is_full_page: false } : {}),
    }
}
const productDefault = () => {
    return {
        selling: false,
        goodPrice: false,
        favourite: false,
        newProduct: true,
    }
}

// change value and emit modelValue
const changeValue = () => {
    emit('update:modelValue', innerForm.value)
}

onMounted(() => {
    innerForm.value = { ...defaultConfig(), ...props.modelValue }

    if (props.type === 'product') {
        innerForm.value = {
            ...defaultConfig(),
            ...productDefault(),
            ...props.modelValue,
        }
    }
})

watch(
    () => innerForm.value,
    (val) => {
        if (val !== props.modelValue) {
            changeValue()
        }
    }
)

watch(
    () => props.modelValue,
    (val) => {
        if (val !== innerForm.value) {
            innerForm.value = Object.assign(defaultConfig(), val)
        }
    }
)
</script>
