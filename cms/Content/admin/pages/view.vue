<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-4">
        <breadcrumbs
            :items="[
                {
                    icon: 'text-box-edit-outline',
                    title: $t('nav.a.posts'),
                    text: $t('nav.a.posts'),
                    name: 'post-post',
                },
                { text: post.name || $t('nav.b.view-detail'), title: $t('nav.b.view-detail') },
            ]"
        >
            <i-button as="router-link" :to="{ name: 'post-post' }" variant="default">
                <template #icon>
                    <i-icon class="size-3.5" icon="format-list-bulleted" />
                </template>
                <span>{{ $t('nav.a.manage') }}</span>
            </i-button>
            <i-button
                as="router-link"
                v-if="post.uuid"
                :to="{ name: 'post-update', params: { uuid: post.uuid } }"
                variant="primary"
            >
                <template #icon>
                    <i-icon class="size-3.5" icon="pencil" />
                </template>
                <span>{{ $t('nav.b.edit') }}</span>
            </i-button>
        </breadcrumbs>

        <a-skeleton v-if="isLoading" :paragraph="{ rows: 12 }" active />

        <template v-else-if="post.id">
            <!-- Header -->
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <a-tag :color="statusColor" class="uppercase text-xs">
                            {{ statusLabel }}
                        </a-tag>
                        <a-tag v-if="post.type" class="uppercase text-xs">
                            {{ post.type }}
                        </a-tag>
                    </div>
                    <div class="text-xs text-slate-500 space-x-2">
                        <span v-if="post.slug">{{ post.slug }}</span>
                        <span v-if="categoryName">• {{ categoryName }}</span>
                    </div>
                </div>
            </div>

            <!-- Summary pills -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                <div v-for="card in summaryCards" :key="card.key" class="i-box p-4 space-y-2">
                    <p class="text-xs uppercase text-slate-500">{{ card.label }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <AppStatPill
                            :icon="card.icon"
                            :label="card.label"
                            :value="card.value"
                            variant="muted"
                        />
                    </div>
                </div>
            </div>

            <!-- Main content grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <!-- LEFT: Content -->
                <div class="lg:col-span-2 min-w-0 space-y-4">
                    <!-- Post Content Card -->
                    <div class="i-box p-4 space-y-3">
                        <h2 class="text-sm font-semibold text-slate-700 uppercase">
                            {{ $t('validation.attributes.content') }}
                        </h2>

                        <div v-if="post.description" class="text-sm text-slate-600">
                            <p class="text-xs uppercase text-slate-500 mb-1">
                                {{ $t('validation.attributes.description') }}
                            </p>
                            <p>{{ post.description }}</p>
                        </div>

                        <SafeHtml
                            v-if="post.convert_content"
                            class="prose prose-sm max-w-none text-slate-700 border-t pt-3"
                            :html="post.convert_content"
                        />
                        <a-empty v-else :description="$t('text.post.no_content')" />
                    </div>
                </div>

                <!-- RIGHT: Metadata -->
                <div class="space-y-4">
                    <!-- Thumbnail -->
                    <div v-if="postCoverUrl" class="i-box p-4 space-y-3">
                        <h2 class="text-sm font-semibold text-slate-700 uppercase">
                            {{ $t('text.post.thumbnail') }}
                        </h2>
                        <img
                            :alt="post.name"
                            :src="postCoverUrl"
                            class="w-full aspect-video rounded object-cover"
                            loading="lazy"
                            @error="onCoverError"
                        />
                    </div>

                    <!-- Metadata Card -->
                    <div class="i-box p-4 space-y-3">
                        <h2 class="text-sm font-semibold text-slate-700 uppercase">
                            {{ $t('text.information') }}
                        </h2>
                        <div class="space-y-2 text-sm">
                            <div
                                v-for="item in metadataItems"
                                :key="item.key"
                                :data-test="`post-metadata-${item.key}`"
                                class="space-y-0.5"
                            >
                                <p class="text-xs uppercase text-slate-500">{{ item.label }}</p>
                                <p class="text-slate-700 font-medium break-words">
                                    {{ item.value ?? '—' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- SEO Card -->
                    <div v-if="post.seo" class="i-box p-4 space-y-3">
                        <h2 class="text-sm font-semibold text-slate-700 uppercase">SEO</h2>
                        <div class="space-y-2 text-sm">
                            <div v-if="post.seo.title" class="space-y-0.5">
                                <p class="text-xs uppercase text-slate-500">{{ $t('validation.attributes.title') }}</p>
                                <p class="text-slate-700 font-medium">{{ post.seo.title }}</p>
                            </div>
                            <div v-if="post.seo.description" class="space-y-0.5">
                                <p class="text-xs uppercase text-slate-500">
                                    {{ $t('validation.attributes.description') }}
                                </p>
                                <p class="text-slate-700">{{ post.seo.description }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Views Card -->
                    <div class="i-box p-4 space-y-3">
                        <h2 class="text-sm font-semibold text-slate-700 uppercase">
                            {{ $t('validation.attributes.views') }}
                        </h2>
                        <div class="text-2xl font-bold text-slate-800">
                            {{ formatNumber(post.total_view || 0) }}
                        </div>
                        <p class="text-xs text-slate-500">
                            {{ $t('text.post.total_views_note') }}
                        </p>
                    </div>
                </div>
            </div>
        </template>

        <a-empty v-else :description="$t('text.not-found')" />
    </div>
</template>

<script setup>
import { computed, defineComponent } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useEnumerates, usePost } from '@/hooks'
import dayjs from 'dayjs'
import numeral from 'numeral'
import AppStatPill from '@/components/common/AppStatPill.vue'
import SafeHtml from '@/components/common/SafeHtml.vue'
import '@shared-data-chart-css'

defineComponent({
    name: 'PostView',
})

const { t } = useI18n()
const route = useRoute()

const uuid = computed(() => route.params.uuid)

const { data: rawData, isLoading } = usePost(uuid.value, false, {
    enabled: computed(() => Boolean(uuid.value)),
    staleTime: 1000 * 60 * 5,
})

const post = computed(() => rawData.value?.data || rawData.value || {})
const postCoverUrl = computed(() => {
    const image = post.value.image
    return typeof image === 'string'
        ? image
        : image?.cover_lg || image?.url || image?.thumbnail_lg || ''
})
const onCoverError = (event) => {
    const original = post.value.image?.url
    if (original && event.target.getAttribute('src') !== original) event.target.src = original
}
const categoryName = computed(
    () =>
        post.value.categories
            ?.map((category) => category.name)
            .filter(Boolean)
            .join(', ') || post.value.category?.name
)

const statusPalette = {
    status_published: 'green',
    status_draft: 'gold',
    pending: 'orange',
    archived: 'volcano',
    disabled: 'red',
}

const { data: enumerates } = useEnumerates()

/**
 * Status label from the enumerate list the create and update pages use.
 * An unknown or missing code falls back to a generic label, never the raw code.
 */
const statusLabel = computed(() => {
    const code = post.value.status
    const match = (enumerates.value || []).find((item) => item.type === 'status' && item.enum_key === code)

    return match?.name || t('text.not_applicable')
})

const statusColor = computed(() => statusPalette[post.value.status] || 'default')

const formatDate = (val) => {
    if (!val) return '—'
    return dayjs(val).format('DD/MM/YYYY HH:mm')
}

const formatNumber = (val) => numeral(val).format('0,0')

const summaryCards = computed(() => [
    {
        key: 'status',
        icon: 'check-circle',
        label: t('validation.attributes.status'),
        value: statusLabel.value,
    },
    {
        key: 'type',
        icon: 'file-document',
        label: t('validation.attributes.type'),
        value: post.value.type || '—',
    },
    {
        key: 'views',
        icon: 'eye',
        label: t('validation.attributes.views'),
        value: formatNumber(post.value.total_view || 0),
    },
    {
        key: 'published',
        icon: 'calendar',
        label: t('validation.attributes.published_at'),
        value: formatDate(post.value.published_at),
    },
])

const metadataItems = computed(() => {
    const items = [
        {
            key: 'category',
            label: t('text.category'),
            value: categoryName.value,
        },
        {
            key: 'uuid',
            label: 'UUID',
            value: post.value.uuid,
        },
        {
            key: 'slug',
            label: t('validation.attributes.slug'),
            value: post.value.slug,
        },
        {
            key: 'content_blocks',
            label: t('text.post.content_blocks'),
            value: String(post.value.content?.length ?? 0),
        },
        {
            key: 'layout',
            label: t('text.post.full_page_article'),
            value:
                post.value.config?.is_full_page === true
                    ? t('text.yes')
                    : t('text.no'),
        },
        {
            key: 'author',
            label: t('text.author'),
            value: post.value.author?.name || post.value.author?.username,
        },
        {
            key: 'language',
            label: t('validation.attributes.language'),
            value: post.value.language?.name,
        },
        {
            key: 'published_at',
            label: t('validation.attributes.published_at'),
            value: formatDate(post.value.published_at),
        },
        {
            key: 'created_at',
            label: t('text.created_at'),
            value: formatDate(post.value.created_at),
        },
        {
            key: 'updated_at',
            label: t('text.updated_at'),
            value: formatDate(post.value.updated_at),
        },
    ]
    if (post.value.hasVideo) {
        items.push({
            key: 'video',
            label: t('validation.attributes.video_url'),
            value: post.value.video_url,
        })
    }
    return items
})
</script>
