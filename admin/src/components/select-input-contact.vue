<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form-item
        v-loading="isFetching"
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
            :disabled="disabled"
            :multiple="multiple"
            :options="selectOptions"
            :placeholder="$t('text.f.search')"
            allow-clear
            class="w-full md:min-w-[150px]"
            show-search
            @search="fetchContactDebounce"
        />
    </a-form-item>
</template>

<script setup>
import { computed, defineComponent, onMounted, reactive, ref, watch } from 'vue'
import { debounce, isEmpty } from 'lodash-es'
import { useSearchContacts } from '@/hooks'

const props = defineProps({
    modelValue: {
        type: [String, Number, Array],
        default: null,
    },
    name: {
        type: String,
        default: 'email',
    },
    label: {
        default: '',
        type: String,
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
    multiple: {
        type: Boolean,
        default: false,
    },
    limit: {
        type: Number,
        default: 0,
    },
})
const emit = defineEmits(['update:modelValue', 'contact-selected'])

defineComponent({
    name: 'SelectInputContact',
})

const queryData = reactive({})
const innerValue = ref(props.modelValue)
const loadingState = ref(false)
//  Process to update query and search
const fetchContact = async (value, type = 'keywords') => {
    const params = {
        [type]: value,
    }
    Object.assign(queryData, params)
}
const fetchContactDebounce = debounce(fetchContact, 500)
const { data, isFetching } = useSearchContacts(queryData)
const contactOptions = computed(() => (Array.isArray(data.value) ? data.value : []))
const selectOptions = computed(() =>
    contactOptions.value
        .filter((contact) => contact.email)
        .map((contact) => ({
            label: contact.name ? `${contact.name} <${contact.email}>` : contact.email,
            value: contact.email,
        }))
)
const findSelectedContacts = (value) => {
    const values = Array.isArray(value) ? value : [value]

    return contactOptions.value.filter((contact) => values.includes(contact.email))
}

watch(
    () => innerValue.value,
    (val) => {
        emit('update:modelValue', val)
        const selectedContacts = findSelectedContacts(val)
        emit(
            'contact-selected',
            props.multiple ? selectedContacts : selectedContacts[0] || null
        )
    }
)
watch(
    () => props.modelValue,
    (val) => {
        innerValue.value = val
    }
)
onMounted(() => {
    fetchContact(10, 'pageSize')
    fetchContact('id,name,email', 'select')
})
</script>
