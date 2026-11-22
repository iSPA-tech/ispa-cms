<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="xl:flex-1 w-full">
        <router-view></router-view>
    </div>
</template>

<script setup>
import { defineComponent, provide, ref, nextTick } from 'vue'
import { iAlert, objectAssignOnlyByKeyHasValue } from '@/mixins'
import { useMetaClasses } from '@/hooks'
import { isEmpty } from 'lodash-es'
import { storeUser, updateUser } from '@/libs'
import dayjs from 'dayjs'

defineComponent({
    name: 'User',
})

const defaultForm = () => {
    return {
        id: 0,
        name: null,
        email: null,
        password: null,
        mobile: null,
        type: 3,
        roles: [],
        status: 'user_active',
        avatar: [],
        meta: {},
    }
}
/** Create / Update user */
const formRef = ref(null)
provide('formRef', formRef)
const btnState = ref(false)
provide('btnState', btnState)
const form = ref(defaultForm())
provide('form', form)
/** Submit create user */
const userSubmit = (data = {}) => {
    const value = objectAssignOnlyByKeyHasValue(form.value, data)

    if (btnState.value) {
        return
    }
    btnState.value = true
    const called = value.id > 0 ? updateUser(value.uuid, value) : storeUser(value)
    called
        .then((res) => {
            if (res?.status === 'success') {
                if (value.id < 1) {
                    resetUserForm()
                }
            }
            if (res?.message) {
                iAlert(res.message)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            btnState.value = false
        })
}
provide('userSubmit', userSubmit)
const resetUserForm = () => {
    form.value = defaultForm()
}
provide('resetUserForm', resetUserForm)

//  Get meta attributes
const { data: meta_attributes } = useMetaClasses({
    target: 'User',
})
provide('meta_attributes', meta_attributes)
const initFormMeta = async (meta_attributes = []) => {
    const newFormMeta = { ...form.value.meta }

    await nextTick(() => {
        if (Array.isArray(meta_attributes)) {
            meta_attributes.forEach((classObj) => {
                if (!isEmpty(classObj.options)) {
                    classObj.options.forEach((option) => {
                        if (!newFormMeta[option.name] || newFormMeta[option.name] === undefined) {
                            newFormMeta[option.name] = {
                                value: null,
                            }
                        }
                        if (
                            isEmpty(newFormMeta[option.name]) ||
                            !newFormMeta[option.name].hasOwnProperty('id') ||
                            newFormMeta[option.name].attr_id !== option.id
                        ) {
                            newFormMeta[option.name] = {
                                meta_id: 0,
                                class_id: classObj.id,
                                type: option.type,
                                attr_id: option.id,
                                value: option.input_type === 'select' ? [] : null,
                            }
                        } else {
                            //  check if value is date
                            if (option.type === 'date') {
                                newFormMeta[option.name].value = dayjs(
                                    newFormMeta[option.name].value
                                )
                            }
                        }
                    })
                }
            })

            const newForm = { ...form.value }
            newForm.meta = newFormMeta

            form.value = newForm
        }
    })
}
provide('initFormMeta', initFormMeta)
</script>
