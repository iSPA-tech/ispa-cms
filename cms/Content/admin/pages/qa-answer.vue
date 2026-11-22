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
                    text: $t('nav.b.reply'),
                    title: $t('nav.b.reply'),
                },
            ]"
        />

        <a-form
            v-if="can('post', 'update')"
            ref="formRef"
            :key="route.fullPath"
            :model="form"
            class="cms-form"
            layout="vertical"
            @finish="submitAnswerForm"
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

                        <div>
                            <h3 class="font-medium text-blk-950 mb-3 text-lg">
                                {{ $t('text.qa.question_content') }}
                            </h3>
                            <div class="text-sm space-y-2">
                                <div>
                                    <span class="font-semibold">{{ $t('text.qa.asker_name') }}:</span>
                                    {{ form.question_name || '—' }}
                                </div>
                                <div>
                                    <span class="font-semibold">{{ $t('text.qa.email') }}:</span>
                                    {{ form.question_email || '—' }}
                                </div>
                                <div>
                                    <div class="font-semibold">{{ $t('validation.attributes.content') }}:</div>
                                    <p class="mt-1 whitespace-pre-line text-blk-950">
                                        {{ form.question_content || '—' }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="i-box">
                        <h3 class="font-medium text-blk-950">{{ $t('text.qa.answer_content') }}</h3>

                        <a-form-item
                            :label="$t('text.qa.responder')"
                            name="responder_name"
                            :rules="[
                                {
                                    required: true,
                                    message: $t('text.qa.responder_required'),
                                },
                            ]"
                        >
                            <a-input v-model:value="form.responder_name" />
                        </a-form-item>

                        <a-form-item
                            :label="$t('text.qa.reply_content')"
                            name="answer_content"
                            :rules="[
                                {
                                    required: true,
                                    message: $t('text.qa.reply_required'),
                                },
                            ]"
                        >
                            <a-textarea
                                v-model:value="form.answer_content"
                                :auto-size="{ minRows: 12, maxRows: 20 }"
                                :placeholder="$t('text.qa.reply_placeholder')"
                            />
                        </a-form-item>

                        <a-form-item>
                            <a-checkbox
                                v-model:checked="form.send_email"
                                :disabled="!canSendEmail || isEmailSent"
                                :class="{ 'cursor-not-allowed': isEmailSent }"
                            >
                                {{ $t('text.qa.send_email') }}
                            </a-checkbox>
                            <div v-if="isEmailSent" class="text-xs text-green-600 mt-2">
                                {{ $t('text.qa.email_sent_at', { time: formattedEmailSentAt }) }}
                            </div>
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
import { useQueryClient } from '@tanstack/vue-query'
import { useI18n } from 'vue-i18n'
import dayjs from 'dayjs'
import { can, finishFailed, iAlert } from '@/mixins'
import { useAccount, useEnumerates } from '@/hooks'
import { useRoute, useRouter } from 'vue-router'
import { getPost } from '@/libs'
import ajax from '@/ajax'
import SlugV3 from '@/components/slug-v3.vue'
import SelectInputLanguage from '@/components/select-input-language.vue'
import InputCategory from '@/components/input-category.vue'
import { CATEGORY_TYPE, CATEGORY_TYPE_STR_POST } from '@/config'

