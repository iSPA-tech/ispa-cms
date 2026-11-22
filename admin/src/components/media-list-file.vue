<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <fieldset class="pb-4">
        <legend class="sr-only">{{ $t('text.media.file_list') }}</legend>
        <div class="space-y-0.5">
            <div
                v-for="(file, index) in data || []"
                :key="index"
                class="relative flex items-start space-x-4 hover:bg-gray-100 p-1"
            >
                <div class="relative flex-1 flex items-start">
                    <a-tooltip :title="$t('text.click-to-view')">
                        <span
                            class="absolute inset-0 z-40"
                            role="button"
                            @click="viewDetail(file)"
                        ></span>
                    </a-tooltip>
                    <div class="flex w-4 h-6 items-center relative">
                        <a-checkbox
                            :id="`file-${file.id}`"
                            :checked="selectedIds.includes(file.id)"
                            :disabled="isSelectDisabled(file.id)"
                            :value="file.id"
                            class="absolute z-50"
                            @change="(e) => handleSelect(e, index, file.id)"
                        ></a-checkbox>
                    </div>
                    <div v-if="file?.type === 'image'" class="rounded-sm ml-3">
                        <i-image
                            :class="['pointer-events-none object-cover border w-11']"
                            :src="file"
                        ></i-image>
                    </div>
                    <div class="ml-3 text-sm leading-6">
                        <a-tooltip :title="$t('text.click-to-add')">
                            <label
                                :for="`file-${file.id}`"
                                class="font-medium text-gray-900 cursor-pointer hover:text-gray-600 relative"
                            >
                                {{ file?.name || 'N/a' }}
                                <span class="absolute inset-0 z-50"></span>
                            </label>
                        </a-tooltip>
                        <div class="flex items-center space-x-4">
                            <p class="text-gray-500 m-0">
                                {{ file?.size || '0' }} |
                                {{ file?.created_at || '' }}
                            </p>
                            <!-- Usage count only exists on general media rows; private document stores have none. -->
                            <div v-if="file?.pivots_count !== undefined" class="flex items-start space-x-3">
                                <span class="text-cyan-500">
                                    <i class="sr-only">{{ $t('text.linked-target') }}</i>
                                    <i-icon icon="link-box" class="size-4" />
                                    {{ file?.pivots_count || 0 }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <!--                <div-->
                <!--                    v-if="file?.pivots_count < 1"-->
                <!--                    class="flex items-start pl-4 pr-3"-->
                <!--                >-->
                <!--                    <a-popconfirm :title="$t('text.del-confirm')">-->
                <!--                        <a-tooltip-->
                <!--                            :title="$t('nav.b.delete')"-->
                <!--                            placement="left"-->
                <!--                            color="red"-->
                <!--                        >-->
                <!--                            <span-->
                <!--                                class="text-rose-500 hover:text-rose-700 cursor-pointer"-->
                <!--                            >-->
                <!--                                <i-icon class="size-4" icon="delete" />-->
                <!--                            </span>-->
                <!--                        </a-tooltip>-->
                <!--                    </a-popconfirm>-->
                <!--                </div>-->
            </div>
        </div>
    </fieldset>
</template>

<script>
import { defineComponent, ref, watch } from 'vue'

export default defineComponent({
    name: 'MediaListFile',
    props: {
        modelValue: { type: Array, default: () => [] },
        data: { type: Array, default: () => [] },
        isSelectDisabled: { type: Function, default: () => false },
    },
    emits: ['update:modelValue', 'select', 'view-detail'],
    setup(props, { emit }) {
        const selectedIds = ref(props.modelValue)

        const viewDetail = (file) => {
            emit('view-detail', file)
        }

        const handleSelect = (e, index, id) => {
            emit('select', e, index, id)
        }

        //  Keep the modelValue in sync with the selectedIds
        watch(
            () => props.modelValue,
            (val) => {
                selectedIds.value = val
            }
        )

        return {
            selectedIds,
            viewDetail,
            handleSelect,
        }
    },
})
</script>
