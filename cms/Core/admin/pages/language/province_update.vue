<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form ref="formRef" :model="form" layout="vertical">
        <div v-if="rule('province.update')" class="px-2 md:px-1 py-4 mb-12">
            <div class="flex items-center space-x-2">
                <router-link
                    :title="$t('nav.b.cancel')"
                    :to="{ name: 'province-manage' }"
                    class="ml-auto"
                >
                    <a-button>
                        <span><i-icon icon="close" /></span>
                        <span>{{ $t('nav.b.cancel') }}</span>
                    </a-button>
                </router-link>
                <a-button danger type="primary" @click="submitForm">
                    <span><i-icon icon="content-save" class="size-4" /></span>
                    <span>{{ $t('nav.b.save') }}</span>
                </a-button>
            </div>

            <a-tabs v-model:active-key="activeTab">
                <a-tab-pane key="basic">
                    <template #tab>
                        <div class="flex items-center space-x-0 md:space-x-2">
                            <span class="w-4 hidden md:block">
                                <i-icon icon="alert" class="size-4" />
                            </span>
                            <span>{{ $t('text.tab.basic') }}</span>
                        </div>
                    </template>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2 md:col-span-1">
                            <a-form-item v-if="rule('province.uid')" name="uid" :label="$t('text.language.uid_code')">
                                <a-input v-model:value="form.uid" :placeholder="$t('text.language.uid_code')" />
                            </a-form-item>
                            <a-form-item
                                name="name"
                                :label="`${$t('validation.attributes.name')} ${$t('nav.a.provinces')}`"
                            >
                                <a-input v-model:value="form.name" :placeholder="$t('validation.attributes.name')" />
                            </a-form-item>

                            <a-form-item :label="$t('validation.attributes.global_name')" name="global_name">
                                <a-input
                                    v-model:value="form.global_name"
                                    :placeholder="$t('validation.attributes.global_name')"
                                    @change="gSlug('global_name')"
                                />
                            </a-form-item>

                            <a-form-item :label="$t('validation.attributes.slug')" name="slug">
                                <a-input
                                    v-model:value="form.slug"
                                    :placeholder="$t('validation.attributes.slug')"
                                    @change="gSlug('slug')"
                                />
                            </a-form-item>
                            <droppable-map v-model="form"></droppable-map>

                            <a-form-item :label="$t('validation.attributes.longitude')" name="longitude">
                                <a-input
                                    v-model:value="form.longitude"
                                    :placeholder="$t('validation.attributes.longitude')"
                                />
                            </a-form-item>

                            <a-form-item :label="$t('validation.attributes.latitude')" name="latitude">
                                <a-input
                                    v-model:value="form.latitude"
                                    :placeholder="$t('validation.attributes.latitude')"
                                />
                            </a-form-item>
                            <a-form-item :label="$t('validation.attributes.status')" name="status">
                                <a-select
                                    v-model:value="form.status"
                                    :options="
                                        (status || [])
                                            .filter((s) => s.type === 'status')
                                            .map((s) => ({
                                                value: s.enum_key,
                                                label: `${s.name} (${s.enum_key})`,
                                            }))
                                    "
                                    :placeholder="$t('text.select')"
                                    class="w-full"
                                />
                            </a-form-item>
                        </div>
                        <div class="col-span-2 md:col-span-1">
                            <a-form-item :label="$t('validation.attributes.type')" name="type">
                                <a-select
                                    v-model:value="form.type"
                                    :options="
                                        typeOptions.map((s) => ({
                                            value: s.id,
                                            label: $t(s.text),
                                        }))
                                    "
                                    :placeholder="$t('validation.attributes.type')"
                                    class="w-full"
                                />
                            </a-form-item>

                            <select-input-province
                                v-if="form.type > 0"
                                :label="`${$t('validation.attributes.parent_id')} (${$t('nav.a.provinces')})`"
                                name="parent_id"
                            />
                        </div>
                    </div>
                </a-tab-pane>
                <a-tab-pane key="custom">
                    <template #tab>
                        <div class="flex items-center space-x-0 md:space-x-2">
                            <span class="w-4 hidden md:block"
                                ><i-icon icon="cog-sync" class="size-4"
                            /></span>
                            <span>{{ $t('text.tab.config') }}</span>
                        </div>
                    </template>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2 md:col-span-1">
                            <a-form-item
                                :label="$t('text.config.webDefault')"
                                :name="['config', 'webDefault']"
                            >
                                <a-switch
                                    v-model:checked="form.config.webDefault"
                                    :checked-value="true"
                                    :un-checked-value="false"
                                />
                            </a-form-item>

                            <a-form-item
                                :label="$t('text.config.webShow')"
                                :name="['config', 'webShow']"
                            >
                                <a-switch
                                    v-model:checked="form.config.webShow"
                                    :checked-value="true"
                                    :un-checked-value="false"
                                />
                            </a-form-item>

                            <a-form-item
                                v-if="form.type === 0"
                                :label="$t('text.config.flag')"
                                :name="['config', 'flag']"
                            >
                                <a-input
                                    v-model:value="form.config.flag"
                                    :placeholder="$t('text.config.flag')"
                                    type="url"
                                />
                            </a-form-item>

                            <a-form-item
                                :label="$t('text.config.zipCode')"
                                :name="['config', 'zipCode']"
                            >
                                <a-input
                                    v-model="form.config.zipCode"
                                    :placeholder="$t('text.config.zipCode')"
                                />
                            </a-form-item>

                            <a-form-item :label="$t('text.config.url')">
                                <a-input
                                    v-model="form.config.url"
                                    :placeholder="$t('text.config.url')"
                                    type="url"
                                />
                            </a-form-item>

                            <a-form-item :label="$t('text.config.administrative')">
                                <a-input
                                    v-model="form.config.administrative"
                                    :placeholder="$t('text.config.administrative')"
                                />
                            </a-form-item>

                            <a-form-item :label="$t('text.config.timeZone')">
                                <a-input
                                    v-model="form.config.timeZone"
                                    :placeholder="$t('text.config.timeZone')"
                                />
                            </a-form-item>

                            <a-form-item :label="$t('text.config.phoneCode')">
                                <a-input
                                    v-model="form.config.phoneCode"
                                    :placeholder="$t('text.config.phoneCode')"
                                />
                            </a-form-item>

                            <a-form-item :label="$t('text.config.areaCode')">
                                <a-input
                                    v-model="form.config.areaCode"
                                    :placeholder="$t('text.config.areaCode')"
                                />
                            </a-form-item>
                        </div>
                    </div>
                </a-tab-pane>

                <a-tab-pane :tab="$t('text.tab.content')" key="content">
                    <i-contents
                        v-model="form.content"
                        :plugins="['text', 'image', 'video']"
                        :target="'Province'"
                        :target-id="form.id"
                        category="content"
                        no-label
                        required
                    />
                </a-tab-pane>
            </a-tabs>
        </div>
        <no-permission-error v-else></no-permission-error>
    </a-form>
