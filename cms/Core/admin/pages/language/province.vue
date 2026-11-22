<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div v-if="rule('province.viewAny')" class="px-2 md:px-1 py-4 mb-12">
        <a-empty v-if="loaded && !data.length" :description="$t('text.province_empty')" class="my-6" data-testid="province-empty" />
        <ul v-else class="root node">
            <folder-node v-for="node in data" :key="node.id" :node="node" url="province" />
        </ul>
    </div>
    <no-permission-error v-else></no-permission-error>
</template>

<script setup>
import { defineComponent, onMounted, ref } from 'vue'
import { rule } from '@/mixins'
import ajax from '@/ajax'

defineComponent({
    name: 'ProvinceManage',
})

const data = ref([])
/** True once the first request settled, so the empty state never flashes while loading. */
const loaded = ref(false)
onMounted(() => {
    ajax.get('province/getFolderTree/0')
        .then((res) => {
            data.value = res.data.data || []
        })
        .finally(() => {
            loaded.value = true
        })
})
</script>