defineComponent({
    name: 'PostQaAnswer',
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const formRef = ref(null)
const isSubmitting = ref(false)
const originalQuestion = ref({})
const queryClient = useQueryClient()
const { data: account } = useAccount()
const { data: status } = useEnumerates()

const adminResponderName = computed(() => {
    const currentAccount = account.value?.data || account.value || {}
    return String(
        currentAccount?.name ||
            currentAccount?.full_name ||
            currentAccount?.username ||
            currentAccount?.email ||
            ''
    ).trim()
})

const form = ref({
    id: '',
    name: '',
    status: 'status_published',
    language_id: null,
    question_name: '',
    question_email: '',
    question_content: '',
    responder_name: '',
    answer_content: '',
    send_email: false,
    email_sent_at: '',
    category_id: [],
    slugs: { slug: null },
})

const canSendEmail = computed(() => {
    return Boolean(form.value.question_email && String(form.value.question_email).trim())
})

const isEmailSent = computed(() => Boolean(form.value.email_sent_at))

const formattedEmailSentAt = computed(() => {
    if (!form.value.email_sent_at) {
        return ''
    }

    const parsed = dayjs(form.value.email_sent_at)
    if (!parsed.isValid()) {
        return form.value.email_sent_at
    }

    return parsed.format('HH:mm:ss DD/MM/YYYY')
})

const getBlock = (content = [], blockType = 'question') => {
    const blocks = Array.isArray(content) ? content : []
    return (
        blocks.find((item) => item?.block_type === blockType) ||
        blocks.find((item) => item?.type === blockType) ||
        {}
    )
}

const normalizeQuestionBlock = (block = {}) => {
    return {
        question_name: block.question_name || block.asker_name || '',
        question_email: block.question_email || block.asker_email || '',
        question_content: block.question_content || block.content || '',
        value: block.question_content || block.value || '',
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

const normalizeAnswerBlock = (block = {}) => {
    return {
        responder_name: block.responder_name || '',
        answered_at: block.answered_at || null,
        answer_content: block.answer_content || block.content || '',
        send_email: Boolean(block.send_email),
        email_sent_at: block.email_sent_at || '',
        value: block.answer_content || block.value || '',
    }
}

const getQuestionContentPayload = () => ({
    type: 'text',
    block_type: 'question',
    question_name: form.value.question_name,
    question_email: form.value.question_email || null,
    question_content: form.value.question_content,
    value: form.value.question_content || form.value.name || 'question',
})

const getAnswerContentPayload = (answeredAt) => ({
    type: 'text',
    block_type: 'answer',
    responder_name: form.value.responder_name,
    answered_at: answeredAt ? dayjs(answeredAt).toISOString() : null,
    answer_content: form.value.answer_content,
    send_email: canSendEmail.value && !isEmailSent.value ? form.value.send_email : false,
    email_sent_at: form.value.email_sent_at || null,
    value: form.value.answer_content || '',
})

const submitAnswerForm = async () => {
    if (isSubmitting.value) return
    const answeredAt = dayjs()
    const payload = {
        ...originalQuestion.value,
        id: form.value.id,
        type: 'qa',
        name: form.value.name,
        language_id: form.value.language_id,
        category_id: normalizeCategoryIdPayload(form.value.category_id),
        status: form.value.status,
        slugs: form.value.slugs || { slug: null },
        content: [getQuestionContentPayload(), getAnswerContentPayload(answeredAt)],
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
        const response = await ajax.put(`/post/${route.params.uuid}`, payload)
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
    const question = normalizeQuestionBlock(getBlock(data.content, 'question'))
    const answer = normalizeAnswerBlock(getBlock(data.content, 'answer'))

    form.value = {
        ...form.value,
        id: data.uuid || data.id || '',
        name: data.name || '',
        status: data.status || 'status_published',
        language_id: data.language_id || null,
        slugs: data.slugs || { slug: null },
        category_id: normalizeCategoryIdPayload(data.category_id),
        question_name: question.question_name,
        question_email: question.question_email,
        question_content: question.question_content,
        responder_name: answer.responder_name || adminResponderName.value,
        answer_content: answer.answer_content,
        send_email: answer.send_email,
        email_sent_at: answer.email_sent_at,
    }

    originalQuestion.value = {
        name: data.name,
        description: data.description || '',
        type: 'qa',
        language_id: data.language_id || null,
        category_id: normalizeCategoryIdPayload(data.category_id),
        slugs: data.slugs || { slug: null },
    }
}

watch(
    () => canSendEmail.value,
    (allowed) => {
        if (!allowed) {
            form.value.send_email = false
        }
    }
)

watch(
    () => isEmailSent.value,
    (sent) => {
        if (sent) {
            form.value.send_email = false
        }
    }
)

watch(
    () => account.value,
    () => {
        if (!form.value.responder_name && adminResponderName.value) {
            form.value.responder_name = adminResponderName.value
        }
    },
    { deep: true }
)

watch(
    () => route.fullPath,
    () => {
        void loadPost()
    },
    { immediate: true }
)
</script>
