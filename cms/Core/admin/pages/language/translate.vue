<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="w-full">
        <div class="shrink-0 font-bold text-gray-700 flex items-center pb-4 gap-x-2">
            <div class="flex-1"></div>
            <a-button :icon="h(SyncOutlined)" :loading="btnState" @click="syncFile">
                {{ $t('nav.b.sync') }}
            </a-button>
            <a-button
                :icon="h(PlusOutlined)"
                :loading="btnState"
                class="btn-success"
                @click="openForm"
            >
                {{ $t('nav.b.add') }}
            </a-button>
        </div>

        <router-view />
    </div>

    <a-modal
        v-model:open="modalState"
        :title="`${form.id > 0 ? $t('nav.b.update') : $t('nav.b.create')} ${$t('nav.a.translates')}`"
    >
        <a-form
            id="translateForm"
            :model="form"
            :rules="formRule"
            layout="vertical"
            @finish="submit"
            @finish-failed="finishFailed"
        >
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-0 md:gap-x-4">
                <a-form-item :label="$t('validation.attributes.group_name')" name="group_name">
                    <a-select
                        v-model:value="form.group_name"
                        :options="
                            groupTrans.map((opt) => {
                                return {
                                    label: t('text.translate.group.option_label', {
                                        text: opt.text,
                                        value: opt.value,
                                        admin: opt.value[0],
                                    }),
                                    value: opt.value,
                                }
                            })
                        "
                        :placeholder="$t('validation.attributes.group_name')"
                        class="w-full"
                    />
                </a-form-item>
                <a-form-item :label="$t('validation.attributes.trans_name')" name="name">
                    <a-input v-model:value="form.name" :placeholder="$t('validation.attributes.trans_name')" />
                </a-form-item>
            </div>
            <div class="grid grid-cols-1 gap-3 mb-4">
                <a-form-item
                    v-for="(l, ind) in langList"
                    :key="ind"
                    :label="l.name"
                    :name="['text', l.path]"
                    class="mb-0"
                >
                    <a-textarea
                        v-model:value="form.text[l.path]"
                        :placeholder="t('text.translate.form.text_description', { name: l.name })"
                        :rows="3"
                    >
                        <template #prefix>
                            <img
                                :src="`${apiUrl}images/flags/4x3/${l.flag}`"
                                alt=""
                                class="w-auto h-4 -ml-1.5 max-w-none"
                            />
                        </template>
                    </a-textarea>
                </a-form-item>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-x-0 md:gap-x-4">
                <a-form-item :label="$t('validation.attributes.for_web')" name="for_web">
                    <a-switch v-model:checked="form.for_web" />
                </a-form-item>
                <a-form-item :label="$t('validation.attributes.for_adm')" name="for_adm">
                    <a-switch v-model:checked="form.for_adm" />
                </a-form-item>
                <a-form-item :label="$t('validation.attributes.for_app')" name="for_app">
                    <a-switch v-model:checked="form.for_app" />
                </a-form-item>
            </div>
            <div class="pb-6 flex flex-col space-y-2">
                <label class="text-gray-700">
                    {{ $t('validation.attributes.modules') }}
                </label>
                <a-checkbox-group v-model:value="form.modules">
                    <a-checkbox v-for="(mod, index) in moduleList" :key="index" :value="mod.name">
                        {{ mod.name }}
                    </a-checkbox>
                </a-checkbox-group>
            </div>
        </a-form>
        <template #footer>
            <div class="flex flex-nowrap">
                <div class="flex-1"></div>
                <a-button :icon="h(CloseCircleOutlined)" @click="modalState = false">
                    {{ $t('nav.b.cancel') }}
                </a-button>
                <a-button
                    :icon="h(SaveOutlined)"
                    :loading="btnState"
                    :disabled="btnState"
                    danger
                    form="translateForm"
                    html-type="submit"
                    type="primary"
                >
                    {{ $t('nav.b.submit') }}
                </a-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { computed, defineComponent, ref, watch, provide, h, reactive, inject, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { finishFailed, iAlert, initFilter, objectAssignOnlyByKeyHasValue } from '@/mixins'
import {
    CloseCircleOutlined,
    PlusOutlined,
    SaveOutlined,
    SyncOutlined,
} from '@ant-design/icons-vue'
import ajax from '@/ajax'
import { useI18n } from 'vue-i18n'
import { CACHE_KEY_MESSAGES_LOCALE, translateFilter, apiUrl } from '@/config'
import { useTranslates } from '@/hooks'
import { getTranslate, storeTranslate, updateTranslate } from '@/libs'
import { Form } from 'ant-design-vue'
import { getModuleList } from '@/libs/utils'
import { useAppRuntime } from '@/runtime'

const useForm = Form.useForm

defineComponent({
    name: 'TranslateManage',
})

const { languages } = useAppRuntime()

/** Normalize languages to always be an array regardless of data shape */
const langList = computed(() => {
    const val = languages.value
    if (Array.isArray(val)) return val
    if (val?.data && Array.isArray(val.data)) return val.data
    return []
})

const route = useRoute()
const router = useRouter()
const modalState = ref(false)

const queryData = reactive(initFilter(translateFilter, route.query))
const { data: dataRef, refetch } = useTranslates(queryData, {})
provide('dataRef', dataRef)

const tableChange = (pagination) => {
    const newFilterParams = {
        ...queryData,
        page: pagination.current,
        pageSize: pagination.pageSize,
    }

    router.push({
        name: 'language-translate-admin',
        query: newFilterParams,
    })
}
provide('tableChange', tableChange)

const btnState = ref(false)
const { t } = useI18n()

const formDefault = () => {
    return {
        id: 0,
        modules: ['Core'],
        group_name: 'text',
        name: null,
        text: {},
        for_web: false,
        for_adm: true,
        for_app: false,
        status: 'status_published',
    }
}
const form = ref({
    id: 0,
    group_name: 'text',
    name: null,
    text: {},
    for_web: false,
    for_adm: true,
    for_app: false,
    status: 'status_published',
})
const formRule = reactive({
    group_name: [
        {
            required: true,
            trigger: 'blur',
            message: t('validation.accepted', { a: t('validation.attributes.group_name') }),
        },
    ],
    name: [
        {
            required: true,
            trigger: 'blur',
            message: t('validation.required', { a: t('validation.attributes.trans_name') }),
        },
    ],
})
const { resetFields } = useForm(form, formRule)

const groupTrans = computed(() => [
    { value: 'action', text: t('text.translate.group.action') },
    { value: 'cart', text: t('text.translate.group.cart') },
    { value: 'enum', text: t('text.translate.group.enum') },
    { value: 'mgs', text: t('text.translate.group.message') },
    { value: 'nav', text: t('text.translate.group.navigation') },
    { value: 'pagination', text: t('text.translate.group.pagination') },
    { value: 'setting', text: t('text.translate.group.setting') },
    { value: 'text', text: t('text.translate.group.text') },
    { value: 'validation', text: t('text.translate.group.validation') },
    { value: 'web', text: t('text.translate.group.web') },
])

/** Reset form */
const resetForm = () => {
    form.value = formDefault()
    resetFields()

    langList.value.forEach((e) => {
        form.value.text[e.path] = ''
    })
}

/** Open form to edit / create */
const openForm = async (id = 0) => {
    if (id > 0) {
        await getTranslate(id, true)
            .then((res) => {
                if (res?.status === 'success') {
                    form.value = res.data
                    modalState.value = true
                }
                if (res?.message) {
                    iAlert(res)
                }
            })
            .catch((err) => {
                iAlert({ ...err, title: t('text.title.error') }, true)
            })
    } else {
        resetForm()
    }
    modalState.value = true
}
provide('openForm', openForm)

/** Submit form */
const submit = (data = {}) => {
    const value = objectAssignOnlyByKeyHasValue(form.value, data)

    const currentGroup = form.value.group_name
    if (btnState.value) {
        return
    }
    btnState.value = true
    const call = form.value.id > 0 ? updateTranslate(value.id, value) : storeTranslate(value)
    call.then((res) => {
        if (res?.status === 'success') {
            //  Reset form
            if (value.id === 0) {
                form.value = formDefault()

                form.value.group_name = currentGroup

                langList.value.forEach((e) => {
                    form.value.text[e.path] = null
                })
            }

            refetch()
        }
        if (res?.message) {
            iAlert({ ...res, title: t('text.title.success') })
        }
    })
        .catch((err) => {
            iAlert({ ...err, title: t('text.title.error') }, true)
        })
        .finally(() => {
            btnState.value = false
        })
}

/** Call to sync/generate language file */
const syncFile = async () => {
    if (btnState.value) {
        return
    }
    btnState.value = true

    ajax.put('translate/generate')
        .then((res) => {
            //    Remove current localStorage data
            localStorage.removeItem(CACHE_KEY_MESSAGES_LOCALE)
            if (res.data.message) {
                iAlert({
                    ...res.data,
                    title: t(res.data.status === 'success' ? 'text.title.success' : 'text.title.error'),
                })
            }
        })
        .catch((err) => {
            iAlert({ ...err, title: t('text.title.error') }, true)
        })
        .finally(() => {
            btnState.value = false
        })
}

const moduleList = ref([])
provide('moduleList', moduleList)
const getModules = async () => {
    try {
        if (btnState.value) {
            return
        }
        btnState.value = true

        const data = await getModuleList()

        moduleList.value = data
    } finally {
        btnState.value = false
    }
}

watch(
    langList,
    (val) => {
        if (val && val.length > 0) {
            form.value.text = {}
            val.forEach((e) => {
                form.value.text[e.path] = ''
            })
        }
    }
)

watch(
    () => route.query,
    (val) => {
        const next =
            Object.keys(val).length < 1
                ? { ...translateFilter }
                : initFilter(translateFilter, val)
        // Replace queryData entirely so cleared keys (e.g. keywords, is_used) are removed.
        // Object.assign merges and never removes; stale keys would still be sent to the API.
        Object.keys(queryData).forEach((k) => delete queryData[k])
        Object.assign(queryData, next)
        refetch()
    }
)

onMounted(() => {
    getModules()
})
</script>
