<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-drawer v-model:open="currentContentState" :width="$isMobile() ? '100%' : '50%'">
        <template #title>
            <div class="flex items-center space-x-2">
                <img
                    v-if="currentContent.language"
                    :src="currentContent.language.flag_url"
                    alt=""
                    class="w-6"
                />
                <span>{{ currentContent.name || 'N/a' }}</span>
            </div>
        </template>

        <div class="text-right">
            <a-tag :color="currentContent.is_confirmed ? '#108ee9' : '#f50'" class="text-sm">
                {{ currentContent.is_confirmed ? $t('text.confirm.yes') : $t('text.confirm.no') }}
            </a-tag>

            <a-tag :color="contentStatusColor(currentContent.status)" class="m-0!">
                {{
                    (currentContent.content_status
                        ? isEn
                            ? currentContent.content_status.name_alias
                            : currentContent.content_status.name
                        : currentContent.status) || $t('text.acc.not-set')
                }}
            </a-tag>
        </div>

        <div v-if="currentContent.language_id !== 1" class="py-4">
            <a-collapse>
                <a-collapse-panel key="1" :header="$t('text.translate.status')">
                    <p class="m-0">
                        - {{ $t('text.translate.name') }}:
                        {{ $t('text.translate.status_' + currentContent.custom.name) }}
                    </p>
                    <p class="m-0">
                        - {{ $t('text.translate.description') }}:
                        {{ $t('text.translate.status_' + currentContent.custom.description) }}
                    </p>
                    <p class="m-0">
                        - {{ $t('text.translate.content') }}:
                        {{ $t('text.translate.status_' + currentContent.custom.content) }}
                    </p>
                </a-collapse-panel>
            </a-collapse>
        </div>

        <div class="relative">
            <div>
                <h2 class="text-sm font-bold text-gray-900">
                    {{ $t('validation.attributes.description') }}
                </h2>
                <div class="my-3">
                    {{ currentContent.description || 'N/a' }}
                </div>
            </div>
            <div v-if="currentContent.video_url">
                <h2 class="text-sm font-bold text-gray-900">
                    {{ $t('validation.attributes.video_url') }}
                </h2>
                <div class="my-3">
                    {{ currentContent.video_url || 'N/a' }}
                </div>
            </div>

            <ul
                v-if="currentContent.audios && currentContent.audios.length > 0"
                class="border border-gray-200 rounded-md divide-y divide-gray-200 mb-3 mt-1.5"
                role="list"
            >
                <li
                    v-for="(audio, ind) in currentContent.audios"
                    :key="ind"
                    class="pl-3 pr-4 py-3 flex items-start justify-between text-sm"
                >
                    <div class="w-0 flex-1">
                        <div class="flex items-center">
                            <span class="shrink-0 h-5 w-5 text-gray-400">
                                <i-icon icon="waveform" class="size-4" />
                            </span>
                            <span class="ml-2 flex-1 w-0 truncate">{{ audio.name }}</span>
                        </div>
                        <div class="w-full mt-2">
                            <audio
                                v-if="currentContentState"
                                :src="`${apiUrl}${audio.url}`"
                                class="w-full"
                                controls
                            >
                                {{ $t('text.media.audio_unsupported') }}
                            </audio>
                        </div>
                    </div>
                </li>
            </ul>

            <div class="border rounded-sm shadow-xs p-4 space-y-2 relative mt-8">
                <span class="absolute bg-white px-3 left-4 -top-4 text-gray-900 font-bold">
                    {{ $t('nav.a.contents') }}
                </span>
                <div class="max-h-[300px] overflow-auto">
                    <template v-for="(ct, ind) in currentContent.content" :key="ind">
                        <div v-if="ct.type === 'text'" v-html="ct.value"></div>
                        <div
                            v-if="ct.type === 'image' && ct.value && ct.value[0]"
                            class="flex items-start"
                        >
                            <img :src="ct.value[0].url" alt="" class="max-w-[200px] flex-1" />
                            <div class="flex-1 pl-4">
                                <h4 v-if="ct.options.caption" class="text-lg text-gray-900">
                                    {{ ct.options.caption }}
                                </h4>
                                <p v-if="ct.options.alt" class="text-sm text-gray-500">
                                    {{ ct.options.alt }}
                                </p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <span
                v-if="
                    (isEmpty(currentContent.content) ||
                        [
                            getContentStatus('DRAFT'),
                            getContentStatus('ON_QUEUE_TRANSLATION'),
                            getContentStatus('PENDING_TRANSLATION'),
                        ].includes(currentContent.status)) &&
                    currentContent.language_id > 1
                "
                class="absolute z-10 inset-0 bg-white/90 flex items-center justify-center text-2xl font-bold text-gray-900"
            >
                {{ $t('text.content-pending-translation') }}
            </span>
        </div>

        <div :class="{ 'is-active': currentContent.is_confirmed }" class="pt-4 space-y-3 group">
            <template v-if="currentContent.language_id === 1">
                <div
                    :class="[
                        currentContent.status !== getContentStatus('PUBLISHED') ||
                        currentContent.is_confirmed === false
                            ? 'text-gray-500/80 border-gray-500/30 cursor-not-allowed bg-gray-100/80'
                            : 'text-gray-600 border-green-700/30 bg-green-50/70',
                    ]"
                    class="p-3 rounded-sm border border-dashed space-y-2"
                >
                    <a-button
                        :disabled="
                            currentContent.status !== getContentStatus('PUBLISHED') ||
                            currentContent.is_confirmed === false
                        "
                        :loading="confirmState"
                        class="whitespace-nowrap btn-success"
                        @click="translateLanguage(false)"
                    >
                        <span><i-icon icon="translate-variant" class="size-4" /></span>
                        <span>{{ $t('nav.b.auto-translate') }}</span>
                    </a-button>

                    <p class="m-0">
                        {{
                            currentContent.is_confirmed
                                ? $t('mgs.content.translation-for-draft')
                                : $t('mgs.content.translate-confirmed-required')
                        }}
                    </p>
                </div>

                <div
                    :class="[
                        currentContent.status !== getContentStatus('PUBLISHED') ||
                        currentContent.is_confirmed === false
                            ? 'text-gray-500/80 border-gray-500/30 cursor-not-allowed bg-gray-100/80'
                            : 'text-gray-600 border-rose-700/30 bg-rose-50/70',
                    ]"
                    class="p-3 rounded-sm border border-dashed space-y-2"
                >
                    <a-button
                        :disabled="
                            currentContent.status !== getContentStatus('PUBLISHED') ||
                            currentContent.is_confirmed === false
                        "
                        :loading="confirmState"
                        class="whitespace-nowrap btn-danger"
                        @click="translateLanguage(true)"
                    >
                        <span><i-icon icon="translate-variant" class="size-4" /></span>
                        <span>{{ $t('nav.b.auto-translate') }} {{ $t('text.override') }}</span>
                    </a-button>

                    <p
                        class="m-0"
                        v-html="
                            currentContent.is_confirmed
                                ? $t('mgs.content.translation-override')
                                : $t('mgs.content.translate-confirmed-required')
                        "
                    />
                </div>
            </template>

            <div
                :class="[
                    ![getContentStatus('MODERATED'), getContentStatus('PUBLISHED')].includes(
                        currentContent.status
                    ) || currentContent.is_confirmed === false
                        ? 'text-gray-500/80 border-gray-500/30 cursor-not-allowed bg-gray-100/80'
                        : 'text-gray-600 border-indigo-700/30 bg-indigo-50/70',
                ]"
                class="p-3 rounded-sm border border-dashed space-y-2"
            >
                <a-button
                    :disabled="
                        ![getContentStatus('MODERATED'), getContentStatus('PUBLISHED')].includes(
                            currentContent.status
                        ) || currentContent.is_confirmed === false
                    "
                    :loading="confirmState"
                    class="whitespace-nowrap btn-help"
                    @click="cvAudio(false)"
                >
                    <span><i-icon icon="volume-minus" class="size-4" /></span>
                    <span>{{ $t('nav.b.audio-convert') }}</span>
                </a-button>

                <p class="m-0">
                    {{
                        currentContent.is_confirmed
                            ? $t('mgs.content.convert-audio')
                            : $t('mgs.content.audio-confirmed-required')
                    }}
                </p>
            </div>

            <div
                :class="[
                    ![getContentStatus('MODERATED'), getContentStatus('PUBLISHED')].includes(
                        currentContent.status
                    ) ||
                    currentContent.is_confirmed === false ||
                    !currentContent.audios ||
                    currentContent.audios.length === 0
                        ? 'text-gray-500/80 border-gray-500/30 cursor-not-allowed bg-gray-100/80'
                        : 'text-gray-600 border-indigo-700/30 bg-indigo-50/70',
                ]"
                class="p-3 rounded-sm border border-dashed space-y-2"
            >
                <a-button
                    :disabled="
                        ![getContentStatus('MODERATED'), getContentStatus('PUBLISHED')].includes(
                            currentContent.status
                        ) ||
                        currentContent.is_confirmed === false ||
                        !currentContent.audios ||
                        currentContent.audios.length === 0
                    "
                    :loading="confirmState"
                    class="whitespace-nowrap btn-help"
                    @click="cvAudio(true)"
                >
                    <span><i-icon icon="volume-plus" class="size-4" /></span>
                    <span>{{ $t('nav.b.audio-convert') }} {{ $t('text.override') }}</span>
                </a-button>

                <p class="m-0">
                    {{
                        currentContent.is_confirmed
                            ? $t('mgs.content.convert-audio-override')
                            : $t('mgs.content.audio-confirmed-required')
                    }}
                </p>
            </div>
        </div>

        <template
            v-if="
                (!currentContent.is_confirmed || currentContent.status === 'content_draft') &&
                rule('content.confirm') &&
                (currentContent.language_id === 1 ||
                    (currentContent.language_id !== 1 &&
                        currentContent.status !== CONTENT_STATUSES['TRANSLATED']))
            "
            #footer
        >
            <div class="flex items-center gap-x-2">
                <div class="flex-1 text-right text-rose-800 font-semibold">
                    {{ $t('text.content-review') }}
                </div>
                <div>
                    <a-button :disabled="confirmState" danger type="primary" @click="cfContent">
                        <i-icon class="size-4" icon="check-decagram" />
                        <span class="pl-1.5">{{ $t('nav.b.confirm') }}</span>
                    </a-button>
                </div>
            </div>
        </template>
    </a-drawer>
