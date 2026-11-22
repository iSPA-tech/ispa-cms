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
                text: $t('text.a.email-manage'),
                title: $t('text.a.email-manage'),
                icon: 'email-multiple',
            },
        ]"
    />

    <section class="i-box overflow-hidden p-0!" :aria-label="t('text.contact.email.mailbox')">
        <div class="bg-white">
            <div class="flex min-h-14 items-center justify-between border-b border-gray-200 px-3 sm:px-4">
                <div class="flex items-center gap-1 sm:gap-2">
                    <a-checkbox
                        :checked="isAllSelected"
                        :disabled="!emails.length || isDeleting || isMarkingSpam"
                        :indeterminate="isIndeterminate"
                        :aria-label="t('text.contact.email.select_all')"
                        @change="toggleAll($event.target.checked)"
                    />
                    <a-button
                        :aria-label="t('text.contact.email.refresh_list')"
                        type="text"
                        @click="refetch"
                    >
                        <i-icon icon="refresh" />
                    </a-button>
                    <a-popconfirm
                        v-if="spamUuids.length"
                        @confirm="markSelectedAsSpam"
                    >
                        <template #title>
                            <div class="max-w-xs space-y-2">
                                <p>
                                    {{ t('text.contact.email.spam.confirm', { count: spamUuids.length }) }}
                                </p>
                                <a-checkbox v-model:checked="blockCustomDomains">
                                    {{ t('text.contact.email.spam.block_custom_domain') }}
                                </a-checkbox>
                            </div>
                        </template>
                        <a-button
                            :disabled="isDeleting || isMarkingSpam"
                            :loading="isMarkingSpam"
                            :aria-label="t('text.contact.email.spam.action')"
                            type="text"
                        >
                            <i-icon icon="alert-octagon-outline" />
                        </a-button>
                    </a-popconfirm>
                    <a-popconfirm
                        v-if="selectedUuids.length"
                        :ok-button-props="{ danger: true }"
                        :title="$t('nav.b.delete')"
                        @confirm="deleteSelected"
                    >
                        <a-button
                            :disabled="isDeleting || isMarkingSpam"
                            :loading="isDeleting"
                            :aria-label="t('text.contact.email.delete_selected')"
                            danger
                            type="text"
                        >
                            <i-icon icon="delete" />
                        </a-button>
                    </a-popconfirm>
                </div>

                <div class="flex items-center gap-1 text-xs text-gray-500 sm:gap-2 sm:text-sm">
                    <span data-test="mail-page-summary">{{ pageSummary }}</span>
                    <a-button
                        :disabled="!canGoPrevious"
                        :aria-label="t('text.contact.email.page_first_label')"
                        class="hidden sm:inline-flex"
                        :title="t('text.contact.email.page_first')"
                        type="text"
                        @click="changePage(1)"
                    >
                        {{ t('pagination.first') }}
                    </a-button>
                    <a-button
                        :disabled="!canGoPrevious"
                        :aria-label="t('text.contact.email.page_prev_label')"
                        :title="t('text.contact.email.page_prev')"
                        type="text"
                        @click="changePage(currentPage - 1)"
                    >
                        <i-icon icon="chevron-left" />
                    </a-button>
                    <span class="hidden sm:inline">{{ currentPage }} / {{ lastPage }}</span>
                    <a-button
                        :disabled="!canGoNext"
                        :aria-label="t('text.contact.email.page_next_label')"
                        :title="t('text.contact.email.page_next')"
                        type="text"
                        @click="changePage(currentPage + 1)"
                    >
                        <i-icon icon="chevron-right" />
                    </a-button>
                    <a-button
                        :disabled="!canGoNext"
                        :aria-label="t('text.contact.email.page_last_label')"
                        class="hidden sm:inline-flex"
                        :title="t('text.contact.email.page_last')"
                        type="text"
                        @click="changePage(lastPage)"
                    >
                        {{ t('pagination.last') }}
                    </a-button>
                </div>
            </div>

            <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 px-3 sm:px-4" :aria-label="t('text.contact.email.mailbox_filters')">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    class="shrink-0 border-b-2 px-3 py-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:ring-offset-[-2px]"
                    :class="
                        activeTab === tab.key
                            ? 'border-sky-600 text-sky-700'
                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-800'
                    "
                    type="button"
                    @click="switchTab(tab.key)"
                >
                    {{ tab.label }}
                </button>
            </nav>

            <div class="border-b border-gray-200 px-3 py-3 sm:px-4">
                <form
                    class="flex flex-col gap-2 sm:flex-row sm:items-center"
                    data-test="mail-search-form"
                    @submit.prevent="applyFilters"
                >
                    <label class="sr-only" for="mailbox-search">{{ t('text.contact.email.search_label') }}</label>
                    <input
                        id="mailbox-search"
                        v-model="searchQuery"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-100 sm:max-w-xl"
                        data-test="mail-search"
                        :placeholder="t('text.contact.email.search_placeholder')"
                        type="search"
                    />
                    <div class="flex gap-2">
                        <a-button html-type="submit" type="primary">{{ t('nav.b.filter') }}</a-button>
                        <a-button v-if="searchQuery || queryData.query" type="button" @click="clearFilters">
                            {{ t('text.contact.email.clear_filters') }}
                        </a-button>
                    </div>
                </form>
            </div>

            <a-spin :spinning="isFetching">
                <div v-if="emails.length" class="divide-y divide-gray-200">
                    <article
                        v-for="row in emails"
                        :key="row.uuid"
                        :aria-label="`${senderName(row)}: ${row.subject || ''}`"
                        :class="[
                            isUnread(row) ? 'bg-sky-50/70' : 'bg-white',
                            selectedUuids.includes(row.uuid) ? 'bg-sky-100/80' : '',
                        ]"
                        class="group flex cursor-pointer items-center gap-3 px-3 py-3 transition-colors hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-sky-500 sm:px-4"
                        data-test="mail-row"
                        role="button"
                        tabindex="0"
                        @click="openEmail(row)"
                        @keydown.enter="openEmail(row)"
                        @keydown.space.prevent="openEmail(row)"
                    >
                        <a-checkbox
                            :checked="selectedUuids.includes(row.uuid)"
                            :disabled="isDeleting || isMarkingSpam"
                            :aria-label="t('text.contact.email.select_row', { subject: row.subject || senderName(row) })"
                            class="shrink-0"
                            @click.stop
                            @change="toggleRow(row.uuid, $event.target.checked)"
                        />

                        <div class="min-w-0 shrink-0 md:w-48">
                            <div
                                :class="isUnread(row) ? 'font-semibold text-gray-900' : 'text-gray-700'"
                                class="truncate text-sm"
                            >
                                {{ senderName(row) }}
                            </div>
                            <div class="truncate text-xs text-gray-500 md:hidden">
                                {{ senderEmail(row) }}
                            </div>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex min-w-0 items-baseline gap-2">
                                <span
                                    v-if="isUnread(row)"
                                    class="size-2 shrink-0 rounded-full bg-sky-600"
                                    :title="$t('nav.a.contact-unread')"
                                />
                                <span
                                    :class="isUnread(row) ? 'font-semibold text-gray-900' : 'text-gray-800'"
                                    class="truncate text-sm"
                                >
                                    {{ row.subject || '—' }}
                                </span>
                                <span class="hidden truncate text-sm text-gray-500 md:block">
                                    — {{ emailExcerpt(row.content) }}
                                </span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-gray-500 md:hidden">
                                {{ emailExcerpt(row.content) }}
                            </p>
                            <div
                                v-if="attachmentCount(row)"
                                class="mt-1 inline-flex items-center gap-1 rounded-full border border-gray-200 bg-white px-2 py-0.5 text-xs text-gray-600"
                            >
                                <i-icon icon="paperclip" class="size-3" />
                                <span>{{ attachmentCount(row) }}</span>
                            </div>
                        </div>

                        <time
                            :class="isUnread(row) ? 'font-semibold text-gray-800' : 'text-gray-500'"
                            class="shrink-0 text-xs sm:text-sm"
                        >
                            {{ row.created_at }}
                        </time>
                    </article>
                </div>

                <a-empty
                    v-else-if="!isFetching"
                    :description="$t('text.empty-data')"
                    class="py-16"
                />
            </a-spin>
        </div>
    </section>
