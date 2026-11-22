<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <ul
        class="h-full grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 p-2"
    >
        <li v-for="(file, index) in data || []" :key="index" class="relative">
            <template v-if="file">
                <div class="absolute top-0 right-0 z-20">
                    <label
                        :class="[
                            'cursor-pointer rounded-bl-xl bg-white rounded-tr-lg relative text-center',
                            'h-8 w-8 border-l border-b border-gray-400 flex items-center justify-center',
                            isSelectDisabled(file.id)
                                ? 'hover:bg-white cursor-not-allowed'
                                : 'hover:bg-gray-100',
                        ]"
                    >
                        <a-checkbox
                            :checked="selectedIds.includes(file.id)"
                            :disabled="isSelectDisabled(file.id)"
                            :value="file.id"
                            class=""
                            @change="(e) => handleSelect(e, index, file.id)"
                        />
                    </label>
                </div>
                <span
                    aria-description="Click to view details file"
                    class="absolute inset-0 top-0 left-0 z-10 cursor-pointer"
                    @click="viewDetail(file)"
                />
                <div
                    :class="[
                        selectedIds.includes(file.id)
                            ? 'ring-2 ring-indigo-500 ring-offset-2'
                            : 'focus-within:ring-2 focus-within:ring-indigo-500 focus-within:ring-offset-2 focus-within:ring-offset-gray-100',
                        'aspect-square group block w-full overflow-hidden rounded-lg bg-gray-100 relative',
                    ]"
                >
                    <i-image
                        :class="[
                            selectedIds.includes(file.id) ? '' : 'group-hover:opacity-75',
                            'pointer-events-none object-cover border rounded-lg border-gray-400 shadow-xs',
                        ]"
                        :src="file"
                    />
                </div>
                <div class="absolute right-2 bottom-4 z-20">
                    <div class="flex items-start space-x-2 text-xs">
                        <a-tooltip :title="$t('text.linked-target')">
                            <span class="text-cyan-500">
                                <i-icon icon="link-box" class="size-4" />
                                {{ file?.pivots_count || 0 }}
                            </span>
                        </a-tooltip>
                    </div>
                </div>
                <p
                    class="pointer-events-none mt-2 block truncate text-sm font-medium text-gray-900"
                >
                    {{ file?.name || 'N/a' }}
                </p>
                <p class="pointer-events-none block text-sm text-gray-500">
                    {{ file?.size || '0' }}
                </p>
            </template>
        </li>
    </ul>
</template>

<script>
import { defineComponent, ref, watch } from 'vue'

export default defineComponent({
    name: 'MediaListThumbnail',
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
