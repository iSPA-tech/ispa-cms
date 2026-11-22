<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <li v-if="node.id" v-loading="loading" class="transition">
        <div class="flex items-center hover:bg-gray-100 py-1 px-3">
            <span class="h-5 w-5">
                <template v-if="node.children_count > 0">
                    <svg
                        v-if="!isOpen"
                        class="h-5 w-5"
                        fill="currentColor"
                        viewBox="0 0 20 20"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            clip-rule="evenodd"
                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                            fill-rule="evenodd"
                        />
                    </svg>
                    <svg
                        v-else
                        class="h-5 w-5"
                        fill="currentColor"
                        viewBox="0 0 20 20"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            clip-rule="evenodd"
                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                            fill-rule="evenodd"
                        />
                    </svg>
                </template>
            </span>
            <span class="flex-1" role="button" @click="getFolderTree(node.id)">{{
                node.name
            }}</span>

            <div class="flex items-center">
                <template v-if="rule(makeFirstLower() + '.update')">
                    <a-tooltip :title="$t('nav.b.edit')">
                        <router-link
                            :title="$t('nav.b.edit')"
                            :to="{ name: 'province-update', params: { id: node.id } }"
                        >
                            <span class="text-blue-500">
                                <svg
                                    class="h-5 w-5"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                    xmlns="http://www.w3.org/2000/svg"
                                >
                                    <path
                                        d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z"
                                    />
                                    <path
                                        clip-rule="evenodd"
                                        d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"
                                        fill-rule="evenodd"
                                    />
                                </svg>
                            </span>
                        </router-link>
                    </a-tooltip>
                </template>
                <template v-if="rule(makeFirstLower() + '.delete')">
                    <a-divider class="mx-3" type="vertical" />
                    <a-popconfirm
                        :title="$t('text.del-confirm')"
                        @confirm="() => callDeleteProvince(node.id)"
                    >
                        <span class="cursor-pointer text-rose-500">
                            <i-icon class="size-4" icon="delete" />
                        </span>
                    </a-popconfirm>
                </template>
            </div>
        </div>
        <ul v-if="data.length > 0" class="m-0 pl-6 list-none py-0">
            <folder-node v-for="item in data" :key="item.id" :node="item" :url="url"></folder-node>
        </ul>
    </li>
</template>

<script setup>
import { defineComponent, ref } from 'vue'
import ajax from '@/ajax'
import { iAlert, rule } from '@/mixins'

const props = defineProps({
    node: { type: Object, default: {} },
    root: { type: Boolean, default: false },
    url: { type: String, default: '' },
})

defineComponent({
    name: 'FolderNode',
})

const data = ref([])
const loading = ref(false)
const isOpen = ref(false)

const callDeleteProvince = (id) => {
    ajax.delete(`province/` + id)
        .then((res) => {
            if (res.data.status === 'success') {
                iAlert(res.data)
                getFolderTree()
            }
            if (res.data.message) {
                iAlert(res.data)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}
/** Get folder tree via Id */
const getFolderTree = (id = 0) => {
    if (isOpen.value === false && props.node.children_count > 0) {
        loading.value = true
        ajax.get(`${props.url}/getFolderTree/` + id)
            .then((res) => {
                if (res.data.status === 'success') {
                    data.value = res.data.data
                    if (data.value.length > 0) {
                        isOpen.value = true
                    }
                }
                if (res.data.message) {
                    iAlert(res.data)
                }
                loading.value = false
            })
            .catch((err) => {
                iAlert(err, true)
                loading.value = false
            })
    } else {
        isOpen.value = false
        data.value = []
    }
}

const makeFirstLower = () => {
    return 'province'
}
</script>
