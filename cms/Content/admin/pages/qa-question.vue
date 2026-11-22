<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div :key="route.fullPath" class="mx-auto max-w-7xl">
        <breadcrumbs
            :items="[
                {
                    name: 'post-dashboard',
                    icon: 'text-box-edit-outline',
                    title: $t('nav.a.posts'),
                },
                {
                    name: 'post-qa',
                    icon: 'comment-question-outline',
                    title: $t('text.qa.title'),
                },
                {
                    text: isEditMode ? $t('nav.b.update') : $t('nav.b.create'),
                    title: isEditMode ? $t('nav.b.update') : $t('nav.b.create'),
                },
            ]"
        />

        <a-form
            v-if="can('post', isEditMode ? 'update' : 'create')"
            ref="formRef"
            :key="route.fullPath"
            :model="form"
            class="cms-form"
            layout="vertical"
            @finish="submitQuestionForm"
            @finish-failed="finishFailed"
        >
            <div class="grid grid-cols-1 md:grid-cols-5 lg:grid-cols-4 gap-3">
                <div class="col-span-1 md:col-span-3 lg:col-span-3 space-y-3">
                    <div class="i-box">
                        <slug-v3
                            v-model="form"
                            :label="$t('validation.attributes.title')"
                            module="post"
                        />

                        <a-form-item
                            :label="$t('text.qa.asker_name')"
                            name="question_name"
                            :rules="[
                                {
                                    required: true,
                                    message: $t('text.qa.asker_name_required'),
                                },
                            ]"
                        >
                            <a-input
                                v-model:value="form.question_name"
                                :placeholder="$t('text.qa.asker_name')"
                                :maxlength="255"
                            />
                        </a-form-item>

                        <a-form-item :label="$t('text.qa.asker_email')" name="question_email">
                            <a-input
                                v-model:value="form.question_email"
                                :placeholder="$t('text.qa.asker_email_placeholder')"
                                :maxlength="255"
                            />
                        </a-form-item>

                        <a-form-item
                            :label="$t('text.qa.question_content')"
                            name="question_content"
                            :rules="[
                                {
                                    required: true,
                                    message: $t('text.qa.question_required'),
                                },
                            ]"
                        >
                            <a-textarea
                                v-model:value="form.question_content"
                                :auto-size="{ minRows: 8, maxRows: 14 }"
                                :placeholder="$t('text.qa.question_placeholder')"
                            />
                        </a-form-item>
                    </div>
                </div>

                <div class="md:col-span-2 lg:col-span-1 space-y-3">
                    <div class="i-box">
                        <h3 class="font-medium text-blk-950">{{ $t('text.qa.config') }}</h3>
                        <div class="pt-3">
                            <a-form-item :label="$t('validation.attributes.status')" name="status">
                                <a-radio-group
                                    v-model:value="form.status"
                                    class="flex flex-wrap gap-2"
                                    :options="
                                        (status || [])
                                            .filter((s) => s.type === 'status')
                                            .map((s) => ({
                                                label: `${s.name} (${s.enum_key})`,
                                                value: s.enum_key,
                                            }))
                                    "
                                />
                            </a-form-item>

                            <select-input-language
                                v-model="form.language_id"
                                :label="$t('nav.a.languages')"
                                name="language_id"
                            />

                            <input-category
                                v-model="form"
                                :label="$t('nav.a.categories')"
                                :type="CATEGORY_TYPE[CATEGORY_TYPE_STR_POST]"
                                multiple
                            />
                        </div>
                    </div>

                    <div class="i-box">
                        <a-button
                            class="btn-danger w-full justify-center"
                            :loading="isSubmitting"
                            :disabled="isSubmitting"
                            html-type="submit"
                        >
                            <i-icon class="size-3.5 mr-1" icon="content-save" />
                            {{ $t('nav.b.save') }}
                        </a-button>
                    </div>
                </div>
            </div>
        </a-form>

        <no-permission-error v-else />
    </div>
</template>

