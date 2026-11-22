<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="mx-auto max-w-6xl space-y-4">
        <breadcrumbs
            :items="[
                {
                    name: 'media-dashboard',
                    title: $t('nav.a.media'),
                    icon: 'multimedia',
                },
                {
                    text: $t('nav.a.media-manage'),
                    icon: 'multimedia',
                },
            ]"
        />

        <div class="i-box space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold uppercase text-slate-700">
                    {{ $t('nav.a.media-manage') }}
                </h2>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-500">
                        {{ $t('nav.b.filter') }}
                    </span>
                    <a-select
                        v-model:value="selectedType"
                        :options="typeOptions"
                        class="min-w-[160px]"
                        size="small"
                    />
                </div>
            </div>

            <media-center :show="true" :type="selectedType" />
        </div>
    </div>
</template>

<script setup>
import { computed, defineComponent, ref } from 'vue'
import { useI18n } from 'vue-i18n'

defineComponent({
    name: 'MediaManage',
})

const { t } = useI18n()
const selectedType = ref('all')

const typeOptions = computed(() => [
    { label: t('text.filter.all', 'All'), value: 'all' },
    { label: t('text.type.image', 'Images'), value: 'image' },
    { label: t('text.tab.avatar', 'Avatars'), value: 'avatar' },
    { label: t('text.type.video', 'Videos'), value: 'video' },
    { label: t('nav.a.podcast', 'Podcast'), value: 'audio' },
    { label: t('nav.a.documents') || 'Documents', value: 'document' },
    { label: t('nav.a.archives') || 'Archives', value: 'archive' },
])
</script>
