<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <span v-if="!isEmpty(title)" class="text-gray-500 font-normal text-sm">
        /
        <template v-for="(text, ind) in title" :key="ind">
            <template v-if="ind > 0"> / </template>

            <template v-if="Array.isArray(text)">
                {{ text.map((t) => $t(t)).join(' ') }}
            </template>
            <template v-else>{{ $t(text) }}</template>
        </template>
    </span>
</template>

<script>
import { defineComponent } from 'vue'
import { isEmpty } from 'lodash-es'

export default defineComponent({
    name: 'SubTitle',
    methods: { isEmpty },
    props: {
        title: {
            type: Array,
            default: () => [],
        },
    },
})
</script>
