<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="flex items-center space-x-2">
        <div class="flex-1"></div>
    </div>
</template>

<script setup>
import { defineComponent, inject, provide, ref, nextTick, h } from 'vue'
import { isEmpty } from 'lodash-es'
import { Form } from 'ant-design-vue'

const useForm = Form.useForm

defineComponent({
    name: 'SettingScheduler',
})

const formDefault = () => {
    return {
        id: 0,
        group_key: null,
        name: null,
        language_id: null,
        status: 'status_published',
        items: [],
    }
}
const form = ref(formDefault())
const formRuleRef = ref({})
const { resetFields, clearValidate } = useForm(form.value, formRuleRef.value)
//  Check required field in items
const checkRequiredField = (items = []) => {
    let result = true
    //  If name is empty, return false immediately
    //  If it has children, check children
    items.forEach((item) => {
        if (isEmpty(item.name) || isEmpty(item.url) || isEmpty(item.type) || isEmpty(item.title)) {
            result = false
            return false
        }
        if (item.items.length > 0) {
            result = checkRequiredField(item.items)
            if (!result) return false
        }
    })

    return result
}
</script>
