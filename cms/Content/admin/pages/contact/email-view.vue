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
                name: 'contact-dashboard',
                title: $t('nav.a.contacts'),
                icon: 'email-outline',
            },
            {
                name: 'email-all',
                title: $t('text.a.email-manage'),
                icon: 'email-multiple',
            },
            {
                text: dataRef?.subject || $t('text.a.email-manage'),
                title: dataRef?.subject || $t('text.a.email-manage'),
                icon: 'email-open-outline',
            },
        ]"
    />

    <a-spin :indicator="loadingSpinner()" :spinning="isLoading">
        <section class="i-box overflow-hidden p-0!" aria-labelledby="message-heading">
            <div class="flex min-h-14 items-center justify-between border-b border-gray-200 bg-white px-3 sm:px-4">
                <div class="flex items-center gap-1 sm:gap-2">
                    <a-button :aria-label="$t('text.contact.email.back_to_list')" type="text" @click="goBack">
                        <i-icon icon="arrow-left" />
                    </a-button>
                    <a-button :aria-label="$t('text.contact.email.refresh')" type="text" @click="getMail(route.params.uuid)">
                        <i-icon icon="refresh" />
                    </a-button>
                </div>

                <a-popconfirm
                    :ok-button-props="{ danger: true }"
                    :title="$t('nav.b.delete')"
                    @confirm="delMail(dataRef?.uuid)"
                >
                    <a-button :aria-label="$t('text.contact.email.delete')" danger type="text">
                        <i-icon icon="delete" />
                    </a-button>
                </a-popconfirm>
            </div>

            <div class="grid bg-white lg:grid-cols-[minmax(0,1fr)_18rem]">
                <article class="min-w-0">
                    <header class="border-b border-gray-100 px-4 py-5 sm:px-8">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 id="message-heading" class="min-w-0 text-xl font-normal text-gray-900 sm:text-2xl">
                                {{ dataRef?.subject || '—' }}
                            </h1>
                            <a-tag class="m-0" color="blue">{{ mailboxLabel(dataRef?.type) }}</a-tag>
                        </div>

                        <div class="mt-5 flex items-start gap-3">
                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-sky-100 font-semibold text-sky-700"
                                aria-hidden="true"
                            >
                                {{ senderInitial }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span class="font-semibold text-gray-900">{{ senderName }}</span>
                                    <span class="truncate text-sm text-gray-500">{{ dataRef?.from_email || '—' }}</span>
                                </div>
                                <p class="m-0 mt-0.5 text-xs text-gray-500">{{ dataRef?.created_at || '' }}</p>
                            </div>
                            <a-button
                                :aria-expanded="asideState"
                                :title="$t('nav.a.contacts')"
                                data-test="contact-toggle"
                                type="text"
                                @click="asideToggle"
                            >
                                <i-icon icon="account-box-outline" />
                                <span class="hidden pl-1 sm:inline">{{ $t('nav.a.contacts') }}</span>
                            </a-button>
                        </div>
                    </header>

                    <div class="px-4 py-6 sm:px-8 sm:py-8">
                        <SafeHtml
                            class="mail-content mx-auto max-w-none overflow-x-auto text-sm leading-6 text-gray-800"
                            :html="dataRef?.content"
                        />
                    </div>

                    <div class="border-t border-gray-100 px-4 py-5 sm:px-8">
                        <div v-if="dataRef?.type === 'inbox' && !formState" class="flex items-center gap-2">
                            <a-button class="rounded-full px-5" data-test="reply-toggle" @click="openForm">
                                <i-icon icon="reply" />
                                <span class="pl-2">{{ $t('nav.b.reply') }}</span>
                            </a-button>
                        </div>

                        <div
                            v-else-if="formState"
                            class="rounded-xl border border-gray-300 bg-white shadow-sm"
                            data-test="reply-composer"
                        >
                            <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 text-sm">
                                <i-icon icon="reply" class="text-gray-500" />
                                <div class="min-w-0">
                                    <span class="font-medium text-gray-800">{{ $t('nav.b.reply') }}</span>
                                    <span class="text-gray-500"> · {{ mailForm.name }} &lt;{{ mailForm.email }}&gt;</span>
                                </div>
                            </div>
                            <a-form
                                :model="mailForm"
                                class="p-4"
                                layout="vertical"
                                @finish="sendMail"
                                @finish-failed="finishFailed"
                            >
                                <a-form-item class="mb-3" name="content">
                                    <div class="block w-full bg-white">
                                        <tip-tap-editor v-model="mailForm.content" />
                                    </div>
                                </a-form-item>

                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <a-button @click="closeForm">{{ $t('nav.b.cancel') }}</a-button>
                                        <a-button
                                            :disabled="btnState"
                                            :loading="btnState"
                                            danger
                                            html-type="submit"
                                            type="primary"
                                        >
                                            <i-icon icon="send-variant" />
                                            <span class="pl-2">{{ $t('nav.b.send') }}</span>
                                        </a-button>
                                    </div>
                                </div>
                            </a-form>
                        </div>
                    </div>
                </article>

                <aside
                    v-if="asideState"
                    class="border-t border-gray-200 bg-slate-50 px-5 py-6 lg:border-t-0 lg:border-l"
                    data-test="contact-panel"
                >
                    <div class="mb-5 flex items-center justify-between">
                        <h2 class="m-0 text-sm font-semibold text-gray-800">{{ $t('nav.a.contacts') }}</h2>
                        <a-button :aria-label="$t('nav.b.close')" type="text" @click="asideToggle">
                            <i-icon icon="close" />
                        </a-button>
                    </div>

                    <dl class="space-y-5 text-sm">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                {{ $t('text.contact-name') }}
                            </dt>
                            <dd class="m-0 mt-1 break-words text-gray-800">{{ dataRef?.contact?.name || '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                {{ $t('text.contact-email') }}
                            </dt>
                            <dd class="m-0 mt-1 break-all text-gray-800">{{ dataRef?.contact?.email || '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                {{ $t('text.contact-phone') }}
                            </dt>
                            <dd class="m-0 mt-1 break-words text-gray-800">{{ dataRef?.contact?.mobile || '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                {{ $t('text.contact-address') }}
                            </dt>
                            <dd class="m-0 mt-1 break-words text-gray-800">{{ dataRef?.contact?.address || '—' }}</dd>
                        </div>
                    </dl>
                </aside>
            </div>
        </section>
    </a-spin>
</template>

<script setup>
import { computed, defineComponent, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { finishFailed, iAlert, loadingSpinner, objectAssignOnlyByKeyHasValue } from '@/mixins'
import { deleteEmail, getEmail, replyEmail } from '@/libs'
import TipTapEditor from '@/components/tiptap-editor.vue'
import SafeHtml from '@/components/common/SafeHtml.vue'
import { useI18n } from 'vue-i18n'

defineComponent({
    name: 'EmailView',
})

const { t } = useI18n()
const asideState = ref(false)
const route = useRoute()
const router = useRouter()
const dataRef = ref({})
const isLoading = ref(false)
const formState = ref(false)
const btnState = ref(false)
const mailForm = ref({})

const senderName = computed(() => dataRef.value?.from_name || dataRef.value?.contact?.name || '—')
const senderInitial = computed(() => senderName.value.trim().charAt(0).toUpperCase() || '?')
const mailboxLabel = (type) => (type === 'sent' ? t('nav.a.email-sent') : t('nav.a.inbox'))
const asideToggle = () => {
    asideState.value = !asideState.value
}
const getMail = async (id = 0) => {
    isLoading.value = true
    try {
        const res = await getEmail(id)
        if (res?.status === 'success') {
            dataRef.value = res.data
        }
        if (res?.message) {
            iAlert(res.message)
        }
    } catch (error) {
        iAlert(error, true)
    } finally {
        isLoading.value = false
    }
}
const goBack = () => {
    router.go(-1)
}
const openForm = () => {
    formState.value = true
    mailForm.value = {
        subject: `Re: ${dataRef.value.subject}`,
        content: `<br><br><blockquote>${t('text.contact.email.reply_quote', { date: dataRef.value.created_at, sender: senderName.value })}<br><br>${dataRef.value.content}</blockquote>`,
        name: dataRef.value.from_name || dataRef.value.contact?.name || null,
        email: dataRef.value.from_email || dataRef.value.contact?.email || null,
        reply_id: dataRef.value.id,
        contact_id: dataRef.value.contact_id,
        type: 'sent',
    }
}
const closeForm = () => {
    formState.value = false
    mailForm.value = {}
}
const sendMail = async (data = {}) => {
    if (btnState.value) {
        return
    }

    btnState.value = true
    const value = objectAssignOnlyByKeyHasValue(mailForm.value, data)
    try {
        const res = await replyEmail(value.reply_id, value)
        if (res?.status === 'success') {
            closeForm()
            await getMail(route.params.uuid)
        }
        if (res?.message) {
            iAlert(res.message)
        }
    } catch (error) {
        iAlert(error, true)
    } finally {
        btnState.value = false
    }
}
const delMail = async (id = 0) => {
    try {
        const res = await deleteEmail(id)
        if (res?.status === 'success') {
            goBack()
        }
        if (res?.message) {
            iAlert(res.message)
        }
    } catch (error) {
        iAlert(error, true)
    }
}

onMounted(() => {
    getMail(route.params.uuid)
})
</script>
