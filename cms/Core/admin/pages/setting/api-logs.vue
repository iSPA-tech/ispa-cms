<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="inline-block min-w-full py-2 align-middle">
        <div class="overflow-hidden shadow-xs ring-1 ring-black/5 md:rounded-lg">
            <table class="min-w-full divide-y divide-gray-300">
                <thead class="bg-gray-50">
                    <tr>
                        <th
                            class="whitespace-nowrap py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-6"
                            scope="col"
                        >
                            {{ $t('text.api-logs.method') }}
                        </th>
                        <th
                            class="whitespace-nowrap px-2 py-3.5 text-left text-sm font-semibold text-gray-900"
                            scope="col"
                        >
                            {{ $t('text.api-logs.domain') }}
                        </th>
                        <th
                            class="whitespace-nowrap px-2 py-3.5 text-left text-sm font-semibold text-gray-900"
                            scope="col"
                        >
                            {{ $t('text.api-logs.type') }}
                        </th>
                        <th
                            class="whitespace-nowrap px-2 py-3.5 text-left text-sm font-semibold text-gray-900"
                            scope="col"
                        >
                            {{ $t('text.api-logs.requested_at') }}
                        </th>
                        <th class="relative whitespace-nowrap py-3.5 pl-3 pr-4 sm:pr-6" scope="col">
                            <span class="sr-only">{{ $t('nav.b.edit') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <tr v-for="(a, ind) in api.data" :key="ind">
                        <td class="whitespace-nowrap py-2 pl-4 pr-3 text-sm text-gray-500 sm:pl-6">
                            {{ a.method }}
                        </td>
                        <td class="whitespace-nowrap px-2 py-2 text-sm text-gray-900">
                            <span class="block font-medium">{{ a.domain || a.url }}</span>
                            <span v-if="a.type === 'incoming'" class="block text-green-500">{{
                                a.url
                            }}</span>
                            <span class="block text-slate-400">{{ $t('text.api-logs.request_id', { id: a.request_id }) }}</span>
                        </td>
                        <td class="whitespace-nowrap px-2 py-2 text-sm text-gray-500">
                            <span v-if="a.type === 'incoming'" class="text-green-500">
                                <i-icon icon="arrow-down-thin" class="size-4" />
                            </span>
                            <span v-else-if="a.type === 'internal'" class="text-blue-500">
                                <i-icon icon="wifi" class="size-4" />
                            </span>
                            <span v-else-if="a.type === 'local'" class="text-indigo-500">
                                <i-icon icon="code" class="size-4" />
                            </span>
                            <span v-else class="text-rose-500">
                                <i-icon icon="arrow-up-thin" class="size-4" />
                            </span>
                            {{ a.type }}
                            <span class="block text-xs text-rose-500" :title="$t('text.api-logs.duration')"
                                >{{ $t('text.api-logs.duration_ms', { ms: a.duration }) }}</span
                            >
                        </td>
                        <td class="whitespace-nowrap px-2 py-2 text-sm text-gray-500">
                            {{ a.created_at }}
                        </td>
                        <td
                            class="relative whitespace-nowrap py-2 pl-3 pr-4 text-right text-sm font-medium sm:pr-6"
                        >
                            <a class="text-indigo-600 hover:text-indigo-900" href="#"
                                >{{ $t('nav.b.edit') }}<span class="sr-only"></span
                            ></a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
<script setup>
import { ref } from 'vue'

defineOptions({ name: 'SettingApiLogs' })

const api = ref({ data: [] })
</script>
