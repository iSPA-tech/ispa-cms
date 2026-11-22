<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <breadcrumbs v-if="breadcrumbItems" :items="breadcrumbItems" />

    <router-view v-if="isOwnLayout"></router-view>
    <div v-else class="px-4 sm:px-6 lg:px-8 py-4 mb-12 pt-6">
        <router-view></router-view>
    </div>
</template>

<script setup>
import { computed, defineComponent, inject, provide, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { iAlert, objectAssignOnlyByKeyHasValue } from '@/mixins'
import ajax from '@/ajax'

defineComponent({
    name: 'Language',
})

const { t } = useI18n()

const route = useRoute()
const router = useRouter()
const languageRefetch = inject('languageRefetch', () => {})

// Leaf label per sub page that still uses this wrapper's breadcrumb.
// language-manage / language-create / language-update render their own breadcrumbs.
const leafTextKeys = {
    'province-manage': 'nav.a.provinces',
    'province-update': 'nav.b.update',
    'language-translate': 'nav.a.translates',
    'language-translate-web': 'nav.a.trans-web',
    'language-translate-app': 'nav.a.trans-app',
    'language-translate-admin': 'nav.a.trans-adm',
    'language-translate-email': 'nav.a.trans-email',
}
const ownBreadcrumbRoutes = ['language-manage', 'language-create', 'language-update']
// Pages that render their own breadcrumb also own their layout, so no extra wrapper padding.
const isOwnLayout = computed(() => ownBreadcrumbRoutes.includes(route.name))
const breadcrumbItems = computed(() => {
    if (ownBreadcrumbRoutes.includes(route.name)) {
        return null
    }
    const leafKey = leafTextKeys[route.name]
    if (!leafKey) {
        return [
            { icon: 'translate-variant', title: t('nav.a.languages'), text: t('nav.a.languages') },
        ]
    }
    return [
        { name: 'language-manage', icon: 'translate-variant', title: t('nav.a.languages') },
        { text: t(leafKey), title: t(leafKey) },
    ]
})

/** Form config */
const btnState = ref(false)
provide('btnState', btnState)
const formDefault = () => {
    return {
        id: 0,
        name: null,
        path: null,
        currency: null,
        path_alias: null,
        code: null,
        flag: null,
        time_zone: null,
        status: 'published',
        is_locked: false,
        default_web: false,
        default_adm: false,
        default_app: false,
        for_app: false,
        for_web: false,
        for_adm: false,
    }
}
provide('formDefault', formDefault)
const form = ref(formDefault())
provide('form', form)
const formRef = ref(null)
provide('formRef', formRef)
/** Reset the shared form to its defaults (and clear validation state when mounted). */
const resetForm = () => {
    form.value = formDefault()
    formRef.value?.clearValidate?.()
}
provide('resetForm', resetForm)
const setForm = (val) => {
    resetForm()
    const next = Object.assign(formDefault(), val)
    // Languages store bare status keys; accept the prefixed enum form too.
    if (typeof next.status === 'string') {
        next.status = next.status.replace(/^status_/, '')
    }
    form.value = next
}
provide('setForm', setForm)
const submitForm = (value = {}) => {
    if (btnState.value) {
        return
    }
    const data = objectAssignOnlyByKeyHasValue(form.value, value)
    btnState.value = true
    //  Todo: change it to function call in libs
    const isCreate = !(data.id > 0)
    const call = isCreate ? ajax.post(`/language`, data) : ajax.put(`/language/${data.id}`, data)
    return call
        .then((res) => {
            btnState.value = false
            if (res?.message) {
                iAlert(res.message)
            }
            if (res?.status === 'error') {
                return
            }
            languageRefetch()
            if (isCreate) {
                resetForm()
                router.push({ name: 'language-manage' })
            }
        })
        .catch((err) => {
            btnState.value = false
            iAlert(err, true)
        })
}
provide('submitForm', submitForm)
</script>