</template>

<script setup>
import { computed, defineComponent, inject, onMounted, reactive, ref, watch } from 'vue'
import { useEmails } from '@/hooks'
import { useRoute, useRouter } from 'vue-router'
import { iAlert, initFilter } from '@/mixins'
import { deleteEmail, markEmailsAsSpam } from '@/libs'
import { useI18n } from 'vue-i18n'

defineComponent({
    name: 'AllEmail',
})

const { t } = useI18n()
const PAGE_SIZE = 20
const getCounter = inject('getCounter')
const route = useRoute()
const router = useRouter()
const activeTab = ref(route.query.type || 'inbox')
const selectedUuids = ref([])
const isDeleting = ref(false)
const isMarkingSpam = ref(false)
const blockCustomDomains = ref(false)
const searchQuery = ref(String(route.query.query || ''))
const queryData = reactive(initFilter({ type: activeTab.value, limit: PAGE_SIZE }, route.query))
const { data: dataRef, isFetching, refetch } = useEmails(queryData, {
    keepPreviousData: true,
})

const tabs = computed(() => [
    { key: 'inbox', label: t('nav.a.inbox') },
    { key: 'unread', label: t('nav.a.contact-unread') },
    { key: 'sent', label: t('nav.a.email-sent') },
])
const emails = computed(() => dataRef.value?.data?.data || [])
const emailMeta = computed(() => dataRef.value?.data || {})
const currentPage = computed(() => emailMeta.value.current_page || 1)
const lastPage = computed(() => emailMeta.value.last_page || 1)
const canGoPrevious = computed(() => currentPage.value > 1)
const canGoNext = computed(() => currentPage.value < lastPage.value)
const pageSummary = computed(() => {
    const { from = 0, to = 0, total = 0 } = emailMeta.value
    return `${from || 0}–${to || 0} / ${total || 0}`
})
const isAllSelected = computed(
    () => emails.value.length > 0 && emails.value.every((row) => selectedUuids.value.includes(row.uuid))
)
const isIndeterminate = computed(
    () => selectedUuids.value.length > 0 && !isAllSelected.value
)

