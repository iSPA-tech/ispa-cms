<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="mx-auto max-w-7xl">
        <breadcrumbs
            :items="[
                {
                    name: 'post-dashboard',
                    icon: 'text-box-edit-outline',
                    title: $t('nav.a.posts'),
                },
                {
                    text: $t('nav.b.create'),
                    title: $t('nav.b.create'),
                },
            ]"
        >
            <i-button v-if="can('post', 'create')" variant="info" @click="previewOpen = true">
                <template #icon>
                    <i-icon class="size-3.5" icon="eye" />
                </template>
                <span>{{ $t('nav.b.preview') }}</span>
            </i-button>
            <i-button class="text-xs" @click="router.push({ name: 'post-dashboard' })">
                <template #icon>
                    <i-icon icon="arrow-left" />
                </template>
                {{ $t('nav.b.back') }}
            </i-button>
        </breadcrumbs>

        <a-form
            v-if="can('post', 'create')"
            ref="formRef"
            :model="form"
            class="cms-form"
            layout="vertical"
            @finish="submitForm"
            @finish-failed="finishFailed"
        >
            <div
                :class="
                    clsx(
                        'top-1 left-1/2 -translate-x-1/2 z-20 w-full max-w-xl bg-blk-50 border-blk-200',
                        'shadow-md rounded-full border px-2 py-1',
                        isFormChanged ? 'fixed' : 'hidden'
                    )
                "
            >
                <div :class="clsx('flex items-center space-x-2')">
                    <div class="flex-1 text-xs text-slate-600">
                        {{ t('text.form.un-save').replace(':name', t('nav.a.posts')) }}
                    </div>

                    <i-button variant="default" @click="discardChange">
                        <template #icon>
                            <i-icon class="size-3.5" icon="close" />
                        </template>
                        <span>{{ $t('nav.b.discard') }}</span>
                    </i-button>
                    <i-button
                        :disabled="btnState"
                        :loading="btnState"
                        native-type="submit"
                        variant="primary"
                    >
                        <template #icon>
                            <i-icon class="size-3.5" icon="content-save" />
                        </template>
                        <span>{{ $t('nav.b.save') }} {{ $t('text.type.' + form.type) }}</span>
                    </i-button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-5 lg:grid-cols-4 gap-3">
                <div class="col-span-1 md:col-span-3 lg:col-span-3 space-y-3">
                    <div class="i-box">
                        <slug-v3 v-model="form" :label="$t('nav.a.posts')" module="post" />

                        <div class="space-y-2 flex flex-col">
                            <span class="text-blk-950 font-medium">
                                {{ $t('validation.attributes.content') }}
                            </span>

                            <i-contents
                                v-model="form.content"
                                :plugins="['text', 'image', 'video', 'widget']"
                                :target-id="form.id"
                                category="content"
                                no-label
                                target="Post"
                            />
                        </div>
                    </div>

                    <component
                        v-for="extension in postEditorExtensions"
                        :is="extension.component"
                        :key="extension.key"
                        v-model="form"
                    />

                    <input-excerpt v-model="form.description" />

                    <seo-box v-model="form" />

                    <div class="i-box">
                        <h3 class="font-medium text-blk-950">
                            {{ $t('text.config.title').replace(':name', $t('nav.a.posts')) }}
                        </h3>
                        <p class="text-slate-500 text-sm">
                            {{ $t('text.config.desc').replace(':name', $t('nav.a.posts')) }}
                        </p>

                        <div class="pt-3">
                            <i-form-config v-model="form.config" :type="form.type" />
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2 lg:col-span-1 space-y-3">
                    <div class="i-box">
                        <h3 class="font-medium text-slate-800 mb-2">
                            {{ $t('validation.attributes.enumerate_id') }}
                        </h3>

                        <a-form-item v-if="postMedia().length > 1" name="type" no-style>
                            <a-select v-model:value="form.type" :options="postMedia()" />
                        </a-form-item>
                    </div>
                    <div class="i-box">
                        <div class="flex items-center space-x-2">
                            <div class="flex-1">
                                <span class="text-blk-950 font-medium">
                                    {{ $t('validation.attributes.status') }}
                                </span>
                            </div>
                            <div>
                                <input-published-date
                                    v-if="!form.published_at"
                                    v-model="form.published_at"
                                    :type="$t('nav.a.posts')"
                                />
                            </div>
                        </div>
                        <a-form-item name="status" no-style>
                            <a-radio-group
                                v-model:value="form.status"
                                class="flex flex-wrap gap-2"
                                :options="
                                    (status || [])
                                        .filter((s) => s.type === 'status')
                                        .map((s) => ({
                                            label: s.name,
                                            value: s.enum_key,
                                        }))
                                "
                            />
                        </a-form-item>

                        <div
                            v-if="form.published_at"
                            class="text-slate-500 italic text-xs flex space-x-3"
                        >
                            <div class="flex-1">
                                {{ $t('text.post.published_at_future') }}:
                                {{ formatLocalizedDateTime(form.published_at) }}
                            </div>
                            <div class="inline-flex space-x-1">
                                <input-published-date
                                    v-model="form.published_at"
                                    is-edit
                                    :type="$t('nav.a.posts')"
                                />

                                <a-tooltip
                                    :title="`${$t('nav.b.clear')}: ${$t('validation.attributes.published_at')}`"
                                >
                                    <span
                                        @click="form.published_at = null"
                                        role="button"
                                        class="rounded-sm bg-white hover:bg-blk-100 transition-all size-6 p-1"
                                    >
                                        <i-icon
                                            icon="delete"
                                            class="size-4 text-slate-600 hover:text-slate-800"
                                        />
                                    </span>
                                </a-tooltip>
                            </div>
                        </div>
                    </div>
                    <div class="i-box">
                        <media-selection
                            v-model="form.images"
                            :limit="getLimitImageByPostType(form.type)"
                            folder="Post"
                        />

                        <div
                            v-if="
                                can(legacy('post', 'attachments')) &&
                                !['page', 'video'].includes(form.type)
                            "
                        >
                            <div class="pt-3">
                                <media-selection
                                    v-model="form.attachments"
                                    :limit="1"
                                    :type="form.type === 'post' ? 'document' : form.type"
                                    folder="Post"
                                    :required="['audio', 'document'].includes(form.type)"
                                />
                            </div>

                            <div
                                v-if="form.type === 'document'"
                                class="pt-3 grid grid-cols-1 md:grid-cols-2 gap-x-3"
                            >
                                <a-form-item
                                    :label="$t('text.author')"
                                    :name="['config', 'bookAuthor']"
                                >
                                    <a-input
                                        v-model:value="form.config.bookAuthor"
                                        :placeholder="$t('text.author')"
                                    />
                                </a-form-item>
                                <a-form-item
                                    :label="$t('text.releaseDate')"
                                    :name="['config', 'bookReleaseDate']"
                                >
                                    <a-date-picker
                                        v-model:value="form.published_at"
                                        :placeholder="$t('text.releaseDate')"
                                        :show-time="false"
                                        class="w-full"
                                    />
                                </a-form-item>
                            </div>
                        </div>

                        <a-form-item
                            :label="$t('validation.attributes.video_url')"
                            :rules="[
                                {
                                    required: form.type === 'video',
                                    trigger: 'blur',
                                    message: $t('validation.required', {
                                        a: $t('validation.attributes.video_url'),
                                    }),
                                },
                            ]"
                            name="video_url"
                        >
                            <a-input
                                v-model:value="form.video_url"
                                :placeholder="$t('validation.attributes.video_url')"
                            />
                        </a-form-item>
                    </div>
                    <div class="i-box">
                        <h3 class="font-medium text-slate-800 mb-2">
                            {{ $t('text.form.organization') }}
                        </h3>
                        <div v-if="!['page', ''].includes(form.type)">
                            <input-category v-model="form" :type="cateType" multiple />
                        </div>
                        <div v-else>
                            <select-input-post
                                v-model="form.parent_id"
                                :label="$t('text.post.select-parent')"
                                name="parent_id"
                                type="page"
                            />
                        </div>

                        <select-input-language
                            v-model="form.language_id"
                            :label="$t('nav.a.languages')"
                            name="language_id"
                        />

                        <input-tag v-if="!['notification'].includes(form.type)" v-model="form" />
                    </div>
                </div>
            </div>
        </a-form>

        <no-permission-error v-else />

        <post-preview-modal v-model:open="previewOpen" :post="form" />
    </div>