<script setup>
import { computed, defineComponent, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { can, finishFailed, getDefaultLanguageId, iAlert } from '@/mixins'
import { useEnumerates } from '@/hooks'
import { useRoute, useRouter } from 'vue-router'
import { useQueryClient } from '@tanstack/vue-query'
import ajax from '@/ajax'
import { getPost } from '@/libs'
import { useAppRuntime } from '@/runtime'
import SlugV3 from '@/components/slug-v3.vue'
import SelectInputLanguage from '@/components/select-input-language.vue'
import InputCategory from '@/components/input-category.vue'
import { CATEGORY_TYPE, CATEGORY_TYPE_STR_POST } from '@/config'

defineComponent({
    name: 'PostQaQuestion',
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const formRef = ref(null)
const isSubmitting = ref(false)
const isEditMode = computed(() => route.name === 'post-qa-question-update')
const queryClient = useQueryClient()

const { data: status } = useEnumerates()
const { languages } = useAppRuntime()

const defaultQuestionBlock = (overrides = {}) => ({
    type: 'text',
    block_type: 'question',
    question_name: '',
    question_email: null,
    question_content: '',
    value: '',
    ...overrides,
})

const defaultAnswerBlock = (overrides = {}) => ({
    type: 'text',
    block_type: 'answer',
    responder_name: '',
    answered_at: null,
    answer_content: '',
    send_email: false,
    value: '',
    ...overrides,
})

const getDefaultForm = () => ({
    id: null,
    type: 'qa',
    status: 'status_published',
    name: '',
    slugs: { slug: null },
    description: '',
    language_id: null,
    category_id: [],
    question_name: '',
    question_email: '',
    question_content: '',
    answer_block: defaultAnswerBlock(),
})

const form = ref(getDefaultForm())

const makeSlug = (value = '') => {
    return String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9\s-_]/g, '')
        .trim()
        .replace(/[\s_]+/g, '-')
        .replace(/-+/g, '-')
}

const normalizeTextBlock = (block = {}, type = 'question') => {
    if (!block || typeof block !== 'object') {
        return type === 'question' ? defaultQuestionBlock() : defaultAnswerBlock()
    }

    const isQuestion =
        block.block_type === 'question' || (!block.block_type && block.type === 'question')
    const isAnswer = block.block_type === 'answer' || (!block.block_type && block.type === 'answer')
    if (type === 'question' && !isQuestion) {
        return defaultQuestionBlock()
    }

    if (type === 'answer' && !isAnswer) {
        return defaultAnswerBlock()
    }

    if (type === 'question') {
        return {
            ...defaultQuestionBlock(),
            ...block,
            question_name: block.question_name || block.name || block.asker_name || '',
            question_email: block.question_email || block.email || '',
            question_content: block.question_content || block.content || '',
            value: block.question_content || block.value || '',
        }
    }

    return {
        ...defaultAnswerBlock(),
        ...block,
        answer_content: block.answer_content || block.content || '',
        value: block.answer_content || block.value || '',
    }
}

const normalizeCategoryIdPayload = (categoryId) => {
    if (categoryId == null || categoryId === '') {
        return []
    }

    if (Array.isArray(categoryId)) {
        return categoryId
    }

    return [categoryId]
}

const getPayloadQuestionBlock = () => {
    const questionName = String(form.value.question_name || '').trim()
    const questionEmail = String(form.value.question_email || '').trim()
    const questionContent = String(form.value.question_content || '')

    return {
        ...defaultQuestionBlock({
            question_name: questionName,
            question_email: questionEmail || null,
            question_content: questionContent,
            value: questionContent || questionName || 'question',
        }),
    }
}

const getPayloadAnswerBlock = () => {
    const answerBlock = form.value.answer_block || defaultAnswerBlock()
    const answerContent = String(answerBlock.answer_content || '')
    return {
        ...answerBlock,
        type: 'text',
        block_type: 'answer',
        value: answerContent,
    }
}

const submitQuestionForm = async () => {
    if (isSubmitting.value) return
    const payload = {
        id: form.value.id,
        type: 'qa',
        name: form.value.name,
        description: form.value.description || '',
        status: form.value.status,
        language_id: form.value.language_id,
        category_id: normalizeCategoryIdPayload(form.value.category_id),
        slugs: {
            ...(form.value.slugs || {}),
            slug: form.value.slugs?.slug || makeSlug(form.value.name),
        },
        content: [getPayloadQuestionBlock(), getPayloadAnswerBlock()],
    }

    if (!payload.slugs.slug) {
        iAlert(
            {
                status: 'error',
                message: t('validation.required', {
                    a: t('validation.attributes.slug'),
                }),
            },
            true
        )
        return
    }

    if (isSubmitting.value) {
        return
    }
    isSubmitting.value = true
    try {
        const response = isEditMode.value
            ? await ajax.put(`/post/${route.params.uuid}`, payload)
            : await ajax.post('/post', payload)

        if (response?.data?.message) {
            iAlert(response.data)
        }
        if (response?.data?.status === 'success') {
            await queryClient.invalidateQueries({ queryKey: ['posts'] })
            router.push({ name: 'post-qa' })
        }
    } catch (error) {
        iAlert(error, true)
    } finally {
        isSubmitting.value = false
    }
}

const loadPost = async () => {
    if (!route.params.uuid) {
        return
    }

    const res = await getPost(route.params.uuid, true)
    const data = res?.data || {}
    const content = parsePostContent(data.content)

    form.value = {
        ...form.value,
        id: data.uuid || data.id || null,
        name: data.name || '',
        status: data.status || 'status_published',
        language_id: data.language_id || null,
        category_id: normalizeCategoryIdPayload(data.category_id),
        slugs: data.slugs || { slug: null },
        question_name: content.question.question_name,
        question_email: content.question.question_email || '',
        question_content: content.question.question_content || '',
        answer_block: content.answer,
    }
}

const parsePostContent = (content = []) => {
    const blocks = Array.isArray(content) ? content : []
    const questionBlock =
        blocks.find((item) => item?.block_type === 'question' || item?.type === 'question') || {}
    const answerBlock =
        blocks.find((item) => item?.block_type === 'answer' || item?.type === 'answer') || {}

    return {
        question: normalizeTextBlock(questionBlock, 'question'),
        answer: normalizeTextBlock(answerBlock, 'answer'),
    }
}

const initDefaultLanguage = () => {
    if (form.value.language_id) {
        return
    }

    if (languages.value?.data?.length > 0) {
        form.value.language_id = getDefaultLanguageId(languages.value.data)
    }
}

const initByMode = async () => {
    form.value = getDefaultForm()
    try {
        if (isEditMode.value) {
            await loadPost()
            return
        }
        initDefaultLanguage()
    } catch (error) {
        iAlert(error, true)
    }
}

watch(
    () => route.fullPath,
    () => {
        void initByMode()
    },
    { immediate: true }
)
</script>
