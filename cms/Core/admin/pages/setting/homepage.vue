<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form :model="form" layout="horizontal" @finish="onFinish">
        <div
            class="mb-6 border border-rose-500/20 p-4 rounded-sm bg-rose-50 text-rose-900 shadow-xs"
        >
            {{ $t('mgs.advanced_setting') }}
        </div>

        <a-form-item :label="$t('text.s.homepage_display')" name="homepage">
            <a-radio-group
                class=""
                v-model:value="form.homepage"
                :options="[
                    {
                        label: $t('text.s.homepage_default'),
                        value: 'default',
                    },
                    {
                        label: $t('text.s.homepage_latest_blog'),
                        value: 'latest-post',
                    },
                    {
                        label: $t('text.s.homepage_latest_product'),
                        value: 'latest-product',
                    },
                    {
                        label: $t('text.s.homepage_static'),
                        value: 'static-page',
                    },
                ]"
            />
        </a-form-item>
        <a-form-item name="static_id" :label="$t('text.s.select_static_page')">
            <select-input-post
                v-model="form.static_id"
                :disabled="form.homepage !== 'static-page'"
                type="page"
            />
        </a-form-item>
        <div class="flex justify-end items-center">
            <a-button
                :disabled="btnState"
                :loading="btnState"
                class="btn-danger"
                html-type="submit"
            >
                <i-icon icon="content-save" class="size-4" />
                {{ $t('nav.b.save') }}
            </a-button>
        </div>
    </a-form>
</template>

<script setup>
import { defineComponent, h, onMounted, ref } from 'vue'
import { getHomepageSetting, updateHomepageSetting } from '@/libs'
import { iAlert } from '@/mixins'

defineComponent({
    name: 'SettingHomepage',
})

const form = ref({
    homepage: 'default',
    static_id: null,
})
const btnState = ref(false)

const onFinish = async (value = {}) => {
    if (btnState.value) {
        return
    }

    btnState.value = true
    await updateHomepageSetting(value)
        .then((res) => {
            if (res.message) {
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

const getSetting = async () => {
    await getHomepageSetting()
        .then((res) => {
            if (res?.data) {
                form.value = {
                    ...form.value,
                    homepage: res.data.homepage,
                    static_id: parseInt(res.data.static_id),
                }
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}

onMounted(() => {
    getSetting()
})
</script>
