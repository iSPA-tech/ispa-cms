<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div v-if="rule('gallery.viewAny')">
        <router-view />
    </div>
    <no-permission-error v-else />
</template>

<script setup>
import { defineComponent, provide, ref } from 'vue'
import { rule, iAlert } from '@/mixins'
import { storeGallery, updateGallery } from '@/libs'
import dayjs from 'dayjs'
import { useI18n } from 'vue-i18n'
import ajax from '@/ajax'

defineComponent({
    name: 'Gallery',
})

const { t } = useI18n()

const formDefault = () => {
    return {
        id: 0,
        name: null,
        description: null,
        hashtag: [],
        content: [{ type: 'text', value: null }],
        language_id: 1,
        user_id: null,
        custom: {},
        status: 'status_draft',
        published_at: dayjs(new Date()),
        slugs: { slug: null },
        categories: [],
        category_id: [],
        images: [],
    }
}
provide('formDefault', formDefault)
const form = ref(formDefault())
provide('form', form)
const resetForm = () => {
    form.value = formDefault()
}
provide('resetForm', resetForm)

const formRule = ref({
    name: [
        {
            required: true,
            trigger: 'blur',
            message: t('validation.required', { a: t('validation.attributes.name') }),
        },
    ],
    slugs: {
        type: 'object',
        fields: {
            slug: {
                required: true,
                trigger: 'blur',
                message: t('validation.required', { a: t('validation.attributes.slug') }),
            },
        },
    },
    language_id: [
        {
            required: true,
            type: 'number',
            min: 1,
            trigger: 'blur',
            message: t('validation.required', { a: t('validation.attributes.language_id') }),
        },
    ],
})
provide('formRule', formRule)
const setForm = (val) => {
    resetForm()
    form.value = {
        ...formDefault(),
        ...val,
        published_at: dayjs(val.published_at),
    }

    if (Object.keys(form.value.config).length === 0) {
        form.value.config = formDefault().config
    }
    if (!val.slugs || Object.keys(val.slugs).length === 0) {
        form.value.slugs = { slug: null }
    }
    if (!val.seos || val.seos.length === 0) {
        form.value.seos = []
    }
}
provide('setForm', setForm)
//const editGallery = inject('editGallery')
const editGallery = async (id) => {
    if (id > 0) {
        await ajax.get('gallery/' + id + '/edit').then((res) => {
            if (res.data.status === 'success') {
                setForm(res.data.data)
            }
            if (res.data.message) {
                iAlert(res.data)
            }
        })
    }
}
provide('editBanner', editGallery)
const btnState = ref(false)
provide('btnState', btnState)
const submitForm = (value = {}) => {
    if (btnState.value) {
        return
    }
    btnState.value = true
    const call =
        form.value.id > 0 ? updateGallery(form.value.id, form.value) : storeGallery(form.value)
    call.then((res) => {
        if (res.status === 'success') {
            if (form.value.id < 1) {
                resetForm()
            }
        }
        if (res.message) {
            iAlert(res)
        }
    })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            btnState.value = false
        })
}
provide('submitForm', submitForm)
</script>
