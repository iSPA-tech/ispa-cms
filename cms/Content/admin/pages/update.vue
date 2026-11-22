<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="mx-auto max-w-7xl">
        <a-skeleton v-if="form.id < 1" active :rows="5" />
        <template v-else>
            <breadcrumbs
                :items="[
                    {
                        name: 'post-dashboard',
                        icon: 'text-box-edit-outline',
                        title: $t('nav.a.posts'),
                    },
                    {
                        text: form.name || $t('nav.b.update'),
                        title: form.name || $t('nav.b.update'),
                    },
                ]"
            >
                <i-button
                    v-if="can('post', 'update')"
                    variant="info"
                    @click="previewOpen = true"
                >
                    <template #icon>
                        <i-icon class="size-3.5" icon="eye" />
                    </template>
                    <span>{{ $t('nav.b.preview') }}</span>
                </i-button>
                <i-button :to="{ name: 'post-create' }" as="router-link" variant="default">
                    <template #icon>
                        <i-icon class="size-3.5" icon="comment" />
                    </template>
                    <span>{{ $t('nav.b.comment') }}</span>
                </i-button>
            </breadcrumbs>

            <a-form
                v-if="can('post', 'update')"
                ref="formRef"
                :model="form"
                class="cms-form"
                layout="vertical"
                @finish="manualSubmitForm"
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
                            {{ $t('text.form.un-save-change') }}
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
                                <div class="flex items-center justify-between">
                                    <span class="text-blk-950 font-medium">
                                        {{ $t('validation.attributes.content') }}
                                    </span>
                                    <i-button
                                        v-if="form.config?.crawler_from"
                                        variant="default"
                                        @click="showOriginalContent = true"
                                    >
                                        <template #icon>
                                            <i-icon class="size-3.5" icon="file-document" />
                                        </template>
                                        <span>{{ $t('text.post.update.original_source') }}</span>
                                    </i-button>
                                </div>

                                <original-content-drawer
                                    v-model:open="showOriginalContent"
                                    :crawler-from="form.config?.crawler_from || ''"
                                    :temp-id="form.config?.temp_id || null"
                                    :post-id="form.id || 0"
                                    @reparsed="refreshPost"
                                />

                                <i-contents
                                    v-model="form.content"
                                    :plugins="['text', 'image', 'video', 'widget']"
                                    :target-id="form.id"
                                    :initial-widget="initialWidget"
                                    category="content"
                                    no-label
                                    target="Post"
                                    @change="updateContent"
                                    @initial-widget-handled="initialWidget = null"
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

                            <!-- Pipeline status badges -->
                            <div
                                v-if="form.review_pipeline"
                                class="mt-2 space-y-1.5"
                            >
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span
                                        class="text-xs px-1.5 py-0.5 rounded font-medium"
                                        :class="{
                                            'bg-green-100 text-green-700': form.review_pipeline.decision === 'auto_publish',
                                            'bg-amber-100 text-amber-700': form.review_pipeline.decision === 'needs_review' || form.review_pipeline.decision === 'partial_publish',
                                            'bg-red-100 text-red-700': form.review_pipeline.decision === 'rejected',
                                        }"
                                    >
                                        {{ form.review_pipeline.decision }}
                                    </span>
                                    <span
                                        v-if="form.review_pipeline.quality_score"
                                        class="text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-600"
                                    >
                                        {{ $t('text.post.update.score', { score: form.review_pipeline.quality_score }) }}
                                    </span>
                                    <span
                                        v-if="pipelineImagesPending > 0"
                                        class="text-xs px-1.5 py-0.5 rounded bg-orange-100 text-orange-700"
                                    >
                                        {{ $t('text.post.update.images_pending', { count: pipelineImagesPending }) }}
                                    </span>
                                    <span
                                        v-else-if="form.review_pipeline.images_ready"
                                        class="text-xs px-1.5 py-0.5 rounded bg-green-50 text-green-600"
                                    >
                                        {{ $t('text.post.update.images_ready') }}
                                    </span>
                                </div>
                                <div
                                    v-if="pipelineApproved && pipelineImagesPending > 0"
                                    class="text-xs text-amber-600 bg-amber-50 rounded p-2"
                                >
                                    {{ $t('text.post.update.approved_waiting', { count: pipelineImagesPending }) }}
                                </div>
                                <post-editorial-review-warning
                                    :post-uuid="form.uuid || ''"
                                    :review-pipeline="form.review_pipeline"
                                    :stale="editorialReviewFieldsChanged"
                                />
                            </div>

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
                                        :title="`${$t('nav.b.delete')} ${$t('validation.attributes.published_at')}`"
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

                            <input-tag
                                v-if="!['notification'].includes(form.type)"
                                v-model="form"
                            />
                        </div>
                    </div>
                </div>
            </a-form>

            <no-permission-error v-else />

            <post-preview-modal v-model:open="previewOpen" :post="form" />
        </template>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, inject, nextTick, onMounted, ref, toRaw, watch } from 'vue'
