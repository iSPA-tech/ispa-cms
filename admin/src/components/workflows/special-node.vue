<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<script setup>
import { computed, defineComponent } from 'vue'
import { Position, Handle } from '@vue-flow/core'

defineComponent({
    name: 'SpecialNode',
})

const props = defineProps({
    position: {
        type: Object,
        required: true,
    },
})

const x = computed(() => `${Math.round(props.position.x)}px`)
const y = computed(() => `${Math.round(props.position.y)}px`)
</script>

<template>
    <div class="vue-flow__node-default">
        <div>{{ data.label }}</div>

        <div>{{ x }} {{ y }}</div>

        <Handle type="source" :position="Position.Bottom" />
    </div>
</template>
