<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-card size="small" class="mt-4">
        <template #title>
            <div class="flex items-center justify-between gap-2">
                <span>{{ $t('text.workflow.sidebar.blueprint_library') }}</span>
                <a-tag color="blue">{{ functionLibrary.length }}</a-tag>
            </div>
        </template>
        <a-spin :spinning="loading">
            <a-empty
                v-if="!functionLibrary.length"
                :description="$t('text.no-data')"
                class="py-8"
            />
            <a-collapse v-else ghost>
                <a-collapse-panel
                    v-for="group in groupedFunctions"
                    :key="group.category"
                    :header="`${group.label} (${group.items.length})`"
                >
                    <div class="space-y-3">
                        <div
                            v-for="item in group.items"
                            :key="item.class"
                            class="rounded border border-gray-100 p-3 hover:border-cyan-400/60"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-medium text-sm text-gray-800 truncate">
                                        {{ item.display_name || item.short_name || item.class }}
                                    </p>
                                    <p class="text-xs text-gray-500 mt-0.5">
                                        {{ item.description || item.class }}
                                    </p>
                                </div>
                                <a-tag>{{ item.category }}</a-tag>
                            </div>
                            <div class="mt-3">
                                <p class="text-xs uppercase text-gray-400 tracking-wide">
                                    {{ $t('text.workflow.library.functions') }}
                                </p>
                                <ul class="mt-1 space-y-1">
                                    <li
                                        v-for="fn in item.functions"
                                        :key="`${item.class}-${fn.name}`"
                                        class="text-xs text-gray-600"
                                    >
                                        <span class="font-medium text-gray-700">
                                            {{ fn.name }}
                                        </span>
                                        <span
                                            v-if="fn.parameters && fn.parameters.length"
                                            class="text-gray-400"
                                        >
                                            ({{ formatParameters(fn.parameters) }})
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </a-collapse-panel>
            </a-collapse>
        </a-spin>
    </a-card>
</template>

<script setup>
defineOptions({
    name: 'WorkflowFunctionLibrary',
})

defineProps({
    groupedFunctions: {
        type: Array,
        default: () => [],
    },
    functionLibrary: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
})

const formatParameters = (params = []) =>
    params
        .map((param) => `${param.name}${param.type ? `: ${param.type}` : ''}`)
        .join(', ')
</script>
