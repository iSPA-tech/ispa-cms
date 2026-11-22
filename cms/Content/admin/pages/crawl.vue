<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<script setup>
import { defineComponent, h, ref } from 'vue'
import ajax from '@/ajax'
import IContentsView from '@/components/i-contents-view.vue'

defineComponent({
    name: 'PostCrawl',
})

const form = ref({
    url: '',
    type: 'chatGPT',
    translate: false,
    content: [],
    title: '',
    description: '',
    thumbnail: '',
    original: {
        thumbnail: '',
        title: '',
        description: '',
        contents: [],
    },
    translated: {
        thumbnail: '',
        title: '',
        description: '',
        contents: [],
    },
})

const stateLoading = ref(0)
const saveLoading = ref(false)
// Save appears only where a module or project turns crawled articles into posts (the crawl answer says so).
const canSave = ref(false)
const formRef = ref()
const resetForm = () => {
    formRef.value.resetFields()
    stateLoading.value = 0
}

const onFinishTranslate = (values) => {
    if (stateLoading.value > 0 && stateLoading.value < 3) {
        return
    }

    stateLoading.value = 1
    // Call API to get data
    ajax.post('/post/crawler', values)
        .then((res) => {
            if (res.data.status === 'success') {
                const { can_save: saveAvailable, ...crawled } = res.data.data
                canSave.value = saveAvailable === true
                const newOriginal = {
                    ...form.value.original,
                    ...crawled,
                }
                //  init
                newOriginal.contents.map((item) => {
                    if (item.type === 'video') {
                        item.options = { desktopHeight: 450, mobileHeight: 300 }
                    }
                    if (item.type === 'image') {
                        item.options = {
                            caption: null,
                            alt: null,
                            title: null,
                            description: null,
                        }
                    }

                    return item
                })

                form.value.thumbnail = newOriginal.thumbnail
                form.value.original = newOriginal

                if (values.translate) {
                    stateLoading.value = 2
                    // Call API to translate
                    ajax.post('post/crawler/translate', {
                        ...values,
                        original: newOriginal,
                        type: form.value.type,
                    })
                        .then((res) => {
                            if (res.data.status === 'success') {
                                form.value.translated = res.data.data
                                form.value.title = res.data.data.title
                                form.value.description = res.data.data.description
                                form.value.content = res.data.data.contents

                                stateLoading.value = 4
                            }
                        })
                        .catch((err) => {
                            console.error(err)
                            stateLoading.value = 0
                        })
                } else {
                    form.value.title = newOriginal.title
                    form.value.description = newOriginal.description
                    form.value.content = newOriginal.contents
                    form.value.translated = {
                        thumbnail: newOriginal.thumbnail,
                        title: newOriginal.title,
                        description: newOriginal.description,
                        contents: newOriginal.contents,
                    }

                    stateLoading.value = 3
                }
            }
        })
        .catch((err) => {
            console.error(err)
            stateLoading.value = 0
        })
}

const translateTypes = [
    { label: 'ChatGPT', value: 'chatGPT' },
    { label: 'Google Translate', value: 'google' },
]

const onSaveArticle = (values) => {
    // Enter in a field submits the form even when Save is hidden
    if (saveLoading.value || !canSave.value) {
        return
    }

    const newValue = {
        ...values,
        ...form.value,
    }
    saveLoading.value = true

    ajax.post('post/crawler/save', newValue)
        .then((res) => {
            if (res.data.status === 'success') {
                stateLoading.value = 0
                form.value = {
                    type: 'chatGPT',
                    url: '',
                    translate: false,
                    content: [],
                    title: '',
                    description: '',
                    thumbnail: '',
                    original: {
                        thumbnail: '',
                        title: '',
                        description: '',
                        contents: [],
                    },
                    translated: {
                        thumbnail: '',
                        title: '',
                        description: '',
                        contents: [],
                    },
                }
            }
        })
        .catch((err) => {
            console.error(err)
        })
        .finally(() => {
            saveLoading.value = false
        })
}
</script>

<template>
    <div>
        <breadcrumbs
            :items="[
                {
                    name: 'post-dashboard',
                    icon: 'text-box-edit-outline',
                    title: $t('nav.a.posts'),
                },
                {
                    text: $t('nav.a.post-crawl'),
                    title: $t('nav.a.post-crawl'),
                },
            ]"
        />

        <a-form ref="formRef" :model="form" layout="vertical" @finish="onFinishTranslate">
            <div class="max-w-lg mx-auto">
                <a-form-item name="url" required>
                    <a-input
                        v-model:value="form.url"
                        :placeholder="$t('text.crawl.url_placeholder')"
                        type="url"
                    />
                </a-form-item>
                <a-form-item :label="$t('text.crawl.tool_label')" name="type" required>
                    <a-select v-model:value="form.type" :options="translateTypes" />
                </a-form-item>
                <a-form-item name="translate">
                    <div class="flex items-center">
                        <a-checkbox
                            v-model:checked="form.translate"
                            class="flex-1"
                            name="translate"
                        >
                            {{ $t('text.crawl.translate_vi') }}
                        </a-checkbox>
                        <a-button :disabled="stateLoading < 3" class="mr-2" @click="resetForm">
                            {{ $t('nav.b.clear') }}
                        </a-button>
                        <a-button
                            :disabled="!form.url || stateLoading > 0"
                            :loading="stateLoading > 0 && stateLoading < 3"
                            html-type="submit"
                        >
                            {{
                                stateLoading === 1
                                    ? $t('text.crawl.state_fetching')
                                    : stateLoading === 2
                                      ? $t('text.crawl.state_translating')
                                      : stateLoading === 3
                                        ? $t('text.crawl.state_fetched')
                                        : stateLoading === 4
                                          ? $t('text.crawl.state_translated')
                                          : $t('text.crawl.fetch')
                            }}
                        </a-button>
                    </div>
                </a-form-item>
            </div>
        </a-form>

        <a-form v-if="stateLoading >= 3" :model="form" layout="vertical" @finish="onSaveArticle">
            <div class="mb-4">
                <label for="">{{ $t('text.image.default') }}</label>
                <img :src="form.thumbnail" alt="" class="max-w-[150px] not-prose" />
                <a :href="form.thumbnail" class="text-xs m-0" target="_blank">
                    {{ form.thumbnail }}
                </a>
            </div>

            <a-form-item :label="$t('validation.attributes.title')" name="title">
                <a-input v-model:value="form.title" />
                <p class="bg-gray-50 p-2 text-xs m-0">{{ form.original.title }}</p>
            </a-form-item>
            <a-form-item :label="$t('validation.attributes.description')" name="description">
                <a-textarea
                    v-model:value="form.description"
                    :default-value="form.translated.description"
                />
                <p class="bg-gray-50 p-2 text-xs m-0">{{ form.original.description }}</p>
            </a-form-item>
            <div class="prose max-w-none!">
                <IContentsView
                    v-model:value="form.content"
                    :original="form.original"
                    :translated="form.translated"
                    @change="(value) => (form.content = value)"
                />
            </div>
            <div v-if="canSave" class="flex items-center border-t pt-3 mt-3">
                <a-button
                    :disabled="saveLoading"
                    :loading="saveLoading"
                    class="ml-auto btn-danger"
                    html-type="submit"
                >
                    <i-icon icon="content-save" class="size-4" />
                    {{ $t('nav.b.save') }}
                </a-button>
            </div>
        </a-form>
    </div>
</template>
