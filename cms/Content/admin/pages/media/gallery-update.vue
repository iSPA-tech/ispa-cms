<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <breadcrumbs
        :items="[
            {
                name: 'media-dashboard',
                title: $t('nav.a.media'),
                icon: 'multimedia',
            },
            {
                name: 'gallery-manage',
                title: $t('nav.a.galleries'),
                text: $t('nav.a.galleries'),
            },
            {
                text: $t('nav.b.update'),
                title: $t('nav.b.update'),
            },
        ]"
    >
        <i-button :to="{ name: 'gallery-manage' }" as="router-link" variant="default">
            <template #icon>
                <i-icon class="size-3.5" icon="arrow-left" />
            </template>
            <span>{{ $t('nav.b.back') }}</span>
        </i-button>
        <i-button
            :disabled="btnState"
            :loading="btnState"
            form="gallery-form"
            native-type="submit"
            variant="primary"
        >
            <template #icon>
                <i-icon class="size-3.5" icon="content-save" />
            </template>
            <span>{{ $t('nav.b.save') }}</span>
        </i-button>
    </breadcrumbs>

    <div class="space-y-4">
        <div v-if="rule('gallery.update')">
            <a-form
                id="gallery-form"
                ref="formRef"
                :model="form"
                :rules="formRule"
                class="cms-form"
                layout="vertical"
                @finish="submitForm"
                @finish-failed="finishFailed"
            >
                <i-box class="mt-4 mb-6">
                    <h2 class="font-medium text-lg text-slate-800">
                        {{ $t('text.tab.basic') }}
                    </h2>

                    <media-selection
                        v-model="form.images"
                        :limit="
                            getSettingValue(globalSettings, 'upload.max_image_count.gallery', 10)
                        "
                        folder="Post"
                    />

                    <div class="mt-3">
                        <slug-v3 v-model="form" :label="$t('nav.a.galleries')" module="post" />
                    </div>

                    <input-category v-model="form" :type="cateType" multiple />
                </i-box>

                <i-box class="mt-4">
                    <h2 class="font-medium text-lg text-slate-800">
                        {{ $t('text.attributes.title').replace(':name', $t('nav.a.galleries')) }}
                        <span class="text-red-500">*</span>
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4">
                        <select-input-language
                            v-model="form.language_id"
                            :label="$t('nav.a.languages')"
                            name="language_id"
                            required
                        />

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

                    <div v-show="basicState" class="grid grid-cols-1 md:grid-cols-2 gap-x-4">
                        <i-seo module="gallery"></i-seo>

                        <a-form-item :label="$t('validation.attributes.published_at')" name="published_at">
                            <a-date-picker
                                v-model:value="form.published_at"
                                :placeholder="$t('text.select')"
                                :show-time="true"
                            />
                        </a-form-item>
                    </div>
                    <a
                        class="text-cyan-600 text-sm"
                        role="button"
                        @click="basicState = !basicState"
                    >
                        <span v-if="!basicState">
                            {{ $t('text.product.more') }}
                            <i-icon class="size-4" icon="chevron-down" />
                        </span>
                        <span v-else>
                            {{ $t('web.a.collapse') }}
                            <i-icon class="size-4" icon="chevron-up" />
                        </span>
                    </a>
                </i-box>

                <i-box class="mt-4 mb-6">
                    <h2 class="font-medium text-lg text-slate-800">
                        {{ $t('text.gallery.content') }}
                        <span class="text-red-500">*</span>
                    </h2>
                    <p class="text-sm text-gray-400">
                        {{ $t('text.gallery.content-info') }}
                    </p>

                    <div class="pt-4">
                        <a-form-item :label="$t('validation.attributes.description')" name="description">
                            <a-textarea
                                v-model:value="form.description"
                                :maxlength="1000"
                                :placeholder="$t('validation.attributes.description')"
                                :rows="5"
                                show-count
                            />
                        </a-form-item>
                        <i-contents
                            v-model="form.content"
                            :plugins="['text', 'image', 'video']"
                            :target-id="form.id"
                            category="content"
                            no-label
                            target="Gallery"
                        />
                    </div>
                </i-box>

                <i-box class="mt-4 mb-6">
                    <h2 class="font-medium text-lg text-slate-800">
                        {{ $t('text.config.title').replace(':name', $t('nav.a.galleries')) }}
                    </h2>
                    <p class="text-sm text-gray-400">
                        {{ $t('text.config.desc').replace(':name', $t('nav.a.galleries')) }}
                    </p>

                    <div class="pt-4">
                        <i-form-config v-model="form.config" type="gallery"></i-form-config>
                    </div>
                </i-box>
            </a-form>
        </div>
        <no-permission-error v-else></no-permission-error>
    </div>
</template>

<script setup>
import { defineComponent, ref, onMounted, inject } from 'vue'
import { finishFailed, getSettingValue, rule } from '@/mixins'
import { useEnumerates } from '@/hooks'
import { getGallery } from '@/libs'
import { useRoute } from 'vue-router'
import { useAppRuntime } from '@/runtime'

defineComponent({
    name: 'GalleryUpdate',
})

const { globalSettings } = useAppRuntime()
const basicState = ref(false)
const route = useRoute()
const { data: status } = useEnumerates()
const submitForm = inject('submitForm')
const form = inject('form')
const btnState = inject('btnState')
const setForm = inject('setForm')
const formRule = inject('formRule')

const cateType = 2

onMounted(() => {
    getGallery(route.params.id, true).then((res) => {
        setForm(res.data)
    })
})
</script>