import { can, deepCloneObj, finishFailed, legacy, objectAssignOnlyByKeyHasValue } from '@/mixins'
import { useRoute, useRouter } from 'vue-router'
import { useEnumerates } from '@/hooks'
import { getPost } from '@/libs'
import clsx from 'clsx'
import { useI18n } from 'vue-i18n'
import InputExcerpt from '@/cms/core/components/input-excerpt.vue'
import OriginalContentDrawer from './original-content-drawer.vue'
import { cloneDeep, isEqual } from 'lodash-es'
import { useAppRuntime } from '@/runtime'
import { adminPostEditorExtensions } from '@/config/admin-modules'
import { useActiveModules } from '@/hooks/useActiveModules'
import PostPreviewModal from './post-preview-modal.vue'
import PostEditorialReviewWarning from './post-editorial-review-warning.vue'

const { t } = useI18n()

defineComponent({
    name: 'PostUpdate',
})

const postMedia = inject('postMedia')
const route = useRoute()
const router = useRouter()
const { data: status } = useEnumerates()
const cateType = 0
const form = inject('form')
const formRef = inject('formRef')
const setForm = inject('setForm')
const submitForm = inject('submitForm')
const btnState = inject('btnState')
const getLimitImageByPostType = inject('getLimitImageByPostType')
const { formatLocalizedDateTime } = useAppRuntime()
const excerptState = ref(false)
const innerContent = ref([])
const showOriginalContent = ref(false)
const previewOpen = ref(false)
const { isModuleExtensionAllowed } = useActiveModules()
const postEditorExtensions = computed(() =>
    adminPostEditorExtensions.filter((extension) => isModuleExtensionAllowed(extension.module))
)

const pipelineImagesPending = computed(() => {
    return form.value?.review_pipeline?.steps?.final_reviewer?.mutations?.images_pending ?? 0
})
const pipelineApproved = computed(() => {
    return form.value?.review_pipeline?.steps?.final_reviewer?.mutations?.approved ?? false
})
const checkFormChanged = inject('checkFormChanged')
const isFormChanged = ref(false)
const originalData = ref({})
const baselineReady = ref(false)
const editorialReviewFieldsChanged = computed(() => baselineReady.value && !isEqual(
    [form.value?.name || '', form.value?.description || '', form.value?.content || []],
    [originalData.value?.name || '', originalData.value?.description || '', originalData.value?.content || []],
))

const resetBaseline = () => {
    originalData.value = cloneDeep(toRaw(form.value))
    isFormChanged.value = false
    baselineReady.value = true
}

// A post saved without config (e.g. seeded pages, config NULL) gets its defaults from i-form-config
// right after load. That fill is not an operator edit, so the baseline takes it too.
watch(
    () => form.value?.config,
    (config) => {
        if (!baselineReady.value || originalData.value?.config != null || config == null) return
        originalData.value = { ...originalData.value, config: cloneDeep(toRaw(config)) }
    }
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

    setForm(cloneDeep(originalData.value))
    isFormChanged.value = false
    router.back()
}

const updateContent = (value) => {
    if (Array.isArray(value)) {
        innerContent.value = value
    }
}

const manualSubmitForm = (val = {}) => {
    const contentSource = Array.isArray(form.value?.content)
        ? form.value.content
        : Array.isArray(innerContent.value)
          ? innerContent.value
          : []
    const value = objectAssignOnlyByKeyHasValue(cloneDeep(toRaw(form.value)), val)

    value.content = cloneDeep(toRaw(contentSource))

    return submitForm(value)
}

// "Thêm vào… → Trang" in the widget library opens this page with `?add_widget=<id>&theme=<id>`. The two values are
// read once and taken out of the address without a history entry, so a reload or coming back adds nothing again.
// The widget becomes one new block at the end of the content, in the editor only: the operator still saves the page.
let pendingWidget = null
const initialWidget = ref(null)

const takeWidgetFromAddress = () => {
    const { add_widget: widgetId, theme: templateId, ...query } = route.query
    if (widgetId === undefined && templateId === undefined) return

    pendingWidget = { widgetId: Number(widgetId) || 0, templateId: Number(templateId) || 0 }
    router.replace({ query })
}

const loadPost = () => {
    getPost(route.params.uuid, true).then((res) => {
        setForm(deepCloneObj(res.data))
        innerContent.value = res.data.content
        if (res.data.description && res.data.description.length > 0) {
            excerptState.value = true
        }
        nextTick(() => {
            resetBaseline()
            // After the baseline, so the new block counts as an unsaved change and the save bar shows.
            if (pendingWidget) {
                initialWidget.value = pendingWidget
                pendingWidget = null
            }
        })
    })
}

const refreshPost = () => {
    showOriginalContent.value = false
    loadPost()
}

onMounted(() => {
    takeWidgetFromAddress()
    loadPost()
})

watch(
    () => form.value,
    (val) => {
        if (!baselineReady.value) {
            isFormChanged.value = false
            return
        }

        const currentSnapshot = cloneDeep(toRaw(val))
        const baselineSnapshot = cloneDeep(toRaw(originalData.value))

        isFormChanged.value = !isEqual(currentSnapshot, baselineSnapshot)
    },
    { immediate: true, deep: true }
)
</script>
