<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-drawer
        v-model:open="drawerOpen"
        :title="$t('text.post.source.title')"
        :width="$isMobile() ? '100%' : '55%'"
        @close="close"
    >
        <template v-if="loading">
            <div class="flex justify-center py-12">
                <a-spin size="large" />
            </div>
        </template>

        <template v-else-if="error">
            <a-alert :message="error" type="error" show-icon />
        </template>

        <template v-else-if="article">
            <div class="space-y-4">
                <!-- Source URL -->
                <div class="text-sm text-gray-500">
                    <a :href="crawlerFrom" target="_blank" class="text-blue-600 hover:underline">
                        {{ crawlerFrom }}
                    </a>
                </div>

                <!-- Original Title -->
                <div>
                    <label class="text-xs font-medium text-gray-400 uppercase">{{ $t('text.post.source.original_title') }}</label>
                    <h2 class="text-lg font-semibold text-gray-900 mt-1">{{ article.name }}</h2>
                </div>

                <!-- Original Description -->
                <div v-if="article.description">
                    <label class="text-xs font-medium text-gray-400 uppercase">{{ $t('text.post.common.description') }}</label>
                    <p class="text-sm text-gray-700 mt-1">{{ article.description }}</p>
                </div>

                <!-- Translated Title -->
                <div v-if="article.translated_name" class="bg-blue-50 rounded p-3">
                    <label class="text-xs font-medium text-blue-400 uppercase">{{ $t('text.post.source.translated_title') }}</label>
                    <p class="text-sm text-gray-800 mt-1">{{ article.translated_name }}</p>
                </div>

                <!-- Content Blocks -->
                <div>
                    <label class="text-xs font-medium text-gray-400 uppercase mb-2 block">
                        {{ $t('text.post.source.content_blocks', { count: contentBlocks.length }) }}
                    </label>
                    <div class="space-y-3">
                        <div
                            v-for="(block, idx) in contentBlocks"
                            :key="idx"
                            class="border rounded-sm p-3"
                            :class="block.type === 'image' ? 'bg-amber-50 border-amber-200' : 'bg-gray-50 border-gray-200'"
                        >
                            <div class="flex items-center gap-2 mb-2">
                                <span
                                    class="text-xs font-mono px-1.5 py-0.5 rounded"
                                    :class="block.type === 'image' ? 'bg-amber-200 text-amber-800' : 'bg-gray-200 text-gray-600'"
                                >
                                    {{ block.type }}
                                </span>
                                <span class="text-xs text-gray-400">#{{ idx }}</span>
                            </div>

                            <!-- Text block: show HTML preview -->
                            <div
                                v-if="block.type === 'text'"
                                class="text-sm text-gray-700 prose prose-sm max-w-none"
                                v-html="block.value"
                            />

                            <!-- Image block: show URLs -->
                            <div v-else-if="block.type === 'image'" class="space-y-1">
                                <div
                                    v-for="(url, imgIdx) in (Array.isArray(block.value) ? block.value : [block.value])"
                                    :key="imgIdx"
                                    class="text-xs text-amber-700 font-mono break-all"
                                >
                                    {{ url }}
                                </div>
                            </div>

                            <!-- Video block -->
                            <div v-else-if="block.type === 'video'" class="text-sm text-gray-600">
                                {{ block.value }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Re-parse Button -->
                <div class="border-t pt-4 mt-4">
                    <a-popconfirm
                        :title="$t('text.post.source.reparse_confirm')"
                        :ok-text="$t('text.yes')"
                        :cancel-text="$t('text.no')"
                        @confirm="reparse"
                    >
                        <a-button
                            type="primary"
                            :loading="reparsing"
                            danger
                        >
                            {{ $t('text.post.source.reparse_button') }}
                        </a-button>
                    </a-popconfirm>
                    <p class="text-xs text-gray-400 mt-2">
                        {{ $t('text.post.source.reparse_help') }}
                    </p>
                </div>
            </div>
        </template>
    </a-drawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { message } from 'ant-design-vue'
import { useI18n } from 'vue-i18n'
import ajaxV4 from '@/ajax-v4'

const props = defineProps({
    open: { type: Boolean, default: false },
    crawlerFrom: { type: String, default: '' },
    tempId: { type: [Number, String], default: null },
    postId: { type: Number, default: 0 },
})

const emit = defineEmits(['update:open', 'reparsed'])

const { t } = useI18n()

const drawerOpen = computed({
    get: () => props.open,
    set: (val) => emit('update:open', val),
})

const loading = ref(false)
const error = ref('')
const article = ref(null)
const reparsing = ref(false)

const contentBlocks = computed(() => {
    if (!article.value) return []
    try {
        const content = article.value.converted_content
        return Array.isArray(content) ? content : (typeof content === 'string' ? JSON.parse(content) : [])
    } catch {
        // Fall back to raw content blocks
        const raw = article.value.content
        if (!raw) return []
        return [{ type: 'text', value: raw }]
    }
})

const fetchArticle = async () => {
    if (!props.tempId && !props.crawlerFrom) {
        error.value = t('text.post.source.no_link')
        return
    }

    loading.value = true
    error.value = ''
    article.value = null

    try {
        const params = props.tempId ? { temp_id: props.tempId } : { url: props.crawlerFrom }
        const res = await ajaxV4.get('crawled-article/by-url', { params })
        article.value = res.data?.data || null
        if (!article.value) {
            error.value = t('text.post.source.no_article')
        }
    } catch (e) {
        error.value = e.response?.data?.message || t('text.post.source.load_failed')
    } finally {
        loading.value = false
    }
}

const reparse = async () => {
    if (reparsing.value || !props.postId) return
    reparsing.value = true

    try {
        const res = await ajaxV4.post('crawled-article/reparse', {
            post_id: props.postId,
        })
        message.success(res.data?.message || t('text.post.source.reparse_done'))
        emit('reparsed')
    } catch (e) {
        message.error(e.response?.data?.message || t('text.post.source.reparse_failed'))
    } finally {
        reparsing.value = false
    }
}

const close = () => {
    emit('update:open', false)
}

watch(() => props.open, (val) => {
    if (val) fetchArticle()
})
</script>