</template>

<script setup>
import { computed, defineComponent, inject, onMounted, ref, watch } from 'vue'
import { can, finishFailed, getDefaultLanguageId, legacy } from '@/mixins'
import { useEnumerates } from '@/hooks'
import { useRoute, useRouter } from 'vue-router'
import clsx from 'clsx'
import { useI18n } from 'vue-i18n'
import InputExcerpt from '@/cms/core/components/input-excerpt.vue'
import { useAppRuntime } from '@/runtime'
import { adminPostEditorExtensions } from '@/config/admin-modules'
import { useActiveModules } from '@/hooks/useActiveModules'
import PostPreviewModal from './post-preview-modal.vue'

defineComponent({
    name: 'PostCreate',
})

const { t } = useI18n()
const route = useRoute()
const { query } = route
const { type = 'post' } = query

const postMedia = inject('postMedia')
const router = useRouter()
const { languages } = useAppRuntime()
const { data: status } = useEnumerates()
const cateType = 0

const form = inject('form')
const formRef = inject('formRef')
const resetForm = inject('resetForm')
const submitForm = inject('submitForm')
const btnState = inject('btnState')
const getLimitImageByPostType = inject('getLimitImageByPostType')
const { formatLocalizedDateTime } = useAppRuntime()
const checkFormChanged = inject('checkFormChanged')
const formDefault = inject('formDefault')
const excerptState = ref(false)
const isFormChanged = ref(false)
const previewOpen = ref(false)
const { isModuleExtensionAllowed } = useActiveModules()
const postEditorExtensions = computed(() =>
    adminPostEditorExtensions.filter((extension) => isModuleExtensionAllowed(extension.module))
)
const discardChange = () => {
    // confirm before discard
    if (!isFormChanged.value) {
        router.back()
        return
    }

    if (!confirm(t('mgs.confirm.discard_confirm'))) {
        return
    }

    resetForm()
    isFormChanged.value = false
    router.back()
}

onMounted(async () => {
    resetForm()
    form.value.type = type
    //	Set default language
    if (languages.value?.data.length > 0) {
        form.value.language_id = getDefaultLanguageId(languages.value.data)
    }
})

watch(
    () => form.value,
    (val) => {
        isFormChanged.value = checkFormChanged(val, formDefault(), [
            'name',
            'content',
            'category_id',
            'review_pipeline',
        ])
    },
    { deep: true }
)
</script>