const senderName = (row) => row?.contact?.name || row?.from_name || row?.from_email || '—'
const senderEmail = (row) => row?.from_email || row?.contact?.email || ''
const emailExcerpt = (content) =>
    String(content || '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim()
const attachmentCount = (row) => (Array.isArray(row?.files) ? row.files.length : 0)
const isUnread = (row) => row?.type === 'inbox' && !row?.last_read
const isSpamEligible = (row) => row?.type === 'inbox' && Boolean(senderEmail(row))
const spamUuids = computed(() =>
    emails.value
        .filter((row) => selectedUuids.value.includes(row.uuid) && isSpamEligible(row))
        .map((row) => row.uuid)
        .filter(Boolean)
)

const normalizeRouteQuery = (updates = {}) => {
    const next = { ...route.query, ...updates }

    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] === null || next[key] === undefined) {
            delete next[key]
        }
    })

    return next
}
const pushMailboxQuery = (updates) => {
    router.push({ name: route.name, query: normalizeRouteQuery(updates) })
}
const syncQueryData = (value) => {
    activeTab.value = value.type || 'inbox'
    const next = initFilter({ type: activeTab.value, limit: PAGE_SIZE }, value)

    Object.keys(queryData).forEach((key) => {
        if (!(key in next)) {
            delete queryData[key]
        }
    })
    Object.assign(queryData, next)
    searchQuery.value = String(next.query || '')
}

const openEmail = (row) => {
    if (row?.uuid) {
        router.push({ name: 'email-view', params: { uuid: row.uuid } })
    }
}
const toggleRow = (uuid, checked) => {
    if (!uuid) {
        return
    }
    selectedUuids.value = checked
        ? [...new Set([...selectedUuids.value, uuid])]
        : selectedUuids.value.filter((item) => item !== uuid)
}
const toggleAll = (checked) => {
    selectedUuids.value = checked ? emails.value.map((row) => row.uuid).filter(Boolean) : []
}
const switchTab = (type) => {
    activeTab.value = type
    selectedUuids.value = []
    Object.assign(queryData, { type, page: 1 })
    pushMailboxQuery({ type, page: 1 })
}
const changePage = (page) => {
    if (page < 1 || page > lastPage.value) {
        return
    }
    selectedUuids.value = []
    queryData.page = page
    pushMailboxQuery({ page })
}
const applyFilters = () => {
    const query = searchQuery.value.trim()

    selectedUuids.value = []
    Object.assign(queryData, { query, page: 1 })
    pushMailboxQuery({ query: query || undefined, page: 1 })
}
const clearFilters = () => {
    searchQuery.value = ''
    selectedUuids.value = []
    delete queryData.query
    queryData.page = 1
    pushMailboxQuery({ query: undefined, page: 1 })
}
const deleteSelected = async () => {
    const rows = emails.value.filter((row) => selectedUuids.value.includes(row.uuid) && row.uuid)
    if (!rows.length) {
        return
    }

    isDeleting.value = true
    try {
        const responses = await Promise.all(rows.map((row) => deleteEmail(row.uuid)))
        const failed = responses.find((response) => response?.status !== 'success')
        if (failed?.message) {
            iAlert(failed.message)
        }
        selectedUuids.value = []
        getCounter?.()
        await refetch()
    } catch (error) {
        iAlert(error, true)
    } finally {
        isDeleting.value = false
    }
}
const markSelectedAsSpam = async () => {
    if (!spamUuids.value.length) {
        return
    }

    isMarkingSpam.value = true
    try {
        const response = await markEmailsAsSpam(spamUuids.value, blockCustomDomains.value)
        if (response?.status !== 'success') {
            iAlert(response?.message || t('text.contact.email.spam.failed'))
            return
        }

        selectedUuids.value = []
        blockCustomDomains.value = false
        getCounter?.()
        await refetch()
    } catch (error) {
        iAlert(error, true)
    } finally {
        isMarkingSpam.value = false
    }
}

watch(
    spamUuids,
    (uuids) => {
        if (!uuids.length) {
            blockCustomDomains.value = false
        }
    }
)

watch(
    () => route.query,
    (value) => {
        syncQueryData(value)
    }
)

onMounted(() => {
    getCounter?.()
})
</script>