</template>
<script setup>
import { defineComponent, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { iAlert, rule } from '@/mixins'
import ajax from '@/ajax'
import { useEnumerates } from '@/hooks'
import { getProvince } from '@/libs'

defineComponent({
    name: 'ProvinceUpdate',
})

const form = ref({
    config: {
        webDefault: false,
        webShow: false,
        flag: '',
        zipCode: '',
        url: '',
        administrative: '',
        timeZone: '',
        phoneCode: '',
        areaName: '',
    },
})
const { data: status } = useEnumerates()
const getProvinceEdit = async () => {
    await getProvince(route.params.id, true)
        .then((res) => {
            if (res.status === 'success') {
                form.value = res.data
                form.value.config = Object.assign(form.value.config, res.data.config)
                form.value.content = res.data.content
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}

const route = useRoute()
const activeTab = ref('basic')
const typeOptions = ref([
    { id: 0, text: 'text.province-nation' },
    { id: 1, text: 'text.province-country' },
    { id: 2, text: 'text.province-city' },
    { id: 3, text: 'text.province-district' },
    { id: 4, text: 'text.province-ward' },
])

onMounted(async () => {
    await getProvinceEdit()
})

const submitForm = () => {
    // formRef.value.validate(valid => {
    //   if (valid) {

    const call =
        form.value.id > 0
            ? ajax.put(`province/${form.value.id}`, form.value)
            : ajax.post(`province`, form.value)
    call.then((res) => {
        if (res.data.message) {
            iAlert(res.data)
        }
        // btnState.value = false
    }).catch((err) => {
        iAlert(err, true)
        //btnState.value = false
    })
    //}
    // })
}
const gSlug = (col = 'engName') => {
    const data = {
        table: 'Province',
        slug: form.value[col],
        id: form.value.id,
    }
    if (data.slug && data.slug.length > 0) {
        ajax.post(`slug/check`, data)
            .then((res) => {
                if (res.data.status === 'success') {
                    form.value.slug = res.data.data
                }
            })
            .catch((err) => {})
    }
}
</script>
