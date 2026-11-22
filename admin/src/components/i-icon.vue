<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <svg-icon v-if="props.icon" :path="path" class="cms-icon" type="mdi" />
</template>

<script setup>
import { computed, defineComponent } from 'vue'
import SvgIcon from '@jamescoyle/vue-icon'
import { iconMaps } from '@/config/icon-maps'

defineComponent({
    name: 'i-icon',
})

const props = defineProps({
    icon: {
        type: String,
        default: 'reload-alert',
    },
})

const path = computed(() => {
    return iconMaps[props.icon] ?? iconMaps['reload-alert']
})

</script>
