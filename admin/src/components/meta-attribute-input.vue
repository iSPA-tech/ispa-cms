<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form-item
        v-if="attribute"
        :label="attribute.translated_label"
        :name="name"
        :rules="getRules(attribute)"
    >
        <template v-if="['text'].includes(attribute.type)">
            <a-textarea
                v-if="attribute.input_type === 'textarea'"
                v-model:value="innerValue"
                :placeholder="attribute.translated_description"
            ></a-textarea>

            <media-selection
                v-else
                v-model="innerValue"
                :limit="1"
                :required="attribute.is_required"
                :type="attribute.input_type"
            ></media-selection>
        </template>
        <template v-else-if="attribute.options.length > 1">
            <a-select
                v-model:value="innerValue"
                :mode="attribute.is_multiple ? 'tags' : 'default'"
                :multiple="attribute.is_multiple"
                :options="
                    (attribute.options || []).map((item) => {
                        return {
                            value: ['int', 'bigInt', 'tinyInt'].includes(attribute?.type)
                                ? parseInt(item.value)
                                : item.value,
                            label: item.label || '',
                        }
                    })
                "
                :placeholder="$t('text.select')"
                class="selected-demo"
            />
        </template>
        <template
            v-else-if="
                ['string', 'int', 'bigInt'].includes(attribute?.type) &&
                attribute?.input_type !== 'select'
            "
        >
            <a-input
                v-model:value="innerValue"
                :placeholder="attribute.translated_description"
                :type="attribute.input_type"
            />
        </template>
        <template v-else-if="attribute.type === 'date'">
            <a-date-picker
                v-if="['date', 'datetime'].includes(attribute.input_type)"
                v-model:value="innerValue"
                :placeholder="attribute.translated_description"
                :show-time="attribute.input_type === 'datetime'"
                :type="attribute.input_type"
            />
            <a-time-picker
                v-else
                v-model:value="innerValue"
                :placeholder="attribute.translated_description"
                :type="attribute.input_type"
            />
        </template>
        <template v-else>
            <a-select
                v-model:value="innerValue"
                :mode="attribute.is_multiple ? 'tags' : 'default'"
                :multiple="attribute.is_multiple"
                :options="
                    (attribute.options || []).map((item) => {
                        return {
                            value: item,
                            label: item.label || '',
                        }
                    })
                "
                :placeholder="$t('text.select')"
                class="selected-demo"
            />
        </template>
    </a-form-item>
</template>

<script setup>
import { defineComponent, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    attribute: {
        type: Object,
        default: () => {},
    },
    modelValue: { type: [Array, String, Number, Object], default: '' },
    name: { type: [Array, String], default: '' },
})
const emit = defineEmits(['update:modelValue'])
defineComponent({
    name: 'MetaAttributeInput',
})

const { t } = useI18n()
const innerValue = ref(props.modelValue)
//  Get Rules from attribute for form validation
const getRules = (attribute = {}) => {
    const rules = []
    if (attribute && attribute.id) {
        if (attribute.is_required) {
            rules.push({
                required: true,
                message: t('validation.required', {
                    a: attribute.translated_label || attribute.name,
                }),
            })
        }
        if (attribute.input_type === 'url') {
            rules.push({
                type: 'url',
                message: t('validation.url', {
                    a: attribute.translated_label || attribute.name,
                }),
            })
        }
    }

    return rules
}
//  Watch to update value
watch(
    () => props.modelValue,
    (val) => {
        innerValue.value = val
    }
)
//  Watch to update model value
watch(
    () => innerValue.value,
    (val) => {
        if (val !== props.modelValue) {
            emit('update:modelValue', val)
        }
    }
)
</script>