</template>

<script setup>
import { computed, defineComponent, ref, watch } from 'vue'
import { getContentStatus, contentStatusColor, rule, iAlert } from '@/mixins'
import { isEmpty } from 'lodash-es'
import { confirmContent, convertAudio, translateContent } from '@/libs'
import { apiUrl, CONTENT_STATUSES } from '@/config'
import { useAppRuntime } from '@/runtime'

const props = defineProps({
    visible: {
        type: Boolean,
        default: false,
    },
    data: {
        type: Object,
        default: () => ({}),
    },
})
const emit = defineEmits(['update:visible', 'finish'])
defineComponent({
    name: 'ViewDetailContent',
})

const { locale } = useAppRuntime()
const isEn = computed(() => locale.value === 'en')
const currentContentState = ref(props.visible)
const currentContent = ref(props.data)
//  Process to confirm content
const confirmState = ref(false)
//  Request to translate language manual
const translateLanguage = async (override = false) => {
    try {
        await translateContent(currentContent.value.uuid, {
            force: override,
        })
            .then((res) => {
                if (res.status === 'success') {
                    currentContent.value.custom = res.data
                }
                if (res.message) {
                    iAlert(res.message)
                }
            })
            .catch((err) => {
                iAlert(err, true)
            })
            .finally(() => {
                confirmState.value = false
            })
    } catch (e) {
        console.error(e)
    }
}
const cfContent = async () => {
    confirmState.value = true
    try {
        await confirmContent(currentContent.value.uuid, {
        })
            .then((res) => {
                if (res.data.status === 'success') {
                    emit('finish')
                    currentContent.value.is_confirmed = true
                }
                if (res.data.message) {
                    iAlert(res.data)
                }
            })
            .catch((err) => {
                iAlert(err, true)
            })
            .finally(() => {
                confirmState.value = false
            })
    } catch (e) {
        console.error(e)
    }
}
//  Request to convert audio
const cvAudio = async (override = false) => {
    try {
        await convertAudio(currentContent.value.uuid, {
            force: override,
        })
            .then((res) => {
                if (res.data.status === 'success') {
                    currentContent.value.audios = res.data.data
                }
                if (res.data.message) {
                    iAlert(res.data)
                }
            })
            .catch((err) => {
                iAlert(err, true)
            })
    } catch (e) {
        console.error(e)
    }
}

watch([() => props.visible, () => props.data], ([visible, data]) => {
    currentContentState.value = visible
    currentContent.value = data
})

watch(
    () => currentContentState.value,
    (val) => {
        emit('update:visible', val)
    }
)
</script>
